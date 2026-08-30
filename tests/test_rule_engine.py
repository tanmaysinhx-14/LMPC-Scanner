"""Rule-engine tests, with the OCR failures that motivated each fix.

Every case whose text looks mangled is a real reading taken from
``runs/ocr_benchmark`` or a live scan, not an invented string.
"""

from __future__ import annotations

import pytest

import rule_engine


class TestDateDeclarations:
    def test_glued_use_by_prefix_is_an_expiry(self):
        """RapidOCR returns 'USEBY:10/11/2026' - the space is lost in the print."""

        parsed = rule_engine.parse_date_declarations("USEBY:10/11/2026")
        assert parsed["expiry_or_use_by_date"] == "2026-11-10"
        assert parsed["manufacture_or_packaging_date"] is None

    def test_glued_packed_prefix_is_a_manufacture_date(self):
        """'PKD12/08/2026' - a word boundary never matches between D and 1."""

        parsed = rule_engine.parse_date_declarations("PKD12/08/2026")
        assert parsed["manufacture_or_packaging_date"] == "2026-08-12"
        assert parsed["expiry_or_use_by_date"] is None

    def test_spaced_prefixes_still_work(self):
        parsed = rule_engine.parse_date_declarations("PKD 12/08/2026 USE BY 10/11/2026")
        assert parsed["manufacture_or_packaging_date"] == "2026-08-12"
        assert parsed["expiry_or_use_by_date"] == "2026-11-10"
        assert parsed["is_compliant"] is True

    def test_alphabetic_month(self):
        parsed = rule_engine.parse_date_declarations("MFG 01JUN2026")
        assert parsed["manufacture_or_packaging_date"] == "2026-06-01"

    def test_no_date_is_not_compliant(self):
        parsed = rule_engine.parse_date_declarations("No text detected")
        assert parsed["is_compliant"] is False
        assert parsed["penalty_amount"] == rule_engine.FIRST_OFFENSE_PENALTY


class TestNetQuantity:
    @pytest.mark.parametrize(
        "text,value,unit",
        [
            ("Net Wt. 200 g", 200.0, "g"),
            ("NET QUANTITY 1 kg", 1.0, "kg"),
            ("500ml", 500.0, "ml"),
        ],
    )
    def test_units(self, text, value, unit):
        parsed = rule_engine.parse_net_quantity(text)
        assert parsed["value"] == pytest.approx(value)
        assert parsed["unit"] == unit

    def test_non_standard_unit_is_rejected(self):
        """LMPC Rule 6 requires SI symbols: 'gms' is not a lawful declaration."""

        parsed = rule_engine.parse_net_quantity("Net Wt 200 gms")
        assert parsed["is_compliant"] is False


class TestMrp:
    def test_inclusive_of_all_taxes(self):
        parsed = rule_engine.parse_mrp_declaration("MRP Rs. 29.00 (Inclusive of all Taxes)")
        assert parsed["mrp"] == pytest.approx(29.0)
        assert parsed["is_compliant"] is True

    def test_missing_tax_clause_fails(self):
        parsed = rule_engine.parse_mrp_declaration("MRP Rs. 29.00")
        assert parsed["mrp"] == pytest.approx(29.0)
        assert parsed["is_compliant"] is False


class TestDietaryMark:
    @pytest.mark.parametrize("status,compliant", [("VEG", True), ("NON_VEG", True),
                                                  ("UNCERTAIN", False), ("NOT_DETECTED", False)])
    def test_status_maps_to_a_row(self, status, compliant):
        result = rule_engine.validate_dietary_mark(status)
        assert result["is_compliant"] is compliant
        assert result["dietary_status"] == status

    def test_never_adds_penalty(self):
        """The mark is an FSS requirement, so it must not inflate Rule 6 exposure."""

        assert rule_engine.validate_dietary_mark("NOT_DETECTED")["penalty_amount"] == 0

    def test_unknown_status_is_treated_as_absent(self):
        assert rule_engine.validate_dietary_mark("banana")["dietary_status"] == "NOT_DETECTED"


class TestOcrHealing:
    """The healers rescue mangled keywords, but they used to mangle lawful units."""

    def test_kg_is_not_healed_into_the_packing_keyword(self):
        """Levenshtein.ratio('kg', 'pkg') is 0.8, which cleared the short-token
        threshold and turned '1 kg' into '1 PKG' - a INR 25,000 false positive."""

        assert rule_engine.clean_ocr_text("NET QUANTITY 1 kg") == "NET QUANTITY 1 kg"

    def test_litre_symbol_survives_the_glyph_rules(self):
        """The l-to-1 rule rewrote '500ml' to '500m1'."""

        assert rule_engine.clean_ocr_text("500ml") == "500ml"

    @pytest.mark.parametrize("mangled,healed", [("5OOml", "500ml"), ("1OOg", "100g"), ("1Okg", "10kg")])
    def test_glyphs_in_the_number_are_still_healed(self, mangled, healed):
        assert rule_engine.clean_ocr_text(mangled) == healed

    @pytest.mark.parametrize("text,value,unit", [("Net Wt. 5OOml", 500.0, "ml"), ("Net wt 1OOg", 100.0, "g")])
    def test_healed_quantities_parse(self, text, value, unit):
        parsed = rule_engine.parse_net_quantity(text)
        assert parsed["value"] == pytest.approx(value)
        assert parsed["unit"] == unit
        assert parsed["is_compliant"] is True

    def test_unlawful_unit_is_not_healed_into_a_lawful_one(self):
        """'gms' must reach the invalid-unit check intact to be rejected."""

        assert "gms" in rule_engine.clean_ocr_text("Net Wt 200 gms")

    @pytest.mark.parametrize("text,expected", [
        ("trur| CARE", "OUR, CARE"),
        ("t0 v5: 1800 123 4567", "to US: 1800 123 4567"),
        ("NET WEIGHT 2OO9", "NET WEIGHT 2009"),
    ])
    def test_keyword_healing_still_works(self, text, expected):
        assert rule_engine.clean_ocr_text(text) == expected


class TestValidateCompliance:
    def test_returns_every_rule_class_plus_the_dietary_row(self):
        results = rule_engine.validate_compliance({}, None)
        assert set(results) == {
            "product_name", "net_quantity", "pricing", "dates", "batch_details",
            "manufacturer_info", "compliance_and_support", "dietary_mark",
        }

    def test_tolerates_junk_input(self):
        assert rule_engine.validate_compliance(None)["dates"]["is_compliant"] is False

    def test_dietary_status_reaches_the_result(self):
        results = rule_engine.validate_compliance({"product_name": "Gram Flour"}, "VEG")
        assert results["dietary_mark"]["is_compliant"] is True
