# AUDIT PROYEK AKHIR — AGUNG ATK

**Judul:** Sistem Informasi Penjualan Toko Jual Beli Alat Tulis Kerja Berbasis Web dengan Integrasi Artificial Intelligence (AI) di Kabupaten Kuningan
**Mata Kuliah:** Rekayasa Perangkat Lunak (TIKB2503021) — STMIK IKMI Cirebon
**Tanggal audit:** 15 November 2026
**Metode:** Inspeksi kode, eksekusi nyata (server lokal, browser, API eksternal), jalankan seluruh suite pengujian, pemindaian rahasia pada file ter-track, dan pemeriksaan log.

> Dokumen ini adalah **FASE 1** dari rangkaian kerja proyek akhir. Seluruh pernyataan di dalamnya berdasarkan bukti yang dapat direproduksi; bagian yang belum terbukti ditandai jujur sebagai **MENUNGGU** atau **BELUM**.

---

## 1. Kondisi Proyek Saat Ini

### 1.1 Lingkungan Pemeriksaan

| Komponen | Nilai Terukur |
|---|---|
| PHP | 8.3.33 (CLI, ZTS Visual C++ 2019) |
| Laravel | 13.31.0 |
| Database | MySQL 8.4.3 (Laragon) — database `agung_atk` |
| Web server | `php artisan serve` pada `http://127.0.0.1:8899` (HTTP 200) |
| Mail | Mailpit SMTP `127.0.0.1:1025` + antarmuka web `:8025` (milik Laragon) |
| Layanan AI | `ai-service/server.py` (YOLOv8) aktif pada port **8090** |
| Node.js | **Tidak terpasang** di mesin pengembangan (build Vite lokal tidak dapat dijalankan) |
| Git | 3 commit; **belum ada remote** (GitHub belum terhubung) |

### 1.2 Ukuran Proyek (terukur)

| Aspek | Jumlah |
|---|---:|
| Route non-vendor | 111 |
| Controller | 29 |
| Model Eloquent | 17 |
| Migration | 25 |
| Tabel MySQL aktual | 25 |
| View Blade | 66 |
| File pengujian | 40 |
| File `.md` di `docs/` | 6 (+ subfolder belum ada) |
| Console command | 5 |
| Middleware | 6 |
| Policy / Gate | **0** (otorisasi via middleware peran) |

### 1.3 Status Pengujian (15 November 2026, run segar)

```
php artisan test --compact
→ 614 test, 614 lulus, 2.908 asersi, ±5,4 menit (SQLite in-memory)
```

Tidak ada `ERROR` aplikasi baru di `storage/logs/laravel.log` (error tertua berupa salah opsi CLI percobaan manual tanggal 16 Okt 2026, bukan error runtime aplikasi).

---

## 2. Fitur yang Sudah Tersedia (Terbukti di Kode + Teruji)

### 2.1 Autentikasi & Otorisasi
- Login/register dengan **rate limit** (`throttle:login` 5/menit per email+IP, `throttle:register` 5/menit per IP, didefinisikan di `AppServiceProvider`).
- **4 role nyata**: Admin, Anggota (pegawai toko), Kurir, Pelanggan — dipisah oleh middleware `AdminMiddleware`, `AnggotaMiddleware`, `CourierMiddleware`, `CustomerMiddleware` + `EnsureAccountIsActive` (akun nonaktif ditolak).
- Redirect otomatis sesuai role setelah login (`redirectBasedOnRole`).
- Ganti password dibatasi `throttle:10,1`; admin dapat reset password pegawai (`users/{user}/reset-password`).
- Profil + **tautan akun kanal berkode** (kode 6 digit, anti brute-force).

### 2.2 Bisnis Penjualan
- Katalog produk (`/products`, detail per slug, pencarian), **pemindaian barcode** (`products/scan/{code}` — hanya untuk anggota, teruji `BarcodeScanTest`).
- Keranjang, checkout, alamat pengiriman, pesanan, unggah bukti pembayaran, verifikasi pembayaran (admin), penugasan kurir, status pengiriman, ulasan produk.
- Admin: kategori, produk, pengguna, pesanan, pembayaran, pengiriman, **laporan + ekspor + cetak**, log aktivitas (jejak audit), pengaturan notifikasi, manajemen kurir.
- Anggota: daftar pesanan, manajemen stok.
- Kurir: daftar & detail pengiriman yang ditugaskan.
- Pelanggan: dashboard, pesanan, pembayaran, ulasan.

### 2.3 AI & Computer Vision (terbukti implementasi, bukan klaim)
| Komponen | Bukti |
|---|---|
| Chatbot web (widget + `/ai`) | `AIService`, `HomeChatTest`, `Chatbot*Test` (8 file tes) |
| Pengetahuan database terkontrol | `DatabaseKnowledgeService` (read-only, tanpa SQL bebas), `ChatbotDataService` (intent → query Eloquent ber-scope peran) |
| Pagaran keamanan chatbot | `ChatGuard` (input ditolak sebelum model dipanggil; output disaring dari kebocoran system prompt/rahasia) |
| Identitas & peran | `ChatbotProfile` — aktor dari role NYATA session server, verbatim "Siapa kamu?" |
| Perintah & multi-bahasa | `AIService::commandReply()` (`/menu`, `/stok`, `/produk`, `/pesanan`, `/lacak`) + sapaan 12 bahasa — teruji `ChatbotCommandsTest` (33 tes) |
| Pencarian produk via gambar | `ATKImageRecognitionService` driver **hybrid**: deteksi objek **YOLOv8** lewat layanan Python terpisah + fallback pengenalan bawaan |
| Model & pelatihan YOLO | `ai-service/models/yolov8n-atk.pt`, `train.py`, `evaluate.py`, `runs/atk-yolov8n/` (kurva metrik, confusion matrix), `metrics.json` |
| Layanan inferensi | `ai-service/server.py` aktif di port 8090 (terverifikasi terbuka saat audit) |
| Pengujian gambar | `ImageSearchTest`, `YoloImageSearchTest` |

### 2.4 Notifikasi (status jujur per kanal)

| Kanal | Status Terukur | Bukti |
|---|---|---|
| **Email (SMTP)** | ✅ **BERHASIL diuji end-to-end** | Kirim uji dari halaman admin → diterima Mailpit (dari "Agung ATK", subjek "Uji Kirim Notifikasi"), 13 Nov 2026 |
| **Telegram (keluar)** | ✅ **API resmi BERHASIL** | `getMe` sukses → bot terdaftar `AGUNGATK_bot`; uji koneksi halaman admin: `Telegram OK (@AGUNGATK_bot)` |
| **Telegram (webhook/masuk)** | ⏸️ **DIHENTIKAN sengaja** | Username `@AGUNGATK.bot` yang diminta **tidak valid** (yang terdaftar `AGUNGATK_bot`) → pendaftaran webhook otomatis dihentikan, menunggu keputusan pemilik + URL HTTPS publik |
| **WhatsApp Cloud API** | ❌ **BELUM BERHASIL** | Kunci yang diberikan pemilik ditolak API Meta (`Invalid OAuth access token – Cannot parse access token`, code 190) dan `WHATSAPP_PHONE_NUMBER_ID`/`BUSINESS_ACCOUNT_ID`/`APP_SECRET` kosong → `WHATSAPP_ENABLED=false` |
| Mekanisme integrasi | ✅ Lengkap & teruji | Service, queue job (retry 3×), validasi HMAC/secret webhook, dedupe, log `notification_logs`, halaman admin — semua tercakup `WhatsAppCloudApiTest`, `NotificationDispatchTest`, `ChatbotChannelWebhookTest` (30 tes) |

### 2.5 Kualitas Lain
- **Tema** Terang/Gelap/System (`ThemeTest`).
- **Security headers** middleware (`SecurityHeadersTest`).
- **Error page** 404/403/500 (`ErrorPagesTest`).
- **Backup database**: `scripts/backup-database.ps1` & `.sh`.
- **Konfigurasi deployment**: `deploy/supervisor`, `deploy/systemd`, `deploy/crontab.example`, commit auto-start Task Scheduler.
- **Scheduler**: ringkasan penjualan harian 07:00 WIB + `queue:prune-failed`.

---

## 3. Fitur yang BELUM Tersedia / Menunggu Pihak Luar

| # | Item | Status | Yang Dibutuhkan |
|---|---|---|---|
| 1 | Jawaban AI umum multi-bahasa (mode API) | ⏸️ **MENUNGGU kunci AI valid** | Kunci `AI_API_KEY` (NVIDIA/OpenAI-compatible) — kunci saat ini ditolak **HTTP 401** |
| 2 | Notifikasi WhatsApp keluar | ⏸️ **MENUNGGU kredensial Meta** | Token Meta valid + `PHONE_NUMBER_ID` + `BUSINESS_ACCOUNT_ID` + `APP_SECRET` |
| 3 | Webhook masuk WA/TG (balasan chatbot via kanal) | ⏸️ **MENUNGGU** | (a) keputusan username bot, (b) URL HTTPS publik (ngrok/Cloudflare Tunnel/domain) |
| 4 | GitHub Actions CI/CD | ❌ **BELUM ADA** | Remote GitHub (belum ada `git remote`) — file workflow akan disiapkan FASE 6 |
| 5 | Video progres 1/2 & final | ❌ **BELUM** | Dikerjakan & direkam oleh mahasiswa (tidak dapat diwakili) |
| 6 | Identitas mahasiswa di README (nama, NIM, kelompok, tautan video) | ⏸️ **MENUNGGU data** | Placeholder disiapkan — tidak boleh dikarang |
| 7 | Build aset Vite | ⚠️ **TIDAK DIPAKAI** | View memakai CDN Bootstrap + `<style>` inline; `vite.config.js` tersisa scaffold (lihat §7) |

---

## 4. Fitur yang TIDAK BERFUNGSI (terbukti saat audit)

1. **Mode API chatbot (AI umum)** — `POST` ke `https://integrate.api.nvidia.com/v1/chat/completions` dengan kunci di `.env` menghasilkan **401 Unauthorized**. Dampak: pertanyaan umum non-DB (mis. *"What is the capital of France?"*) jatuh ke jawaban fallback jujur lokal — **bukan** jawaban AI. Perintah (`/menu`), sapaan multi-bahasa, identitas, dan intent data tetap bekerja (teruji live 13 Nov 2026).
2. **WhatsApp** — kunci `c5aFZ…` (20 karakter) **bukan** token Graph API Meta; ditolak parser OAuth Meta. Fitur terkonfigurasi penuh, tetapi tidak dapat mengirim apa pun sampai kredensial benar tersedia.
3. **Webhook Telegram** — sengaja belum didaftarkan (lihat §2.4) — ini keputusan penghentian otomatis, bukan crash.

> Tidak ditemukan halaman 500, error JavaScript runtime, error database, atau error queue pada pemeriksaan live.

---

## 5. Bug yang Ditemukan (riwayat & status)

| Bug | Status |
|---|---|
| Validasi/otorisasi endpoint kurang (temuan audit awal P0–P3) | ✅ Diperbaiki (PROMPT 2), teruji |
| Chatbot bocor konteks/klaim membaca DB tanpa data | ✅ Diperbaiki (ChatGuard + lapisan data) |
| `InboundChatService` import namespace salah (`App\Services\AIService`) | ✅ Diperbaiki → `App\Support\AIService` |
| Verifikasi webhook WA: PHP mengubah `hub.mode` → `hub_mode` | ✅ Diperbaiki (baca kedua bentuk) |
| Asumsi `Request::json()` pada HTTP client (kelas `Request` hanya punya `data()`) | ✅ Diperbaiki |
| Instalasi MySQL kehilangan folder `lib` (33 DLL) → server mati | ✅ Dipulihkan dari zip resmi 8.4.3; data utuh |
| Suite sempat gagal setelah penambahan intent `tracking_lookup` | ✅ Ditangani — **614/614 lulus** |

**Belum ada bug baru** yang ditemukan pada audit FASE 1 ini.

---

## 6. Risiko Keamanan

### 6.1 Sudah Baik (terverifikasi)
- ✅ `.env` **tidak** masuk Git (`git ls-files` bersih); `.gitignore` mencakup `.env*`, `vendor`, `node_modules`, `public/build`.
- ✅ **Pemindaian rahasia** pada seluruh file ter-track (pola token NVIDIA/Meta/AWS) → **tidak ditemukan**.
- ✅ CSRF dikecualikan hanya untuk `webhooks/*`; webhook WA divalidasi **HMAC-SHA256** (`X-Hub-Signature-256`), webhook TG **fail-closed** pada header secret.
- ✅ Rate limit: login/register/chat/webhook/ganti password/kode tautan.
- ✅ Chatbot: penolakan privasi sebelum model dipanggil; penyaringan output; data lintas pengguna diskop per role di server.
- ✅ Notifikasi & kanal: token hanya di `.env`, halaman admin hanya menampilkan **nama variabel** (tanpa nilai rahasia).
- ✅ Query chatbot read-only, tanpa SQL bebas, `LIMIT`/agregasi.

### 6.2 Perlu Perhatian (prioritas)
| # | Risiko | Saran |
|---|---|---|
| R1 | `APP_DEBUG=true` di `.env` lokal | Pastikan `false` di produksi (dokumentasikan di laporan deployment) |
| R2 | **Tidak ada Policy/Gate** — otorisasi hanya 4 middleware peran | Tambahkan Policy untuk resource kunci (Order, Product) bila waktu memungkinkan; minimal dokumentasikan pola yang konsisten |
| K3 | Ketergantungan CDN (Bootstrap, Chart.js, icons) tanpa fallback lokal | Unduh aset lokal atau sediakan fallback offline untuk demo tanpa internet |
| R4 | Tidak ada lupa-password mandiri (hanya reset oleh admin) | Bila fitur ini diharapkan rubrik, tambahkan alur email reset |

---

## 7. Kualitas Database

- **25 tabel** konsisten dengan 25 migration; relasi Eloquent lengkap (teruji `ModelRelationTest`, `RelationshipTest`).
- Tabel pendukung modern: `activity_logs` (jejak audit), `notification_logs` + `notification_settings`, `channel_identities` (tautan akun kanal), `ai_conversations`/`ai_messages` (riwayat chat), `jobs`/`failed_jobs`/`job_batches` (queue), `sessions`/`cache` berbasis database.
- Data pengembangan wajar (4 pengguna, 4 peran, 8 produk, 8 pesanan) — **bukan** data produksi.
- Optimasi indeks parsial untuk indeks visual search dibahas di `AppServiceProvider`.
- Backup tersedia (`scripts/backup-database.*`).

**Kesimpulan: BAIK (skor 4).**

---

## 8. Kualitas Arsitektur

**Kekuatan**
- Pemisahan lapisan jelas: Controller → Service (`NotificationManager`, `ChannelIdentityService`, `InboundChatService`, `ATKImageRecognitionService`) → Support (`AIService`, `ChatGuard`, `ChatbotDataService`) → Model.
- Chatbot berlapis: guard → perintah lokal → intent data read-only → AI API → fallback jujur.
- Notifikasi terpusat, per-event, per-audience, dengan queue & anti-duplikat.
- Knowledge base chatbot didokumentasikan (`docs/CHATBOT_*`).

**Temuan**
| # | Temuan | Dampak |
|---|---|---|
| A1 | `vite.config.js` + `resources/css/app.css` (Tailwind v4) **tidak direferensikan** view mana pun (aset via CDN + `<style>` inline) | Konfigurasi build mati — berisiko membingungkan; putuskan: pakai Vite atau bersihkan |
| A2 | Route alias ganda: `/kurir/dashboard`≡`/courier/dashboard`, `/penjual/dashboard`≡`/seller/dashboard`≡`/anggota/dashboard` | Menu ganda berisiko di penilaian "tidak ada menu ganda" — perlu dikonsolidasi/didokumentasikan |
| A3 | 0 Policy | Lihat R2 |
| A4 | `tmp_*.php` percobaan pernah ada (dibersihkan) | Kebiasaan bersih sudah diterapkan |

**Kesimpulan: BAIK–SANGAT BAIK**, dengan catatan A1–A3.

---

## 9. Kualitas UI/UX

- 66 view; layout terpisah untuk publik (`layouts/app`) dan dashboard per role (`layouts/dashboard`); tema **Terang/Gelap/System** dengan preferensi tersimpan.
- Navigasi berbasis role (teruji `RoleAccessTest`, `AccountPagesTest`).
- Halaman error 404/403/500 tersedia; feedback sukses/gagal pada form (terlihat live: alert "Pesan uji berhasil terkirim…").
- Validasi form di server (request validation) — bukan hanya frontend.
- **Ketergantungan CDN** + minimnya pengujian tampilan mobile manual → catatan risiko.
- Belum ada pemeriksaan aksesibilitas otomatis (aria/perhatian keyboard) menyeluruh.

**Kesimpulan: BAIK (menuju 4).**

---

## 10. Kualitas AI

| Aspek | Penilaian |
|---|---|
| Integritas jawaban (tidak mengarang) | ✅ Kuat — fallback jujur, blok data aktual, penolakan privasi |
| Pemahaman database | ✅ Intent terbatas, read-only, scoped per role (teruji 28+ tes) |
| Multi-bahasa & perintah | ✅ Lokal teruji (12 bahasa sapaan, 9 varian `/menu`); **mode API menunggu kunci valid** |
| Computer vision | ✅ YOLOv8 nyata (model terlatih, layanan hidup, hybrid fallback, tes khusus) |
| Keamanan prompt | ✅ ChatGuard input/output, anti-injection, larangan bocor sistem |
| Ketersediaan API eksternal | ❌ Kunci AI ditolak 401 (lihat §4) |

**Kesimpulan: BAIK–SANGAT BAIK untuk mekanisme; ketersediaan layanan AI bergantung kunci valid pemilik.**

---

## 11. Kualitas Testing

- **614 tes / 2.908 asersi — lulus penuh** (run 15 Nov 2026), SQLite in-memory (terisolasi dari MySQL produksi).
- Cakupan: auth, role & akses, CRUD produk/kategori/stok, keranjang, checkout, pesanan, pembayaran, pengiriman, notifikasi (dispatch/settings/WhatsApp API mock), chatbot (identitas, pertanyaan sederhana, pemahaman sistem, data DB, perintah, webhook kanal), pencarian gambar (YOLO & hybrid), barcode, tema, header keamanan, halaman error, relasi model, audit bisnis, timezone.
- `Http::fake` digunakan untuk integrasi API eksternal (tidak ada panggilan jujur ke layanan berbayar saat tes).
- Pint (formatter) bersih pada file yang diubah.

**Kesimpulan: SANGAT BAIK (skor 4).**

---

## 12. Kualitas CI/CD

- ❌ **Belum ada** `.github/workflows/`; belum ada remote GitHub.
- Aset pendukung sudah ada: `composer.json` (script `setup`), `phpunit.xml` terisolasi, `.env.example` lengkap.
- **Rencana FASE 6:** workflow checkout → setup PHP 8.3 → composer install → `.env` testing → key:generate → migrate (SQLite) → `php artisan test` → `vendor/bin/pint --test` → (opsional) setup Node + build.

---

## 13. Kelengkapan Dokumen

**Sudah ada (6):**
1. `docs/AUDIT_AGUNG_ATK.md` (audit awal PROMPT 1)
2. `docs/CHATBOT_DATABASE_KNOWLEDGE.md`
3. `docs/CHATBOT_SYSTEM_KNOWLEDGE.md`
4. `docs/CHATBOT_DATABASE_INTEGRATION.md`
5. `docs/CHATBOT_WHATSAPP_TELEGRAM_SETUP.md`
6. `docs/WHATSAPP_SETUP.md`

**Belum ada (wajib sesuai struktur pengumpulan):**
- `docs/AUDIT_PROYEK_AGUNG_ATK.md` ← dokumen ini (dibuat FASE 1)
- `docs/PRD_SRS_AGUNG_ATK.md` (FASE 3)
- `docs/SDD_ARSITEKTUR_AGUNG_ATK.md` + `docs/uml/*.md` (FASE 4)
- `docs/TESTING_CI_CD_AGUNG_ATK.md` (FASE 6)
- `docs/PANDUAN_VIDEO_PRESENTASI.md` (FASE 7)
- `docs/LAPORAN_AKHIR_AGUNG_ATK.md` (FASE 7)
- `docs/EVALUASI_RUBRIK_OBE_AGUNG_ATK.md` (FASE 8)
- README: identitas mahasiswa/NIM/kelompok/tautan video (placeholder — menunggu data nyata)

---

## 14. Pemetaan terhadap Rubrik OBE (prasyarat FASE 2)

| Dimensi | Bobot | Bukti yang Sudah Ada | Kekurangan Utama | Target |
|---|---:|---|---|---|
| PRD/SRS & relevansi AI | 20% | Fitur AI nyata (chatbot, DB intent, YOLO), knowledge docs | **Belum ada dokumen PRD/SRS** terstruktur IEEE 830 | FASE 3 |
| UML & UI/UX | 20% | Kode terstruktur, 66 view, tema, role-based UI | **Belum ada diagram UML/ERD/prototype** | FASE 4 |
| Implementasi kode & AI | 25% | 614 tes lulus, AI berlapis, YOLO hybrid, notifikasi | Kunci AI 401, WhatsApp belum terkoneksi, temuan A1–A3 | FASE 5 (perbaikan kecil) |
| Testing & CI/CD | 15% | 614 tes lulus, Pint bersih | **Belum ada GitHub Actions** | FASE 6 |
| Video presentasi | 20% | (belum dinilai) | Video belum direkam — **tanggung jawab mahasiswa** | FASE 7 (naskah) |

---

## 15. Rekomendasi Prioritas Perbaikan

| Prioritas | Tindakan | Penanggung jawab |
|---|---|---|
| **P0** | Sediakan **kunci AI valid** (NVIDIA/OpenAI-compatible) → mode API aktif | Pemilik |
| **P0** | Sediakan **kredensial WhatsApp Meta** benar (token panjang + phone_number_id) | Pemilik |
| **P0** | Keputusan **username bot Telegram** + **URL HTTPS publik** untuk webhook | Pemilik |
| **P1** | Buat `.github/workflows/ci.yml` + hubungkan remote GitHub | FASE 6 / mahasiswa (push) |
| **P1** | Dokumen PRD/SRS, UML+ERD+SDD, TESTING_CI_CD | FASE 3–6 |
| **P2** | Konsolidasi route alias (A2), putuskan nasib scaffold Vite (A1), pertimbangkan Policy (R2) | FASE 5 |
| **P2** | README: identitas + tautan video (placeholder), laporan akhir, panduan video | FASE 7 |
| **P3** | Aset CDN → lokal (R3), alur lupa-password (R4), aksesibilitas | Pasca-audit |

---

## 16. Kesimpulan Audit

Aplikasi **berjalan fungsional** (server 200, seluruh halaman utama OK), **614/614 tes lulus**, fitur AI chatbot dan computer vision **benar-benar terimplementasi**, integrasi notifikasi email & Telegram **teruji lewat API nyata**, sementara WhatsApp dan mode API AI **jujur belum berfungsi** menunggu kredensial valid. Kekurangan dokumentasi (PRD/SRS, UML, CI/CD, laporan) adalah pekerjaan FASE berikutnya dan **belum boleh diklaim selesai** sebelum dibuat.

*Audit selanjutnya (FASE 2) memetakan setiap temuan ini ke rubrik OBE sebelum perbaikan dilakukan.*
