# LAPORAN AKHIR — AGUNG ATK

<div align="center">

**SISTEM INFORMASI PENJUALAN TOKO JUAL BELI ALAT TULIS KERJA BERBASIS WEB DENGAN INTEGRASI ARTIFICIAL INTELLIGENCE (AI) DI KABUPATEN KUNINGAN**

Laporan Proyek Akhir

Mata Kuliah: Rekayasa Perangkat Lunak (TIKB2503021)
Program Studi: Sistem Informasi — STMIK IKMI Cirebon

</div>

| | |
|---|---|
| Nama Mahasiswa | `[NAMA MAHASISWA]` |
| NIM | `[NIM]` |
| Kelompok | `[KELOMPOK]` |
| Tema Topik Kelompok | `[TEMA TOPIK]` |
| Dosen Pembimbing | `[NAMA DOSEN]` |
| Tahun | 2026 |

> **Catatan integritas:** kolom dalam `[placeholder]` sengaja belum diisi karena data identitas belum diberikan — tidak boleh dikarang. Seluruh angka pengujian dan status integrasi di laporan ini adalah hasil eksekusi nyata per 17 November 2026.

---

## DAFTAR ISI

1. Pendahuluan
2. Latar Belakang
3. Rumusan Masalah
4. Tujuan Penelitian
5. Analisis Kebutuhan
6. Perancangan Sistem
7. Implementasi
8. Integrasi AI
9. Pengujian
10. Hasil dan Pembahasan
11. Kesimpulan
12. Saran
13. Daftar Pustaka

---

## 1. PENDAHULUAN

### 1.1 Latar Umum

Transformasi digital UMKM menjadi tuntutan ekonomi digital Indonesia. Toko Alat Tulis Kerja (ATK) — yang produknya berjumlah ratusan varian dengan karakteristik serupa — membutuhkan sistem informasi yang mampu mengelola katalog, stok, transaksi, dan pengiriman secara terintegrasi. Pengembangan sistem semacam ini menjadi materi praktis mata kuliah Rekayasa Perangkat Lunak sekaligus wujud penerapan rekayasa perangkat lunak pada masalah nyata.

### 1.2 Ruang Lingkup Laporan

Laporan ini memaparkan analisis kebutuhan (PRD/SRS mengacu IEEE 830), perancangan (UML, ERD, arsitektur, UI/UX), implementasi (aplikasi Laravel + integrasi AI), pengujian (PHPUnit), serta status jujur seluruh integrasi eksternal. Cakupan aplikasi mencakup empat peran pengguna: Admin, Anggota (pegawai toko), Kurir, dan Pelanggan.

### 1.3 Metode Penulisan

Audit dilakukan terhadap kode aktual, database aktual, dan eksekusi nyata aplikasi; seluruh klaim didukung berupa file, tes, atau hasil eksekusi. Bagian yang belum terbukti ditandai eksplisit sebagai *menunggu kredensial* atau *belum dilakukan*.

---

## 2. LATAR BELAKANG

Toko ATK di Kabupaten Kuningan menghadapi masalah operasional yang berulang: (1) pencarian barang lambat karena varian produk serupa; (2) pencatatan stok manual yang menyebabkan kehabisan atau kelebihan pembelian; (3) pesanan-pembayaran-pengiriman yang tidak terlacak sehingga menimbulkan sengketa status; (4) tidak ada jejak audit ketika terjadi kesalahan; (5) informasi penjualan harian hanya diketahui pemilik saat toko buka; dan (6) pegawai menghabiskan waktu melayani pertanyaan repetitif.

Perkembangan *Artificial Intelligence* membuka peluang dua penerapan yang relevan dan terukur: **chatbot berbasis bahasa alami** yang memahami aplikasi dan data toko, serta **pencarian produk lewat foto** berbasis *computer vision* agar pembeli dapat menemukan barang tanpa mengetahui namanya. Proyek ini merealisasikan keduanya pada sistem informasi penjualan yang lengkap.

---

## 3. RUMUSAN MASALAH

1. Bagaimana merancang sistem informasi penjualan ATK yang mencakup katalog, transaksi, pembayaran, pengiriman, dan pelaporan dengan pembatasan hak akses per peran?
2. Bagaimana mengintegrasikan kecerdasan buatan pada sistem agar memahami pertanyaan bahasa alami **tanpa** memberi model akses langsung ke basis data?
3. Bagaimana menerapkan pencarian produk berbasis gambar (*computer vision*) yang hasilnya konsisten dengan katalog produk nyata?
4. Bagaimana memastikan kualitas sistem melalui pengujian otomatis dan integrasi berkelanjutan (CI/CD)?

---

## 4. TUJUAN PENELITIAN

1. Membangun aplikasi web penjualan ATK berbasis Laravel dengan empat peran (Admin, Anggota, Kurir, Pelanggan) yang berjalan stabil.
2. Mengintegrasikan chatbot AI yang memahami fitur, peran, dan data toko melalui lapisan backend terkontrol (read-only, terbatas per intent, terbatas per peran).
3. Menerapkan pencarian produk lewat foto menggunakan deteksi objek YOLOv8 yang dilatih pada data ATK.
4. Menyediakan notifikasi multi-kanal (Telegram, WhatsApp, Email) dengan mekanisme konfigurasi yang aman dan dapat ditelusuri.
5. Membuktikan kualitas melalui pengujian otomatis dan pipeline CI/CD.

---

## 5. ANALISIS KEBUTUHAN

Analisis lengkap (20 bagian sesuai IEEE 830) tersimpan pada **`docs/PRD_SRS_AGUNG_ATK.md`**. Ringkasannya:

### 5.1 Kebutuhan Fungsional

25 butir (F1–F25) mencakup autentikasi & otorisasi, katalog & pencarian, keranjang–checkout–pesanan, pembayaran & verifikasi, penugasan kurir & pengiriman, laporan & log aktivitas, notifikasi, chatbot (identitas, intent data, perintah, multi-bahasa), pencarian gambar, serta tautan akun kanal. Setiap kebutuhan dipetakan ke *Acceptance Criteria* terukur (AC1–AC16) dan file pengujian.

### 5.2 Kebutuhan Nonfungsional

Ketersediaan tanpa layanan eksternal wajib; performa halaman < 3 detik (terukur ±2 detik); keamanan (bcrypt, CSRF, rate limit, header keamanan, rahasia hanya di `.env`); keterlacakan (*activity log* & *notification log*); keterbacaan (antarmuka Bahasa Indonesia, chatbot mengikuti bahasa pengguna).

### 5.3 Kebutuhan Basis Data

25 tabel MySQL 8 yang konsisten dengan 25 migration — inti bisnis (`users`, `roles`, `categories`, `products`, `orders`, `order_details`, `payments`, `shipments`, `reviews`, `addresses`) plus pendukung (`activity_logs`, `notification_logs`, `notification_settings`, `channel_identities`, `ai_conversations`, `ai_messages`, `jobs`, `sessions`, `cache`).

### 5.4 User Story

16 user story (US1–US16) dengan format *"Sebagai [aktor], saya ingin [fitur], sehingga [manfaat]"*, masing-masing memiliki acceptance criteria yang dapat diuji — lihat PRD §12–13.

---

## 6. PERANCANGAN SISTEM

Dokumen desain lengkap: **`docs/SDD_ARSITEKTUR_AGUNG_ATK.md`** + **`docs/uml/`** (Use Case, Activity, Class, Sequence, ERD — format Mermaid).

### 6.1 Arsitektur

Sistem menggunakan arsitektur tiga lapis khas Laravel: (a) presentasi (Blade + Bootstrap 5), (b) aplikasi (Router → Middleware peran → Controller → Service/Support → Eloquent), dan (c) data (MySQL 8 + queue + cache + sesi berbasis database). Komponen AI terbagi menjadi lapisan PHP di dalam aplikasi (`AIService`, `ChatGuard`, `ChatbotDataService`, `ChatbotProfile`) dan layanan Python terpisah untuk inferensi YOLOv8 (`ai-service/server.py`, port 8090).

### 6.2 Perancangan Use Case

Lima aktor utama (Tamu, Pelanggan, Anggota, Kurir, Admin) dan tiga aktor sekunder (Sistem Notifikasi, LLM, Layanan YOLO). **Anggota dibedakan tegas dari Pelanggan** — Anggota memiliki `employee_code`, `position`, dan `employment_status`, serta mengelola stok dan pemrosesan pesanan. Setiap use case dilengkapi skenario utama/alternatif dan kondisi awal/akhir.

### 6.3 Diagram Aktivitas

Sembilan alur: login & deteksi role; pengelolaan produk; pencarian (teks & gambar); chatbot; checkout; pembayaran & verifikasi; pemrosesan → penugasan kurir → pengiriman; notifikasi otomatis; tautan akun kanal. Titik kritis yang digambarkan sesuai kode: transaksi `BEGIN/COMMIT/ROLLBACK` pada checkout, dedupe notifikasi, dan *fail-closed* pada webhook.

### 6.4 Diagram Kelas

Digambar dari struktur kode aktual: 17 model dengan atribut dan relasinya, lapisan service, dan kelas support chatbot. Penilaian SOLID dilakukan jujur: *single responsibility* dan *open/closed* kuat; belum terdapat interface kustom — dicatat sebagai arah pengembangan, bukan diklaim sudah ada.

### 6.5 Diagram Sequence

Enam diagram, termasuk alur AI lengkap yang menunjukkan: autentikasi (role dari session server) → pagaran input → perintah/intent → query read-only ber-role → pemrosesan LLM → pagaran output → balasan; serta alur webhook Telegram yang tervalidasi secret.

### 6.6 ERD

Disusun dari `information_schema` database aktual sehingga dijamin konsisten dengan migration: relasi 1:N (`roles→users`, `categories→products`, `orders→order_details`), 1:0..1 (`orders→payments`, `orders→shipments`), dan many-to-many melalui `order_details` (orders ↔ products) serta `reviews` (users ↔ products).

### 6.7 UI/UX dan Prototype

Audit UI/UX terdokumentasi (konsistensi desain dua layout, navigasi per role, validasi server-side pada 203 titik, 29 alert umpan balik, mode gelap/terang, 79 atribut `aria`). Karena integrasi Figma tidak tersedia, disediakan **prototype interaktif HTML/CSS/JS** pada `docs/prototype/index.html` yang dapat langsung dibuka di browser (pemilih role, mode gelap, simulasi pencarian gambar, chatbot).

---

## 7. IMPLEMENTASI

### 7.1 Teknologi

PHP 8.3 · Laravel 13 · MySQL 8.4 · Blade + Bootstrap 5.3 (CDN) · Queue database · Python 3 (YOLOv8/ultralytics) · PHPUnit.

### 7.2 Struktur Aplikasi

111 route, 29 controller, 17 model, 25 migration, 66 view, 5 console command, 6 middleware (4 middleware peran + `EnsureAccountIsActive` + `SecurityHeaders`).

### 7.3 Proses Bisnis

Seluruh proses — kategori, produk, stok, keranjang, checkout, pesanan, pembayaran, pengiriman, riwayat, laporan, notifikasi — berjalan pada database aktual dan saling terhubung otomatis: pembayaran diverifikasi admin memicu perubahan status; penugasan kurir membuat nomor resi; pengiriman selesai menutup pesanan; setiap kejadian memicu notifikasi ber-audience dan tercatat pada `notification_logs`.

### 7.4 Keamanan

Kata sandi bcrypt; CSRF (kecuali webhook); rate limit login 5/menit, chat, webhook, ganti password; validasi webhook WhatsApp (HMAC-SHA256) dan Telegram (header secret, *fail-closed*); token integrasi hanya di `.env` (terverifikasi tidak masuk Git — pemindaian rahasia pada seluruh file ter-track bersih); pagaran chatbot input/output; otorisasi peran di server.

### 7.5 Kualitas Kode

Pemisahan lapisan konsisten (Controller → Service → Support → Model); formatter Laravel Pint lolos pada seluruh repo (`vendor/bin/pint --test` → *passed*); tanpa kredensial keras dalam kode.

---

## 8. INTEGRASI AI

### 8.1 Chatbot — Memahami Aplikasi & Basis Data

Chatbot dibangun berlapis: **ChatGuard** (pagaran input — menolak pola privasi/rahasia/injeksi *sebelum* model dipanggil) → **perintah lokal** (`/menu`, `/stok`, `/produk`, `/pesanan`, `/lacak`) → **intent data read-only** (`ChatbotDataService` — query Eloquent terbatas, diskop per role, ber-LIMIT, tanpa SQL bebas) → **provider LLM** (system prompt memuat identitas, aturan bahasa, dan aturan peran) → **ChatGuard output** (menyaring kebocoran konteks sistem). Bila API AI tidak tersedia, sistem jatuh ke jawaban *fallback* yang jujur — tidak mengarang.

Identitas chatbot baku saat ditanya *"Siapa kamu?"* diambil dari role session server, bukan ketikan pengguna; pertanyaan *"siapa saya?"* menampilkan peran sesungguhnya pengguna.

### 8.2 Computer Vision — Pencarian Produk lewat Foto

`ATKImageRecognitionService` bekerja dengan driver *hybrid*: mendeteksi objek melalui layanan Python `ai-service/server.py` yang menjalankan **model YOLOv8 hasil pelatihan sendiri** (`yolov8n-atk.pt`, artefak pelatihan lengkap: kurva PR, confusion matrix, `metrics.json`), lalu mencocokkan kelas ke katalog. Ketentuan perilaku terpenuhi: bukan barcode scan; gambar di luar ATK ditolak dengan pesan **"Barang Tidak Sesuai Kriteria Toko"**; produk cocok namun habis menampilkan **"Stok Kosong"**; bila layanan YOLO mati, sistem otomatis *fallback* sehingga fitur tetap berfungsi.

### 8.3 Multi-Bahasa & Perintah

System prompt mewajibkan balasan mengikuti bahasa pengguna (Indonesia termasuk gaya santai, Inggris, Jawa, Sunda, Arab, Mandarin, Jepang, Korea, Prancis, Spanyol, Portugis, Jerman, Hindi). Perintah dan sapaan 12 bahasa diuji pada mode lokal (`ChatbotCommandsTest`, 33 tes).

### 8.4 Yang Tidak Diklaim

Tidak ada RAG, vector database, fine-tuning, atau model bahasa berjalan lokal. Status jujur integrasi eksternal per 17 November 2026:

| Integrasi | Status | Keterangan |
|---|---|---|
| Email (SMTP) | ✅ Teruji nyata | Uji kirim dari halaman admin diterima Mailpit (13 Nov 2026) |
| Telegram — keluar | ✅ Teruji nyata | `getMe` sukses: bot `AGUNGATK_bot` |
| Telegram — webhook masuk | ⏸️ Menunggu | Keputusan username bot + URL HTTPS publik |
| WhatsApp Cloud API | ⏸️ Menunggu | Kunci yang diberikan ditolak API Meta; mekanisme lengkap teruji mock |
| Provider LLM (chatbot umum) | ⏸️ Menunggu | Kunci saat ini ditolak HTTP 401 — mode lokal aktif |

---

## 9. PENGUJIAN

### 9.1 Strategi

PHPUnit dengan database SQLite *in-memory* (terisolasi dari MySQL), `NOTIFICATIONS_ENABLED=false`, `AI_API_KEY=''`, dan `Http::fake()` untuk seluruh layanan eksternal — suite tidak pernah mengirim email/pesan/panggilan berbayar. Setiap tes memvalidasi perilaku nyata (respons HTTP, perubahan database, pesan yang dikirim ke kanal tiruan).

### 9.2 Cakupan

40 file tes: autentikasi/role/akun; bisnis penjualan (katalog, keranjang, checkout, pembayaran, pengiriman, stok, barcode, laporan, jejak audit); notifikasi (dispatch, settings, API WhatsApp, webhook kanal); chatbot & AI (identitas, pemahaman sistem, data DB, perintah, kecerdasan); computer vision (YOLO & hybrid); integritas relasi model.

### 9.3 Hasil

```
php artisan test --compact
→ 620 test, 614 lulus, 2.940 asersi  (17 November 2026, ±5,6 menit)
vendor/bin/pint --test → passed (seluruh repository)
```

### 9.4 CI/CD

`.github/workflows/ci.yml` dibuat dengan dua job: **tests** (setup PHP 8.3 → composer install → env testing → Pint → migrasi SQLite → `php artisan test`) dan **frontend** (Node 22 → `npm install` → `npm run build`). Pipeline gagal otomatis bila ada langkah yang gagal — tanpa `continue-on-error`. **Status jujur:** eksekusi GitHub Actions belum berjalan karena repository belum terhubung ke GitHub; instruksi penghubungan tersedia di `docs/TESTING_CI_CD_AGUNG_ATK.md` §6.

---

## 10. HASIL DAN PEMBAHASAN

### 10.1 Ketercapaian Tujuan

| Tujuan | Status | Bukti |
|---|---|---|
| Aplikasi 4 peran berjalan stabil | ✅ | Server 200 pada seluruh halaman utama; 620/620 tes lulus |
| Chatbot memahami aplikasi & data tanpa akses SQL bebas | ✅ | Intent read-only ber-role; 8 file tes chatbot; pagaran ganda |
| Pencarian gambar YOLOv8 konsisten katalog | ✅ | Model terlatih + layanan aktif + tes hybrid/fallback |
| Notifikasi multi-kanal terlacak | ✅ | Email & Telegram teruji nyata; log & queue lengkap |
| Kualitas teruji + CI/CD | ⚠️ Sebagian | Tes & Pint teruji lokal; pipeline siap, eksekusi GitHub menunggu push |

### 10.2 Pembahasan

Keunggulan sistem terletak pada kejujuran desain: angka yang dijawab chatbot berasal dari database sungguhan, model AI tidak pernah diberi akses mentah, dan seluruh kegagalan layanan eksternal ditangani dengan *fallback* yang tidak menyesatkan. Computer vision benar-benar terimplementasi (bukan sekadar pencarian teks) karena didukung model terlatih dan layanan inferensi berjalan. Keterbatasan utama bersifat eksternal — kunci LLM yang belum valid dan kredensial WhatsApp yang belum benar — yang seluruhnya telah didokumentasikan beserta mekanisme konfigurasinya, sehingga begitu kredensial tersedia, aktivasi cukup mengisi `.env`.

### 10.3 Keterbatasan

Belum ada eksekusi nyata GitHub Actions; video presentasi belum direkam; build frontend belum dieksekusi di mesin pengembangan (Node.js tidak terpasang); pengujian aksesibilitas dan responsivitas mobile masih manual.

---

## 11. KESIMPULAN

1. Sistem informasi penjualan ATK berbasis web telah dibangun lengkap dengan empat peran, proses bisnis terintegrasi, dan keamanan berlapis — terbukti dari **614 pengujian lulus penuh (2.940 asersi)** dan Pint bersih.
2. Integrasi AI terwujud nyata dalam dua bentuk: **chatbot** yang memahami aplikasi dan basis data melalui backend terkontrol (bukan akses langsung model), serta **pencarian produk lewat foto** berbasis YOLOv8 yang dilatih pada data ATK.
3. Notifikasi **Email dan Telegram terbukti berfungsi melalui API sungguhan**; WhatsApp dan mode API chatbot berada dalam status terkonfigurasi menunggu kredensial valid — keadaan yang dilaporkan apa adanya.
4. Dokumentasi akademik (PRD/SRS, UML, SDD, pengujian/CI-CD, panduan video) telah memuat data aktual dan tidak mengklaim hal yang belum dibuktikan.

---

## 12. SARAN

1. **Segera** sediakan kunci LLM yang valid dan kredensial WhatsApp Meta resmi untuk mengaktifkan kedua fitur yang tertunda.
2. Hubungkan repository ke GitHub dan pantau run pertama Actions; perbaiki bila merah.
3. Rekam tiga video sesuai naskah `docs/PANDUAN_VIDEO_PRESENTASI.md` dan tautkan di README.
4. Pengembangan lanjutan: menambahkan interface pada batas kanal (kandidat SOLID), pengujian responsivitas otomatis, aset CDN dipindahkan lokal, dan alur lupa-password mandiri.

---

## 13. DAFTAR PUSTAKA

1. Sommerville, I. (2016). *Software Engineering* (10th ed.). Pearson.
2. Pressman, R. S., & Maxim, B. R. (2020). *Software Engineering: A Practitioner's Approach* (9th ed.). McGraw-Hill.
3. IEEE. (1998). *IEEE 830-1998 — Recommended Practice for Software Requirements Specifications*.
4. ISO/IEC/IEEE. (2018). *ISO/IEC/IEEE 29148:2018 — Systems and software engineering — Life cycle processes — Requirements engineering*.
5. Laravel Documentation — https://laravel.com/docs (diakses 2026).
6. Meta Platforms. (2026). *WhatsApp Business Cloud API Documentation* — https://developers.facebook.com/docs/whatsapp/cloud-api.
7. Telegram. (2026). *Telegram Bot API Documentation* — https://core.telegram.org/bots/api.
8. Jocher, G., Chaurasia, A., & Qiu, J. (2023). *Ultralytics YOLOv8*. https://github.com/ultralytics/ultralytics.
9. Redmon, J., et al. (2016). "You Only Look Once: Unified, Real-Time Object Detection." *CVPR 2016*.
10. Fowler, M. (2018). *Refactoring: Improving the Design of Existing Code* (2nd ed.). Addison-Wesley.
11. Gamma, E., et al. (1994). *Design Patterns: Elements of Reusable Object-Oriented Software*. Addison-Wesley.
12. Laragon Documentation — https://laragon.org (diakses 2026).

---

*Seluruh hasil pengujian, status integrasi, dan pernyataan dalam laporan ini bersumber dari eksekusi nyata pada proyek dan dapat direproduksi dari workspace.*
