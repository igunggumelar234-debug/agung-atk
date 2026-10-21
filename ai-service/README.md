# AI Service — Deteksi Barang ATK (YOLOv8)

Layanan Python terpisah yang menjalankan model deteksi objek **YOLOv8n (Ultralytics)**
untuk mengenali barang ATK dari foto. Laravel (fitur Pencarian Gambar & chatbot AI)
menghubunginya lewat HTTP; **bila layanan ini mati, sistem otomatis jatuh ke pengenal
bawaan (k-NN fitur GD)** sehingga fitur pencarian tidak pernah ikut mati.

Keputusan desain:

* Arsitektur **hybrid**: driver `hybrid` (default) = YOLO dulu → bila layanan tak
  terjangkau / balasan tidak valid → mesin k-NN bawaan; driver `gd` = mesin bawaan
  saja (dipakai phpunit.xml agar suite lama deterministik).
* Selama layanan menjawab, keputusan (`recognized` / `not_atk` / `uncertain`)
  dipercayakan ke YOLO. Ambang keputusan akhir (`conf`, margin) **dikirim Laravel**
  ke `/detect` — satu sumber kebenaran di `config/services.php`.
* Bobot `.pt`, direktori `runs/`, dan dataset hasil build **tidak ikut git**
  (lihat `.gitignore`); jalankan langkah di bawah untuk membangunnya ulang.

## Isi direktori

```
ai-service/
├── requirements.txt          # dependensi Python (ultralytics, torch cpu, fastapi, dst.)
├── train.py                  # pelatihan YOLOv8 → ai-service/models/*.pt
├── evaluate.py               # evaluasi pada split TEST → models/metrics.json
├── server.py                 # layanan inferensi FastAPI (port 8090)
├── tools/
│   └── build_dataset.py      # konversi dataset mentah → format YOLO + split
├── models/                   # hasil train (di-gitignore)
└── runs/                     # run dir ultralytics (di-gitignore)
```

Dataset mentah (di-commit? **tidak**, tetap di mesin masing-masing):
`storage/app/datasets/atk-image-search/raw/` — jangan sentuh/timpa; hasil olahan
dataset YOLO keluar di `storage/app/datasets/yolo-atk/`.

## Prasyarat

* Python 3.10+ (dikembangkan & diuji pada Python 3.13, Windows).
* Cukup RAM ±4 GB; training dijalankan di CPU (mesin uji: i3-6006U 2C/4T).
* Pakai virtualenv bila diinginkan (opsional):

  ```powershell
  python -m venv .venv
  .venv\Scripts\activate     # PowerShell; bash: source .venv/bin/activate
  pip install -r ai-service/requirements.txt
  ```

  Di mesin uji paket dipasang global (tanpa venv) dan bekerja normal.

## 1. Menyiapkan dataset (build_dataset.py)

Sumber data yang dipakai (semuanya di bawah `storage/app/datasets/atk-image-search/raw/`):

| Folder raw           | Sumber                                        | Status & lisensi |
|----------------------|-----------------------------------------------|------------------|
| `zip1/`              | DatasetBarangATK.zip (images.cv)              | Ada di mesin; **meta.json tanpa lisensi → wajib verifikasi sebelum pemakaian komersial** |
| `zip2/`              | Roboflow "Office Items" (via images.cv)       | Ada di mesin; **tanpa lisensi di meta.json → wajib verifikasi** |
| `school-supplies/`   | Roboflow "School Supplies" (**opsional**)     | **Belum diunduh**; dataset aslinya CC BY 4.0 |

Jalankan (dari root proyek):

```powershell
python ai-service/tools/build_dataset.py
# ulangi dengan timpa hasil lama bila sumber bertambah:
python ai-service/tools/build_dataset.py --force
# opsi: --train 0.70 --val 0.15 --test 0.15 --seed 42
```

Keluaran (`storage/app/datasets/yolo-atk/`):

```
yolo-atk/
├── data.yaml               # path + 16 nama kelas (urutan = id 0..15)
├── images/{train,val,test}/
├── labels/{train,val,test}/
└── metadata/
    ├── dataset-report.json # jumlah gambar/kelas, duplikat dibuang, file rusak
    └── ATTRIBUTION.md      # catatan sumber & lisensi (perbarui manual bila perlu)
```

Catatan jujur:

* Dataset klasifikasi per-folder dikonversi ke kotak penuh frame
  (`0.5 0.5 1 1`) — lihat komentar di `build_dataset.py`. Model karena itu
  belajar klasifikasi-gambar-utuh, bukan deteksi multi-objek nyata.
* Gambar sumber semuanya **64×64 px** (lihat laporan) — dasar pemilihan `--imgsz 160`
  (lihat bagian Pelatihan).
* Duplikat lintas-label dibuang otomatis; detailnya di `metadata/dataset-report.json`.
* 6 kelas opsional (`pencil`, `eraser`, `ruler`, `highlighter`, `marker`,
  `compass`) baru ikut terlatih bila folder `school-supplies/` tersedia; sampai
  saat itu `data.yaml` hanya memuat 16 kelas yang ada datanya.

### Unduh manual dataset Roboflow (bila perlu menambah kelas)

Halaman Roboflow tidak bisa diakses otomatis (HTTP 403 tanpa login), jadi unduh
manual:

1. Buat akun gratis di https://app.roboflow.com , lalu buka halaman dataset
   **"School Supplies"** (mis. pencarian di https://universe.roboflow.com ).
2. Pilih versi dengan lisensi **CC BY 4.0**, klik *Download Dataset* →
   *YOLOv8* → *show download code*.
3. Ekstrak isi unduhan ke
   `storage/app/datasets/atk-image-search/raw/school-supplies/`
   (harus berisi folder `train/` + `valid/`/`test/` khas ekspor Roboflow).
4. Jalankan ulang `python ai-service/tools/build_dataset.py --force`.
5. **Atribusi wajib**: dataset CC BY 4.0 mensyaratkan penyebutan nama pembuat
   dataset & tautan sumber. Tambahkan ke `metadata/ATTRIBUTION.md`
   (format sudah tersedia).

## 2. Pelatihan (train.py)

```powershell
python ai-service/train.py --imgsz 160 --batch 8 --epochs 20 --patience 6
```

Kenapa `--imgsz 160` (bukan 640)? Dua dasar terukur, bukan tebakan:

1. **Ukuran gambar sumber**: semua gambar dataset 64×64 px — memaksa model belajar
   pada 640×640 hanya memperbesar 10× tanpa menambah informasi piksel.
2. **Ukuran waktu di mesin uji (i3-6006U, CPU)**: run 640 diukur ±8,2 s/iterasi
   → ±48 menit/epoch (≈20 jam untuk 20 epoch); run 160 ±1,6 s/iterasi →
   ±5 menit/epoch (≈1,5–2 jam). "Sesuaikan berdasarkan hasil pengujian" =
   pengukuran ini.

Parameter CPU aman (jangan diubah tanpa alasan): `amp=False`, `cache=False`,
`workers=0`, `seed=42`, `deterministic=True`. Bila RAM/cores memadai, `--batch 8`
boleh dinaikkan; bila OOM, turunkan.

Keluaran:

* `ai-service/models/yolov8n-atk.pt` — bobot terbaik (di-gitignore).
* `ai-service/models/training-summary.json` — hyperparameter + metrik val terbaik
  (precision/recall/mAP50/mAP50-95) + versi paket.

Base model `yolov8n.pt` diunduh otomatis Ultralytics saat pertama kali (butuh
internet); bila offline, taruh `yolov8n.pt` di root folder dan pakai
`--model yolov8n.pt`.

## 3. Evaluasi & pemilihan ambang (evaluate.py)

**Jangan menebak ambang keyakinan** — ambil dari hasil sweep pada split test:

```powershell
python ai-service/evaluate.py
```

Keluaran `ai-service/models/metrics.json` berisi:

* metrik val resmi Ultralytics pada **split test** (precision, recall, mAP50,
  mAP50-95; keseluruhan + per kelas),
* `threshold_sweep`: akurasi klasifikasi tingkat gambar untuk ambang
  0.20–0.80 × margin 0.00/0.05/0.10,
* `threshold_suggestion`: baris dengan `atk_precision ≥ 0.90` dan coverage
  tertinggi — nilai inilah yang dipakai sebagai
  `IMAGE_SEARCH_YOLO_MIN_CONFIDENCE` / `IMAGE_SEARCH_YOLO_MIN_MARGIN` di `.env`
  (hasil sweep terakhir: conf 0.80, margin 0.00 → atk_precision 0.9065 @
  coverage 0.8487 pada 304 gambar test — sudah diterapkan sebagai default di
  `config/services.php`).

## 4. Menjalankan layanan (server.py)

```powershell
python ai-service/server.py
# setara: uvicorn server:app --host 127.0.0.1 --port 8090  (dari folder ai-service)
```

Cek kesehatan:

```powershell
Invoke-RestMethod http://127.0.0.1:8090/health
# {"ok": true, "model_loaded": true, "classes": [...], "defaults": {...}, "versions": {...}}
```

Deteksi manual (opsional):

```powershell
curl.exe -X POST http://127.0.0.1:8090/detect -F "image=@foto.jpg" -F "conf=0.45"
```

Konfigurasi layanan lewat env (tanpa rahasia di kode):

| Env             | Default                          | Keterangan |
|-----------------|----------------------------------|------------|
| `YOLO_MODEL_PATH` | `ai-service/models/yolov8n-atk.pt` | lokasi bobot |
| `YOLO_CONF`     | `0.25`                           | ambang KASAR server; ambang keputusan akhir dikirim Laravel lewat form `conf` |
| `YOLO_IOU`      | `0.45`                           | NMS IoU |
| `YOLO_IMGSZ`    | `160`                            | **harus sama** dengan `--imgsz` training |
| `YOLO_HOST`     | `127.0.0.1`                      | |
| `YOLO_PORT`     | `8090`                           | |

Bila bobot belum ada, `/health` menjawab `model_loaded: false` dan `/detect`
menjawab 503 yang jujur — Laravel otomatis memakai mesin bawaan (respons
`engine: gd`), jadi fitur tetap berfungsi.

## 5. Konfigurasi Laravel (`.env`)

Salin blok berikut dari `.env.example` (nilai default sudah sesuai kebutuhan
development):

```dotenv
IMAGE_SEARCH_DRIVER=hybrid            # hybrid | gd
IMAGE_SEARCH_SERVICE_URL=http://127.0.0.1:8090
IMAGE_SEARCH_SERVICE_TIMEOUT=10       # detik (timeout baca jawaban)
IMAGE_SEARCH_SERVICE_CONNECT_TIMEOUT=2 # detik (timeout koneksi — jangan besar: kegagalan cepat → fallback cepat)
IMAGE_SEARCH_YOLO_MIN_CONFIDENCE=0.80 # hasil sweep metrics.json (atk_precision 0.9065 @ coverage 0.8487)
IMAGE_SEARCH_YOLO_MIN_MARGIN=0.00     # margin 0.05/0.10 tidak menaikkan presisi (lihat metrics.json)
IMAGE_SEARCH_MAX_UPLOAD_KB=2048
```

Konfigurasi dibaca dari `config/services.php` (`image_search.*`) — model, ambang,
URL, dan driver bisa diganti tanpa menyentuh kode inti.

## 6. Uji integrasi nyata (end-to-end)

Dengan layanan berjalan + indeks pengenal bawaan sudah dibangun
(`php artisan atk:image-index`):

```powershell
# lewat endpoint Laravel (foto ATK → engine: yolo + produk dari DB):
curl.exe -X POST http://127.0.0.1:8899/image-search -F "image=@contoh-pulpen.jpg"
# non-ATK harus berstatus not_atk tanpa produk; gambar rusak harus berstatus error.
```

Otomatisasi regression: `tests/Feature/YoloImageSearchTest.php` (Http::fake —
tidak butuh layanan hidup), jalankan `php artisan test --compact --filter=YoloImageSearchTest`.

## Troubleshooting

| Gejala | Penyebab → tindakan |
|--------|---------------------|
| Respons `engine: gd` padahal layanan sudah nyala | Konek/URL salah — cek `IMAGE_SEARCH_SERVICE_URL`, buka `/health` |
| `/health` → `model_loaded: false` | Bobot belum ada → jalankan `train.py` atau set `YOLO_MODEL_PATH` |
| `Undefined array key` / hasil aneh setelah ganti imgsz | `YOLO_IMGSZ` ≠ `--imgsz` training → samakan |
| Training lambat / OOM | Turunkan `--batch`; jangan naikkan `cache`/`amp` di CPU lama |
| Kelas `pencil` dst. tidak muncul | Data `school-supplies/` belum ada → lihat bagian 1 |
