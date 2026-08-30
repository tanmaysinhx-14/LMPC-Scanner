"""Dataset audit/repair tests.

Every test here builds its own miniature dataset in ``tmp_path``. Nothing touches
``packaged-commodity-dataset`` - a test that rewrote 2064 real label files would be
worse than no test at all.

The fixture reproduces the actual defect found in the Roboflow export: one label
file holding both 5-field detection rows and variable-length polygon rows, which
Ultralytics resolves per *file*, silently misreading whichever rows are in the
minority encoding.
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest

import dataset_tools


CLASS_LINE = "names: ['consumer_care_region', 'date_region', 'dietary_symbol_region', 'generic_name_region', 'manufacturer_region', 'mrp_region', 'net_quantity_region']"

#: A tidy box and a polygon covering the same region, both in normalized units.
GOOD_BBOX = "5 0.500000 0.500000 0.200000 0.100000"
POLYGON = "1 0.10 0.10 0.30 0.10 0.30 0.20 0.10 0.20"
POLYGON_AS_BOX = (0.2, 0.15, 0.2, 0.1)


def _make_dataset(tmp_path: Path, files: dict[str, list[str]], split: str = "train") -> Path:
    root = tmp_path / "mini-dataset"
    (root / split / "labels").mkdir(parents=True)
    (root / split / "images").mkdir(parents=True)
    (root / "data.yaml").write_text(
        f"train: ../{split}/images\nval: ../{split}/images\n\nnc: 7\n{CLASS_LINE}\n",
        encoding="utf-8",
    )
    for name, rows in files.items():
        (root / split / "labels" / name).write_text("\n".join(rows) + "\n", encoding="utf-8")
        (root / split / "images" / name.replace(".txt", ".jpg")).write_bytes(b"")
    return root


@pytest.fixture
def mixed_dataset(tmp_path: Path) -> Path:
    """One clean file, one mixed file - the shape of the real corruption."""

    return _make_dataset(tmp_path, {
        "clean.txt": [GOOD_BBOX, "3 0.250000 0.250000 0.100000 0.100000"],
        "mixed.txt": [GOOD_BBOX, POLYGON],
    })


class TestParsing:
    def test_row_kinds_are_recognised(self, mixed_dataset):
        rows = dataset_tools.read_label_file(mixed_dataset / "train" / "labels" / "mixed.txt")
        assert [row.kind for row in rows] == ["bbox", "polygon"]
        assert rows[0].field_count == 5
        assert rows[1].field_count == 9

    def test_blank_lines_are_skipped(self, tmp_path):
        path = tmp_path / "labels.txt"
        path.write_text(f"\n{GOOD_BBOX}\n\n   \n", encoding="utf-8")
        assert len(dataset_tools.read_label_file(path)) == 1

    def test_a_non_numeric_row_is_kept_for_reporting(self, tmp_path):
        path = tmp_path / "labels.txt"
        path.write_text("banana 0.1 0.2\n", encoding="utf-8")
        rows = dataset_tools.read_label_file(path)
        assert rows[0].kind == "unknown"
        assert rows[0].class_id == -1

    def test_polygon_to_bbox_is_the_tight_clamped_hull(self):
        assert dataset_tools.polygon_to_bbox([0.10, 0.10, 0.30, 0.10, 0.30, 0.20, 0.10, 0.20]) == pytest.approx(
            POLYGON_AS_BOX
        )

    def test_out_of_range_polygon_coordinates_are_clamped(self):
        cx, cy, width, height = dataset_tools.polygon_to_bbox([-0.5, -0.5, 1.5, 1.5])
        assert (cx, cy) == pytest.approx((0.5, 0.5))
        assert (width, height) == pytest.approx((1.0, 1.0))

    def test_class_names_are_read_without_pyyaml(self, mixed_dataset):
        names = dataset_tools.class_names(mixed_dataset)
        assert len(names) == 7
        assert names[5] == "mrp_region"


class TestLoaderEmulation:
    def test_a_clean_box_file_is_read_as_authored(self, mixed_dataset):
        rows = dataset_tools.read_label_file(mixed_dataset / "train" / "labels" / "clean.txt")
        trained = dataset_tools.ultralytics_trained_boxes(rows)
        assert trained == [dataset_tools.row_to_bbox(row) for row in rows]

    def test_a_bbox_row_inside_a_mixed_file_is_misread(self, mixed_dataset):
        """This is the whole bug: the file is treated as segments, so the 5-field
        row is parsed as a two-point polygon and the box moves."""

        rows = dataset_tools.read_label_file(mixed_dataset / "train" / "labels" / "mixed.txt")
        trained = dataset_tools.ultralytics_trained_boxes(rows)
        authored = dataset_tools.row_to_bbox(rows[0])
        assert trained[0] != pytest.approx(authored)
        assert dataset_tools._iou(authored, trained[0]) < 0.99

    def test_the_polygon_row_survives_because_the_file_looks_like_segments(self, mixed_dataset):
        rows = dataset_tools.read_label_file(mixed_dataset / "train" / "labels" / "mixed.txt")
        assert dataset_tools.ultralytics_trained_boxes(rows)[1] == pytest.approx(POLYGON_AS_BOX)


class TestAudit:
    def test_counters_match_the_fixture(self, mixed_dataset):
        totals = dataset_tools.audit_dataset(mixed_dataset).totals
        assert (totals.files, totals.rows) == (2, 4)
        assert (totals.bbox_rows, totals.polygon_rows) == (3, 1)
        assert totals.mixed_files == 1
        assert totals.corrupted_rows == 1  # only the box row inside mixed.txt

    def test_the_mixed_file_is_named(self, mixed_dataset):
        report = dataset_tools.audit_dataset(mixed_dataset)
        assert report.mixed_file_names == ["train/mixed.txt"]
        assert report.is_clean is False

    def test_worst_corruptions_are_ranked_by_misplaced_area(self, mixed_dataset):
        report = dataset_tools.audit_dataset(mixed_dataset)
        assert len(report.worst_corruptions) == 1
        item = report.worst_corruptions[0]
        assert item["file"] == "train/mixed.txt"
        assert item["class_name"] == "mrp_region"
        assert item["iou"] < 0.99

    def test_a_pure_box_dataset_audits_clean(self, tmp_path):
        dataset = _make_dataset(tmp_path, {"a.txt": [GOOD_BBOX]})
        report = dataset_tools.audit_dataset(dataset)
        assert report.is_clean
        assert report.totals.mixed_files == 0

    def test_a_pure_polygon_dataset_audits_clean_but_is_still_repaired_later(self, tmp_path):
        """Uniform polygons are read correctly by the loader, so they are not
        *corruption* - but repair still normalizes them so no future edit can
        tip the file into the mixed state."""

        dataset = _make_dataset(tmp_path, {"a.txt": [POLYGON, POLYGON]})
        assert dataset_tools.audit_dataset(dataset).is_clean
        assert dataset_tools.repair_dataset(dataset).rows_converted == 2

    def test_tiny_boxes_are_counted_separately(self, tmp_path):
        dataset = _make_dataset(tmp_path, {"a.txt": ["0 0.5 0.5 0.002 0.002"]})
        assert dataset_tools.audit_dataset(dataset).totals.tiny_rows == 1

    def test_the_report_serializes_and_renders(self, mixed_dataset):
        report = dataset_tools.audit_dataset(mixed_dataset)
        payload = json.loads(json.dumps(report.to_dict()))
        assert payload["totals"]["corrupted_rows"] == 1
        assert payload["splits"]["train"]["files"] == 2
        assert "mrp_region" in report.render()


class TestRepair:
    def test_every_row_ends_up_in_the_canonical_five_field_form(self, mixed_dataset):
        summary = dataset_tools.repair_dataset(mixed_dataset)
        assert summary.files_scanned == 2
        assert summary.rows_converted == 1
        assert summary.rows_unchanged == 3
        rows = dataset_tools.read_label_file(mixed_dataset / "train" / "labels" / "mixed.txt")
        assert {row.field_count for row in rows} == {5}

    def test_the_converted_polygon_keeps_its_class_and_its_hull(self, mixed_dataset):
        dataset_tools.repair_dataset(mixed_dataset)
        rows = dataset_tools.read_label_file(mixed_dataset / "train" / "labels" / "mixed.txt")
        assert rows[1].class_id == 1
        assert rows[1].values == pytest.approx(POLYGON_AS_BOX)

    def test_an_already_clean_file_is_left_alone(self, mixed_dataset):
        before = (mixed_dataset / "train" / "labels" / "clean.txt").read_bytes()
        dataset_tools.repair_dataset(mixed_dataset)
        assert (mixed_dataset / "train" / "labels" / "clean.txt").read_bytes() == before

    def test_originals_are_backed_up_before_the_first_rewrite(self, mixed_dataset):
        original = (mixed_dataset / "train" / "labels" / "mixed.txt").read_text(encoding="utf-8")
        summary = dataset_tools.repair_dataset(mixed_dataset)
        backup = mixed_dataset / "train" / dataset_tools.BACKUP_DIR_NAME / "mixed.txt"
        assert summary.backups_created == 1
        assert backup.read_text(encoding="utf-8") == original

    def test_a_second_run_does_not_clobber_the_backup(self, mixed_dataset):
        dataset_tools.repair_dataset(mixed_dataset)
        backup = mixed_dataset / "train" / dataset_tools.BACKUP_DIR_NAME / "mixed.txt"
        preserved = backup.read_text(encoding="utf-8")
        second = dataset_tools.repair_dataset(mixed_dataset)
        assert second.files_rewritten == 0
        assert second.backups_created == 0
        assert backup.read_text(encoding="utf-8") == preserved

    def test_repair_is_idempotent_on_disk(self, mixed_dataset):
        dataset_tools.repair_dataset(mixed_dataset)
        after_first = (mixed_dataset / "train" / "labels" / "mixed.txt").read_bytes()
        dataset_tools.repair_dataset(mixed_dataset)
        assert (mixed_dataset / "train" / "labels" / "mixed.txt").read_bytes() == after_first

    def test_the_manifest_records_what_changed(self, mixed_dataset):
        dataset_tools.repair_dataset(mixed_dataset)
        manifest = json.loads(
            (mixed_dataset / dataset_tools.MANIFEST_NAME).read_text(encoding="utf-8")
        )
        assert manifest["rows_converted"] == 1
        assert [entry["file"] for entry in manifest["files"]] == ["mixed.txt"]
        assert manifest["files"][0]["polygons_converted"] == 1

    def test_a_dry_run_writes_nothing(self, mixed_dataset):
        before = (mixed_dataset / "train" / "labels" / "mixed.txt").read_bytes()
        summary = dataset_tools.repair_dataset(mixed_dataset, dry_run=True)
        assert summary.files_rewritten == 1
        assert (mixed_dataset / "train" / "labels" / "mixed.txt").read_bytes() == before
        assert not (mixed_dataset / dataset_tools.MANIFEST_NAME).exists()
        assert not (mixed_dataset / "train" / dataset_tools.BACKUP_DIR_NAME).exists()

    def test_a_degenerate_row_is_dropped_rather_than_trained_on(self, tmp_path):
        dataset = _make_dataset(tmp_path, {"a.txt": [GOOD_BBOX, "2 0.5 0.5 0.0 0.0"]})
        summary = dataset_tools.repair_dataset(dataset)
        assert len(summary.rows_dropped) == 1
        assert len(dataset_tools.read_label_file(dataset / "train" / "labels" / "a.txt")) == 1

    def test_a_stale_label_cache_is_removed(self, mixed_dataset):
        cache = mixed_dataset / "train" / "labels.cache"
        cache.write_bytes(b"stale")
        summary = dataset_tools.repair_dataset(mixed_dataset)
        # The paths are relative to the dataset root and use the OS separator.
        assert [Path(entry).as_posix() for entry in summary.caches_removed] == ["train/labels.cache"]
        assert not cache.exists()

    def test_repair_touches_only_the_splits_it_was_given(self, tmp_path):
        dataset = _make_dataset(tmp_path, {"a.txt": [POLYGON]}, split="train")
        (dataset / "valid" / "labels").mkdir(parents=True)
        (dataset / "valid" / "labels" / "b.txt").write_text(f"{POLYGON}\n", encoding="utf-8")
        dataset_tools.repair_dataset(dataset, splits=("valid",))
        assert dataset_tools.read_label_file(dataset / "train" / "labels" / "a.txt")[0].kind == "polygon"
        assert dataset_tools.read_label_file(dataset / "valid" / "labels" / "b.txt")[0].kind == "bbox"


class TestVerify:
    def test_a_mixed_dataset_is_reported_as_broken(self, mixed_dataset):
        problems = dataset_tools.verify_dataset(mixed_dataset)
        assert problems
        assert any("9 fields" in problem for problem in problems)
        assert any("relocate" in problem for problem in problems)

    def test_verify_is_silent_after_repair(self, mixed_dataset):
        dataset_tools.repair_dataset(mixed_dataset)
        assert dataset_tools.verify_dataset(mixed_dataset) == []

    def test_the_audit_is_clean_after_repair(self, mixed_dataset):
        dataset_tools.repair_dataset(mixed_dataset)
        totals = dataset_tools.audit_dataset(mixed_dataset).totals
        assert (totals.bbox_rows, totals.polygon_rows) == (4, 0)
        assert (totals.mixed_files, totals.corrupted_rows) == (0, 0)

    def test_an_out_of_range_class_id_is_caught(self, tmp_path):
        dataset = _make_dataset(tmp_path, {"a.txt": ["99 0.5 0.5 0.2 0.1"]})
        assert any("class id 99" in problem for problem in dataset_tools.verify_dataset(dataset))

    def test_a_box_crossing_the_image_edge_is_caught(self, tmp_path):
        dataset = _make_dataset(tmp_path, {"a.txt": ["0 0.95 0.5 0.2 0.1"]})
        assert any("left/right image edge" in problem for problem in dataset_tools.verify_dataset(dataset))


class TestCli:
    def test_audit_exits_nonzero_on_a_broken_dataset_and_writes_json(self, mixed_dataset, tmp_path):
        out = tmp_path / "audit.json"
        code = dataset_tools.main(["--dataset", str(mixed_dataset), "audit", "--json", str(out)])
        assert code == 1
        assert json.loads(out.read_text(encoding="utf-8"))["totals"]["corrupted_rows"] == 1

    def test_repair_then_verify_exits_zero(self, mixed_dataset):
        dataset = ["--dataset", str(mixed_dataset)]
        assert dataset_tools.main(dataset + ["repair"]) == 0
        assert dataset_tools.main(dataset + ["verify"]) == 0
        assert dataset_tools.main(dataset + ["audit"]) == 0

    def test_verify_exits_nonzero_before_repair(self, mixed_dataset):
        assert dataset_tools.main(["--dataset", str(mixed_dataset), "verify"]) == 1

    def test_a_missing_dataset_is_reported_rather_than_traced(self, tmp_path):
        assert dataset_tools.main(["--dataset", str(tmp_path / "nope"), "verify"]) == 2
