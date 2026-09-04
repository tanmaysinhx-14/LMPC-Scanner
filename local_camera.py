"""Offline live capture: the Streamlit UI over :mod:`frame_sources`.

Why this exists alongside ``webrtc_live``
-----------------------------------------
``webrtc_live`` asks the *browser* for the camera. That needs a secure origin,
ICE negotiation and - if the demo is served over a tunnel - the internet. An
offline dry run of the evaluation demo failed on exactly that. This module keeps
the same live experience but moves the camera into the Streamlit **server**
process, where nothing but a USB cable is involved.

The detector overlay, focus/coverage meters, capture guidance and the gallery are
*not reimplemented here*: this module drives the very same
``webrtc_live.LiveScanProcessor`` and reuses its render helpers, so a frame
captured offline is graded byte-identically to a frame captured over WebRTC.
The only thing that changes is where the pixels come from.

Streamlit has no frame loop, so the preview is an ``st.fragment`` that reruns on a
short timer and, within each run, drains and paints the frames that arrived since
the last one. That keeps the buttons responsive while still looking like video.
"""

from __future__ import annotations

import logging
import time
from typing import Any, Callable

import frame_sources
import webrtc_live
from frame_sources import SourceError

logger = logging.getLogger(__name__)

#: Wall-clock budget for one fragment run. Long enough to skip a backlog and grade
#: one frame, short enough that a button press is not delayed.
PREVIEW_BUDGET_S = 0.35
#: Hard cap on frames read per run while skipping a backlog, so a fast source cannot
#: monopolise the run.
PREVIEW_MAX_FRAMES = 8
#: A read that takes longer than this waited for the camera rather than returning
#: something already buffered - which means there is no backlog left to skip. Any
#: real source needs more than 10 ms to produce a frame, so this is unambiguous.
LIVE_READ_S = 0.010
#: How often Streamlit reruns the preview fragment.
PREVIEW_INTERVAL = "0.05s"
#: Loopback port used for the adb tunnel. Any free port works; 8080 matches the
#: default of IP Webcam, which keeps the phone-side setup to "press start".
DEFAULT_LOCAL_PORT = 8080

SOURCE_DEVICE = "USB / virtual camera"
SOURCE_MJPEG = "Phone camera over USB (adb + MJPEG app)"
SOURCE_WIFI = "Phone camera over Wi-Fi (IP address)"
SOURCE_SCREEN = "Phone screen over USB (adb, no app)"

__all__ = ["render_local_capture", "source_summary"]


def _slot(key: str, name: str) -> str:
    return f"{key}_{name}"


def _get_source(st: Any, key: str) -> frame_sources.FrameSource | None:
    return st.session_state.get(_slot(key, "source"))


def _get_processor(st: Any, key: str) -> webrtc_live.LiveScanProcessor | None:
    return st.session_state.get(_slot(key, "processor"))


def source_summary(source: frame_sources.FrameSource | None) -> str:
    """One line describing the connected camera, for the capture record."""

    return source.describe() if source is not None else "not connected"


def _disconnect(st: Any, key: str) -> None:
    """Release the camera and any adb tunnel. Safe to call when not connected."""

    source = _get_source(st, key)
    if source is not None:
        try:
            source.close()
        except Exception as exc:  # a stuck driver must not wedge the UI
            logger.warning("closing %s failed: %s", source.describe(), exc)
    port = st.session_state.get(_slot(key, "forwarded_port"))
    if port:
        frame_sources.adb_remove_forward(int(port), st.session_state.get(_slot(key, "serial")))
        st.session_state[_slot(key, "forwarded_port")] = None
    st.session_state[_slot(key, "source")] = None
    st.session_state[_slot(key, "processor")] = None
    st.session_state[_slot(key, "error")] = None


def _connect(
    st: Any,
    key: str,
    source: frame_sources.FrameSource,
    settings: webrtc_live.LiveSettings,
    forwarded_port: int | None = None,
) -> bool:
    """Open one source and attach a processor. Returns whether it worked.

    ``forwarded_port`` is recorded *after* the source opens, and deliberately not
    by the caller beforehand: the first thing this function does is release the
    previously connected camera, and that teardown also removes any adb forward it
    finds in session state. A tunnel recorded before this point would therefore be
    torn down before ``open()`` could ever use it - which reads as "detects the
    phone but no video".
    """

    _disconnect(st, key)
    try:
        source.open()
    except SourceError as exc:
        st.session_state[_slot(key, "error")] = str(exc)
        return False
    if forwarded_port is not None:
        st.session_state[_slot(key, "forwarded_port")] = int(forwarded_port)
    st.session_state[_slot(key, "source")] = source
    st.session_state[_slot(key, "processor")] = webrtc_live.LiveScanProcessor(settings)
    st.session_state[_slot(key, "error")] = None
    return True


def _phone_first(record: dict) -> tuple[int, ...]:
    """Sort key putting a phone/virtual camera ahead of the machine's own webcam.

    A tiebreaker, not a ranking: it is applied within a group of equally-good cameras,
    so a webcam that delivered a picture still outranks a Camo that came back black.
    What it fixes is the default selection when *nothing* delivered - the laptop's
    shuttered webcam at index 0 versus a Camo at index 1 that a reopen would recover.
    Returning a tuple keeps the sort stable on index order for anything unrecognised.
    """

    return (0 if frame_sources.is_virtual_camera(str(record.get("name", ""))) else 1,)


def _device_controls(st: Any, key: str, settings: webrtc_live.LiveSettings) -> None:
    """Pick a camera by name. Covers webcams and Camo/DroidCam/OBS virtual cameras.

    By name, not by index: OpenCV can only open a camera by integer, and a picker
    offering "device 0 / device 1" gives the inspector no way to tell the built-in
    webcam from Camo. The selectbox then defaults to the first entry - the built-in
    webcam - so a perfectly working Camo reads as "the app did not detect it". Hence
    also the ordering: see :func:`_phone_first`.
    """

    st.caption(
        "Any camera Windows already exposes. This includes a virtual camera published "
        "by Camo, DroidCam or OBS, so the Camo workflow still works here - it just is "
        "no longer required."
    )
    scan, choose = st.columns([1, 2])
    with scan:
        if st.button("Scan for cameras", use_container_width=True, key=f"{key}_probe"):
            with st.spinner("Opening each camera Windows reports. A virtual camera can take a few seconds."):
                st.session_state[_slot(key, "devices")] = frame_sources.probe_devices()
    devices = st.session_state.get(_slot(key, "devices"))
    if devices is None:
        st.info("Press **Scan for cameras** to list what this machine can open.")
        return
    usable = [record for record in devices if record.get("ok", True)]
    # A camera that opened but handed back black is offered anyway, after the ones that
    # delivered. Measured against Camo, that state clears on a reopen about as often as
    # not, and the connect retries where the scan does not - so treating one black
    # attempt as final is what makes a working phone camera unselectable, which is the
    # "the app is not detecting Camo" report this route grew out of.
    retryable = [
        record for record in devices
        if not record.get("ok", True) and record.get("blank")
    ]
    # Within each group, a phone camera leads. Offering a black Camo is only half the
    # fix: with a shuttered webcam at index 0 the picker still *defaults* to the webcam,
    # and an inspector who presses Connect on the default sees the same "it did not
    # detect my phone" failure as before.
    usable.sort(key=_phone_first)
    retryable.sort(key=_phone_first)
    # A camera Windows lists but which will not deliver is reported rather than
    # hidden: "Camo is there but not streaming" is actionable, Camo silently absent
    # from the list is indistinguishable from a bug in this application.
    for record in devices:
        if not record.get("ok", True):
            st.warning(f"**{record['name']}** was found but {record['error']}")
    if not usable and not retryable:
        st.warning(
            "No camera delivered a picture. Check that no other app holds it, that any "
            "lens cover or privacy shutter is open, and that Windows camera privacy "
            f"settings allow desktop apps. The scan waits {frame_sources.PROBE_TIMEOUT_S:.0f}s "
            "per camera, so if a virtual camera's desktop app was still starting up, "
            "press **Scan for cameras** again."
        )
        return
    with choose:
        labels = {record["label"]: record for record in usable + retryable}
        chosen = st.selectbox("Camera", list(labels), key=f"{key}_device_pick")
    record = labels[chosen]
    if record.get("blank"):
        st.caption(
            "This one was black when scanned, which often clears on its own. Connecting "
            "retries it a few times - if it still fails, open the camera's own app and "
            "check the phone is connected."
        )
    if st.button("Connect", type="primary", use_container_width=True, key=f"{key}_device_connect"):
        # The name goes with the index, so a failure names the camera the inspector
        # actually picked rather than an index they never saw.
        source = frame_sources.DeviceSource(
            index=int(record["index"]), api=record["api"] or "auto", name=record["name"],
        )
        if _connect(st, key, source, settings):
            st.rerun()


def _phone_picker(st: Any, key: str) -> str | None:
    """List phones on USB and return the chosen serial, or ``None``.

    The unauthorised state is surfaced rather than hidden: an unaccepted USB
    debugging prompt is the single most common reason this path fails.
    """

    adb = frame_sources.find_adb()
    if adb is None:
        st.error(
            "adb was not found. Install Android platform-tools (a 10 MB zip, no SDK "
            "needed), put adb on PATH, or set the LMPC_ADB environment variable to "
            "adb.exe. This is a one-time setup and needs no internet afterwards."
        )
        return None
    st.caption(f"adb: `{adb}`")
    if st.button("Detect phones", use_container_width=True, key=f"{key}_adb_scan"):
        try:
            st.session_state[_slot(key, "phones")] = frame_sources.adb_devices()
        except SourceError as exc:
            st.session_state[_slot(key, "phones")] = []
            st.session_state[_slot(key, "error")] = str(exc)
    phones = st.session_state.get(_slot(key, "phones"))
    if phones is None:
        st.info("Plug the phone in with a USB cable and press **Detect phones**.")
        return None
    unauthorised = [record for record in phones if record["state"] == "unauthorized"]
    if unauthorised:
        st.warning(
            "Phone found but not authorised. Unlock it and accept the "
            "**Allow USB debugging** prompt, then press Detect phones again."
        )
    ready = [record for record in phones if record["state"] == "device"]
    if not ready:
        if not unauthorised:
            st.warning(
                "No phone on USB. Enable Developer options > USB debugging, and set the "
                "USB mode to File transfer rather than Charging only."
            )
        return None
    options = {
        f"{record['model'] or 'phone'} ({record['serial']})": record["serial"]
        for record in ready
    }
    chosen = st.selectbox("Phone", list(options), key=f"{key}_phone_pick")
    serial = options[chosen]
    st.session_state[_slot(key, "serial")] = serial
    return serial


def _mjpeg_controls(st: Any, key: str, settings: webrtc_live.LiveSettings) -> None:
    """The good phone path: MJPEG server on the phone, carried over USB by adb."""

    st.caption(
        "Full sensor resolution at low latency, entirely over the cable. Install a free "
        "MJPEG camera app on the phone (IP Webcam or DroidCam), press its start button, "
        "then connect here - adb forwards the port so nothing uses Wi-Fi."
    )
    serial = _phone_picker(st, key)
    if serial is None:
        return
    app, port_column = st.columns([2, 1])
    with app:
        preset = st.selectbox("Phone app", list(frame_sources.MJPEG_PRESETS), key=f"{key}_mjpeg_app")
    remote_port, path = frame_sources.MJPEG_PRESETS[preset]
    with port_column:
        remote_port = st.number_input(
            "Phone port", 1, 65535, int(remote_port), key=f"{key}_mjpeg_port",
            help="The port the app shows on the phone screen.",
        )
    if st.button("Connect over USB", type="primary", use_container_width=True, key=f"{key}_mjpeg_connect"):
        # Release the previous camera *before* building the tunnel, not after. The
        # teardown removes any forward recorded in session state, and this route
        # reuses one fixed local port - so tearing down afterwards could remove the
        # very tunnel this click just created, which reads as "detects the phone but
        # no video". _connect's own teardown is then a no-op for forwards.
        _disconnect(st, key)
        try:
            frame_sources.adb_forward(DEFAULT_LOCAL_PORT, int(remote_port), serial)
        except SourceError as exc:
            st.session_state[_slot(key, "error")] = str(exc)
            st.rerun()
            return
        # The preset's path, not adb_forward's default, decides the endpoint.
        url = frame_sources.mjpeg_url(DEFAULT_LOCAL_PORT, path)
        connected = _connect(
            st, key, frame_sources.UrlSource(url=url), settings,
            forwarded_port=DEFAULT_LOCAL_PORT,
        )
        if not connected:  # tunnel is up but the app is not serving; do not leak it
            frame_sources.adb_remove_forward(DEFAULT_LOCAL_PORT, serial)
            st.session_state[_slot(key, "forwarded_port")] = None
        st.rerun()


def _wifi_controls(st: Any, key: str, settings: webrtc_live.LiveSettings) -> None:
    """Connect straight to the address IP Webcam shows on the phone screen.

    Simpler than the adb route - no cable, no platform-tools - at the cost of
    depending on the Wi-Fi network staying up. Both machines only need to be on the
    same router; no internet is involved either way.
    """

    st.caption(
        "Type the address IP Webcam shows at the bottom of the phone screen. Phone and "
        "laptop must be on the same Wi-Fi. No internet needed - but if the network is "
        "unreliable, prefer the USB route above."
    )
    entered = st.text_input(
        "Phone address", value="", placeholder="http://192.168.1.2:8080",
        key=f"{key}_wifi_url",
        help="Paste it exactly as the phone shows it. The stream path is added for you.",
    )
    if st.button("Connect over Wi-Fi", type="primary", use_container_width=True, key=f"{key}_wifi_connect"):
        try:
            url = frame_sources.normalise_stream_url(entered)
        except SourceError as exc:
            st.session_state[_slot(key, "error")] = str(exc)
            st.rerun()
            return
        st.caption(f"Opening `{url}`")
        if _connect(st, key, frame_sources.UrlSource(url=url), settings):
            st.rerun()
        else:
            st.rerun()


def _screen_controls(st: Any, key: str, settings: webrtc_live.LiveSettings) -> None:
    """The always-works fallback: mirror the phone screen, no app to install."""

    st.caption(
        "Needs nothing on the phone but USB debugging. Open the phone's own camera app "
        "and this mirrors its screen. Slower (about a second behind) and it captures "
        "screen pixels rather than the sensor, so prefer the MJPEG route when possible."
    )
    serial = _phone_picker(st, key)
    if serial is None:
        return
    size = st.select_slider(
        "Mirror resolution", ["854x480", "1280x720", "1920x1080"], value="1280x720",
        key=f"{key}_screen_size",
        help="Higher is sharper for small print but adds latency.",
    )
    if st.button("Mirror phone screen", type="primary", use_container_width=True, key=f"{key}_screen_connect"):
        source = frame_sources.AdbScreenrecordSource(serial=serial, size=size)
        with st.spinner("Starting screenrecord and waiting for the first keyframe..."):
            connected = _connect(st, key, source, settings)
        if connected:
            st.rerun()


def _pump(
    source: frame_sources.FrameSource,
    processor: webrtc_live.LiveScanProcessor,
    budget_s: float = PREVIEW_BUDGET_S,
    max_frames: int = PREVIEW_MAX_FRAMES,
) -> tuple[Any | None, int]:
    """Skip any backlog, then grade only the newest frame. Returns the annotated one.

    Two rules, both of which cost the inspector latency if broken.

    *Skip the backlog.* A capture that queues hands back the **oldest** frame it
    holds, so a single read shows the past. This drains until a read blocks: a read
    that takes longer than :data:`LIVE_READ_S` waited for the camera rather than
    returning something already buffered, which means nothing is queued behind it.
    Sources that already serve the newest frame - anything with
    :attr:`~frame_sources.FrameSource.latest_only` set - return ``None`` on the
    second read and leave immediately.

    *Grade only the newest.* Detection costs about 90 ms a frame, so grading every
    frame that arrived is how the preview falls behind in the first place, and an
    already-stale frame is worth nothing to somebody aiming a camera. The return
    count is therefore 0 or 1: it says whether this run had anything to paint, not
    how many frames the camera produced.
    """

    deadline = time.monotonic() + budget_s
    frame = None
    read = 0
    while read < max_frames and time.monotonic() < deadline:
        started = time.monotonic()
        latest = source.read()
        if latest is None:
            break
        frame = latest
        read += 1
        if time.monotonic() - started > LIVE_READ_S:
            break
    if frame is None:
        return None, 0
    return processor.process(frame), 1


def _render_preview(st: Any, key: str, settings: webrtc_live.LiveSettings) -> None:
    """The live surface: a fragment that reruns on a timer and paints frames.

    Streamlit has no frame callback, so this is the substitute. It is a fragment so
    that only this part of the page reruns 20 times a second - a full-script rerun
    at that rate would fight every button on the page.
    """

    @st.fragment(run_every=PREVIEW_INTERVAL)
    def _preview() -> None:
        source = _get_source(st, key)
        processor = _get_processor(st, key)
        if source is None or processor is None:
            return
        processor.update_settings(settings)
        try:
            annotated, painted = _pump(source, processor)
        except SourceError as exc:
            # The cable was pulled or screenrecord died. Say so and stop the loop
            # rather than repainting a frozen frame forever.
            st.session_state[_slot(key, "error")] = str(exc)
            _disconnect(st, key)
            st.rerun()
            return
        if annotated is not None:
            st.session_state[_slot(key, "last_frame")] = annotated
        last = st.session_state.get(_slot(key, "last_frame"))
        if last is not None:
            st.image(last, channels="BGR", use_container_width=True)
        elif painted == 0:
            st.info("Camera is connected but has not sent a frame yet.")
        webrtc_live._render_meters(st, processor.status(), settings)
        # Only the thread-backed sources count drops, and a non-zero count is the
        # useful signal: it says the preview is showing the current frame rather than
        # working through a queue, which is exactly the failure this route used to have.
        dropped = getattr(source, "dropped", 0)
        if dropped:
            st.caption(
                f"{dropped} frame(s) skipped to stay live - the preview is the current "
                "frame, not a backlog."
            )

    _preview()


#: Order matters: this is the order worth trying on demo day, most robust first.
SOURCE_ORDER = (SOURCE_MJPEG, SOURCE_WIFI, SOURCE_DEVICE, SOURCE_SCREEN)

SOURCE_HELP = {
    SOURCE_DEVICE: "Webcam, USB camera, or a Camo/DroidCam/OBS virtual camera.",
    SOURCE_MJPEG: "Most reliable phone route. Needs the USB cable and adb.",
    SOURCE_WIFI: "Same phone app, over Wi-Fi. No cable, but needs the network up.",
    SOURCE_SCREEN: "Works with no app on the phone. Slower, mirrors the screen.",
}


def render_local_capture(
    on_capture: Callable[[list[bytes], list[str], list[dict[str, Any]]], None],
    key: str = "lmpc_local",
) -> None:
    """Render the offline live-capture panel.

    ``on_capture(image_bytes_list, names, capture_metadata)`` has the same shape the
    uploader and the WebRTC path produce, so the caller keeps one analysis path.
    """

    import streamlit as st

    st.caption(
        "The camera is opened by this application, not by the browser, so this path "
        "needs no internet, no HTTPS and no STUN server. Detection boxes and the "
        "focus bar are drawn into the preview; OCR and the rule engine run only on a "
        "captured still."
    )
    settings = webrtc_live._settings_controls(st, key)
    source = _get_source(st, key)

    if source is None:
        choice = st.radio(
            "Camera source", list(SOURCE_ORDER),
            key=f"{key}_source_kind",
            captions=[SOURCE_HELP[name] for name in SOURCE_ORDER],
        )
        error = st.session_state.get(_slot(key, "error"))
        if error:
            st.error(error)
        controls = {
            SOURCE_DEVICE: _device_controls,
            SOURCE_MJPEG: _mjpeg_controls,
            SOURCE_WIFI: _wifi_controls,
            SOURCE_SCREEN: _screen_controls,
        }
        controls[choice](st, key, settings)
    else:
        connected, disconnect = st.columns([3, 1])
        connected.success(f"Connected: {source.describe()}")
        with disconnect:
            if st.button("Disconnect", use_container_width=True, key=f"{key}_disconnect"):
                _disconnect(st, key)
                st.session_state[_slot(key, "last_frame")] = None
                st.rerun()
        _render_preview(st, key, settings)

        processor = _get_processor(st, key)
        if processor is not None:
            automatic = processor.drain_captures()
            if automatic:
                webrtc_live._capture_store(st, key).extend(automatic)
                st.rerun()
        if st.button(
            "Capture frame", type="primary", use_container_width=True,
            key=f"{key}_shutter", disabled=processor is None,
        ):
            capture = processor.snapshot() if processor is not None else None
            if capture is None:
                st.warning("No frame has arrived from the camera yet.")
            else:
                capture["source"] = source.describe()
                webrtc_live._capture_store(st, key).append(capture)
                st.rerun()

    st.divider()
    webrtc_live._render_gallery(st, key, on_capture)
