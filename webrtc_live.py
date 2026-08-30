"""Live WebRTC capture with a detector overlay and capture guidance.

Why this module exists
---------------------
The SIH26034 problem statement asks for a live camera workflow, not only a file
upload, and the jury called it out. Running the full OCR + rule chain on every
video frame is not an option: one scan costs seconds. So the live path is split:

* **every frame** - cheap work only: a Laplacian focus score and, on a throttle,
  YOLO boxes from ``pipeline.detect_only``, drawn straight into the video so the
  inspector sees which statutory panels the model can already find.
* **on capture** - the expensive chain. The captured still goes through the same
  ``pipeline.analyze_images`` + ``rule_engine.validate_compliance`` path as an
  uploaded photo, so a live scan and an uploaded scan are graded identically.

The guidance is what makes this an accuracy fix rather than a demo. The OCR
benchmark showed statutory text on this dataset is often only 14-20 px tall, and
no engine recovers that; telling the inspector "move closer, hold steady" before
the shutter fires is worth more than any post-processing.

Everything the inspector needs at video rate is drawn *into the frame*, not into
Streamlit widgets, because the frame is the only thing that updates at 20 fps
without a script rerun.

Security note
-------------
``webrtc_streamer`` negotiates a media session with the browser. On localhost
that is local traffic. If this app is exposed on a network, the WebRTC endpoint
is reachable by anyone who can reach the app, so it must sit behind the existing
login *and* TLS - the signalling path has no authentication of its own.
"""

from __future__ import annotations

from collections import deque
from dataclasses import dataclass, field, replace
import logging
import time
from threading import Lock
from typing import Any, Callable, Sequence

import cv2
import numpy as np

import debug_view
import pipeline
import preprocessing

logger = logging.getLogger(__name__)

#: Detection is throttled because YOLO at 768 px costs ~30-60 ms on this GPU and
#: the browser only needs boxes to be *recent*, not per-frame.
DEFAULT_DETECT_INTERVAL_S = 0.30
#: Live inference runs smaller than the scan path: latency matters more than the
#: last point of recall, and the capture is re-run at full size anyway.
LIVE_IMGSZ = 640
#: Laplacian variance below this reads as motion blur or a missed focus lock.
#: Calibrated on 40 test-split photographs of real packs: their focus scores run
#: p10=351, p50=1099, p90=3852, while the same images at 1.6-sigma Gaussian blur
#: collapse to p50=19. 300 therefore sits just under the worst *usable* real
#: capture and an order of magnitude above anything blurred.
SHARPNESS_FLOOR = 300.0
#: How many of the seven statutory panels must be visible before the frame is
#: worth spending 20 s of OCR on.
MIN_PANEL_CLASSES = 3
#: Auto-capture guard, so one steady hand does not queue thirty stills.
CAPTURE_COOLDOWN_S = 4.0
#: Rolling buffer of recent frames; the shutter picks the sharpest of these
#: because the instant a button is pressed is usually the blurriest.
SNAPSHOT_BUFFER = 10
JPEG_QUALITY = 95
#: Focus is measured on the middle of the frame: the pack is what must be sharp,
#: not the shelf behind it.
FOCUS_ROI = 0.6

__all__ = [
    "LiveScanProcessor",
    "LiveSettings",
    "LiveStatus",
    "encode_jpeg",
    "guidance_for",
    "render_live_capture",
    "webrtc_available",
]


@dataclass
class LiveSettings:
    """Everything the inspector can tune from the sidebar, in one object."""

    conf: float = pipeline.DETECT_CONF
    imgsz: int = LIVE_IMGSZ
    detect_interval_s: float = DEFAULT_DETECT_INTERVAL_S
    sharpness_floor: float = SHARPNESS_FLOOR
    min_classes: int = MIN_PANEL_CLASSES
    auto_capture: bool = False
    cooldown_s: float = CAPTURE_COOLDOWN_S
    draw_overlay: bool = True


@dataclass
class LiveStatus:
    """A consistent snapshot of the worker thread, safe to read from the UI."""

    frames: int = 0
    detect_calls: int = 0
    fps: float = 0.0
    detect_ms: float = 0.0
    sharpness: float = 0.0
    detections: int = 0
    classes_found: tuple[str, ...] = ()
    captures_taken: int = 0
    detector_error: str | None = None
    frame_size: tuple[int, int] = (0, 0)

    @property
    def missing_classes(self) -> tuple[str, ...]:
        found = set(self.classes_found)
        return tuple(name for name in pipeline.RULE_CLASSES if name not in found)

    @property
    def coverage(self) -> float:
        return len(set(self.classes_found) & set(pipeline.RULE_CLASSES)) / len(pipeline.RULE_CLASSES)


def guidance_for(status: LiveStatus, settings: LiveSettings) -> list[str]:
    """Plain-language capture hints, worst problem first.

    Ordered deliberately: a blurred frame cannot be fixed by better framing, so
    focus is always the first thing the inspector is told about.
    """

    hints: list[str] = []
    if status.detector_error:
        hints.append(f"Detector unavailable: {status.detector_error}")
        return hints
    if status.frames == 0:
        hints.append("Waiting for the first video frame.")
        return hints
    if status.sharpness < settings.sharpness_floor * 0.6:
        hints.append("Very blurred - hold the phone still and let the camera focus.")
    elif status.sharpness < settings.sharpness_floor:
        hints.append("Slightly soft - steady the pack or add light before capturing.")
    if status.detections == 0:
        hints.append("No statutory panel found - bring the printed label into frame.")
    elif len(status.classes_found) < settings.min_classes:
        hints.append(
            f"Only {len(status.classes_found)} of 7 panels visible - move closer or "
            f"rotate the pack; missing: {', '.join(status.missing_classes[:3])}."
        )
    if min(status.frame_size or (0, 0)) and min(status.frame_size) < 600:
        hints.append("Camera is streaming a low resolution; small print will not be readable.")
    if not hints:
        hints.append("Good frame - capture now.")
    return hints


def encode_jpeg(image: np.ndarray, quality: int = JPEG_QUALITY) -> bytes:
    """BGR array to JPEG bytes, the same currency the upload path already uses."""

    ok, buffer = cv2.imencode(".jpg", image, [int(cv2.IMWRITE_JPEG_QUALITY), int(quality)])
    if not ok:
        raise RuntimeError("failed to JPEG-encode the captured frame")
    return buffer.tobytes()


def focus_score(image: np.ndarray, roi: float = FOCUS_ROI) -> float:
    """Laplacian variance over the middle of the frame."""

    if image is None or image.size == 0:
        return 0.0
    height, width = image.shape[:2]
    half = max(0.1, min(1.0, roi)) / 2.0
    y1, y2 = int(height * (0.5 - half)), int(height * (0.5 + half))
    x1, x2 = int(width * (0.5 - half)), int(width * (0.5 + half))
    return preprocessing.laplacian_sharpness(image[y1:y2, x1:x2])


_HUD_BG = (32, 32, 32)
_HUD_OK = (60, 180, 75)
_HUD_WARN = (0, 165, 255)
_HUD_BAD = (40, 40, 220)


def _hud_color(value: float, floor: float) -> tuple[int, int, int]:
    if value >= floor:
        return _HUD_OK
    return _HUD_WARN if value >= floor * 0.6 else _HUD_BAD


def draw_hud(image: np.ndarray, status: LiveStatus, settings: LiveSettings) -> np.ndarray:
    """Overlay focus, coverage and the top hint directly onto the frame.

    In-frame rather than in Streamlit: the video is the only surface that updates
    without a script rerun, so this is the only guidance the inspector can act on
    while actually aiming the camera.
    """

    height, width = image.shape[:2]
    bar_h = max(28, height // 18)
    overlay = image.copy()
    cv2.rectangle(overlay, (0, 0), (width, bar_h), _HUD_BG, -1)
    cv2.rectangle(overlay, (0, height - bar_h), (width, height), _HUD_BG, -1)
    cv2.addWeighted(overlay, 0.55, image, 0.45, 0, image)

    scale = max(0.4, min(0.7, width / 1600.0))
    focus_color = _hud_color(status.sharpness, settings.sharpness_floor)
    cv2.putText(
        image,
        f"focus {status.sharpness:>6.0f}/{settings.sharpness_floor:.0f}",
        (8, int(bar_h * 0.7)),
        cv2.FONT_HERSHEY_SIMPLEX, scale, focus_color, 1, cv2.LINE_AA,
    )
    panels = f"panels {len(status.classes_found)}/{len(pipeline.RULE_CLASSES)}"
    panel_color = _HUD_OK if len(status.classes_found) >= settings.min_classes else _HUD_WARN
    cv2.putText(
        image, panels, (int(width * 0.34), int(bar_h * 0.7)),
        cv2.FONT_HERSHEY_SIMPLEX, scale, panel_color, 1, cv2.LINE_AA,
    )
    cv2.putText(
        image, f"{status.fps:4.1f} fps  yolo {status.detect_ms:3.0f} ms",
        (int(width * 0.62), int(bar_h * 0.7)),
        cv2.FONT_HERSHEY_SIMPLEX, scale, (235, 235, 235), 1, cv2.LINE_AA,
    )

    hint = guidance_for(status, settings)[0]
    cv2.putText(
        image, hint[:90], (8, height - int(bar_h * 0.32)),
        cv2.FONT_HERSHEY_SIMPLEX, scale, (245, 245, 245), 1, cv2.LINE_AA,
    )
    # Framing guide: fill this with the printed panel and the crops stay large.
    guide = (int(width * 0.08), int(bar_h * 1.4), int(width * 0.92), height - int(bar_h * 1.4))
    cv2.rectangle(image, guide[:2], guide[2:], (200, 200, 200), 1)
    return image


class LiveScanProcessor:
    """streamlit-webrtc video processor: throttled detection plus focus scoring.

    ``recv`` runs on aiortc's worker thread while Streamlit reruns the script on
    the main thread, so every field the UI reads is guarded by ``_lock`` and
    handed out as an immutable snapshot.
    """

    def __init__(self, settings: LiveSettings | None = None) -> None:
        self.settings = settings or LiveSettings()
        self._lock = Lock()
        self._status = LiveStatus()
        self._detections: list[dict[str, Any]] = []
        self._recent: deque[tuple[float, np.ndarray]] = deque(maxlen=SNAPSHOT_BUFFER)
        self._captures: deque[dict[str, Any]] = deque(maxlen=6)
        self._frame_times: deque[float] = deque(maxlen=30)
        self._last_detect_at = 0.0
        self._last_capture_at = 0.0
        self._captures_taken = 0
        self._detector_error: str | None = None
        try:  # surface a missing checkpoint as a message, not as an empty overlay
            pipeline._get_model()
        except Exception as exc:
            self._detector_error = str(exc)
            logger.warning("live view has no detector: %s", exc)

    def update_settings(self, settings: LiveSettings) -> None:
        with self._lock:
            self.settings = settings

    def status(self) -> LiveStatus:
        with self._lock:
            return replace(self._status)

    def drain_captures(self) -> list[dict[str, Any]]:
        """Hand over auto-captured frames and forget them."""

        with self._lock:
            captured = list(self._captures)
            self._captures.clear()
        return captured

    def snapshot(self, note: str = "manual") -> dict[str, Any] | None:
        """The sharpest of the last ~2 s of frames, JPEG-encoded.

        Picking the sharpest recent frame rather than the newest one costs
        nothing and avoids the shutter-press shake that ruins a hand-held still.
        """

        with self._lock:
            if not self._recent:
                return None
            sharpness, image = max(self._recent, key=lambda item: item[0])
            classes = self._status.classes_found
            detections = self._status.detections
        return {
            "image_bytes": encode_jpeg(image),
            "sharpness": round(sharpness, 1),
            "classes_found": list(classes),
            "detections": detections,
            "note": note,
            "captured_at": time.time(),
        }


    def process(self, image: np.ndarray) -> np.ndarray:
        """Score, detect (on a throttle), decide about capture, then annotate."""

        with self._lock:
            settings = replace(self.settings)
        now = time.monotonic()
        self._frame_times.append(now)
        sharpness = focus_score(image)

        detect_ms = self._status.detect_ms
        detected = self._detections
        ran_detect = False
        if self._detector_error is None and now - self._last_detect_at >= settings.detect_interval_s:
            started = time.perf_counter()
            try:
                detected = pipeline.detect_only(image, conf=settings.conf, imgsz=settings.imgsz)
            except Exception as exc:  # a bad frame must not kill the stream
                self._detector_error = str(exc)
                logger.warning("live detection failed: %s", exc)
                detected = []
            detect_ms = (time.perf_counter() - started) * 1000.0
            self._last_detect_at = now
            self._detections = detected
            ran_detect = True

        classes = tuple(sorted({
            record["canonical_name"] for record in detected if record.get("canonical_name")
        }))
        window = self._frame_times[-1] - self._frame_times[0] if len(self._frame_times) > 1 else 0.0
        status = LiveStatus(
            frames=self._status.frames + 1,
            detect_calls=self._status.detect_calls + (1 if ran_detect else 0),
            fps=(len(self._frame_times) - 1) / window if window > 0 else 0.0,
            detect_ms=detect_ms,
            sharpness=sharpness,
            detections=len(detected),
            classes_found=classes,
            captures_taken=self._captures_taken,
            detector_error=self._detector_error,
            frame_size=(image.shape[1], image.shape[0]),
        )
        with self._lock:
            self._status = status
            self._recent.append((sharpness, image.copy()))
        self._maybe_auto_capture(status, settings, now)

        if not settings.draw_overlay:
            return image
        annotated = debug_view.draw_on_array(image.copy(), detected, show_ocr=False)
        return draw_hud(annotated, status, settings)

    def _maybe_auto_capture(self, status: LiveStatus, settings: LiveSettings, now: float) -> None:
        """Fire the shutter when the frame is genuinely worth an OCR pass."""

        if not settings.auto_capture:
            return
        if now - self._last_capture_at < settings.cooldown_s:
            return
        if status.sharpness < settings.sharpness_floor:
            return
        if len(status.classes_found) < settings.min_classes:
            return
        capture = self.snapshot(note="auto")
        if capture is None:
            return
        self._last_capture_at = now
        with self._lock:
            self._captures_taken += 1
            self._captures.append(capture)
            # Publish the new count immediately. Waiting for the next frame to
            # rebuild the status would leave the meter showing a stale total, and
            # ``drain_captures`` may well be called before that frame arrives.
            self._status = replace(self._status, captures_taken=self._captures_taken)

    def recv(self, frame: Any) -> Any:
        """aiortc entry point. Returns the annotated frame to the browser."""

        import av  # imported here so the module stays importable without WebRTC

        image = frame.to_ndarray(format="bgr24")
        annotated = self.process(image)
        new_frame = av.VideoFrame.from_ndarray(annotated, format="bgr24")
        new_frame.pts = frame.pts
        new_frame.time_base = frame.time_base
        return new_frame


def webrtc_available() -> tuple[bool, str | None]:
    """Whether the browser-side stack is installed, and why not if it isn't."""

    try:
        import av  # noqa: F401
        import streamlit_webrtc  # noqa: F401
    except Exception as exc:
        return False, f"{type(exc).__name__}: {exc}"
    return True, None


#: A public STUN server is only needed when the browser and the server are not on
#: the same host. It reveals the client's public IP to that server, so it is a
#: toggle rather than a default for a government-facing deployment.
STUN_SERVERS = ["stun:stun.l.google.com:19302"]

#: Ask for the highest resolution the camera will give: on this dataset the
#: limiting factor for OCR is how many pixels the printed declaration occupies.
MEDIA_CONSTRAINTS = {
    "video": {
        "width": {"ideal": 1920, "min": 640},
        "height": {"ideal": 1080, "min": 480},
        "frameRate": {"ideal": 20, "max": 30},
    },
    "audio": False,
}


def _settings_controls(st: Any, key: str) -> LiveSettings:
    """Sidebar-style controls; returns the settings the processor should use."""

    defaults = LiveSettings()
    with st.expander("Live capture settings", expanded=False):
        left, right = st.columns(2)
        with left:
            conf = st.slider(
                "Detector confidence", 0.05, 0.80, float(defaults.conf), 0.05,
                key=f"{key}_conf",
                help="Lower finds more small panels and more false boxes.",
            )
            interval = st.slider(
                "Seconds between detections", 0.1, 1.5, float(defaults.detect_interval_s), 0.05,
                key=f"{key}_interval",
                help="Detection is throttled so the video stays smooth.",
            )
            imgsz = st.select_slider(
                "Live inference size", [416, 512, 640, 768], value=defaults.imgsz,
                key=f"{key}_imgsz",
                help="Bigger finds smaller print but drops the frame rate.",
            )
        with right:
            floor = st.slider(
                "Sharpness floor", 50.0, 1500.0, float(defaults.sharpness_floor), 25.0,
                key=f"{key}_floor",
                help="Laplacian variance below this counts as blurred. Real packs in "
                     "this dataset score 350-3900 when in focus.",
            )
            min_classes = st.slider(
                "Panels required to auto-capture", 1, 7, int(defaults.min_classes), 1,
                key=f"{key}_minclasses",
            )
            auto = st.checkbox(
                "Auto-capture when the frame is good", value=False, key=f"{key}_auto",
                help="Fires at most once every few seconds while focus and coverage hold.",
            )
            overlay = st.checkbox("Draw overlay on the video", value=True, key=f"{key}_overlay")
    return LiveSettings(
        conf=conf, imgsz=int(imgsz), detect_interval_s=float(interval),
        sharpness_floor=float(floor), min_classes=int(min_classes),
        auto_capture=bool(auto), cooldown_s=defaults.cooldown_s, draw_overlay=bool(overlay),
    )


def _capture_store(st: Any, key: str) -> list[dict[str, Any]]:
    return st.session_state.setdefault(f"{key}_captures", [])


def _render_meters(st: Any, status: LiveStatus, settings: LiveSettings) -> None:
    first, second, third, fourth = st.columns(4)
    first.metric("Focus", f"{status.sharpness:.0f}", f"floor {settings.sharpness_floor:.0f}")
    first.progress(min(1.0, status.sharpness / max(1.0, settings.sharpness_floor)))
    second.metric("Panels visible", f"{len(status.classes_found)}/{len(pipeline.RULE_CLASSES)}")
    second.progress(status.coverage)
    third.metric("Stream", f"{status.fps:.1f} fps")
    fourth.metric("Detector", f"{status.detect_ms:.0f} ms")
    if status.frame_size[0]:
        st.caption(
            f"{status.frame_size[0]}x{status.frame_size[1]} from the camera | "
            f"{status.frames} frames, {status.detect_calls} detector calls, "
            f"{status.captures_taken} auto-capture(s)"
        )
    for index, hint in enumerate(guidance_for(status, settings)):
        if hint.startswith("Good frame"):
            st.success(hint)
        elif index == 0:
            st.warning(hint)
        else:
            st.info(hint)
    if status.classes_found:
        st.caption("Detected now: " + ", ".join(status.classes_found))


def _render_gallery(st: Any, key: str, on_capture: Callable[..., None]) -> None:
    captures = _capture_store(st, key)
    if not captures:
        st.info("No frames captured yet. Aim at the statutory panel and press Capture frame.")
        return
    st.write(f"**{len(captures)} captured frame(s)**")
    columns = st.columns(min(4, len(captures)))
    for index, capture in enumerate(captures):
        with columns[index % len(columns)]:
            st.image(capture["image_bytes"], use_container_width=True)
            st.caption(
                f"#{index + 1} {capture['note']} | focus {capture['sharpness']:.0f} | "
                f"{len(capture['classes_found'])} panel(s)"
            )
    analyse, clear = st.columns([3, 1])
    with analyse:
        if st.button(
            f"Analyse {len(captures)} captured frame(s)",
            type="primary", use_container_width=True, key=f"{key}_analyse",
        ):
            names = [f"live_capture_{index + 1}.jpg" for index in range(len(captures))]
            on_capture([capture["image_bytes"] for capture in captures], names, list(captures))
    with clear:
        if st.button("Clear frames", use_container_width=True, key=f"{key}_clear"):
            st.session_state[f"{key}_captures"] = []
            st.rerun()


INSTALL_HINT = (
    "Live capture needs the WebRTC stack:\n\n"
    "    pip install streamlit-webrtc av aiortc"
)


def render_live_capture(
    on_capture: Callable[[list[bytes], list[str], list[dict[str, Any]]], None],
    key: str = "lmpc_live",
) -> None:
    """Render the live capture panel; ``on_capture`` receives the chosen stills.

    ``on_capture(image_bytes_list, names, capture_metadata)`` is deliberately the
    same shape the uploader produces, so the caller runs one analysis path.
    """

    import streamlit as st

    available, error = webrtc_available()
    if not available:
        st.error(f"{INSTALL_HINT}\n\nImport failed with: {error}")
        return
    from streamlit_webrtc import WebRtcMode, webrtc_streamer

    st.caption(
        "Boxes and the focus/coverage bar are drawn into the video, so they update "
        "at frame rate. OCR and the rule engine run only on a captured still."
    )
    settings = _settings_controls(st, key)
    use_stun = st.checkbox(
        "Use a public STUN server (needed when the browser is not on this machine)",
        value=False, key=f"{key}_stun",
        help="Contacting a public STUN server discloses the client's IP address to it.",
    )
    context = webrtc_streamer(
        key=key,
        mode=WebRtcMode.SENDRECV,
        rtc_configuration={"iceServers": [{"urls": STUN_SERVERS}]} if use_stun else None,
        media_stream_constraints=MEDIA_CONSTRAINTS,
        video_processor_factory=lambda: LiveScanProcessor(settings),
        async_processing=True,
    )

    processor = context.video_processor
    if processor is not None:
        processor.update_settings(settings)
        automatic = processor.drain_captures()
        if automatic:
            _capture_store(st, key).extend(automatic)
            st.rerun()
    status = processor.status() if processor is not None else LiveStatus()

    shutter, refresh = st.columns([3, 1])
    with shutter:
        if st.button(
            "Capture frame", type="primary", use_container_width=True,
            key=f"{key}_shutter", disabled=processor is None,
        ):
            capture = processor.snapshot() if processor is not None else None
            if capture is None:
                st.warning("No frame has arrived from the camera yet.")
            else:
                _capture_store(st, key).append(capture)
                st.rerun()
    with refresh:
        st.button(
            "Refresh meters", use_container_width=True, key=f"{key}_refresh",
            help="The numbers below are a snapshot; the in-video bar is live.",
        )

    if processor is None:
        st.info("Press START above and allow camera access to begin.")
    else:
        _render_meters(st, status, settings)
    st.divider()
    _render_gallery(st, key, on_capture)
