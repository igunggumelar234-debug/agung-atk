# PRD / SRS — AGUNG ATK

**Produk:** Sistem Informasi Penjualan Toko Jual Beli Alat Tulis Kerja Berbasis Web dengan Integrasi Artificial Intelligence (AI) di Kabupaten Kuningan
**Versi dokumen:** 1.0 — 17 November 2026
**Metode acuan:** IEEE 830 (Recommended Practice for Software Requirements Specifications) dan ISO/IEC/IEEE 29148
**Status:** Seluruh kebutuhan dalam dokumen ini dipetakan dari **implementasi nyata** di workspace. Bagian yang menunggu kredensial eksternal ditandai eksplisit.

---

## 1. Pendahuluan

### 1.1 Tujuan dokumen
Dokumen ini merumuskan kebutuhan fungsional dan nonfungsional sistem **Agung ATK** secara terukur dan dapat diuji, menjadi acuan pengembangan, pengujian, dan penilaian proyek akhir.

### 1.2 Ruang lingkup dokumen
Mencakup seluruh fitur aplikasi web penjualan ATK: katalog, transaksi, pengiriman, manajemen peran, integrasi AI (chatbot & pencarian gambar), notifikasi multi-kanal, serta keamanan — sesuai kondisi implementasi aktual per 17 November 2026.

### 1.3 Definisi, akronim, referensi
| Istilah | Arti |
|---|---|
| ATK | Alat Tulis Kerja |
| AI | Artificial Intelligence |
| YOLOv8 | *You Only Look Once* versi 8 — model deteksi objek (implementasi nyata: `ai-service/`) |
| Cloud API | REST API resmi WhatsApp Business milik Meta |
| Bot API | REST API resmi Telegram |
| Role | Peran pengguna: Admin, Anggota, Kurir, Pelanggan |
| Intent | Pola maksud pertanyaan chatbot yang dipetakan ke operasi baca-data terbatas |

Referensi: IEEE 830-1998; ISO/IEC/IEEE 29148:2018; Dokumentasi Laravel 13; Dokumentasi Meta WhatsApp Cloud API; Dokumentasi Telegram Bot API.

---

## 2. Latar Belakang Masalah Nyata

Toko Alat Tulis Kerja (ATK) di Kabupaten Kuningan umumnya mengelola ratusan item dengan karakteristik serupa (buku, pulpen, kertas dengan varian tipe/merk), sehingga muncul masalah nyata:

1. **Pencarian barang lambat** — pembeli/pegawai harus menelusuri rak atau bertanya langsung; varian produk sering tertukar.
2. **Stok sulit dipantau** — pencatatan manual membuat keputusan pembelian ulang terlambat, barang laku terjual habis tanpa terdeteksi.
3. **Pesanan & pembayaran tidak terlacak** — pembeli tidak tahu status pesanan; verifikasi bukti transfer manual memakan waktu.
4. **Pengiriman tidak terdokumentasi** — penugasan kurir dan status paket hanya lewat pesan singkat, tidak ada jejak audit.
5. **Komunikasi terputus** — informasi stok/penjualan harian hanya diketahui pemilik saat toko buka.
6. **Keterbatasan tenaga** — pertanyaan repetitif (harga, ketersediaan, status pesanan) menghabiskan waktu pegawai.

Integrasi AI diarahkan pada dua kebutuhan nyata: **asisten tanya-jawab** yang memahami aplikasi & data toko, dan **pencarian produk lewat foto** barang yang dilihat pembeli.

---

## 3. Identifikasi Masalah

| ID | Masalah | Dampak |
|---|---|---|
| M1 | Tidak ada katalog digital terstruktur | Produk sulit ditemukan, perbandingan harga tidak bisa |
| M2 | Stok tidak terekam real-time | Kehilangan penjualan & kelebihan pembelian |
| M3 | Alur pesanan-pembayaran-pengiriman tidak terintegrasi | Salah kirim, sengketa status, hilang jejak |
| M4 | Tidak ada pemisahan hak akses jelas | Risiko data bocor antar pihak (pembeli vs pegawai) |
| M5 | Tidak ada pencatatan aktivitas | Kesalahan sulit ditelusuri |
| M6 | Tidak ada kanal informasi otomatis | Pemilik/pegawai telat menerima info penting |
| M7 | Pencarian barang hanya manual/teks | Pembeli tidak tahu nama produk yang dicari |
| M8 | Pertanyaan repetitif dilayani manual | Waktu pegawai terbuang untuk hal rutin |

---

## 4. Tujuan Sistem

1. Menyediakan **katalog digital** ATK yang dapat dicari (teks & gambar) dengan data stok akurat.
2. Menutup siklus **pesanan → pembayaran → pengiriman → ulasan** dalam satu sistem berbasis peran.
3. Memberi **otorisasi ketat** per peran (Admin, Anggota, Kurir, Pelanggan) dengan jejak audit.
4. Mengirim **notifikasi otomatis** (Telegram, WhatsApp, Email) pada kejadian bisnis penting.
5. Menyediakan **chatbot AI** yang memahami aplikasi, database (lewat backend terkontrol), dan menjawab sesuai hak akses.
6. Menyediakan **pencarian produk berbasis gambar** untuk menemukan barang ATK dari foto.

---

## 5. Manfaat Sistem

| Pihak | Manfaat |
|---|---|
| Pemilik/Admin | Stok & penjualan terpantau; laporan siap cetak; notifikasi otomatis; jejak audit |
| Anggota (pegawai) | Kerja katalog/stok/pesanan lebih cepat; barcode scan; panduan lewat chatbot |
| Kurir | Daftar tugas pengiriman jelas; update status terdokumentasi |
| Pembeli | Cari produk via foto; lacak pesanan; info harga/stok instan |
| Akademik | Demonstrasi integrasi AI (NLP + computer vision) pada sistem informasi nyata |

---

## 6. Ruang Lingkup Aplikasi

**Masuk (in scope):**
- Autentikasi & otorisasi 4 peran; profil & keamanan akun.
- Kategori, produk (CRUD admin/anggota), gambar produk, barcode scan.
- Keranjang, alamat, checkout, pesanan, pembayaran (unggah bukti + verifikasi), pengiriman (penugasan kurir + status), ulasan.
- Laporan penjualan (filter, ekspor, cetak) & log aktivitas.
- Chatbot AI (web), pencarian gambar (kamera/unggah), notifikasi Telegram/WhatsApp/Email + webhook masuk.
- Tema terang/gelap, halaman error, backup database.

**Keluar (out of scope):**
- Pembayaran online gateway (transfer manual + verifikasi admin).
- Aplikasi mobile native; multi-toko/franchise; pengiriman lintas negara.
- Integrasi akuntansi penuh (laporan ringkas saja).

---

## 7. Target Pengguna

| Aktor | Deskripsi | Login |
|---|---|---|
| Admin | Pemilik/pengelola toko — kendali penuh | Ya |
| Anggota | Pegawai toko — katalog, stok, pesanan | Ya |
| Kurir — Pengantar paket internal | Ya |
| Pelanggan | Pembeli — belanja, bayar, lacak, ulas | Ya |
| Tamu | Pengunjung — lihat katalog & chatbot dasar | Tidak |

---

## 8. Batasan Sistem

1. Hanya berjalan pada web (browser modern) + layanan pendukung (MySQL, queue worker, layanan AI Python opsional).
2. Notifikasi terluar bergantung kredensial pihak ketiga (lihat §18–19).
3. Chatbot **tidak** memiliki akses SQL bebas — hanya intent yang dipetakan di backend.
4. Chatbot tidak menjawab permintaan data pribadi pihak lain atau rahasia sistem.
5. Pembayaran hanya manual (bukti transfer diverifikasi admin).
6. Pencarian gambar hanya untuk **barang ATK**; di luar itu ditolak dengan pesan baku.

---

## 9. Gambaran Umum Sistem

**Arsitektur 3 lapis khas Laravel:**
- **Presentasi:** Blade + Bootstrap 5 (CDN) + gaya inline; tema ringan.
- **Aplikasi:** Router → Middleware (peran & keamanan) → Controller → Service/Support → Eloquent.
- **Data:** MySQL 8 (25 tabel) + queue database + cache database + sesi database.

**Komponen AI:**
1. `AIService` + `ChatGuard` + `DatabaseKnowledgeService` + `ChatbotDataService` + `ChatbotProfile` (PHP, dalam aplikasi).
2. `ai-service/` — layanan Python `server.py` (inferensi **YOLOv8**), `train.py`, `evaluate.py`, model `yolov8n-atk.pt`.
3. Provider LLM eksternal (OpenAI-compatible) untuk jawaban umum — **menunggu kunci valid**.

*(Diagram lengkap: `docs/SDD_ARSITEKTUR_AGUNG_ATK.md`.)*

---

## 10. Kebutuhan Fungsional

| ID | Kebutuhan | Penerima |
|---|---|---|
| F1 | Sistem SHALL mendaftarkan & mengautentikasi pengguna dengan email+kata sandi, membatasi percobaan 5/menit | Semua |
| F2 | Sistem SHALL mengarahkan pengguna ke dashboard sesuai role setelah login | Semua |
| F3 | Sistem SHALL menampilkan katalog produk aktif (nama, merk, harga, stok, gambar) beserta pencarian & filter | Tamu/Pelanggan |
| F4 | Sistem SHALL menampilkan detail produk per slug + ulasan | Tamu/Pelanggan |
| F5 | Sistem SHALL memindai barcode produk (`products/scan/{code}`) untuk mempercepat pencarian pegawai | Anggota |
| F6 | Sistem SHALL mengelola kategori, produk, & stok (CRUD + gambar) | Admin/Anggota |
| F7 | Sistem SHALL mengelola keranjang, alamat, dan checkout menjadi pesanan | Pelanggan |
| F8 | Sistem SHALL menerima unggah bukti pembayaran & mencatat status verifikasi | Pelanggan/Admin |
| F9 | Sistem SHALL menugaskan kurir & mencatat status pengiriman hingga selesai | Admin/Kurir |
| F10 | Sistem SHALL menampilkan riwayat pesanan sesuai hak akses (pelanggan: miliknya; kurir: tugasnya; admin: semua) | Semua login |
| F11 | Sistem SHALL membuat laporan penjualan dengan filter tanggal + ekspor/cetak | Admin |
| F12 | Sistem SHALL mencatat jejak aktivitas (aktor, aksi, objek, waktu) | Admin |
| F13 | Sistem SHALL mengelola pengguna & reset password pegawai | Admin |
| F14 | Sistem SHALL mengirim notifikasi pada kejadian (pesanan dibuat/diverifikasi/dikirim, ringkasan harian 07:00) | Admin/Anggota/Kurir/Pelanggan |
| F15 | Sistem SHALL melayani chatbot pada halaman beranda untuk tamu & semua role | Semua |
| F16 | Sistem SHALL menjawab identitas chatbot secara baku saat ditanya "Siapa kamu?" | Semua |
| F17 | Sistem SHALL menjawab pertanyaan data toko lewat intent read-only yang diskop per role | Semua |
| F18 | Sistem SHALL menolak pertanyaan privasi/rahasia dengan respons tetap | Semua |
| F19 | Sistem SHALL melayani perintah `/menu`, `/stok`, `/produk`, `/pesanan`, `/lacak` | Semua |
| F20 | Sistem SHALL menjawab sapaan dalam bahasa yang dipakai pengguna (12 bahasa terdeteksi pada mode lokal) | Semua |
| F21 | Sistem SHALL mencari produk dari foto (kamera/unggah) dan mencocokkan ke katalog | Pelanggan/Anggota |
| F22 | Sistem SHALL menampilkan **"Barang Tidak Sesuai Kriteria Toko"** untuk gambar di luar ATK, dan **"Stok Kosong"** bila cocok tetapi habis | Pelanggan/Anggota |
| F23 | Sistem SHALL menerima webhook Telegram/WhatsApp yang tervalidasi secret & membalas chatbot lewat kanal | Sistem |
| F24 | Sistem SHALL menautkan akun kanal (WA/TG) ke akun login lewat kode 6 digit kedaluwarsa 10 menit | Pelanggan |
| F25 | Sistem SHALL menyediakan tema terang/gelap/system | Semua |

---

## 11. Kebutuhan Nonfungsional

| ID | Kebutuhan | Target terukur |
|---|---|---|
| NF1 | Ketersediaan | Aplikasi lokal berjalan penuh tanpa layanan eksternal wajib; fitur eksternal gagal → pesan jujur, bukan error |
| NF2 | Performa halaman | Halaman utama termuat < 3 detik pada lokal (terukur: 200 OK ±2dt) |
| NF3 | Performa chatbot lokal | Jawaban intent/perintah tanpa panggilan jaringan (< 1 dtk) |
| NF4 | Keamanan | Kata sandi hashed (bcrypt), CSRF, rate limit login, header keamanan, token hanya di `.env` |
| NF5 | Integritas data | Transaksi pada checkout; query chatbot read-only; backup tersedia |
| NF6 | Keterlacakan | Setiap aksi penting tercatat di `activity_logs`/`notification_logs` |
| NF7 | Keterbacaan/bahasa | Antarmuka & jawaban chatbot Bahasa Indonesia; chatbot mengikuti bahasa pengguna |
| NF8 | Portabilitas | Windows/Linux (dokumentasi deploy cron/supervisor/systemd tersedia) |

---

## 12. User Stories

| ID | User Story |
|---|---|
| US1 | Sebagai **pembeli**, saya ingin **melihat daftar produk ATK beserta harga dan stok**, sehingga saya dapat memilih barang tanpa bertanya ke pegawai. |
| US2 | Sebagai **pembeli**, saya ingin **memotret/mengunggah gambar barang**, sehingga saya menemukan produk yang mirip di katalog. |
| US3 | Sebagai **pembeli**, saya ingin **memasukkan barang ke keranjang dan checkout**, sehingga pesanan saya tercatat resmi. |
| US4 | Sebagai **pembeli**, saya ingin **mengunggah bukti bayar dan melihat status verifikasi**, sehingga saya tahu pesanan diproses. |
| US5 | Sebagai **pembeli**, saya ingin **melacak pesanan & resi saya sendiri**, sehingga saya tahu paket sudah sampai mana. |
| US6 | Sebagai **pembeli**, saya ingin **menanyakan hal umum ke chatbot kapan saja**, sehingga tidak perlu menunggu pegawai. |
| US7 | Sebagai **pegawai (Anggota)**, saya ingin **mencari produk lewat barcode**, sehingga pengecekan stok di rak cepat. |
| US8 | Sebagai **pegawai (Anggota)**, saya ingin **memperbarui stok & produk**, sehingga katalog selalu akurat. |
| US9 | Sebagai **pegawai (Anggota)**, saya ingin **melihat daftar pesanan yang perlu diproses**, sehingga tidak ada pesanan terlewat. |
| US10 | Sebagai **kurir**, saya ingin **melihat daftar pengiriman yang ditugaskan kepada saya dan memperbarui statusnya**, sehingga pengiriman terdokumentasi. |
| US11 | Sebagai **admin**, saya ingin **memverifikasi pembayaran dan menugaskan kurir**, sehingga alur pesanan berjalan tanpa hambatan. |
| US12 | Sebagai **admin**, saya ingin **melihat laporan penjualan dan mengekspornya**, sehingga pembukuan mudah. |
| US13 | Sebagai **admin**, saya ingin **menerima notifikasi otomatis (Telegram/Email) untuk kejadian penting dan ringkasan harian**, sehingga saya selalu tahu kondisi toko. |
| US14 | Sebagai **admin**, saya ingin **mengetahui siapa melakukan aksi apa (log aktivitas)**, sehingga kesalahan dapat ditelusuri. |
| US15 | Sebagai **pengguna (semua peran)**, saya ingin **chatbot menjawab sesuai peran saya dan tidak membocorkan data orang lain**, sehingga aman dipakai. |
| US16 | Sebagai **mahasiswa**, saya ingin **seluruh fitur teruji otomatis**, sehingga perubahan tidak merusak fungsi yang ada. |

---

## 13. Acceptance Criteria

| ID | Acceptance Criteria (terukur & dapat diuji) |
|---|---|
| AC1 | (US1) GET `/products` → 200; menampilkan produk aktif; produk nonaktif tidak tampil — *teruji `CatalogCartTest`* |
| AC2 | (US2) Unggah gambar ATK → daftar kecocokan tampil; gambar non-ATK → pesan **"Barang Tidak Sesuai Kriteria Toko"**; produk habis → **"Stok Kosong"** — *teruji `ImageSearchTest`, `YoloImageSearchTest`* |
| AC3 | (US3) Checkout mengurangi stok & membuat `orders` + `order_details` dalam satu transaksi — *teruji `CheckoutTest`* |
| AC4 | (US4) Unggah bukti → status `pending`; verifikasi admin → status `verified` + notifikasi — *teruji `PaymentManagementTest`* |
| AC5 | (US5) Pelanggan hanya melihat pesanan miliknya (permintaan ID lain → 403/penolakan) — *teruji `RoleAccessTest`, `ChatbotDataIntegrationTest`* |
| AC6 | (US6) Chatbot menjawab pertanyaan umum dalam bahasa pertanyaan **atau** — bila API AI tak tersedia — jawaban fallback jujur tanpa mengarang — *teruji `ChatbotCommandsTest`, `ChatbotSimpleQuestionTest`* |
| AC7 | (US7) Pemindaian barcode valid → halaman produk; kode tidak ada → pesan gagal jelas — *teruji `BarcodeScanTest`* |
| AC8 | (US8) Perubahan stok tercatat & terlihat di katalog; stok tak boleh negatif — *teruji `SellerModuleTest`, `AdminCatalogTest`* |
| AC9 | (US9) Pesanan baru muncul di daftar anggota; pelanggan TIDAK melihat halaman anggota — *teruji `RoleAccessTest`* |
| AC10 | (US10) Kurir hanya melihat pengiriman yang ditugasinya; update status terekam — *teruji `ShipmentModuleTest`* |
| AC11 | (US11) Verifikasi pembayaran wajib login admin; penugasan kurir mengubah status pesanan — *teruji `PaymentManagementTest`* |
| AC12 | (US12) Laporan menampilkan total penjualan sesuai data tersimpan; ekspor menghasilkan file — *teruji `DashboardReportTest`* |
| AC13 | (US13) Kejadian terjadwal menghasilkan baris `notification_logs`; uji kirim dari halaman admin → email diterima Mailpit — *teruji `NotificationDispatchTest` + uji live 13 Nov 2026* |
| AC14 | (US14) Setiap aksi CRUD tercatat di `activity_logs` dengan aktor & waktu — *teruji `BusinessAuditTest`* |
| AC15 | (US15) Pertanyaan "siapa data pelanggan X" → penolakan tetap; pesan mengandung pola system prompt → output disaring — *teruji `ChatbotIdentityTest`, `ChatbotIntelligenceTest`* |
| AC16 | (US16) `php artisan test` → **620/620 lulus (2.940 asersi)** — *terverifikasi 17 Nov 2026* |

---

## 14. Kebutuhan Database

- **25 tabel** MySQL 8 (lihat daftar pada `docs/AUDIT_PROYEK_AGUNG_ATK.md` §7), konsisten dengan 25 migration.
- Intitas inti: `users`↔`roles` (1:N), `categories`→`products` (1:N), `products`↔`product_images` (1:N), `users`→`orders`→`order_details`→`products`, `orders`→`payments` (1:N), `orders`→`shipments` (1:N, dengan `courier_id` → `users`), `users`→`reviews`→`products`, `users`→`addresses`.
- Tabel pendukung: `activity_logs`, `notification_logs`, `notification_settings`, `channel_identities`, `ai_conversations`, `ai_messages`, `jobs`, `failed_jobs`, `job_batches`, `sessions`, `cache`, `password_reset_tokens`, `migrations`.
- Kebutuhan: integritas referensial (FK), indeks pada kolom pencarian (`slug`, `sku`, `tracking_number`, `order_number`), tanpa kolom rahasia tersimpan plaintext (token notifikasi hidup di `.env`).

---

## 15. Kebutuhan Keamanan

| ID | Kebutuhan | Implementasi |
|---|---|---|
| S1 | Autentikasi wajib + sesi aman | Laravel session (database), password hashing bcrypt |
| S2 | Autorisasi per role di server | 4 middleware peran + `EnsureAccountIsActive` |
| S3 | Proteksi CSRF | `VerifyCsrfToken` (kecuali `webhooks/*`) |
| S4 | Pembatasan brute-force | `throttle:login/register/password/chat/webhook` |
| S5 | Rahasia tidak di source code | Token hanya `.env` (gitignore); halaman admin hanya menampilkan nama variabel |
| S6 | Validasi webhook eksternal | WA: HMAC-SHA256 `X-Hub-Signature-256`; TG: header secret fail-closed; dedupe update |
| S7 | Privasi chatbot | Penolakan privasi sebelum model dipanggil; output disaring; data diskop role |
| S8 | Injeksi prompt | `ChatGuard` menolak pola injeksi & kebocoran konteks internal |
| S9 | Header keamanan | Middleware `SecurityHeaders` (teruji `SecurityHeadersTest`) |
| S10 | Jejak audit | `activity_logs` untuk aksi penting |

---

## 16. Kebutuhan Performa

- Pemuatan halaman publik < 3 detik pada lingkungan lokal (terukur ±2dt).
- Chatbot mode lokal menjawab < 1 detik (tanpa jaringan).
- Chatbot mode API memiliki timeout konfigurabel (`AI_TIMEOUT`, default 60 dtk) dan gagal → fallback jujur.
- Query chatbot ber-limit (`LIMIT` daftar, agregasi ringkas) untuk menjaga beban DB.
- Queue notifikasi asinkron (`queue:worker`) agar HTTP request tidak tertahan.

---

## 17. Kebutuhan Kompatibilitas

| Lapisan | Target |
|---|---|
| Browser | Chrome/Edge/Firefox/Safari terbaru (layout Bootstrap 5.3) |
| Server | PHP 8.3+, Laravel 13, MySQL 8 / MariaDB 10.4+ (SQLite untuk tes) |
| OS pengembangan | Windows (Laragon) & Linux (dokumentasi deploy tersedia) |
| Layanan opsional | Python 3 + paket `ai-service/requirements.txt` untuk YOLOv8; Mailpit/SMTP untuk email |

---

## 18. Kebutuhan Integrasi API

| API | Penggunaan | Status jujur (17 Nov 2026) |
|---|---|---|
| **Telegram Bot API** | Kirim notifikasi, `getMe`/`setWebhook`/`webhookInfo`, terima webhook masuk | ✅ Keluar teruji (`Telegram OK (@AGUNGATK_bot)`); ⏸️ webhook masuk menunggu keputusan username + URL HTTPS |
| **WhatsApp Cloud API (Meta)** | Kirim notifikasi teks/template, webhook masuk + verifikasi signature | ⏸️ Mekanisme lengkap & teruji mock; **kredensial menunggu** (kunci yang diberikan ditolak Meta: code 190) |
| **SMTP (Laravel Mailer)** | Email notifikasi | ✅ Teruji end-to-end via Mailpit (13 Nov 2026) |
| **LLM OpenAI-compatible** | Jawaban chatbot umum multi-bahasa | ⏸️ Mekanisme siap; **kunci saat ini 401** — menunggu kunci valid |
| **Layanan AI lokal (YOLOv8)** | Deteksi objek untuk pencarian gambar | ✅ `ai-service/server.py` port 8090 aktif; model terlatih |

Semua kredensial hanya melalui environment variable — **tidak pernah** dalam source code, dokumen, atau commit.

---

## 19. Spesifikasi Fitur AI

> Disusun dari **implementasi aktual** — tanpa klaim yang belum terbukti.

### 19.1 Chatbot pemaham aplikasi (`AIService`, `Chatguard`, `ChatbotProfile`, `WorkflowKnowledge`)
- Menjawab pertanyaan tentang **fitur, alur, dan panduan** aplikasi dari knowledge base yang ditulis dari kode nyata.
- Identitas baku "Siapa kamu?" — aktor diambil dari **role session server**, bukan ketikan pengguna.
- **18+ skenario** pemahaman sistem teruji (`ChatbotSystemUnderstandingTest`, `ChatbotSystemMapTest`).

### 19.2 Chatbot pemaham database via backend terkontrol (`DatabaseKnowledgeService`, `ChatbotDataService`)
- Pertanyaan bahasa alami dipetakan ke **intent terbatas** (harga/stok, produk terlaris, pendapatan, jumlah pesanan, status pesanan milik sendiri, resi, tugas kurir, dsb).
- Query dijalankan **read-only** di Laravel, diskop per role di server, dengan LIMIT/agregasi — **tanpa SQL bebas**, tanpa akses tabel rahasia.
- Jawaban memakai **angka nyata** dari database; bila kosong → jawaban kosong yang jujur; bila gagal → jawaban aman.

### 19.3 Jawaban tanpa sumber data
- Bila data tidak tersedia → jawaban fallback jujur: *"saya tidak memiliki informasi yang cukup… saya tidak boleh mengarang jawaban"* — diuji sebagai perilaku.

### 19.4 Perlindungan data pribadi & rahasia
- Input ditolak **sebelum** model dipanggil untuk pertanyaan privasi/PII/rahasia (`ChatGuard`).
- Output disaring dari pola kebocoran system prompt & rahasia teknis.
- Lintas pengguna: pelanggan hanya melihat pesan/pesanannya sendiri.

### 19.5 Multi-bahasa & perintah
- System prompt mewajibkan **balas dengan bahasa pengguna** (Indonesia termasuk gaya santai, Inggris, Jawa, Sunda, Arab, Mandarin, Jepang, Korea, Prancis, Spanyol, Portugis, Jerman, Hindi).
- Perintah lokal deterministik: `/menu|/help|/bantuan|/ayuda|/aide|/帮助|/ヘルプ|/도움말|/مساعدة`, `/stok`, `/produk|/rekomendasi`, `/pesanan`, `/lacak <ORD|TRK>` — 33 tes (`ChatbotCommandsTest`).
- Sapaan 12 bahasa dibalas pada bahasa yang sama (mode lokal).

### 19.6 Pencarian produk berbasis gambar (Computer Vision — **YOLOv8 nyata**)
- `ATKImageRecognitionService` driver **hybrid**: mendeteksi objek lewat layanan Python `ai-service/server.py` (**model `yolov8n-atk.pt` terlatih**, diuji `evaluate.py`, artefak `runs/atk-yolov8n/` berisi confusion matrix & kurva PR), lalu mencocokkan kelas ke katalog produk.
- Fallback ke pengenalan bawaan saat layanan mati → aplikasi tetap berfungsi.
- Ambang `min_confidence`/`min_margin` dikonfigurasi agar hasil tidak mengarang.
- **Bukan** barcode scan (fitur barcode terpisah untuk anggota).
- Hasil wajib: cocok → saran produk katalog; bukan ATK → **"Barang Tidak Sesuai Kriteria Toko"**; habis → **"Stok Kosong"**.

### 19.7 Yang TIDAK diklaim (jujur)
- ❌ RAG, Vector Database, fine-tuning LLM, multi-agent — **tidak diimplementasikan**.
- ❌ Model bahasa berjalan lokal — jawaban umum bergantung API eksternal (**kunci saat ini belum valid**).
- ❌ Klaim akurasi persentase YOLO tanpa angka — angka resmi hanya yang tercatat di `ai-service/models/metrics.json`.

### 19.8 Manfaat nyata AI terhadap operasional toko
| Fitur AI | Manfaat operasional |
|---|---|
| Chatbot tanya-jawab | Mengurangi pertanyaan repetitif ke pegawai (harga, stok, status) — 24/7 |
| Intent data read-only | Pemilik/pegawai mendapat angka penjualan/stok tanpa membuka laporan manual |
| Pencarian gambar | Pembeli menemukan produk dari foto — menaikkan konversi pencarian |
| Deteksi YOLOv8 | Mengenali jenis barang ATK walau pengguna tidak tahu nama produknya |
| Notifikasi AI-adjacent | Ringkasan harian otomatis memanggil perhatian pemilik tepat waktu |

---

## 20. Risiko dan Asumsi Proyek

| ID | Risiko | Dampak | Mitasi |
|---|---|---|---|
| RK1 | Kunci AI eksternal tidak valid (kondisi aktual: 401) | Jawaban umum chatbot fallback lokal | Sediakan kunci valid; fallback jujur sudah aman |
| RK2 | Kredensial WhatsApp belum benar (kondisi aktual: ditolak Meta) | Notifikasi WA nonaktif | Mekanisme lengkap menunggu kredensial resmi |
| RK3 | Webhook butuh URL HTTPS publik | Balasan chatbot via kanal tertunda | Dokumentasi `chatbot:webhook set` + tunnel siap dipakai |
| RK4 | Ketergantungan CDN | UI rusak tanpa internet | Pertimbangkan aset lokal (rekomendasi audit) |
| RK5 | Data dummy hanya di lingkungan dev | Salah bila dipindah ke produksi | Seeder hanya untuk dev; produksi dengan data nyata |
| RK6 | Tanpa GitHub remote/CI (kondisi aktual) | Bukti otomasi belum ada | FASE 6 menyiapkan workflow; push oleh pemilik |
| RK7 | Video belum direkam | Nilai video 0 sementara | Naskah disediakan FASE 7; rekaman oleh mahasiswa |

**Asumsi:**
1. Pengguna berinternet stabil untuk halaman berbasis CDN.
2. Verifikasi pembayaran manual diterima sebagai desain bisnis toko.
3. Semua kredensial eksternal disediakan pemilik proyek.

---

* Dokumen ini bersumber dari kode & pengujian aktual. Perubahan fitur wajib memperbarui dokumen ini (traceability F ↔ AC ↔ tes).*
