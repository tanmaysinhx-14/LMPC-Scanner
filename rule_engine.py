"""LMPC Rule 6 compliance checks, written against real OCR output.

Every parser here receives one class's text for one pack, already concatenated by
``pipeline.join_readings`` from every box the detector found for that class across
every photograph of it. Two consequences shape all of the logic below.

First, a declaration is routinely split across boxes. The annotations put the
"MRP" tag, the price and "inclusive of all taxes" in separate boxes whenever the
pack prints them apart, and the manufacturer and consumer-care panels are split
the same way. So a parser reads a joined, out-of-order string - the label can
arrive *after* its value - not a tidy line.

Second, the text is raw recognizer output over dot-matrix over-print, glare and
curved film. "Inclusive of all taxes" comes back as ``MRPINCL OFALLTAXES``,
``(ind. ofalltaxes)`` and ``nclisofall taxes``; "36 months from manufacture" as
``EXPIRY 36MONTHSFROMMANUFACTURED.``; "Marketed by" as ``MKTD. BY``.

The tolerances below were sized against a measured corpus rather than guessed:
the full RapidOCR sweep of the annotated dataset, 1397 images and 4274
image-by-class groups, in ``runs/ocr_rapidocr.digest.csv``. Comments cite the
strings that motivated each one. To re-check a change, rebuild that digest with
``python extract_dataset_ocr.py --output_file runs/ocr_rapidocr.jsonl
--digest_only`` - about five seconds, because it re-parses cached OCR instead of
re-reading the images - and diff the per-class verdict counts.

Three layers, in order:

* ``clean_ocr_text`` - repair that is safe for every class: glyph confusions,
  fuzzy keyword healing, splitting a label glued onto its value.
* the fuzzy helpers - ``_phrase_score`` and the field extractors, which tolerate
  lost spaces and mangled letters.
* the ``parse_*`` functions - one per annotated class, each returning the cleaned
  text, its parsed fields, a verdict and a penalty.

What is deliberately unchanged: *which* fields a class must produce to be
compliant. Relaxing that would raise the compliance rate without any pack
becoming more lawful. Every gain here comes from reading text that was already
printed on the label.
"""

from __future__ import annotations

from datetime import date
import re
from typing import Any

import Levenshtein

#: Section 45 of the Legal Metrology Act read with the 2011 Rules: the compounding
#: amount for a first offence against a Rule 6 declaration.
FIRST_OFFENSE_PENALTY = 25_000

_RUPEE = "₹"
#: The rupee sign after a utf-8 payload has been decoded as cp1252 somewhere
#: upstream. It survives into the OCR text often enough to be worth normalising.
_MOJIBAKE_RUPEE = "â‚¹"

_NO_TEXT = re.compile(r"^no\s+text(?:\s+detected)?[.!]?$", re.IGNORECASE)


# ---------------------------------------------------------------------------
# 1. region aliases
# ---------------------------------------------------------------------------

#: Every key an upstream caller has ever used for a region, newest first. The app
#: passes the canonical detector class names; older exports and hand-written
#: fixtures use the "_region" suffix.
_ALIASES: dict[str, tuple[str, ...]] = {
    "product_name": (
        "product_name",
        "product_name_region",
        "generic_name_region",
        "generic_name",
        "brand_region",
        "product_identity_region",
    ),
    "net_quantity": ("net_quantity", "net_quantity_region"),
    "mrp_declaration": (
        "mrp_declaration",
        "mrp_region",
        "pricing",
        "price_region",
        "unit_sale_price_region",
    ),
    "date_declarations": ("date_declarations", "date_region", "dates", "expiry_region"),
    "batch_number": (
        "batch_number",
        "batch_number_region",
        "batch_region",
        "lot_number_region",
    ),
    "manufacturer_details": (
        "manufacturer_details",
        "manufacturer_region",
        "manufacturer",
        "manufacturer_address_region",
        "packer_region",
        "importer_region",
        "marketed_by_region",
        "country_origin_region",
    ),
    "consumer_care_fssai": (
        "consumer_care_fssai",
        "consumer_care_region",
        "consumer",
        "fssai",
        "fssai_region",
        "consumer_support_region",
    ),
}

# ---------------------------------------------------------------------------
# 2. the OCR noise layer
# ---------------------------------------------------------------------------

#: Words the recognizer mangles but that carry meaning for a rule. The healer
#: replaces a token with one of these when it is close enough by edit distance -
#: this is what turns "trur|" into "OUR" and "Manutactured" into "MANUFACTURED".
_FUZZY_KEYWORDS = {
    "mfd": "MFD",
    "mfg": "MFG",
    "pkd": "PKD",
    "pkg": "PKG",
    "exp": "EXP",
    "mrp": "MRP",
    "marketed": "MARKETED",
    "manufactured": "MANUFACTURED",
    "packed": "PACKED",
    "distributed": "DISTRIBUTED",
    "imported": "IMPORTED",
    "batch": "BATCH",
    "lot": "LOT",
    "best": "BEST",
    "before": "BEFORE",
    "use": "USE",
    "by": "BY",
    "from": "FROM",
    "manufacture": "MANUFACTURE",
    "manufacturing": "MANUFACTURING",
    "address": "ADDRESS",
    "country": "COUNTRY",
    "origin": "ORIGIN",
    "net": "NET",
    "quantity": "QUANTITY",
    "weight": "WEIGHT",
    "volume": "VOLUME",
    "price": "PRICE",
    "usp": "USP",
    "lic": "LIC",
    "inclusive": "INCLUSIVE",
    "taxes": "TAXES",
    "consumer": "CONSUMER",
    "care": "CARE",
    "feedback": "FEEDBACK",
    "complaint": "COMPLAINT",
    "queries": "QUERIES",
    "contact": "CONTACT",
    "visit": "VISIT",
    "us": "US",
    "our": "OUR",
    "fssai": "FSSAI",
    "license": "LICENSE",
}

#: Misreads too far gone for edit distance to recover, seen repeatedly in the
#: corpus. "trur|" is "OUR" with the O read as t-r and the R as a pipe.
_DIRECT_KEYWORD_FIXES = {
    "trur": "OUR",
    "trur|": "OUR",
    "u5": "US",
    "v5": "US",
    "bv": "BY",
}
_DOMAIN_SUFFIXES = r"(?:com|in|org|net|coop|co\.in)"

#: Tokens the OCR healers must leave alone. Both healers are aggressive by
#: design - they exist to rescue "trur|" into "OUR" - but they were also
#: rewriting lawful unit symbols: the keyword healer turned "1 kg" into "1 PKG"
#: (Levenshtein ratio 0.8 against the "pkg" packing keyword) and the numeric
#: healer turned "500ml" into "500m1" (the l-to-1 glyph rule), so two perfectly
#: ordinary net-quantity declarations parsed as no quantity at all.
#: The address words are protected for the same reason: "Plot No. 50" was healed
#: into "LOT No. 50" (ratio 0.86 against the batch keyword) and "Industrial Area"
#: into "Industrial CARE" (0.75), which cost the manufacturer's address the only
#: legible words it had.
_PROTECTED_TOKENS = frozenset({
    "g", "gm", "gms", "kg", "kgs", "mg", "mgs", "ml", "mls", "cl", "l", "ls",
    "ltr", "ltrs", "liter", "liters", "litre", "litres", "mm", "cm", "m",
    "pc", "pcs", "no", "nos", "n", "u", "rs", "inr",
    "plot", "area", "road", "post", "part", "phase", "near", "opp", "dist",
})
#: A number glued to a unit ("500ml", "1kg", "2OOg"). The head may still contain
#: OCR glyph confusions worth healing; the unit must survive untouched.
_NUMBER_WITH_UNIT = re.compile(
    r"(?i)^(?P<head>[\dOoIl|.,]+)(?P<unit>kgs?|mgs?|mls?|kg|mg|ml|cl|gms?|ltrs?|pcs?|nos?|[gl])$"
)
_GLYPH_TO_DIGIT = str.maketrans({"O": "0", "o": "0", "I": "1", "l": "1", "|": "1"})
#: A run of digit-shaped letters that touches a digit, which is the only place the
#: numeric healer is allowed to work.
_GLYPH_RUN = re.compile(r"(?<=\d)[OoIl|]+|[OoIl|]+(?=\d)")

#: An address run - anything holding an "@" or a domain suffix by the time the
#: healers run. Its words are not label words and must never be healed into any:
#: "care@tataconsumer.com" came back as "CARE@CONSUMER.com", because the token
#: "tataconsumer" scores 0.8 against the "consumer" keyword. Fabricating a contact
#: address is worse than leaving a mangled one, since the mangled one is visibly
#: mangled and this one is not.
_ADDRESS_RUN = re.compile(
    r"[A-Za-z0-9._%+|-]*(?:@|\.(?:com|in|org|net|coop))[A-Za-z0-9._%+-]*",
    re.IGNORECASE,
)

#: Label words that dot-matrix printing glues straight onto their value:
#: "NETWEIGHT150g", "MRP279.00", "PKD12/08/2026". Splitting them once, here, lets
#: every downstream pattern keep its "(?<!\w)" guard - which is exactly what was
#: rejecting these declarations - instead of each pattern growing a special case.
#: Deliberately not anchored on a word boundary: the label is usually glued on its
#: left as well ("DATEOFPKG"), and \b never matches between two letters.
_GLUED_LABEL = re.compile(
    r"(?i)(wt|weight|qty|quantity|vol|volume|contents?|"
    r"mrp|usp|price|rs|inr|pkd|pkg|pkt|mfd|mfg|exp|batch|lot|no)(?=\d)"
)

def _coerce_text(value: Any) -> str:
    if value is None:
        return ""
    if isinstance(value, str):
        return re.sub(r"\s+", " ", value).strip()
    if isinstance(value, dict):
        return re.sub(r"\s+", " ", " ".join(_coerce_text(item) for item in value.values())).strip()
    if isinstance(value, (list, tuple, set)):
        return re.sub(r"\s+", " ", " ".join(_coerce_text(item) for item in value)).strip()
    return str(value).strip()


def _heal_keyword(match: re.Match[str]) -> str:
    raw = match.group(0)
    lookup = raw.strip("|.").lower()
    if lookup in _PROTECTED_TOKENS or _NUMBER_WITH_UNIT.match(lookup):
        return raw
    if lookup in _DIRECT_KEYWORD_FIXES:
        return _DIRECT_KEYWORD_FIXES[lookup]
    candidate = lookup.replace("0", "o").replace("1", "i").replace("5", "s").replace("|", "i")
    best_keyword = None
    best_score = 0.0
    for keyword, replacement in _FUZZY_KEYWORDS.items():
        score = Levenshtein.ratio(candidate, keyword)
        if score > best_score:
            best_keyword = replacement
            best_score = score
    threshold = 0.68 if len(candidate) <= 4 else 0.76
    return best_keyword if best_keyword is not None and best_score >= threshold else raw


def _normalize_numeric_token(match: re.Match[str]) -> str:
    token = match.group(1)
    with_unit = _NUMBER_WITH_UNIT.match(token)
    if with_unit and any(character.isdigit() for character in with_unit.group("head")):
        # "500ml" is a number glued to its unit. Healing glyphs in the numeric
        # head is always safe here ("5OOml" -> "500ml", the single most common
        # net-quantity misread); touching the unit is not, because l-to-1 would
        # destroy every millilitre declaration on the shelf.
        return with_unit.group("head").translate(_GLYPH_TO_DIGIT) + with_unit.group("unit")
    digit_count = sum(character.isdigit() for character in token)
    if digit_count < 2 or not re.search(r"[A-Za-z|]", token):
        return token
    # Only glyph runs that touch a digit are healed. Translating every O in the
    # token turned the glued "BEFORE18MONTHSFROMMANUFACTURE" into
    # "BEF0RE18M0NTHSFR0MMANUFACTURE", which hid both the shelf life and the
    # "from manufacture" anchor that licenses reading it as one.
    token = _GLYPH_RUN.sub(lambda run: run.group(0).translate(_GLYPH_TO_DIGIT), token)
    if digit_count >= len(token) - 1:
        token = token.translate(str.maketrans({"S": "5", "s": "5"}))
    return token

def clean_ocr_text(text: str) -> str:
    """Repair recognizer noise that is safe to repair for every class."""

    cleaned = _coerce_text(text)
    if not cleaned:
        return ""
    cleaned = cleaned.replace(_MOJIBAKE_RUPEE, _RUPEE)
    cleaned = cleaned.replace("×", "x")
    # A price loses its decimal point to a stray space or gains a second one:
    # "Rs 175. .00", "M 5 515 .00", "MRP: 92. 00". Repairing the separator here
    # keeps the amount pattern a single tight rule instead of three loose ones.
    cleaned = re.sub(r"(?<=\d)\s*\.\s*\.\s*(?=\d)", ".", cleaned)
    cleaned = re.sub(r"(?<=\d)\s+\.(?=\d{2}(?!\d))", ".", cleaned)
    cleaned = re.sub(r"(?<=\d)\.\s+(?=\d{2}(?!\d))", ".", cleaned)
    cleaned = re.sub(r"\b(?:t0|to)\s+(?:vs|v5)\s*[:;|]?", "to us: ", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(
        r"\b(?:marketed|manufactured|packed|mfd|mfg|pkd|pkg)\s+B[VY]\b",
        lambda match: f"{match.group(0).rsplit(maxsplit=1)[0]} BY",
        cleaned,
        flags=re.IGNORECASE,
    )
    cleaned = re.sub(r"\bM\s*[.]?\s*F\s*[.]?\s*D\b", "MFD", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bM\s*[.]?\s*F\s*[.]?\s*G\b", "MFG", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bP\s*[.]?\s*K\s*[.]?\s*D\b", "PKD", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\bP\s*[.]?\s*K\s*[.]?\s*G\b", "PKG", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\btrur\|?\b", "OUR", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"(?<!\d)\d[.\s-]*(?=1800(?:[\s-]*\d))", "", cleaned)
    cleaned = re.sub(r"\bwww(?=[A-Za-z0-9])", "www.", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"(?i)(?<![A-Za-z0-9.])([A-Za-z0-9_-]{3,})(" + _DOMAIN_SUFFIXES + r")\b", r"\1.\2", cleaned)
    cleaned = re.sub(r"(?i)\bco\s*[.]?\s*in\b", "co.in", cleaned)
    cleaned = re.sub(r"(?i)(?<=\w)\s*@\s*(?=\w)", "@", cleaned)
    cleaned = _GLUED_LABEL.sub(r"\1 ", cleaned)
    protected = [span.span() for span in _ADDRESS_RUN.finditer(cleaned)]
    cleaned = re.sub(
        r"(?i)\b[A-Za-z0-9|]+\b",
        lambda token: token.group(0)
        if any(start <= token.start() and token.end() <= end for start, end in protected)
        else _heal_keyword(token),
        cleaned,
    )
    cleaned = re.sub(r"(?<!\d)([A-Za-z0-9|]+)(?!\d)", _normalize_numeric_token, cleaned)
    cleaned = re.sub(r"[|;]+", ",", cleaned)
    cleaned = re.sub(r"\s*,\s*", ", ", cleaned)
    cleaned = re.sub(r"\s+", " ", cleaned).strip(" ,")
    return cleaned


def _usable_text(text: str) -> bool:
    return bool(text) and not _NO_TEXT.fullmatch(text.strip())


def _region_text(data: dict[str, Any], region: str, fallback: str = "") -> str:
    for alias in _ALIASES[region]:
        if alias in data:
            value = _coerce_text(data[alias])
            if value:
                return value
    return fallback

# ---------------------------------------------------------------------------
# 3. fuzzy phrase matching
# ---------------------------------------------------------------------------


def _letters(text: str) -> str:
    return re.sub(r"[^a-z]", "", text.lower())


def _phrase_score(text: str, target: str, slack: int = 6) -> float:
    """Best similarity between any letter window of ``text`` and ``target``.

    Words on a pack lose their spaces long before they lose their letters:
    "inclusive of all taxes" comes back as ``MRPINCL OFALLTAXES``,
    ``(ind. ofalltaxes)`` and ``inc.o ofallt taxes``. Comparing word against word -
    which is what this used to do, over windows of three to six words - fails on
    all three, because the words are no longer words. Dropping every non-letter
    first and sliding a window of roughly the target's length over what remains
    survives all of them, and costs nothing: these strings are short.

    ``Levenshtein.ratio`` here is the indel similarity, ``2 * LCS / (len + len)``,
    so letters must appear *in order* to count - which is what keeps a window of
    unrelated text from scoring highly just by sharing an alphabet.
    """

    haystack = _letters(text)
    if not haystack:
        return 0.0
    needle = _letters(target)
    best = 0.0
    for width in sorted({max(4, len(needle) - slack), len(needle), len(needle) + slack}):
        if width >= len(haystack):
            best = max(best, Levenshtein.ratio(haystack, needle))
            continue
        for start in range(0, len(haystack) - width + 1):
            best = max(best, Levenshtein.ratio(haystack[start : start + width], needle))
    return best

# ---------------------------------------------------------------------------
# 4. shared value helpers
# ---------------------------------------------------------------------------


def _number(value: str) -> float:
    normalized = re.sub(r"\s+", " ", value.strip())
    if re.fullmatch(r"\d{1,4} \d{2}", normalized):
        normalized = normalized.replace(" ", ".")
    else:
        normalized = normalized.replace(" ", "")
    return float(normalized.replace(",", "."))


def _scalar(value: float | None) -> float | None:
    if value is None:
        return None
    return float(value)


def _count(value: float) -> float | int:
    return int(value) if value.is_integer() else value


def _result(extracted_text: str, fields: dict[str, Any], compliant: bool) -> dict[str, Any]:
    result: dict[str, Any] = {"extracted_text": extracted_text or None}
    result.update(fields)
    result["is_compliant"] = bool(compliant)
    result["penalty_amount"] = 0 if compliant else FIRST_OFFENSE_PENALTY
    result["reason"] = (
        "All configured checks passed for this declaration."
        if compliant
        else "The declaration is missing, unreadable, or failed a configured compliance check."
    )
    return result


# ---------------------------------------------------------------------------
# 5. net quantity
# ---------------------------------------------------------------------------

#: Units Rule 6 does not permit. "200 gms" is a real declaration on real packs and
#: a real offence: the schedule requires the SI symbol "g".
_INVALID_UNITS = re.compile(
    r"(?<!\w)\d+(?:[.,]\d+)?\s*(?:kgs|gms|gm|mls|ls|mgs)(?!\w)",
    re.IGNORECASE,
)

_UNIT_WORDS = (
    r"kgs?|gms?|gm|mls?|ls?|mgs?|"
    r"kilograms?|grams?|millilit(?:er|re)s?|lit(?:er|re)s?|"
    r"milligrams?|kg|mg|ml|cl|l|cm|m|g|q|9"
)
#: The unit as printed, plus the two glyphs the recognizer substitutes for a
#: lone "g": "9" (already handled) and - measured on 14 groups in the corpus -
#: "6" or "8", as in "NETWEIGHT: 70 6", "NET 100 6", "62 6". Those two are
#: accepted only as a *separate* token, never glued to the number, so "1006"
#: still parses as no quantity rather than as 100 g.
_QUANTITY = re.compile(
    r"(?<!\w)(?P<value>\d+(?:[.,]\d+)?)\s*"
    rf"(?P<unit>{_UNIT_WORDS}|"
    r"pieces?|pcs?|nos?|units?|count|n|tabs?|tablets?|capsules?|sachets?|"
    r"(?<=\s)[68])(?!\w)",
    re.IGNORECASE,
)
_MULTIPACK = re.compile(
    r"(?<!\w)(?P<units>\d+(?:[.,]\d+)?)\s*"
    r"(?:(?:packs?|pkts?|units?)|u)?\s*[x×*]\s*"
    r"(?P<weight>\d+(?:[.,]\d+)?)\s*"
    rf"(?P<unit>{_UNIT_WORDS})(?!\w)",
    re.IGNORECASE,
)


def _unit(value: str) -> str | None:
    """Canonical SI symbol for a printed unit token, or None if unlawful."""

    normalized = value.lower()
    if normalized in {"q", "9", "6", "8"}:
        return "g"
    if normalized in {"g", "gram", "grams"}:
        return "g"
    if normalized in {"kg", "kilogram", "kilograms"}:
        return "kg"
    if normalized in {"mg", "milligram", "milligrams"}:
        return "mg"
    if normalized in {"ml", "milliliter", "milliliters", "millilitre", "millilitres"}:
        return "ml"
    if normalized in {"cl", "l", "liter", "liters", "litre", "litres"}:
        return "cl" if normalized == "cl" else "l"
    if normalized in {"cm", "m"}:
        return normalized
    if normalized in {
        "piece", "pieces", "pc", "pcs", "no", "nos", "unit", "units", "count",
        "n", "tab", "tabs", "tablet", "tablets", "capsule", "capsules",
        "sachet", "sachets",
    }:
        return "pcs"
    return None

def parse_net_quantity(text: str) -> dict[str, Any]:
    extracted = clean_ocr_text(text)
    value: float | None = None
    unit: str | None = None
    multipack: dict[str, Any] | None = None
    compliant = _usable_text(extracted)

    multipack_match = _MULTIPACK.search(extracted) if compliant else None
    if multipack_match:
        multipack_unit = _unit(multipack_match.group("unit"))
        multipack = {
            "units": _count(_number(multipack_match.group("units"))),
            "unit_weight": _scalar(_number(multipack_match.group("weight"))),
            "unit_measure": multipack_unit,
        }
        if multipack_unit is None:
            compliant = False

    quantity_match = _QUANTITY.search(extracted) if compliant else None
    if quantity_match:
        value = _number(quantity_match.group("value"))
        unit = _unit(quantity_match.group("unit"))
        if unit is None:
            compliant = False

    if multipack and value is None:
        value = float(multipack["units"]) * float(multipack["unit_weight"])
        unit = multipack["unit_measure"]
    if multipack and value is not None:
        expected = float(multipack["units"]) * float(multipack["unit_weight"])
        is_breakdown_quantity = (
            quantity_match is not None
            and multipack_match is not None
            and multipack_match.start() <= quantity_match.start() <= quantity_match.end() <= multipack_match.end()
        )
        if is_breakdown_quantity:
            value = expected
            unit = multipack["unit_measure"]
        elif abs(value - expected) > 0.01:
            compliant = False
    if _INVALID_UNITS.search(extracted):
        compliant = False
    if value is None or unit is None:
        compliant = False

    return _result(
        extracted,
        {"value": _scalar(value), "unit": unit, "multipack": multipack},
        compliant,
    )

# ---------------------------------------------------------------------------
# 6. product name
# ---------------------------------------------------------------------------

_NAME_LABEL = re.compile(
    r"^\s*(?:product(?:\s*name)?|common\s*name|generic\s*name|brand(?:\s*name)?|"
    r"name\s+of\s+(?:the\s+)?commodity|name)\s*[:#.-]?\s*",
    re.IGNORECASE,
)


def parse_product_name(text: str) -> dict[str, Any]:
    extracted = clean_ocr_text(text)
    if not _usable_text(extracted):
        return _result(extracted, {}, False)
    core = _NAME_LABEL.sub("", extracted).strip(" -:;,.()")
    compliant = bool(re.search(r"[A-Za-z]{2,}", core)) and not bool(re.fullmatch(r"[\d\W_]+", core))
    return _result(extracted, {}, compliant)


# ---------------------------------------------------------------------------
# 7. the price declaration
# ---------------------------------------------------------------------------

_CURRENCY_PREFIX = r"(?:MRP|USP|#|U|V|₹|Rs\.?|INR)"
_MRP_MARKER = re.compile(
    rf"(?:\bm\s*r\s*p\b|\bmaximum\s+retail\s+price\b|\bmax\.?\s+retail\s+price\b|"
    rf"\bretail\s+sale\s+price\b|(?<!\w){_CURRENCY_PREFIX}(?!\w))",
    re.IGNORECASE,
)
_AMOUNT = re.compile(r"(?<!\w)(\d{1,4}(?:[., ]\d{2})?)(?!\w)")
_PRICE_PAIR = re.compile(
    r"(?P<mrp>\d{1,4}(?:[., ]\d{2})?)\s*/\s*\(?\s*"
    r"(?P<usp>\d{1,4}(?:[., ]\d{2})?)",
    re.IGNORECASE,
)
#: A price per quantity: "Rs.74/100g", "0.72/g", "2.00/ 9". The slash here is a
#: rate denominator, so the number in front of it is the unit sale price and the
#: MRP is printed elsewhere in the region. Without this, _PRICE_PAIR reads
#: "USP Rs.74/100g" as an MRP of 74 and a USP of 100, discarding the real price.
_UNIT_RATE = re.compile(
    r"(?P<rate>\d{1,4}(?:[., ]\d{2})?)\s*/\s*\(?\s*(?:rs\.?\s*)?(?:per\s*)?"
    r"(?:\d{1,4}\s*)?(?:kgs?|mgs?|mls?|gms?|cl|ltrs?|[gl69])(?!\w)",
    re.IGNORECASE,
)
_USP_MARKER = re.compile(r"\bUSP\b", re.IGNORECASE)
_USP_UNIT = re.compile(
    r"(?:rs\.?\s*(?:per|/)\s*(?:\d+(?:[.,]\d+)?\s*)?|"
    r"per\s*(?:\d+(?:[.,]\d+)?\s*)?|/\s*(?:\d+(?:[.,]\d+)?\s*)?)"
    r"(?P<unit>kg|mg|g|ml|cl|l)\b",
    re.IGNORECASE,
)

#: Rule 6(1)(e) wants the retail sale price declared as inclusive of all taxes.
#: The corpus prints that wording three ways once OCR has been through it, so the
#: check looks for three cues in order of how much of the phrase survived. Of the
#: 619 groups that failed this check before, 149 carry a glued "ofalltaxes"-shaped
#: run, 153 an "incl"-shaped one and 230 something tax-shaped, so the cheapest
#: honest reading of the corpus needs all three.
_TAX_PHRASE_FULL = "inclusive of all taxes"
_TAX_PHRASE_CORE = "of all taxes"
_TAX_FULL_CUT = 0.78
_TAX_CORE_CUT = 0.82
#: "TAXES" with the usual glyph substitutions: TAXES, T4XES, TOXES, TAKES, TAXS.
#: An 's' or the correct spelling is required so that ordinary letter runs such as
#: "tok" in a brand name cannot stand in for the word.
_TAX_TOKEN = re.compile(r"t[a4o][xk]e?s|tax")
#: "INCL" / "IND" / "INC" / "LNC" - what is left of "inclusive" on a two-line
#: dot-matrix print. Only meaningful next to a tax token, hence the reach below.
_INCL_CUE = re.compile(r"[il1]n[cdgo]")
_INCL_REACH = 10
#: "OF ALL" with f/t and l/1/i confusions, used when "taxes" itself was dropped.
_OF_ALL_CUE = re.compile(r"o[frt]a[il1][il1]")
_OF_ALL_REACH = 6


def _tax_clause(text: str) -> str | None:
    """Which surviving form of "inclusive of all taxes" the label carries."""

    if _phrase_score(text, _TAX_PHRASE_FULL) >= _TAX_FULL_CUT:
        return "inclusive of all taxes"
    if _phrase_score(text, _TAX_PHRASE_CORE, slack=3) >= _TAX_CORE_CUT:
        return "of all taxes"
    letters = _letters(text)
    # 'taxes) (incl. of all' - joining the annotated boxes can present the two
    # halves of the clause in either order, so both sides are searched.
    for tax in _TAX_TOKEN.finditer(letters):
        window = letters[max(0, tax.start() - _INCL_REACH) : tax.end() + _INCL_REACH]
        if _INCL_CUE.search(window):
            return "incl. taxes"
    # '(Incl. of all USP SD 20.00' - the word "taxes" was lost entirely, but
    # "inclusive of all" introduces nothing else on a price panel.
    for incl in _INCL_CUE.finditer(letters):
        if _OF_ALL_CUE.search(letters[incl.end() : incl.end() + _OF_ALL_REACH]):
            return "incl. of all"
    return None


def parse_mrp_declaration(text: str) -> dict[str, Any]:
    extracted = clean_ocr_text(text)
    marker = bool(_MRP_MARKER.search(extracted))
    marker = marker or _RUPEE in extracted or _MOJIBAKE_RUPEE in extracted
    amounts = [
        (_number(match.group(1)), match.start(), match.end())
        for match in (_AMOUNT.finditer(extracted) if marker else [])
    ]
    price_pair = _PRICE_PAIR.search(extracted)
    unit_rate = _UNIT_RATE.search(extracted)
    usp_markers = list(_USP_MARKER.finditer(extracted))
    last_usp_marker = usp_markers[-1] if usp_markers else None
    # "Rs.74/100g" is one rate, not two prices, so the pair rule must stand down
    # wherever the two overlap.
    pair_is_a_rate = (
        price_pair is not None
        and unit_rate is not None
        and price_pair.start() < unit_rate.end()
        and unit_rate.start() < price_pair.end()
    )
    pair_is_reliable = bool(price_pair) and not pair_is_a_rate and not (
        last_usp_marker is not None and last_usp_marker.start() > price_pair.end()
    )

    mrp: float | None = None
    unit_sale_price: float | None = None
    if pair_is_reliable and price_pair is not None:
        mrp = _number(price_pair.group("mrp"))
        unit_sale_price = _number(price_pair.group("usp"))
    elif pair_is_a_rate and unit_rate is not None:
        unit_sale_price = _number(unit_rate.group("rate"))
        # The MRP is the largest amount outside the rate itself: it is the price of
        # the whole pack, so it cannot be smaller than the price of one unit of it.
        outside = [
            item for item in amounts
            if item[2] <= unit_rate.start() or item[1] >= unit_rate.end()
        ]
        if outside:
            mrp = max(item[0] for item in outside)
        if mrp is not None and unit_sale_price > mrp:
            mrp, unit_sale_price = unit_sale_price, mrp
    elif last_usp_marker is not None:
        before_usp = [item for item in amounts if item[2] <= last_usp_marker.start()]
        after_usp = [item for item in amounts if item[1] >= last_usp_marker.end()]
        if before_usp:
            mrp = before_usp[-1][0]
        elif amounts:
            mrp = amounts[0][0]
        if after_usp:
            unit_sale_price = after_usp[0][0]
            context = extracted[last_usp_marker.start() : after_usp[0][2]]
            if (
                unit_sale_price >= 10
                and unit_sale_price < 100
                and re.search(r"#\s*[u0o]\s*\d{1,2}\s*$", context, re.IGNORECASE)
            ):
                unit_sale_price /= 100
    elif amounts:
        mrp = amounts[0][0]
        if len(amounts) > 1:
            unit_sale_price = amounts[1][0]
    unit_match = _USP_UNIT.search(extracted)
    tax_clause = _tax_clause(extracted) if marker else None
    # A pack cannot lawfully declare a price of nothing, and "MRP Rs. 00" is what
    # a dropped digit looks like, so a zero is reported but never passes.
    compliant = bool(marker and mrp is not None and mrp > 0 and tax_clause is not None)
    return _result(
        extracted,
        {
            "mrp": _scalar(mrp),
            "currency": "INR" if marker else None,
            "unit_sale_price": _scalar(unit_sale_price),
            "usp_unit": unit_match.group("unit").lower() if unit_match else None,
            "tax_clause": tax_clause,
        },
        compliant,
    )

# ---------------------------------------------------------------------------
# 8. the date declarations
# ---------------------------------------------------------------------------

_MONTH_NAMES = (
    r"jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|"
    r"aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?"
)
_DATE = re.compile(
    rf"(?<!\d)(?:(?P<day>\d{{1,2}})\s*[/.-]\s*(?P<month>\d{{1,2}}|{_MONTH_NAMES})"
    r"\s*[/.-]\s*(?P<year>\d{2,4})"
    r"|(?P<month_only>\d{1,2})\s*[/.-]\s*(?P<year_only>\d{2,4}))(?!\d)",
    re.IGNORECASE,
)
_DATE_ISO = re.compile(
    r"(?<!\d)(?P<year_iso>\d{4})\s*[-/.]\s*(?P<month_iso>\d{1,2})"
    r"\s*[-/.]\s*(?P<day_iso>\d{1,2})(?!\d)",
    re.IGNORECASE,
)
_DATE_MONTH_WORD = re.compile(
    rf"(?<![\w/.-])(?:(?P<day_word>\d{{1,2}})\s+)?(?P<month_word>{_MONTH_NAMES})"
    r"\s*[-/. ]\s*(?P<year_word>\d{2,4})(?!\d)",
    re.IGNORECASE,
)
_DATE_ALPHA = re.compile(
    r"(?<!\d)(?P<day_alpha>\d{1,2})[-\s/]*(?P<month_alpha>[A-Za-z]{3})"
    r"[-\s/]*(?P<year_alpha>\d{2,4})(?!\d)",
    re.IGNORECASE,
)
_MONTHS = {
    "jan": 1, "january": 1, "feb": 2, "february": 2, "mar": 3, "march": 3,
    "apr": 4, "april": 4, "may": 5, "jun": 6, "june": 6, "jul": 7, "july": 7,
    "aug": 8, "august": 8, "sep": 9, "sept": 9, "september": 9, "oct": 10,
    "october": 10, "nov": 11, "november": 11, "dec": 12, "december": 12,
}
#: How far a printed date may sit from today and still be believable. Wide enough
#: for a five-year shelf life and for stock photographed years ago, tight enough
#: to throw out a misread year - "13/07/2091", "30/07/1926" - before it becomes
#: the pack's expiry date.
_YEAR_REACH = 15

def _date_from_match(match: re.Match[str]) -> date | None:
    groups = match.groupdict()
    try:
        if groups.get("day_iso") is not None:
            day = int(groups["day_iso"])
            month = int(groups["month_iso"])
            year = int(groups["year_iso"])
        elif groups.get("day_alpha") is not None:
            day = int(groups["day_alpha"])
            month = _MONTHS[groups["month_alpha"].lower()]
            year = int(groups["year_alpha"])
        elif groups.get("day_word") is not None or groups.get("month_word") is not None:
            day = int(groups.get("day_word") or 1)
            month = _MONTHS[groups["month_word"].lower()]
            year = int(groups["year_word"])
        elif groups.get("day") is not None:
            day = int(groups["day"])
            month_token = groups["month"].lower()
            month = int(month_token) if month_token.isdigit() else _MONTHS[month_token]
            year = int(groups["year"])
        else:
            day = 1
            month = int(groups["month_only"])
            year = int(groups["year_only"])
        if year < 100:
            year += 2000 if year <= 68 else 1900
        return date(year, month, day)
    except (KeyError, TypeError, ValueError):
        return None


def _plausible(value: date) -> bool:
    this_year = date.today().year
    return this_year - _YEAR_REACH <= value.year <= this_year + _YEAR_REACH

#: The label that says what a date *is*. Neither end is anchored on a word
#: boundary: dot-matrix print runs the label into the date ("PKD12/08/2026") and
#: into the word before it ("DATEOFPKG", "ftomMFD"), and \b never matches between
#: two letters. Where a short form could hide inside an ordinary word the
#: alternative carries its own guard - "exp" must not fire on "EXPORT".
_PACKED_PREFIX = (
    r"date\W{0,3}of\W{0,3}(?:pack\w*|manufactur\w*|mfg|mfd)"
    r"|pack(?:ed|ing|aging)?(?:\W{0,3}on)?|p\W?k\W?[dgt]"
    r"|manufactur\w*|m\W?f\W?[dg]|(?<![a-z])m\W?[il]\W?[dg](?![a-z])"
    r"|(?<![a-z])dom(?![a-z])"
)
_EXPIRY_PREFIX = (
    r"best\W{0,3}be[fl]?[o0]re|best\W{0,3}betore"
    r"|use\W{0,3}(?:by|[a-z]?\W{0,3}be[fl]?[o0]re)"
    r"|see\W{0,3}by|consume\W{0,3}(?:by|be[fl]?[o0]re)"
    r"|exp(?![aeo])\w*|(?<![a-z])bbe(?![a-z])|(?<![a-z])ubd(?![a-z])"
)
_DATE_PREFIX = re.compile(
    rf"(?P<packed>{_PACKED_PREFIX})|(?P<expiry>{_EXPIRY_PREFIX})",
    re.IGNORECASE,
)

#: A shelf life stated as a duration instead of a date: "36MONTHSFROMMANUFACTURED",
#: "Use ebefore36Months from MFD", "BEST BEFORE 9O DAYS FROM MANUFACTURING" - the
#: count itself is often glyph-damaged, hence the O/Q/l alternatives.
_DURATION = re.compile(
    r"(?<![\d.])(?P<count>[\dOoQq|lI]{1,3})\s*"
    # No trailing boundary: "36MONTHSFROMMANUFACTURED" is one word by the time
    # the recognizer is done with it, so a guard here would reject the very
    # strings this pattern exists for.
    r"(?P<period>months?|mnths?|mths?|days?|dys?|years?|yrs?)",
    re.IGNORECASE,
)
_DURATION_GLYPHS = str.maketrans({"O": "0", "o": "0", "Q": "0", "q": "0", "I": "1", "l": "1", "|": "1"})
#: "from the date of manufacture", with the misreads the corpus actually contains
#: ("daysatrom date or mtg", "onthsftomMFD", "2yearsfrom packed on").
_SHELF_LIFE_ANCHOR = re.compile(
    r"(?:from|[fa]?t?[rf]om|ftom|faom|atrom|trom)\W{0,3}(?:the\W{0,3})?"
    r"(?:date\W{0,3}of\W{0,3})?"
    r"(?:manufactur\w*|m\W?f\W?[dg]|mtg|pack\w*|p\W?k\W?[dg])",
    re.IGNORECASE,
)
#: A duration only declares a shelf life if something says so. Without this a
#: batch code like "24" next to the word "days" would read as a lawful date.
_SHELF_LIFE_INTENT = re.compile(
    rf"(?:{_EXPIRY_PREFIX})|shelf\W{{0,3}}life|consume\W{{0,3}}within",
    re.IGNORECASE,
)

def _dates_in(text: str) -> list[tuple[int, int, date]]:
    """Every plausible calendar date in ``text``, as (start, end, value).

    The four shape patterns overlap: "2026-06-20" is one ISO date, but the plain
    pattern also sees "06-20" inside it and reads that as June 2020. Keeping the
    longest match at each position and dropping anything that overlaps it removes
    those phantoms - which matters more now than it used to, because the earliest
    and latest dates on the pack are what decide packing versus expiry below.
    """

    found: list[tuple[int, int, date]] = []
    for pattern in (_DATE_ISO, _DATE_ALPHA, _DATE, _DATE_MONTH_WORD):
        for match in pattern.finditer(text):
            value = _date_from_match(match)
            if value is not None and _plausible(value):
                found.append((match.start(), match.end(), value))
    found.sort(key=lambda item: (item[0], -item[1]))
    kept: list[tuple[int, int, date]] = []
    for start, end, value in found:
        if any(start < other_end and end > other_start for other_start, other_end, _ in kept):
            continue
        kept.append((start, end, value))
    return kept


#: How far a label may sit from its date. The old rule looked forward only, from
#: eight characters behind the label to eighty ahead; but join_readings
#: concatenates boxes in detection order, so on 61 of the failing groups the value
#: arrives before its label ("30- 07-2026 Mfg date", "13/07/26 12/07/29 Date aMg").
#: Reach the same distance both ways instead.
_PREFIX_REACH = 90


def _prefix_distance(prefix: re.Match[str], start: int, end: int) -> int:
    if start >= prefix.end():
        return start - prefix.end()
    if end <= prefix.start():
        return prefix.start() - end
    return 0

def _label_dates(
    dates: list[tuple[int, int, date]], prefixes: list[re.Match[str]]
) -> tuple[list[date], list[date], list[date]]:
    """Split dates into packed, expiry and unlabelled by the label nearest each."""

    if not prefixes:
        return [], [], [value for _, _, value in dates]
    kinds = {"packed" if prefix.group("packed") else "expiry" for prefix in prefixes}
    values = sorted({value for _, _, value in dates})
    if kinds == {"packed", "expiry"} and len(values) >= 2:
        # Both kinds of label and at least two distinct dates: the pack states a
        # packing date and an expiry, and chronology settles which is which far
        # more reliably than proximity does once the boxes have been concatenated
        # out of order. Only the extremes are claimed - anything between them is
        # left unlabelled rather than guessed at.
        return [values[0]], [values[-1]], values[1:-1]
    packed: list[date] = []
    expiry: list[date] = []
    unlabelled: list[date] = []
    for start, end, value in dates:
        nearest: re.Match[str] | None = None
        nearest_distance = _PREFIX_REACH + 1
        for prefix in prefixes:
            distance = _prefix_distance(prefix, start, end)
            if distance < nearest_distance:
                nearest, nearest_distance = prefix, distance
        if nearest is None:
            unlabelled.append(value)
        elif nearest.group("packed"):
            packed.append(value)
        else:
            expiry.append(value)
    return packed, expiry, unlabelled

def _shelf_life(text: str) -> str | None:
    """The shelf life when the pack prints a duration instead of a second date."""

    for duration in _DURATION.finditer(text):
        count = duration.group("count").translate(_DURATION_GLYPHS)
        if not count.isdigit() or not 1 <= int(count) <= 999:
            continue
        anchor = next(
            (
                candidate
                for candidate in _SHELF_LIFE_ANCHOR.finditer(text)
                if _prefix_distance(candidate, duration.start(), duration.end()) <= 30
            ),
            None,
        )
        intent = _SHELF_LIFE_INTENT.search(text)
        if anchor is None and intent is None:
            continue
        # The reported string is the duration and the phrase it hangs off, not a
        # hull over the whole region: 'best before' elsewhere in the text only
        # grants permission to read the duration as a shelf life.
        start, end = duration.start(), duration.end()
        if anchor is not None:
            start = min(start, anchor.start())
            end = max(end, anchor.end())
        return re.sub(r"\s+", " ", text[start:end]).strip(" .:,-") or None
    return None


def parse_date_declarations(text: str) -> dict[str, Any]:
    extracted = clean_ocr_text(text)
    prefixes = list(_DATE_PREFIX.finditer(extracted)) if _usable_text(extracted) else []
    dates = _dates_in(extracted) if _usable_text(extracted) else []
    packed, expiry, unlabelled = _label_dates(dates, prefixes)
    best_before_raw = _shelf_life(extracted) if _usable_text(extracted) else None
    manufacture_date = min(packed).isoformat() if packed else None
    expiry_date = max(expiry).isoformat() if expiry else None
    # Rule 6 requires the date to say what it is, so an unlabelled date cannot
    # satisfy the check. It is still reported: an inspector looking at a pack
    # whose label the recognizer dropped needs to see that a date was printed.
    compliant = bool(manufacture_date or expiry_date or best_before_raw)
    return _result(
        extracted,
        {
            "manufacture_or_packaging_date": manufacture_date,
            "expiry_or_use_by_date": expiry_date,
            "best_before_raw": best_before_raw,
            "unlabelled_dates": ", ".join(value.isoformat() for value in sorted(set(unlabelled))) or None,
        },
        compliant,
    )

# ---------------------------------------------------------------------------
# 9. Batch / lot number
# ---------------------------------------------------------------------------
#: ``B.N.`` and ``B/No`` are as common on Indian packs as the full word, and the
#: recognizer often drops the periods, so the abbreviation is matched separately.
_BATCH = re.compile(
    r"\b(?:(?:batch|lot)\s*(?:no\.?|number|code|id)?|b\s*[./]?\s*n\.?)"
    r"\s*[:#.-]*\s*(?P<body>[^\r\n;|]+)",
    re.IGNORECASE,
)
#: A packing time printed next to the code, e.g. ``BATCH A21 09:45``. It is kept
#: as its own field so it does not end up inside the batch number.
_TIME = re.compile(r"\b\d{1,2}:\d{2}(?::\d{2})?\b")


def parse_batch_number(text: str) -> dict[str, Any]:
    extracted = clean_ocr_text(text)
    batch_number: str | None = None
    timestamp: str | None = None
    match = _BATCH.search(extracted) if _usable_text(extracted) else None
    if match:
        body = match.group("body").strip()
        next_label = re.search(
            r"\b(?:mfg|mfd|manufactured|exp|expiry|pkd|pkg|packed|use\s*by|best\s*before)\b",
            body,
            re.IGNORECASE,
        )
        if next_label:
            body = body[: next_label.start()].strip()
        time_match = _TIME.search(body)
        if time_match:
            timestamp = time_match.group(0)
            body = f"{body[:time_match.start()]} {body[time_match.end():]}"
        batch_number = re.sub(r"\s+", " ", body).strip(" .,:;#/()-") or None
    compliant = bool(batch_number and re.search(r"[A-Za-z0-9]", batch_number))
    return _result(extracted, {"batch_number": batch_number, "timestamp": timestamp}, compliant)

# ---------------------------------------------------------------------------
# 10. Manufacturer / packer / importer
# ---------------------------------------------------------------------------
#: Rule 6(1)(a) accepts any of the roles below, so the parser must not privilege
#: the word "manufactured". In the corpus 202 of the 396 failing manufacturer
#: regions carry a pincode - the address is being read - but only 86 matched the
#: old marker, which required one of nine fully-spelled forms.
_PINCODE = re.compile(r"(?<!\d)[1-9]\d{2}\s?\d{3}(?!\d)")
_ROLE_VERBS = (
    r"manufactur(?:ed|er|ing|e)?(?:\W{0,3}(?:and|&)\W{0,3}market(?:ed|er|ing|e)?)?"
    r"|m\W?f\W?[dg]|mfr|mnf"
    r"|market(?:ed|er|ing|e)?|mktd|mktg|mkt"
    r"|re\W?pack(?:ed|ing)?|pack(?:ed|er|ing)?|pkd|pkg"
    r"|import(?:ed|er)?|export(?:ed|er)?"
    r"|distribut(?:ed|or|er|ors|ers)?|dist"
    r"|produced|processed|blended|bottled|assembled"
)
#: ``(?-i:(?![a-z]))`` turns case-insensitivity off for the guard alone: the pack
#: prints ``PACKED BY HINDUSTAN`` with the space lost, so an upper-case letter has
#: to be allowed after "by", while the lower-case tail of an address word such as
#: "Mkt Bypass Road" must still be rejected. An all-capitals region defeats that
#: guard, so the one address word that actually starts with "by" is named.
_ROLE_MARKER = re.compile(
    rf"(?<![A-Za-z])(?P<kind>{_ROLE_VERBS})"
    r"\W{0,4}(?:in\W{0,3}india\W{0,3}|for\W{0,3})?(?:and|&)?\W{0,4}"
    r"(?:by|bv)(?!pass)(?-i:(?![a-z]))\s*[:#.\-]?\s*",
    re.IGNORECASE,
)
#: A bare field label ends the address just as a second role verb does.
_NEXT_FIELD = re.compile(
    r"\b(?:mfg|mfd|exp|expiry|pkd|pkg|batch|lot|mrp|usp|fssai|"
    r"net\s*(?:wt|weight|qty|quantity)|best\s*before|use\s*by)\b",
    re.IGNORECASE,
)


def _role_type(kind: str) -> str:
    letters = re.sub(r"[^a-z]", "", kind.lower())
    if letters.startswith("import"):
        return "imported_by"
    if letters.startswith("export"):
        return "exported_by"
    if "manufactur" in letters and "market" in letters:
        return "manufactured_and_marketed_by"
    if letters.startswith(("market", "mkt")):
        return "marketed_by"
    if letters.startswith(("pack", "repack", "pkd", "pkg")):
        return "packed_by"
    if letters.startswith(("distribut", "dist")):
        return "distributed_by"
    return "manufactured_by"

def _entity_name(text: str) -> str | None:
    """The readable part of an address slice, or None when nothing legible is left."""

    name = re.sub(r"\s+", " ", text).strip(" .,:;/-")
    return name if re.search(r"[A-Za-z]{2,}", name) else None


def parse_manufacturer_details(text: str) -> dict[str, Any]:
    extracted = clean_ocr_text(text)
    usable = _usable_text(extracted)
    pincode_match = _PINCODE.search(extracted) if usable else None
    pincode = re.sub(r"\D", "", pincode_match.group(0)) if pincode_match else None
    name: str | None = None
    entity_type: str | None = None
    marker = _ROLE_MARKER.search(extracted) if usable else None
    if marker:
        entity_type = _role_type(marker.group("kind"))
        whole_tail = extracted[marker.end() :]
        tail = whole_tail
        for boundary in (_ROLE_MARKER.search(tail), _NEXT_FIELD.search(tail)):
            if boundary is not None:
                tail = tail[: boundary.start()]
        # The address normally runs up to the pincode. Once the boxes are joined
        # the pincode can land first, so the truncated slice is only preferred
        # when something legible survives it.
        truncated = tail
        if pincode_match is not None and pincode_match.start() >= marker.end():
            truncated = tail[: pincode_match.start() - marker.end()]
        # 'HINDUSTAN FOODS LTD ... MFD BY' - the joined region often puts the
        # company above the role verb, so the text before it is the last resort.
        for candidate in (truncated, tail, whole_tail, extracted[: marker.start()]):
            name = _entity_name(candidate)
            if name is not None:
                break
    compliant = bool(name and pincode and entity_type)
    return _result(
        extracted,
        {"name": name, "pincode": pincode, "type": entity_type},
        compliant,
    )

# ---------------------------------------------------------------------------
# 11. Consumer care and FSSAI licence
# ---------------------------------------------------------------------------
_EMAIL = re.compile(
    r"(?<![\w.+-])[\w.!#$%&'*+/=?^`{|}~-]+@"
    r"[A-Za-z0-9](?:[A-Za-z0-9.-]{0,61}[A-Za-z0-9])?"
    r"\.[A-Za-z]{2,}(?![\w.-])",
)
#: ``care@brandfoods.com`` with the '@' dropped by the recognizer still names the
#: support address, and Rule 6 asks for a contact, not a parseable mailbox.
_RUN_ON_EMAIL = re.compile(
    r"(?<![\w.-])[A-Za-z0-9][A-Za-z0-9._-]*(?:com|in|org|net|coop)\b",
    re.IGNORECASE,
)
#: A licence is exactly 14 digits. When it is printed hard against another number
#: the exact match fails, so an anchored fallback takes a longer run apart.
_FSSAI = re.compile(r"(?<!\d)(?P<license>\d(?:[\s-]*\d){13})(?!\d)")
#: "FSSAI" and "Lic No" as the recognizer actually returns them: "F8SAI" with the
#: second S read as an 8, "F.S.S.A.I" spaced out, "LcNa" for "Lic No". The digit
#: class inside the acronym is what makes the anchor survive those reads.
_FSSAI_ANCHOR = re.compile(
    r"f[\W\d]{0,2}s[\W\d]{0,2}s?[\W\d]{0,2}a[\W\d]{0,2}[il1]"
    r"|ssai|fssa"
    r"|l[il1]?c[\W\d]{0,3}(?:n[o0a]|number)?",
    re.IGNORECASE,
)
_LONG_DIGIT_RUN = re.compile(r"\d(?:[\s-]*\d){13,}")
_FSSAI_REACH = 24
_TOLL_FREE = re.compile(
    r"(?<!\d)(?:\+?91[\s-]*)?(?P<number>(?:1800|1860)(?:[\s-]*\d){6,10})(?!\d)",
    re.IGNORECASE,
)
_PHONE = re.compile(
    r"(?<!\d)(?:\+?91[\s-]*)?(?P<number>[6-9]\d{4}[\s-]?\d{5})(?!\d)",
)


def _licence_number(text: str) -> str | None:
    exact = _FSSAI.search(text)
    if exact:
        return re.sub(r"\D", "", exact.group("license"))
    for anchor in _FSSAI_ANCHOR.finditer(text):
        run = _LONG_DIGIT_RUN.search(text, anchor.end(), anchor.end() + _FSSAI_REACH + 20)
        if run is None or run.start() - anchor.end() > _FSSAI_REACH:
            continue
        digits = re.sub(r"\D", "", run.group(0))
        # Digit 1 of a licence is the state code, so a window that starts with a
        # plausible one is the better guess when the run is over-long.
        offset = next((index for index in range(len(digits) - 13) if digits[index] in "12"), 0)
        return digits[offset : offset + 14]
    return None

def parse_consumer_care_fssai(text: str, additional_text: str = "") -> dict[str, Any]:
    extracted = clean_ocr_text(text)
    searchable_text = clean_ocr_text(f"{extracted} {additional_text}")
    license_number = _licence_number(searchable_text) if _usable_text(searchable_text) else None
    email_text = re.sub(r"\s*@\s*", "@", searchable_text)
    email_match = _EMAIL.search(email_text) if _usable_text(email_text) else None
    if email_match is None and _usable_text(searchable_text):
        email_match = _RUN_ON_EMAIL.search(searchable_text)
    email = email_match.group(0) if email_match else None
    toll_match = _TOLL_FREE.search(searchable_text) if _usable_text(searchable_text) else None
    phone_match = _PHONE.search(searchable_text) if toll_match is None and _usable_text(searchable_text) else None
    phone_source = toll_match or phone_match
    helpline = re.sub(r"\D", "", phone_source.group("number")) if phone_source else None
    compliant = bool(license_number and email and helpline)
    return _result(
        extracted,
        {
            "fssai_license_number": license_number,
            "helpline_number": helpline,
            "email": email,
        },
        compliant,
    )

# ---------------------------------------------------------------------------
# 12. Dietary mark (advisory)
# ---------------------------------------------------------------------------
#: The veg / non-veg mark is required by the FSS (Labelling and Display)
#: Regulations, not by LMPC Rule 6, so a missing mark is reported but carries no
#: Legal Metrology penalty. Keeping its penalty at zero stops it from inflating
#: the Rule 6 exposure figure the inspector reports.
_DIETARY_REASONS = {
    "VEG": "Green vegetarian mark detected on the pack.",
    "NON_VEG": "Brown/maroon non-vegetarian mark detected on the pack.",
    "UNCERTAIN": "A dietary mark was located but its colour was inconclusive - verify visually.",
    "NOT_DETECTED": "No dietary mark was located on the supplied panels.",
}


def validate_dietary_mark(dietary_status: str | None) -> dict[str, Any]:
    """Report the veg / non-veg mark as an advisory (non-penalising) row."""

    status = (dietary_status or "NOT_DETECTED").upper()
    if status not in _DIETARY_REASONS:
        status = "NOT_DETECTED"
    result: dict[str, Any] = {
        "extracted_text": None if status == "NOT_DETECTED" else status,
        "dietary_status": status,
        "is_compliant": status in {"VEG", "NON_VEG"},
        "penalty_amount": 0,
        "reason": _DIETARY_REASONS[status],
        "statute": "FSS (Labelling and Display) Regulations 2020, reg. 5(3) - advisory here",
    }
    return result

# ---------------------------------------------------------------------------
# 13. Entry point
# ---------------------------------------------------------------------------
def validate_compliance(aggregated_data: dict[str, Any], dietary_status: str | None = None) -> dict[str, dict[str, Any]]:
    if not isinstance(aggregated_data, dict):
        aggregated_data = {}
    all_regions_text = clean_ocr_text(
        " ".join(_coerce_text(value) for value in aggregated_data.values())
    )
    product_name = parse_product_name(_region_text(aggregated_data, "product_name"))
    net_quantity = parse_net_quantity(_region_text(aggregated_data, "net_quantity"))
    pricing = parse_mrp_declaration(_region_text(aggregated_data, "mrp_declaration"))
    dates = parse_date_declarations(_region_text(aggregated_data, "date_declarations"))
    batch_source = _region_text(aggregated_data, "batch_number")
    if not batch_source:
        batch_source = _region_text(aggregated_data, "date_declarations")
    batch_details = parse_batch_number(batch_source)
    manufacturer_info = parse_manufacturer_details(
        _region_text(aggregated_data, "manufacturer_details")
    )
    # The licence number is frequently printed in the manufacturer block rather
    # than beside the care line, so this one class is allowed to see the whole
    # pack. Every other class is judged on its own region only.
    compliance_and_support = parse_consumer_care_fssai(
        _region_text(aggregated_data, "consumer_care_fssai"),
        additional_text=all_regions_text,
    )
    return {
        "product_name": product_name,
        "net_quantity": net_quantity,
        "pricing": pricing,
        "dates": dates,
        "batch_details": batch_details,
        "manufacturer_info": manufacturer_info,
        "compliance_and_support": compliance_and_support,
        "dietary_mark": validate_dietary_mark(dietary_status),
    }
