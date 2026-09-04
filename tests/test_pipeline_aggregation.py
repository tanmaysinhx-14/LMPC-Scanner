"""Tests for what the rule engine is actually handed.

``pipeline.read_region`` picks one reading per crop and ``pipeline.join_readings``
concatenates a class's readings across boxes and photographs. Both sit between the
OCR engines and ``rule_engine``, so a mistake here looks like a rule bug: the
parser is blamed for text it was never given, or for text it was given twice.

A stub backend stands in for RapidOCR/EasyOCR, so nothing here needs torch, an
ONNX runtime, or the trained checkpoint.
"""

from __future__ import annotations

import numpy as np

import pipeline
import preprocessing
from conftest import render_text_image
from ocr_backends import OcrLine, OcrResult


class OrderedStub:
    """Answers by call order, which is what variant order means to a caller."""

    def __init__(self, scripted: list[tuple[str, float]]):
        self.scripted = scripted
        self.calls = 0
        self.name = "stub"

    def read(self, image: np.ndarray, allowlist: str | None = None) -> OcrResult:
        text, confidence = self.scripted[min(self.calls, len(self.scripted) - 1)]
        self.calls += 1
        lines = [OcrLine(text, confidence, (0, 0, 10, 10))] if text else []
        return OcrResult(backend=self.name, lines=lines, elapsed_s=0.01)


class TestJoinReadings:
    def test_separate_boxes_are_concatenated_in_order(self):
        """The annotations split the MRP tag, the price and the tax phrase on
        purpose, so the parser is only ever meant to see them joined."""

        assert pipeline.join_readings(["MRP", "Rs. 29.00", "inclusive of all taxes"]) == (
            "MRP Rs. 29.00 inclusive of all taxes"
        )

    def test_the_same_declaration_read_twice_is_kept_once(self):
        assert pipeline.join_readings(["MRP 29.00", "MRP 29.00"]) == "MRP 29.00"

    def test_case_spacing_and_punctuation_do_not_defeat_the_check(self):
        assert pipeline.join_readings(["MRP: Rs 29.00", "mrp rs29,00"]) == "MRP: Rs 29.00"

    def test_the_first_spelling_is_the_one_kept(self):
        assert pipeline.join_readings(["Net Qty 500 g", "NETQTY500G"]) == "Net Qty 500 g"

    def test_genuinely_different_readings_all_survive(self):
        assert pipeline.join_readings(["PKD 10/2026", "USE BY 04/2027"]) == "PKD 10/2026 USE BY 04/2027"

    def test_blank_and_whitespace_readings_are_dropped(self):
        assert pipeline.join_readings(["", "   ", "500 g"]) == "500 g"

    def test_nothing_to_join_is_an_empty_string(self):
        assert pipeline.join_readings([]) == ""
        assert pipeline.join_readings(["", ""]) == ""

    def test_punctuation_only_readings_are_not_collapsed_into_each_other(self):
        """Two boxes reading "-" and "/" have no alphanumerics to fingerprint, so
        neither may swallow the other."""

        assert pipeline.join_readings(["-", "/"]) == "- /"

    def test_surrounding_whitespace_is_trimmed_per_reading(self):
        assert pipeline.join_readings(["  MRP 29.00  ", "\n500 g\t"]) == "MRP 29.00 500 g"


class TestReadRegionVariantOrder:
    def test_variants_are_tried_in_the_order_preprocessing_publishes(self):
        stub = OrderedStub([("first", 0.1), ("second", 0.1), ("third", 0.1)])
        pipeline.read_region(render_text_image(), "mrp_declaration", stub)
        assert stub.calls == len(preprocessing.variant_names("mrp_declaration")) == 3

    def test_max_variants_truncates_from_the_front(self):
        """``--fast`` is this path: it keeps only the first prepared variant."""

        stub = OrderedStub([("only", 0.9)])
        reading = pipeline.read_region(render_text_image(), "mrp_declaration", stub, max_variants=1)
        assert stub.calls == 1
        assert [candidate["variant"] for candidate in reading["candidates"]] == ["plain"]

    def test_two_variants_are_tried_when_asked_for(self):
        stub = OrderedStub([("a", 0.2), ("b", 0.2), ("c", 0.2)])
        reading = pipeline.read_region(render_text_image(), "date_declarations", stub, max_variants=2)
        assert [candidate["variant"] for candidate in reading["candidates"]] == ["plain", "dotmatrix"]

    def test_the_best_scoring_candidate_wins_not_the_first(self):
        stub = OrderedStub([("Rs", 0.30), ("MRP Rs. 29.00 inclusive of all taxes", 0.95)])
        reading = pipeline.read_region(render_text_image(), "mrp_declaration", stub, max_variants=2)
        assert reading["text"] == "MRP Rs. 29.00 inclusive of all taxes"
        assert reading["variant"] == "dotmatrix"
        assert reading["score"] > reading["candidates"][0]["score"]

    def test_every_candidate_is_recorded_even_when_it_loses(self):
        """The extraction sweep reads these to compare variants after the fact."""

        stub = OrderedStub([("a", 0.4), ("bb", 0.5), ("ccc", 0.6)])
        reading = pipeline.read_region(render_text_image(), "mrp_declaration", stub)
        assert len(reading["candidates"]) == 3
        assert all("score" in candidate and "seconds" in candidate for candidate in reading["candidates"])

    def test_an_empty_crop_reads_as_nothing_without_calling_the_engine(self):
        stub = OrderedStub([("never", 1.0)])
        reading = pipeline.read_region(np.zeros((0, 0, 3), dtype=np.uint8), "mrp_declaration", stub)
        assert stub.calls == 0
        assert reading["text"] == ""
        assert reading["candidates"] == []

    def test_a_backend_error_is_carried_on_the_candidate(self):
        class FailingStub:
            name = "stub"

            def read(self, image, allowlist=None):
                return OcrResult(backend="stub", error="engine exploded")

        reading = pipeline.read_region(render_text_image(), "product_name", FailingStub())
        assert reading["text"] == ""
        assert all(candidate["error"] == "engine exploded" for candidate in reading["candidates"])


class TestReadRegionEarlyStop:
    def test_a_compliant_confident_first_reading_stops_the_loop(self):
        """This is why product_name is cheap: 72% of its crops stop here."""

        stub = OrderedStub([("Britannia Marie Gold Biscuits", 0.99), ("never asked", 0.99)])
        reading = pipeline.read_region(render_text_image(), "product_name", stub)
        assert stub.calls == 1
        assert reading["parsed"] is True

    def test_a_confident_but_unparseable_reading_does_not_stop_the_loop(self):
        """Confidence alone is not enough - the reading has to be usable."""

        stub = OrderedStub([("!!!!", 0.99), ("!!!!", 0.99)])
        pipeline.read_region(render_text_image(), "net_quantity", stub)
        assert stub.calls == len(preprocessing.variant_names("net_quantity"))

    def test_the_stop_threshold_is_honoured(self):
        stub = OrderedStub([("Britannia Marie Gold Biscuits", 0.80), ("second", 0.80)])
        pipeline.read_region(render_text_image(), "product_name", stub, stop_score=0.99)
        assert stub.calls == 2
