#!/usr/bin/env python3
"""
Bangun dataset deteksi objek YOLO (kelas ATK) dari sumber yang sudah tersedia.

Sumber yang didukung:
  1. raw/zip1  - DatasetBarangATK (images.cv, klasifikasi per-folder, tanpa kotak)
  2. raw/zip2  - Roboflow "Office Items" via images.cv (klasifikasi per-folder)
  3. raw/school-supplies - OPSIONAL, unduhan manual Roboflow "School Supplies"
     (mendukung ekspor YOLOv8 [images/labels], YOLOv5 [txt sebelah gambar],
      COCO JSON, atau klasifikasi per-folder)

Prinsip (sesuai spesifikasi tugas):
  * Sumber asli HANYA dibaca — semua keluaran ditulis ke direktori terpisah
    (default: storage/app/datasets/yolo-atk), tanpa menimpa dataset lama.
  * Nama kelas dinormalisasi lewat CLASS_ALIASES; kelas tak dikenal DIBUANG dan
    dilaporkan (kelas non-ATK kanonik tetap dipertahankan sebagai kelas tolak,
    bukan kelas yang bisa dicari di katalog).
  * Duplikat dikelompokkan (md5 byte + dHash64) lalu SATU grup = SATU split,
    sehingga tidak ada gambar bocor antar train/validation/test.
  * Gambar klasifikasi dikonversi ke format YOLO dengan kotak penuh frame
    (cx=0.5, cy=0.5, w=1, h=1) — konversi terekspresi, divalidasi per baris.
  * Validasi akhir: jumlah gambar == jumlah label, tiap label ≥1 baris valid,
    koordinat dalam [0,1], semua gambar berhasil dibuka PIL.
  * Laporan: metadata/dataset-report.json + metadata/ATTRIBUTION.md.

Penggunaan:
  python ai-service/tools/build_dataset.py            # bangun dataset
  python ai-service/tools/build_dataset.py --force    # timpa keluaran lama
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import random
import shutil
import sys
from datetime import datetime, timezone
from pathlib import Path

from PIL import Image

PROJECT_ROOT = Path(__file__).resolve().parents[2]
RAW_ROOT = PROJECT_ROOT / "storage" / "app" / "datasets" / "atk-image-search" / "raw"
DEFAULT_OUTPUT = PROJECT_ROOT / "storage" / "app" / "datasets" / "yolo-atk"

IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".webp", ".bmp"}

# ---------------------------------------------------------------------------
# Normalisasi nama kelas -> kanonik (harus selaras dengan LABELS di
# app/Services/ATKImageRecognitionService.php).
# ---------------------------------------------------------------------------
CLASS_ALIASES: dict[str, str] = {
    # --- sumber lokal (zip1/zip2) ---
    "office": "office_supplies",
    "office_supplies": "office_supplies",
    "office supplies": "office_supplies",
    "office_equipment": "office_supplies",
    "scissors": "scissors",
    "gunting": "scissors",
    "paper_notebook": "notebook",
    "paper notebook": "notebook",
    "notebook": "notebook",
    "buku tulis": "notebook",
    "pen": "pen",
    "pulpen": "pen",
    "pena": "pen",
    "punchers": "puncher",
    "puncher": "puncher",
    "pembolong": "puncher",
    "ring_binder": "ring_binder",
    "ring binder": "ring_binder",
    "stapler": "stapler",
    "tape_dispenser": "tape",
    "tape dispenser": "tape",
    "tape": "tape",
    "letter_tray": "letter_tray",
    "letter tray": "letter_tray",
    "calculator": "calculator",
    "desk_chair": "desk_chair",
    "desk lamp": "desk_lamp",
    "desk_lamp": "desk_lamp",
    "file_cabinet": "file_cabinet",
    "laptop_computer": "laptop_computer",
    "laptop computer": "laptop_computer",
    "printer": "printer",
    "trash_can": "trash_can",
    "trash can": "trash_can",
    # --- Roboflow School Supplies (opsional) ---
    "pencil": "pencil",
    "pensil": "pencil",
    "eraser": "eraser",
    "penghapus": "eraser",
    "ruler": "ruler",
    "penggaris": "ruler",
    "highlighter": "highlighter",
    "stabilo": "highlighter",
    "marker": "marker",
    "spidol": "marker",
    "whiteboard marker": "marker",
    "compass": "compass",
    "jangka": "compass",
    "correction pen": "tape",
    "tip ex": "tape",
    "correction": "tape",
}

# Urutan kanonik = urutan LABELS pada ATKImageRecognitionService.php
# (kelas ATK dulu, lalu kelas non-ATK untuk penolakan).
ATK_CLASSES: list[str] = [
    "office_supplies",
    "pen",
    "notebook",
    "scissors",
    "stapler",
    "puncher",
    "ring_binder",
    "tape",
    "letter_tray",
    "pencil",
    "eraser",
    "ruler",
    "highlighter",
    "marker",
    "compass",
]
NON_ATK_CLASSES: list[str] = [
    "calculator",
    "desk_chair",
    "desk_lamp",
    "file_cabinet",
    "laptop_computer",
    "printer",
    "trash_can",
]
CANONICAL_ORDER: list[str] = ATK_CLASSES + NON_ATK_CLASSES
NON_ATK_SET: set[str] = set(NON_ATK_CLASSES)

SOURCES: list[dict[str, str]] = [
    {
        "name": "zip1-datasetbarangatk",
        "root": str(RAW_ROOT / "zip1"),
        "license": "Tidak tercantum pada metadata (images.cv) — perlu verifikasi sebelum distribusi.",
    },
    {
        "name": "zip2-roboflow-office-items",
        "root": str(RAW_ROOT / "zip2"),
        "license": "Tidak tercantum pada metadata (images.cv) — perlu verifikasi sebelum distribusi.",
    },
    {
        "name": "school-supplies",
        "root": str(RAW_ROOT / "school-supplies"),
        "license": "CC BY 4.0 (Roboflow Universe, author: AYLA) — atribusi wajib, lihat ATTRIBUTION.md.",
    },
]

ATTRIBUTION_TEMPLATE = """# Atribusi & Lisensi Dataset (YOLO ATK)

Dataset gabungan di direktori ini dibangun oleh `ai-service/tools/build_dataset.py`
dari sumber-sumber berikut. Sumber asli TIDAK diubah maupun dihapus.

| Sumber | Lokasi asli | Jumlah gambar | Lisensi |
|---|---|---|---|
{rows}

## Ketentuan

- **DatasetBarangATK (zip1)** & **Office Items (zip2)**: berkas `meta.json`
  dari images.cv TIDAK mencantumkan lisensi. Hak penggunaan belum terverifikasi —
  jangan mendistribusikan ulang dataset ini sebelum lisensi dipastikan.
- **School Supplies (Roboflow Universe)**: lisensi **CC BY 4.0** (Creative Commons
  Atribusi 4.0). Wajib mencantumkan atribusi berikut pada dokumen/karya yang
  memakai dataset ini:

  > @misc{{ school-supplies-su2af_dataset,
  >   title = {{ School Supplies Dataset }},
  >   type = {{ Open Source Dataset }},
  >   author = {{ AYLA }},
  >   howpublished = {{ \\url{{ https://universe.roboflow.com/ayla-cpohc/school-supplies-su2af }} }},
  >   url = {{ https://universe.roboflow.com/ayla-cpohc/school-supplies-su2af }},
  >   journal = {{ Roboflow Universe }},
  >   publisher = {{ Roboflow }},
  >   year = {{ 2025 }},
  >   month = {{ dec }},
  > }}
"""


def canonical_label(raw: str) -> str | None:
    """Normalisasi nama kelas mentah -> kanonik (None = tak dikenal)."""
    key = raw.strip().lower().replace("-", " ").replace("_", "_")
    if key in CLASS_ALIASES:
        return CLASS_ALIASES[key]
    key2 = key.replace("_", " ")
    return CLASS_ALIASES.get(key2)


# ---------------------------------------------------------------------------
# Pembacaan sumber
# ---------------------------------------------------------------------------


def is_image_file(path: Path) -> bool:
    return path.suffix.lower() in IMAGE_EXTS


def load_names_from_data_yaml(yaml_path: Path) -> list[str] | None:
    """Baca daftar nama kelas dari data.yaml (tanpa dependensi pyyaml wajib)."""
    try:
        import yaml  # type: ignore

        data = yaml.safe_load(yaml_path.read_text(encoding="utf-8"))
        names = data.get("names") if isinstance(data, dict) else None
        if isinstance(names, dict):
            return [str(names[k]) for k in sorted(names)]
        if isinstance(names, list):
            return [str(n) for n in names]
    except Exception:
        pass
    # Fallback: parser sederhana untuk format Roboflow.
    try:
        lines = yaml_path.read_text(encoding="utf-8").splitlines()
    except OSError:
        return None
    names: list[str] = []
    in_names = False
    for line in lines:
        stripped = line.strip()
        if stripped.startswith("names:"):
            in_names = True
            rest = stripped[len("names:") :].strip()
            if rest.startswith("["):
                return [s.strip().strip("'\"") for s in rest.strip("[]").split(",") if s.strip()]
            continue
        if in_names:
            if stripped.startswith("- "):
                names.append(stripped[2:].strip().strip("'\""))
            elif ":" in stripped and stripped[0].isdigit():
                names.append(stripped.split(":", 1)[1].strip().strip("'\""))
            elif stripped and not stripped.startswith("#"):
                break
    return names or None


def find_data_yaml(start: Path, stop: Path) -> Path | None:
    cur = start.resolve()
    stop_r = stop.resolve()
    for _ in range(6):
        for cand in (cur / "data.yaml", cur / "data.yml"):
            if cand.is_file():
                return cand
        if cur == stop_r or cur.parent == cur:
            break
        cur = cur.parent
    # cari rekursif dari root sumber
    for cand in sorted(start.rglob("data.y*ml")):
        return cand
    return None


def read_yolo_label_file(path: Path, names: list[str]) -> tuple[list[tuple[str, float, float, float, float]], int, int]:
    """Baca satu file label YOLO -> (valid_boxes, invalid_lines, unknown_boxes)."""
    boxes: list[tuple[str, float, float, float, float]] = []
    invalid = 0
    unknown = 0
    try:
        content = path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return [], 1, 0
    for line in content.splitlines():
        line = line.strip()
        if not line:
            continue
        parts = line.split()
        if len(parts) < 5:
            invalid += 1
            continue
        try:
            cls_id = int(float(parts[0]))
            cx, cy, w, h = (float(v) for v in parts[1:5])
        except ValueError:
            invalid += 1
            continue
        if not (0.0 <= cx <= 1.0 and 0.0 <= cy <= 1.0 and 0.0 < w <= 1.0 and 0.0 < h <= 1.0):
            invalid += 1
            continue
        if cx - w / 2 < -0.01 or cx + w / 2 > 1.01 or cy - h / 2 < -0.01 or cy + h / 2 > 1.01:
            invalid += 1
            continue
        if cls_id < 0 or cls_id >= len(names):
            invalid += 1
            continue
        label = canonical_label(names[cls_id])
        if label is None:
            unknown += 1
            continue
        boxes.append((label, cx, cy, w, h))
    return boxes, invalid, unknown


def discover_folder_source(root: Path, source_name: str) -> tuple[list[dict], dict]:
    """Sumber klasifikasi per-folder: data/<split>/<kelas>/*.jpg."""
    records: list[dict] = []
    stats = {"unknown_class_files": 0, "invalid_annotation_lines": 0, "unknown_box_classes": 0}
    for path in sorted(root.rglob("*")):
        if not path.is_file() or not is_image_file(path):
            continue
        raw_label = path.parent.name
        label = canonical_label(raw_label)
        if label is None:
            stats["unknown_class_files"] += 1
            continue
        records.append(
            {
                "source": source_name,
                "path": path,
                "label": label,
                "boxes": [(label, 0.5, 0.5, 1.0, 1.0)],  # kotak penuh frame
            }
        )
    return records, stats


def discover_yolo_source(root: Path, source_name: str, pairs: list[tuple[Path, Path]]) -> tuple[list[dict], dict]:
    """Sumber format YOLO (images/ + labels/)."""
    records: list[dict] = []
    stats = {"unknown_class_files": 0, "invalid_annotation_lines": 0, "unknown_box_classes": 0, "images_without_labels": 0}
    yaml_path = find_data_yaml(root, pairs[0][0].parent)
    names = load_names_from_data_yaml(yaml_path) if yaml_path else None
    if not names:
        print(f"  [SKIP] {source_name}: data.yaml tidak ditemukan / tanpa daftar kelas — sumber dilewati agar tidak salah interpretasi.")
        return [], {**stats, "skipped": True}

    for images_dir, labels_dir in pairs:
        for img in sorted(images_dir.rglob("*")):
            if not img.is_file() or not is_image_file(img):
                continue
            rel = img.relative_to(images_dir)
            label_file = (labels_dir / rel).with_suffix(".txt")
            if not label_file.is_file():
                stats["images_without_labels"] += 1
                continue
            boxes, invalid, unknown = read_yolo_label_file(label_file, names)
            stats["invalid_annotation_lines"] += invalid
            stats["unknown_box_classes"] += unknown
            if not boxes:
                continue
            records.append(
                {
                    "source": source_name,
                    "path": img,
                    "label": boxes[0][0],
                    "boxes": boxes,
                }
            )
    return records, stats


def discover_flat_yolo_source(root: Path, source_name: str, dirs: list[Path]) -> tuple[list[dict], dict]:
    """Sumber YOLOv5 lawas: txt anotasi sebelah gambar dalam folder yang sama."""
    records: list[dict] = []
    stats = {"unknown_class_files": 0, "invalid_annotation_lines": 0, "unknown_box_classes": 0, "images_without_labels": 0}
    yaml_path = find_data_yaml(root, dirs[0])
    names = load_names_from_data_yaml(yaml_path) if yaml_path else None
    if not names:
        print(f"  [SKIP] {source_name}: data.yaml tidak ditemukan untuk format txt-datar — sumber dilewati.")
        return [], {**stats, "skipped": True}

    for d in dirs:
        for img in sorted(d.iterdir()):
            if not img.is_file() or not is_image_file(img):
                continue
            label_file = img.with_suffix(".txt")
            if not label_file.is_file():
                stats["images_without_labels"] += 1
                continue
            boxes, invalid, unknown = read_yolo_label_file(label_file, names)
            stats["invalid_annotation_lines"] += invalid
            stats["unknown_box_classes"] += unknown
            if not boxes:
                continue
            records.append({"source": source_name, "path": img, "label": boxes[0][0], "boxes": boxes})
    return records, stats


def discover_coco_source(root: Path, source_name: str, json_files: list[Path]) -> tuple[list[dict], dict]:
    """Sumber COCO JSON (ekspor Roboflow)."""
    records: list[dict] = []
    stats = {"unknown_class_files": 0, "invalid_annotation_lines": 0, "unknown_box_classes": 0, "images_without_labels": 0}
    for jf in json_files:
        try:
            coco = json.loads(jf.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            print(f"  [WARN] {source_name}: gagal baca {jf.name}: {exc}")
            continue
        categories = {int(c["id"]): str(c["name"]) for c in coco.get("categories", [])}
        anns_by_img: dict[int, list] = {}
        for ann in coco.get("annotations", []):
            if ann.get("iscrowd", 0):
                continue
            anns_by_img.setdefault(int(ann["image_id"]), []).append(ann)
        for info in coco.get("images", []):
            file_name = str(info.get("file_name", ""))
            candidates = [jf.parent / file_name, jf.parent.parent / file_name, root / file_name]
            img_path = next((c for c in candidates if c.is_file()), None)
            if img_path is None:
                stats["images_without_labels"] += 1
                continue
            width = float(info.get("width") or 0)
            height = float(info.get("height") or 0)
            boxes: list[tuple[str, float, float, float, float]] = []
            for ann in anns_by_img.get(int(info["id"]), []):
                raw_name = categories.get(int(ann.get("category_id", -1)), "")
                label = canonical_label(raw_name)
                if label is None:
                    stats["unknown_box_classes"] += 1
                    continue
                x, y, w, h = (float(v) for v in ann.get("bbox", [0, 0, 0, 0])[:4])
                if w <= 0 or h <= 0 or width <= 0 or height <= 0:
                    stats["invalid_annotation_lines"] += 1
                    continue
                cx, cy = (x + w / 2) / width, (y + h / 2) / height
                nw, nh = w / width, h / height
                if not (0 < nw <= 1 and 0 < nh <= 1 and -0.01 <= cx - nw / 2 and cx + nw / 2 <= 1.01):
                    stats["invalid_annotation_lines"] += 1
                    continue
                boxes.append((label, min(max(cx, 0.0), 1.0), min(max(cy, 0.0), 1.0), nw, nh))
            if not boxes:
                stats["images_without_labels"] += 1
                continue
            records.append({"source": source_name, "path": img_path, "label": boxes[0][0], "boxes": boxes})
    return records, stats


def discover_source(source: dict[str, str]) -> tuple[list[dict], dict, str]:
    """Deteksi layout otomatis untuk satu sumber."""
    root = Path(source["root"])
    name = source["name"]
    if not root.is_dir():
        print(f"  [INFO] {name}: belum tersedia ({root}) — dilewati.")
        return [], {"missing": True}, "missing"

    # 1) YOLO berpasangan: <dir>/images + <dir>/labels
    pairs: list[tuple[Path, Path]] = []
    for d in root.rglob("*"):
        if d.is_dir() and (d / "images").is_dir() and (d / "labels").is_dir():
            pairs.append((d / "images", d / "labels"))
    if pairs:
        records, stats = discover_yolo_source(root, name, pairs)
        return records, stats, "yolo"

    # 2) YOLOv5 datar: >=60% gambar punya txt saudara
    flat_dirs: list[Path] = []
    for d in root.rglob("*"):
        if not d.is_dir():
            continue
        imgs = [f for f in d.iterdir() if f.is_file() and is_image_file(f)]
        if len(imgs) < 3:
            continue
        paired = sum(1 for f in imgs if f.with_suffix(".txt").is_file())
        if paired / len(imgs) >= 0.6:
            flat_dirs.append(d)
    if flat_dirs:
        records, stats = discover_flat_yolo_source(root, name, flat_dirs)
        return records, stats, "yolo_flat"

    # 3) COCO JSON
    coco_files = sorted(root.rglob("*annotations*.json")) or sorted(root.rglob("*coco*.json"))
    if coco_files:
        records, stats = discover_coco_source(root, name, coco_files)
        return records, stats, "coco"

    # 4) Klasifikasi per-folder
    records, stats = discover_folder_source(root, name)
    return records, stats, "folders"


# ---------------------------------------------------------------------------
# Normalisasi, dedup, split, penulisan
# ---------------------------------------------------------------------------


def fingerprint(record: dict) -> tuple[str | None, int | None, tuple[int, int] | None]:
    """Kembalikan (md5, dHash64, ukuran) — md5/dHash None bila gambar rusak."""
    path: Path = record["path"]
    try:
        data = path.read_bytes()
        with Image.open(io.BytesIO(data)) as im:
            im.load()
            size = im.size
            gray = im.convert("L").resize((9, 8), Image.BILINEAR)
            px = gray.tobytes()
    except Exception:
        return None, None, None
    bits = 0
    for row in range(8):
        for col in range(8):
            bits = (bits << 1) | (1 if px[row * 9 + col] > px[row * 9 + col + 1] else 0)
    return hashlib.md5(data).hexdigest(), bits, size


def stratified_split(groups_by_label: dict[str, list[int]], seed: int, ratios: tuple[float, float, float]) -> dict[int, str]:
    """Bagi grup per kelas secara stratifik (unit = grup duplikat, tanpa bocor)."""
    train_r, val_r, _test_r = ratios
    assignment: dict[int, str] = {}
    for label in sorted(groups_by_label):
        idxs = list(groups_by_label[label])
        rng = random.Random(f"{seed}:{label}")
        rng.shuffle(idxs)
        n = len(idxs)
        n_train = round(n * train_r)
        n_val = round(n * val_r)
        n_train = min(n_train, n)
        n_val = min(n_val, n - n_train)
        for i in idxs[:n_train]:
            assignment[i] = "train"
        for i in idxs[n_train : n_train + n_val]:
            assignment[i] = "val"
        for i in idxs[n_train + n_val :]:
            assignment[i] = "test"
    return assignment


def main() -> int:
    parser = argparse.ArgumentParser(description="Bangun dataset YOLO deteksi ATK (gabungan sumber lokal + opsional Roboflow).")
    parser.add_argument("--output", default=str(DEFAULT_OUTPUT), help="Direktori keluaran (default: storage/app/datasets/yolo-atk)")
    parser.add_argument("--force", action="store_true", help="Timpa keluaran yang sudah ada")
    parser.add_argument("--seed", type=int, default=42, help="Seed pembagian split (default 42)")
    parser.add_argument("--train", type=float, default=0.70, help="Rasio train (default 0.70)")
    parser.add_argument("--val", type=float, default=0.15, help="Rasio validation (default 0.15)")
    parser.add_argument("--test", type=float, default=0.15, help="Rasio test (default 0.15)")
    args = parser.parse_args()

    ratios = (args.train, args.val, args.test)
    if abs(sum(ratios) - 1.0) > 1e-6:
        print("ERROR: rasio --train/--val/--test harus berjumlah 1.0")
        return 1

    out_dir = Path(args.output).resolve()
    if out_dir.exists() and any(out_dir.iterdir()):
        if not args.force:
            print(f"ERROR: {out_dir} sudah berisi data. Gunakan --force untuk membangun ulang (keluaran lama akan diganti; sumber asli tetap aman).")
            return 1
        shutil.rmtree(out_dir)

    print("== 1. Membaca sumber ==")
    all_records: list[dict] = []
    source_reports: list[dict] = []
    dropped: dict[str, int] = {
        "corrupt_images": 0,
        "unknown_class_files": 0,
        "invalid_annotation_lines": 0,
        "unknown_box_classes": 0,
        "images_without_labels": 0,
        "duplicates": 0,
        "cross_label_duplicates": 0,
    }
    unknown_classes_seen: set[str] = set()

    for source in SOURCES:
        records, stats, layout = discover_source(source)
        print(f"  - {source['name']}: layout={layout}, gambar beranotasi={len(records)}")
        source_reports.append(
            {
                "name": source["name"],
                "root": str(Path(source["root"])),
                "layout": layout,
                "license": source["license"],
                "records": len(records),
                "stats": stats,
            }
        )
        for key in ("unknown_class_files", "invalid_annotation_lines", "unknown_box_classes", "images_without_labels"):
            dropped[key] += int(stats.get(key, 0))
        all_records.extend(records)

    if not all_records:
        print("ERROR: tidak ada gambar yang bisa dibaca dari sumber mana pun.")
        return 1

    print(f"== 2. Validasi gambar + fingerprint ({len(all_records)} kandidat) ==")
    valid: list[dict] = []
    md5_map: dict[str, list[int]] = {}
    dhash_map: dict[int, list[int]] = {}
    for rec in all_records:
        md5, dhash, size = fingerprint(rec)
        if md5 is None:
            dropped["corrupt_images"] += 1
            continue
        rec["md5"] = md5
        rec["dhash"] = dhash
        rec["size"] = size
        idx = len(valid)
        valid.append(rec)
        md5_map.setdefault(md5, []).append(idx)
        dhash_map.setdefault(dhash, []).append(idx)

    if not valid:
        print("ERROR: semua gambar rusak/tidak terbaca.")
        return 1

    print("== 3. Pengelompokan duplikat (md5 + dHash64) ==")
    parent = list(range(len(valid)))

    def find(x: int) -> int:
        while parent[x] != x:
            parent[x] = parent[parent[x]]
            x = parent[x]
        return x

    def union(a: int, b: int) -> None:
        ra, rb = find(a), find(b)
        if ra != rb:
            parent[rb] = ra

    for idxs in md5_map.values():
        for i in idxs[1:]:
            union(idxs[0], i)
    for idxs in dhash_map.values():
        for i in idxs[1:]:
            union(idxs[0], i)

    groups: dict[int, list[int]] = {}
    for i in range(len(valid)):
        groups.setdefault(find(i), []).append(i)

    # Satu grup = satu label (kanonik); selisih = duplikat lintas-label → buang sisanya.
    final_records: list[dict] = []
    group_label: dict[int, str] = {}
    for root_idx, members in sorted(groups.items()):
        members_sorted = sorted(members, key=lambda i: (valid[i]["label"], valid[i]["source"], valid[i]["md5"]))
        keep = members_sorted[0]
        labels = {valid[i]["label"] for i in members_sorted}
        if len(labels) > 1:
            dropped["cross_label_duplicates"] += len(members_sorted) - 1
        dropped["duplicates"] += len(members_sorted) - 1
        group_label[len(final_records)] = valid[keep]["label"]
        final_records.append(valid[keep])

    total_groups = len(final_records)
    print(f"   grup unik: {total_groups} (buang {dropped['duplicates']} duplikat; {dropped['cross_label_duplicates']} lintas-label)")

    print("== 4. Pembagian split stratifik (unit = grup duplikat) ==")
    groups_by_label: dict[str, list[int]] = {}
    for gi, label in group_label.items():
        groups_by_label.setdefault(label, []).append(gi)
    assignment = stratified_split(groups_by_label, args.seed, ratios)

    print("== 5. Menulis gambar + label YOLO ==")
    img_root = out_dir / "images"
    lbl_root = out_dir / "labels"
    for split in ("train", "val", "test"):
        (img_root / split).mkdir(parents=True, exist_ok=True)
        (lbl_root / split).mkdir(parents=True, exist_ok=True)

    # Semua label yang muncul (primer maupun kotak sekunder) -> indeks data.yaml.
    all_box_labels = {lbl for rec in final_records for lbl, *_ in rec["boxes"]}
    present_labels = sorted(all_box_labels, key=lambda x: CANONICAL_ORDER.index(x))
    class_index = {label: i for i, label in enumerate(present_labels)}

    per_split_counter: dict[str, int] = {"train": 0, "val": 0, "test": 0}
    per_class_counter: dict[str, dict[str, int]] = {label: {"train": 0, "val": 0, "test": 0} for label in present_labels}

    for gi, rec in enumerate(final_records):
        split = assignment[gi]
        out_name = f"{rec['source']}__{rec['label']}__{rec['md5'][:10]}{rec['path'].suffix.lower()}"
        shutil.copyfile(rec["path"], img_root / split / out_name)
        lines = []
        for label, cx, cy, w, h in rec["boxes"]:
            lines.append(f"{class_index[label]} {cx:.6f} {cy:.6f} {w:.6f} {h:.6f}")
        (lbl_root / split / (Path(out_name).stem + ".txt")).write_text("\n".join(lines) + "\n", encoding="utf-8")
        per_split_counter[split] += 1
        per_class_counter[rec["label"]][split] += 1

    print("== 6. Validasi akhir ==")
    errors: list[str] = []
    for split in ("train", "val", "test"):
        imgs = sorted((img_root / split).glob("*"))
        lbls = sorted((lbl_root / split).glob("*.txt"))
        if len(imgs) != len(lbls):
            errors.append(f"{split}: gambar={len(imgs)} != label={len(lbls)}")
        img_stems = {p.stem for p in imgs}
        lbl_stems = {p.stem for p in lbls}
        if img_stems != lbl_stems:
            errors.append(f"{split}: nama file gambar/label tidak cocok ({len(img_stems ^ lbl_stems)} beda)")
        for lp in lbls:
            content = lp.read_text(encoding="utf-8").strip()
            if not content:
                errors.append(f"{split}: label kosong {lp.name}")
                continue
            for line in content.splitlines():
                parts = line.split()
                if len(parts) != 5:
                    errors.append(f"{split}: format baris salah di {lp.name}")
                    continue
                cx, cy, w, h = (float(v) for v in parts[1:5])
                if not (0 <= cx <= 1 and 0 <= cy <= 1 and 0 < w <= 1 and 0 < h <= 1):
                    errors.append(f"{split}: koordinat di luar [0,1] di {lp.name}")
    if errors:
        for e in errors[:20]:
            print(f"  [ERROR] {e}")
        print(f"  ... total {len(errors)} galat validasi")
        return 1
    print("   OK: semua split valid (pasangan gambar/label, ≥1 baris, koordinat [0,1])")

    # data.yaml
    data_yaml = (
        f"# Dibangun otomatis oleh ai-service/tools/build_dataset.py "
        f"({datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%SZ')})\n"
        f"path: {out_dir.as_posix()}\n"
        "train: images/train\n"
        "val: images/val\n"
        "test: images/test\n"
        f"nc: {len(present_labels)}\n"
        "names:\n"
        + "".join(f"  {i}: {name}\n" for i, name in enumerate(present_labels))
    )
    (out_dir / "data.yaml").write_text(data_yaml, encoding="utf-8")

    # Laporan
    report = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "builder": "ai-service/tools/build_dataset.py",
        "seed": args.seed,
        "split_ratios": {"train": ratios[0], "val": ratios[1], "test": ratios[2]},
        "output_dir": str(out_dir),
        "sources": source_reports,
        "totals": {
            "images_written": total_groups,
            "images_copied_per_split": per_split_counter,
            "classes_present": len(present_labels),
            "labels_per_image": "YOLO (class cx cy w h ternormalisasi)",
            "classification_conversion": "kotak penuh frame (0.5 0.5 1 1) untuk sumber tanpa anotasi kotak",
        },
        "class_distribution": {label: per_class_counter[label] for label in present_labels},
        "class_order_data_yaml": present_labels,
        "absent_canonical_classes": [c for c in CANONICAL_ORDER if c not in present_labels],
        "dropped": dropped,
        "validation_errors": errors,
    }
    meta_dir = out_dir / "metadata"
    meta_dir.mkdir(parents=True, exist_ok=True)
    (meta_dir / "dataset-report.json").write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    # Atribusi
    rows = []
    for s in source_reports:
        missing = s["stats"].get("missing")
        count = s["records"] if not missing else "—"
        lic = next(x["license"] for x in SOURCES if x["name"] == s["name"])
        rows.append(f"| {s['name']} | `{s['root']}` | {count} | {lic} |")
    (meta_dir / "ATTRIBUTION.md").write_text(
        ATTRIBUTION_TEMPLATE.format(rows="\n".join(rows)), encoding="utf-8"
    )

    print("\n== RINGKASAN ==")
    print(f"  total gambar : {total_groups}  (train {per_split_counter['train']} / val {per_split_counter['val']} / test {per_split_counter['test']})")
    print(f"  kelas        : {len(present_labels)} -> {', '.join(present_labels)}")
    if report["absent_canonical_classes"]:
        print(f"  kelas kanonik tanpa data: {', '.join(report['absent_canonical_classes'])}")
    print(f"  dibuang      : rusak={dropped['corrupt_images']}, duplikat={dropped['duplicates']}, "
          f"kelas tak dikenal={dropped['unknown_class_files']} file + {dropped['unknown_box_classes']} kotak, "
          f"anotasi rusak={dropped['invalid_annotation_lines']}, tanpa label={dropped['images_without_labels']}")
    print(f"  laporan      : {(meta_dir / 'dataset-report.json')}")
    print(f"  atribusi     : {(meta_dir / 'ATTRIBUTION.md')}")
    print(f"  data.yaml    : {out_dir / 'data.yaml'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
