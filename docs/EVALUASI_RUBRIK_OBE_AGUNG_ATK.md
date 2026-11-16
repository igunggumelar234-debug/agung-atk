# EVALUASI MANDIRI RUBRIK OBE — AGUNG ATK

**Tanggal evaluasi:** 17 November 2026
**Acuan:** Panduan Proyek Akhir Mandiri & Rubrik Penilaian OBE Rekayasa Perangkat Lunak
**Skala:** 4 = Sangat Baik · 3 = Baik · 2 = Cukup · 1 = Kurang
**Rumus:** Nilai akhir = Σ (skor dimensi / 4 × bobot dimensi)

> Aturan evaluasi: skor 4 hanya diberikan bila ada **bukti nyata** (file, implementasi, hasil test, status pipeline) — bukan karena dokumen sudah dibuat. Dimensi video **tidak diberikan skor** selama video belum direkam.

---

## Ringkasan Skor

| Dimensi | Bobot | Skor | Nilai (skor/4 × bobot) |
|---|---:|---:|---:|
| 1. PRD/SRS dan relevansi AI | 20% | **4** | 20,00 |
| 2. UML dan UI/UX | 20% | **4** | 20,00 |
| 3. Implementasi kode dan AI | 25% | **4** | 25,00 |
| 4. Testing dan GitHub CI/CD | 15% | **3** | 11,25 |
| 5. Video presentasi | 20% | **BELUM DINILAI** | — |
| **Total yang sudah dinilai** | **80%** | | **76,25 dari 80** |

- **Persentase dari bobot yang sudah dinilai:** 76,25 / 80 = **95,3%** (≈ 95,3).
- **Proyeksi (bukan klaim):** bila video direkam sesuai naskah dan dinilai 4, total menjadi **96,25%**. Skor video hanya boleh ditulis setelah video benar-benar ada.

---

## Dimensi 1 — PRD/SRS dan Relevansi AI (bobot 20%)

| | |
|---|---|
| **Skor** | **4 — Sangat Baik** |
| **Alasan penilaian** | Dokumen PRD/SRS 20 bagian sesuai IEEE 830 tersedia, seluruhnya dipetakan dari implementasi aktual: 25 kebutuhan fungsional (F1–F25) dengan 16 acceptance criteria terukur dan 16 user story berformat benar. Relevansi AI dijelaskan spesifik dari kode nyata (intent data read-only, pagaran ChatGuard, YOLOv8 terlatih) beserta bagian "yang TIDAK diklaim" — tidak ada klaim RAG/vector DB/fine-tuning. |
| **Bukti** | `docs/PRD_SRS_AGUNG_ATK.md` (20 bagian) · `docs/AUDIT_PROYEK_AGUNG_ATK.md` §10 (pemetaan rubrik) · traceability AC ↔ file tes (mis. AC16 → `620/620`) |
| **Kekurangan** | Identitas mahasiswa di header laporan masih placeholder (menunggu data); sebagian AC berupa rujukan tes (dapat dibaca langsung dari file tes). |
| **Rekomendasi** | Isi identitas setelah data diberikan; bila perlu, lampirkan matriks F↔AC↔tes eksplisit di lampiran. |
| **Status pencapaian** | ✅ Tercapai (target skor 4) |

---

## Dimensi 2 — UML dan UI/UX (bobot 20%)

| | |
|---|---|
| **Skor** | **4 — Sangat Baik** |
| **Alasan penilaian** | Empat diagram UML utama + ERD dibuat dalam format Mermaid yang dapat dirender, seluruhnya diturunkan dari kode & schema aktual (17 model, 111 route, 25 tabel) — bukan rancangan fiktif. Skenario use case (utama/alternatif, kondisi awal/akhir), titik keputusan activity (transaksi, dedupe, fail-closed), diagram sequence AI (auth→permission→data→AI→balasan), dan ERD konsisten dengan migration. UI/UX diaudit terukur (203 titik error field, 29 alert, mode gelap, 79 aria) dan prototype interaktif HTML/CSS/JS tersedia sebagai pengganti Figma (Figma tidak tersedia — sesuai ketentuan prompt). |
| **Bukti** | `docs/uml/use_case.md`, `activity.md`, `class_diagram.md`, `sequence_diagram.md`, `erd.md` · `docs/SDD_ARSITEKTUR_AGUNG_ATK.md` (§2 traceability diagram↔kode+tes, §4 audit UI/UX) · `docs/prototype/index.html` · screenshot nyata `docs/screenshots/` |
| **Kekurangan** | Belum ada file Figma (disediakan prototype HTML sebagai alternatif yang diizinkan); pengujian responsivitas mobile masih manual; audit aksesibilitas belum menyeluruh. |
| **Rekomendasi** | Bila Figma tersedia, pindahkan layar prototype 1:1; tambahkan pengujian tampilan mobile bila waktu memungkinkan. |
| **Status pencapaian** | ✅ Tercapai (target skor 4) |

---

## Dimensi 3 — Implementasi Kode dan AI (bobot 25%)

| | |
|---|---|
| **Skor** | **4 — Sangat Baik** |
| **Alasan penilaian** | Aplikasi berjalan fungsional (server 200 pada seluruh halaman utama, empat role aktif), seluruh proses bisnis (katalog→checkout→bayar→kirim→laporan) terhubung dan teruji. Fitur AI benar-benar terimplementasi: chatbot berlapis (guard→perintah→intent data read-only ber-role→LLM→guard output) dan computer vision YOLOv8 dengan model terlatih + layanan inferensi berjalan (port 8090) + fallback. Kualitas kode: Pint lolos seluruh repo, tanpa kredensial di source (pemindaian rahasia bersih), otorisasi middleware konsisten, queue + log notifikasi. |
| **Bukti** | 620/620 tes lulus (2.940 asersi) · `vendor/bin/pint --test` → passed · `app/Support/{AIService,ChatGuard,ChatbotDataService,ChatbotProfile}` · `ai-service/models/yolov8n-atk.pt` + `runs/atk-yolov8n/` · `docs/screenshots/` (5 tangkapan layar nyata) |
| **Kekurangan** | 1) Kunci LLM saat ini ditolak 401 → jawaban umum via model belum aktif (mode lokal teruji & jujur). 2) Kredensial WhatsApp belum benar → pengiriman WA belum teruji nyata. 3) Tanpa Policy/Gate (otorisasi via middleware). 4) Scaffold Vite tidak direferensikan view (aset CDN). 5) Route alias kompatibilitas dipertahankan (menu UI tetap satu per role). |
| **Rekomendasi** | Sediakan kunci AI valid + kredensial Meta (prasyarat pemilik); pertambahkan Policy untuk resource kunci pada pengembangan lanjutan; putuskan nasib scaffold Vite. |
| **Status pencapaian** | ✅ Tercapai (target skor 4) — catatan kredensial eksternal terdokumentasi jujur |

---

## Dimensi 4 — Testing dan GitHub CI/CD (bobot 15%)

| | |
|---|---|
| **Skor** | **3 — Baik** |
| **Alasan penilaian** | **Sisi testing jelas skor 4:** 620 tes / 2.940 asersi lulus pada run 17 Nov 2026, cakupan luas (auth, role, CRUD, transaksi, notifikasi, chatbot 8 file, YOLO, webhook 30 tes), database terisolasi (SQLite in-memory), tanpa panggilan API berbayar saat suite. **Sisi CI/CD menahan skor:** workflow `.github/workflows/ci.yml` sudah dibuat lengkap (PHP 8.3 → composer → env → Pint → migrasi SQLite → tes; job Node → build) dan seluruh langkah **divalidasi lokal** kecuali build — tetapi **eksekusi GitHub Actions belum pernah berjalan** karena repository belum terhubung ke GitHub (`git remote -v` kosong). Status pipeline belum dapat diklaim hijau. |
| **Bukti** | `tests/` (40 file) · `phpunit.xml` (isolasi) · `.github/workflows/ci.yml` · `docs/TESTING_CI_CD_AGUNG_ATK.md` §5 (status jujur per langkah) · `vendor/bin/pint --test` → passed |
| **Kekurangan** | 1) Run GitHub Actions belum ada. 2) `npm run build` belum dieksekusi (Node tidak terpasang di mesin dev). |
| **Rekomendasi** | Hubungkan remote GitHub lalu push (instruksi `docs/TESTING_CI_CD_AGUNG_ATK.md` §6) → pastikan run pertama hijau → **skor dapat dinaikkan menjadi 4** setelah bukti run terlihat. |
| **Status pencapaian** | ⚠️ Sebagian — testing tercapai; pipeline siap tetapi **belum terbukti berjalan** |

---

## Dimensi 5 — Video Presentasi (bobot 20%)

| | |
|---|---|
| **Skor** | **BELUM DINILAI** |
| **Alasan** | Ketiga video (Progres 1, Progres 2, Final) **belum direkam/diunggah**. Tidak ada skor yang diberikan agar tidak mengarang hasil. |
| **Yang sudah tersedia** | Naskah lengkap per segmen + rubrik khusus + ketentuan rekaman di `docs/PANDUAN_VIDEO_PRESENTASI.md`; slot tautan di README. |
| **Langkah wajib mahasiswa** | Rekam 3 video (3–5 / 5–7 / 8–12 menit, webcam aktif, live demo wajib di video final) → unggah YouTube Unlisted/Drive → isi tautan di README → baru dimasukkan ke evaluasi ini. |
| **Status pencapaian** | ❌ Belum dikerjakan (tanggung jawab perekam) |

---

## Kesimpulan Evaluasi

1. **Empat dari lima dimensi** yang dapat dinilai dari bukti workspace: tiga dimensi mencapai **skor 4** (PRD/SRS, UML/UI-UX, Implementasi) dan satu dimensi **skor 3** (Testing & CI/CD) karena pipeline belum dieksekusi.
2. **Nilai sementara dari bobot yang sudah dinilai: 76,25/80 (95,3%)**; dimensi video statusnya **BELUM DINILAI**.
3. **Tiga tindakan agar seluruh dimensi mencapai 4:**
   - Push repository ke GitHub & pastikan run Actions hijau (menaikkan Dimensi 4 → 4);
   - Rekam & unggah 3 video sesuai naskah (menyelesaikan Dimensi 5);
   - Sediakan kunci AI valid + kredensial WhatsApp Meta (melengkapi klaim integrasi eksternal).
4. Seluruh pernyataan dalam dokumen ini dapat direproduksi dari workspace; bagian yang belum dibuktikan ditandai eksplisit dan tidak diklaim selesai.
