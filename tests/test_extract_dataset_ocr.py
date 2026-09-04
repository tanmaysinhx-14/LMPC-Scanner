"""Tests for the offline rule-tuning sweep's bookkeeping.

None of this touches YOLO or an OCR engine: it is the file-level logic that
decides what gets re-processed on resume and what text reaches the rule parsers.
Every case here is one that actually went wrong on the full dataset run - a
resume that redid 258 finished images, a digest that blended two engines'
readings of the same box, a torn line where a killed run met an appended one.
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest

import extract_dataset_ocr as extract
import pipeline


def write_jsonl(path: Path, records: list[dict]) -> Path:
    path.write_text(
        "\n".join(json.dumps(record, ensure_ascii=False) for record in records) + "\n",
        encoding="utf-8",
    )
    return path


def detection(image: str, index: int, canonical: str, text: str) -> dict:
    return {
        "record_type": "detection",
        "image_path": image,
        "image_filename": image.rsplit("/", 1)[-1],
        "detection_index": index,
        "canonical_class": canonical,
        "ocr_text": text,
    }


def done(image: str) -> dict:
    return {"record_type": "image_done", "image_path": image, "image_filename": image}


class TestIterRecords:
    def test_a_torn_line_is_skipped_not_raised(self, tmp_path):
        """A killed run leaves one unparseable line; the rest must still be read."""

        path = tmp_path / "out.jsonl"
        path.write_text(
            json.dumps(detection("a.jpg", 0, "mrp_declaration", "MRP 10"))
            + "\n"
            + '{"record_type": "detection", "ocr_text": "trunc'
            + "\n"
            + json.dumps(done("a.jpg"))
            + "\n",
            encoding="utf-8",
        )
        kinds = [record["record_type"] for record in extract.iter_records(path)]
        assert kinds == ["detection", "image_done"]

    def test_blank_lines_are_ignored(self, tmp_path):
        path = tmp_path / "out.jsonl"
        path.write_text("\n\n" + json.dumps(done("a.jpg")) + "\n\n", encoding="utf-8")
        assert len(list(extract.iter_records(path))) == 1


class TestTrailingNewline:
    def test_a_file_cut_mid_record_gains_its_newline(self, tmp_path):
        """Without this, the next run's first record is spliced onto the partial
        one and *both* become unreadable - which is what happened at line 1872 of
        the full dataset run."""

        path = tmp_path / "out.jsonl"
        path.write_text('{"record_type": "detection", "ocr_conf', encoding="utf-8")
        extract._ensure_trailing_newline(path)
        assert path.read_text(encoding="utf-8").endswith("\n")

        with path.open("a", encoding="utf-8", newline="\n") as handle:
            json.dump(done("a.jpg"), handle)
            handle.write("\n")
        assert [record["record_type"] for record in extract.iter_records(path)] == ["image_done"]

    def test_a_well_terminated_file_is_untouched(self, tmp_path):
        path = write_jsonl(tmp_path / "out.jsonl", [done("a.jpg")])
        before = path.read_bytes()
        extract._ensure_trailing_newline(path)
        assert path.read_bytes() == before

    def test_a_missing_or_empty_file_is_tolerated(self, tmp_path):
        extract._ensure_trailing_newline(tmp_path / "absent.jsonl")
        empty = tmp_path / "empty.jsonl"
        empty.touch()
        extract._ensure_trailing_newline(empty)
        assert empty.read_bytes() == b""


class TestAlreadyProcessed:
    def test_markers_are_read(self, tmp_path):
        path = write_jsonl(
            tmp_path / "out.jsonl",
            [detection("a.jpg", 0, "mrp_declaration", "MRP 10"), done("a.jpg")],
        )
        assert extract.already_processed(path) == {"a.jpg"}

    def test_completion_is_inferred_for_files_written_before_markers_existed(self, tmp_path):
        """The user's first sweep predates ``image_done``, so ``--resume`` skipped
        nothing and re-OCR'd 258 finished images. An image that has a successor
        finished, whether or not it was marked."""

        path = write_jsonl(
            tmp_path / "out.jsonl",
            [
                detection("a.jpg", 0, "mrp_declaration", "MRP 10"),
                detection("b.jpg", 0, "net_quantity", "500 g"),
                detection("c.jpg", 0, "product_name", "Biscuits"),
            ],
        )
        assert extract.already_processed(path) == {"a.jpg", "b.jpg"}

    def test_the_last_image_is_redone_because_it_may_be_truncated(self, tmp_path):
        path = write_jsonl(
            tmp_path / "out.jsonl",
            [detection("a.jpg", 0, "mrp_declaration", "MRP 10"), detection("b.jpg", 0, "net_quantity", "500 g")],
        )
        assert "b.jpg" not in extract.already_processed(path)

    def test_a_marker_on_the_last_image_still_counts(self, tmp_path):
        """Inference must never override an explicit marker."""

        path = write_jsonl(
            tmp_path / "out.jsonl",
            [detection("a.jpg", 0, "mrp_declaration", "MRP 10"), done("a.jpg")],
        )
        assert extract.already_processed(path) == {"a.jpg"}

    def test_a_zero_detection_image_is_not_reprocessed(self, tmp_path):
        """Its marker is the only trace it leaves in the file."""

        path = write_jsonl(tmp_path / "out.jsonl", [done("blank.jpg"), done("next.jpg")])
        assert extract.already_processed(path) == {"blank.jpg", "next.jpg"}

    def test_a_missing_file_means_nothing_is_done(self, tmp_path):
        assert extract.already_processed(tmp_path / "absent.jsonl") == set()

    def test_records_without_an_image_path_are_ignored(self, tmp_path):
        path = write_jsonl(
            tmp_path / "out.jsonl",
            [{"record_type": "summary", "image_count": 2}, detection("a.jpg", 0, "product_name", "Tea"), done("a.jpg")],
        )
        assert extract.already_processed(path) == {"a.jpg"}


class TestNewestPassDetections:
    def test_a_marked_second_pass_replaces_the_first(self, tmp_path):
        path = write_jsonl(
            tmp_path / "out.jsonl",
            [
                detection("a.jpg", 0, "mrp_declaration", "old reading"),
                done("a.jpg"),
                detection("a.jpg", 0, "mrp_declaration", "new reading"),
                done("a.jpg"),
            ],
        )
        assert [record["ocr_text"] for record in extract.newest_pass_detections(path)] == ["new reading"]

    def test_a_restarted_detection_index_delimits_an_unmarked_pass(self, tmp_path):
        """Files from before the marker existed need this second delimiter: the
        writer numbers detections from 0 for each image, so seeing 0 again for an
        image that already has records means a new pass began."""

        path = write_jsonl(
            tmp_path / "out.jsonl",
            [
                detection("a.jpg", 0, "mrp_declaration", "old 1"),
                detection("a.jpg", 1, "net_quantity", "old 2"),
                detection("a.jpg", 0, "mrp_declaration", "new 1"),
                detection("a.jpg", 1, "net_quantity", "new 2"),
            ],
        )
        assert [record["ocr_text"] for record in extract.newest_pass_detections(path)] == ["new 1", "new 2"]

    def test_a_single_pass_file_is_returned_whole(self, tmp_path):
        path = write_jsonl(
            tmp_path / "out.jsonl",
            [
                detection("a.jpg", 0, "mrp_declaration", "MRP 10"),
                detection("a.jpg", 1, "net_quantity", "500 g"),
                done("a.jpg"),
                detection("b.jpg", 0, "product_name", "Tea"),
                done("b.jpg"),
            ],
        )
        assert len(extract.newest_pass_detections(path)) == 3

    def test_interleaved_images_keep_their_own_passes(self, tmp_path):
        path = write_jsonl(
            tmp_path / "out.jsonl",
            [
                detection("a.jpg", 0, "product_name", "a old"),
                detection("b.jpg", 0, "product_name", "b only"),
                detection("a.jpg", 0, "product_name", "a new"),
            ],
        )
        assert sorted(record["ocr_text"] for record in extract.newest_pass_detections(path)) == ["a new", "b only"]

    def test_non_detection_records_are_dropped(self, tmp_path):
        path = write_jsonl(
            tmp_path / "out.jsonl",
            [
                {"record_type": "full_image_ocr", "image_path": "a.jpg", "ocr_text": "whole label"},
                {"record_type": "image_error", "image_path": "a.jpg", "error": "unreadable"},
                detection("a.jpg", 0, "product_name", "Tea"),
            ],
        )
        assert [record["ocr_text"] for record in extract.newest_pass_detections(path)] == ["Tea"]


class TestBuildDigestRows:
    def test_boxes_of_one_class_are_aggregated_per_image(self, tmp_path):
        """The annotations split MRP tag / price / "inclusive of all taxes" across
        boxes on purpose, so only the joined text is meaningful."""

        path = write_jsonl(
            tmp_path / "out.jsonl",
            [
                detection("a.jpg", 0, "mrp_declaration", "MRP"),
                detection("a.jpg", 1, "mrp_declaration", "Rs. 29.00"),
                detection("a.jpg", 2, "mrp_declaration", "inclusive of all taxes"),
            ],
        )
        rows = extract.build_digest_rows(path)
        assert len(rows) == 1
        assert rows[0]["box_count"] == 3
        assert rows[0]["combined_text"] == "MRP Rs. 29.00 inclusive of all taxes"

    def test_a_repeated_reading_reaches_the_parser_once(self, tmp_path):
        path = write_jsonl(
            tmp_path / "out.jsonl",
            [
                detection("a.jpg", 0, "mrp_declaration", "MRP 29.00"),
                detection("a.jpg", 1, "mrp_declaration", "mrp: 29,00"),
            ],
        )
        row = extract.build_digest_rows(path)[0]
        assert row["combined_text"] == "MRP 29.00"
        # Both boxes are still listed, so nothing is hidden from the reader.
        assert row["box_texts"] == ["MRP 29.00", "mrp: 29,00"]
        assert row["box_count"] == 2

    def test_a_stale_pass_does_not_contaminate_the_group(self, tmp_path):
        path = write_jsonl(
            tmp_path / "out.jsonl",
            [
                detection("a.jpg", 0, "net_quantity", "5OO g"),
                done("a.jpg"),
                detection("a.jpg", 0, "net_quantity", "500 g"),
                done("a.jpg"),
            ],
        )
        assert extract.build_digest_rows(path)[0]["combined_text"] == "500 g"

    def test_a_class_with_a_parser_gets_a_compliance_verdict(self, tmp_path):
        path = write_jsonl(tmp_path / "out.jsonl", [detection("a.jpg", 0, "net_quantity", "Net Qty 500 g")])
        row = extract.build_digest_rows(path)[0]
        assert row["is_compliant"] in (True, False)
        assert row["parsed"]

    def test_the_dietary_class_has_no_parser_and_no_verdict(self, tmp_path):
        path = write_jsonl(tmp_path / "out.jsonl", [detection("a.jpg", 0, "dietary_symbol", "VEG")])
        assert extract.build_digest_rows(path)[0]["is_compliant"] is None

    def test_a_group_with_no_text_is_still_reported(self, tmp_path):
        """These are the crops to look at: the box was found, nothing was read."""

        path = write_jsonl(tmp_path / "out.jsonl", [detection("a.jpg", 0, "date_declarations", "")])
        row = extract.build_digest_rows(path)[0]
        assert row["box_count"] == 1
        assert row["combined_text"] == ""
        assert row["is_compliant"] is None

    def test_a_raising_parser_is_recorded_rather_than_propagated(self, tmp_path, monkeypatch):
        def explode(_text: str) -> dict:
            raise ValueError("bad regex")

        monkeypatch.setitem(pipeline._CLASS_PARSERS, "product_name", explode)
        path = write_jsonl(tmp_path / "out.jsonl", [detection("a.jpg", 0, "product_name", "Tea")])
        assert "parser_error" in extract.build_digest_rows(path)[0]["parsed"]

    def test_the_consumer_class_is_scored_twice(self, tmp_path):
        """The app hands that one class the whole pack's text, because the FSSAI
        licence is usually printed in the manufacturer block. The digest has to do
        the same to measure the parser the app runs - and keep the single-region
        verdict beside it so a gain is attributable to the parser, not the text."""

        path = write_jsonl(
            tmp_path / "out.jsonl",
            [
                detection("a.jpg", 0, "consumer_care_fssai", "care@acme.com 1800 123 4567"),
                detection("a.jpg", 1, "manufacturer_details", "FSSAI 10012043000123"),
            ],
        )
        rows = {row["class"]: row for row in extract.build_digest_rows(path)}
        assert rows["consumer_care_fssai"]["is_compliant"] is True
        assert rows["consumer_care_fssai"]["strict_is_compliant"] is False
        assert rows["consumer_care_fssai"]["parsed"]["fssai_license_number"] == "10012043000123"

    def test_every_other_class_is_judged_on_its_own_region(self, tmp_path):
        path = write_jsonl(
            tmp_path / "out.jsonl",
            [
                detection("a.jpg", 0, "mrp_declaration", "MRP Rs. 29.00"),
                detection("a.jpg", 1, "net_quantity", "inclusive of all taxes 200 g"),
            ],
        )
        rows = {row["class"]: row for row in extract.build_digest_rows(path)}
        assert rows["mrp_declaration"]["is_compliant"] is False
        assert rows["mrp_declaration"]["strict_is_compliant"] is False


class TestThroughputTrace:
    """The sweep's own speedometer.

    Every case here is a property the first full sweep needed and did not have:
    it degraded from 0.43 to 2.30 s/crop over 4h15m and nothing said so until the
    output file was analysed afterwards.
    """

    def trace(self, pairs: list[tuple[float, int]], bucket_images: int = 2):
        instance = extract.ThroughputTrace(bucket_images=bucket_images)
        for seconds, crops in pairs:
            instance.record(seconds, crops)
        return instance

    def test_the_rate_is_per_crop_not_per_image(self):
        """Images carry between 0 and a dozen boxes, so per-image time is noise."""

        instance = self.trace([(10.0, 10), (2.0, 1)])
        assert instance.overall_rate == pytest.approx(12.0 / 11.0)

    def test_an_image_with_no_boxes_defines_no_rate(self):
        instance = self.trace([(0.5, 0), (0.5, 0)])
        assert instance.overall_rate is None
        assert instance.opening_rate is None
        assert instance.progress_note() == ""

    def test_the_baseline_is_the_opening_bucket_not_the_running_mean(self):
        """A mean over thousands of images barely moves, which is why the first
        sweep's slowdown was invisible while it ran."""

        instance = self.trace([(1.0, 1), (1.0, 1), (5.0, 1), (5.0, 1)])
        assert instance.opening_rate == pytest.approx(1.0)
        assert instance.recent_rate == pytest.approx(5.0)

    def test_a_degrading_sweep_is_warned_about_exactly_once(self):
        instance = self.trace([(1.0, 1), (1.0, 1), (4.0, 1), (4.0, 1)])
        warning = instance.slowdown_warning()
        assert warning is not None
        assert "--resume" in warning
        assert instance.slowdown_warning() is None

    def test_a_steady_sweep_is_never_warned_about(self):
        instance = self.trace([(1.0, 1)] * 8)
        assert all(instance.slowdown_warning() is None for _ in range(4))

    def test_one_slow_image_does_not_trip_the_warning(self):
        """Only completed buckets are compared, so a single hard image is not a trend."""

        instance = self.trace([(1.0, 1), (1.0, 1), (40.0, 1)], bucket_images=4)
        assert instance.slowdown_warning() is None

    def test_the_rate_curve_survives_into_the_summary(self):
        instance = self.trace([(1.0, 2), (1.0, 2), (6.0, 2)])
        trace = instance.as_records()
        assert [bucket["images"] for bucket in trace] == [2, 1]
        assert trace[0]["seconds_per_crop"] == pytest.approx(0.5)
        assert trace[1]["seconds_per_crop"] == pytest.approx(3.0)
        assert json.dumps(trace)  # has to be writable to the JSONL summary record

    def test_the_progress_note_reports_overall_and_recent(self):
        instance = self.trace([(1.0, 1), (1.0, 1), (5.0, 1), (5.0, 1)])
        note = instance.progress_note()
        assert "3.00s/crop" in note
        assert "5.00 recent" in note


class TestLowConfidenceDefault:
    def test_easyocr_gets_its_own_uncalibrated_default(self):
        """EasyOCR's score is not a probability: judging it on RapidOCR's 0.5
        flagged 5282 of 5419 readings in the full sweep as failures."""

        assert extract.resolve_low_confidence(None, "easyocr") == 0.2
        assert extract.resolve_low_confidence(None, "rapidocr") == 0.5

    def test_an_explicit_flag_always_wins(self):
        assert extract.resolve_low_confidence(0.8, "easyocr") == 0.8
        assert extract.resolve_low_confidence(0.0, "rapidocr") == 0.0

    def test_an_unknown_engine_falls_back(self):
        assert extract.resolve_low_confidence(None, "some-new-engine") == extract.DEFAULT_OCR_LOW_CONFIDENCE


class TestCliValidation:
    def test_digest_only_needs_no_dataset_or_weights(self, tmp_path):
        path = write_jsonl(tmp_path / "out.jsonl", [detection("a.jpg", 0, "product_name", "Tea")])
        assert extract.main(["--output_file", str(path), "--digest_only"]) == 0
        md_path, csv_path = extract.digest_paths(path)
        assert md_path.is_file() and csv_path.is_file()
        assert "product_name" in md_path.read_text(encoding="utf-8")

    def test_digest_only_refuses_a_missing_file(self, tmp_path):
        with pytest.raises(SystemExit):
            extract.main(["--output_file", str(tmp_path / "absent.jsonl"), "--digest_only"])

    def test_digest_only_and_no_digest_contradict(self, tmp_path):
        path = write_jsonl(tmp_path / "out.jsonl", [detection("a.jpg", 0, "product_name", "Tea")])
        with pytest.raises(SystemExit):
            extract.main(["--output_file", str(path), "--digest_only", "--no_digest"])

    def test_a_sweep_still_requires_a_dataset(self, tmp_path):
        with pytest.raises(SystemExit):
            extract.main(["--output_file", str(tmp_path / "out.jsonl")])

    def test_fast_and_a_conflicting_max_variants_are_refused(self, tmp_path, capsys):
        parser = extract.build_parser()
        args = parser.parse_args(
            ["--output_file", str(tmp_path / "out.jsonl"), "--fast", "--max_variants", "3"]
        )
        args.dataset_dir = tmp_path
        args.yolo_weights = write_jsonl(tmp_path / "w.pt", [])
        with pytest.raises(SystemExit):
            extract.validate_args(args, parser)
        assert "--fast" in capsys.readouterr().err

    def test_a_low_confidence_threshold_outside_zero_to_one_is_refused(self, tmp_path):
        parser = extract.build_parser()
        args = parser.parse_args(
            ["--output_file", str(tmp_path / "out.jsonl"), "--ocr_low_conf_threshold", "1.5"]
        )
        args.dataset_dir = tmp_path
        args.yolo_weights = write_jsonl(tmp_path / "w.pt", [])
        with pytest.raises(SystemExit):
            extract.validate_args(args, parser)

    def test_help_renders(self):
        # argparse interpolates help strings, so an unescaped percentage in one
        # of them makes --help raise instead of printing.
        text = extract.build_parser().format_help()
        assert "38.7% to 33.7%" in text
