"""Train the packaged-commodity region detector.

Run ``python dataset_tools.py verify`` first - this script refuses to start if any
label row is still in the mixed polygon/box encoding that Ultralytics silently
misreads (see ``dataset_tools`` for the full explanation).

Examples
--------
    python train.py                          # defaults below
    python train.py --epochs 150 --imgsz 960
    python train.py --name second --resume
    python train.py --list-augmentation      # print the augmentation rationale
"""

from __future__ import annotations

import argparse
from pathlib import Path

HERE = Path(__file__).resolve().parent
DATA_YAML = HERE / "packaged-commodity-dataset" / "data.yaml"
PROJECT_DIR = HERE / "runs" / "detect"

#: ``pipeline.MODEL_PATH`` loads ``runs/detect/<RUN_NAME>/weights/best.pt``.
#: Keep the two in step or the app will keep serving an older checkpoint.
RUN_NAME = "train"

BASE_WEIGHTS = "yolo26n.pt"

#: Why each augmentation is set the way it is. Printed by --list-augmentation.
AUGMENTATION_NOTES = {
    "hsv_h": (0.015, "slight hue jitter: shelf lighting and phone white balance vary"),
    "hsv_s": (0.5, "saturation jitter: glossy foil vs matte paper wrappers"),
    "hsv_v": (0.4, "brightness jitter: the biggest real-world variable (flash, shade, glare)"),
    "degrees": (7.0, "small rotation: hand-held captures are rarely level"),
    "shear": (3.0, "slight shear: stands in for off-axis capture of a flat panel"),
    "perspective": (0.0005, "mild perspective: curved pouches and angled shots"),
    "translate": (0.1, "translation: the panel is not always centred"),
    "scale": (0.4, "scale: distance to the pack varies a lot"),
    "fliplr": (0.0, "OFF - mirrored text is never a valid label declaration"),
    "flipud": (0.0, "OFF - upside-down text is handled by asking the user to rotate"),
    "mosaic": (1.0, "mosaic: cheap context variety for a small dataset"),
    "close_mosaic": (15, "disable mosaic for the last 15 epochs so boxes settle"),
    "erasing": (0.2, "random erasing: mimics a thumb or crease covering part of a panel"),
}


def _augmentation_kwargs() -> dict[str, float | int]:
    return {key: value for key, (value, _reason) in AUGMENTATION_NOTES.items()}


def _print_augmentation() -> None:
    width = max(len(key) for key in AUGMENTATION_NOTES)
    for key, (value, reason) in AUGMENTATION_NOTES.items():
        print(f"{key:<{width}}  {str(value):<8}{reason}")


def _assert_labels_repaired(data_yaml: Path) -> None:
    """Refuse to train on labels the loader would misread."""

    try:
        from dataset_tools import verify_dataset
    except ImportError:  # dataset_tools is optional at runtime
        print("warning: dataset_tools not importable, skipping the label sanity check")
        return
    problems = verify_dataset(data_yaml.parent)
    if not problems:
        return
    preview = "\n  ".join(problems[:10])
    raise SystemExit(
        f"{len(problems)} label problem(s) found - training would learn wrong boxes.\n"
        f"  {preview}\n\nFix with: python dataset_tools.py repair"
    )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--data", type=Path, default=DATA_YAML)
    parser.add_argument("--weights", default=BASE_WEIGHTS, help="base checkpoint or a .pt to fine-tune")
    parser.add_argument("--epochs", type=int, default=150)
    parser.add_argument("--patience", type=int, default=30, help="early-stop patience in epochs")
    parser.add_argument("--imgsz", type=int, default=768, help="statutory print is small; do not go below 640")
    parser.add_argument("--batch", type=int, default=-1, help="-1 lets Ultralytics AutoBatch pick a safe size")
    parser.add_argument("--device", default="0", help="'0' for the first GPU, 'cpu' to force CPU")
    parser.add_argument("--workers", type=int, default=4)
    parser.add_argument("--name", default=RUN_NAME, help=f"run folder under {PROJECT_DIR}")
    parser.add_argument("--project", type=Path, default=PROJECT_DIR)
    parser.add_argument("--resume", action="store_true", help="continue the last run of this name")
    parser.add_argument("--cos-lr", action="store_true", help="cosine LR schedule instead of linear")
    parser.add_argument("--no-augment", action="store_true", help="train with augmentation off (ablation only)")
    parser.add_argument("--skip-label-check", action="store_true", help="train even if labels look broken")
    parser.add_argument("--list-augmentation", action="store_true", help="print augmentation settings and exit")
    return parser


def train_model(argv: list[str] | None = None):
    args = build_parser().parse_args(argv)
    if args.list_augmentation:
        _print_augmentation()
        return None

    if not args.data.is_file():
        raise SystemExit(f"data.yaml not found: {args.data}")
    if not args.skip_label_check:
        _assert_labels_repaired(args.data)

    from ultralytics import YOLO  # imported late so --list-augmentation stays instant

    augment = {} if args.no_augment else _augmentation_kwargs()
    out_dir = args.project / args.name
    print(f"weights will be written to {out_dir / 'weights' / 'best.pt'}")
    if args.name != RUN_NAME:
        print(f"note: pipeline.py loads run '{RUN_NAME}'. Point MODEL_PATH at '{args.name}' to serve it.")

    model = YOLO(args.weights)
    return model.train(
        data=str(args.data),
        epochs=args.epochs,
        patience=args.patience,
        imgsz=args.imgsz,
        batch=args.batch,
        device=args.device,
        workers=args.workers,
        project=str(args.project),
        name=args.name,
        exist_ok=True,
        resume=args.resume,
        cos_lr=args.cos_lr,
        plots=True,
        val=True,
        **augment,
    )


if __name__ == "__main__":
    train_model()
