"""Offline live-capture UI tests.

Streamlit itself is not exercised here - what matters is the piece that has real
logic: the frame pump that stands in for a video callback Streamlit does not
provide. The rest of the module is deliberately thin because it reuses
``webrtc_live``'s processor and render helpers, and that reuse is asserted rather
than reimplemented.
"""

from __future__ import annotations

import time

import numpy as np
import pytest

import frame_sources as fs
import local_camera
import webrtc_live


class ScriptedSource(fs.FrameSource):
    """Yields a fixed number of frames, then ``None`` (nothing available yet).

    Every frame carries its 1-based sequence number in pixel ``[0, 0, 0]`` so a test
    can tell *which* frame the pump chose to grade, not merely how many it read.
    ``read_delay_s`` models a source that blocks until the camera produces: a real
    read that waits is the signal that no backlog is left to skip.
    """

    def __init__(
        self,
        frames: int = 3,
        error_after: int | None = None,
        read_delay_s: float = 0.0,
    ):
        self.label = "scripted source"
        self._remaining = frames
        self._error_after = error_after
        self._delay_s = read_delay_s
        self._served = 0
        self.reads = 0
        self.closed = False

    def open(self) -> None:
        pass

    def read(self):
        self.reads += 1
        if self._error_after is not None and self._served >= self._error_after:
            raise fs.SourceError("cable pulled")
        if self._remaining <= 0:
            return None
        if self._delay_s:
            time.sleep(self._delay_s)
        self._remaining -= 1
        self._served += 1
        frame = np.full((120, 160, 3), 100, dtype=np.uint8)
        frame[0, 0, 0] = self._served
        return frame

    def close(self) -> None:
        self.closed = True


class CountingProcessor:
    """Stands in for LiveScanProcessor; records how often it was asked to process."""

    def __init__(self):
        self.calls = 0
        self.seen: list[int] = []
        self.settings = None

    def update_settings(self, settings):
        self.settings = settings

    def process(self, frame):
        self.calls += 1
        self.seen.append(int(frame[0, 0, 0]))
        return frame


def test_pump_skips_the_backlog_and_grades_only_the_newest():
    """A queuing capture hands back the oldest frame it holds, so the pump has to
    read past the backlog - but grading each frame on the way is what makes the
    preview fall behind, and a stale frame is worth nothing when aiming a camera."""

    source = ScriptedSource(frames=3)
    processor = CountingProcessor()
    annotated, painted = local_camera._pump(source, processor)
    assert source.reads == 4        # the backlog, plus the None that ends it
    assert processor.calls == 1     # only one frame cost detector time
    assert processor.seen == [3]    # and it was the newest one
    assert painted == 1
    assert annotated is not None


def test_pump_stops_draining_once_a_read_waits_for_the_camera():
    """A read slower than LIVE_READ_S waited on the camera rather than returning
    something buffered, so nothing is queued behind it. Without this the pump would
    spend the whole run blocking on frames it then throws away, and a live 30 fps
    camera would paint a few times a second instead of twenty."""

    source = ScriptedSource(frames=50, read_delay_s=local_camera.LIVE_READ_S * 4)
    processor = CountingProcessor()
    _, painted = local_camera._pump(source, processor)
    assert source.reads == 1
    assert processor.calls == 1
    assert painted == 1


def test_pump_returns_none_when_the_camera_has_nothing_yet():
    """Normal for a slow source; must not be treated as a failure."""

    annotated, painted = local_camera._pump(ScriptedSource(frames=0), CountingProcessor())
    assert annotated is None and painted == 0


def test_pump_honours_the_frame_cap():
    """A fast camera must not monopolise the fragment run: the backlog skip is
    bounded, and the newest frame reached within that bound is the one graded."""

    source = ScriptedSource(frames=50)
    processor = CountingProcessor()
    _, painted = local_camera._pump(source, processor, max_frames=4)
    assert source.reads == 4
    assert processor.seen == [4]
    assert painted == 1


def test_pump_stops_at_the_time_budget():
    source = ScriptedSource(frames=1000)
    _, painted = local_camera._pump(source, CountingProcessor(), budget_s=0.0)
    assert painted == 0


def test_pump_propagates_a_dead_stream():
    """The UI turns this into a message plus a disconnect, rather than repainting
    a frozen frame forever."""

    source = ScriptedSource(frames=5, error_after=2)
    with pytest.raises(fs.SourceError, match="cable pulled"):
        local_camera._pump(source, CountingProcessor())


def test_pump_feeds_the_real_processor(monkeypatch):
    """End to end against LiveScanProcessor, with the detector stubbed: the offline
    path must produce the same status object the WebRTC path does."""

    monkeypatch.setattr(webrtc_live.pipeline, "_get_model", lambda: object())
    monkeypatch.setattr(webrtc_live.pipeline, "detect_only", lambda *a, **k: [])
    processor = webrtc_live.LiveScanProcessor(webrtc_live.LiveSettings(draw_overlay=False))
    annotated, painted = local_camera._pump(ScriptedSource(frames=2), processor)
    assert painted == 1
    assert annotated is not None
    status = processor.status()
    # One graded frame per run, so the processor's own frame count is the number of
    # frames the inspector actually saw graded - not the number the camera produced.
    assert status.frames == 1
    assert status.frame_size == (160, 120)


def test_source_summary_describes_the_connection():
    assert local_camera.source_summary(None) == "not connected"
    assert local_camera.source_summary(ScriptedSource()) == "scripted source"


def test_preview_interval_is_short_enough_to_look_live():
    assert local_camera.PREVIEW_INTERVAL.endswith("s")
    assert float(local_camera.PREVIEW_INTERVAL.rstrip("s")) <= 0.1


class FakeStreamlit:
    """Enough of Streamlit for the connect/disconnect bookkeeping."""

    def __init__(self):
        self.session_state: dict = {}
        self.reruns = 0

    def rerun(self):
        self.reruns += 1


def test_connect_records_the_tunnel_only_after_the_source_opens(monkeypatch):
    """Regression: _connect starts by disconnecting, and that teardown removes any
    adb forward it finds in session state. A tunnel recorded *before* _connect was
    therefore destroyed before open() could use it - the phone was detected but no
    video ever arrived."""

    st = FakeStreamlit()
    removed: list[int] = []
    monkeypatch.setattr(
        local_camera.frame_sources, "adb_remove_forward",
        lambda port, serial=None: removed.append(int(port)),
    )
    monkeypatch.setattr(local_camera.webrtc_live, "LiveScanProcessor", lambda settings: object())

    seen: dict = {}

    class Recording(ScriptedSource):
        def open(self):
            seen["port_during_open"] = st.session_state.get(
                local_camera._slot("k", "forwarded_port")
            )
            seen["removed_during_open"] = list(removed)

    assert local_camera._connect(st, "k", Recording(), None, forwarded_port=8080) is True
    # The tunnel must still be intact while the source is opening.
    assert seen["removed_during_open"] == []
    assert st.session_state[local_camera._slot("k", "forwarded_port")] == 8080


def test_connect_does_not_record_a_tunnel_when_opening_fails(monkeypatch):
    """A failed connect must not leave a port behind for _disconnect to remove."""

    st = FakeStreamlit()
    monkeypatch.setattr(
        local_camera.frame_sources, "adb_remove_forward", lambda port, serial=None: None
    )

    class Failing(ScriptedSource):
        def open(self):
            raise fs.SourceError("app is not serving")

    assert local_camera._connect(st, "k", Failing(), None, forwarded_port=8080) is False
    assert st.session_state.get(local_camera._slot("k", "forwarded_port")) is None
    assert "not serving" in st.session_state[local_camera._slot("k", "error")]


def test_connect_releases_a_previous_tunnel(monkeypatch):
    """Switching sources must still tear down the *old* forward."""

    st = FakeStreamlit()
    st.session_state[local_camera._slot("k", "forwarded_port")] = 9999
    st.session_state[local_camera._slot("k", "source")] = ScriptedSource()
    removed: list[int] = []
    monkeypatch.setattr(
        local_camera.frame_sources, "adb_remove_forward",
        lambda port, serial=None: removed.append(int(port)),
    )
    monkeypatch.setattr(local_camera.webrtc_live, "LiveScanProcessor", lambda settings: object())

    local_camera._connect(st, "k", ScriptedSource(), None)
    assert removed == [9999]


def test_mjpeg_connect_builds_the_tunnel_after_releasing_the_old_camera(monkeypatch):
    """Regression, one level up from _connect: this route reuses a single fixed local
    port, so a teardown that ran *after* adb_forward would remove the tunnel the
    click just built. Ordering is asserted on the real call sequence."""

    st = FakeStreamlit()
    calls: list[str] = []

    stale = ScriptedSource()
    st.session_state[local_camera._slot("k", "source")] = stale
    st.session_state[local_camera._slot("k", "forwarded_port")] = local_camera.DEFAULT_LOCAL_PORT

    monkeypatch.setattr(
        local_camera.frame_sources, "adb_forward",
        lambda local, remote, serial=None: calls.append(f"forward:{local}") or "url",
    )
    monkeypatch.setattr(
        local_camera.frame_sources, "adb_remove_forward",
        lambda port, serial=None: calls.append(f"remove:{port}"),
    )
    monkeypatch.setattr(local_camera.webrtc_live, "LiveScanProcessor", lambda settings: object())

    class Widgets:
        """Streamlit reduced to the handful of calls _mjpeg_controls makes."""

        session_state = st.session_state

        def __init__(self):
            self.reruns = 0

        def caption(self, *a, **k):
            pass

        def columns(self, spec):
            return [self, self]

        def __enter__(self):
            return self

        def __exit__(self, *exc):
            return False

        def selectbox(self, label, options, **k):
            return list(options)[0]

        def number_input(self, label, low, high, value, **k):
            return value

        def button(self, *a, **k):
            return True

        def rerun(self):
            self.reruns += 1

    widgets = Widgets()
    monkeypatch.setattr(local_camera, "_phone_picker", lambda st, key: "SERIAL")
    monkeypatch.setattr(
        local_camera.frame_sources, "UrlSource", lambda url: ScriptedSource(),
    )

    local_camera._mjpeg_controls(widgets, "k", None)

    # The stale forward is removed first; the new tunnel is built after that and is
    # never removed by the connect that follows.
    assert calls == [f"remove:{local_camera.DEFAULT_LOCAL_PORT}",
                     f"forward:{local_camera.DEFAULT_LOCAL_PORT}"]
    assert stale.closed
    assert st.session_state[local_camera._slot("k", "forwarded_port")] == local_camera.DEFAULT_LOCAL_PORT


def test_a_camera_that_scanned_black_is_still_offered(monkeypatch):
    """The whole point of the blank/would-not-open split. A Camo camera measured here
    was black on roughly one open in three, so a scan that drops black cameras from the
    picker makes a working phone camera unselectable - the "app is not detecting Camo"
    report. Cameras that delivered still come first, so the default pick is a good one.
    """

    picked: dict = {}

    class Widgets:
        session_state = {
            local_camera._slot("k", "devices"): [
                {"index": 0, "name": "Camo", "ok": False, "blank": True,
                 "error": "black", "label": "Camo - no picture yet (index 0)", "api": ""},
                {"index": 1, "name": "OBS", "ok": False, "blank": False,
                 "error": "would not open", "label": "OBS - not delivering", "api": ""},
                {"index": 2, "name": "Integrated Camera", "ok": True, "blank": False,
                 "error": "", "label": "Integrated Camera - 640x480 (index 2)", "api": "dshow"},
            ]
        }

        def caption(self, *a, **k):
            pass

        def warning(self, *a, **k):
            pass

        def info(self, *a, **k):
            raise AssertionError("the picker was skipped")

        def columns(self, spec):
            return [self, self]

        def __enter__(self):
            return self

        def __exit__(self, *exc):
            return False

        def button(self, *a, **k):
            return False

        def selectbox(self, label, options, **k):
            picked["options"] = list(options)
            return picked["options"][0]

    local_camera._device_controls(Widgets(), "k", None)
    assert picked["options"] == [
        "Integrated Camera - 640x480 (index 2)",  # delivered, so it leads
        "Camo - no picture yet (index 0)",        # black, but recoverable on connect
    ]
    # A camera that never opened is not offered: there is nothing to connect to.
    assert "OBS - not delivering" not in picked["options"]


def test_the_picker_defaults_to_the_phone_when_nothing_delivered(monkeypatch):
    """The other half of the blank/would-not-open split, and the failure seen in the
    app: with a shuttered webcam at index 0 and Camo at index 1, *both* black, offering
    the Camo is not enough - index order still defaults the picker to the dead webcam,
    so pressing Connect on the default reproduces "the app did not detect my phone".
    """

    picked: dict = {}

    class Widgets:
        session_state = {
            local_camera._slot("k", "devices"): [
                {"index": 0, "name": "Integrated Camera", "ok": False, "blank": True,
                 "error": "black", "label": "Integrated Camera - no picture yet (index 0)",
                 "api": ""},
                {"index": 1, "name": "Camo", "ok": False, "blank": True,
                 "error": "black", "label": "Camo - no picture yet (index 1)", "api": ""},
            ]
        }

        def caption(self, *a, **k):
            pass

        def warning(self, *a, **k):
            pass

        def columns(self, spec):
            return [self, self]

        def __enter__(self):
            return self

        def __exit__(self, *exc):
            return False

        def button(self, *a, **k):
            return False

        def selectbox(self, label, options, **k):
            picked["options"] = list(options)
            return picked["options"][0]

    local_camera._device_controls(Widgets(), "k", None)
    assert picked["options"][0] == "Camo - no picture yet (index 1)"


def test_a_delivering_webcam_still_outranks_a_black_phone_camera():
    """The phone preference is a tiebreaker, not an override: a camera that handed over
    a picture is a better default than one that needs a reopen to work at all."""

    devices = [
        {"index": 0, "name": "Integrated Camera", "ok": True, "blank": False,
         "error": "", "label": "webcam", "api": "dshow"},
        {"index": 1, "name": "Camo", "ok": False, "blank": True,
         "error": "black", "label": "camo", "api": ""},
    ]
    usable = sorted((r for r in devices if r["ok"]), key=local_camera._phone_first)
    retryable = sorted((r for r in devices if not r["ok"]), key=local_camera._phone_first)
    assert [r["label"] for r in usable + retryable] == ["webcam", "camo"]


def test_phone_first_leaves_unrecognised_cameras_in_scan_order():
    """Two USB cameras neither of which is a phone app must not be reordered - the
    index the inspector saw in the scan is the only ordering they have."""

    records = [{"name": "Logitech C920"}, {"name": "USB2.0 HD UVC WebCam"}]
    assert sorted(records, key=local_camera._phone_first) == records


def test_every_source_choice_has_a_control_and_a_caption():
    """The radio is built from SOURCE_ORDER, so a source added without a caption or
    a handler would render a blank option rather than fail loudly."""

    assert set(local_camera.SOURCE_ORDER) == set(local_camera.SOURCE_HELP)
    assert len(local_camera.SOURCE_ORDER) == 4
    # USB first: it is the route that does not depend on a network staying up.
    assert local_camera.SOURCE_ORDER[0] == local_camera.SOURCE_MJPEG


def test_module_reuses_the_webrtc_processor_and_renderers():
    """Offline and WebRTC captures must be graded identically, which only holds if
    this module drives the same processor rather than a parallel implementation."""

    source = local_camera.__dict__
    assert source["webrtc_live"] is webrtc_live
    for helper in ("_settings_controls", "_render_meters", "_render_gallery", "_capture_store"):
        assert hasattr(webrtc_live, helper)
