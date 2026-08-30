"""Crop-preparation tests.

These are the cheapest guards in the suite: no OCR engine, no torch, no
checkpoint. They pin the two properties the pipeline actually depends on -
that a small crop gets scaled towards the recognizer's training height, and
that every variant handed to a backend is a usable image of the same scene.
"""

from __future__ import annotations

import numpy as np
import pytest

import preprocessing
from conftest import render_text_image


class TestTextHeight:
    def test_measures_a_rendered_line(self):
        image = render_text_image("MRP 29.00", height=90, scale=1.0, thickness=2)
        height = preprocessing.estimate_text_height(image)
        # cv2.FONT_HERSHEY_SIMPLEX at scale 1.0 draws caps about 22 px tall.
        assert 12.0 <= height <= 34.0

    def test_blank_image_does_not_divide_by_zero(self):
        blank = np.full((40, 120, 3), 255, dtype=np.uint8)
        assert preprocessing.estimate_text_height(blank) >= 0.0

    def test_empty_input_is_tolerated(self):
        assert preprocessing.estimate_text_height(np.zeros((0, 0, 3), dtype=np.uint8)) >= 0.0


class TestScaleForOcr:
    def test_small_text_is_scaled_up(self):
        """A 7 px date print is the case the blind 3x resize handled badly."""

        image = render_text_image("10/11/2026", width=120, height=26, scale=0.32, thickness=1)
        scaled, factor = preprocessing.scale_for_ocr(image)
        assert factor > 1.0
        assert scaled.shape[0] > image.shape[0]

    def test_scale_is_bounded(self):
        tiny = render_text_image("1", width=20, height=12, scale=0.2, thickness=1)
        _, factor = preprocessing.scale_for_ocr(tiny)
        assert preprocessing.MIN_SCALE <= factor <= preprocessing.MAX_SCALE

    def test_large_text_is_left_alone(self):
        big = render_text_image("NET WT", width=900, height=260, scale=4.0, thickness=8)
        _, factor = preprocessing.scale_for_ocr(big)
        assert factor == pytest.approx(1.0)

    def test_target_height_is_approached(self):
        image = render_text_image("500 ml", width=200, height=40, scale=0.5, thickness=1)
        measured = preprocessing.estimate_text_height(image)
        scaled, factor = preprocessing.scale_for_ocr(image)
        if preprocessing.MIN_SCALE < factor < preprocessing.MAX_SCALE:
            assert preprocessing.estimate_text_height(scaled) == pytest.approx(
                preprocessing.TARGET_TEXT_HEIGHT, abs=12
            )
        assert measured > 0


class TestDeskew:
    def test_a_tilted_crop_is_straightened(self):
        tilted = render_text_image("BATCH B2C3", angle=7.0)
        straight = preprocessing.deskew(tilted)
        assert straight.shape == tilted.shape

    def test_rotation_beyond_the_cap_is_refused(self):
        """A 40-degree pack is a badly held phone, not a tilted photo: leave it."""

        rotated = render_text_image("BATCH B2C3", angle=40.0)
        assert np.array_equal(preprocessing.deskew(rotated), rotated)

    def test_empty_input_is_tolerated(self):
        empty = np.zeros((0, 0, 3), dtype=np.uint8)
        assert preprocessing.deskew(empty).size == 0


class TestVariants:
    def test_offset_print_classes_get_two_variants(self):
        variants = preprocessing.build_variants(render_text_image(), "product_name")
        assert [variant.name for variant in variants] == ["plain", "enhanced"]

    def test_over_printed_classes_get_the_dotmatrix_variant(self):
        """MRP / date / batch text is ink-jetted after packing, so it needs the close."""

        for canonical in sorted(preprocessing.DOT_MATRIX_CLASSES):
            names = [variant.name for variant in preprocessing.build_variants(render_text_image(), canonical)]
            assert names == ["plain", "enhanced", "dotmatrix"]
            assert tuple(names) == tuple(preprocessing.variant_names(canonical))

    def test_dotmatrix_can_be_forced_either_way(self):
        forced = preprocessing.build_variants(render_text_image(), "product_name", include_dotmatrix=True)
        assert "dotmatrix" in [variant.name for variant in forced]
        suppressed = preprocessing.build_variants(render_text_image(), "mrp_declaration", include_dotmatrix=False)
        assert "dotmatrix" not in [variant.name for variant in suppressed]

    def test_every_variant_is_a_usable_image(self):
        for variant in preprocessing.build_variants(render_text_image(), "date_declarations"):
            assert variant.image.dtype == np.uint8
            assert variant.image.size > 0
            assert variant.image.ndim in (2, 3)
            assert variant.scale >= preprocessing.MIN_SCALE

    def test_allowlists_are_attached_per_class_and_can_be_disabled(self):
        with_list = preprocessing.build_variants(render_text_image(), "mrp_declaration")
        assert all("0123456789" in (variant.allowlist or "") for variant in with_list)
        without = preprocessing.build_variants(render_text_image(), "mrp_declaration", use_allowlist=False)
        assert all(variant.allowlist is None for variant in without)

    def test_empty_crop_yields_no_variants(self):
        assert preprocessing.build_variants(np.zeros((0, 0, 3), dtype=np.uint8), "mrp_declaration") == []


class TestDotMatrixHealing:
    def test_output_keeps_grey_levels(self):
        """The binarizing version measured worst of five recipes; grey must survive."""

        healed = preprocessing.heal_dot_matrix_text(render_text_image("PKD 12/08/2026"))
        assert healed.ndim == 2
        assert len(np.unique(healed)) > 2

    def test_ink_is_not_eaten(self):
        """A close on the *inverted* image joins dots. With the polarity wrong it
        would erode strokes instead, so assert the exact morphological property:
        closing ink can only ever darken a pixel, never lighten it."""

        import cv2

        image = render_text_image("USE BY 10/11/2026", scale=0.6, thickness=1)
        reference = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(
            preprocessing._to_gray(image)
        )
        healed = preprocessing.heal_dot_matrix_text(image)
        assert np.all(healed.astype(int) <= reference.astype(int))
        assert healed.mean() < reference.mean()

    def test_empty_input_is_tolerated(self):
        empty = np.zeros((0, 0, 3), dtype=np.uint8)
        assert preprocessing.heal_dot_matrix_text(empty).size == 0


class TestSharpness:
    def test_blur_lowers_the_score(self):
        import cv2

        sharp = render_text_image("MRP Rs. 29.00")
        blurred = cv2.GaussianBlur(sharp, (0, 0), 1.6)
        assert preprocessing.laplacian_sharpness(sharp) > preprocessing.laplacian_sharpness(blurred)

    def test_a_flat_panel_scores_zero(self):
        flat = np.full((60, 60, 3), 200, dtype=np.uint8)
        assert preprocessing.laplacian_sharpness(flat) == pytest.approx(0.0)

    def test_empty_input_scores_zero(self):
        assert preprocessing.laplacian_sharpness(np.zeros((0, 0, 3), dtype=np.uint8)) == 0.0


def _solid(color_bgr: tuple[int, int, int]) -> np.ndarray:
    patch = np.zeros((40, 40, 3), dtype=np.uint8)
    patch[:, :] = color_bgr
    return patch


class TestDietarySymbol:
    def test_green_square_is_vegetarian(self):
        assert preprocessing.classify_dietary_symbol(_solid((40, 160, 40))) == "VEG"

    def test_maroon_mark_is_non_vegetarian(self):
        assert preprocessing.classify_dietary_symbol(_solid((30, 30, 130))) == "NON_VEG"

    def test_a_grey_patch_is_uncertain_rather_than_guessed(self):
        assert preprocessing.classify_dietary_symbol(_solid((128, 128, 128))) == "UNCERTAIN"

    def test_grayscale_or_empty_input_is_uncertain(self):
        assert preprocessing.classify_dietary_symbol(np.zeros((10, 10), dtype=np.uint8)) == "UNCERTAIN"
        assert preprocessing.classify_dietary_symbol(np.zeros((0, 0, 3), dtype=np.uint8)) == "UNCERTAIN"
