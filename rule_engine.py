from __future__ import annotations

from datetime import date
import re
from typing import Any

import Levenshtein


FIRST_OFFENSE_PENALTY = 25_000

_NO_TEXT = re.compile(r"^no\s+text(?:\s+detected)?[.!]?$", re.IGNORECASE)
_INVALID_UNITS = re.compile(
    r"(?<!\w)\d+(?:[.,]\d+)?\s*(?:kgs|gms|gm|mls|ls|mgs)(?!\w)",
    re.IGNORECASE,
)
_QUANTITY = re.compile(
    r"(?<!\w)(?P<value>\d+(?:[.,]\d+)?)\s*"
    r"(?P<unit>kgs?|gms?|gm|mls?|ls?|mgs?|"
    r"kilograms?|grams?|millilit(?:er|re)s?|lit(?:er|re)s?|"
    r"milligrams?|kg|mg|ml|cl|l|cm|m|g|q|9|"
    r"pieces?|pcs?|nos?|units?|count|n|tabs?|tablets?|capsules?|sachets?)(?!\w)",
    re.IGNORECASE,
)
_MULTIPACK = re.compile(
    r"(?<!\w)(?P<units>\d+(?:[.,]\d+)?)\s*"
    r"(?:(?:packs?|pkts?|units?)|u)?\s*[x\u00d7*]\s*"
    r"(?P<weight>\d+(?:[.,]\d+)?)\s*"
    r"(?P<unit>kgs?|gms?|gm|mls?|ls?|mgs?|"
    r"kilograms?|grams?|millilit(?:er|re)s?|lit(?:er|re)s?|"
    r"milligrams?|kg|mg|ml|cl|l|cm|m|g|q|9)(?!\w)",
    re.IGNORECASE,
)
_CURRENCY_PREFIX = r"(?:MRP|USP|#|U|V|₹|Rs\.?|INR)"
_AMOUNT = re.compile(r"(?<!\w)(\d{1,4}(?:[., ]\d{2})?)(?!\w)")
_PRICE_PAIR = re.compile(
    r"(?P<mrp>\d{1,4}(?:[., ]\d{2})?)\s*/\s*\(?\s*"
    r"(?P<usp>\d{1,4}(?:[., ]\d{2})?)",
    re.IGNORECASE,
)
_USP_MARKER = re.compile(r"\bUSP\b", re.IGNORECASE)
_DATE_ALPHA = re.compile(
    r"(?<!\d)(?P<day_alpha>\d{1,2})[-\s/]*(?P<month_alpha>[A-Za-z]{3})"
    r"[-\s/]*(?P<year_alpha>\d{2,4})(?!\d)",
    re.IGNORECASE,
)
_USP_UNIT = re.compile(
    r"(?:rs\.?\s*(?:per|/)\s*(?:\d+(?:[.,]\d+)?\s*)?|"
    r"per\s*(?:\d+(?:[.,]\d+)?\s*)?|/\s*(?:\d+(?:[.,]\d+)?\s*)?)"
    r"(?P<unit>kg|mg|g|ml|cl|l)\b",
    re.IGNORECASE,
)
_DATE = re.compile(
    r"(?<!\d)(?:(?P<day>\d{1,2})\s*[/.-]\s*"
    r"(?P<month>\d{1,2}|jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|"
    r"may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|"
    r"nov(?:ember)?|dec(?:ember)?)\s*[/.-]\s*(?P<year>\d{2,4})"
    r"|(?P<month_only>\d{1,2})\s*[/.-]\s*(?P<year_only>\d{2,4}))(?!\d)",
    re.IGNORECASE,
)
_DATE_ISO = re.compile(
    r"(?<!\d)(?P<year_iso>\d{4})\s*[-/.]\s*(?P<month_iso>\d{1,2})"
    r"\s*[-/.]\s*(?P<day_iso>\d{1,2})(?!\d)",
    re.IGNORECASE,
)
_DATE_MONTH_WORD = re.compile(
    r"(?<![\w/.-])(?:(?P<day_word>\d{1,2})\s+)?"
    r"(?P<month_word>jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|"
    r"jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|"
    r"nov(?:ember)?|dec(?:ember)?)\s*[-/. ]\s*(?P<year_word>\d{2,4})(?!\d)",
    re.IGNORECASE,
)
_DATE_PREFIX = re.compile(
    r"\b(?P<prefix>pkd|pkg|pkt|packed(?:\s+on)?|date\s*of\s*(?:packing|packaging|manufacture)|"
    r"mfg|mfd|manufactured|dom|exp|expiry|use\s*(?:by|before)|best\s*before|bbe)"
    # Not \b: dot-matrix over-print runs the label into the date ("PKD12/08/2026"),
    # and \b never matches between a letter and a digit.
    r"(?![A-Za-z])",
    re.IGNORECASE,
)
_BEST_BEFORE_DURATION = re.compile(
    r"\bbest\s*before\s+\d+\s+(?:days?|months?|years?)\s+from\s+"
    r"(?:the\s+)?(?:date\s+of\s+)?(?:manufacturing|manufacture|mfg|packing|packaging|pkg)\b[^\r\n;|]*",
    re.IGNORECASE,
)
_BATCH = re.compile(
    r"\b(?:(?:batch|lot)\s*(?:no\.?|number|code|id)?|b\s*[./]?\s*n\.?)"
    r"\s*[:#.-]*\s*(?P<body>[^\r\n;|]+)",
    re.IGNORECASE,
)
_TIME = re.compile(r"\b\d{1,2}:\d{2}(?::\d{2})?\b")
_PINCODE = re.compile(r"(?<!\d)[1-9]\d{2}\s?\d{3}(?!\d)")
_EMAIL = re.compile(
    r"(?<![\w.+-])[\w.!#$%&'*+/=?^`{|}~-]+@"
    r"[A-Za-z0-9](?:[A-Za-z0-9.-]{0,61}[A-Za-z0-9])?"
    r"\.[A-Za-z]{2,}(?![\w.-])",
)
_FSSAI = re.compile(r"(?<!\d)(?P<license>\d(?:[\s-]*\d){13})(?!\d)")
_TOLL_FREE = re.compile(
    r"(?<!\d)(?:\+?91[\s-]*)?(?P<number>(?:1800|1860)(?:[\s-]*\d){6,10})(?!\d)",
    re.IGNORECASE,
)
_PHONE = re.compile(
    r"(?<!\d)(?:\+?91[\s-]*)?(?P<number>[6-9]\d{4}[\s-]?\d{5})(?!\d)",
)
_RUN_ON_EMAIL = re.compile(
    r"(?<![\w.-])[A-Za-z0-9][A-Za-z0-9._-]*(?:com|in|org|net|coop)\b",
    re.IGNORECASE,
)

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

_RUPEE = "\u20b9"
_MOJIBAKE_RUPEE = "\u00e2\u201a\u00b9"
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
_PROTECTED_TOKENS = frozenset({
    "g", "gm", "gms", "kg", "kgs", "mg", "mgs", "ml", "mls", "cl", "l", "ls",
    "ltr", "ltrs", "liter", "liters", "litre", "litres", "mm", "cm", "m",
    "pc", "pcs", "no", "nos", "n", "u", "rs", "inr",
})
#: A number glued to a unit ("500ml", "1kg", "2OOg"). The head may still contain
#: OCR glyph confusions worth healing; the unit must survive untouched.
_NUMBER_WITH_UNIT = re.compile(
    r"(?i)^(?P<head>[\dOoIl|.,]+)(?P<unit>kgs?|mgs?|mls?|kg|mg|ml|cl|gms?|ltrs?|pcs?|nos?|[gl])$"
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


def clean_ocr_text(text: str) -> str:
    cleaned = _coerce_text(text)
    if not cleaned:
        return ""
    cleaned = cleaned.replace(_MOJIBAKE_RUPEE, _RUPEE)
    cleaned = cleaned.replace("\u00d7", "x")
    cleaned = cleaned.replace("×", "x")
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
    cleaned = re.sub(r"(?i)\b[A-Za-z0-9|]+\b", _heal_keyword, cleaned)
    cleaned = re.sub(r"(?<!\d)([A-Za-z0-9|]+)(?!\d)", _normalize_numeric_token, cleaned)
    cleaned = re.sub(r"[|;]+", ",", cleaned)
    cleaned = re.sub(r"\s*,\s*", ", ", cleaned)
    cleaned = re.sub(r"\s+", " ", cleaned).strip(" ,")
    return cleaned


_GLYPH_TO_DIGIT = str.maketrans({"O": "0", "o": "0", "I": "1", "l": "1", "|": "1"})


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
    token = token.translate(_GLYPH_TO_DIGIT)

    if digit_count >= len(token) - 1:
        token = token.translate(str.maketrans({"S": "5", "s": "5"}))
    return token


def _region_text(data: dict[str, Any], region: str, fallback: str = "") -> str:
    for alias in _ALIASES[region]:
        if alias in data:
            value = _coerce_text(data[alias])
            if value:
                return value
    return fallback


def _usable_text(text: str) -> bool:
    return bool(text) and not _NO_TEXT.fullmatch(text.strip())


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


def _unit(value: str) -> str | None:
    normalized = value.lower()
    if normalized in {"q", "9"}:
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
        "piece",
        "pieces",
        "pc",
        "pcs",
        "no",
        "nos",
        "unit",
        "units",
        "count",
        "n",
        "tab",
        "tabs",
        "tablet",
        "tablets",
        "capsule",
        "capsules",
        "sachet",
        "sachets",
    }:
        return "pcs"
    return None


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


def parse_product_name(text: str) -> dict[str, Any]:
    extracted = clean_ocr_text(text)
    if not _usable_text(extracted):
        return _result(extracted, {}, False)
    core = re.sub(
        r"^\s*(?:product(?:\s*name)?|common\s*name|generic\s*name|brand(?:\s*name)?|"
        r"name\s+of\s+(?:the\s+)?commodity|name)\s*[:#.-]?\s*",
        "",
        extracted,
        flags=re.IGNORECASE,
    ).strip(" -:;,.()")
    compliant = bool(re.search(r"[A-Za-z]{2,}", core)) and not bool(
        re.fullmatch(r"[\d\W_]+", core)
    )
    return _result(extracted, {}, compliant)


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


def _mrp_phrase_similarity(text: str) -> float:
    text = text.replace(_RUPEE, " ").replace(_MOJIBAKE_RUPEE, " ")
    normalized = text.lower().replace("₹", " ")
    normalized = re.sub(r"\b(?:incl|inclu|inc)\.?\b", "inclusive", normalized)
    normalized = re.sub(r"\b(?:tax|tx|txs|t4x|taks|taxe5)\b", "taxes", normalized)
    normalized = re.sub(r"\balll\b", "all", normalized)
    words = re.findall(r"[a-z]+", normalized)
    target = "inclusive of all taxes"
    best = 0.0
    for width in range(3, 7):
        for index in range(0, max(0, len(words) - width + 1)):
            candidate = " ".join(words[index : index + width])
            best = max(best, Levenshtein.ratio(candidate, target))
    return max(best, Levenshtein.ratio(normalized.strip(), target)) if normalized.strip() else 0.0


def parse_mrp_declaration(text: str) -> dict[str, Any]:
    extracted = clean_ocr_text(text)
    marker = bool(
        re.search(
            rf"(?:\bm\s*r\s*p\b|\bmaximum\s+retail\s+price\b|\bmax\.?\s+retail\s+price\b|"
            rf"\bretail\s+sale\s+price\b|(?<!\w){_CURRENCY_PREFIX}(?!\w))",
            extracted,
            re.IGNORECASE,
        )
    )
    marker = marker or _RUPEE in extracted or _MOJIBAKE_RUPEE in extracted
    amount_matches = list(_AMOUNT.finditer(extracted)) if marker else []
    amounts = [
        (_number(match.group(1)), match.start(), match.end())
        for match in amount_matches
    ]
    price_pair = _PRICE_PAIR.search(extracted)
    usp_markers = list(_USP_MARKER.finditer(extracted))
    last_usp_marker = usp_markers[-1] if usp_markers else None
    pair_is_reliable = bool(price_pair) and not (
        last_usp_marker is not None and last_usp_marker.start() > price_pair.end()
    )

    mrp: float | None = None
    unit_sale_price: float | None = None
    if pair_is_reliable and price_pair is not None:
        mrp = _number(price_pair.group("mrp"))
        unit_sale_price = _number(price_pair.group("usp"))
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
    phrase_similarity = _mrp_phrase_similarity(extracted) if marker else 0.0
    compliant = marker and mrp is not None and phrase_similarity > 0.85
    return _result(
        extracted,
        {
            "mrp": _scalar(mrp),
            "currency": "INR" if marker else None,
            "unit_sale_price": _scalar(unit_sale_price),
            "usp_unit": unit_match.group("unit").lower() if unit_match else None,
        },
        compliant,
    )


_MONTHS = {
    "jan": 1,
    "january": 1,
    "feb": 2,
    "february": 2,
    "mar": 3,
    "march": 3,
    "apr": 4,
    "april": 4,
    "may": 5,
    "jun": 6,
    "june": 6,
    "jul": 7,
    "july": 7,
    "aug": 8,
    "august": 8,
    "sep": 9,
    "sept": 9,
    "september": 9,
    "oct": 10,
    "october": 10,
    "nov": 11,
    "november": 11,
    "dec": 12,
    "december": 12,
}


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


def parse_date_declarations(text: str) -> dict[str, Any]:
    extracted = clean_ocr_text(text)
    manufacture_date: str | None = None
    expiry_date: str | None = None
    best_before_raw: str | None = None
    compliant = False
    prefix_matches = list(_DATE_PREFIX.finditer(extracted)) if _usable_text(extracted) else []
    duration_match = _BEST_BEFORE_DURATION.search(extracted) if _usable_text(extracted) else None
    if duration_match:
        best_before_raw = duration_match.group(0).strip(" .:-")
        compliant = True

    for date_pattern in (_DATE, _DATE_ISO, _DATE_MONTH_WORD, _DATE_ALPHA):
        for date_match in date_pattern.finditer(extracted):
            parsed = _date_from_match(date_match)
            if parsed is None:
                continue
            nearby_prefix = None
            nearby_distance = 10**9
            for prefix in prefix_matches:
                distance = date_match.start() - prefix.end()
                if -8 <= distance <= 80 and distance < nearby_distance:
                    nearby_prefix = re.sub(r"\s+", " ", prefix.group("prefix").lower())
                    nearby_distance = distance
            if nearby_prefix is None:
                continue
            # OCR frequently loses the space inside "USE BY" / "BEST BEFORE", so
            # the prefix is matched with optional whitespace rather than literal
            # spaces. Without this, "USEBY:10/11/2026" parses as no date at all.
            is_manufacture_prefix = bool(
                re.search(
                    r"(?:pkd|pkg|pkt|packed|date\s*of\s*(?:packing|packaging|manufacture)|"
                    r"mfg|mfd|manufactured|dom)",
                    nearby_prefix,
                )
            )
            is_expiry_prefix = bool(
                re.search(r"(?:exp|expiry|use\s*(?:by|before)|best\s*before|bbe)", nearby_prefix)
            )
            if is_manufacture_prefix:
                manufacture_date = parsed.isoformat()
                compliant = True
            elif is_expiry_prefix:
                expiry_date = parsed.isoformat()
                compliant = True

    return _result(
        extracted,
        {
            "manufacture_or_packaging_date": manufacture_date,
            "expiry_or_use_by_date": expiry_date,
            "best_before_raw": best_before_raw,
        },
        compliant,
    )


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


def parse_manufacturer_details(text: str) -> dict[str, Any]:
    extracted = clean_ocr_text(text)
    pincode_match = _PINCODE.search(extracted) if _usable_text(extracted) else None
    pincode = re.sub(r"\D", "", pincode_match.group(0)) if pincode_match else None
    entity: str | None = None
    entity_type: str | None = None
    marker = re.search(
        r"\b(?P<kind>manufactured\s*(?:and|&)\s*marketed\s*by|"
        r"manufactured\s+by|marketed\s+by|mfg\.?\s*by|mfd\.?\s*by|"
        r"packed\s+by|pkd\.?\s*by|distributed\s+by|imported\s+by)\b"
        r"\s*[:#.-]?\s*",
        extracted,
        re.IGNORECASE,
    ) if _usable_text(extracted) else None
    if marker:
        kind = marker.group("kind").lower()
        entity_type = "marketed_by" if "marketed" in kind else "manufactured_by"
        tail = extracted[marker.end() :]
        tail = re.split(
            r"\b(?:manufactured|marketed|packed|distributed|imported)\s+by\b|"
            r"\b(?:mfg|mfd|exp|pkd|pkg)\b",
            tail,
            maxsplit=1,
            flags=re.IGNORECASE,
        )[0]
        if pincode_match:
            tail = tail[: max(0, pincode_match.start() - marker.end())]
        entity = re.sub(r"\s+", " ", tail).strip(" .,:;/-") or None
    compliant = bool(entity and pincode and entity_type)
    return _result(
        extracted,
        {"pincode": pincode, "type": entity_type},
        compliant,
    )


def parse_consumer_care_fssai(text: str, additional_text: str = "") -> dict[str, Any]:
    extracted = clean_ocr_text(text)
    searchable_text = clean_ocr_text(f"{extracted} {additional_text}")
    license_match = _FSSAI.search(searchable_text) if _usable_text(searchable_text) else None
    license_number = re.sub(r"\D", "", license_match.group("license")) if license_match else None
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
