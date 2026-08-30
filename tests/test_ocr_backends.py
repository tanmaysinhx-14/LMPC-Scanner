"""OCR backend layer tests.

These never load a real engine. Loading EasyOCR costs ~15 s and RapidOCR needs its
ONNX weights on disk, so the whole suite would become unrunnable on a clean
checkout. What is worth pinning here is the *contract* around the engines - lazy
loading, the lock, error containment, reading order, confidence weighting and the
fallback registry - because that is the code this repository owns. The engines
themselves are exercised by ``benchmark_ocr.py`` against real crops.
"""

from __future__ import annotations

import numpy as np
import pytest

import ocr_backends
from ocr_backends import OcrLine, OcrResult


def _line(text: str, confidence: float, x1: int, y1: int, x2: int, y2: int) -> OcrLine:
    return OcrLine(text, confidence, (x1, y1, x2, y2))


class FakeBackend(ocr_backends.OcrBackend):
    """A backend whose engine is a list of lines, with switchable failure modes."""

    name = "fake"

    def __init__(self, lines=None, fail_init=False, fail_read=False) -> None:
        super().__init__()
        self.lines = list(lines or [])
        self.fail_init = fail_init
        self.fail_read = fail_read
        self.builds = 0
        self.reads = 0
        self.last_kwargs: dict = {}

    def _build_engine(self):
        self.builds += 1
        if self.fail_init:
            raise RuntimeError("no weights on this machine")
        return object()

    def _recognize(self, engine, image, **kwargs):
        self.reads += 1
        self.last_kwargs = dict(kwargs)
        if self.fail_read:
            raise ValueError("bad crop")
        return list(self.lines)


@pytest.fixture
def registry():
    """Isolate the module-level backend registry for one test."""

    factories = dict(ocr_backends._FACTORIES)
    instances = dict(ocr_backends._INSTANCES)
    ocr_backends._INSTANCES.clear()
    try:
        yield ocr_backends._FACTORIES
    finally:
        ocr_backends._FACTORIES.clear()
        ocr_backends._FACTORIES.update(factories)
        ocr_backends._INSTANCES.clear()
        ocr_backends._INSTANCES.update(instances)


@pytest.fixture
def crop():
    return np.full((40, 120, 3), 220, dtype=np.uint8)


class TestSortLines:
    def test_columns_on_one_row_are_read_left_to_right(self):
        ordered = ocr_backends.sort_lines([
            _line("Rs. 29.00", 0.9, 90, 10, 180, 26),
            _line("MRP", 0.9, 10, 11, 60, 27),
        ])
        assert [line.text for line in ordered] == ["MRP", "Rs. 29.00"]

    def test_rows_are_read_top_to_bottom(self):
        ordered = ocr_backends.sort_lines([
            _line("NET QTY 500 g", 0.9, 10, 60, 150, 76),
            _line("MRP Rs. 29.00", 0.9, 10, 10, 150, 26),
        ])
        assert [line.text for line in ordered] == ["MRP Rs. 29.00", "NET QTY 500 g"]

    def test_a_slightly_offset_line_still_counts_as_the_same_row(self):
        """Hand-held capture skews a panel by a few pixels; that must not reorder it."""

        ordered = ocr_backends.sort_lines([
            _line("B", 0.9, 100, 14, 140, 30),
            _line("A", 0.9, 10, 10, 50, 26),
        ])
        assert [line.text for line in ordered] == ["A", "B"]

    def test_empty_input_is_not_an_error(self):
        assert ocr_backends.sort_lines([]) == []


class TestOcrResult:
    def test_text_joins_in_reading_order_and_drops_blanks(self):
        result = OcrResult("fake", [
            _line("  ", 0.9, 0, 0, 5, 5),
            _line("29.00", 0.9, 90, 10, 180, 26),
            _line("MRP", 0.9, 10, 11, 60, 27),
        ])
        assert result.text == "MRP 29.00"
        assert result.line_count == 2

    def test_confidence_is_character_weighted(self):
        """A 20-character line must outweigh a stray "g"; a plain mean would not."""

        result = OcrResult("fake", [
            _line("MANUFACTURED BY XYZ", 0.9, 0, 0, 200, 16),
            _line("g", 0.1, 0, 20, 8, 36),
        ])
        assert result.confidence == pytest.approx((19 * 0.9 + 1 * 0.1) / 20)

    def test_confidence_is_none_when_nothing_was_read(self):
        assert OcrResult("fake").confidence is None
        assert OcrResult("fake").text == ""


class TestQuadToBox:
    def test_a_rotated_quad_collapses_to_its_hull(self):
        assert ocr_backends._quad_to_box([[12.7, 4.2], [98.0, 9.9], [96.1, 30.4], [10.0, 25.0]]) == (10, 4, 98, 30)


class TestBackendContract:
    def test_the_engine_is_built_once_and_reused(self, crop):
        backend = FakeBackend([_line("500 g", 0.8, 0, 0, 50, 16)])
        backend.read(crop)
        backend.read(crop)
        assert backend.builds == 1
        assert backend.reads == 2

    def test_nothing_loads_until_the_first_read(self):
        backend = FakeBackend()
        assert backend.describe()["ready"] is False
        assert backend.builds == 0

    def test_a_successful_read_reports_timing_and_no_error(self, crop):
        result = FakeBackend([_line("MRP", 0.7, 0, 0, 30, 16)]).read(crop)
        assert result.error is None
        assert result.text == "MRP"
        assert result.elapsed_s >= 0.0
        assert result.backend == "fake"

    def test_an_empty_crop_is_rejected_without_loading_the_engine(self):
        backend = FakeBackend()
        result = backend.read(np.zeros((0, 0, 3), dtype=np.uint8))
        assert result.error == "empty image"
        assert backend.builds == 0

    def test_a_none_image_is_rejected(self):
        assert FakeBackend().read(None).error == "empty image"

    def test_a_failing_engine_load_becomes_an_error_not_an_exception(self, crop):
        backend = FakeBackend(fail_init=True)
        result = backend.read(crop)
        assert "no weights" in (result.error or "")
        assert result.lines == []

    def test_a_failed_load_is_not_retried_on_every_crop(self, crop):
        backend = FakeBackend(fail_init=True)
        backend.read(crop)
        second = backend.read(crop)
        assert backend.builds == 1  # one attempt, then remembered
        assert "previously failed to load" in (second.error or "")

    def test_a_bad_crop_degrades_instead_of_crashing_the_scan(self, crop):
        result = FakeBackend(fail_read=True).read(crop)
        assert result.error == "bad crop"
        assert result.lines == []

    def test_is_available_reflects_whether_the_engine_loads(self):
        assert FakeBackend().is_available() is True
        assert FakeBackend(fail_init=True).is_available() is False

    def test_describe_surfaces_the_load_failure(self):
        backend = FakeBackend(fail_init=True)
        backend.is_available()
        info = backend.describe()
        assert info["name"] == "fake"
        assert info["ready"] is False
        assert "no weights" in info["init_error"]

    def test_keyword_arguments_reach_the_engine(self, crop):
        backend = FakeBackend()
        backend.read(crop, allowlist="0123456789")
        assert backend.last_kwargs == {"allowlist": "0123456789"}


class TestRegistry:
    def test_the_real_backends_are_registered(self):
        assert ocr_backends.backend_names() == ["easyocr", "rapidocr"]

    def test_rapidocr_is_the_default_backend(self):
        assert ocr_backends.DEFAULT_BACKEND == "rapidocr"

    def test_get_backend_returns_one_shared_instance(self, registry):
        registry["fake"] = FakeBackend
        assert ocr_backends.get_backend("fake") is ocr_backends.get_backend("FAKE ")

    def test_an_unknown_name_names_the_known_ones(self, registry):
        with pytest.raises(KeyError, match="rapidocr"):
            ocr_backends.get_backend("paddleocr")

    def test_available_backends_skips_the_ones_that_cannot_load(self, registry):
        registry.clear()
        registry["fake"] = FakeBackend
        registry["broken"] = lambda: FakeBackend(fail_init=True)
        assert [backend.name for backend in ocr_backends.available_backends()] == ["fake"]

    def test_resolve_backend_falls_back_to_a_working_engine(self, registry):
        """The app must survive one engine's native dependencies breaking - which is
        exactly how PaddleOCR was lost on this machine."""

        registry.clear()
        registry["broken"] = lambda: FakeBackend(fail_init=True)
        registry["working"] = FakeBackend
        resolved = ocr_backends.resolve_backend("broken")
        assert resolved.is_available()
        assert resolved is not ocr_backends.get_backend("broken")

    def test_resolve_backend_prefers_the_requested_engine(self, registry):
        registry.clear()
        registry["fake"] = FakeBackend
        assert ocr_backends.resolve_backend("fake") is ocr_backends.get_backend("fake")

    def test_resolve_backend_explains_itself_when_nothing_works(self, registry):
        registry.clear()
        registry["broken"] = lambda: FakeBackend(fail_init=True)
        with pytest.raises(RuntimeError, match="pip install"):
            ocr_backends.resolve_backend("broken")


class TestRapidOcrNormalisation:
    def test_a_single_channel_crop_is_widened_to_three(self, monkeypatch):
        """``dotmatrix`` crops arrive 2-D; RapidOCR requires 3 channels."""

        seen: dict = {}

        def engine(image):
            seen["shape"] = image.shape
            return ([[[[0, 0], [50, 0], [50, 16], [0, 16]], "500 g", 0.91]], None)

        backend = ocr_backends.RapidOcrBackend()
        lines = backend._recognize(engine, np.full((16, 50), 128, dtype=np.uint8))
        assert seen["shape"] == (16, 50, 3)
        assert lines == [OcrLine("500 g", 0.91, (0, 0, 50, 16))]

    def test_malformed_engine_output_is_skipped(self):
        def engine(image):
            return ([None, [[[0, 0], [10, 0], [10, 8], [0, 8]], "  ", 0.5]], None)

        backend = ocr_backends.RapidOcrBackend()
        assert backend._recognize(engine, np.zeros((8, 10, 3), dtype=np.uint8)) == []


class TestEasyOcrNormalisation:
    def test_the_tuned_detector_thresholds_are_passed_through(self):
        seen: dict = {}

        class Engine:
            def readtext(self, image, **kwargs):
                seen.update(kwargs)
                return [([[0, 0], [40, 0], [40, 16], [0, 16]], "MRP", 0.42)]

        backend = ocr_backends.EasyOcrBackend()
        lines = backend._recognize(Engine(), np.zeros((16, 40, 3), dtype=np.uint8))
        assert lines == [OcrLine("MRP", 0.42, (0, 0, 40, 16))]
        assert seen["text_threshold"] == 0.55
        assert seen["paragraph"] is False

    def test_an_allowlist_is_forwarded(self):
        seen: dict = {}

        class Engine:
            def readtext(self, image, **kwargs):
                seen.update(kwargs)
                return []

        ocr_backends.EasyOcrBackend()._recognize(
            Engine(), np.zeros((16, 40, 3), dtype=np.uint8), allowlist="0123456789."
        )
        assert seen["allowlist"] == "0123456789."

    def test_an_empty_reading_is_dropped(self):
        class Engine:
            def readtext(self, image, **kwargs):
                return [([[0, 0], [1, 0], [1, 1], [0, 1]], "   ", 0.9)]

        assert ocr_backends.EasyOcrBackend()._recognize(Engine(), np.zeros((4, 4, 3), dtype=np.uint8)) == []
