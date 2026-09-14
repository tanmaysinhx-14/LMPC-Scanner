# Dataset label repair

**Owner:** Tanmay · **Last updated:** 30 August 2026 · **Tool:** [`dataset_tools.py`](../dataset_tools.py)

## 1. What was wrong

The Roboflow export (`packaged-commodity`, version 6) contains **two different label
encodings mixed inside the same files**: 5-field detection rows (`cls cx cy w h`) and
variable-length polygon rows (`cls x1 y1 x2 y2 …`).

Ultralytics decides *per file* whether a label file is boxes or segments, based on the
first rows it sees. In a mixed file, the rows in the minority encoding are misread:
a 5-field detection row parsed as a polygon becomes a degenerate two-point shape, and
the box the detector is trained on is not the box the annotator drew.

Measured across the whole dataset before repair (`runs/label_audit_before.json`):

| | files | rows | bbox rows | polygon rows | mixed files | rows the loader misreads |
|---|---:|---:|---:|---:|---:|---:|
| train | 1905 | 6471 | 1041 | 5430 | 219 | 486 |
| valid | 79 | 301 | 44 | 257 | 10 | 18 |
| test | 80 | 349 | 46 | 303 | 5 | 8 |
| **total** | **2064** | **7121** | **1131** | **5990** | **234** | **512** |

So **512 of 7121 annotation rows (7.2%)** were training the detector towards the wrong
region. Damage per row was ranked by `(1 - IoU) * max(authored_area, trained_area)`,
which puts large, badly-relocated boxes at the top rather than trivial ones.

This matters more than the percentage suggests: the misread rows are concentrated in
the 234 mixed files, and a detector that sees a manufacturer block labelled at the
wrong coordinates learns a worse prior for every image.

## 2. The repair

```bash
python dataset_tools.py audit  --json runs/label_audit_before.json
python dataset_tools.py repair
python dataset_tools.py verify
python dataset_tools.py audit  --json runs/label_audit_after.json
```

`repair` rewrites **every** row in the canonical 5-field detection form, converting
polygons to their clamped bounding box, so the loader has nothing left to guess:

- 2064 files scanned, 2064 rewritten
- 5990 polygon rows converted to boxes, 1131 already-correct rows preserved byte-wise
- originals copied to `<split>/labels_original/` before the first rewrite (a second run
  will not clobber the backup)
- `packaged-commodity-dataset/label_repair_manifest.json` records every file and row
- stale `labels.cache` files removed — otherwise Ultralytics would reuse the
  pre-repair parse and the repair would appear to do nothing

After repair (`runs/label_audit_after.json`): 7121 bbox rows, 0 polygon rows,
0 mixed files, 0 misread rows. `verify` reports no problems.

Four rows remain flagged as *tiny* (a box under ~0.5% of the image). They are genuine
annotations of very small print, not corruption, and are left alone.

The audit → repair → verify round trip is pinned by 36 tests in
[`tests/test_dataset_tools.py`](../tests/test_dataset_tools.py), which build their own
miniature dataset in a temporary directory — including the mixed-encoding file that
reproduces the defect, the `labels_original` backup, the manifest, idempotence on a
second run and the dry-run mode. Nothing in the suite touches the real dataset.

## 3. What this does and does not fix

The checkpoint currently in `runs/detect/train/weights/best.pt` was trained **before**
the repair. Its recorded metrics are precision 0.539, recall 0.353, mAP50 0.318,
mAP50-95 0.186 at epoch 98.

Recall 0.35 is the binding constraint on the whole system: a panel that is never
detected can never be read, so no OCR improvement can compensate for it. On a real
pack the live overlay currently shows ~2 boxes at 0.21–0.22 confidence with one class
confusion — exactly what a detector trained on 7% wrong boxes looks like.

**The retrain has not been run.** That was a deliberate decision: it is hours of GPU
time on the user's machine and the user owns when it happens.
[`train.py`](../train.py) has been prepared so the run is reproducible:

- it **refuses to start** while any label row is still in the mixed encoding
  (`verify_dataset` runs first), so a wasted training run is no longer possible
- `RUN_NAME` and the path `pipeline.MODEL_PATH` loads now agree, so a finished run is
  actually the one the app serves
- defaults raised for this dataset: 150 epochs, patience 30, `imgsz=768` (statutory
  print is small; 640 was throwing detail away), AutoBatch for the 6 GB card
- every augmentation carries a written reason (`python train.py --list-augmentation`);
  `fliplr`/`flipud` are off because mirrored text is never a valid declaration

So the retrain is a single command:

```bash
python train.py
```

Expect the largest single accuracy gain of any change in this repository from that
run — and re-run `benchmark_ocr.py` afterwards, because several OCR numbers are
currently limited by which panels get detected at all.

## 4. Dataset facts, corrected

Earlier drafts of the project report stated 1,914 training images and a 4 GB GPU. The
verified figures are:

| | value |
|---|---|
| train / valid / test images | 1905 / 79 / 80 |
| annotation rows | 7121 across 2064 files |
| detector classes (7) | `consumer_care_region`, `date_region`, `dietary_symbol_region`, `generic_name_region`, `manufacturer_region`, `mrp_region`, `net_quantity_region` |
| GPU | NVIDIA RTX 3050, 6 GB |

Note there is still **no `batch_number` class** in the dataset. The rule engine parses
batch/lot text, but it only ever receives it when the text happens to fall inside
another detected panel. Adding that class is a dataset task, not a code task.
