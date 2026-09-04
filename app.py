from __future__ import annotations

from datetime import datetime, timezone
from io import BytesIO
import json
import os
from pathlib import Path
import shutil
import tempfile
from typing import Any
from uuid import uuid4

import pandas as pd
from PIL import Image
import streamlit as st

import db
import debug_view
import pipeline
import local_camera
import session_cookie
import webrtc_live
from reporting import (
    build_report_payload,
    generate_csv_report,
    generate_docx_report,
    generate_json_report,
    generate_pdf_report,
    report_filename,
)
from rule_engine import validate_compliance


@st.cache_resource(show_spinner=False)
def _warm_pipeline(backend_name: str) -> dict[str, Any]:
    """Load the detector and the chosen OCR engine once per server process.

    Without this the first scan pays the model + engine initialisation (seconds,
    ~15s for EasyOCR) *inside its own spinner*, which reads as the app hanging.
    ``st.cache_resource`` keeps the loaded objects across reruns and sessions and
    is keyed on ``backend_name``, so flipping the engine toggle warms the new
    engine exactly once.
    """

    return pipeline.warmup(backend_name)


def _render_engine_controls() -> None:
    """Sidebar OCR-engine picker, warmed on selection.

    RapidOCR (PP-OCR via ONNX Runtime) stays the default: its confidence is
    calibrated, which the review-routing depends on. EasyOCR runs on the GPU here
    and is ~5x faster per crop - the switch to flip for a queue of panels or a
    live demo, at the cost of uncalibrated confidence. See
    ``docs/ocr_improvement_plan.md``.
    """

    from ocr_backends import backend_names

    options = backend_names()
    if not options:
        return
    labels = {
        "rapidocr": "RapidOCR - CPU, calibrated confidence (default)",
        "easyocr": "EasyOCR - GPU, ~5x faster, uncalibrated",
    }
    if "ocr_backend_choice" not in st.session_state:
        st.session_state.ocr_backend_choice = "rapidocr" if "rapidocr" in options else options[0]

    st.markdown("**OCR engine**")
    st.selectbox(
        "OCR engine",
        options=options,
        format_func=lambda name: labels.get(name, name),
        key="ocr_backend_choice",
        label_visibility="collapsed",
        help="RapidOCR is the default. Switch to EasyOCR to use the GPU when a "
             "queue of panels or a live demo makes latency matter.",
    )
    info = _warm_pipeline(st.session_state.ocr_backend_choice)
    ocr_info = info.get("ocr") if isinstance(info, dict) else None
    if isinstance(ocr_info, dict):
        providers = ocr_info.get("providers") or []
        device = ocr_info.get("device") or ("cuda" if "CUDAExecutionProvider" in providers else "cpu")
        st.caption(f"Engine ready: {ocr_info.get('name')} ({device})")
    elif isinstance(info, dict) and info.get("ocr_error"):
        st.caption(f"OCR engine failed to load: {info['ocr_error']}")


def _initialize_session_state() -> None:
    defaults = {
        "logged_in": False,
        "user_id": None,
        "username": None,
        "role": None,
        "current_scan": None,
        "login_error": None,
        "inspector_evidence_notes": "",
        "session_token": None,
        "session_restored": False,
        "session_cookie_warning": None,
    }
    for key, value in defaults.items():
        if key not in st.session_state:
            st.session_state[key] = value
    if not st.session_state.session_restored:
        st.session_state.session_restored = True
        token = session_cookie.get_token()
        if token:
            try:
                user = db.get_session(token)
            except Exception:
                user = None
            if user is not None:
                st.session_state.logged_in = True
                st.session_state.user_id = user["user_id"]
                st.session_state.username = user["username"]
                st.session_state.role = user["role"]
                st.session_state.session_token = token
            else:
                session_cookie.clear_token()


def _set_authenticated_user(user: dict[str, Any], token: str) -> None:
    st.session_state.logged_in = True
    st.session_state.user_id = user["user_id"]
    st.session_state.username = user["username"]
    st.session_state.role = user["role"]
    st.session_state.session_token = token
    if not session_cookie.set_token(token):
        st.session_state.session_cookie_warning = (
            "Browser-cookie support is unavailable. This login will last only "
            "until the Streamlit session ends; install requirements.txt to keep "
            "the login across refreshes."
        )


def _apply_style() -> None:
    st.markdown(
        """
        <style>
        .main .block-container {max-width: 1450px; padding-top: 1.5rem;}
        [data-testid="stMetric"] {background: #f5f8fc; border: 1px solid #dce6f2; padding: 0.8rem; border-radius: 0.7rem;}
        .report-card {background: linear-gradient(135deg,#17365d,#2b6ca3); color: white; border-radius: 0.8rem; padding: 1.2rem 1.4rem; margin-bottom: 1rem;}
        .small-muted {color: #617083; font-size: 0.88rem;}
        </style>
        """,
        unsafe_allow_html=True,
    )


def _render_login() -> None:
    left, right = st.columns([1.15, 0.85], gap="large")
    with left:
        st.markdown(
            '<div class="report-card"><h1>LMPC Rule 6 Scanner</h1><p>Evidence-backed packaging inspection for SIH26034.</p></div>',
            unsafe_allow_html=True,
        )
        st.subheader("Inspect declarations. Preserve evidence. Review decisions.")
        st.write(
            "Upload product panels, extract mandatory declarations, validate them with deterministic rules, and route every finding through an auditable review workflow."
        )
        st.info(
            "This is a hackathon proof-of-concept. Automated results support an inspector and verifier; they do not replace a statutory determination."
        )
    with right:
        st.subheader("Secure sign in")
        with st.form("login_form", clear_on_submit=False):
            username = st.text_input("Username", autocomplete="username")
            password = st.text_input("Password", type="password", autocomplete="current-password")
            submitted = st.form_submit_button("Sign in", type="primary", use_container_width=True)
        if submitted:
            database_failed = False
            try:
                user = db.authenticate_user(username, password)
            except Exception as error:
                user = None
                database_failed = True
                st.error(f"Database unavailable: {error}")
            if user is None:
                if not database_failed and not st.session_state.get("login_error"):
                    st.error("Invalid credentials or inactive account.")
            else:
                try:
                    token = db.create_session(user["user_id"])
                except Exception as error:
                    st.error(f"Could not create a secure session: {error}")
                else:
                    _set_authenticated_user(user, token)
                    st.session_state.login_error = None
                    st.rerun()
        with st.expander("Demo accounts"):
            st.code("inspector / pass123\nverifier / pass123\nadmin / pass123")


def _logout() -> None:
    _remove_temporary_image(st.session_state.current_scan)
    token = st.session_state.get("session_token")
    if token:
        try:
            db.revoke_session(token)
        except Exception:
            pass
    session_cookie.clear_token()
    st.session_state.logged_in = False
    st.session_state.user_id = None
    st.session_state.username = None
    st.session_state.role = None
    st.session_state.session_token = None
    st.session_state.current_scan = None
    st.rerun()


def _safe_suffix(suffix: str) -> str:
    normalized = str(suffix).lower()
    return normalized if normalized in {".jpg", ".jpeg", ".png"} else ".jpg"


def _suffixes_for_count(suffixes: list[str] | str | None, count: int) -> list[str]:
    if suffixes is None:
        return [".jpg"] * count
    if isinstance(suffixes, str):
        return [_safe_suffix(suffixes)] * count
    if len(suffixes) != count:
        raise ValueError("the number of image suffixes must match the number of images")
    return [_safe_suffix(suffix) for suffix in suffixes]


def _save_temporary_image(
    image_bytes_list: list[bytes], suffixes: list[str] | str | None = None
) -> list[Path]:
    if isinstance(image_bytes_list, (bytes, bytearray, memoryview)):
        image_bytes_list = [bytes(image_bytes_list)]
    if not image_bytes_list:
        raise ValueError("at least one image is required")
    paths: list[Path] = []
    try:
        for index, (image_bytes, suffix) in enumerate(
            zip(image_bytes_list, _suffixes_for_count(suffixes, len(image_bytes_list)))
        ):
            if not isinstance(image_bytes, (bytes, bytearray, memoryview)):
                raise TypeError("each image must be bytes-like")
            descriptor, filename = tempfile.mkstemp(prefix=f"lmpc_{index}_", suffix=suffix)
            with os.fdopen(descriptor, "wb") as handle:
                handle.write(bytes(image_bytes))
            paths.append(Path(filename))
    except Exception:
        for path in paths:
            path.unlink(missing_ok=True)
        raise
    return paths


def _persist_image(
    temp_paths: list[Path], suffixes: list[str] | str | None = None
) -> list[Path]:
    if isinstance(temp_paths, Path):
        temp_paths = [temp_paths]
    if not temp_paths:
        raise ValueError("at least one temporary image path is required")
    upload_dir = Path(__file__).resolve().with_name("scan_uploads")
    upload_dir.mkdir(parents=True, exist_ok=True)
    sources = [Path(path) for path in temp_paths]
    if any(not source.is_file() for source in sources):
        raise FileNotFoundError("one or more temporary evidence images are unavailable")
    destinations: list[Path] = []
    for source, suffix in zip(sources, _suffixes_for_count(suffixes, len(sources))):
        destination = upload_dir / f"scan_{uuid4().hex}{suffix}"
        shutil.move(str(source), str(destination))
        destinations.append(destination)
    return destinations


def _remove_temporary_image(scan: dict[str, Any] | None) -> None:
    if not scan:
        return
    paths = scan.get("temporary_paths")
    if paths is None and scan.get("temporary_path"):
        paths = [scan["temporary_path"]]
    if isinstance(paths, (str, Path)):
        paths = [paths]
    for path in paths or []:
        try:
            Path(path).unlink(missing_ok=True)
        except OSError:
            continue


def _result_reason(rule_name: str, details: dict[str, Any]) -> str:
    if details.get("reason"):
        return str(details["reason"])
    if details.get("is_compliant", False):
        return "All configured checks passed for this declaration."
    return f"{rule_name} is missing, unreadable, or failed a configured compliance check."


def _result_rows(results: dict | list) -> list[dict[str, Any]]:
    if isinstance(results, dict):
        rows = []
        for rule_name, details in results.items():
            if not isinstance(details, dict):
                continue
            parsed_values = {
                key: value
                for key, value in details.items()
                if key not in {"extracted_text", "is_compliant", "penalty_amount", "reason"}
            }
            rows.append(
                {
                    "Rule class": rule_name,
                    "Status": "PASS" if details.get("is_compliant", False) else "FAIL",
                    "Extracted text": details.get("extracted_text") or "",
                    "Parsed values": json.dumps(parsed_values, ensure_ascii=False, default=str),
                    "Reason": _result_reason(rule_name, details),
                    "Penalty": int(details.get("penalty_amount", 0) or 0),
                }
            )
        return rows
    rows = []
    for result in results:
        if not isinstance(result, dict):
            continue
        rule_name = str(result.get("rule_class", ""))
        parsed_values = result.get("parsed_data", {})
        rows.append(
            {
                "Rule class": rule_name,
                "Status": "PASS" if result.get("is_compliant", False) else "FAIL",
                "Extracted text": result.get("extracted_text", ""),
                "Parsed values": json.dumps(parsed_values, ensure_ascii=False, default=str),
                "Reason": _result_reason(rule_name, result),
                "Penalty": int(result.get("penalty_amount", 0) or 0),
            }
        )
    return rows


def _database_results(results: dict | list) -> list[dict[str, Any]]:
    if isinstance(results, dict):
        normalized = []
        for rule_name, details in results.items():
            if not isinstance(details, dict):
                continue
            parsed_data = {
                key: value
                for key, value in details.items()
                if key not in {"extracted_text", "is_compliant", "penalty_amount", "reason"}
            }
            normalized.append(
                {
                    "rule_class": rule_name,
                    "extracted_text": str(details.get("extracted_text") or ""),
                    "is_compliant": bool(details.get("is_compliant", False)),
                    "penalty_amount": int(details.get("penalty_amount", 0) or 0),
                    "reason": _result_reason(rule_name, details),
                    "parsed_data": parsed_data,
                }
            )
        return normalized
    return [
        {
            "rule_class": str(result.get("rule_class", "")),
            "extracted_text": str(result.get("extracted_text") or ""),
            "is_compliant": bool(result.get("is_compliant", False)),
            "penalty_amount": int(result.get("penalty_amount", 0) or 0),
            "reason": _result_reason(str(result.get("rule_class", "")), result),
            "parsed_data": result.get("parsed_data", {}),
        }
        for result in results
        if isinstance(result, dict) and result.get("rule_class")
    ]


def _scan_summary(results: dict | list) -> tuple[int, int, int]:
    rows = _result_rows(results)
    passed = sum(row["Status"] == "PASS" for row in rows)
    failed = len(rows) - passed
    penalty = sum(int(row["Penalty"]) for row in rows if row["Status"] == "FAIL")
    return passed, failed, penalty


def _render_scan_results(results: dict | list) -> None:
    rows = _result_rows(results)
    if not rows:
        st.warning("No rule results are available for this scan.")
        return
    st.dataframe(pd.DataFrame(rows), use_container_width=True, hide_index=True)
    for index, row in enumerate(rows):
        label = f"{'✅' if row['Status'] == 'PASS' else '⚠️'} {row['Rule class']} — {row['Status']}"
        with st.expander(label, expanded=row["Status"] == "FAIL"):
            st.write(f"**Extracted text:** {row['Extracted text'] or 'No text detected'}")
            st.write(f"**Reason:** {row['Reason']}")
            if row["Parsed values"] not in {"{}", "null", ""}:
                try:
                    st.json(json.loads(row["Parsed values"]))
                except json.JSONDecodeError:
                    st.code(row["Parsed values"])
            if row["Status"] == "FAIL":
                st.error(f"Estimated first-offense penalty: INR {row['Penalty']:,}")
            else:
                st.success("Declaration passed the configured checks.")


def _image_paths_for_scan(scan: dict[str, Any]) -> list[str]:
    paths = scan.get("image_paths")
    if paths is None:
        paths = scan.get("image_path")
    if paths is None:
        paths = scan.get("temporary_paths", [])
    if isinstance(paths, (str, Path)):
        paths = [paths]
    return [str(path) for path in paths or []]


def _render_evidence(scan: dict[str, Any], include_hashes: bool = True) -> None:
    paths = _image_paths_for_scan(scan)
    if not paths:
        st.info("No evidence images are attached.")
        return
    columns = st.columns(min(3, len(paths)))
    hashes = scan.get("evidence_hashes", [])
    for index, path_value in enumerate(paths):
        path = Path(path_value)
        with columns[index % len(columns)]:
            if path.is_file():
                st.image(str(path), caption=f"Evidence {index + 1}: {path.name}", use_container_width=True)
            else:
                st.warning(f"Evidence image unavailable: {path}")
            if include_hashes and isinstance(hashes, list) and index < len(hashes) and hashes[index]:
                st.caption(f"SHA-256: {hashes[index]}")
    notes = str(scan.get("evidence_notes", "") or "").strip()
    if notes:
        st.info(f"Inspector evidence notes: {notes}")


def _render_detection_preview(scan: dict[str, Any]) -> None:
    detections_by_image = scan.get("detections_by_image") or []
    image_bytes_list = scan.get("image_bytes_list") or []
    image_names = scan.get("image_names") or []
    if not detections_by_image:
        st.info("No detections recorded for this scan.")
        return
    st.caption(
        "Box color = YOLO confidence (green high, orange medium, red low). "
        "Gray = detected but not mapped to a report field yet (e.g. dietary_symbol today). "
        "Label shows YOLO confidence and OCR confidence for that crop."
    )
    for index, (image_bytes, detections) in enumerate(zip(image_bytes_list, detections_by_image)):
        name = image_names[index] if index < len(image_names) else f"Panel {index + 1}"
        annotated = debug_view.draw_detections(image_bytes, detections)
        st.image(annotated, caption=f"{name} - {len(detections)} detection(s)", use_container_width=True)


def _render_report_downloads(scan: dict[str, Any], key_prefix: str, generated_by: str) -> None:
    payload = build_report_payload(scan, generated_by)
    st.caption("Reports contain the extracted fields, parser reasons, workflow notes, and attached evidence paths/images where supported.")
    columns = st.columns(4)
    with columns[0]:
        st.download_button(
            "Download PDF",
            data=generate_pdf_report(scan, generated_by),
            file_name=report_filename(scan, "pdf"),
            mime="application/pdf",
            key=f"{key_prefix}_pdf",
            use_container_width=True,
        )
    with columns[1]:
        st.download_button(
            "Download DOCX",
            data=generate_docx_report(scan, generated_by),
            file_name=report_filename(scan, "docx"),
            mime="application/vnd.openxmlformats-officedocument.wordprocessingml.document",
            key=f"{key_prefix}_docx",
            use_container_width=True,
        )
    with columns[2]:
        st.download_button(
            "Download CSV",
            data=generate_csv_report(scan, generated_by),
            file_name=report_filename(scan, "csv"),
            mime="text/csv",
            key=f"{key_prefix}_csv",
            use_container_width=True,
        )
    with columns[3]:
        st.download_button(
            "Download JSON",
            data=json.dumps(payload, ensure_ascii=False, indent=2, default=str).encode("utf-8"),
            file_name=report_filename(scan, "json"),
            mime="application/json",
            key=f"{key_prefix}_json",
            use_container_width=True,
        )


def _run_analysis(
    image_bytes_list: list[bytes],
    image_names: list[str],
    source: str = "upload",
    capture_metadata: list[dict[str, Any]] | None = None,
) -> bool:
    """Detect, read, validate and store one scan. Shared by upload and live capture.

    Both entry points must produce byte-identical scans, so the whole chain lives
    here rather than inside either UI branch.
    """

    _remove_temporary_image(st.session_state.current_scan)
    suffixes = [Path(name).suffix for name in image_names]
    temporary_paths: list[Path] = []
    try:
        temporary_paths = _save_temporary_image(image_bytes_list, suffixes)
        with st.spinner("Running YOLO detection, OCR across prepared crop variants, and rule validation..."):
            scan = pipeline.analyze_images(
                image_bytes_list, backend_name=st.session_state.get("ocr_backend_choice")
            )
            results = validate_compliance(scan.extracted, scan.dietary_status)
    except Exception as error:
        _remove_temporary_image({"temporary_paths": [str(path) for path in temporary_paths]})
        st.error(f"Analysis failed: {error}")
        return False
    st.session_state.current_scan = {
        "image_bytes_list": image_bytes_list,
        "image_names": image_names,
        "temporary_paths": [str(path) for path in temporary_paths],
        "extracted_data": scan.extracted,
        "detections_by_image": scan.detections_by_image,
        "results": results,
        "evidence_notes": st.session_state.inspector_evidence_notes,
        "status": "READY",
        "timestamp": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "capture_source": source,
        "capture_metadata": capture_metadata or [],
        "dietary_status": scan.dietary_status,
        "ocr_backend": scan.ocr_backend,
        "detect_seconds": round(scan.detect_seconds, 2),
        "ocr_seconds": round(scan.ocr_seconds, 2),
    }
    return True


def _render_ocr_diagnostics(scan: dict[str, Any]) -> None:
    """Per-region OCR detail: which prepared variant won, and what the others read.

    This is the panel that makes an OCR regression diagnosable instead of a
    complaint - it shows every candidate reading the pipeline rejected.
    """

    rows: list[dict[str, Any]] = []
    for image_index, detections in enumerate(scan.get("detections_by_image") or []):
        for record in detections:
            rows.append(
                {
                    "Panel": image_index + 1,
                    "Class": record.get("canonical_name") or f"UNMAPPED:{record.get('raw_class_name')}",
                    "YOLO": round(float(record.get("yolo_confidence") or 0.0), 2),
                    "OCR text": record.get("ocr_text") or "",
                    "OCR conf": None if record.get("ocr_confidence") is None else round(record["ocr_confidence"], 2),
                    "Winning variant": record.get("ocr_variant") or "-",
                    "Parsed": "yes" if record.get("parsed") else "no",
                    "Engine": record.get("ocr_backend") or "-",
                    "Seconds": record.get("ocr_seconds"),
                }
            )
    if not rows:
        st.info("No regions were detected, so there is nothing for the OCR engine to read.")
        return
    st.dataframe(pd.DataFrame(rows), use_container_width=True, hide_index=True)
    candidates: list[dict[str, Any]] = []
    for image_index, detections in enumerate(scan.get("detections_by_image") or []):
        for record in detections:
            for candidate in record.get("candidates") or []:
                candidates.append(
                    {
                        "Panel": image_index + 1,
                        "Class": record.get("canonical_name") or "",
                        "Variant": candidate.get("variant"),
                        "Text": candidate.get("text"),
                        "Conf": None if candidate.get("confidence") is None else round(candidate["confidence"], 2),
                        "Parsed": "yes" if candidate.get("parsed") else "no",
                        "Score": candidate.get("score"),
                        "Seconds": candidate.get("seconds"),
                        "Error": candidate.get("error"),
                    }
                )
    if candidates:
        with st.expander("Every candidate reading (plain / enhanced / binary per region)"):
            st.caption(
                "The pipeline scores each prepared variant on recognizer confidence, "
                "whether the rule engine can parse it, and how much text it recovered."
            )
            st.dataframe(pd.DataFrame(candidates), use_container_width=True, hide_index=True)


def _render_inspector() -> None:
    st.header("Inspector workspace")
    st.write("Capture all relevant panels of one product so the engine can aggregate the declarations before validation.")
    mode = st.radio(
        "Capture source",
        ["Upload photographs", "Live camera (offline)", "Live camera (browser/WebRTC)"],
        horizontal=True,
        key="inspector_capture_mode",
        help="Both live modes show detection boxes in real time and run the full OCR "
             "chain only on the frames you keep. The offline mode opens the camera on "
             "this machine, so it needs no internet; the WebRTC mode uses the browser's "
             "camera and needs HTTPS or localhost.",
    )
    st.text_area(
        "Evidence notes",
        key="inspector_evidence_notes",
        placeholder="Record panel orientation, lighting, visible damage, or other inspection context.",
    )

    if mode.startswith("Live camera"):
        capture_source = "local_camera" if "offline" in mode else "webrtc"

        def _analyse_live(
            image_bytes_list: list[bytes],
            names: list[str],
            captures: list[dict[str, Any]],
        ) -> None:
            metadata = [
                {
                    "sharpness": capture.get("sharpness"),
                    "classes_found": capture.get("classes_found"),
                    "note": capture.get("note"),
                    "camera": capture.get("source"),
                }
                for capture in captures
            ]
            if _run_analysis(
                image_bytes_list, names, source=capture_source, capture_metadata=metadata
            ):
                st.rerun()

        if capture_source == "local_camera":
            st.caption(
                "Frames never leave this machine: the camera is opened by this "
                "application. Works with the internet unplugged."
            )
            local_camera.render_local_capture(_analyse_live, key="inspector_local")
        else:
            st.caption(
                "Camera frames stay on this machine. If you expose this app on a network, "
                "serve it over HTTPS behind this login - the WebRTC signalling path has no "
                "authentication of its own."
            )
            webrtc_live.render_live_capture(_analyse_live, key="inspector_live")
    else:
        uploaded_files = st.file_uploader(
            "Attach product-panel photographs",
            type=["jpg", "jpeg", "png"],
            accept_multiple_files=True,
            help="Add front, back, side, top, or bottom panels. These files become evidence attached to the scan.",
            key="inspector_upload",
        )
        if uploaded_files:
            image_bytes_list = [uploaded_file.getvalue() for uploaded_file in uploaded_files]
            image_names = [uploaded_file.name for uploaded_file in uploaded_files]
            st.caption(f"{len(uploaded_files)} evidence image(s) attached")
            thumbnail_columns = st.columns(min(4, len(uploaded_files)))
            for index, uploaded_file in enumerate(uploaded_files):
                with thumbnail_columns[index % len(thumbnail_columns)]:
                    st.image(
                        uploaded_file.getvalue(),
                        caption=uploaded_file.name,
                        use_container_width=True,
                    )
            if st.button("Analyze package", type="primary", use_container_width=True):
                if _run_analysis(image_bytes_list, image_names, source="upload"):
                    st.rerun()

    current_scan = st.session_state.current_scan
    if not current_scan:
        st.info("Attach one or more product panels, or capture from the live camera, to begin.")
        return

    passed, failed, penalty = _scan_summary(current_scan["results"])
    first, second, third, fourth = st.columns(4)
    first.metric("Fields passed", passed)
    second.metric("Fields requiring attention", failed)
    third.metric("Estimated penalty", f"INR {penalty:,}")
    fourth.metric("Evidence images", len(_image_paths_for_scan(current_scan)))
    if current_scan.get("ocr_backend"):
        st.caption(
            f"Source: {current_scan.get('capture_source', 'upload')} | "
            f"OCR engine: {current_scan['ocr_backend']} | "
            f"detection {current_scan.get('detect_seconds', 0)}s, OCR {current_scan.get('ocr_seconds', 0)}s | "
            f"dietary mark: {current_scan.get('dietary_status', 'NOT_DETECTED')}"
        )

    tabs = st.tabs(["Detection preview", "Compliance checklist", "Raw OCR", "OCR diagnostics", "Evidence and reports"])
    with tabs[0]:
        _render_detection_preview(current_scan)
    with tabs[1]:
        _render_scan_results(current_scan["results"])
    with tabs[2]:
        st.dataframe(
            pd.DataFrame(
                [
                    {"Region": key, "Aggregated OCR text": value or "No text detected"}
                    for key, value in current_scan["extracted_data"].items()
                ]
            ),
            use_container_width=True,
            hide_index=True,
        )
    with tabs[3]:
        _render_ocr_diagnostics(current_scan)
    with tabs[4]:
        _render_evidence(current_scan)
        _render_report_downloads(current_scan, "inspector_report", st.session_state.username)

    if current_scan.get("status") == "READY":
        st.divider()
        submit, reset = st.columns([3, 1])
        with submit:
            if st.button("Submit to verifier queue", type="primary", use_container_width=True):
                try:
                    persistent_paths = _persist_image(
                        [Path(path) for path in current_scan["temporary_paths"]],
                        [Path(name).suffix for name in current_scan["image_names"]],
                    )
                    scan_id = db.insert_scan(
                        st.session_state.user_id,
                        [str(path) for path in persistent_paths],
                        _database_results(current_scan["results"]),
                        evidence_notes=current_scan.get("evidence_notes", ""),
                    )
                except Exception as error:
                    st.error(f"Submission failed: {error}")
                else:
                    current_scan["scan_id"] = scan_id
                    current_scan["image_paths"] = [str(path) for path in persistent_paths]
                    current_scan["status"] = "PENDING"
                    current_scan.pop("temporary_paths", None)
                    st.session_state.current_scan = current_scan
                    st.success(f"Scan #{scan_id} submitted with evidence and routed to the verifier.")
                    st.rerun()
        with reset:
            if st.button("Reset scan", use_container_width=True):
                _remove_temporary_image(current_scan)
                st.session_state.current_scan = None
                st.session_state.inspector_evidence_notes = ""
                st.session_state["inspector_live_captures"] = []
                st.session_state["inspector_local_captures"] = []
                st.rerun()
    else:
        st.info(f"Scan #{current_scan.get('scan_id', '')} is {current_scan.get('status', 'SUBMITTED')}.")


def _render_verifier_scan(scan: dict[str, Any], show_actions: bool = True) -> None:
    title = (
        f"Scan #{scan['scan_id']} | {scan.get('product_name') or 'Product not identified'} | "
        f"Inspector: {scan['inspector_username']} | {scan['status']}"
    )
    with st.expander(title, expanded=show_actions):
        st.caption(f"Captured: {scan['timestamp']} | Evidence: {len(_image_paths_for_scan(scan))} image(s)")
        _render_evidence(scan)
        _render_scan_results(scan["results"])
        report_columns = st.columns([1, 1, 1, 1])
        with report_columns[0]:
            st.download_button(
                "PDF report",
                data=generate_pdf_report(scan, st.session_state.username),
                file_name=report_filename(scan, "pdf"),
                mime="application/pdf",
                key=f"verifier_pdf_{scan['scan_id']}",
                use_container_width=True,
            )
        with report_columns[1]:
            st.download_button(
                "Editable DOCX",
                data=generate_docx_report(scan, st.session_state.username),
                file_name=report_filename(scan, "docx"),
                mime="application/vnd.openxmlformats-officedocument.wordprocessingml.document",
                key=f"verifier_docx_{scan['scan_id']}",
                use_container_width=True,
            )
        with report_columns[2]:
            st.download_button(
                "CSV evidence log",
                data=generate_csv_report(scan, st.session_state.username),
                file_name=report_filename(scan, "csv"),
                mime="text/csv",
                key=f"verifier_csv_{scan['scan_id']}",
                use_container_width=True,
            )
        with report_columns[3]:
            st.download_button(
                "JSON data",
                data=generate_json_report(scan, st.session_state.username),
                file_name=report_filename(scan, "json"),
                mime="application/json",
                key=f"verifier_json_{scan['scan_id']}",
                use_container_width=True,
            )
        if show_actions:
            reason = st.text_area(
                "Verifier note",
                key=f"review_reason_{scan['scan_id']}",
                placeholder="Required when overriding; recommended for every decision.",
            )
            approve, override = st.columns(2)
            with approve:
                if st.button(
                    "Approve violation report",
                    key=f"approve_{scan['scan_id']}",
                    type="primary",
                    use_container_width=True,
                ):
                    try:
                        db.update_scan_status(
                            scan["scan_id"],
                            "APPROVED",
                            actor_id=st.session_state.user_id,
                            review_reason=reason,
                        )
                    except Exception as error:
                        st.error(f"Decision failed: {error}")
                    else:
                        st.success("Violation report approved and audit event recorded.")
                        st.rerun()
            with override:
                if st.button(
                    "Override / reject",
                    key=f"override_{scan['scan_id']}",
                    use_container_width=True,
                ):
                    if not reason.strip():
                        st.error("Enter a verifier note before overriding this scan.")
                    else:
                        try:
                            db.update_scan_status(
                                scan["scan_id"],
                                "OVERRIDDEN",
                                actor_id=st.session_state.user_id,
                                review_reason=reason,
                            )
                        except Exception as error:
                            st.error(f"Decision failed: {error}")
                        else:
                            st.success("Scan overridden and audit event recorded.")
                            st.rerun()


def _render_verifier() -> None:
    st.header("Verifier workspace")
    pending_scans = db.get_pending_scans(st.session_state.user_id)
    total_penalty = sum(
        int(result.get("penalty_amount", 0) or 0)
        for scan in pending_scans
        for result in scan["results"]
        if not result.get("is_compliant", False)
    )
    first, second, third = st.columns(3)
    first.metric("Pending scans", len(pending_scans))
    second.metric("Pending violations", sum(not result.get("is_compliant", False) for scan in pending_scans for result in scan["results"]))
    third.metric("Pending estimated penalty", f"INR {total_penalty:,}")
    st.caption("Review every attached panel and the parser explanation before approving or overriding a report.")
    if pending_scans:
        for scan in pending_scans:
            _render_verifier_scan(scan)
    else:
        st.success("The verifier queue is clear.")

    st.divider()
    st.subheader("Reviewed history")
    history_query = st.text_input("Search by scan ID, inspector, product, or evidence note", key="verifier_history_query")
    history_status = st.selectbox("History status", ["ALL", "APPROVED", "OVERRIDDEN"], key="verifier_history_status")
    history = db.get_scan_history(
        st.session_state.user_id,
        query=history_query,
        status=history_status,
        limit=100,
    )
    for scan in history:
        if scan["status"] != "PENDING":
            _render_verifier_scan(scan, show_actions=False)
    if not any(scan["status"] != "PENDING" for scan in history):
        st.info("No reviewed scans match the current filters.")


def _render_admin_history() -> None:
    query = st.text_input("Search scan ID, inspector, product, or evidence note", key="admin_scan_query")
    status = st.selectbox("Status", ["ALL", "PENDING", "APPROVED", "OVERRIDDEN"], key="admin_scan_status")
    history = db.get_scan_history(st.session_state.user_id, query=query, status=status, limit=250)
    st.caption(f"{len(history)} scan record(s) returned")
    table = []
    for scan in history:
        passed, failed, penalty = _scan_summary(scan["results"])
        table.append(
            {
                "Scan ID": scan["scan_id"],
                "Product": scan.get("product_name", "") or "Not detected",
                "Inspector": scan["inspector_username"],
                "Status": scan["status"],
                "Evidence": len(_image_paths_for_scan(scan)),
                "Passed": passed,
                "Failed": failed,
                "Penalty": penalty,
                "Captured": scan["timestamp"],
            }
        )
    if table:
        st.dataframe(pd.DataFrame(table), use_container_width=True, hide_index=True)
        for scan in history:
            _render_verifier_scan(scan, show_actions=False)
    else:
        st.info("No scans match the current filters.")


def _render_admin_users() -> None:
    st.subheader("User and role management")
    users = db.list_users(st.session_state.user_id)
    st.dataframe(pd.DataFrame(users), use_container_width=True, hide_index=True)
    with st.form("create_user_form", clear_on_submit=True):
        st.write("Create account")
        username = st.text_input("Username")
        password = st.text_input("Temporary password", type="password")
        role = st.selectbox("Role", ["Inspector", "Verifier", "Admin"])
        create = st.form_submit_button("Create user", type="primary")
    if create:
        try:
            user_id = db.create_user(st.session_state.user_id, username, password, role)
        except Exception as error:
            st.error(str(error))
        else:
            st.success(f"User #{user_id} created.")
            st.rerun()
    active_users = [user for user in users if user["active"] and user["user_id"] != st.session_state.user_id]
    inactive_users = [user for user in users if not user["active"]]
    if active_users:
        st.write("Deactivate account")
        selected = st.selectbox(
            "Active account",
            active_users,
            format_func=lambda user: f"{user['username']} ({user['role']})",
            key="deactivate_user_select",
        )
        if st.button("Deactivate selected account", key="deactivate_user_button"):
            try:
                db.set_user_active(st.session_state.user_id, selected["user_id"], False)
            except Exception as error:
                st.error(str(error))
            else:
                st.success("Account deactivated.")
                st.rerun()
    if inactive_users:
        st.write("Reactivate account")
        selected = st.selectbox(
            "Inactive account",
            inactive_users,
            format_func=lambda user: f"{user['username']} ({user['role']})",
            key="reactivate_user_select",
        )
        if st.button("Reactivate selected account", key="reactivate_user_button"):
            try:
                db.set_user_active(st.session_state.user_id, selected["user_id"], True)
            except Exception as error:
                st.error(str(error))
            else:
                st.success("Account reactivated.")
                st.rerun()


def _render_admin() -> None:
    st.header("Admin control center")
    analytics = db.get_analytics(st.session_state.user_id)
    first, second, third, fourth, fifth = st.columns(5)
    first.metric("Total scans", analytics["total_scans"])
    second.metric("Pending", analytics["status_counts"].get("PENDING", 0))
    third.metric("Violations", analytics["total_violations"])
    fourth.metric("Failure rate", f"{analytics['compliance_failure_rate']:.1f}%")
    fifth.metric("Potential penalty", f"INR {analytics['total_potential_penalty_revenue']:,}")
    st.caption(f"{analytics['active_users']} active user account(s) | SQLite audit store: {db.DB_PATH.name}")

    tabs = st.tabs(["Inspection history", "Analytics", "Users and roles", "Audit events"])
    with tabs[0]:
        _render_admin_history()
        st.subheader("Raw Scans table")
        st.dataframe(pd.DataFrame(db.get_all_scans(st.session_state.user_id)), use_container_width=True, hide_index=True)
        st.subheader("Raw ScanResults table")
        st.dataframe(pd.DataFrame(db.get_all_scan_results(st.session_state.user_id)), use_container_width=True, hide_index=True)
    with tabs[1]:
        status_frame = pd.DataFrame(
            [{"Status": status, "Scans": count} for status, count in analytics["status_counts"].items()]
        )
        rule_frame = pd.DataFrame(analytics["violations_by_rule"])
        left, right = st.columns(2)
        with left:
            st.subheader("Scan status")
            if not status_frame.empty:
                st.bar_chart(status_frame.set_index("Status"))
            else:
                st.info("No scan status data yet.")
        with right:
            st.subheader("Violations by rule")
            if not rule_frame.empty:
                st.bar_chart(rule_frame.set_index("rule_class")["violations"])
            else:
                st.info("No rule results yet.")
        st.subheader("Download aggregate audit data")
        all_scans = pd.DataFrame(db.get_all_scans(st.session_state.user_id))
        all_results = pd.DataFrame(db.get_all_scan_results(st.session_state.user_id))
        st.download_button(
            "Download scans CSV",
            data=all_scans.to_csv(index=False).encode("utf-8-sig"),
            file_name="lmpc_scans_audit.csv",
            mime="text/csv",
            key="admin_scans_csv",
        )
        st.download_button(
            "Download results CSV",
            data=all_results.to_csv(index=False).encode("utf-8-sig"),
            file_name="lmpc_scan_results_audit.csv",
            mime="text/csv",
            key="admin_results_csv",
        )
    with tabs[2]:
        _render_admin_users()
    with tabs[3]:
        events = db.get_audit_events(st.session_state.user_id)
        if events:
            st.dataframe(pd.DataFrame(events), use_container_width=True, hide_index=True)
        else:
            st.info("No audit events yet.")


def main() -> None:
    st.set_page_config(
        page_title="LMPC Rule 6 Compliance Scanner",
        page_icon="⚖️",
        layout="wide",
        initial_sidebar_state="expanded",
    )
    if not db.ensure_database():
        st.error(f"Database unavailable ({db.database_description()})")
        st.code(db.DATABASE_INIT_ERROR or "Unknown database initialization error")
        st.info(
            "For XAMPP, start MySQL and check LMPC_MYSQL_HOST/PORT/USER/PASSWORD, "
            "or unset LMPC_DB_BACKEND to use the local SQLite fallback."
        )
        return
    _initialize_session_state()
    _apply_style()
    if not st.session_state.logged_in:
        _render_login()
        return

    with st.sidebar:
        st.markdown("### LMPC Scanner")
        st.write(f"**User:** {st.session_state.username}")
        st.write(f"**Role:** {st.session_state.role}")
        st.caption(f"SIH26034 | Inspector decision support | {db.database_description()}")
        if st.session_state.get("session_cookie_warning"):
            st.warning(st.session_state.session_cookie_warning)
        if st.session_state.role == "Inspector":
            st.divider()
            _render_engine_controls()
            st.divider()
        if st.button("Sign out", use_container_width=True):
            _logout()

    st.title("LMPC Rule 6 Compliance Scanner")
    role = st.session_state.role
    if role == "Inspector":
        _render_inspector()
    elif role == "Verifier":
        _render_verifier()
    elif role == "Admin":
        _render_admin()
    else:
        st.error("Your account has no permitted scanner role.")


if __name__ == "__main__":
    main()
