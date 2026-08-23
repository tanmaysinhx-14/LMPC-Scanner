import shutil, yaml
from pathlib import Path
from collections import defaultdict

RAW = Path("raw")
OUT = Path("civic-dataset")

DESIRED_ORDER = ["pothole", "garbage", "graffiti"]

DATASETS = [
    "garbage1",
    "garbage2",
    "pothole",
    "graffiti"
]

CLASS_ALIASES = {
    "glass": "garbage",
    "metal": "garbage",
    "paper": "garbage",
    "plastic": "garbage",
    "waste": "garbage",
    "vandalisme": "graffiti",
    "potholes": "pothole",
    "road_damage": "pothole"
}


def resolve_name(raw_name: str) -> str:
    normalized = raw_name.lower().strip().replace(" ", "_")
    if raw_name in CLASS_ALIASES:
        return CLASS_ALIASES[raw_name]
    if normalized in CLASS_ALIASES:
        return CLASS_ALIASES[normalized]
    return normalized

# Create output directories
for split in ["train", "valid"]:
    for sub in ["images", "labels"]:
        (OUT / split / sub).mkdir(parents=True, exist_ok=True)

stats = defaultdict(int)

for dataset_name in DATASETS:
    dataset_path = RAW / dataset_name

    yaml_path = dataset_path / "data.yaml"
    if not yaml_path.exists():
        print(f"SKIP {dataset_name}: no data.yaml found")
        continue

    with open(yaml_path) as f:
        cfg = yaml.safe_load(f)

    local_names: list[str] = cfg["names"]

    id_map: dict[int, int] = {}
    for old_id, raw_name in enumerate(local_names):
        canonical = resolve_name(raw_name)
        if canonical in DESIRED_ORDER:
            id_map[old_id] = DESIRED_ORDER.index(canonical)
        else:
            print(f"  WARNING [{dataset_name}] '{raw_name}' not in DESIRED_ORDER — annotations dropped")

    print(f"\n{dataset_name}:")
    for old_id, raw_name in enumerate(local_names):
        canonical = resolve_name(raw_name)
        new_id = id_map.get(old_id, "DROP")
        print(f"  {old_id} ({raw_name}) → {new_id} ({canonical})")

    for src_split, dst_split in [("train", "train"), ("valid", "valid"), ("test", "valid")]:
        img_src = dataset_path / src_split / "images"
        lbl_src = dataset_path / src_split / "labels"

        if not img_src.exists():
            continue

        for img_file in img_src.iterdir():
            if img_file.suffix.lower() not in {".jpg", ".jpeg", ".png", ".webp"}:
                continue

            unique_stem = f"{dataset_name}_{img_file.stem}"

            shutil.copy2(
                img_file,
                OUT / dst_split / "images" / f"{unique_stem}{img_file.suffix}"
            )

            lbl_file = lbl_src / f"{img_file.stem}.txt"
            dst_lbl  = OUT / dst_split / "labels" / f"{unique_stem}.txt"

            if not lbl_file.exists():
                dst_lbl.touch()
                continue

            lines = lbl_file.read_text().splitlines()
            new_lines = []
            for line in lines:
                parts = line.strip().split()
                if not parts:
                    continue
                old_id = int(parts[0])
                if old_id not in id_map:
                    continue
                new_id = id_map[old_id]
                parts[0] = str(new_id)
                new_lines.append(" ".join(parts))
                stats[DESIRED_ORDER[new_id]] += 1

            dst_lbl.write_text("\n".join(new_lines))

final_yaml = {
    "train": str((OUT / "train" / "images").resolve()),
    "val":   str((OUT / "valid" / "images").resolve()),
    "nc":    len(DESIRED_ORDER),
    "names": DESIRED_ORDER,
}
with open(OUT / "data.yaml", "w") as f:
    yaml.dump(final_yaml, f, default_flow_style=False)

print("\n── Consolidation complete ───────────────────────────────")
print(f"Output: {OUT.resolve()}")
print(f"Classes ({len(DESIRED_ORDER)}):")
for i, name in enumerate(DESIRED_ORDER):
    print(f"  {i}  {name:<20} {stats[name]:>5} instances")
print()