"""Camera frame sources that do not need a network.

Why this module exists
---------------------
``webrtc_live`` gets its frames from the *browser*: the page calls
``getUserMedia``, aiortc negotiates a peer connection, and frames arrive over
WebRTC. That works, but it drags in three hard requirements that all failed
during an offline dry run of the evaluation demo:

1. **A secure origin.** Browsers refuse ``getUserMedia`` on anything except
   ``localhost`` or HTTPS. Demoing over a LAN IP or a tunnel URL therefore needs
   working DNS/TLS - i.e. the internet.
2. **ICE negotiation.** With a STUN server configured, ICE gathering waits for a
   reply from ``stun.l.google.com``. Unplugged, that is a stall, not an error.
3. **A reachable tunnel.** If the demo URL is a Cloudflare tunnel, no internet
   means no page at all.

Nothing about reading a camera actually requires any of that. So this module
takes the other route: **the Streamlit server opens the camera itself**, in
process, with OpenCV or a USB pipe, and hands ``numpy`` frames to the same
``LiveScanProcessor`` the WebRTC path uses. No browser camera, no ICE, no STUN,
no secure origin, no internet.

Sources, in the order worth trying on demo day
----------------------------------------------
* :class:`DeviceSource` - any camera Windows already exposes: a built-in webcam,
  a USB webcam, or a *virtual* camera published by Camo / DroidCam / OBS. Zero
  setup beyond picking an index.
* :class:`UrlSource` - an MJPEG or RTSP URL. Combined with :func:`adb_forward`
  this is the good phone path: the phone runs an MJPEG server, ``adb forward``
  carries it over the USB cable, and OpenCV reads ``127.0.0.1``. No Wi-Fi, no
  internet, full sensor resolution, ~150 ms latency.
* :class:`AdbScreenrecordSource` - the phone's *screen* over USB, decoded from
  ``adb exec-out screenrecord``. Needs no app installed beyond USB debugging, so
  it is the fallback that always works; the cost is ~1-2 s of latency and the
  camera app's own UI in frame.

Everything here is deliberately Streamlit-free so it can be unit-tested; the UI
lives in ``local_camera``.
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
import logging
import os
import shutil
import subprocess
import sys
from threading import Lock, Thread
import time
from typing import Any, ClassVar

import cv2
import numpy as np

logger = logging.getLogger(__name__)

IS_WINDOWS = sys.platform.startswith("win")

#: Ask the camera for the highest resolution it will give. On this dataset the
#: limiting factor for OCR is how many pixels the printed declaration occupies,
#: so a 640x480 stream is not worth scanning.
DEFAULT_WIDTH = 1920
DEFAULT_HEIGHT = 1080

#: How long to wait for a camera that has delivered *no* frame at all before calling
#: it dead. Virtual cameras (Camo, DroidCam, OBS) commonly open instantly but deliver
#: nothing for a second or two while their pipeline spins up. A camera delivering black
#: frames is a different failure on a different budget - see :data:`BLANK_TIMEOUT_S`.
OPEN_TIMEOUT_S = 8.0

#: The same wait, for :func:`probe_devices`. Shorter because a scan pays it per
#: camera and the inspector is sitting in front of a spinner: on a laptop with an
#: integrated webcam, Camo and an OBS virtual camera, three eight-second waits read
#: as a hung application. Connecting to one chosen camera still gets the full
#: :data:`OPEN_TIMEOUT_S`, so a slow virtual camera costs a re-scan at worst.
PROBE_TIMEOUT_S = 3.0

#: How long a capture may hand back all-black buffers before that session is written
#: off. Separate from :data:`OPEN_TIMEOUT_S` because the two failures deserve
#: different budgets: a camera that has produced no frame *at all* may still be
#: warming up, and waiting helps, whereas one that is producing black is stuck, and
#: waiting does not. Measured against a Camo virtual camera that was black on roughly
#: one open in three: twelve seconds of black inside a single session never cleared,
#: twice, while a fresh capture delivered a real frame in 0.02s. So the budget here is
#: short and the recovery is a reopen, not a longer wait. Timed rather than counted
#: because DirectShow emits one black buffer per second while an MJPEG stream emits
#: them at frame rate, and a warming-up phone camera must not be written off after two
#: frames.
BLANK_TIMEOUT_S = 2.0

#: Fresh captures to try when the symptom is an all-black buffer, on top of the first
#: attempt. Over five trials against that same flaky Camo camera, a short wait plus a
#: reopen landed a real frame every time - worst case 2.97s, typically 0.5s - where a
#: single long wait failed three times in four. This is why the "Camo is not detected"
#: report is a code problem and not just a phone problem.
BLANK_RETRIES = 2

#: Appended once to a camera failure whose symptom was an all-black buffer. Kept out
#: of the per-backend reasons so the message ends in one checklist rather than three
#: copies of the same paragraph. Deliberately a list of things to *check* rather than
#: a diagnosis: OpenCV cannot tell a closed shutter from a busy camera from a virtual
#: camera whose desktop app is idle, and at a demo the inspector can check all three
#: faster than anybody can work out which it was. It ends with "try again" because
#: this state was measured to be bursty on a Wi-Fi-connected Camo camera - three
#: reopens inside fourteen seconds all came back black, and a fresh attempt a second
#: later delivered immediately - so another press really is the next thing to do.
BLANK_FRAME_ADVICE = (
    "The camera is open but nothing is reaching it - check a privacy shutter or lens "
    "cover, close any other app using the camera (Teams, Zoom, a browser tab, another "
    "copy of this app), and for a virtual camera make sure its desktop app is connected "
    "and streaming. If it all looks right, press Connect again: a phone camera over "
    "Wi-Fi goes quiet in bursts and usually comes back within a few seconds."
)

#: Substrings that mark a camera as published by a desktop app rather than built into
#: the machine. Used only to order the camera picker, and it matters most when nothing
#: delivered a picture: with a shuttered laptop webcam at index 0 and Camo at index 1,
#: index order defaults the picker to the webcam, which is precisely the "the app only
#: scans the integrated camera" report this route grew out of. An inspector on this
#: route is aiming a phone at a package, so a phone camera outranks the laptop's own.
#: Matched case-insensitively; a name not listed here simply keeps its index order.
VIRTUAL_CAMERA_HINTS = ("camo", "droidcam", "iriun", "epoccam", "obs", "virtual", "phone")

#: ``adb exec-out screenrecord`` caps out at 180 s per invocation, so the source
#: restarts the child process before Android kills it.
SCREENRECORD_LIMIT_S = 175

#: stdout read size for the H.264 pipe. Large enough that the decoder is not woken
#: per-NAL, small enough that a frame is never held back waiting for the buffer.
_READ_CHUNK = 1 << 16

#: Default MJPEG endpoints, by app. Both are plain HTTP on the phone, tunnelled
#: over USB by ``adb forward`` so nothing touches Wi-Fi.
MJPEG_PRESETS = {
    "IP Webcam": (8080, "/video"),
    "DroidCam": (4747, "/video"),
}

__all__ = [
    "AdbScreenrecordSource",
    "DeviceSource",
    "FrameSource",
    "SourceError",
    "UrlSource",
    "adb_devices",
    "adb_forward",
    "adb_remove_forward",
    "device_names",
    "find_adb",
    "mjpeg_url",
    "normalise_stream_url",
    "parse_adb_devices",
    "probe_devices",
    "screenrecord_command",
]


class SourceError(RuntimeError):
    """A frame source could not be opened, or died mid-stream."""


class FrameSource(ABC):
    """One camera, opened server-side. ``read`` returns BGR or ``None``."""

    #: Human-readable, shown in the UI and stored on the capture record.
    label: str = "frame source"

    #: True when :meth:`read` already returns the newest frame the source has, and
    #: returns ``None`` rather than blocking when nothing new has arrived since the
    #: last call. Such a source cannot accumulate a backlog, so a caller need not
    #: drain one; a source that leaves this ``False`` may hand back queued frames
    #: oldest-first and should be drained before the newest one is used.
    latest_only: bool = False

    @abstractmethod
    def open(self) -> None:
        """Acquire the device. Raises :class:`SourceError` on failure."""

    @abstractmethod
    def read(self) -> np.ndarray | None:
        """Next frame as BGR, or ``None`` when none is available right now."""

    def close(self) -> None:
        """Release the device. Must be safe to call twice."""

    def describe(self) -> str:
        return self.label

    def __enter__(self) -> "FrameSource":
        self.open()
        return self

    def __exit__(self, *exc: Any) -> None:
        self.close()


#: OpenCV capture backends worth trying on Windows, best first. DirectShow is
#: listed ahead of Media Foundation because the virtual cameras this project has
#: to work with (Camo, DroidCam, OBS) all register as DirectShow devices, and MSMF
#: often opens them and then delivers nothing.
_WINDOWS_APIS: tuple[tuple[str, int], ...] = (
    ("dshow", cv2.CAP_DSHOW),
    ("msmf", cv2.CAP_MSMF),
    ("any", cv2.CAP_ANY),
)
_POSIX_APIS: tuple[tuple[str, int], ...] = (("any", cv2.CAP_ANY),)


def _capture_apis(preferred: str = "auto") -> tuple[tuple[str, int], ...]:
    table = _WINDOWS_APIS if IS_WINDOWS else _POSIX_APIS
    if preferred in {"auto", "", None}:
        return table
    chosen = tuple(item for item in table if item[0] == preferred)
    # Keep the rest as fallbacks rather than failing outright: a wrong backend
    # guess should cost a warning, not the demo.
    return chosen + tuple(item for item in table if item[0] != preferred)


@dataclass
class DeviceSource(FrameSource):
    """A local camera by index: webcam, USB camera, or a virtual camera.

    ``index`` is the OpenCV device index, which is also what Camo, DroidCam and
    the OBS virtual camera occupy once their driver is running - so this class is
    the drop-in replacement for the Camo workflow without the Camo desktop app in
    the loop, and equally the path for a plain USB webcam.

    All the unreliability measured on this route lives in :meth:`open`, and none of it
    in :meth:`read`. Against a Camo camera fed by a phone over Wi-Fi, opening came back
    with an all-black buffer on roughly one attempt in three, in bursts - but once a
    capture handed over one real frame it stayed solid: 901 reads over 30 seconds at a
    flat 30.0/s, not one black frame and not one empty read. That is why the effort here
    goes into getting *through* the handshake - reopening rather than waiting, and
    leaving a black camera selectable - and why nothing guards the steady state.
    """

    index: int = 0
    #: Requested frame size; ``0`` leaves the camera in whatever mode it opens in.
    #: Asking DirectShow to renegotiate the graph measured at ~2s per open on this
    #: laptop, which a scan across three cameras cannot afford and does not need - see
    #: :func:`probe_devices`.
    width: int = DEFAULT_WIDTH
    height: int = DEFAULT_HEIGHT
    api: str = "auto"
    fourcc: str = "MJPG"
    #: The DirectShow name, when the caller knows it. Only cosmetic, but the cosmetic
    #: part matters: the inspector picked "Camo" from a list, so a failure that talks
    #: about "camera index 1" reads as being about something else entirely.
    name: str = ""
    #: Seconds to wait for the first real frame. ``0`` means :data:`OPEN_TIMEOUT_S`,
    #: resolved when :meth:`open` runs rather than baked in as a default here, so
    #: lowering the module constant still takes effect.
    timeout_s: float = 0.0
    #: How long this camera may hand back black before the session is written off.
    #: Negative means :data:`BLANK_TIMEOUT_S`; ``0`` makes the first black frame
    #: conclusive, which is what a scan wants - each black read costs a flat second of
    #: DirectShow timeout, and the scan no longer needs to be generous now that a black
    #: camera stays selectable.
    blank_timeout_s: float = -1.0
    #: Fresh captures to try after an all-black one. Negative means
    #: :data:`BLANK_RETRIES`, resolved at :meth:`open` time for the same reason.
    #: :func:`probe_devices` passes ``0``: a scan across several cameras cannot afford
    #: to reopen a dead one three times, and it no longer needs to, because a camera
    #: that came back black is still offered in the picker and the connect that follows
    #: gets the full retry budget.
    blank_retries: int = -1
    capture: Any | None = field(default=None, repr=False, compare=False)
    opened_api: str = ""
    #: Set by :meth:`open` when the failure was an all-black buffer rather than a
    #: refusal to open. The distinction is what lets the picker offer the camera
    #: anyway: black is a state that clears, whereas a device that will not open is
    #: not there.
    blank_only: bool = False

    def __post_init__(self) -> None:
        self.label = self.target

    @property
    def target(self) -> str:
        """How to refer to this camera in a message: the name if there is one."""

        return f"{self.name} (index {self.index})" if self.name else f"device {self.index}"

    def open(self) -> None:
        errors: list[str] = []
        advice = ""
        retries = BLANK_RETRIES if self.blank_retries < 0 else self.blank_retries
        for name, flag in _capture_apis(self.api):
            attempts = 0
            blank = False
            while True:
                attempts += 1
                capture, frame, reason, blank = self._attempt(flag)
                if capture is not None and frame is not None:
                    self.capture = capture
                    self.opened_api = name
                    self.blank_only = False
                    actual = (frame.shape[1], frame.shape[0])
                    self.label = f"{self.target} via {name} at {actual[0]}x{actual[1]}"
                    logger.info("camera opened: %s", self.label)
                    return
                # A stuck capture graph does not recover in place - the measurements
                # behind :data:`BLANK_RETRIES` say so - so the retry is a reopen, and
                # only the black-buffer symptom is worth reopening for. A backend that
                # refused to open at all will refuse identically a second later.
                if not blank or attempts > retries:
                    break
                logger.info("%s: %s on attempt %d, reopening", self.target, reason, attempts)
            errors.append(
                f"{name}: {reason}" if attempts == 1
                else f"{name}: {reason} on {attempts} attempts"
            )
            if blank:
                # Stop here rather than paying the same waits again. Every remaining
                # backend drives the same device, so it reaches the same all-black
                # buffer; on a machine with three cameras that is a scan an inspector
                # will assume has hung.
                advice = BLANK_FRAME_ADVICE
                break
        self.blank_only = bool(advice)
        tried = f"{self.target} could not be opened. Tried - " + "; ".join(errors)
        # The checklist goes on the end once, not once per backend: three copies of it
        # is what turns an actionable message into a wall of text.
        raise SourceError(f"{tried}. {advice}" if advice else tried)

    def _attempt(self, flag: int) -> tuple[Any | None, np.ndarray | None, str, bool]:
        """One open-configure-wait cycle against a single backend.

        Returns ``(capture, first_frame, reason, blank)``; on failure the capture is
        already released and the first two are ``None``. Split out of :meth:`open`
        because recovering a black camera means building a *new* capture, so the
        retry loop has to own the object's whole lifetime, not just the read.
        """

        capture = cv2.VideoCapture(self.index, flag)
        if not capture.isOpened():
            capture.release()
            return None, None, "would not open", False
        # MJPG before the resolution request: many USB cameras only offer
        # 1080p in MJPG and silently clamp to 640x480 in raw YUY2.
        if self.fourcc:
            capture.set(cv2.CAP_PROP_FOURCC, cv2.VideoWriter_fourcc(*self.fourcc))
        if self.width and self.height:
            capture.set(cv2.CAP_PROP_FRAME_WIDTH, int(self.width))
            capture.set(cv2.CAP_PROP_FRAME_HEIGHT, int(self.height))
        frame, reason, blank = self._wait_for_first_frame(
            capture, self.timeout_s, self.blank_timeout_s
        )
        if frame is None:
            capture.release()
            return None, None, reason, blank
        return capture, frame, "", False

    @staticmethod
    def _is_blank(frame: np.ndarray) -> bool:
        """True for the all-zero buffer a stalled capture graph hands back.

        OpenCV's DirectShow backend returns ``ok=True`` with an all-black buffer
        when the graph is running but no sample arrived within its internal
        one-second timeout. A closed privacy shutter, a camera already held by
        another application, and a virtual camera whose desktop app is not
        streaming all look exactly like this - reads succeed, at a suspiciously
        even 1 fps, forever.

        Accepting that frame is what makes a dead camera indistinguishable from a
        working one in the picker, so it is rejected. The test is exact-zero on
        purpose: a dim room is dark, never uniformly zero.
        """

        return not frame.any()

    @staticmethod
    def _wait_for_first_frame(
        capture: Any, timeout_s: float = 0.0, blank_timeout_s: float = -1.0
    ) -> tuple[np.ndarray | None, str, bool]:
        """First *real* frame, or ``(None, reason, blank)`` explaining what went wrong.

        The reason is returned rather than raised because :meth:`open` decides what to
        do with it - reopen, try another backend, or give up - and reports every
        attempt together. ``blank`` distinguishes the two failures that need different
        handling: a backend that never opened is worth replacing with another, whereas
        one that opened and delivered only black is stuck, and the fix is a fresh
        capture rather than a longer wait.

        The two failures also get different budgets. ``timeout_s`` (``0`` meaning
        :data:`OPEN_TIMEOUT_S`) bounds the wait for a camera that has produced nothing
        at all, which may still be starting up. Once black frames start arriving,
        ``blank_timeout_s`` bounds it instead - negative meaning
        :data:`BLANK_TIMEOUT_S`, ``0`` convicting on the first black frame - because
        waiting out the full timeout on a stuck graph only delays the reopen that does
        work.
        """

        budget = timeout_s or OPEN_TIMEOUT_S
        black_budget = BLANK_TIMEOUT_S if blank_timeout_s < 0 else blank_timeout_s
        black_budget = min(black_budget, budget)
        deadline = time.monotonic() + budget
        black_since = 0.0
        while time.monotonic() < deadline:
            ok, frame = capture.read()
            now = time.monotonic()
            if ok and frame is not None and getattr(frame, "size", 0):
                if not DeviceSource._is_blank(frame):
                    return frame, "", False
                if not black_since:
                    black_since = now
                if now - black_since >= black_budget:
                    break
                continue  # the read already cost the backend's own timeout
            time.sleep(0.05)
        if black_since:
            return None, "delivered only black frames", True
        return None, f"opened but delivered no frame in {budget:.0f}s", False

    def read(self) -> np.ndarray | None:
        if self.capture is None:
            raise SourceError("read() before open()")
        ok, frame = self.capture.read()
        if not ok or frame is None or not getattr(frame, "size", 0):
            return None
        return frame

    def close(self) -> None:
        if self.capture is not None:
            self.capture.release()
            self.capture = None


def device_names() -> list[str]:
    """DirectShow camera names in OpenCV index order, or ``[]`` if unavailable.

    OpenCV can only open a camera by integer index and offers no way to ask what
    that index *is*. That is a real usability failure here: a picker showing
    "device 0 / device 1" gives the inspector no way to tell the integrated webcam
    from Camo, so the phone camera looks undetected even when it opened fine.

    ``pygrabber`` enumerates the DirectShow graph, and its ordering is the same one
    ``cv2.CAP_DSHOW`` indexes by. Windows-only and optional - a missing package
    costs the names, not the feature.
    """

    if not IS_WINDOWS:
        return []
    try:
        from pygrabber.dshow_graph import FilterGraph
    except Exception as exc:  # ImportError, or comtypes failing to initialise
        logger.info("camera names unavailable (%s); falling back to indices", exc)
        return []
    try:
        return list(FilterGraph().get_input_devices())
    except Exception as exc:
        logger.info("could not enumerate camera names: %s", exc)
        return []


def is_virtual_camera(name: str) -> bool:
    """True for a camera a desktop app publishes - Camo, DroidCam, OBS and friends.

    A name test, because there is nothing else to go on: DirectShow reports a virtual
    camera exactly like a physical one, and by the time this matters the camera has
    already failed to hand over a picture, so its frames cannot be inspected either.
    Used purely to order :func:`probe_devices` results - see
    :data:`VIRTUAL_CAMERA_HINTS` - so an unrecognised phone app costs the default
    selection, nothing more.
    """

    lowered = name.lower()
    return any(hint in lowered for hint in VIRTUAL_CAMERA_HINTS)


def probe_devices(
    max_index: int = 5, api: str = "auto", timeout_s: float = 0.0
) -> list[dict[str, Any]]:
    """Which cameras exist, which actually deliver frames, and why not.

    Called once from the UI so the inspector picks from a list instead of guessing
    an index. Opening a camera is slow (a virtual camera can take seconds), which
    is why this is a deliberate button rather than something that runs on
    every rerun.

    Each record carries ``ok``. A camera Windows enumerated but which would not
    deliver is returned with ``ok=False`` and an ``error`` explaining why, instead
    of being dropped: "Camo is listed but not delivering, because its desktop app
    is not streaming" is actionable, whereas Camo silently missing from the list
    reads as a bug in this application. Unnamed indices - the non-Windows path,
    where nothing enumerates the hardware - are dropped when they fail, since
    there is nothing to report about a device that may not exist.

    A failed record also carries ``blank``: true when the camera opened and handed
    back an all-black buffer, false when it would not open at all. Only the first is
    worth offering in the picker anyway, and it *must* be - a Camo camera measured
    here was black on roughly one open in three, so a scan that treats one black
    attempt as final is exactly the "the app is not detecting Camo" report this
    grew out of.

    That changes what a scan is for, and so how much it should spend. It answers "does
    this camera hand over a picture right now?", nothing more, and a "no" is no longer
    a verdict. So each camera gets one cheap attempt: no reopen retries, no waiting out
    a second black frame, and no 1080p renegotiation - each of which measured at
    roughly two seconds per camera on this laptop, and together turned a 6-second scan
    into a 26-second one. The reported size is therefore the camera's own default mode
    rather than what a connect will negotiate; it is a hint for telling two cameras
    apart, not a promise.

    When names are available the scan covers exactly the cameras Windows reports
    rather than a fixed index range, which also avoids paying the full open
    timeout on indices that hold nothing. ``timeout_s`` is shorter than the one a
    deliberate connect uses, because a scan pays it once per camera; ``0`` means
    :data:`PROBE_TIMEOUT_S`, read here rather than used as the parameter default so
    that changing the constant changes the behaviour.
    """

    names = device_names()
    limit = len(names) - 1 if names else max_index
    found: list[dict[str, Any]] = []
    for index in range(max(1, limit + 1)):
        name = names[index] if index < len(names) else ""
        source = DeviceSource(
            index=index, api=api, name=name,
            timeout_s=timeout_s or PROBE_TIMEOUT_S,
            width=0, height=0, fourcc="", blank_timeout_s=0.0, blank_retries=0,
        )
        try:
            source.open()
        except SourceError as exc:
            logger.info("camera index %d (%s) unusable: %s", index, name or "unnamed", exc)
            if name:
                found.append({
                    "index": index, "api": "", "width": 0, "height": 0, "name": name,
                    "ok": False, "blank": source.blank_only, "error": str(exc),
                    "label": (
                        f"{name} - no picture yet (index {index})"
                        if source.blank_only
                        else f"{name} - not delivering"
                    ),
                })
            continue
        try:
            frame = source.read()
            height, width = (frame.shape[0], frame.shape[1]) if frame is not None else (0, 0)
            found.append(
                {
                    "index": index,
                    "api": source.opened_api,
                    "width": width,
                    "height": height,
                    "name": name,
                    "ok": True,
                    "blank": False,
                    "error": "",
                    # The name leads: it is the only part an inspector recognises.
                    "label": (
                        f"{name} - {width}x{height} (index {index})"
                        if name
                        else source.label
                    ),
                }
            )
        finally:
            source.close()
    return found


@dataclass
class UrlSource(FrameSource):
    """An MJPEG / RTSP / HTTP video URL, drained by a background thread.

    The intended use is a phone MJPEG server reached over ``adb forward``, i.e.
    ``http://127.0.0.1:8080/video`` - the bytes travel down the USB cable, so this
    is still a fully offline path despite being an HTTP URL.

    Why a thread, when :class:`DeviceSource` needs none
    --------------------------------------------------
    A webcam driver hands back the *live* frame and quietly drops what nobody
    collected; an MJPEG server instead pushes into a TCP socket that keeps
    buffering while the consumer is busy. The preview spends ~90 ms per frame in
    the processor, so reading in step with processing means every read returns
    something older than the last, and the lag grows until the socket buffer is
    full - measured at 5-7 s on a phone at 1080p, which is what made this route
    unusable for aiming a camera.

    Draining harder inside the UI loop does not fix it: at 1080p a single
    ``grab()`` costs ~42 ms because the read is network-bound, not decode-bound,
    so the loop cannot outrun the stream it is trying to catch up with. Measured
    on a non-blocking test server at 30 fps / 1080p, median lag behind live:

    ==================================================  ==========
    process every frame read (the previous behaviour)    0.87 s
    ``grab()`` past the backlog, decode only the newest  0.30 s
    background reader, newest frame wins (this class)    0.17 s
    ==================================================  ==========

    So the socket is drained continuously on its own thread, which is never
    blocked by the processor, and :meth:`read` returns the newest frame that has
    arrived. Frames nobody asked for are dropped rather than queued - for aiming a
    camera, the current frame is the only one with any value.
    """

    url: str = ""
    timeout_s: float = OPEN_TIMEOUT_S
    capture: Any | None = field(default=None, repr=False, compare=False)
    _frame: np.ndarray | None = field(default=None, repr=False, compare=False)
    _lock: Lock = field(default_factory=Lock, repr=False, compare=False)
    _reader: Any | None = field(default=None, repr=False, compare=False)
    _stop: bool = field(default=False, repr=False, compare=False)
    _error: str = field(default="", repr=False, compare=False)
    #: Frames the reader dropped because the UI had not collected them. Surfaced in
    #: the UI as evidence that the preview is showing live pixels, not a backlog.
    dropped: int = field(default=0, repr=False, compare=False)

    #: The reader thread is what keeps the socket empty, so a caller must not try to
    #: drain one here: every backlog this source could have has already been dropped.
    #: A ``ClassVar`` rather than a field, so it stays out of the constructor.
    latest_only: ClassVar[bool] = True

    def __post_init__(self) -> None:
        self.label = f"url {self.url}"

    def open(self) -> None:
        if not self.url:
            raise SourceError("no URL given")
        capture = cv2.VideoCapture(self.url)
        if not capture.isOpened():
            capture.release()
            raise SourceError(
                f"could not open {self.url}. If this is a phone MJPEG server, check "
                f"that 'adb forward' is active and the app's server is started."
            )
        # Belt and braces with the reader thread: the backend may honour this, and
        # where it does the thread has less to throw away.
        capture.set(cv2.CAP_PROP_BUFFERSIZE, 1)
        frame, reason, blank = DeviceSource._wait_for_first_frame(capture)
        if frame is None:
            capture.release()
            raise SourceError(
                f"{self.url} {reason}. {BLANK_FRAME_ADVICE}" if blank
                else f"{self.url} {reason}"
            )
        self.capture = capture
        self._frame = frame
        self._stop = False
        self._error = ""
        self.dropped = 0
        self.label = f"url {self.url} at {frame.shape[1]}x{frame.shape[0]}"
        self._reader = Thread(target=self._drain, name="url-source-reader", daemon=True)
        self._reader.start()

    def _drain(self) -> None:
        """Keep the socket empty; publish only the newest frame. Runs off-thread."""

        capture = self.capture
        while not self._stop and capture is not None:
            try:
                ok, frame = capture.read()
            except Exception as exc:  # a dying stream must not kill the thread silently
                with self._lock:
                    self._error = str(exc)
                return
            if not ok or frame is None or not getattr(frame, "size", 0):
                # A momentary gap is normal for MJPEG; a persistent one is the stream
                # ending, which read() reports once the UI next asks.
                with self._lock:
                    self._error = "the video stream ended"
                return
            with self._lock:
                if self._frame is not None:
                    self.dropped += 1
                self._frame = frame

    def read(self) -> np.ndarray | None:
        """The newest frame that has arrived, or ``None`` if none is new.

        The frame is taken rather than copied, so a caller polling faster than the
        camera delivers sees ``None`` instead of processing one frame repeatedly and
        reporting an inflated frame rate.
        """

        if self.capture is None:
            raise SourceError("read() before open()")
        with self._lock:
            frame, self._frame = self._frame, None
            error = self._error
        if frame is not None:
            return frame
        if error:
            raise SourceError(f"{self.url}: {error}")
        return None

    def close(self) -> None:
        self._stop = True
        reader, self._reader = self._reader, None
        if reader is not None and reader.is_alive():
            # release() below is what actually unblocks a thread parked in read(),
            # so this join only has to cover the gap between iterations.
            reader.join(timeout=0.5)
        if self.capture is not None:
            self.capture.release()
            self.capture = None
        with self._lock:
            self._frame = None


def mjpeg_url(port: int, path: str = "/video", host: str = "127.0.0.1") -> str:
    """Build the loopback URL that ``adb forward`` exposes on this machine."""

    suffix = path if path.startswith("/") else f"/{path}"
    return f"http://{host}:{int(port)}{suffix}"


def normalise_stream_url(entered: str, port: int = 8080, path: str = "/video") -> str:
    """Turn whatever the inspector typed into a URL OpenCV can open.

    IP Webcam shows an address like ``http://192.168.1.2:8080`` on the phone
    screen, so that is what gets typed - bare, with no stream path, and sometimes
    with the ``https://`` variant the app also advertises. Each of those opens
    nothing in OpenCV, which is a confusing failure when the same address plays
    fine in a browser. So:

    * a bare host or ``host:port`` gains a scheme,
    * ``https`` is downgraded to ``http`` (IP Webcam's TLS is a self-signed cert
      that OpenCV's FFmpeg backend will not accept, and this is LAN traffic),
    * a missing port gains ``port``, and a URL with no path gains ``path``.

    An ``rtsp://`` URL is passed through untouched apart from whitespace: it
    carries its own scheme, port convention and path.
    """

    text = (entered or "").strip().strip('"')
    if not text:
        raise SourceError("no address given")
    if text.startswith("rtsp://"):
        return text
    if "//" not in text:
        text = f"http://{text}"
    scheme, _, remainder = text.partition("://")
    if scheme.lower() == "https":
        # OpenCV/FFmpeg rejects IP Webcam's self-signed certificate, and on a LAN
        # there is nothing for TLS to protect us from that a hostile LAN would not
        # already own. The phone serves the identical stream over http.
        scheme = "http"
    host_port, slash, tail = remainder.partition("/")
    if ":" not in host_port:
        host_port = f"{host_port}:{int(port)}"
    suffix = f"/{tail}" if slash and tail else path
    return f"{scheme}://{host_port}{suffix}"


# ---------------------------------------------------------------------------
# adb: the phone-over-USB paths
# ---------------------------------------------------------------------------

#: Where Android platform-tools normally land on Windows, checked when ``adb`` is
#: not on PATH. ``LMPC_ADB`` overrides everything.
_ADB_CANDIDATES = (
    r"C:\platform-tools\adb.exe",
    r"C:\Android\platform-tools\adb.exe",
    os.path.expandvars(r"%LOCALAPPDATA%\Android\Sdk\platform-tools\adb.exe"),
    os.path.expandvars(r"%USERPROFILE%\platform-tools\adb.exe"),
    os.path.expandvars(r"%USERPROFILE%\Downloads\platform-tools\adb.exe"),
)

#: adb should answer instantly over USB; anything slower means a stuck daemon.
ADB_TIMEOUT_S = 20.0

#: Hide the console window adb would otherwise flash on Windows. Every adb call
#: goes through ``_run_adb``, so setting it in one place covers the module.
_NO_WINDOW = getattr(subprocess, "CREATE_NO_WINDOW", 0) if IS_WINDOWS else 0


def find_adb() -> str | None:
    """Absolute path to ``adb``, or ``None`` if it is not installed.

    ``LMPC_ADB`` wins, then PATH, then the usual platform-tools locations - so a
    demo machine with the zip merely unpacked in Downloads still works without
    anybody editing PATH under time pressure.
    """

    override = os.environ.get("LMPC_ADB", "").strip('" ')
    if override:
        return override if os.path.isfile(override) else None
    found = shutil.which("adb")
    if found:
        return found
    for candidate in _ADB_CANDIDATES:
        if candidate and os.path.isfile(candidate):
            return candidate
    return None


def _run_adb(args: list[str], serial: str | None = None, timeout: float = ADB_TIMEOUT_S) -> str:
    """Run one adb subcommand and return stdout. Raises :class:`SourceError`.

    argv is built as a list and never a shell string: the serial and any port come
    from device output or the UI, so a shell would be an injection surface.
    """

    adb = find_adb()
    if adb is None:
        raise SourceError(
            "adb was not found. Install Android platform-tools and put adb on PATH, "
            "or set LMPC_ADB to adb.exe."
        )
    command = [adb, *(["-s", serial] if serial else []), *args]
    try:
        completed = subprocess.run(
            command, capture_output=True, text=True, timeout=timeout, creationflags=_NO_WINDOW,
        )
    except subprocess.TimeoutExpired as exc:
        raise SourceError(f"adb {' '.join(args)} timed out after {timeout:.0f}s") from exc
    except OSError as exc:
        raise SourceError(f"could not run adb: {exc}") from exc
    if completed.returncode != 0:
        detail = (completed.stderr or completed.stdout or "").strip()
        raise SourceError(f"adb {' '.join(args)} failed: {detail or completed.returncode}")
    return completed.stdout


def parse_adb_devices(text: str) -> list[dict[str, str]]:
    """Parse ``adb devices -l`` output into serial/state/model records.

    Split out as a pure function so the parsing is unit-testable without a phone
    plugged in, which is the only part of the adb path that can go subtly wrong.
    """

    records: list[dict[str, str]] = []
    for line in text.splitlines():
        line = line.strip()
        if not line or line.lower().startswith("list of devices"):
            continue
        if line.startswith("*"):  # "* daemon started successfully *"
            continue
        parts = line.split()
        if len(parts) < 2:
            continue
        record = {"serial": parts[0], "state": parts[1], "model": "", "product": ""}
        for token in parts[2:]:
            key, _, value = token.partition(":")
            if key in {"model", "product"} and value:
                record[key] = value.replace("_", " ")
        records.append(record)
    return records


def adb_devices() -> list[dict[str, str]]:
    """Phones adb can see. ``state == "device"`` is the only usable one.

    ``unauthorized`` shows up when the USB-debugging prompt on the phone has not
    been accepted yet, which is by far the most common demo-day snag, so the state
    is returned rather than filtered out.
    """

    return parse_adb_devices(_run_adb(["devices", "-l"]))


def adb_forward(local_port: int, remote_port: int, serial: str | None = None) -> str:
    """Tunnel ``127.0.0.1:local_port`` on this PC to ``remote_port`` on the phone.

    This is the whole trick behind the offline phone camera: the phone's MJPEG
    server never touches Wi-Fi, its bytes ride the USB cable, and OpenCV opens a
    plain loopback URL on this machine.
    """

    local, remote = int(local_port), int(remote_port)
    _run_adb(["forward", f"tcp:{local}", f"tcp:{remote}"], serial=serial)
    logger.info("adb forward tcp:%d -> phone tcp:%d", local, remote)
    return mjpeg_url(local)


def adb_remove_forward(local_port: int, serial: str | None = None) -> None:
    """Tear the tunnel down. Never raises - cleanup must not break a demo."""

    try:
        _run_adb(["forward", "--remove", f"tcp:{int(local_port)}"], serial=serial)
    except SourceError as exc:
        logger.info("could not remove adb forward: %s", exc)


def screenrecord_command(
    adb: str,
    serial: str | None = None,
    size: str = "1280x720",
    bit_rate_mbps: int = 8,
    time_limit_s: int = SCREENRECORD_LIMIT_S,
) -> list[str]:
    """argv for a raw H.264 screen stream on stdout.

    ``--output-format=h264`` is what makes this streamable: the default mp4 writer
    only finalises its container when recording stops, so it cannot be read live.
    Kept a pure function so the flags are assertable in a test.
    """

    args = [
        adb, *(["-s", serial] if serial else []),
        "exec-out", "screenrecord",
        "--output-format=h264",
        f"--time-limit={int(time_limit_s)}",
        f"--bit-rate={int(bit_rate_mbps)}M",
    ]
    if size:
        args.append(f"--size={size}")
    args.append("-")
    return args


@dataclass
class AdbScreenrecordSource(FrameSource):
    """The phone's screen over USB, decoded from ``adb exec-out screenrecord``.

    This is the always-works fallback: it needs no app on the phone beyond USB
    debugging, so if the MJPEG route fails five minutes before a demo, open the
    phone's own camera app and point this at the screen. The costs are real -
    roughly 1-2 s of latency, the camera UI in frame, and screen resolution rather
    than sensor resolution - so it ranks below :class:`UrlSource`.

    Android kills ``screenrecord`` at 180 s, so the reader thread relaunches the
    child a little before that and the stream simply continues.
    """

    serial: str | None = None
    size: str = "1280x720"
    bit_rate_mbps: int = 8
    time_limit_s: int = SCREENRECORD_LIMIT_S
    restarts: int = field(default=0, init=False)

    #: The decoder thread already keeps only the newest frame, so there is never a
    #: backlog for a caller to drain past.
    latest_only: ClassVar[bool] = True

    def __post_init__(self) -> None:
        self.label = "phone screen over USB"
        self._lock = Lock()
        self._latest: np.ndarray | None = None
        self._process: Any | None = None
        self._thread: Thread | None = None
        self._stop = False
        self._error: str | None = None

    def open(self) -> None:
        adb = find_adb()
        if adb is None:
            raise SourceError(
                "adb was not found. Install Android platform-tools, or set LMPC_ADB."
            )
        authorised = [record for record in adb_devices() if record["state"] == "device"]
        if not authorised:
            raise SourceError(
                "no authorised phone on USB. Enable Developer options > USB debugging, "
                "then accept the 'Allow USB debugging' prompt on the phone screen."
            )
        if not self.serial:
            self.serial = authorised[0]["serial"]
        try:  # fail here with a clear reason rather than inside the reader thread
            import av  # noqa: F401
        except Exception as exc:
            raise SourceError(f"PyAV is required to decode the phone screen: {exc}") from exc

        self._stop = False
        self._thread = Thread(
            target=self._reader, args=(adb,), name="adb-screenrecord", daemon=True,
        )
        self._thread.start()
        # The first keyframe can take a few seconds: adb has to start, screenrecord
        # has to allocate an encoder, and H.264 needs an IDR before anything decodes.
        deadline = time.monotonic() + max(OPEN_TIMEOUT_S, 12.0)
        while time.monotonic() < deadline:
            with self._lock:
                frame, error = self._latest, self._error
            if frame is not None:
                self.label = f"phone screen {self.serial} at {frame.shape[1]}x{frame.shape[0]}"
                logger.info("phone screen opened: %s", self.label)
                return
            if error:
                self.close()
                raise SourceError(error)
            time.sleep(0.1)
        self.close()
        raise SourceError("screenrecord started but decoded no frame - unlock the phone and retry.")

    def _reader(self, adb: str) -> None:
        """Keep a screenrecord child alive and decode its H.264 into frames."""

        import av

        while not self._stop:
            command = screenrecord_command(
                adb, self.serial, self.size, self.bit_rate_mbps, self.time_limit_s,
            )
            try:
                process = subprocess.Popen(
                    command, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                    bufsize=0, creationflags=_NO_WINDOW,
                )
            except OSError as exc:
                self._fail(f"could not start screenrecord: {exc}")
                return
            self._process = process
            # A bare H.264 elementary stream has no container, so the frames are
            # parsed straight out of the byte stream rather than via av.open().
            codec = av.CodecContext.create("h264", "r")
            try:
                self._decode_stream(process, codec)
            except Exception as exc:
                if not self._stop:
                    self._fail(f"phone screen stream failed: {exc}")
                return
            finally:
                self._terminate(process)
            if self._stop:
                return
            self.restarts += 1
            logger.info("screenrecord hit its time limit; restarted (%d)", self.restarts)

    def _decode_stream(self, process: Any, codec: Any) -> None:
        """Feed stdout into the decoder, publishing each decoded frame."""

        stdout = process.stdout
        while not self._stop:
            chunk = stdout.read(_READ_CHUNK)
            if not chunk:
                return  # child exited: the time limit, or the cable was pulled
            for packet in codec.parse(chunk):
                for frame in codec.decode(packet):
                    self._publish(frame.to_ndarray(format="bgr24"))

    def _publish(self, image: np.ndarray) -> None:
        with self._lock:
            self._latest = image

    def _fail(self, message: str) -> None:
        logger.warning("%s", message)
        with self._lock:
            self._error = message

    def read(self) -> np.ndarray | None:
        """The newest decoded frame, once. ``None`` means nothing new yet.

        Pop rather than peek: the caller measures its own frame rate off this, and
        re-serving one decoded frame would inflate that number.
        """

        with self._lock:
            if self._error and self._latest is None:
                raise SourceError(self._error)
            frame, self._latest = self._latest, None
        return frame

    def close(self) -> None:
        self._stop = True
        process, self._process = self._process, None
        if process is not None:
            # Killing the child closes the pipe, which is what unblocks the reader
            # thread out of stdout.read().
            self._terminate(process)
        thread, self._thread = self._thread, None
        if thread is not None and thread.is_alive():
            thread.join(timeout=3.0)
        with self._lock:
            self._latest = None

    @staticmethod
    def _terminate(process: Any) -> None:
        if process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=3.0)
            except subprocess.TimeoutExpired:
                process.kill()
        for stream in (process.stdout, process.stderr):
            try:
                if stream is not None:
                    stream.close()
            except OSError:
                pass
