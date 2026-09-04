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

    def test_an_email_address_is_never_healed_into_a_keyword(self):
        """'tataconsumer' scores 0.80 against the 'consumer' keyword, so the healer
        turned care@tataconsumer.com into CARE@CONSUMER.com - a fabricated contact
        address in an inspector's report, and one that does not look fabricated."""

        assert rule_engine.clean_ocr_text("care@tataconsumer.com") == "care@tataconsumer.com"
        assert (
            rule_engine.parse_consumer_care_fssai("care@tataconsumer.com 1800 123 4567")["email"]
            == "care@tataconsumer.com"
        )

    def test_a_bare_domain_is_protected_too(self):
        assert rule_engine.clean_ocr_text("visit www.tataconsumer.com").endswith(
            "www.tataconsumer.com"
        )

    def test_healing_still_runs_beside_a_protected_address(self):
        assert rule_engine.clean_ocr_text("trur| CARE care@acme.com") == "OUR, CARE care@acme.com"

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


class TestDatesFromTheCorpus:
    """Readings taken verbatim from ``runs/ocr_rapidocr.digest.csv``."""

    def test_the_label_may_follow_the_value(self):
        """Joining the annotated boxes often puts both labels after both dates."""

        parsed = rule_engine.parse_date_declarations("04/05.26 13/05.27 MFG.DATF USE BY UE")
        assert parsed["manufacture_or_packaging_date"] == "2026-05-04"
        assert parsed["expiry_or_use_by_date"] == "2027-05-13"

    def test_month_word_pair_with_trailing_labels(self):
        parsed = rule_engine.parse_date_declarations("30 JUL 2026 JAN 2028 MFD USEBY")
        assert parsed["manufacture_or_packaging_date"] == "2026-07-30"
        assert parsed["expiry_or_use_by_date"] == "2028-01-01"

    def test_an_implausible_year_is_not_a_date(self):
        """'05.59' is a mangled reading, not May 2059, and reporting it as a date
        would put a fabricated finding in front of an inspector."""

        parsed = rule_engine.parse_date_declarations("&Use ByDate: 65.55 06 Mfd., 05.59")
        assert parsed["manufacture_or_packaging_date"] is None
        assert parsed["is_compliant"] is False

    def test_shelf_life_survives_being_glued_to_its_anchor(self):
        parsed = rule_engine.parse_date_declarations("BEFORE18MONTHSFROMMANUFACTURE")
        assert parsed["best_before_raw"] == "18MONTHSFROMMANUFACTURE"
        assert parsed["is_compliant"] is True

    def test_a_duration_needs_an_anchor_to_be_a_shelf_life(self):
        """"6 MONTHS" on its own could be anything printed on the panel."""

        assert rule_engine.parse_date_declarations("NET 6 MONTHS")["best_before_raw"] is None

    def test_an_unlabelled_date_is_reported_but_does_not_comply(self):
        """Rule 6 wants the date to say what it is; the reading is still surfaced
        so an inspector can see that a date was printed."""

        parsed = rule_engine.parse_date_declarations("12/08/2026 10/11/2026")
        assert parsed["unlabelled_dates"] == "2026-08-12, 2026-11-10"
        assert parsed["is_compliant"] is False


class TestTaxClause:
    """The clause is annotated in its own box, so it arrives in any order and in
    any state of decay. Each string below is a real reading."""

    @pytest.mark.parametrize(
        "text,clause",
        [
            ("VRP Rs.48- IINCL OF ALL TAXES! UNTSALEPRICE:", "inclusive of all taxes"),
            ("MRP: (nclofltaxes) 1 55 USP:E Rs 70 P m1", "of all taxes"),
            ('taxes "MPP 20.00 USP Uinc.ofal Batoh', "incl. taxes"),
            ("(Incl. of all USP SD 20.00", "incl. of all"),
        ],
    )
    def test_surviving_forms_are_accepted(self, text, clause):
        parsed = rule_engine.parse_mrp_declaration(text)
        assert parsed["tax_clause"] == clause
        assert parsed["is_compliant"] is True

    def test_the_clause_may_precede_the_cue(self):
        """'taxes) (incl. of all' - the two halves swap places when the boxes are
        joined top-to-bottom, so the cue is searched on both sides."""

        assert rule_engine.parse_mrp_declaration(
            "taxes) (incl. of all 6 er Pe Rs.0.35 MRP 00 160 Rs."
        )["tax_clause"] == "incl. taxes"

    def test_a_unit_rate_is_not_a_pair_of_prices(self):
        """'USP Rs.74/100g' is one rate. Reading its two numbers as MRP and USP
        reported an MRP of 74 on a pack priced at 185."""

        parsed = rule_engine.parse_mrp_declaration("MRP Rs.185.00 (incl.of alltaxes) USP Rs.74/100g")
        assert parsed["mrp"] == pytest.approx(185.0)
        assert parsed["unit_sale_price"] == pytest.approx(74.0)
        assert parsed["usp_unit"] == "g"

    def test_a_rate_against_a_bare_unit_still_finds_the_pack_price(self):
        """'0 80/9' - the 'g' is read as a 9 and the rupee decimal is a space."""

        parsed = rule_engine.parse_mrp_declaration("MRP (Incl. of all.TAXES) Rs 140 00 (Rs 0 80/9")
        assert parsed["mrp"] == pytest.approx(140.0)
        assert parsed["unit_sale_price"] == pytest.approx(0.8)

    def test_the_pack_price_wins_even_when_it_is_read_second(self):
        parsed = rule_engine.parse_mrp_declaration("(incl. of all taxes) USP 0.52/9 MRP 104 00")
        assert parsed["mrp"] == pytest.approx(104.0)
        assert parsed["unit_sale_price"] == pytest.approx(0.52)

    def test_a_zero_price_never_passes(self):
        """'MRP Rs. 00' is a dropped digit, not a free product."""

        parsed = rule_engine.parse_mrp_declaration("(ind. ofalltaxes) R 00 MRP Rs.")
        assert parsed["mrp"] == pytest.approx(0.0)
        assert parsed["is_compliant"] is False


class TestManufacturerRoles:
    """Rule 6(1)(a) accepts several roles, and the pack abbreviates all of them."""

    @pytest.mark.parametrize(
        "text,entity_type",
        [
            ("MKTD.BY:HINDUSTAN UNILEVER LTD.,MUMBAI-400 099", "marketed_by"),
            ("Repacked &Mktd.by: HARIMA FOODS, chennai-600 131", "marketed_by"),
            ("MFG/MKT BY HINDUSTAN COCA-COLA BEVERAGES, GURUGRAM-122 011", "marketed_by"),
            ("Imported by ACME TRADERS, DELHI-110 001", "imported_by"),
            ("Packed by ACME FOODS, Pune 411001", "packed_by"),
            ("Manufactured and Marketed by ZYDUS WELLNESS, AHMEDABAD-382 481",
             "manufactured_and_marketed_by"),
            ("Md.byReck Benckiserld, GURGAON-122 002", "manufactured_by"),
        ],
    )
    def test_roles_are_recognised_and_named(self, text, entity_type):
        parsed = rule_engine.parse_manufacturer_details(text)
        assert parsed["type"] == entity_type
        assert parsed["is_compliant"] is True

    def test_an_address_word_is_not_a_role_marker(self):
        """'MKT BYPASS ROAD' is part of the address, not a marketer declaration -
        and in an all-capitals region the glued-name guard cannot tell them apart."""

        parsed = rule_engine.parse_manufacturer_details("MKT BYPASS ROAD, PUNE 411001")
        assert parsed["type"] is None
        assert parsed["is_compliant"] is False

    def test_the_name_may_precede_the_verb(self):
        parsed = rule_engine.parse_manufacturer_details("HINDUSTAN FOODS LTD, NASHIK-422 403 MFD BY")
        assert parsed["name"].startswith("HINDUSTAN FOODS LTD")
        assert parsed["pincode"] == "422403"

    def test_address_words_are_not_healed_into_label_keywords(self):
        """'Plot No' used to be healed into 'LOT No' and 'Industrial Area' into
        'Industrial CARE', which cost the address its only legible words."""

        parsed = rule_engine.parse_manufacturer_details(
            "Packed by ACME FOODS, Plot No: 50, Industrial Area, Pune 411001"
        )
        assert "Plot No" in parsed["name"]
        assert "Industrial Area" in parsed["name"]

    def test_a_pincode_alone_is_not_a_declaration(self):
        parsed = rule_engine.parse_manufacturer_details("ACME FOODS, NASHIK-422 403")
        assert parsed["pincode"] == "422403"
        assert parsed["is_compliant"] is False


class TestFssaiLicence:
    def test_fourteen_digits_split_by_spaces(self):
        parsed = rule_engine.parse_consumer_care_fssai(
            "FSSAI Lic No. 100 12 043 000 123 care@acme.com 1800 123 4567"
        )
        assert parsed["fssai_license_number"] == "10012043000123"
        assert parsed["is_compliant"] is True

    def test_an_over_long_run_next_to_a_mangled_anchor(self):
        """'F8SAILcNa100140210012590' - the second S is read as an 8, 'Lic No' as
        'LcNa', and a neighbouring digit runs into the licence."""

        parsed = rule_engine.parse_consumer_care_fssai(
            "F8SAILcNa100140210012590 care@acme.com 18001234567"
        )
        assert parsed["fssai_license_number"] == "10014021001259"

    def test_the_licence_may_be_printed_in_another_region(self):
        """It usually sits in the manufacturer block, which is why this one class
        is given the whole pack's text."""

        parsed = rule_engine.parse_consumer_care_fssai(
            "care@acme.com 1800 123 4567", "FSSAI 10012043000123"
        )
        assert parsed["is_compliant"] is True

    def test_a_long_digit_run_alone_is_not_a_licence(self):
        """A barcode is not a licence number, so the fallback needs its anchor."""

        parsed = rule_engine.parse_consumer_care_fssai(
            "123456789012345678 care@acme.com 18001234567"
        )
        assert parsed["fssai_license_number"] is None


class TestNetQuantityFromTheCorpus:
    @pytest.mark.parametrize("text,value", [("NETWEIGHT150g", 150.0), ("NETWEIGHT: 70 6 NEIGHT", 70.0)])
    def test_glued_label_and_six_for_g(self, text, value):
        parsed = rule_engine.parse_net_quantity(text)
        assert parsed["value"] == pytest.approx(value)
        assert parsed["unit"] == "g"
        assert parsed["is_compliant"] is True

    @pytest.mark.parametrize("text", ["NET 100 6", "Net wt. 200 8"])
    def test_a_spaced_six_or_eight_reads_as_grams(self, text):
        """PP-OCR confuses the 'g' symbol with 6 and 8 on a printed pack."""

        assert rule_engine.parse_net_quantity(text)["unit"] == "g"

    def test_a_glued_six_is_still_part_of_the_number(self):
        """'1006' is one number, not 100 g - only a separated digit is a unit."""

        assert rule_engine.parse_net_quantity("1006")["unit"] is None
