#!/usr/bin/env python3
"""
Latih YOLOv8 (Ultralytics) pada dataset deteksi ATK gabungan.

Contoh:
  python ai-service/train.py                      # 25 epoch, 640x640, batch 4
  python ai-service/train.py --epochs 50 --batch 8
  python ai-service/train.py --model yolov8s.pt   # base model lebih besar

Penyesuaian mesin (i3-6006U 2C/4T, RAM 4 GB):
  * batch kecil (4) + workers 0 + cache=False + amp=False (CPU Skylake tanpa
    bf16/fp16) agar tidak kehabisan memori.
  * imgsz 640 sesuai spesifikasi awal; ubah berdasar hasil pengujian.

Hasil: bobot terbaik disalin ke ai-service/models/<out> + training-summary.json.
Run dir ultralytics dibuang (ai-service/runs/) dan tidak ikut git.
"""

from __future__ import annotations

import argparse
import json
import shutil
import sys
from datetime import datetime, timezone
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_DATA = PROJECT_ROOT / "storage" / "app" / "datasets" / "yolo-atk" / "data.yaml"
DEFAULT_OUT = PROJECT_ROOT / "ai-service" / "models" / "yolov8n-atk.pt"
RUNS_DIR = PROJECT_ROOT / "ai-service" / "runs"


def main() -> int:
    parser = argparse.ArgumentParser(description="Latih YOLOv8 untuk deteksi barang ATK")
    parser.add_argument("--data", default=str(DEFAULT_DATA), help="Path data.yaml hasil build_dataset.py")
    parser.add_argument("--model", default="yolov8n.pt", help="Base model (yolov8n.pt diunduh otomatis bila belum ada)")
    parser.add_argument("--epochs", type=int, default=25)
    parser.add_argument("--imgsz", type=int, default=160)
    parser.add_argument("--batch", type=int, default=4)
    parser.add_argument("--workers", type=int, default=0)
    parser.add_argument("--patience", type=int, default=10, help="Early-stop bila val tidak membaik (epoch)")
    parser.add_argument("--device", default="cpu")
    parser.add_argument("--name", default="atk-yolov8n")
    parser.add_argument("--out", default=str(DEFAULT_OUT), help="Lokasi salinan bobot terbaik")
    parser.add_argument("--freeze", type=int, default=0, help="Bekukan N layer pertama (0 = tanpa)")
    args = parser.parse_args()

    data_path = Path(args.data)
    if not data_path.is_file():
        print(f"ERROR: data.yaml tidak ditemukan: {data_path}")
        print("   Jalankan dulu: python ai-service/tools/build_dataset.py")
        return 1

    try:
        import torch
        import ultralytics
        from ultralytics import YOLO
    except ImportError:
        print("ERROR: ultralytics/torch belum terpasang. Lihat ai-service/requirements.txt")
        return 1

    print(f"== Latih {args.model}")
    print(f"   data   : {data_path}")
    print(f"   hyper  : epochs={args.epochs} imgsz={args.imgsz} batch={args.batch} "
          f"workers={args.workers} device={args.device} patience={args.patience}")

    model = YOLO(args.model)
    results = model.train(
        data=str(data_path),
        epochs=args.epochs,
        imgsz=args.imgsz,
        batch=args.batch,
        workers=args.workers,
        device=args.device,
        patience=args.patience,
        amp=False,        # CPU Skylake: pakai fp32
        cache=False,      # RAM 4 GB: jangan cache gambar ke memori
        seed=42,
        deterministic=True,
        project=str(RUNS_DIR),
        name=args.name,
        exist_ok=True,
        freeze=args.freeze,
        verbose=True,
    )

    save_dir = Path(str(results.save_dir))
    weights_dir = save_dir / "weights"
    best = weights_dir / "best.pt"
    if not best.is_file():
        best = weights_dir / "last.pt"
    if not best.is_file():
        print(f"ERROR: bobot hasil tidak ditemukan di {weights_dir}")
        return 1

    out_path = Path(args.out)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(best, out_path)

    rd = getattr(results, "results_dict", {}) or {}
    summary = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "model_base": args.model,
        "data": str(data_path),
        "hyperparameters": {
            "epochs": args.epochs,
            "imgsz": args.imgsz,
            "batch": args.batch,
            "workers": args.workers,
            "device": args.device,
            "patience": args.patience,
            "seed": 42,
            "amp": False,
        },
        "save_dir": str(save_dir),
        "weights": str(out_path),
        "best_metrics": {
            "precision": rd.get("metrics/precision(B)"),
            "recall": rd.get("metrics/recall(B)"),
            "map50": rd.get("metrics/mAP50(B)"),
            "map50_95": rd.get("metrics/mAP50-95(B)"),
        },
        "versions": {
            "python": sys.version.split()[0],
            "ultralytics": ultralytics.__version__,
            "torch": torch.__version__,
        },
    }
    (out_path.parent / "training-summary.json").write_text(
        json.dumps(summary, indent=2) + "\n", encoding="utf-8"
    )

    print("\n== SELESAI ==")
    print(f"   bobot   : {out_path}")
    print(f"   metrics : {summary['best_metrics']}")
    print("   lanjut  : python ai-service/evaluate.py   # evaluasi pada split test")
    return 0


if __name__ == "__main__":
    sys.exit(main())
