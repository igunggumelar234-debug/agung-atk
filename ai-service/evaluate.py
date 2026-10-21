#!/usr/bin/env python3
"""
Evaluasi bobot YOLO ATK pada split TEST (data terpisah, tanpa kebocoran train/val).

Keluaran: ai-service/models/metrics.json
  - metrik val resmi Ultralytics: precision, recall, mAP50, mAP50-95
    (keseluruhan + per kelas) pada split test
  - threshold_sweep: akurasi klasifikasi tingkat GAMBAR untuk ambang keyakinan
    0.20-0.80 (step 0.05) x margin 0.00/0.05/0.10 — dasar pemilihan nilai
    IMAGE_SEARCH_YOLO_MIN_CONFIDENCE (diambil dari data, bukan angka karangan)
  - kecepatan inferensi (ms/gambar) & versi paket

Pakai:
  python ai-service/evaluate.py
  python ai-service/evaluate.py --weights ai-service/models/yolov8n-atk.pt --imgsz 640
"""

from __future__ import annotations

import argparse
import json
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import yaml

PROJECT_ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(Path(__file__).resolve().parent / "tools"))
from build_dataset import NON_ATK_CLASSES  # noqa: E402  — harus selaras dg LABELS PHP

DEFAULT_DATA = PROJECT_ROOT / "storage" / "app" / "datasets" / "yolo-atk" / "data.yaml"
DEFAULT_WEIGHTS = PROJECT_ROOT / "ai-service" / "models" / "yolov8n-atk.pt"
DEFAULT_OUT = PROJECT_ROOT / "ai-service" / "models" / "metrics.json"

THRESHOLDS = [round(0.20 + i * 0.05, 2) for i in range(13)]  # 0.20 .. 0.80
MARGINS = [0.0, 0.05, 0.10]


def _class_stats(box, class_id: int, position: int) -> tuple[float, float, float, float] | None:
    """Ambil (p, r, ap50, ap) satu kelas — toleran thd perbedaan API id/posisi."""
    for key in (class_id, position):
        try:
            stats = box.class_result(key)
            if stats is None or len(stats) != 4:
                continue
            return tuple(float(v) for v in stats)  # type: ignore[return-value]
        except Exception:
            continue
    return None


def load_gt(labels_dir: Path, names: dict[int, str]) -> dict[str, set[str]]:
    """GT tingkat gambar: stem -> set NAMA kelas (samakan tipe dgn prediksi)."""
    gt: dict[str, set[str]] = {}
    for lp in labels_dir.glob("*.txt"):
        labels: set[str] = set()
        for line in lp.read_text(encoding="utf-8", errors="replace").splitlines():
            parts = line.split()
            if parts:
                try:
                    cid = int(float(parts[0]))
                except ValueError:
                    continue
                labels.add(names.get(cid, str(cid)))
        if labels:
            gt[lp.stem] = labels
    return gt


def main() -> int:
    parser = argparse.ArgumentParser(description="Evaluasi YOLO ATK pada split test")
    parser.add_argument("--weights", default=str(DEFAULT_WEIGHTS))
    parser.add_argument("--data", default=str(DEFAULT_DATA))
    parser.add_argument("--imgsz", type=int, default=160)
    parser.add_argument("--split", default="test")
    parser.add_argument("--conf", type=float, default=0.05, help="Ambang minimum deteksi saat sweep (rendah agar sweep leluasa)")
    parser.add_argument("--out", default=str(DEFAULT_OUT))
    args = parser.parse_args()

    weights = Path(args.weights)
    data_path = Path(args.data)
    if not weights.is_file():
        print(f"ERROR: bobot tidak ditemukan: {weights}\n   Latih dulu: python ai-service/train.py")
        return 1
    if not data_path.is_file():
        print(f"ERROR: data.yaml tidak ditemukan: {data_path}")
        return 1

    from ultralytics import YOLO

    cfg = yaml.safe_load(data_path.read_text(encoding="utf-8"))
    names_map: dict[int, str] = {int(k): str(v) for k, v in (cfg.get("names") or {}).items()}
    data_root = Path(cfg.get("path", data_path.parent))
    images_dir = data_root / "images" / args.split
    labels_dir = data_root / "labels" / args.split
    if not images_dir.is_dir():
        print(f"ERROR: split '{args.split}' tidak ada di {images_dir}")
        return 1

    model = YOLO(str(weights))

    # ------------------------------------------------------------------ 1) val resmi
    print(f"== Val resmi ultralytics | split={args.split} imgsz={args.imgsz} ==")
    metrics = model.val(
        data=str(data_path),
        split=args.split,
        imgsz=args.imgsz,
        device="cpu",
        conf=0.001,
        iou=0.6,
        plots=False,
        save_json=False,
        verbose=True,
    )
    box = metrics.box
    names: dict[int, str] = dict(metrics.names)
    mean_p, mean_r, mean_ap50, mean_ap = (float(v) for v in box.mean_results())

    class_ids = list(getattr(box, "ap_class_index", []))
    per_class = []
    for position, cid in enumerate(class_ids):
        stats = _class_stats(box, int(cid), position)
        if stats is None:
            continue
        name = names.get(int(cid), str(cid))
        per_class.append(
            {
                "class": name,
                "is_atk": name not in NON_ATK_CLASSES,
                "precision": round(stats[0], 4),
                "recall": round(stats[1], 4),
                "map50": round(stats[2], 4),
                "map50_95": round(stats[3], 4),
            }
        )

    # ------------------------------------------------------------------ 2) sweep ambang
    print(f"== Sweep ambang keyakinan pada {args.split} ==")
    gt = load_gt(labels_dir, names_map)
    n_no_gt = 0
    image_preds: list[dict] = []
    t0 = time.time()
    results = model.predict(
        source=str(images_dir),
        conf=args.conf,
        iou=0.45,
        imgsz=args.imgsz,
        device="cpu",
        batch=8,
        stream=True,
        verbose=False,
    )
    for res in results:
        stem = Path(res.path).stem
        dets = sorted(
            ((res.names[int(b.cls[0])], float(b.conf[0])) for b in res.boxes),
            key=lambda x: -x[1],
        )
        gt_ids = gt.get(stem)
        if not gt_ids:
            n_no_gt += 1
            continue
        image_preds.append({"stem": stem, "dets": dets, "gt": gt_ids})
    sweep_seconds = round(time.time() - t0, 1)

    n_total = len(image_preds)
    sweep: list[dict] = []
    for thr in THRESHOLDS:
        for margin_min in MARGINS:
            n_pred = 0
            n_correct = 0
            n_pred_atk = 0
            n_correct_atk = 0
            for item in image_preds:
                dets = [(lbl, c) for lbl, c in item["dets"] if c >= thr]
                if not dets:
                    continue
                best_label, best_conf = dets[0]
                second_conf = 0.0
                for lbl, c in dets[1:]:
                    if lbl != best_label:
                        second_conf = c
                        break
                margin = best_conf - second_conf
                if margin < margin_min:
                    continue
                n_pred += 1
                is_atk = best_label not in NON_ATK_CLASSES
                correct = best_label in item["gt"]
                if correct:
                    n_correct += 1
                if is_atk:
                    n_pred_atk += 1
                    if correct and any(g not in NON_ATK_CLASSES for g in item["gt"]):
                        n_correct_atk += 1
            sweep.append(
                {
                    "min_confidence": thr,
                    "min_margin": margin_min,
                    "predicted": n_pred,
                    "coverage": round(n_pred / n_total, 4) if n_total else 0.0,
                    "precision": round(n_correct / n_pred, 4) if n_pred else None,
                    "recall": round(n_correct / n_total, 4) if n_total else 0.0,
                    "atk_predicted": n_pred_atk,
                    "atk_precision": round(n_correct_atk / n_pred_atk, 4) if n_pred_atk else None,
                }
            )

    # Saran ambang: presisi rekomendasi (atk_precision) >= 0.90, cakupan tertinggi.
    ranked = sorted(
        (row for row in sweep if row["atk_precision"] is not None and row["atk_precision"] >= 0.90),
        key=lambda row: (-row["coverage"], -row["min_confidence"]),
    )
    suggestion = ranked[0] if ranked else None

    speed = getattr(metrics, "speed", {}) or {}
    out_path = Path(args.out)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "weights": str(weights),
        "data": str(data_path),
        "split": args.split,
        "image_count": n_total,
        "images_without_gt_label": n_no_gt,
        "imgsz": args.imgsz,
        "overall": {
            "precision": round(mean_p, 4),
            "recall": round(mean_r, 4),
            "map50": round(mean_ap50, 4),
            "map50_95": round(mean_ap, 4),
        },
        "per_class": per_class,
        "threshold_sweep": sweep,
        "threshold_suggestion": suggestion,
        "sweep_seconds": sweep_seconds,
        "speed_ms_per_image": {k: round(float(v), 2) for k, v in speed.items()},
        "versions": {
            "python": sys.version.split()[0],
            "ultralytics": __import__("ultralytics").__version__,
            "torch": __import__("torch").__version__,
        },
    }
    out_path.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")

    print("\n== HASIL EVALUASI (split test) ==")
    print(f"   gambar test   : {n_total}" + (f" (+{n_no_gt} tanpa label GT)" if n_no_gt else ""))
    print(f"   precision     : {payload['overall']['precision']}")
    print(f"   recall        : {payload['overall']['recall']}")
    print(f"   mAP50         : {payload['overall']['map50']}")
    print(f"   mAP50-95      : {payload['overall']['map50_95']}")
    print(f"   speed         : {payload['speed_ms_per_image']}")
    if suggestion:
        print(f"   saran ambang  : min_confidence={suggestion['min_confidence']} "
              f"min_margin={suggestion['min_margin']} (atk_precision={suggestion['atk_precision']}, "
              f"coverage={suggestion['coverage']})")
    else:
        print("   saran ambang  : TIDAK ADA baris dengan atk_precision >= 0.90 — lihat threshold_sweep.json di metrics.json")
    print(f"   laporan       : {out_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
