"""Tests for the configurable rule layer and the config-aware report payload.

The load-bearing property is separation: a profile may narrow scope, restate a
penalty or relabel severity, but it must never flip the verdict ``rule_engine``
reached on the text read off the pack.
"""

from __future__ import annotations

import json

import pytest

import db
import reporting
import rule_config


def _engine_results() -> dict[str, dict]:
    """A minimal stand-in for validate_compliance output: one pass, one fail."""

    return {
        "product_name": {
            "extracted_text": "Toor Dal",
            "is_compliant": True,
            "penalty_amount": 0,
            "reason": "All configured checks passed for this declaration.",
        },
        "net_quantity": {
            "extracted_text": "500 gms",
            "is_compliant": False,
            "penalty_amount": 25_000,
            "reason": "The declaration uses a non-permitted unit.",
        },
        "dietary_mark": {
            "extracted_text": "VEG",
            "dietary_status": "VEG",
            "is_compliant": True,
            "penalty_amount": 0,
            "reason": "Green vegetarian mark detected on the pack.",
        },
    }


@pytest.fixture
def isolated_database(monkeypatch, tmp_path):
    monkeypatch.setenv("LMPC_DB_BACKEND", "sqlite")
    monkeypatch.setattr(db, "DB_PATH", tmp_path / "scanner.db")
    db.DATABASE_INIT_ERROR = None
    assert db.ensure_database()
    return tmp_path / "scanner.db"


def test_default_profile_covers_every_engine_class():
    config = rule_config.default_config()
    assert set(config["rules"]) == set(rule_config.RULE_CLASSES)
    assert all(setting["enabled"] for setting in config["rules"].values())


def test_config_cannot_flip_the_engine_verdict():
    config = rule_config.default_config()
    config["rules"]["net_quantity"]["penalty"] = 0
    overlaid = rule_config.apply_rule_config(_engine_results(), config)
    assert overlaid["net_quantity"]["is_compliant"] is False
    assert overlaid["net_quantity"]["penalty_amount"] == 0
    assert overlaid["product_name"]["is_compliant"] is True


def test_disabled_class_is_reported_as_out_of_scope_not_as_a_pass():
    config = rule_config.default_config()
    config["rules"]["net_quantity"]["enabled"] = False
    overlaid = rule_config.apply_rule_config(_engine_results(), config)
    row = overlaid["net_quantity"]
    assert row["in_scope"] is False
    assert row["is_compliant"] is False  # the verdict survives; only scope changed
    assert row["penalty_amount"] == 0
    assert "Out of scope" in row["reason"]

    summary = rule_config.summarize(overlaid)
    assert summary["skipped"] == 1
    assert summary["failed"] == 0
    assert summary["penalty"] == 0


def test_configured_penalty_reaches_the_summary():
    config = rule_config.default_config()
    config["rules"]["net_quantity"]["penalty"] = 5_000
    config["rules"]["net_quantity"]["severity"] = "Minor"
    overlaid = rule_config.apply_rule_config(_engine_results(), config)
    summary = rule_config.summarize(overlaid)
    assert summary["penalty"] == 5_000
    assert summary["failed"] == 1
    assert summary["highest_severity"] == "Minor"


def test_penalties_are_clamped_to_the_statutory_ceiling():
    config = rule_config.normalize_config(
        {"rules": {"net_quantity": {"penalty": 10_000_000, "enabled": True}}}
    )
    assert config["rules"]["net_quantity"]["penalty"] == rule_config.STATUTORY_PENALTY_CEILING
    negative = rule_config.normalize_config({"rules": {"net_quantity": {"penalty": -5}}})
    assert negative["rules"]["net_quantity"]["penalty"] == 0


def test_advisory_class_never_carries_a_penalty():
    config = rule_config.normalize_config(
        {"rules": {"dietary_mark": {"penalty": 25_000, "enabled": True}}}
    )
    assert config["rules"]["dietary_mark"]["penalty"] == 0


def test_unknown_and_missing_classes_are_handled_safely():
    config = rule_config.normalize_config(
        {"profile_name": "Edge profile", "rules": {"not_a_real_rule": {"enabled": True}}}
    )
    assert "not_a_real_rule" not in config["rules"]
    assert set(config["rules"]) == set(rule_config.RULE_CLASSES)
    assert config["profile_name"] == "Edge profile"


def test_config_version_is_stable_and_sensitive():
    config = rule_config.default_config()
    assert rule_config.config_version(config) == rule_config.config_version(dict(config))
    changed = rule_config.default_config()
    changed["rules"]["dates"]["severity"] = "Minor"
    assert rule_config.config_version(changed) != rule_config.config_version(config)


def test_editor_round_trip_preserves_edits():
    config = rule_config.default_config()
    config["rules"]["batch_details"]["enabled"] = False
    config["rules"]["dates"]["penalty"] = 12_000
    rebuilt = rule_config.config_from_rows(rule_config.config_rows(config), "Round trip")
    assert rebuilt["rules"]["batch_details"]["enabled"] is False
    assert rebuilt["rules"]["dates"]["penalty"] == 12_000
    assert rebuilt["profile_name"] == "Round trip"


def test_config_diff_names_the_changes():
    changed = rule_config.default_config()
    changed["rules"]["pricing"]["enabled"] = False
    changed["rules"]["dates"]["penalty"] = 1_000
    changes = rule_config.config_diff(changed)
    assert any("pricing set out of scope" in change for change in changes)
    assert any("dates penalty" in change for change in changes)
    assert rule_config.config_diff(rule_config.default_config()) == []


def test_report_payload_carries_the_profile_and_scope_split():
    config = rule_config.default_config()
    config["profile_name"] = "Institutional pack"
    config["rules"]["pricing"]["enabled"] = False
    overlaid = rule_config.apply_rule_config(_engine_results(), config)

    payload = reporting.build_report_payload(
        {"results": overlaid, "rule_config": config, "status": "READY"},
        generated_by="verifier",
    )
    block = payload["rule_config"]
    assert block["profile_name"] == "Institutional pack"
    assert block["config_version"] == rule_config.config_version(config)
    excluded = {row["rule_class"] for row in block["rules_out_of_scope"]}
    assert excluded == {"pricing"}
    assert payload["summary"]["failed_fields"] == 1
    assert payload["summary"]["estimated_penalty"] == 25_000
    assert payload["summary"]["highest_severity"] == "Critical"
    assert all(result.get("statute") for result in payload["results"])


def test_reports_render_with_a_narrowed_profile():
    config = rule_config.default_config()
    config["rules"]["batch_details"]["enabled"] = False
    scan = {
        "scan_id": 7,
        "results": rule_config.apply_rule_config(_engine_results(), config),
        "rule_config": config,
        "status": "PENDING",
        "image_paths": [],
        "evidence_hashes": [],
    }
    csv_bytes = reporting.generate_csv_report(scan, "verifier")
    assert b"Institutional" not in csv_bytes
    assert b"severity" in csv_bytes.lower()
    json_payload = json.loads(reporting.generate_json_report(scan, "verifier"))
    assert json_payload["rule_config"]["profile_name"] == config["profile_name"]
    assert reporting.generate_pdf_report(scan, "verifier").startswith(b"%PDF")


def test_saved_profile_is_versioned_and_activated(isolated_database):
    admin = db.authenticate_user("admin", "pass123")
    assert db.get_active_rule_config()["profile_name"] == rule_config.DEFAULT_PROFILE_NAME

    narrowed = rule_config.default_config()
    narrowed["profile_name"] = "Wholesale"
    narrowed["rules"]["dietary_mark"]["enabled"] = False
    saved = db.save_rule_config(admin["user_id"], narrowed)

    active = db.get_active_rule_config()
    assert active["profile_name"] == "Wholesale"
    assert active["rules"]["dietary_mark"]["enabled"] is False
    assert saved["config_version"] == rule_config.config_version(active)

    db.save_rule_config(admin["user_id"], rule_config.default_config())
    history = db.get_rule_config_history(admin["user_id"])
    assert len(history) == 2
    assert [row["active"] for row in history] == [True, False]
    assert db.get_active_rule_config()["profile_name"] == rule_config.DEFAULT_PROFILE_NAME


def test_only_admins_may_change_the_rule_profile(isolated_database):
    inspector = db.authenticate_user("inspector", "pass123")
    verifier = db.authenticate_user("verifier", "pass123")
    for user in (inspector, verifier):
        with pytest.raises(PermissionError):
            db.save_rule_config(user["user_id"], rule_config.default_config())
        with pytest.raises(PermissionError):
            db.get_rule_config_history(user["user_id"])


def test_profile_change_is_audited(isolated_database):
    admin = db.authenticate_user("admin", "pass123")
    narrowed = rule_config.default_config()
    narrowed["rules"]["dates"]["penalty"] = 3_000
    db.save_rule_config(admin["user_id"], narrowed)
    events = db.get_audit_events(admin["user_id"])
    entry = next(event for event in events if event["action"] == "UPDATE_RULE_CONFIG")
    assert "dates penalty" in entry["details"]


def test_stored_scan_keeps_the_profile_it_was_judged_under(isolated_database, tmp_path):
    admin = db.authenticate_user("admin", "pass123")
    inspector = db.authenticate_user("inspector", "pass123")
    narrowed = rule_config.default_config()
    narrowed["profile_name"] = "Non-food pack"
    narrowed["rules"]["dietary_mark"]["enabled"] = False
    db.save_rule_config(admin["user_id"], narrowed)

    evidence = tmp_path / "panel.jpg"
    evidence.write_bytes(b"not a real jpeg, only needs to hash")
    overlaid = rule_config.apply_rule_config(_engine_results(), db.get_active_rule_config())
    in_scope = [
        {
            "rule_class": rule_class,
            "extracted_text": str(details.get("extracted_text") or ""),
            "is_compliant": bool(details["is_compliant"]),
            "penalty_amount": int(details["penalty_amount"]),
            "reason": details["reason"],
            "parsed_data": {"severity": details["severity"], "in_scope": details["in_scope"]},
        }
        for rule_class, details in overlaid.items()
        if details["in_scope"]
    ]
    scan_id = db.insert_scan(inspector["user_id"], [str(evidence)], in_scope)

    stored = db.get_scan(scan_id, admin["user_id"])
    assert stored["rule_config"]["profile_name"] == "Non-food pack"
    assert {result["rule_class"] for result in stored["results"]} == {"product_name", "net_quantity"}

    # A later profile change must not rewrite history.
    db.save_rule_config(admin["user_id"], rule_config.default_config())
    reloaded = db.get_scan(scan_id, admin["user_id"])
    assert reloaded["rule_config"]["profile_name"] == "Non-food pack"

    payload = reporting.build_report_payload(reloaded, "admin")
    assert payload["rule_config"]["profile_name"] == "Non-food pack"
    assert {row["rule_class"] for row in payload["rule_config"]["rules_out_of_scope"]} == {
        "dietary_mark"
    }
