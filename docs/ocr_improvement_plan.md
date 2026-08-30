# OCR improvement plan and measurements

**Owner:** Tanmay · **Last updated:** 30 August 2026 · **Scope:** SIH26034 LMPC compliance scanner

This document records why the OCR layer looks the way it does, what was measured,
and what is worth doing next. Everything numeric here comes from
[`benchmark_ocr.py`](../benchmark_ocr.py) runs stored under `runs/ocr_benchmark/`;
nothing is estimated.

## 1. Why PaddleOCR was dropped

PaddleOCR was the original choice and is genuinely the strongest family of models
for this task. It could not be kept on this machine:

- `paddlepaddle` 3.x raises `ConvertPirAttribute2RuntimeAttribute` inside the PIR /
  oneDNN executor when the PP-OCR detection graph is run on this CPU. The failure
  is in Paddle's own inference layer, not in application code.
- Importing `paddle` before `torch` corrupts `torch\lib\shm.dll` loading, so YOLO
  then fails to initialise. Import order cannot be relied on inside Streamlit,
  which imports modules on every rerun.

The accuracy is not lost, though: **RapidOCR runs the same PP-OCR model weights
through ONNX Runtime.** `rapidocr-onnxruntime` is therefore the practical way to
keep PaddleOCR-family recognition quality without the paddle runtime. That is now
the default backend.

## 2. The pluggable backend layer

[`ocr_backends.py`](../ocr_backends.py) wraps every engine behind one interface, so
the pipeline never names an engine:

| Backend | Engine | Notes |
|---|---|---|
| `rapidocr` | PP-OCR v4 via ONNX Runtime | default; best-calibrated confidence |
| `easyocr` | CRAFT + CRNN via torch | GPU-enabled here (RTX 3050 6 GB); ~6x faster per crop |

`get_backend(name)` falls back to whichever engine actually loads, and
`LMPC_OCR_BACKEND` overrides the default without a code change:

```bash
LMPC_OCR_BACKEND=easyocr python -m streamlit run app.py
```

Both engines return `OcrLine` records (text, confidence, box), and `sort_lines`
puts them into human reading order — statutory panels are dense and multi-column
("MRP" beside "Rs. 29.00"), and neither engine guarantees ordering.

The contract around the engines — lazy loading, the serialising lock, error
containment (a bad crop degrades instead of crashing a scan), reading order,
character-weighted confidence and the fallback registry — is pinned by 32 tests in
[`tests/test_ocr_backends.py`](../tests/test_ocr_backends.py) against a fake engine, so
the suite runs on a checkout with no OCR weights installed. The engines themselves are
exercised by `benchmark_ocr.py` against real crops.

## 3. Crop preparation

The prototype fed the detector's crop through a blind `cv2.resize(fx=3, fy=3)`.
That is too little for a 7-pixel date print and wasteful for a 200-pixel
manufacturer block, so [`preprocessing.py`](../preprocessing.py) now measures text
height and scales towards the ~32 px the recognisers were trained on
(`TARGET_TEXT_HEIGHT`, capped at 6x), deskews up to 12 degrees, and offers
variants that the pipeline scores against each other:

| Variant | Recipe | Used for |
|---|---|---|
| `plain` | deskew + scale only | all classes |
| `enhanced` | CLAHE on the LAB luminance channel | all classes |
| `dotmatrix` | CLAHE + **grayscale** morphological close | MRP / date / batch only |

## 4. The dot-matrix recipe was measured, not guessed

The original third variant binarized the crop (CLAHE → adaptive threshold →
close). Tested over **all 121 dot-matrix crops in the test split** against four
alternatives, binarization was the worst recipe on both engines — it discards the
grey levels the recogniser uses and roughly halved the characters returned:

| Recipe | EasyOCR signal | RapidOCR signal | chars read (easy/rapid) |
|---|---:|---:|---:|
| `binarize` (old) | 0.125 | 0.293 | 7.0 / 8.9 |
| **`close_gray` (now shipping)** | **0.216** | **0.380** | **13.7 / 13.2** |
| `unsharp` | 0.191 | 0.390 | 13.1 / 12.7 |
| `enhanced` | 0.216 | 0.372 | 13.5 / 13.1 |
| `plain` | 0.216 | 0.291 | 14.0 / 12.1 |

Qualitatively, on the same crop: binarize returned `29.00`, grayscale close
returned `PKD Rs 29.00 MRP USEBY:10/11/2026 72/08/2026 Rs`.

`unsharp` is marginally better on RapidOCR (+0.010) but clearly worse on EasyOCR
(−0.025), so `close_gray` was chosen as the single recipe that helps both engines.
It is implemented in `preprocessing.heal_dot_matrix_text`, which keeps its name and
its purpose — only the recipe changed.

## 5. Candidate scoring

Running three variants per crop only helps if the pipeline can tell which reading
is the good one. `pipeline._candidate_score` combines three signals:

```text
score = 0.50 * ocr_confidence
      + 0.35 * parse_signal          # 0.6 * fields_found/fields_expected + 0.4 * rule_compliant
      + 0.15 * min(chars, 40) / 40   # yield, saturating at 40 characters
```

The parse term is what makes this different from picking the highest-confidence
string: a reading is preferred when the *rule engine can actually use it*. Every
candidate — its variant, text, confidence, parse result, score and latency — is
kept and shown in the app's **OCR diagnostics** tab, so a wrong reading can be
explained rather than guessed at.

## 6. Head-to-head engine benchmark

`python benchmark_ocr.py --limit 30 --split test` — 30 crops, every
backend × variant combination, 150 measurements:

| backend/variant | crops | read | field yield | rule-ok | conf | s/crop |
|---|---:|---:|---:|---:|---:|---:|
| easyocr/plain | 30 | 0.97 | 0.30 | 0.23 | 0.32 | 0.32 |
| easyocr/enhanced | 30 | 0.97 | 0.33 | 0.27 | 0.29 | 0.20 |
| easyocr/dotmatrix | 15 | 1.00 | 0.10 | 0.00 | 0.28 | 0.13 |
| rapidocr/plain | 30 | 0.63 | 0.24 | 0.17 | 0.89 | 1.42 |
| rapidocr/enhanced | 30 | 0.77 | 0.29 | 0.20 | 0.83 | 1.01 |
| rapidocr/dotmatrix | 15 | 0.53 | 0.12 | 0.07 | 0.82 | 0.88 |

Per backend, taking the best variant per crop:

| Backend | field yield | mean confidence | s/crop | 30 crops |
|---|---:|---:|---:|---:|
| easyocr (GPU) | 0.27 | 0.30 | 0.23 | 18 s |
| rapidocr (CPU/ONNX) | 0.23 | 0.85 | 1.14 | 86 s |

**Read this carefully, because the honest conclusion is "keep both":**

- EasyOCR on the GPU is ~5x faster per crop and edges ahead on field yield.
- RapidOCR's confidence is *calibrated* (0.85 vs 0.30). EasyOCR reports ~0.3 whether
  it is right or wrong, which makes confidence-based human-review routing impossible.
- Taking the single best reading per crop, RapidOCR wins 16 of 30 crops.

So RapidOCR stays the default (better readings, usable confidence) and EasyOCR is
the switch to flip when latency matters — e.g. a long queue of panels, or a live
demo. Both are exercised by the same benchmark, so this can be re-decided with
data whenever the hardware changes.

### Effect of the two fixes made on 30 August

Same 30 crops, before and after (a) the dot-matrix recipe change and (b) two
rule-engine healer bugs described in §7. Numbers are the best-of-variants parse
signal per class:

| Class | EasyOCR | RapidOCR |
|---|---|---|
| product_name | 1.000 → 1.000 | 0.750 → 0.750 |
| net_quantity | 0.417 → 0.500 | 0.250 → 0.333 |
| mrp_declaration | 0.157 → 0.343 | 0.114 → 0.171 |
| manufacturer_details | 0.383 → 0.433 | 0.350 → 0.400 |
| consumer_care_fssai | 0.050 → 0.100 | 0.250 → 0.300 |
| date_declarations | 0.000 → 0.000 | 0.050 → 0.075 |

Nothing regressed; MRP more than doubled on EasyOCR; total time per crop *fell*
(0.27 → 0.23 s easyocr, 1.61 → 1.14 s rapidocr) because a single-channel crop is
cheaper to recognise than a binarized three-channel one.

## 7. Two rule-engine bugs the OCR was being blamed for

`clean_ocr_text` heals mangled keywords (`trur|` → `OUR`, `u5` → `US`) and glyph
confusions (`2OO9` → `2009`). Both healers were too aggressive and were destroying
lawful declarations:

| Input | Old output | Consequence |
|---|---|---|
| `NET QUANTITY 1 kg` | `NET QUANTITY 1 PKG` | no quantity parsed → ₹25,000 penalty |
| `500ml` | `500m1` | no quantity parsed → ₹25,000 penalty |

The first was a fuzzy match: `Levenshtein.ratio("kg", "pkg")` is 0.80, above the
0.68 short-token threshold, so a unit symbol was rewritten into the "packed on"
keyword. The second was the `l` → `1` glyph rule firing on the unit.

Fixed by giving both healers a guard list of unit symbols (`_PROTECTED_TOKENS`) and
a number-plus-unit pattern (`_NUMBER_WITH_UNIT`) that heals the numeric head while
leaving the unit alone. This also *improved* healing: `5OOml` → `500ml` and
`1Okg` → `10kg` now parse, where previously they were left broken.

Both cases, and the healing that must keep working, are pinned by
[`tests/test_rule_engine.py`](../tests/test_rule_engine.py). Note that the
non-standard `gms` is deliberately *not* healed — LMPC Rule 6 requires SI symbols,
so `200 gms` must reach the invalid-unit check intact and be reported.

## 8. The real accuracy ceiling is capture resolution

The lowest-yielding class is `date_declarations` (0.00–0.075). Its crops in the
test split are 21×14, 30×13, 94×22, 106×20 and 187×16 pixels. After scaling they
have already hit the 6x cap, so the recogniser is being handed an interpolated
14-pixel-tall dot-matrix date. **No engine choice fixes this** — the information is
not in the photograph.

That finding is what motivated the WebRTC work: a focus meter and a "move closer /
only N of 7 panels visible" hint change the input, which is the only thing that can
change this number. See [`webrtc_integration.md`](webrtc_integration.md).

## 9. What to do next, in value order

1. **Retrain the detector on the repaired labels** (owner: user, not yet run). Recall
   0.35 is the binding constraint: a panel that is never detected cannot be read.
   See [`dataset_label_repair.md`](dataset_label_repair.md).
2. **Transcribe ground truth for the 30 benchmark crops** and pass `--truth` so the
   CER column stops printing `-`. Field yield is a proxy; CER is the real metric.
3. **Use the live guidance to collect a better test set** — re-photograph the worst
   10 packs with the focus meter green and re-run the benchmark. This measures how
   much of the current failure is capture rather than model.
4. **Add a confidence-gated review route**: RapidOCR's calibration makes
   "confidence < 0.5 → manual review" meaningful. Do not attempt this on EasyOCR.
5. **Hindi / regional scripts.** RapidOCR ships PP-OCR multilingual weights; the
   backend layer can host a second recogniser without touching the pipeline.
6. **Perspective correction** for curved and angled packs, once capture guidance is
   in field use and the remaining failures can be attributed.

## 10. Reproducing everything here

```bash
python benchmark_ocr.py --limit 30 --split test
```

```bash
python -m pytest tests/ -q
```

Outputs land in `runs/ocr_benchmark/measurements_<timestamp>.csv` (one row per
crop × backend × variant) and `summary_<timestamp>.json`. The CSV is the primary
record — every table in this document can be rebuilt from it.


