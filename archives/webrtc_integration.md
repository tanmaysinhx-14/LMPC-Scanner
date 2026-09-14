# WebRTC live capture — design and operating notes

**Owner:** Tanmay · **Last updated:** 30 August 2026 · **Module:** [`webrtc_live.py`](../webrtc_live.py)

The SIH26034 problem statement asks for live scanning, and the jury raised its
absence explicitly. This document explains what was built, why it is shaped this
way, and what has to be true before it is exposed on a network.

## 1. What it does

The Inspector screen now has a capture-source switch:

```text
Inspector
  ├── Upload photographs        (unchanged path)
  └── Live camera (WebRTC)      (new)
        ├── browser camera -> aiortc -> LiveScanProcessor.recv()
        ├── per frame:      focus score (Laplacian variance, centre 60%)
        ├── every 0.30 s:   YOLO detect_only() -> live boxes + panel count
        ├── in-frame HUD:   focus / panels / fps + the top guidance hint
        └── on capture:     sharpest recent frame -> the full OCR + rule chain
```

## 2. Why OCR does not run live

OCR costs 0.23–1.42 s **per crop** (see
[`ocr_improvement_plan.md`](ocr_improvement_plan.md) §6) and a pack has up to seven
panels. Detection alone costs 59 ms at `imgsz=640`, measured on this RTX 3050.

So the split is deliberate:

| Work | When | Cost |
|---|---|---|
| Focus score | every frame | < 1 ms |
| YOLO detection | throttled to 0.30 s | ~59 ms (≈16 fps ceiling, so there is headroom) |
| OCR + rules | only on capture | seconds |

Continuous live OCR would produce a slideshow, not a video, and would give the
inspector no reason to hold the camera still. Guided capture is both cheaper and
more accurate.

## 3. Guidance is the accuracy feature, not decoration

The measured ceiling on date and MRP reading is capture resolution: those crops
arrive 13–22 pixels tall and are already scaled at the 6x cap. The only fix is a
better photograph, so the live view tells the inspector what is wrong, worst
problem first (`guidance_for`):

1. `Detector unavailable: …` — nothing else matters, and it is reported rather than
   shown as an empty overlay.
2. `Very blurred` (< 60% of the floor) / `Slightly soft` (< floor) — focus is first
   because no amount of framing rescues a blurred frame.
3. `No statutory panel found` / `Only N of 7 panels visible … missing: …` — names the
   missing classes so the inspector knows which way to turn the pack.
4. `Camera is streaming a low resolution` — small print will not be readable at all.
5. `Good frame - capture now.`

## 4. The sharpness floor is calibrated, not invented

`SHARPNESS_FLOOR = 300.0`. Measured over 40 test-split photographs of real packs:

| Population | p10 | p50 | p90 |
|---|---:|---:|---:|
| Real captures | 351 | 1099 | 3852 |
| The same images at 1.6σ Gaussian blur | — | 19 | — |

300 therefore sits just below the worst *usable* real capture and an order of
magnitude above anything blurred. The slider exposes 50–1500 so the threshold can
be re-tuned for a different camera without editing code.

## 5. Threading

`recv()` runs on aiortc's worker thread; Streamlit reruns the script on the main
thread. Every field the UI reads is written under `_lock` and handed out as an
immutable copy (`status()` returns `replace(self._status)`), so the UI can never
observe a half-updated frame count, and capture counters are published at the moment
the shutter fires rather than on the next frame.

The HUD is drawn *into the video frame* rather than rendered as Streamlit widgets,
because the video is the only surface that updates without a script rerun — it is
the only guidance an inspector can act on while actually aiming the camera.

## 6. Capture behaviour

- **Manual capture** is never gated on quality. The inspector overrides the meter; a
  soft frame is still evidence.
- **Auto-capture** (off by default) fires only when focus ≥ floor *and* at least
  `min_classes` panels are visible, then respects a 4 s cooldown.
- Either way the frame handed onward is the **sharpest of the last ~10 frames**, not
  the newest one. This costs nothing and avoids shutter-press shake.
- Captures are JPEG-encoded at quality 95 — the same currency the upload path uses,
  so a live capture and an uploaded photograph are indistinguishable downstream and
  produce the same evidence record.

## 7. Security — read before exposing this

**WebRTC signalling has no authentication of its own.** `streamlit-webrtc` negotiates
the peer connection over the Streamlit session, so the only thing standing between a
camera stream and the network is the app's own login. That is acceptable on
`localhost`; it is not acceptable on a tunnel or a server. Before exposing it:

- **Terminate TLS.** Browsers refuse `getUserMedia` on a non-secure origin other than
  `localhost` anyway, so this is forced for the camera to work at all — but it also
  means the media and signalling must not be downgraded by a proxy.
- **Keep it behind the existing login**, and replace the seeded demo accounts
  (`inspector`/`verifier`/`admin`, all `pass123`) first. They are demonstration
  credentials only.
- **STUN is opt-in.** The public `stun:stun.l.google.com:19302` server is offered as an
  unchecked checkbox, because contacting a public STUN server discloses the client's IP
  address to a third party. On a LAN it is unnecessary; host-candidate-only
  negotiation works. Enable it only when the browser and the app are on different
  networks, and prefer a self-hosted STUN/TURN service for field deployment.
- **Frames are evidence.** Captured JPEGs follow the existing upload path, so they are
  hashed with SHA-256 at submission and stored with the scan record. Nothing is
  written to disk before capture; the rolling buffer of ~10 frames lives in memory
  only.
- The earlier tunnel setup (Cloudflared, commit `aa950626`) publishes the app to the
  public internet. If it is used for a demo, do it with the demo passwords changed and
  the tunnel shut down afterwards.

## 8. Verification status

Verified headlessly (no browser) with a stubbed detector and synthetic frames:

- Detector loads; `detect_ms = 59` at `imgsz=640`; `focus = 1049` on a real pack photo.
- Auto-capture fires once when the gates are met and respects the cooldown.
- `snapshot()` returned a 234 KB JPEG; the rendered overlay was inspected visually
  (HUD bars, boxes, hint line and framing guide all legible).
- 32 automated tests in [`tests/test_webrtc_live.py`](../tests/test_webrtc_live.py)
  cover the status snapshot, guidance ordering, focus ROI, JPEG round-trip, detection
  throttling, detector-crash and missing-checkpoint paths, and every capture gate.

**Not verified in a browser.** The camera path itself needs one manual run:

```bash
python -m streamlit run app.py
```

then Inspector → *Live camera (WebRTC)* → **START**, and grant camera permission. What
to check: boxes track the pack, the focus number rises when the camera focuses,
capture produces a scan with the same tabs an upload produces.

Expect few boxes and low confidence until the detector is retrained — the checkpoint
in `runs/detect/train/` was trained on the corrupted labels (recall 0.35). On a real
pack the overlay currently shows ~2 boxes at 0.21–0.22 confidence. That is a detector
problem, not a live-view problem; see [`dataset_label_repair.md`](dataset_label_repair.md).

## 9. Dependencies

```text
streamlit-webrtc>=0.47   # 0.77.0 installed
aiortc>=1.9              # 1.15.0 installed
av>=12.0                 # 17.1.0 installed
```

`webrtc_available()` returns `(True, None)` in this environment. `av` is imported
lazily inside `recv()` so the module stays importable — and unit-testable — on a
machine without the WebRTC stack.

