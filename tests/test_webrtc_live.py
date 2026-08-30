"""Live-capture tests: the guidance logic and the video processor.

The processor is the piece with real concurrency risk (aiortc's worker thread
writes what the Streamlit rerun thread reads), and it is also the piece nobody
can test by looking at a browser. Both the detector and the checkpoint are
stubbed out here so these run in milliseconds without torch weights - the
detector's own accuracy is a training question, not a live-view question.
"""

from __future__ import annotations

import cv2
import numpy as np
import pytest

import pipeline
import webrtc_live
from conftest import render_text_image


def _frame(sharp: bool = True, width: int = 1280, height: int = 720) -> np.ndarray:
    """A frame with enough texture in the centre to score as focused."""

    image = np.full((height, width, 3), 235, dtype=np.uint8)
    panel = render_text_image("MRP Rs. 29.00 USE BY 10/11/2026", width=width // 2, height=height // 3)
    y, x = height // 3, width // 4
    image[y:y + panel.shape[0], x:x + panel.shape[1]] = panel
    if not sharp:
        image = cv2.GaussianBlur(image, (0, 0), 6.0)
    return image


def _detection(canonical: str, confidence: float = 0.8) -> dict:
    """A detection in the exact shape ``pipeline.detect_only`` emits, built by the
    pipeline's own factory so a change to that contract fails here too."""

    return pipeline._new_record(canonical, canonical, confidence, [10, 10, 200, 60])


@pytest.fixture
def stub_detector(monkeypatch):
    """Replace the YOLO checkpoint with a scripted list of detections."""

    calls = {"count": 0, "returns": []}

    def fake_detect_only(image, conf=pipeline.DETECT_CONF, iou=0.5, imgsz=768):
        calls["count"] += 1
        return list(calls["returns"])

    monkeypatch.setattr(pipeline, "_get_model", lambda: object())
    monkeypatch.setattr(pipeline, "detect_only", fake_detect_only)
    return calls


class TestLiveStatus:
    def test_missing_classes_lists_every_unseen_panel(self):
        status = webrtc_live.LiveStatus(classes_found=("product_name", "net_quantity"))
        assert set(status.missing_classes) == set(pipeline.RULE_CLASSES) - {"product_name", "net_quantity"}
        assert len(status.missing_classes) == len(pipeline.RULE_CLASSES) - 2

    def test_coverage_is_a_fraction_of_the_rule_classes(self):
        assert webrtc_live.LiveStatus().coverage == pytest.approx(0.0)
        full = webrtc_live.LiveStatus(classes_found=tuple(pipeline.RULE_CLASSES))
        assert full.coverage == pytest.approx(1.0)

    def test_unknown_class_names_do_not_inflate_coverage(self):
        status = webrtc_live.LiveStatus(classes_found=("veg_nonveg_mark", "barcode"))
        assert status.coverage == pytest.approx(0.0)


class TestGuidance:
    def test_detector_error_is_the_only_hint_shown(self):
        status = webrtc_live.LiveStatus(frames=5, detector_error="best.pt not found")
        hints = webrtc_live.guidance_for(status, webrtc_live.LiveSettings())
        assert len(hints) == 1
        assert "best.pt not found" in hints[0]

    def test_waits_for_the_first_frame(self):
        hints = webrtc_live.guidance_for(webrtc_live.LiveStatus(), webrtc_live.LiveSettings())
        assert hints == ["Waiting for the first video frame."]

    def test_focus_is_reported_before_framing(self):
        """A blurred frame cannot be fixed by better framing, so focus leads."""

        settings = webrtc_live.LiveSettings(sharpness_floor=300.0)
        status = webrtc_live.LiveStatus(frames=10, sharpness=50.0, detections=0)
        hints = webrtc_live.guidance_for(status, settings)
        assert "blurred" in hints[0]
        assert any("panel" in hint for hint in hints[1:])

    def test_slightly_soft_is_distinguished_from_very_blurred(self):
        settings = webrtc_live.LiveSettings(sharpness_floor=300.0)
        soft = webrtc_live.LiveStatus(frames=10, sharpness=250.0, detections=3,
                                      classes_found=tuple(pipeline.RULE_CLASSES))
        assert "Slightly soft" in webrtc_live.guidance_for(soft, settings)[0]

    def test_partial_coverage_names_the_missing_panels(self):
        settings = webrtc_live.LiveSettings(sharpness_floor=100.0, min_classes=3)
        status = webrtc_live.LiveStatus(frames=10, sharpness=900.0, detections=1,
                                        classes_found=("product_name",))
        hint = " ".join(webrtc_live.guidance_for(status, settings))
        assert "move closer" in hint
        assert "missing:" in hint

    def test_low_resolution_stream_is_called_out(self):
        settings = webrtc_live.LiveSettings(sharpness_floor=100.0, min_classes=1)
        status = webrtc_live.LiveStatus(frames=10, sharpness=900.0, detections=7,
                                        classes_found=tuple(pipeline.RULE_CLASSES),
                                        frame_size=(640, 480))
        assert any("low resolution" in hint for hint in webrtc_live.guidance_for(status, settings))

    def test_a_good_frame_says_so(self):
        settings = webrtc_live.LiveSettings(sharpness_floor=100.0, min_classes=3)
        status = webrtc_live.LiveStatus(frames=10, sharpness=1200.0, detections=7,
                                        classes_found=tuple(pipeline.RULE_CLASSES),
                                        frame_size=(1920, 1080))
        assert webrtc_live.guidance_for(status, settings) == ["Good frame - capture now."]


class TestFocusScore:
    def test_blur_lowers_the_score(self):
        assert webrtc_live.focus_score(_frame(sharp=True)) > webrtc_live.focus_score(_frame(sharp=False))

    def test_only_the_centre_is_measured(self):
        """Detail in the corners must not mask a soft subject in the middle."""

        image = np.full((400, 400, 3), 240, dtype=np.uint8)
        image[0:40, 0:400:2] = 0  # busy top strip, outside the 60% ROI
        assert webrtc_live.focus_score(image, roi=0.6) == pytest.approx(0.0)
        assert webrtc_live.focus_score(image, roi=1.0) > 0.0

    def test_empty_input_scores_zero(self):
        assert webrtc_live.focus_score(np.zeros((0, 0, 3), dtype=np.uint8)) == 0.0


class TestEncodeJpeg:
    def test_round_trips_to_the_same_shape(self):
        image = _frame()
        decoded = cv2.imdecode(np.frombuffer(webrtc_live.encode_jpeg(image), np.uint8), cv2.IMREAD_COLOR)
        assert decoded.shape == image.shape

    def test_lower_quality_is_smaller(self):
        image = _frame()
        assert len(webrtc_live.encode_jpeg(image, quality=40)) < len(webrtc_live.encode_jpeg(image, quality=95))


class TestProcessor:
    def test_first_frame_populates_the_status(self, stub_detector):
        stub_detector["returns"] = [_detection("product_name"), _detection("net_quantity")]
        processor = webrtc_live.LiveScanProcessor()
        processor.process(_frame())

        status = processor.status()
        assert status.frames == 1
        assert status.detect_calls == 1
        assert status.detections == 2
        assert status.classes_found == ("net_quantity", "product_name")
        assert status.frame_size == (1280, 720)
        assert status.sharpness > 0
        assert status.detector_error is None

    def test_detection_is_throttled_but_every_frame_is_scored(self, stub_detector):
        """Detection is the expensive half; at 0.3 s it must not run per frame."""

        settings = webrtc_live.LiveSettings(detect_interval_s=5.0)
        processor = webrtc_live.LiveScanProcessor(settings)
        for _ in range(6):
            processor.process(_frame())

        status = processor.status()
        assert status.frames == 6
        assert status.detect_calls == 1
        assert stub_detector["count"] == 1

    def test_stale_detections_are_reused_between_detect_calls(self, stub_detector):
        stub_detector["returns"] = [_detection("mrp_declaration")]
        processor = webrtc_live.LiveScanProcessor(webrtc_live.LiveSettings(detect_interval_s=5.0))
        processor.process(_frame())
        stub_detector["returns"] = []
        processor.process(_frame())
        assert processor.status().detections == 1

    def test_status_is_a_copy_the_ui_cannot_corrupt(self, stub_detector):
        processor = webrtc_live.LiveScanProcessor()
        processor.process(_frame())
        snapshot = processor.status()
        snapshot.frames = 999
        assert processor.status().frames == 1

    def test_overlay_annotates_and_can_be_turned_off(self, stub_detector):
        stub_detector["returns"] = [_detection("product_name")]
        frame = _frame()

        drawn = webrtc_live.LiveScanProcessor().process(frame.copy())
        assert drawn.shape == frame.shape
        assert not np.array_equal(drawn, frame)

        plain = webrtc_live.LiveScanProcessor(webrtc_live.LiveSettings(draw_overlay=False)).process(frame.copy())
        assert np.array_equal(plain, frame)

    def test_a_detector_crash_does_not_kill_the_stream(self, monkeypatch):
        monkeypatch.setattr(pipeline, "_get_model", lambda: object())

        def explode(*args, **kwargs):
            raise RuntimeError("CUDA out of memory")

        monkeypatch.setattr(pipeline, "detect_only", explode)
        processor = webrtc_live.LiveScanProcessor()
        returned = processor.process(_frame())

        assert returned is not None
        status = processor.status()
        assert status.detector_error is not None and "CUDA" in status.detector_error
        assert status.detections == 0
        assert webrtc_live.guidance_for(status, processor.settings)[0].startswith("Detector unavailable")

    def test_a_missing_checkpoint_is_reported_at_construction(self, monkeypatch):
        def no_weights():
            raise FileNotFoundError("runs/detect/train/weights/best.pt")

        monkeypatch.setattr(pipeline, "_get_model", no_weights)
        processor = webrtc_live.LiveScanProcessor()
        processor.process(_frame())
        assert "best.pt" in (processor.status().detector_error or "")
        assert processor.status().detect_calls == 0


class TestCapture:
    def test_snapshot_returns_the_sharpest_recent_frame(self, stub_detector):
        stub_detector["returns"] = [_detection("product_name")]
        processor = webrtc_live.LiveScanProcessor()
        sharp, soft = _frame(sharp=True), _frame(sharp=False)
        processor.process(soft)
        processor.process(sharp)
        processor.process(soft)

        capture = processor.snapshot()
        assert capture is not None
        assert capture["sharpness"] == pytest.approx(webrtc_live.focus_score(sharp), rel=0.01)
        assert capture["note"] == "manual"
        assert capture["classes_found"] == ["product_name"]
        decoded = cv2.imdecode(np.frombuffer(capture["image_bytes"], np.uint8), cv2.IMREAD_COLOR)
        assert decoded.shape == sharp.shape

    def test_snapshot_before_any_frame_returns_nothing(self, stub_detector):
        assert webrtc_live.LiveScanProcessor().snapshot() is None

    def test_auto_capture_needs_focus_and_coverage(self, stub_detector):
        settings = webrtc_live.LiveSettings(auto_capture=True, sharpness_floor=1.0,
                                            min_classes=2, detect_interval_s=0.0)
        processor = webrtc_live.LiveScanProcessor(settings)

        stub_detector["returns"] = [_detection("product_name")]
        processor.process(_frame())
        assert processor.status().captures_taken == 0  # one panel, below min_classes

        stub_detector["returns"] = [_detection("product_name"), _detection("net_quantity")]
        processor.process(_frame())
        assert processor.status().captures_taken == 1

    def test_auto_capture_respects_the_cooldown(self, stub_detector):
        stub_detector["returns"] = [_detection("product_name"), _detection("net_quantity")]
        settings = webrtc_live.LiveSettings(auto_capture=True, sharpness_floor=1.0,
                                            min_classes=1, cooldown_s=60.0, detect_interval_s=0.0)
        processor = webrtc_live.LiveScanProcessor(settings)
        for _ in range(5):
            processor.process(_frame())
        assert processor.status().captures_taken == 1

    def test_blurred_frames_are_never_auto_captured(self, stub_detector):
        stub_detector["returns"] = [_detection("product_name"), _detection("net_quantity")]
        settings = webrtc_live.LiveSettings(auto_capture=True, sharpness_floor=10_000.0, min_classes=1)
        processor = webrtc_live.LiveScanProcessor(settings)
        processor.process(_frame(sharp=False))
        assert processor.status().captures_taken == 0
        assert processor.drain_captures() == []

    def test_manual_capture_is_not_gated_on_quality(self, stub_detector):
        """The inspector overrides the meter: a soft frame is still evidence."""

        processor = webrtc_live.LiveScanProcessor(webrtc_live.LiveSettings(sharpness_floor=10_000.0))
        processor.process(_frame(sharp=False))
        assert processor.snapshot(note="manual") is not None

    def test_drain_captures_hands_over_once(self, stub_detector):
        stub_detector["returns"] = [_detection("product_name"), _detection("net_quantity")]
        settings = webrtc_live.LiveSettings(auto_capture=True, sharpness_floor=1.0, min_classes=1)
        processor = webrtc_live.LiveScanProcessor(settings)
        processor.process(_frame())

        drained = processor.drain_captures()
        assert len(drained) == 1
        assert drained[0]["note"] == "auto"
        assert processor.drain_captures() == []

    def test_the_capture_count_is_published_without_waiting_for_a_frame(self, stub_detector):
        """The meter reads ``status()``; a lagging counter would under-report."""

        stub_detector["returns"] = [_detection("product_name"), _detection("net_quantity")]
        settings = webrtc_live.LiveSettings(auto_capture=True, sharpness_floor=1.0, min_classes=1)
        processor = webrtc_live.LiveScanProcessor(settings)
        processor.process(_frame())
        assert processor.status().captures_taken == 1

    def test_settings_can_be_swapped_mid_stream(self, stub_detector):
        processor = webrtc_live.LiveScanProcessor()
        processor.update_settings(webrtc_live.LiveSettings(draw_overlay=False, imgsz=320))
        frame = _frame()
        assert np.array_equal(processor.process(frame.copy()), frame)


class TestAvailability:
    def test_reports_a_reason_when_unavailable(self):
        available, reason = webrtc_live.webrtc_available()
        assert isinstance(available, bool)
        assert available is (reason is None)
