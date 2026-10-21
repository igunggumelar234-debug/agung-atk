#!/usr/bin/env python3
"""
Layanan inferensi deteksi ATK (YOLOv8) — jembatan HTTP antara Laravel dan model.

Kontrak HTTP (JSON):
  GET  /health
       200 {"ok": bool, "model_loaded": bool, "model_path": str,
            "classes": [..], "error": str|null, "versions": {..}}
  POST /detect   (multipart field "image"; form opsional: conf, iou)
       200 {"ok": true, "detections": [{"label": str, "confidence": float,
            "box": [x1,y1,x2,y2]}], "elapsed_ms": float, "model": str}
       413 gambar terlalu besar | 422 gambar tak terbaca
       503 model belum siap | 500 kegagalan inferensi

Env (tanpa rahasia di kode):
  YOLO_MODEL_PATH  default ai-service/models/yolov8n-atk.pt
  YOLO_CONF        default 0.25  (ambang KASAR server; ambang keputusan akhir
                   dikirim Laravel lewat field `conf` — satu sumber kebenaran
                   di config/services.php)
  YOLO_IOU         default 0.45
  YOLO_IMGSZ       default 640
  YOLO_HOST        default 127.0.0.1
  YOLO_PORT        default 8090

Jalankan:
  python ai-service/server.py
  # atau: uvicorn server:app --host 127.0.0.1 --port 8090  (dari folder ai-service)
"""

from __future__ import annotations

import io
import os
import time
import traceback
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from PIL import Image

PROJECT_ROOT = Path(__file__).resolve().parents[1]
MODEL_PATH = Path(os.environ.get("YOLO_MODEL_PATH", PROJECT_ROOT / "ai-service" / "models" / "yolov8n-atk.pt"))
CONF = float(os.environ.get("YOLO_CONF", "0.25"))
IOU = float(os.environ.get("YOLO_IOU", "0.45"))
IMGSZ = int(os.environ.get("YOLO_IMGSZ", "160"))  # HARUS sama dgn --imgsz training (data sumber 64x64)
HOST = os.environ.get("YOLO_HOST", "127.0.0.1")
PORT = int(os.environ.get("YOLO_PORT", "8090"))
MAX_UPLOAD_BYTES = 10 * 1024 * 1024  # 10 MB (validasi keras sebelum decode)

STATE: dict = {"model": None, "error": None, "model_path": str(MODEL_PATH)}


def _load_model() -> None:
    """Muat bobot sekali di awal proses. Gagal → /detect menjawab 503 jujur."""
    if not MODEL_PATH.is_file():
        STATE["error"] = (
            f"bobot tidak ditemukan: {MODEL_PATH} — jalankan `python ai-service/train.py` "
            "atau set YOLO_MODEL_PATH"
        )
        print(f"[vision] PERINGATAN: {STATE['error']}")
        return
    try:
        from ultralytics import YOLO

        model = YOLO(str(MODEL_PATH))
        STATE["model"] = model
        STATE["error"] = None
        print(f"[vision] model siap: {MODEL_PATH} | kelas={len(model.names)}")
    except Exception as exc:  # noqa: BLE001 — laporkan apa adanya ke /health
        STATE["error"] = f"gagal memuat model: {exc}"
        traceback.print_exc()


@asynccontextmanager
async def lifespan(app: FastAPI):
    _load_model()
    yield


app = FastAPI(title="Agung ATK Vision Service", version="1.0", lifespan=lifespan)


@app.get("/health")
def health() -> dict:
    model = STATE.get("model")
    versions = {}
    try:
        import torch
        import ultralytics

        versions = {"ultralytics": ultralytics.__version__, "torch": torch.__version__}
    except Exception:  # noqa: BLE001
        pass
    return {
        "ok": model is not None,
        "model_loaded": model is not None,
        "model_path": STATE["model_path"],
        "classes": list(model.names.values()) if model is not None else [],
        "error": STATE.get("error"),
        "defaults": {"conf": CONF, "iou": IOU, "imgsz": IMGSZ},
        "versions": versions,
    }


@app.post("/detect")
async def detect(
    image: UploadFile = File(...),
    conf: float | None = Form(None),
    iou: float | None = Form(None),
) -> dict:
    if STATE.get("model") is None:
        raise HTTPException(status_code=503, detail={"ok": False, "error": "model_not_ready", "message": STATE.get("error")})

    data = await image.read()
    if len(data) == 0:
        raise HTTPException(status_code=422, detail={"ok": False, "error": "empty_file"})
    if len(data) > MAX_UPLOAD_BYTES:
        raise HTTPException(status_code=413, detail={"ok": False, "error": "file_too_large"})

    try:
        img = Image.open(io.BytesIO(data))
        img.load()
        img = img.convert("RGB")
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=422, detail={"ok": False, "error": "invalid_image", "message": str(exc)[:200]})

    use_conf = min(max(float(conf if conf is not None else CONF), 0.01), 0.99)
    use_iou = min(max(float(iou if iou is not None else IOU), 0.1), 0.95)

    model = STATE["model"]
    try:
        t0 = time.time()
        results = model.predict(img, conf=use_conf, iou=use_iou, imgsz=IMGSZ, device="cpu", verbose=False)
        elapsed_ms = round((time.time() - t0) * 1000, 1)
        result = results[0]
        detections = []
        for box in result.boxes:
            detections.append(
                {
                    "label": result.names[int(box.cls[0])],
                    "confidence": round(float(box.conf[0]), 4),
                    "box": [round(float(v), 2) for v in box.xyxy[0]],
                }
            )
        detections.sort(key=lambda d: -d["confidence"])
        return {
            "ok": True,
            "detections": detections,
            "elapsed_ms": elapsed_ms,
            "model": MODEL_PATH.name,
            "conf": use_conf,
        }
    except HTTPException:
        raise
    except Exception as exc:  # noqa: BLE001
        traceback.print_exc()
        raise HTTPException(status_code=500, detail={"ok": False, "error": "inference_failed", "message": str(exc)[:200]})


if __name__ == "__main__":
    import uvicorn

    print(f"[vision] menjalankan di http://{HOST}:{PORT} | model={MODEL_PATH}")
    uvicorn.run(app, host=HOST, port=PORT, log_level="info")
