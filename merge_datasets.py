import shutil
import yaml
from tqdm import tqdm
from pathlib import Path

RAW = Path("raw")
OUT = Path("merged")
DATASETS = ["garbage", "fallen_tree", "graffiti"]
TARGET_CLASSES = ["garbage", "fallen_tree", "graffiti"]

CLASS_MAP = {
    "garbage": "garbage",
    "fallen_tree": "fallen_tree",
    "graffiti": "graffiti"
}

configs = {}
for name in DATASETS:
    with open(RAW / name / "data.yaml") as f:
        configs[name] = yaml.safe_load(f)

for split in ["train", "valid", "test"]:
    for sub in ["images", "labels"]:
        (OUT / split / sub).mkdir(parents=True, exist_ok=True)

for name in DATASETS:
    local_classes = configs[name]["names"]
    id_map = {}
    for i, cls in enumerate(local_classes):
        if cls in CLASS_MAP:
            id_map[i] = TARGET_CLASSES.index(CLASS_MAP[cls])
        else:
            id_map[i] = -1

    for split in ["train", "valid", "test"]:
        img_dir = RAW / name / split / "images"
        lbl_dir = RAW / name / split / "labels"
        if not img_dir.exists():
            continue

        img_files = [f for f in img_dir.iterdir() if f.suffix.lower() in {".jpg", ".jpeg", ".png", ".webp"}]
        
        for img_file in tqdm(img_files, desc=f"{name} - {split}"):
            stem = f"{name}_{img_file.stem}"
            shutil.copy2(img_file, OUT / split / "images" / f"{stem}{img_file.suffix}")

            src_lbl = lbl_dir / f"{img_file.stem}.txt"
            dst_lbl = OUT / split / "labels" / f"{stem}.txt"

            if src_lbl.exists():
                lines = src_lbl.read_text().splitlines()
                remapped = []
                for line in lines:
                    parts = line.strip().split()
                    if parts:
                        new_id = id_map.get(int(parts[0]), -1)
                        if new_id != -1:
                            parts[0] = str(new_id)
                            remapped.append(" ".join(parts))
                dst_lbl.write_text("\n".join(remapped))
            else:
                dst_lbl.touch()

merged_yaml = {
    "train": "train/images",
    "val": "valid/images",
    "test": "test/images",
    "nc": len(TARGET_CLASSES),
    "names": TARGET_CLASSES,
}

with open(OUT / "data.yaml", "w") as f:
    yaml.dump(merged_yaml, f, default_flow_style=False)

print("\nMerge complete:", merged_yaml)