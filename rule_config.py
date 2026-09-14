"""Configurable Legal Metrology rule layer for the LMPC inspection platform.

``rule_engine`` owns statutory parsing and the pass/fail verdict for every
declaration on a pack. This module owns the part an enforcement office is
allowed to tune between inspections: which declarations are in scope for a
commodity profile, what a first offence costs, and how severe each failure is.

The split is deliberate. A configuration change can widen or narrow the scope
of an inspection and can restate the penalty, but it can never rewrite the
verdict ``rule_engine`` reached on the text actually read off the pack. Every
scan carries the profile name and a config fingerprint, so a report can be
reproduced months later against the exact rule set that produced it.
"""

from __future__ import annotations

from copy import deepcopy
import hashlib
import json
from typing import Any

#: Bumped when the shape of a stored configuration changes, not when values do.
CONFIG_SCHEMA_VERSION = 1

DEFAULT_PROFILE_NAME = "LMPC 2011 Rule 6 - default"

#: Ordered worst-first so a report can lead with the severity that matters.
SEVERITIES: tuple[str, ...] = ("Critical", "Major", "Minor", "Advisory")

#: Legal Metrology Act 2009, s. 36(1): first-offence ceiling for a
#: non-standard package. Configured penalties are clamped to it.
STATUTORY_PENALTY_CEILING = 25_000

#: ``rule_class`` must match the keys ``rule_engine.validate_compliance``
#: returns, otherwise the class can never be configured. Order is the order an
#: officer reads a pack, and the order the report renders.
RULE_CATALOGUE: tuple[dict[str, Any], ...] = (
    {
        "rule_class": "product_name",
        "label": "Name of the commodity",
        "statute": "LMPC Rules 2011, r. 6(1)(b)",
        "severity": "Major",
        "penalty": 25_000,
        "advisory": False,
    },
    {
        "rule_class": "net_quantity",
        "label": "Net quantity in standard units",
        "statute": "LMPC Rules 2011, r. 6(1)(c) with r. 8 and r. 9",
        "severity": "Critical",
        "penalty": 25_000,
        "advisory": False,
    },
    {
        "rule_class": "pricing",
        "label": "Retail sale price, inclusive of all taxes",
        "statute": "LMPC Rules 2011, r. 6(1)(e)",
        "severity": "Critical",
        "penalty": 25_000,
        "advisory": False,
    },
    {
        "rule_class": "dates",
        "label": "Month and year of manufacture or pre-packing",
        "statute": "LMPC Rules 2011, r. 6(1)(d)",
        "severity": "Major",
        "penalty": 25_000,
        "advisory": False,
    },
    {
        "rule_class": "batch_details",
        "label": "Batch, lot or code number",
        "statute": "FSS (Labelling and Display) Regulations 2020, reg. 5(1)",
        "severity": "Minor",
        "penalty": 10_000,
        "advisory": False,
    },
    {
        "rule_class": "manufacturer_info",
        "label": "Name and address of manufacturer, packer or importer",
        "statute": "LMPC Rules 2011, r. 6(1)(a)",
        "severity": "Critical",
        "penalty": 25_000,
        "advisory": False,
    },
    {
        "rule_class": "compliance_and_support",
        "label": "Consumer care details and FSSAI licence",
        "statute": "LMPC Rules 2011, r. 6(1)(f)",
        "severity": "Major",
        "penalty": 25_000,
        "advisory": False,
    },
    {
        "rule_class": "dietary_mark",
        "label": "Veg / non-veg dietary mark",
        "statute": "FSS (Labelling and Display) Regulations 2020, reg. 5(3)",
        "severity": "Advisory",
        "penalty": 0,
        "advisory": True,
    },
)

RULE_CLASSES: tuple[str, ...] = tuple(entry["rule_class"] for entry in RULE_CATALOGUE)

_CATALOGUE_BY_CLASS: dict[str, dict[str, Any]] = {
    entry["rule_class"]: entry for entry in RULE_CATALOGUE
}

def default_config() -> dict[str, Any]:
    """The shipped profile: every Rule 6 declaration in scope at the ceiling."""

    return {
        "schema_version": CONFIG_SCHEMA_VERSION,
        "profile_name": DEFAULT_PROFILE_NAME,
        "rules": {
            entry["rule_class"]: {
                "enabled": True,
                "severity": entry["severity"],
                "penalty": entry["penalty"],
                "statute": entry["statute"],
            }
            for entry in RULE_CATALOGUE
        },
    }


def _clamp_penalty(value: Any, fallback: int) -> int:
    try:
        penalty = int(float(value))
    except (TypeError, ValueError):
        return fallback
    return max(0, min(STATUTORY_PENALTY_CEILING, penalty))


def normalize_config(raw: Any) -> dict[str, Any]:
    """Coerce anything stored, edited or hand-written into a usable profile.

    Unknown rule classes are dropped rather than trusted: a stale config must
    not be able to introduce a rule the engine has no parser for. Missing
    classes fall back to the catalogue so a new statutory check is in scope the
    moment it ships, without an admin having to re-save the profile.
    """

    if isinstance(raw, (str, bytes)):
        try:
            raw = json.loads(raw)
        except (json.JSONDecodeError, UnicodeDecodeError):
            raw = {}
    if not isinstance(raw, dict):
        raw = {}

    stored_rules = raw.get("rules")
    if not isinstance(stored_rules, dict):
        stored_rules = {}

    config = default_config()
    profile_name = str(raw.get("profile_name") or "").strip()
    if profile_name:
        config["profile_name"] = profile_name[:120]

    for rule_class, defaults in config["rules"].items():
        stored = stored_rules.get(rule_class)
        if not isinstance(stored, dict):
            continue
        catalogue = _CATALOGUE_BY_CLASS[rule_class]
        severity = str(stored.get("severity") or "").strip().title()
        defaults["enabled"] = bool(stored.get("enabled", defaults["enabled"]))
        defaults["severity"] = severity if severity in SEVERITIES else defaults["severity"]
        defaults["penalty"] = (
            0 if catalogue["advisory"] else _clamp_penalty(stored.get("penalty"), defaults["penalty"])
        )
    return config


def config_version(config: Any) -> str:
    """Short deterministic fingerprint of a profile, stamped onto every report."""

    normalized = normalize_config(config)
    canonical = json.dumps(normalized, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()[:12]


def apply_rule_config(
    results: dict[str, Any],
    config: Any = None,
) -> dict[str, dict[str, Any]]:
    """Overlay a profile onto one ``validate_compliance`` result set.

    ``is_compliant`` is never touched for an in-scope class: the engine's
    verdict on the extracted text stands. What the profile decides is scope,
    money and severity, and each row records the profile that decided it so the
    report can explain itself without the reader holding the config in hand.
    """

    if not isinstance(results, dict):
        return {}
    normalized = normalize_config(config)
    version = config_version(normalized)
    profile = normalized["profile_name"]
    overlaid: dict[str, dict[str, Any]] = {}

    for rule_class, details in results.items():
        if not isinstance(details, dict):
            continue
        row = deepcopy(details)
        setting = normalized["rules"].get(rule_class)
        catalogue = _CATALOGUE_BY_CLASS.get(rule_class, {})
        row["rule_label"] = str(catalogue.get("label", rule_class.replace("_", " ").title()))
        row["config_profile"] = profile
        row["config_version"] = version

        if setting is None:
            # A class the engine produces but no profile covers. Report it, keep
            # the engine's verdict, and charge nothing we cannot cite.
            row["in_scope"] = True
            row["severity"] = "Unclassified"
            row["penalty_amount"] = 0
            row["statute"] = str(row.get("statute") or "Not mapped to a configured rule")
            row["reason"] = (
                f"{str(row.get('reason') or '').strip()} "
                f"This class is not covered by profile '{profile}', so no penalty is proposed."
            ).strip()
            overlaid[rule_class] = row
            continue

        row["statute"] = setting["statute"]
        row["severity"] = setting["severity"]
        if not setting["enabled"]:
            row["in_scope"] = False
            row["penalty_amount"] = 0
            row["reason"] = (
                f"Out of scope for profile '{profile}': this declaration was not assessed. "
                f"Configured basis: {setting['statute']}."
            )
            overlaid[rule_class] = row
            continue

        row["in_scope"] = True
        compliant = bool(row.get("is_compliant", False))
        row["penalty_amount"] = 0 if compliant else int(setting["penalty"])
        row["reason"] = (
            f"{str(row.get('reason') or '').strip()} "
            f"Assessed under {setting['statute']} at {setting['severity'].lower()} severity "
            f"(profile '{profile}', config {version})."
        ).strip()
        overlaid[rule_class] = row
    return overlaid


def _iter_results(results: Any) -> list[dict[str, Any]]:
    if isinstance(results, dict):
        return [
            {"rule_class": rule_class, **details}
            for rule_class, details in results.items()
            if isinstance(details, dict)
        ]
    if isinstance(results, list):
        return [dict(result) for result in results if isinstance(result, dict)]
    return []


def result_metadata(result: dict[str, Any]) -> dict[str, Any]:
    """Read the overlay fields whether the row is live or reloaded from storage.

    Stored rows keep the overlay inside ``parsed_data``, live rows carry it at
    the top level. Callers should not have to know which one they hold.
    """

    parsed = result.get("parsed_data")
    if not isinstance(parsed, dict):
        parsed = {}
    rule_class = str(result.get("rule_class", ""))
    catalogue = _CATALOGUE_BY_CLASS.get(rule_class, {})
    severity = result.get("severity", parsed.get("severity")) or catalogue.get("severity", "")
    return {
        "rule_class": rule_class,
        "in_scope": bool(result.get("in_scope", parsed.get("in_scope", True))),
        "severity": str(severity),
        "label": str(
            result.get("rule_label")
            or parsed.get("rule_label")
            or catalogue.get("label")
            or rule_class.replace("_", " ").title()
        ),
        "statute": str(result.get("statute") or parsed.get("statute") or ""),
        "profile": str(result.get("config_profile") or parsed.get("config_profile") or ""),
        "version": str(result.get("config_version") or parsed.get("config_version") or ""),
    }


def summarize(results: Any) -> dict[str, Any]:
    """Scope-aware totals. Out-of-scope classes count as neither pass nor fail."""

    rows = _iter_results(results)
    summary: dict[str, Any] = {
        "total": len(rows),
        "assessed": 0,
        "skipped": 0,
        "passed": 0,
        "failed": 0,
        "penalty": 0,
        "failed_by_severity": {severity: 0 for severity in SEVERITIES},
        "highest_severity": "",
        "profile_name": "",
        "config_version": "",
    }
    for row in rows:
        meta = result_metadata(row)
        summary["profile_name"] = summary["profile_name"] or meta["profile"]
        summary["config_version"] = summary["config_version"] or meta["version"]
        if not meta["in_scope"]:
            summary["skipped"] += 1
            continue
        summary["assessed"] += 1
        if bool(row.get("is_compliant", False)):
            summary["passed"] += 1
            continue
        summary["failed"] += 1
        summary["penalty"] += max(0, int(row.get("penalty_amount", 0) or 0))
        if meta["severity"] in summary["failed_by_severity"]:
            summary["failed_by_severity"][meta["severity"]] += 1
    for severity in SEVERITIES:
        if summary["failed_by_severity"].get(severity):
            summary["highest_severity"] = severity
            break
    return summary


#: Column labels for the admin editor. Kept here so the UI and the parser that
#: reads it back can never drift apart.
EDITOR_COLUMNS = {
    "rule_class": "Rule class",
    "label": "Declaration",
    "enabled": "In scope",
    "severity": "Severity",
    "penalty": "Penalty (INR)",
    "statute": "Statutory basis",
}


def config_rows(config: Any = None) -> list[dict[str, Any]]:
    """Flatten a profile into editor rows, catalogue order preserved."""

    normalized = normalize_config(config)
    rows: list[dict[str, Any]] = []
    for entry in RULE_CATALOGUE:
        setting = normalized["rules"][entry["rule_class"]]
        rows.append(
            {
                EDITOR_COLUMNS["rule_class"]: entry["rule_class"],
                EDITOR_COLUMNS["label"]: entry["label"],
                EDITOR_COLUMNS["enabled"]: bool(setting["enabled"]),
                EDITOR_COLUMNS["severity"]: setting["severity"],
                EDITOR_COLUMNS["penalty"]: int(setting["penalty"]),
                EDITOR_COLUMNS["statute"]: setting["statute"],
            }
        )
    return rows


def config_from_rows(rows: Any, profile_name: str = "") -> dict[str, Any]:
    """Rebuild a profile from editor rows, ignoring anything unrecognised."""

    if hasattr(rows, "to_dict"):
        rows = rows.to_dict(orient="records")
    config = default_config()
    if str(profile_name or "").strip():
        config["profile_name"] = str(profile_name).strip()[:120]
    for row in rows or []:
        if not isinstance(row, dict):
            continue
        rule_class = str(row.get(EDITOR_COLUMNS["rule_class"], "")).strip()
        setting = config["rules"].get(rule_class)
        if setting is None:
            continue
        setting["enabled"] = bool(row.get(EDITOR_COLUMNS["enabled"], setting["enabled"]))
        severity = str(row.get(EDITOR_COLUMNS["severity"]) or "").strip().title()
        if severity in SEVERITIES:
            setting["severity"] = severity
        if not _CATALOGUE_BY_CLASS[rule_class]["advisory"]:
            setting["penalty"] = _clamp_penalty(
                row.get(EDITOR_COLUMNS["penalty"]), setting["penalty"]
            )
    return normalize_config(config)


def config_diff(current: Any, baseline: Any = None) -> list[str]:
    """Human-readable deltas against the shipped defaults, for the audit trail."""

    left = normalize_config(current)
    right = normalize_config(baseline if baseline is not None else default_config())
    changes: list[str] = []
    if left["profile_name"] != right["profile_name"]:
        changes.append(f"profile renamed to '{left['profile_name']}'")
    for rule_class, setting in left["rules"].items():
        reference = right["rules"][rule_class]
        if setting["enabled"] != reference["enabled"]:
            state = "in scope" if setting["enabled"] else "out of scope"
            changes.append(f"{rule_class} set {state}")
        if setting["severity"] != reference["severity"]:
            changes.append(f"{rule_class} severity {reference['severity']} to {setting['severity']}")
        if setting["penalty"] != reference["penalty"]:
            changes.append(
                f"{rule_class} penalty {reference['penalty']} to {setting['penalty']}"
            )
    return changes
