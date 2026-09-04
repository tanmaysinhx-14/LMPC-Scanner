"""Offline camera-source tests.

The parts worth testing here are the ones that fail silently on demo day: the adb
device parsing, where ``adb`` is looked up, the exact screenrecord flags, and the
frame-drain loop. No phone and no camera is plugged in during a test run, so
OpenCV and subprocess are stubbed - what is being checked is the plumbing, not
whether a webcam works.
"""

from __future__ import annotations

import subprocess
import time

import numpy as np
import pytest

import frame_sources as fs


# --------------------------------------------------------------------------
# adb devices parsing
# --------------------------------------------------------------------------

SAMPLE_DEVICES = """List of devices attached
* daemon not running; starting now at tcp:5037 *
R58M123ABC     device usb:1-3 product:beyond1lte model:SM_G973F transport_id:1
9A2XPHONE      unauthorized usb:1-7
emulator-5554  offline
"""


def test_parse_adb_devices_reads_serial_state_and_model():
    records = fs.parse_adb_devices(SAMPLE_DEVICES)
    assert [record["serial"] for record in records] == [
        "R58M123ABC", "9A2XPHONE", "emulator-5554",
    ]
    first = records[0]
    assert first["state"] == "device"
    # Underscores become spaces so the UI can show "SM G973F" rather than SM_G973F.
    assert first["model"] == "SM G973F"
    assert first["product"] == "beyond1lte"


def test_parse_adb_devices_keeps_unauthorized_state():
    """An unaccepted USB-debugging prompt must stay visible, not be filtered out."""

    states = {record["serial"]: record["state"] for record in fs.parse_adb_devices(SAMPLE_DEVICES)}
    assert states["9A2XPHONE"] == "unauthorized"
    assert states["emulator-5554"] == "offline"


def test_parse_adb_devices_ignores_daemon_noise_and_blank_input():
    assert fs.parse_adb_devices("") == []
    assert fs.parse_adb_devices("List of devices attached\n\n") == []
    assert fs.parse_adb_devices("* daemon started successfully *\n") == []


# --------------------------------------------------------------------------
# adb discovery
# --------------------------------------------------------------------------

def test_find_adb_prefers_the_environment_override(monkeypatch, tmp_path):
    fake = tmp_path / "adb.exe"
    fake.write_bytes(b"")
    monkeypatch.setenv("LMPC_ADB", str(fake))
    monkeypatch.setattr(fs.shutil, "which", lambda name: "C:/somewhere/else/adb.exe")
    assert fs.find_adb() == str(fake)


def test_find_adb_rejects_an_override_that_does_not_exist(monkeypatch, tmp_path):
    """A typo in LMPC_ADB should be a clear "not found", not a silent fallback."""

    monkeypatch.setenv("LMPC_ADB", str(tmp_path / "nope.exe"))
    assert fs.find_adb() is None


def test_find_adb_falls_back_to_path(monkeypatch):
    monkeypatch.delenv("LMPC_ADB", raising=False)
    monkeypatch.setattr(fs.shutil, "which", lambda name: "/usr/bin/adb" if name == "adb" else None)
    assert fs.find_adb() == "/usr/bin/adb"


def test_find_adb_returns_none_when_nothing_is_installed(monkeypatch):
    monkeypatch.delenv("LMPC_ADB", raising=False)
    monkeypatch.setattr(fs.shutil, "which", lambda name: None)
    monkeypatch.setattr(fs, "_ADB_CANDIDATES", ())
    assert fs.find_adb() is None


def test_run_adb_raises_a_readable_error_when_adb_is_missing(monkeypatch):
    monkeypatch.setattr(fs, "find_adb", lambda: None)
    with pytest.raises(fs.SourceError, match="adb was not found"):
        fs._run_adb(["devices"])


def test_run_adb_surfaces_a_nonzero_exit(monkeypatch):
    monkeypatch.setattr(fs, "find_adb", lambda: "adb")
    completed = subprocess.CompletedProcess(["adb"], 1, "", "device offline")
    monkeypatch.setattr(fs.subprocess, "run", lambda *a, **k: completed)
    with pytest.raises(fs.SourceError, match="device offline"):
        fs._run_adb(["forward", "tcp:8080", "tcp:8080"])


def test_run_adb_never_uses_a_shell(monkeypatch):
    """The serial and port reach argv from device output and the UI, so a shell
    string here would be an injection surface."""

    monkeypatch.setattr(fs, "find_adb", lambda: "adb")
    seen: dict = {}

    def fake_run(command, **kwargs):
        seen["command"] = command
        seen["kwargs"] = kwargs
        return subprocess.CompletedProcess(command, 0, "ok", "")

    monkeypatch.setattr(fs.subprocess, "run", fake_run)
    fs._run_adb(["forward", "tcp:8080", "tcp:4747"], serial="R58M")
    assert isinstance(seen["command"], list)
    assert seen["command"][:4] == ["adb", "-s", "R58M", "forward"]
    assert seen["kwargs"].get("shell") in (None, False)


# --------------------------------------------------------------------------
# forwarding
# --------------------------------------------------------------------------

def test_adb_forward_builds_the_loopback_url(monkeypatch):
    calls: list[list[str]] = []
    monkeypatch.setattr(fs, "_run_adb", lambda args, serial=None, **k: calls.append(args) or "")
    url = fs.adb_forward(8080, 4747, serial="R58M")
    assert calls == [["forward", "tcp:8080", "tcp:4747"]]
    assert url == "http://127.0.0.1:8080/video"


def test_adb_remove_forward_swallows_failures(monkeypatch):
    """Cleanup runs on the way out of a demo; it must never raise."""

    def boom(*args, **kwargs):
        raise fs.SourceError("no such forward")

    monkeypatch.setattr(fs, "_run_adb", boom)
    fs.adb_remove_forward(8080)  # must not raise


def test_mjpeg_url_normalises_a_missing_slash():
    assert fs.mjpeg_url(4747, "video") == "http://127.0.0.1:4747/video"
    assert fs.mjpeg_url(8080, "/video") == "http://127.0.0.1:8080/video"


def test_normalise_stream_url_adds_the_stream_path():
    """IP Webcam shows a bare address; OpenCV needs the /video endpoint."""

    assert fs.normalise_stream_url("http://192.168.1.2:8080") == "http://192.168.1.2:8080/video"


def test_normalise_stream_url_downgrades_https():
    """IP Webcam advertises https with a self-signed cert that FFmpeg refuses, so
    the same address must be tried over http rather than failing opaquely."""

    assert fs.normalise_stream_url("https://192.168.1.2:8080") == "http://192.168.1.2:8080/video"


def test_normalise_stream_url_adds_a_missing_scheme_and_port():
    assert fs.normalise_stream_url("192.168.1.2") == "http://192.168.1.2:8080/video"
    assert fs.normalise_stream_url("192.168.1.2:4747", port=4747) == "http://192.168.1.2:4747/video"


def test_normalise_stream_url_keeps_an_explicit_path():
    assert fs.normalise_stream_url("http://192.168.1.2:8080/videofeed") == (
        "http://192.168.1.2:8080/videofeed"
    )


def test_normalise_stream_url_tolerates_padding_and_quotes():
    assert fs.normalise_stream_url('  "http://192.168.1.2:8080"  ') == (
        "http://192.168.1.2:8080/video"
    )


def test_normalise_stream_url_passes_rtsp_through():
    """RTSP carries its own port convention and path; rewriting it would break it."""

    url = "rtsp://192.168.1.2:5554/camera"
    assert fs.normalise_stream_url(url) == url


def test_normalise_stream_url_rejects_blank_input():
    with pytest.raises(fs.SourceError, match="no address given"):
        fs.normalise_stream_url("   ")


def test_mjpeg_presets_cover_the_documented_apps():
    assert set(fs.MJPEG_PRESETS) == {"IP Webcam", "DroidCam"}
    for port, path in fs.MJPEG_PRESETS.values():
        assert 1 <= port <= 65535 and path.startswith("/")


# --------------------------------------------------------------------------
# screenrecord flags
# --------------------------------------------------------------------------

def test_screenrecord_command_streams_raw_h264():
    """mp4 only finalises its container on stop, so it cannot be read live - the
    h264 output format is what makes this source possible at all."""

    command = fs.screenrecord_command("adb", serial="R58M", size="1280x720")
    assert command[:3] == ["adb", "-s", "R58M"]
    assert "--output-format=h264" in command
    assert "--size=1280x720" in command
    assert command[-1] == "-"  # stdout


def test_screenrecord_command_restarts_before_androids_own_limit():
    """Android kills screenrecord at 180 s; the default must be under that."""

    assert fs.SCREENRECORD_LIMIT_S < 180
    command = fs.screenrecord_command("adb")
    assert f"--time-limit={fs.SCREENRECORD_LIMIT_S}" in command
    assert "-s" not in command  # no serial given, so no -s flag


def test_screenrecord_command_omits_size_when_blank():
    assert not any(part.startswith("--size") for part in fs.screenrecord_command("adb", size=""))


# --------------------------------------------------------------------------
# DeviceSource / UrlSource, with OpenCV stubbed
# --------------------------------------------------------------------------

class FakeCapture:
    """Minimal cv2.VideoCapture stand-in.

    ``blank=True`` reproduces the failure mode that matters most here: a capture
    graph that reports success forever while handing back an all-black buffer.
    """

    def __init__(self, frames=1, opened=True, width=1920, height=1080, blank=False):
        self._remaining = frames
        self._opened = opened
        self.released = False
        self.props: dict = {}
        self.blank = blank
        fill = 0 if blank else 128
        self._frame = np.full((height, width, 3), fill, dtype=np.uint8)
        if blank:
            self._remaining = 1 << 30  # a stalled graph never stops "succeeding"

    def isOpened(self):
        return self._opened

    def set(self, prop, value):
        self.props[prop] = value
        return True

    def read(self):
        if self._remaining <= 0:
            return False, None
        self._remaining -= 1
        return True, self._frame.copy()

    def release(self):
        self.released = True


def test_device_source_opens_and_labels_the_backend(monkeypatch):
    capture = FakeCapture(frames=5)
    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: capture)
    source = fs.DeviceSource(index=2)
    source.open()
    try:
        assert "device 2" in source.describe()
        assert "1920x1080" in source.describe()
        assert source.read().shape == (1080, 1920, 3)
    finally:
        source.close()
    assert capture.released


def test_device_source_requests_mjpg_before_resolution(monkeypatch):
    """USB cameras commonly clamp to 640x480 in raw YUY2, so FOURCC must be set
    before the size request or 1080p is silently lost."""

    capture = FakeCapture(frames=2)
    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: capture)
    source = fs.DeviceSource(index=0)
    source.open()
    source.close()
    keys = list(capture.props)
    assert keys.index(fs.cv2.CAP_PROP_FOURCC) < keys.index(fs.cv2.CAP_PROP_FRAME_WIDTH)
    assert capture.props[fs.cv2.CAP_PROP_FRAME_WIDTH] == fs.DEFAULT_WIDTH


def test_device_source_reports_every_backend_it_tried(monkeypatch):
    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: FakeCapture(opened=False))
    with pytest.raises(fs.SourceError) as error:
        fs.DeviceSource(index=3).open()
    assert "device 3" in str(error.value)
    assert "would not open" in str(error.value)


def test_a_failure_names_the_camera_the_inspector_chose(monkeypatch):
    """The picker offers names, so a failure that says "camera index 1" reads as being
    about a different device than the "Camo" that was just selected."""

    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: FakeCapture(opened=False))
    with pytest.raises(fs.SourceError) as error:
        fs.DeviceSource(index=1, name="Camo").open()
    message = str(error.value)
    assert message.startswith("Camo (index 1)")


def test_a_named_camera_carries_its_name_into_the_capture_record(monkeypatch):
    """describe() is what the capture record stores as the evidence's source, so it
    should say Camo rather than an index nobody can map back to a device."""

    monkeypatch.setattr(
        fs.cv2, "VideoCapture", lambda index, flag=0: FakeCapture(frames=3, width=1280, height=720)
    )
    source = fs.DeviceSource(index=1, name="Camo")
    source.open()
    try:
        # The backend name in the middle differs by platform, so assert the parts that
        # are the point: which camera, and at what resolution.
        assert source.describe().startswith("Camo (index 1) via ")
        assert source.describe().endswith("at 1280x720")
    finally:
        source.close()


def test_device_source_rejects_a_camera_that_opens_but_sends_nothing(monkeypatch):
    """Virtual cameras (Camo, DroidCam, OBS) do exactly this when their phone-side
    app is not streaming, and it is the failure that looks like a hang."""

    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: FakeCapture(frames=0))
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 0.2)
    with pytest.raises(fs.SourceError, match="delivered no frame"):
        fs.DeviceSource(index=0).open()


def test_device_source_read_before_open_is_an_error():
    with pytest.raises(fs.SourceError, match="read\\(\\) before open\\(\\)"):
        fs.DeviceSource(index=0).read()


def test_device_source_close_is_idempotent(monkeypatch):
    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: FakeCapture(frames=3))
    source = fs.DeviceSource(index=0)
    source.open()
    source.close()
    source.close()  # must not raise


def test_capture_apis_keeps_the_others_as_fallbacks():
    """A wrong backend guess should cost a warning, not the demo."""

    apis = fs._capture_apis("msmf")
    assert apis[0][0] == "msmf"
    assert len(apis) == len(fs._WINDOWS_APIS if fs.IS_WINDOWS else fs._POSIX_APIS)


def test_url_source_labels_the_stream_size(monkeypatch):
    """The label is what the capture record stores, so the resolution has to come
    from a real frame rather than from what was requested."""

    monkeypatch.setattr(
        fs.cv2, "VideoCapture", lambda url: FakeCapture(frames=4, width=1280, height=720)
    )
    source = fs.UrlSource(url="http://127.0.0.1:8080/video")
    source.open()
    try:
        assert "1280x720" in source.describe()
    finally:
        source.close()


def test_url_source_explains_the_adb_forward_prerequisite(monkeypatch):
    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda url: FakeCapture(opened=False))
    with pytest.raises(fs.SourceError, match="adb forward"):
        fs.UrlSource(url="http://127.0.0.1:8080/video").open()


def test_url_source_requires_a_url():
    with pytest.raises(fs.SourceError, match="no URL given"):
        fs.UrlSource().open()


# --------------------------------------------------------------------------
# UrlSource's background reader: the fix for 5-7 s of MJPEG lag
# --------------------------------------------------------------------------

class PacedCapture:
    """A capture that produces numbered frames on its own clock.

    Unlike :class:`FakeCapture` this blocks until the next frame is due, which is
    what makes it able to distinguish "kept up with the stream" from "fell behind
    and served stale frames" - the whole point of the reader thread.
    """

    def __init__(self, fps=50.0, width=64, height=48):
        self.interval = 1.0 / fps
        self.width, self.height = width, height
        self.produced = 0
        self.released = False
        self.props: dict = {}
        self._next = time.monotonic()

    def isOpened(self):
        return True

    def set(self, prop, value):
        self.props[prop] = value
        return True

    def read(self):
        if self.released:
            return False, None
        now = time.monotonic()
        if now < self._next:
            time.sleep(self._next - now)
        self._next += self.interval
        self.produced += 1
        # Frame index is stored in pixel 0 so a consumer can tell how old it is.
        frame = np.full((self.height, self.width, 3), 40, dtype=np.uint8)
        frame[0, 0, 0] = self.produced % 251
        return True, frame

    def release(self):
        self.released = True


def _open_paced(monkeypatch, **kwargs):
    capture = PacedCapture(**kwargs)
    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda url: capture)
    source = fs.UrlSource(url="http://127.0.0.1:8080/video")
    source.open()
    return source, capture


def test_url_source_serves_the_newest_frame_not_the_oldest(monkeypatch):
    """The bug this fixes: an MJPEG socket keeps buffering while the processor is
    busy, so reading in step with processing shows older and older frames - measured
    at 5-7 s behind live on a phone at 1080p."""

    source, capture = _open_paced(monkeypatch, fps=100.0)
    try:
        time.sleep(0.25)  # the reader drains ~25 frames while the "UI" is busy
        frame = source.read()
        assert frame is not None
        served = int(frame[0, 0, 0])
        # Whatever the reader threw away, what we get is the latest it saw.
        assert served >= capture.produced - 2
        assert source.dropped > 5
    finally:
        source.close()


def test_url_source_read_does_not_repeat_a_frame(monkeypatch):
    """Polling faster than the camera delivers must report "nothing new" rather than
    reprocessing one frame and inflating the frame-rate meter."""

    source, _ = _open_paced(monkeypatch, fps=20.0)
    try:
        assert source.read() is not None
        assert source.read() is None
    finally:
        source.close()


def test_url_source_keeps_up_when_the_consumer_is_slow(monkeypatch):
    """End to end on the property that matters: with the consumer spending far
    longer per frame than the stream's interval, lag must stay flat rather than
    growing without bound."""

    source, capture = _open_paced(monkeypatch, fps=100.0)
    try:
        lags = []
        for _ in range(12):
            time.sleep(0.05)  # stand in for the processor
            frame = source.read()
            if frame is not None:
                lags.append(capture.produced - int(frame[0, 0, 0]))
        assert lags, "the reader never delivered a frame"
        assert max(lags) <= 3, f"lag grew: {lags}"
    finally:
        source.close()


def test_url_source_reports_a_stream_that_ended(monkeypatch):
    """A dead stream must surface as an error the UI can act on, not as silence -
    otherwise the preview repaints a frozen frame forever."""

    source, capture = _open_paced(monkeypatch, fps=100.0)
    try:
        capture.released = True  # the reader's next read() fails
        deadline = time.monotonic() + 2.0
        while time.monotonic() < deadline:
            try:
                source.read()
            except fs.SourceError as exc:
                assert "ended" in str(exc)
                break
            time.sleep(0.02)
        else:
            pytest.fail("a dead stream was never reported")
    finally:
        source.close()


def test_url_source_close_stops_the_reader_thread(monkeypatch):
    """A leaked reader would keep the phone streaming after Disconnect, and would
    hold the tunnel open."""

    source, capture = _open_paced(monkeypatch, fps=100.0)
    reader = source._reader
    source.close()
    reader.join(timeout=2.0)
    assert not reader.is_alive()
    assert capture.released
    source.close()  # must stay safe to call twice


def test_url_source_shortens_the_buffer_as_well(monkeypatch):
    """The thread is the real fix, but where the backend honours a short queue there
    is less for the thread to throw away."""

    source, capture = _open_paced(monkeypatch, fps=100.0)
    try:
        assert capture.props[fs.cv2.CAP_PROP_BUFFERSIZE] == 1
    finally:
        source.close()


def test_thread_backed_sources_advertise_that_they_serve_the_newest_frame():
    """``latest_only`` is how the UI pump knows whether to skip a backlog. Getting it
    wrong on a thread-backed source is not merely redundant: the pump would spend the
    fragment's whole budget blocking on reads whose frames it discards."""

    assert fs.UrlSource(url="http://127.0.0.1:8080/video").latest_only is True
    assert fs.AdbScreenrecordSource().latest_only is True
    # DeviceSource reads the driver in step with the UI, so it may hand back a queued
    # frame and the caller does have to drain.
    assert fs.DeviceSource(index=0).latest_only is False
    assert fs.FrameSource.latest_only is False


def test_latest_only_is_not_a_constructor_argument():
    """It describes the class, not one connection. A field would let a caller set it
    per instance and quietly disable the reader thread's whole reason for existing."""

    with pytest.raises(TypeError):
        fs.UrlSource(url="http://127.0.0.1:8080/video", latest_only=False)


def test_frame_source_context_manager_closes(monkeypatch):
    capture = FakeCapture(frames=3)
    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: capture)
    with fs.DeviceSource(index=0) as source:
        assert source.read() is not None
    assert capture.released


def test_device_source_rejects_a_camera_that_only_delivers_black(monkeypatch):
    """The failure that cost real debugging time: DirectShow returns ok=True with an
    all-zero buffer, at a flat 1 fps, when a shutter is closed or another app holds
    the camera. Accepting it makes a dead camera look like a working one."""

    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: FakeCapture(blank=True))
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 0.2)
    with pytest.raises(fs.SourceError, match="black frames"):
        fs.DeviceSource(index=0).open()


def test_blank_frame_error_names_what_to_actually_check(monkeypatch):
    """The message has to be a checklist, not a diagnosis: at a demo the inspector
    needs the next action, and any of these three causes produces this symptom."""

    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: FakeCapture(blank=True))
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 0.2)
    with pytest.raises(fs.SourceError) as excinfo:
        fs.DeviceSource(index=0).open()
    message = str(excinfo.value)
    assert "shutter" in message
    assert "another" in message  # another app holding the camera
    assert "streaming" in message  # a virtual camera whose app is idle
    # Once, not once per backend. Three copies of the paragraph is what turns an
    # actionable message into a wall of text nobody reads at a demo.
    assert message.count("shutter") == 1


def test_a_black_camera_is_not_retried_on_every_backend(monkeypatch):
    """A backend that opened and then delivered black has a problem upstream of
    OpenCV, so the next backend pays the same waits to reach the same buffer. With
    three enumerated cameras that turned an 8-second wait into a 48-second one, which
    an inspector reads as the application having hung.

    The reopen retry is deliberately *within* one backend: a fresh capture on the same
    backend does recover a stuck graph, whereas a different backend driving the same
    dead device does not."""

    opened = []

    def capture_for(index, flag=0):
        opened.append(flag)
        return FakeCapture(blank=True)

    monkeypatch.setattr(fs.cv2, "VideoCapture", capture_for)
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 0.2)
    with pytest.raises(fs.SourceError):
        fs.DeviceSource(index=0).open()
    assert len(set(opened)) == 1, f"tried {len(set(opened))} backends against a black camera"
    assert len(opened) == fs.BLANK_RETRIES + 1


def test_a_black_capture_is_reopened_rather_than_waited_out(monkeypatch):
    """The defect behind the user's "Camo is not detected" report, and the measurement
    that fixed it: a Camo virtual camera was black on roughly one open in three, and
    twelve seconds of black inside one session never cleared - but a fresh capture
    delivered a real frame in 0.02s. So a black verdict has to be retried with a new
    capture, or a working phone camera is reported as missing and cannot be picked."""

    sessions = []

    def capture_for(index, flag=0):
        # Black on the first session, real on the second - the measured Camo pattern.
        capture = FakeCapture(frames=3) if sessions else FakeCapture(blank=True)
        sessions.append(capture)
        return capture

    monkeypatch.setattr(fs.cv2, "VideoCapture", capture_for)
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 0.2)
    source = fs.DeviceSource(index=1, name="Camo")
    source.open()
    try:
        assert len(sessions) == 2
        assert sessions[0].released, "the stuck capture must be released, not leaked"
        assert "Camo (index 1)" in source.describe()
    finally:
        source.close()


def test_a_black_camera_gives_up_before_the_full_timeout(monkeypatch):
    """Waiting out OPEN_TIMEOUT_S on a stuck graph buys nothing and delays the reopen
    that does work, so black frames are on their own, much shorter budget."""

    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: FakeCapture(blank=True))
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 30.0)
    monkeypatch.setattr(fs, "BLANK_TIMEOUT_S", 0.05)
    started = time.monotonic()
    with pytest.raises(fs.SourceError, match="black frames"):
        fs.DeviceSource(index=0).open()
    # Three attempts of 0.05s, not one of 30s.
    assert time.monotonic() - started < 5.0


def test_the_retry_count_is_reported_so_a_failure_is_not_mistaken_for_one_try(monkeypatch):
    """"Only black on 3 attempts" tells the inspector the app already did the obvious
    thing, which is what stops them pressing Scan again and again."""

    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: FakeCapture(blank=True))
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 0.2)
    with pytest.raises(fs.SourceError) as excinfo:
        fs.DeviceSource(index=0).open()
    assert f"on {fs.BLANK_RETRIES + 1} attempts" in str(excinfo.value)


def test_a_backend_that_will_not_open_is_not_reopened(monkeypatch):
    """Only the black-buffer symptom recovers on a reopen. A backend that refused
    outright refuses identically a moment later, so retrying it is dead time in a scan
    the inspector is waiting on."""

    opened = []

    def capture_for(index, flag=0):
        opened.append(flag)
        return FakeCapture(opened=False)

    monkeypatch.setattr(fs.cv2, "VideoCapture", capture_for)
    with pytest.raises(fs.SourceError):
        fs.DeviceSource(index=0).open()
    # One attempt per backend, none repeated.
    assert len(opened) == len(set(opened))


def test_a_backend_that_will_not_open_still_falls_through_to_the_next(monkeypatch):
    """The opposite case, and the reason the loop exists: MSMF opens none of the
    virtual cameras this project needs, so a refusal must not end the attempt."""

    def capture_for(index, flag=0):
        # Only the last backend in the table works.
        return FakeCapture(frames=3) if flag == fs.cv2.CAP_ANY else FakeCapture(opened=False)

    monkeypatch.setattr(fs.cv2, "VideoCapture", capture_for)
    source = fs.DeviceSource(index=0)
    source.open()
    try:
        assert source.opened_api == "any"
    finally:
        source.close()


def test_a_dim_frame_is_not_treated_as_blank():
    """A dark room is dark, never uniformly zero - the test must not reject it."""

    dim = np.zeros((16, 16, 3), dtype=np.uint8)
    dim[8, 8] = 1
    assert not fs.DeviceSource._is_blank(dim)
    assert fs.DeviceSource._is_blank(np.zeros((16, 16, 3), dtype=np.uint8))


def test_probe_uses_a_shorter_wait_than_a_deliberate_connect(monkeypatch):
    """A scan pays the wait once per camera, so it must be quicker than connecting to
    one chosen camera - three eight-second waits in a row read as a hung app. Getting
    this backwards is invisible in a unit test and unmissable at a demo.

    Exercised through the no-frame failure because that is the one the timeout still
    governs: black frames are bounded by BLANK_TIMEOUT_S instead, so a camera stuck on
    black would finish quickly whichever timeout were passed and prove nothing."""

    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: FakeCapture(frames=0))
    monkeypatch.setattr(fs, "device_names", lambda: ["Integrated Camera"])
    monkeypatch.setattr(fs, "PROBE_TIMEOUT_S", 0.05)
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 30.0)
    started = time.monotonic()
    found = fs.probe_devices()  # would take 90s if it used the connect timeout
    assert time.monotonic() - started < 5.0
    assert [record["ok"] for record in found] == [False]


def test_probe_timeout_tracks_the_constant_rather_than_the_signature(monkeypatch):
    """The default is read inside the call, not baked into the parameter. A frozen
    default is a real bug this caught: lowering PROBE_TIMEOUT_S did nothing."""

    seen: list[float] = []

    def recording(capture, timeout_s=0.0, blank_timeout_s=-1.0):
        seen.append(timeout_s)
        return np.full((8, 8, 3), 7, dtype=np.uint8), "", False

    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: FakeCapture(frames=2))
    monkeypatch.setattr(fs, "device_names", lambda: ["Integrated Camera"])
    monkeypatch.setattr(fs, "PROBE_TIMEOUT_S", 1.25)
    monkeypatch.setattr(fs.DeviceSource, "_wait_for_first_frame", staticmethod(recording))
    fs.probe_devices()
    assert seen == [1.25]


def test_device_source_timeout_defaults_to_the_module_constant(monkeypatch):
    """Zero means "use OPEN_TIMEOUT_S", resolved at open() time. Baking the constant
    into the field default would silently ignore a lowered module value - and did once,
    in probe_devices. Read through the no-frame message, the one that still carries the
    budget."""

    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: FakeCapture(frames=0))
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 0.2)
    assert fs.DeviceSource(index=0).timeout_s == 0.0
    with pytest.raises(fs.SourceError, match="no frame in 0s"):
        fs.DeviceSource(index=0).open()


def test_probe_devices_skips_unnamed_indices_that_do_not_open(monkeypatch):
    """With no names available there is nothing to report about an index that holds
    no device, so a failure is dropped rather than listed."""

    def capture_for(index, flag=0):
        return FakeCapture(frames=3) if index in {0, 2} else FakeCapture(opened=False)

    monkeypatch.setattr(fs.cv2, "VideoCapture", capture_for)
    monkeypatch.setattr(fs, "device_names", lambda: [])
    found = fs.probe_devices(max_index=3)
    assert [record["index"] for record in found] == [0, 2]
    assert all(record["width"] == 1920 for record in found)
    assert all(record["ok"] for record in found)


def test_probe_devices_leads_with_the_camera_name(monkeypatch):
    """"device 0 / device 1" gives the inspector no way to tell the built-in webcam
    from Camo, which is what made a working Camo look undetected."""

    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: FakeCapture(frames=3))
    monkeypatch.setattr(fs, "device_names", lambda: ["Integrated Camera", "Camo"])
    found = fs.probe_devices()
    assert [record["name"] for record in found] == ["Integrated Camera", "Camo"]
    assert found[1]["label"].startswith("Camo")
    assert "index 1" in found[1]["label"]


def test_probe_devices_scans_exactly_the_cameras_windows_reports(monkeypatch):
    """The scan must not walk a fixed index range past the last real device: every
    empty index costs the full open timeout."""

    opened: list[int] = []

    def capture_for(index, flag=0):
        opened.append(index)
        return FakeCapture(frames=3)

    monkeypatch.setattr(fs.cv2, "VideoCapture", capture_for)
    monkeypatch.setattr(fs, "device_names", lambda: ["Integrated Camera", "Camo"])
    fs.probe_devices(max_index=5)
    assert set(opened) == {0, 1}


def test_a_scan_does_not_renegotiate_the_graph(monkeypatch):
    """Asking DirectShow for 1080p MJPG measured at ~2s per open on this laptop, paid
    once per camera. A scan only needs to know whether a picture arrives, so it opens in
    whatever mode the camera offers; the connect that follows still asks for 1080p,
    because that is where the pixels the OCR needs come from."""

    captures: list[FakeCapture] = []

    def capture_for(index, flag=0):
        capture = FakeCapture(frames=3)
        captures.append(capture)
        return capture

    monkeypatch.setattr(fs.cv2, "VideoCapture", capture_for)
    monkeypatch.setattr(fs, "device_names", lambda: ["Integrated Camera"])
    fs.probe_devices()
    assert captures[0].props == {}, "the scan renegotiated the capture graph"

    captures.clear()
    source = fs.DeviceSource(index=0)
    source.open()
    source.close()
    assert captures[0].props[fs.cv2.CAP_PROP_FRAME_WIDTH] == fs.DEFAULT_WIDTH


def test_the_first_black_frame_is_conclusive_when_the_caller_says_so(monkeypatch):
    """Each black read costs a flat second of DirectShow timeout, so a scan - which no
    longer treats black as final - should not pay for a second one. The default is more
    patient, because an MJPEG stream emits black at frame rate while a phone camera
    warms up and must not be written off after one frame."""

    reads: list[int] = []

    class Counting(FakeCapture):
        def read(self):
            reads.append(1)
            return super().read()

    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: Counting(blank=True))
    with pytest.raises(fs.SourceError, match="black frames"):
        fs.DeviceSource(index=0, blank_timeout_s=0.0, blank_retries=0).open()
    assert len(reads) == 1

    reads.clear()
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 0.5)
    monkeypatch.setattr(fs, "BLANK_TIMEOUT_S", 0.2)
    with pytest.raises(fs.SourceError, match="black frames"):
        fs.DeviceSource(index=0, blank_retries=0).open()
    assert len(reads) > 1


def test_the_black_budget_never_outlives_the_open_budget(monkeypatch):
    """A test or a caller that lowers the open timeout expects the whole open to finish
    inside it, so the black tolerance is clamped rather than added on top."""

    monkeypatch.setattr(fs.cv2, "VideoCapture", lambda index, flag=0: FakeCapture(blank=True))
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 0.05)
    monkeypatch.setattr(fs, "BLANK_TIMEOUT_S", 30.0)
    started = time.monotonic()
    with pytest.raises(fs.SourceError, match="black frames"):
        fs.DeviceSource(index=0).open()
    assert time.monotonic() - started < 5.0


def test_probe_devices_reports_a_named_camera_that_will_not_deliver(monkeypatch):
    """Camo missing from the list reads as a bug in this app; Camo listed with a reason
    tells the inspector what to go and do about it."""

    def capture_for(index, flag=0):
        return FakeCapture(frames=3) if index == 0 else FakeCapture(blank=True)

    monkeypatch.setattr(fs.cv2, "VideoCapture", capture_for)
    monkeypatch.setattr(fs, "device_names", lambda: ["Integrated Camera", "Camo"])
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 0.2)
    found = fs.probe_devices()
    camo = next(record for record in found if record["name"] == "Camo")
    assert camo["ok"] is False
    assert "black frames" in camo["error"]
    # Black is a state that clears, so the label invites another go rather than
    # pronouncing the camera dead.
    assert camo["blank"] is True
    assert "no picture yet" in camo["label"]


def test_probe_separates_a_black_camera_from_one_that_will_not_open(monkeypatch):
    """The picker offers the first and not the second, so the flag has to be right: a
    camera that opened and went black recovers on a reopen, while one that never opened
    is not there to connect to. Merging them either hides a working Camo or offers a
    camera that cannot exist."""

    def capture_for(index, flag=0):
        return FakeCapture(blank=True) if index == 0 else FakeCapture(opened=False)

    monkeypatch.setattr(fs.cv2, "VideoCapture", capture_for)
    monkeypatch.setattr(fs, "device_names", lambda: ["Camo", "OBS Virtual Camera"])
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 0.2)
    found = {record["name"]: record for record in fs.probe_devices()}
    assert found["Camo"]["blank"] is True
    assert found["OBS Virtual Camera"]["blank"] is False
    assert "not delivering" in found["OBS Virtual Camera"]["label"]


def test_virtual_cameras_are_recognised_by_name(monkeypatch):
    """The only signal available for ordering the picker: by the time it matters the
    camera has handed over no frames, so there is nothing but the name to go on."""

    assert fs.is_virtual_camera("Camo")
    assert fs.is_virtual_camera("Reincubate Camo")
    assert fs.is_virtual_camera("DroidCam Source 3")
    assert fs.is_virtual_camera("OBS Virtual Camera")
    assert not fs.is_virtual_camera("Integrated Camera")
    assert not fs.is_virtual_camera("Logitech C920")
    assert not fs.is_virtual_camera("")


def test_a_scan_takes_one_attempt_per_camera_and_leaves_retries_to_the_connect(monkeypatch):
    """Reopening every dead camera three times while the inspector watches a spinner
    turned a 6-second scan into a 26-second one on this laptop. The retry belongs on the
    connect, which is aimed at one camera the inspector actually chose."""

    opened: list[int] = []

    def capture_for(index, flag=0):
        opened.append(index)
        return FakeCapture(blank=True)

    monkeypatch.setattr(fs.cv2, "VideoCapture", capture_for)
    monkeypatch.setattr(fs, "device_names", lambda: ["Integrated Camera", "Camo"])
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 0.2)
    monkeypatch.setattr(fs, "BLANK_RETRIES", 2)
    fs.probe_devices()
    assert opened == [0, 1], "a scan reopened a camera it should have left to the connect"
    # ... whereas a deliberate connect to the same camera does retry.
    opened.clear()
    with pytest.raises(fs.SourceError):
        fs.DeviceSource(index=1, name="Camo").open()
    assert len(opened) == 3


def test_device_names_survives_a_missing_pygrabber(monkeypatch):
    """Names are a convenience; losing them must not lose the camera picker."""

    monkeypatch.setattr(fs, "IS_WINDOWS", True)
    real_import = __import__

    def blocked(name, *args, **kwargs):
        if name.startswith("pygrabber"):
            raise ImportError("no pygrabber here")
        return real_import(name, *args, **kwargs)

    monkeypatch.setattr("builtins.__import__", blocked)
    assert fs.device_names() == []


# --------------------------------------------------------------------------
# AdbScreenrecordSource: preflight and the frame handoff
# --------------------------------------------------------------------------

def test_screenrecord_source_requires_adb(monkeypatch):
    monkeypatch.setattr(fs, "find_adb", lambda: None)
    with pytest.raises(fs.SourceError, match="adb was not found"):
        fs.AdbScreenrecordSource().open()


def test_screenrecord_source_explains_an_unauthorised_phone(monkeypatch):
    """The single most common demo-day snag deserves the actionable message."""

    monkeypatch.setattr(fs, "find_adb", lambda: "adb")
    monkeypatch.setattr(
        fs, "adb_devices",
        lambda: [{"serial": "9A2X", "state": "unauthorized", "model": "", "product": ""}],
    )
    with pytest.raises(fs.SourceError, match="USB debugging"):
        fs.AdbScreenrecordSource().open()


def test_screenrecord_source_defaults_to_the_first_ready_phone(monkeypatch):
    monkeypatch.setattr(fs, "find_adb", lambda: "adb")
    monkeypatch.setattr(
        fs, "adb_devices",
        lambda: [
            {"serial": "offline1", "state": "offline", "model": "", "product": ""},
            {"serial": "GOODPHONE", "state": "device", "model": "SM G973F", "product": "x"},
        ],
    )
    started: dict = {}

    def fake_thread_start(self):
        started["name"] = self.name
        # Publish a frame the way the real reader thread would, so open() can
        # succeed without decoding actual H.264.
        source._publish(np.zeros((720, 1280, 3), dtype=np.uint8))

    monkeypatch.setattr(fs.Thread, "start", fake_thread_start)
    source = fs.AdbScreenrecordSource()
    source.open()
    assert source.serial == "GOODPHONE"
    assert started["name"] == "adb-screenrecord"
    assert "1280x720" in source.describe()


def test_screenrecord_source_times_out_without_a_keyframe(monkeypatch):
    monkeypatch.setattr(fs, "find_adb", lambda: "adb")
    monkeypatch.setattr(
        fs, "adb_devices",
        lambda: [{"serial": "R58M", "state": "device", "model": "", "product": ""}],
    )
    monkeypatch.setattr(fs.Thread, "start", lambda self: None)
    monkeypatch.setattr(fs, "OPEN_TIMEOUT_S", 0.2)
    source = fs.AdbScreenrecordSource()
    # The floor in open() is max(OPEN_TIMEOUT_S, 12.0); patch the comparison away
    # by making the wait short enough to keep the suite fast.
    monkeypatch.setattr(fs.time, "monotonic", _clock(step=6.0))
    with pytest.raises(fs.SourceError, match="decoded no frame"):
        source.open()


def _clock(step: float):
    """A monotonic clock that jumps forward ``step`` seconds per call."""

    state = {"now": 0.0}

    def monotonic() -> float:
        state["now"] += step
        return state["now"]

    return monotonic


def test_screenrecord_read_pops_the_frame_once():
    """read() must not re-serve one decoded frame: the caller measures its own
    frame rate off this, and a repeat would inflate that number."""

    source = fs.AdbScreenrecordSource()
    frame = np.full((8, 8, 3), 7, dtype=np.uint8)
    source._publish(frame)
    assert source.read() is not None
    assert source.read() is None


def test_screenrecord_read_raises_the_reader_threads_error():
    source = fs.AdbScreenrecordSource()
    source._fail("phone screen stream failed: cable pulled")
    with pytest.raises(fs.SourceError, match="cable pulled"):
        source.read()


def test_screenrecord_read_drains_a_buffered_frame_before_reporting_failure():
    """A frame already decoded is still worth showing, even if the stream then died."""

    source = fs.AdbScreenrecordSource()
    source._publish(np.zeros((4, 4, 3), dtype=np.uint8))
    source._fail("stream ended")
    assert source.read() is not None
    with pytest.raises(fs.SourceError, match="stream ended"):
        source.read()


def test_screenrecord_close_terminates_the_child_and_is_idempotent():
    class FakeProcess:
        def __init__(self):
            self.terminated = False
            self.alive = True
            self.stdout = None
            self.stderr = None

        def poll(self):
            return None if self.alive else 0

        def terminate(self):
            self.terminated = True
            self.alive = False

        def wait(self, timeout=None):
            return 0

        def kill(self):
            self.alive = False

    source = fs.AdbScreenrecordSource()
    process = FakeProcess()
    source._process = process
    source.close()
    assert process.terminated
    assert source._stop is True
    source.close()  # must not raise


def test_screenrecord_close_kills_a_child_that_ignores_terminate():
    class Stubborn:
        def __init__(self):
            self.killed = False
            self.stdout = None
            self.stderr = None

        def poll(self):
            return None

        def terminate(self):
            pass

        def wait(self, timeout=None):
            raise subprocess.TimeoutExpired("adb", timeout or 3.0)

        def kill(self):
            self.killed = True

    process = Stubborn()
    fs.AdbScreenrecordSource._terminate(process)
    assert process.killed


class _FakeStdout:
    """Hands out fixed chunks, then EOF - which is how screenrecord ends."""

    def __init__(self, chunks):
        self._chunks = list(chunks)

    def read(self, size):
        return self._chunks.pop(0) if self._chunks else b""

    def close(self):
        pass


class _FakeCodec:
    """A parse/decode pair that turns each chunk into one frame."""

    def __init__(self):
        self.chunks_seen = 0

    def parse(self, chunk):
        self.chunks_seen += 1
        return [f"packet-{self.chunks_seen}"]

    def decode(self, packet):
        class Frame:
            @staticmethod
            def to_ndarray(format="bgr24"):
                return np.full((16, 16, 3), 5, dtype=np.uint8)

        return [Frame()]


def test_decode_stream_publishes_each_decoded_frame_and_stops_at_eof():
    source = fs.AdbScreenrecordSource()

    class Process:
        stdout = _FakeStdout([b"aa", b"bb"])

    codec = _FakeCodec()
    source._decode_stream(Process(), codec)
    assert codec.chunks_seen == 2
    assert source.read() is not None  # the last frame decoded is available


def test_decode_stream_returns_immediately_when_stopped():
    """close() sets _stop; the loop must not consume another chunk after that."""

    source = fs.AdbScreenrecordSource()
    source._stop = True
    stdout = _FakeStdout([b"aa"])

    class Process:
        pass

    Process.stdout = stdout
    codec = _FakeCodec()
    source._decode_stream(Process(), codec)
    assert codec.chunks_seen == 0
