# Laporan Audit - Sistem Informasi Penjualan ATK Kuningan

- Tanggal audit: 22 Oktober 2026 (WIB)
- Target: http://127.0.0.1:8899 (aplikasi berjalan lokal, diverifikasi dapat diakses)
- Metode: (1) pembacaan kode, konfigurasi, route, model, migration, view; (2) pengujian HTTP nyata: crawl 120 request GET + probe POST terbatas terhadap server yang sedang berjalan; (3) interaksi browser nyata (login 2 role, ganti tema, tambah keranjang, halaman checkout, chatbot); (4) kueri database MySQL read-only; (5) menjalankan seluruh test suite PHPUnit.
- Batasan: tidak ada data yang dihapus atau dimodifikasi. Satu-satunya data baru yang tercipta dari pengujian: 1 baris percakapan chatbot (milik akun pelanggan uji) dan isi keranjang sesi (memori sesi, bukan database). Form hapus ulasan admin TIDAK dijalankan.
- Pembanding: audit statis sebelumnya (AUDIT_REPORT.md, 18 Oktober 2026, 414 tes saat itu) - seluruh temuan P0/P1 lama TIDAK berulang dalam pengujian kali ini.
- Pembaruan perbaikan: 22 Oktober 2026 (tahap perbaikan) - AUD-001, AUD-002, dan AUD-003 selesai ditangani; rincian pada bagian "Hasil Perbaikan".

---

## 1. Ringkasan Eksekutif

| Prioritas | Jumlah | Keterangan |
|---|---|---|
| P0 (kebocoran data, bypass autentikasi, kerusakan kritis) | 0 | Tidak ditemukan |
| P1 (fitur utama rusak, transaksi salah) | 0 | Tidak ditemukan |
| P2 (UI, validasi, alur nonkritis) | 2 | AUD-001 (sidebar mode gelap), AUD-002 (timezone UTC) |
| P3 (penyempurnaan) | 1 | AUD-003 (catatan lingkungan lokal: kotak demo + debug) |

- Test suite: **434/434 lulus** (2.211 assert, 204,8 detik, 0 gagal).
- Probe lintas role: **22 request, 0 bypass** (semua 403).
- Database: **20 pemeriksaan konsistensi/integritas, semuanya bersih**.
- Log aplikasi: **0 galat baru** selama audit (galat terakhir 16 Oktober 2026 berasal dari salah ketik opsi konsol, bukan dari aplikasi).
- Konsol browser: **0 error JavaScript** pada seluruh halaman yang dikunjungi selama sesi audit.

---

## Hasil Perbaikan (tahap perbaikan, 22 Oktober 2026)

Seluruh temuan terverifikasi (P2 dan P3) telah diperbaiki. Temuan P0 dan P1 tidak ada sejak awal, sehingga tidak ada perbaikan backend/otorisasi yang diperlukan: penegakan role, CSRF, validasi server, transaksi checkout, dan pembatasan kepemilikan data sudah terbukti benar pada bagian audit.

### Perubahan kode

| Kesalahan | File yang diubah | Penyebab masalah | Solusi yang diterapkan | Status |
|---|---|---|---|---|
| AUD-001 (P2): kontras sidebar mode gelap 2,34:1 | `resources/views/partials/theme.blade.php`, `resources/views/layouts/dashboard.blade.php` | Latar sidebar memakai `var(--atk-secondary)`, token yang nilainya ikut berubah per tema (#0f172a di mode terang menjadi #f1f5f9 di mode gelap) karena token itu sebenarnya "tinta" (juga dipakai sebagai warna teks di 5 tempat). Teks sidebar (#94a3b8, putih saat hover/aktif) hardcoded dan didesain untuk latar gelap | Ditambahkan token khusus `--atk-sidebar-bg` (#0f172a pada mode terang; #1e293b pada mode gelap sebagai panel yang terpisah dari konten #0f172a); `.dashboard-sidebar` kini memakai token tersebut. Kontras terukur: mode gelap 5,71:1 (sebelumnya 2,34:1), mode terang 6,96:1 - keduanya lolos WCAG AA | SELESAI - terverifikasi langsung di peramban + tes regresi `ThemeTest` |
| AUD-002 (P2): zona waktu UTC untuk pengguna WIB | `config/app.php`, `.env`, `.env.example` | `config/app.php` hardcode `'timezone' => 'UTC'` dan tidak membaca env, sehingga tanggal dashboard dan filter `whereDate` meleset pada pukul 00.00-07.00 WIB | Menjadi `env('TZ', 'Asia/Jakarta')` plus `TZ=Asia/Jakarta` pada `.env` dan `.env.example`. Penjadwal harian sudah eksplisit `Asia/Jakarta` sehingga konsisten | SELESAI - dashboard kini menampilkan tanggal WIB yang benar ("Kamis, 22 Oktober 2026" saat jam mesin 01:52 WIB) + tes regresi `AppTimezoneTest` |
| AUD-003 (P3): kotak kredensial demo dan mode debug di lingkungan lokal | Tidak ada perubahan kode (dokumentasi) | Sifatnya pengaturan lingkungan; sudah berfungsi benar - kotak demo tersembunyi otomatis di production (dibuktikan `AuthTest`) | Checklist deployment dicatat pada bagian konfigurasi manual di bawah | SELESAI - dokumentasi |

### Hasil pengujian: sebelum vs sesudah

| Metrik | Sebelum perbaikan | Sesudah perbaikan |
|---|---|---|
| Suite PHPUnit | 434/434 lulus, 2.211 assert | 437/437 lulus, 2.217 assert (3 tes baru: 2 AppTimezoneTest + 1 ThemeTest), 0 gagal |
| Laravel Pint | bersih | bersih (`vendor/bin/pint --dirty` passed) |
| Kontras sidebar mode gelap (live) | 2,34:1 - gagal WCAG AA | 5,71:1 - lolos WCAG AA, 0 error konsol |
| Kontras sidebar mode terang (live) | 6,1:1 (perkiraan audit) | 6,96:1 (terukur), tidak berubah perilaku |
| Tanggal dashboard (live, 01.52 WIB) | "Rabu, 21 Oktober 2026" (UTC, meleset) | "Kamis, 22 Oktober 2026" (sesuai WIB) |

### Masalah yang belum selesai

Tidak ada temuan terverifikasi yang tersisa. Item "BELUM DAPAT DIVERIFIKASI" pada bagian 8 tetap berstatus demikian karena keterbatasan sesi/lingkungan (bukan bug): AUD-034 (tangkapan layar), AUD-035 (verifikasi visual responsif), AUD-036 (IDOR live terbatas data - mitigasi tercakup `AccountPagesTest::test_customer_cannot_view_other_users_order_detail` yang mengharapkan 404), AUD-037 (alur pesanan penuh live), AUD-038 (queue worker), AUD-039 (sandbox Midtrans), AUD-040 (chat id Telegram).

### Konfigurasi manual yang masih diperlukan

1. Checklist deployment produksi: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` sesuai domain. Zona waktu otomatis WIB lewat default config; mesin baru cukup menyalin `.env.example`.
2. Notifikasi Telegram: isi chat id (`TELEGRAM_CHAT_ID`/`TELEGRAM_ADMIN_CHAT_ID`) via menu Notifikasi admin setelah mengobrol dengan bot.
3. Dataset tambahan Roboflow (kelas sekolah) bila ingin memperluas model - memerlukan akun, petunjuk di `ai-service/README.md`.
4. Bila konfigurasi antrean memakai Redis di produksi: jalankan `queue:work`.
5. Pembayaran Midtrans: isi kredensial sandbox untuk menguji alur pembayaran nyata.
6. Dampak peralihan zona waktu: baris data lama (pesanan, log, aktivitas) kini ditafsirkan pada zona WIB sehingga tampil 7 jam lebih awal dibanding sebelumnya; tidak ada data yang diubah. Untuk produksi, atur TZ sejak awal agar tidak ada pergeseran interpretasi.

---

## 2. A. Pemeriksaan Awal

### 2.1 Teknologi yang benar-benar digunakan

| Komponen | Fakta (diverifikasi) |
|---|---|
| PHP | 8.3.33 (ZTS, Visual C++ 2019) |
| Laravel | 13.31.0 (composer.json: laravel/framework ^13.17) |
| Database | MySQL (driver `mysql`), 24 tabel, port 3306 (Laragon) |
| Frontend | Blade + Bootstrap 5.3.3 (CDN) + CSS custom properties untuk tema; JavaScript vanilla. Vite 8 + Tailwind CSS 4 terpasang sebagai perkakas build, tidak dominan pada UI aktif |
| Pengujian | PHPUnit 12.5.35 (30 file: 28 fitur + 1 unit + TestCase), Laravel Pint, Laravel Boost (dev) |
| Autentikasi | Hand-rolled (bukan Breeze/Fortify/Filament): session + CSRF, role dari kolom `users.role_id` di database |
| Layanan AI visi | FastAPI terpisah di port 8090, model YOLOv8n (`ai-service/models/yolov8n-atk.pt`, 16 kelas ATK); driver `hybrid` di `config/services.php` blok `image_search` (fallback k-NN GD bila layanan mati); klien: `app/Services/YoloDetectionService.php` + `ATKImageRecognitionService.php` |
| Chatbot AI | Provider OpenAI-compatible lewat variabel env `AI_*`; riwayat percakapan di tabel `ai_conversations` |
| Telegram | Bot via env `TELEGRAM_BOT_TOKEN` (validasi getMe pernah berhasil pada pekerjaan sebelumnya) |

### 2.2 Struktur dan konfigurasi

- Route: **108 route** (55 GET|HEAD, 21 POST, 19 PATCH, 5 PUT, 6 DELETE, 2 PUT|PATCH); file route: `routes/web.php`, `routes/console.php` (penjadwal), `routes/bootstrap/app.php`.
- Middleware role: `auth`, `admin`, `customer`, `seller`, `courier` (redirect 302 ke login untuk tamu, 403 untuk role lain); rate limit: login 5/menit per email, chat 20/menit, image-search 10/menit, AI 20/menit.
- Model: 20+ model Eloquent dengan relasi (explore: `User`, `Role`, `Product`, `Category`, `Order`, `OrderDetail`, `Payment`, `Shipment`, `Review`, `Notification`, dll.).
- Konfigurasi via env (`.env.example` memuat seluruh kunci; tidak ada secret di kode).
- `app.env = local`, `app.debug = true` (wajar untuk pengembangan; lihat AUD-003).

### 2.3 Status layanan (diuji langsung saat audit)

| Layanan | Hasil |
|---|---|
| Aplikasi web (8899) | GET / -> HTTP 200, judul "Beranda - Sistem Informasi Penjualan ATK Kuningan" |
| MySQL (3306) | Koneksi OK, 24 tabel |
| Layanan visi YOLO (8090) | /health -> model_loaded=True, 16 kelas |
| Task AgungATK-KeepAlive | Status Running; proses penjaga 1; port 8090, 8899, 3306 semuanya LISTEN |

### 2.4 Log dan error

- `storage/logs/laravel.log` 213 KB; baris ERROR terakhir bertanggal 16 Oktober 2026 dan berisi salah opsi konsol artisan (`--format`, `--columns`) - bukan kerusakan aplikasi.
- Selama audit (sekitar 400+ request termasuk crawl dan sesi browser) **tidak ada galat ERROR baru** yang masuk ke log.

---

## 3. B. Audit Halaman dan Fitur

Status: TERBUKTI BERMASALAH = punya bukti gagal; LULUS = terbuka dan berfungsi pada pengujian; BELUM DAPAT DIVERIFIKASI = alat/batasan sesi, bukan berarti rusak.

| ID | Halaman/Fitur | Temuan | Bukti | Tingkat Prioritas | Solusi |
|---|---|---|---|---|---|
| AUD-001 | Sidebar dashboard (semua role), tema mode gelap | SUDAH DIPERBAIKI (22 Okt 2026, rincian pada bagian "Hasil Perbaikan") - sebelumnya TERBUKTI BERMASALAH: saat mode gelap latar sidebar menjadi terang (#f1f5f9) sedangkan seluruh teksnya tetap warna untuk latar gelap. Ukuran kontras teks menu: 2,34:1 (jauh di bawah WCAG AA 4,5:1); state hover (teks putih di latar nyaris putih) praktis tak terlihat. Mode terang tidak terdampak (kontras 6,1:1) | Kode: `resources/views/layouts/dashboard.blade.php:39` memakai `background-color: var(--atk-secondary)`; `resources/views/partials/theme.blade.php:12` `--atk-secondary: #0f172a` (mode terang) dan `:398` `--atk-secondary: #f1f5f9` (mode gelap). Pengukuran browser: mode terang sidebar=#0f172a, mode gelap sidebar=#f1f5f9, teks menu #94a3b8 di kedua mode. Komentar perbaikan lama di theme.blade:320-321 hanya membahas "sidebar gelap" (kondisi mode terang) sehingga lolos pemeriksaan sebelumnya | P2 (SELESAI) | Sudah diterapkan: token `--atk-sidebar-bg` (#0f172a mode terang, #1e293b mode gelap) di `partials/theme.blade.php` dan dipakai `.dashboard-sidebar` di `layouts/dashboard.blade.php`. Terverifikasi live: mode gelap kontras 5,71:1 (sebelumnya 2,34:1), mode terang 6,96:1, 0 error konsol; tes regresi `ThemeTest` |
| AUD-002 | Seluruh aplikasi: tanggal tampilan dan filter laporan | SUDAH DIPERBAIKI (22 Okt 2026) - sebelumnya TERBUKTI BERMASALAH: `config('app.timezone') = UTC` sedangkan pengguna dan mesin berada di WIB (UTC+7). Pada pukul 00.43 WIB tanggal 22 Okt, dashboard menampilkan "Rabu, 21 Oktober 2026" (kemarin). Query berbasis `whereDate` (laporan harian, ringkasan terjadwal) ikut UTC sehingga pukul 00.00-07.00 WIB data tercatat pada tanggal sebelumnya | `php artisan config:show app.timezone` -> UTC; tinker `now()` = 2026-10-21 17:43 vs `Get-Date` mesin = 2026-10-22 00:43 +07:00; teks tanggal dashboard dibaca via DOM peramban | P2 (SELESAI) | Sudah diterapkan: `config/app.php` kini `env('TZ', 'Asia/Jakarta')`, ditambah `TZ=Asia/Jakarta` di `.env` dan `.env.example`. Terverifikasi live: dashboard menampilkan "Kamis, 22 Oktober 2026" (sesuai jam WIB); tes regresi `AppTimezoneTest`. Catatan dampak: timestamp lama kini dibaca pada zona WIB (geser 7 jam) - tidak ada data yang diubah |
| AUD-003 | Halaman login: kotak kredensial demo; mode debug | SUDAH DITINDAKLANJUTI (dokumentasi; tidak ada perubahan kode karena memang pengaturan lingkungan) - sebelumnya TERBUKTI BERMASALAH (P3, sifatnya lingkungan, bukan kode): di `APP_ENV=local` halaman login menampilkan kotak "isi otomatis" kredensial demo dan `app.debug=true` membuat halaman galat sangat detail. Berbahaya hanya bila terbawa ke produksi | DOM halaman login: tombol "Isi" untuk admin/anggota/kurir/pelanggan; `config:show app.debug` = true, `app.env` = local; `AuthTest` membuktikan kotak demo tersembunyi di lingkungan production | P3 (SELESAI - dokumentasi) | Tidak ada perubahan kode (memang pengaturan lingkungan; sudah terbukti tersembunyi otomatis di production lewat AuthTest). Checklist deployment dicatat pada bagian "Hasil Perbaikan" |
| AUD-004 | Beranda dan katalog publik | LULUS: GET / dan /products 200, daftar kategori dan produk tampil, 0 error konsol | Crawl anonim; snapshot + DOM peramban | - | - |
| AUD-005 | Detail produk (publik) | LULUS: judul, harga "Rp 48.500 / pcs", ketersediaan "Tersedia: 116 pcs", breadcrumb benar; harga dan stok berasal dari database | DOM peramban pada `/products/kertas-hvs-paperone-a4-75gr-rim` | - | - |
| AUD-006 | Login, registrasi, logout | LULUS: form berlabel (`for=email`, `for=password`, `for=remember`), tanpa `aria-hidden`; POST tanpa token -> 419; kredensial salah -> 302 dengan error validasi; logout via form POST berfungsi | Probe live (audit-retest); sesi browser logout/login nyata | - | - |
| AUD-007 | Dashboard administrator | LULUS: halaman tampil (judul benar), sidebar 13 menu, kartu statistik, grafik penjualan, aktivitas audit log, tombol tema; 23 route admin semuanya 200 | Sesi peramban + crawl (lihat Lampiran A) | - | - |
| AUD-008 | Dashboard anggota/penjual | LULUS: /anggota/dashboard, /anggota/orders (dan show), /anggota/stock, /seller/dashboard, /penjual/dashboard semuanya 200 | Crawl role anggota (6 route milik sendiri) | - | - |
| AUD-009 | Dashboard kurir | LULUS: /courier/dashboard, /courier/shipments (dan show), /kurir/dashboard semuanya 200 | Crawl role kurir (4 route milik sendiri) | - | - |
| AUD-010 | Dashboard pelanggan | LULUS: login nyata sebagai pelanggan (Budi Santoso) -> /pelanggan/dashboard tampil dengan menu sesuai peran | Sesi peramban | - | - |
| AUD-011 | Pengelolaan produk dan stok (admin) | LULUS: index, create, edit, show 200; stok tidak ada yang negatif (0 baris); form dan validasi tercakup tes | Crawl + kueri database + AdminCatalogTest | - | - |
| AUD-012 | Pengelolaan kategori (admin) | LULUS: index, create, edit 200; kategori tampil di katalog publik | Crawl + kueri database | - | - |
| AUD-013 | Pesanan (admin dan anggota) | LULUS: /admin/orders (dan show), /anggota/orders (dan show) 200; seluruh nilai status pesanan berada dalam daftar status yang sah (pending, paid, completed) | Crawl + kueri database | - | - |
| AUD-014 | Pembayaran | LULUS: /admin/payments (dan show) 200; bukti bayar tersimpan pada disk privat dan hanya dapat dibuka pemilik/admin; nilai pembayaran konsisten dengan grand total pesanan (0 selisih) | Crawl + kueri database + PaymentManagementTest | - | - |
| AUD-015 | Pengiriman dan kurir | LULUS: /courier/shipments (dan show) 200; /admin/shipments 200; status shipment dalam daftar sah; pesanan berstatus kirim selalu punya baris pengiriman (0 selisih) | Crawl + kueri database + ShipmentModuleTest | - | - |
| AUD-016 | Ulasan produk | LULUS: halaman produk memuat daftar dan form ulasan; halaman produk admin memuat form moderasi (hapus ulasan, action /admin/reviews/1); ulasan ganda per (pesanan, produk, pengguna) 0 | DOM peramban + kueri database + ReviewTest | - | - |
| AUD-017 | Laporan dan statistik | LULUS: /admin/reports, /admin/reports/export (CSV), /admin/reports/print semuanya 200 | Crawl + DashboardReportTest | - | - |
| AUD-018 | Notifikasi | LULUS: /admin/notifications 200; pengaturan dan pengiriman notifikasi tercakup tes (pengiriman eksternal dimock pada pengujian) | Crawl + NotificationSettingsTest + NotificationDispatchTest | - | - |
| AUD-019 | Profil, alamat, aktivitas | LULUS: /profile, /profile/edit, /addresses, /admin/users/1/activities, /admin/users/1/edit 200 | Crawl + AccountPagesTest + AdminManagementTest | - | - |
| AUD-020 | Keranjang dan checkout | LULUS (uji nyata di peramban): form "Tambah" (POST /cart, quantity=1) menambahkan produk; halaman keranjang menampilkan baris produk, harga Rp 48.500, stok 116, badge keranjang "1"; halaman checkout menampilkan 1. Alamat Pengiriman, 2. Metode Pembayaran, opsi ongkir, dan ringkasan belanja. Saat crawl awal /checkout membalas 302 karena keranjang kosong (perilaku benar, dicek ulang dengan isi keranjang -> tampil penuh) | Sesi peramban + crawl + CatalogCartTest + CheckoutTest | - | - |
| AUD-021 | Chatbot AI | LULUS (uji nyata): pesan "kertas HVS apa saja yang tersedia?" via widget -> POST /home/chat membalas HTTP 200 dalam 5,05 detik dengan rekomendasi produk database (PaperOne Rp 48.500 stok 116; Sinar Dunia Rp 52.000 stok 85) - konsisten dengan halaman katalog; riwayat percakapan tersimpan; guard privasi terlihat (menolak menampilkan data pribadi/pesanan orang lain) | Sesi peramban (network + DOM) + AiAssistantTest + AiDatabaseContextTest + HomeChatTest | - | - |
| AUD-022 | Pencarian visual (kamera) | LULUS: POST /image-search tanpa berkas -> 422 (validasi berjalan); layanan visi sehat (model_loaded=True); driver hybrid dengan fallback; pengenalan nyata tercakup YoloImageSearchTest (10 tes) dan ImageSearchTest (jalur fallback gd) | Probe live + status layanan + suite | - | - |
| AUD-023 | Pemindaian barcode | LULUS: BarcodeScanTest lulus; route /products/scan/{code} terdaftar | Suite + route list | - | - |
| AUD-024 | Tema Terang/Gelap/Sistem | LULUS (kecuali catatan AUD-001): klik "Mode Gelap" di peramban benar-benar mengubah `data-theme`, variabel CSS (misal `--atk-bg` menjadi #0f172a), dan tersimpan ke localStorage; opsi Mode Sistem tersedia; kembali ke Mode Terang bekerja | Sesi peramban (pengukuran terukur) + ThemeTest | - | - |
| AUD-025 | Otorisasi antar role | LULUS: 22 probe route role lain semuanya 403 (rincian di bagian 5); role dibaca dari database lewat middleware, bukan dari input pengguna | Crawl + probe + RoleAccessTest | - | - |
| AUD-026 | CSRF | LULUS: POST /login tanpa token -> 419 | Probe live | - | - |
| AUD-027 | Validasi form server | LULUS: image-search tanpa berkas -> 422; login kredensial salah -> redirect dengan pesan galat; seluruh form utama tercakup pengujian validasi | Probe live + suite | - | - |
| AUD-028 | Halaman galat | LULUS: URL tak dikenal -> 404 ramah, tanpa kebocoran jejak exception di respons | Probe live + ErrorPagesTest | - | - |
| AUD-029 | File privat dan storage | LULUS: route framework `GET/PUT storage/{path}` (FilesystemServiceProvider:111/119, disk `local` dengan `serve=true`) mewajibkan tanda tangan URL relatif - tanpa tanda tangan membalas 403 (dev) / 404 (produksi), dengan proteksi path traversal dan header CSP sandbox. Dataset AI dan bukti bayar terbukti tidak dapat diakses tanpa otorisasi | Probe live (/storage/1 -> 403); pembacaan kode framework ServeFile/ReceiveFile; ImageSearchTest skenario 15; PaymentManagementTest | - | - |
| AUD-030 | Integritas database | LULUS: 20 pemeriksaan konsistensi bersih (bagian 4) | Kueri read-only | - | - |
| AUD-031 | Keamanan statis (SQLi, XSS, IDOR, injection) | LULUS: tidak ditemukan pola berbahaya pada pembacaan kode (query terikat parameter, output Blade ter-escape); konsisten dengan audit statis 18 Okt; SecurityHeadersTest dan BusinessAuditTest lulus | Kode + suite | - | - |
| AUD-032 | Log, exception, error browser | LULUS: 0 galat ERROR baru di log selama audit; 0 error JavaScript di konsol peramban pada seluruh halaman yang dikunjungi | Log + konsol peramban | - | - |
| AUD-033 | Auto-start/keep-alive layanan | LULUS: task AgungATK-KeepAlive Running, 1 proses penjaga, port 8090/8899/3306 aktif; aplikasi terbuka tanpa diperintah | Status task dan proses saat awal audit | - | - |
| AUD-034 | Tampilan visual penuh (screenshot halaman) | BELUM DAPAT DIVERIFIKASI: peramban sesi tidak dapat mengambil tangkapan layar (jendela desktop tidak terlihat dari sesi OpenCode). Audit mengandalkan DOM terukur, pohon aksesibilitas, dan warna terhitung | Galat alat: "Screenshot needs a visible tab. ... keep its desktop window visible" | - | Buka aplikasi di peramban desktop dan lakukan pemeriksaan visual manual, atau ulangi sesi dengan jendela peramban terlihat |
| AUD-035 | Tampilan responsif (mobile/tablet) | BELUM DAPAT DIVERIFIKASI: sesi tidak memiliki kendali ukuran viewport. Pada tingkat kode ditemukan pola offcanvas sidebar untuk layar <= 992,98px (dashboard.blade:103-123) dan grid Bootstrap | Pembacaan kode CSS | - | Uji visual pada beberapa lebar layar (360px, 768px, 1280px) |
| AUD-036 | Pembatasan akses antar pemilik data (IDOR, live) | BELUM DAPAT DIVERIFIKASI (live): seluruh 8 pesanan di database hanya dimiliki satu akun sehingga tidak ada "pesanan orang lain" untuk diuji. Pengujian pembatasan otorisasi tersedia pada unit/feature test | Kueri database (pemilik order hanya user id 4) + AccountPagesTest | - | Tambahkan data pesanan milik akun lain (misal lewat seeder uji) lalu ulangi probe GET /orders/{id} lintas pemilik |
| AUD-037 | Pembuatan pesanan end-to-end (live) | BELUM DAPAT DIVERIFIKASI (live): sengaja tidak dijalankan agar tidak membuat data pesanan nyata (permintaan audit: tanpa perubahan data). Seluruh alur tercakup CheckoutTest, OrderCancellationTest, PaymentManagementTest, ShipmentModuleTest | Catatan metode | - | Uji alur penuh pada lingkungan uji dengan data disposable |
| AUD-038 | Antrean pekerjaan (queue worker Redis) | BELUM DAPAT DIVERIFIKASI: tidak ada pekerjaan antrean yang dipicu selama audit dan worker tidak dijalankan | Konfigurasi env | - | Jalankan `queue:work` bila konfigurasi queue memakai Redis di produksi |
| AUD-039 | Pembayaran Midtrans (sandbox) | BELUM DAPAT DIVERIFIKASI: kredensial sandbox tidak tersedia | Konfigurasi | - | Uji dengan kredensial sandbox resmi |
| AUD-040 | Kirim notifikasi Telegram (nyata) | BELUM DAPAT DIVERIFIKASI: `TELEGRAM_CHAT_ID` masih kosong (pengguna perlu chat bot dulu); token bot tervalidasi getMe pada pekerjaan sebelumnya; pengiriman diuji dengan mock | Konfigurasi env + NotificationDispatchTest | - | Isi chat id via menu Notifikasi admin lalu uji kirim nyata |

---

## 4. C. Audit Database

Koneksi dan cakupan: MySQL, 24 tabel; seluruh pemeriksaan bersifat read-only (tidak ada migrasi atau perubahan data).

| No | Pemeriksaan | Hasil |
|---|---|---|
| 1 | Jumlah baris tabel inti | users=4, roles=4, products=8, categories=6, orders=8, order_details=9, payments=8, shipments=2, reviews=1 |
| 2 | Stok produk negatif | 0 |
| 3 | Order detail tanpa produk (yatim) | 0 |
| 4 | Order tanpa pemilik | 0 |
| 5 | grand_total != (total + ongkir - diskon) | 0 |
| 6 | total_amount != jumlah subtotal detail | 0 |
| 7 | subtotal detail != harga x jumlah | 0 |
| 8 | Nilai pembayaran != grand total pesanan | 0 |
| 9 | Email pengguna ganda | 0 |
| 10 | SKU produk ganda | 0 |
| 11 | Nilai status pesanan | pending, paid, completed (semua dalam daftar status yang sah) |
| 12 | Nilai status pembayaran | pending, verified |
| 13 | Nilai status pengiriman | delivered |
| 14 | Ulasan ganda per (pesanan, produk, pengguna) | 0 |
| 15 | Pesanan tanpa detail | 0 |
| 16 | Pesanan berstatus bayar+ tanpa pembayaran | 0 |
| 17 | Pengiriman tanpa pesanan | 0 |
| 18 | Pesanan shipping/delivered/completed tanpa baris pengiriman | 0 |
| 19 | Index tabel orders | order_number (unik), user_id (FK), address_id (FK), status, created_at |
| 20 | Distribusi peran | admin 1, anggota 1, kurir 1, pelanggan 1 |

Catatan tambahan:

- Penurunan stok dan total transaksi dikerjakan di satu titik dalam transaksi database dengan `lockForUpdate` (temuan audit 18 Okt, dipertahankan); keamanan terhadap checkout ganda/ulang tercakup CheckoutTest (idempotensi, TOCTOU, stok bersamaan).
- Tidak ditemukan query dengan penggabungan string mentah yang rentan SQL injection; relasi antartabel memakai foreign key (terverifikasi lewat kueri `show index` dan ModelRelationTest/RelationshipTest).
- Data satu pelanggan tidak dapat dibuka pelanggan lain pada alur pembayaran/pesanan (PaymentManagementTest, AccountPagesTest) - catatan pembatasan uji live pada AUD-036.

---

## 5. D. Audit Role dan Keamanan

### 5.1 Pemisahan role

Role ditentukan dari akun terautentikasi di server (`users.role_id` -> `roles`), ditegakkan middleware `auth` + `admin`/`customer`/`seller`/`courier` per route group. Tidak ada penerimaan role dari form, parameter URL, atau penyimpanan sisi klien.

### 5.2 Hasil probe lintas role (22 request, nol yang tembus)

| Role yang login | Route role lain yang diprobe | Hasil |
|---|---|---|
| admin | /cart, /checkout, /orders, /ai | 403 semua |
| anggota | /admin/dashboard, /admin/products, /admin/users, /courier/dashboard, /cart, /checkout | 403 semua |
| kurir | /admin/dashboard, /admin/orders, /admin/products, /anggota/dashboard, /cart | 403 semua |
| pelanggan | /admin/dashboard, /admin/orders, /admin/products, /admin/users, /anggota/dashboard, /anggota/orders, /courier/dashboard | 403 semua |

Pemeriksaan pendukung:

- Route contoh dengan ID tidak valid: /admin/products/99999 -> 404; /orders/99999 (pelanggan) -> 404 (route model binding + pemeriksaan pemilik).
- CSRF: POST tanpa token -> 419.
- Unggahan produk: validasi tipe/ukuran berkas tercakup ProductCrudTest/AdminCatalogTest; gambar produk tersimpan pada disk publik terkelola, bukti bayar pada disk privat.
- Header keamanan aktif (SecurityHeadersTest).
- Rate limit login terpasang (5 percobaan/menit per email); pengujian kelewat-batas tercakup AuthTest.

---

## 6. E. Audit UI/UX

### 6.1 Tema (pengukuran terukur di peramban)

| Properti | Mode Terang | Mode Gelap |
|---|---|---|
| data-theme / localStorage | light | dark |
| --atk-bg | #f8fafc | #0f172a |
| Latar body | #f8fafc | #0f172a |
| Latar sidebar dashboard | #0f172a (teks #94a3b8, kontras sekitar 6,1:1) | #f1f5f9 (teks #94a3b8, kontras 2,34:1 -> AUD-001) |
| Tombol tersedia | Mode Terang, Mode Gelap, Mode Sistem | sama |

### 6.2 Aksesibilitas dan konsistensi

- Form login: label terhubung (`for`), input dapat dijangkau keyboard, tanpa `aria-hidden` pada kontrol.
- Struktur dashboard konsisten: sidebar kiri, top bar lengkap dengan nama pengguna, sapaan, dan jam tanggal; footer konsisten pada halaman publik.
- Empty state, loading state (indikator mengetik pada chatbot terlihat saat menunggu balasan), dan pesan flash tersedia; pola ini juga diuji ThemeTest dan tes halaman lain.
- Navigasi keyboard dan kontras non-sidebar tidak ditemukan masalah pada pengukuran terbatas; pemeriksaan kontras menyeluruh (Lighthouse/axe) belum dijalankan di sesi ini.

---

## 7. Pengujian yang Dijalankan

### 7.1 Test suite PHPUnit

- Perintah: `php artisan test --compact`
- Hasil: **434/434 tes lulus**, 2.211 assert, durasi 204,8 detik. **Tes gagal: 0.**
- Cakupan suite (28 file tes fitur): AccountPagesTest, AdminCatalogTest, AdminManagementTest, AiAssistantTest, AiDatabaseContextTest, AuthTest, BarcodeScanTest, BusinessAuditTest, CatalogCartTest, CheckoutTest, DashboardReportTest, ErrorPagesTest, ExampleTest, HomeChatTest, ImageSearchTest, ModelRelationTest, NotificationDispatchTest, NotificationSettingsTest, OrderCancellationTest, PaymentManagementTest, RelationshipTest, ReviewTest, RoleAccessTest, SecurityHeadersTest, SellerModuleTest, ShipmentModuleTest, ThemeTest, YoloImageSearchTest (plus tests/Unit/ExampleTest).

### 7.2 Pengujian langsung tambahan (di luar suite)

- Crawl HTTP: 120 request GET (54 anonim + 27 admin + 12 anggota + 9 kurir + 18 pelanggan, termasuk 22 probe lintas role). Rincian per route: Lampiran A.
- Probe terarah (audit-retest): 12 pemeriksaan, semuanya LULUS - product show/edit admin 200, ID tidak ada 404, admin ke /ai 403, pelanggan ke /ai 200, /orders/99999 404, CSRF 419, login salah 302, image-search tanpa berkas 422, /storage tanpa tanda tangan 403, 404 tanpa kebocoran exception.
- Sesi peramban nyata: login admin dan pelanggan, ganti tema (terang/gelap/sistem), alur tambah keranjang -> halaman keranjang -> halaman checkout, kirim pesan chatbot (balasan berisi produk database), logout.
- Kueri database read-only: 20 pemeriksaan (bagian 4).

---

## 8. Bagian yang Belum Dapat Diperiksa

1. Tangkapan layar dan pemeriksaan visual mata (jendela peramban tidak terlihat dari sesi) - AUD-034.
2. Tampilan responsif visual pada berbagai lebar layar - AUD-035 (verifikasi kode sudah dilakukan).
3. Pembatasan antar pemilik data pada pesanan (tidak ada data multi-pemilik untuk diuji live) - AUD-036.
4. Alur pembuatan pesanan end-to-end live (dihindari agar tidak membuat data) - AUD-037.
5. Pekerjaan antrean Redis - AUD-038; pembayaran sandbox Midtrans - AUD-039; kirim Telegram nyata (chat id kosong) - AUD-040.
6. Pemeriksaan kontras otomatis menyeluruh (Lighthouse/axe) - belum dijalankan.
7. Pengunduhan dataset tambahan Roboflow untuk pelatihan kelas sekolah (memerlukan akun) - di luar lingkup website, dicatat pada dokumentasi ai-service.

---

## 9. Catatan Transparansi

- Tidak ada data yang dihapus, dimigrasi, atau diubah selain: 1 percakapan chatbot baru (tabel ai_conversations, akun pelanggan uji) dan isi keranjang sesi (memori sesi).
- Percobaan login gagal selama pengujian tercatat normal pada mekanisme rate limit.
- Kredensial yang dipakai berasal dari variabel env `SEED_PASSWORD` dan tidak ditulis dalam laporan ini.
- Route `GET up` (health check framework) tidak dicrawl untuk menghindari catatan galat pada proses deploy framework.

---

Lampiran A di bawah ini dihasilkan otomatis dari data crawl nyata.

Data crawl HTTP nyata terhadap server yang sedang berjalan (http://127.0.0.1:8899). Setiap baris = satu request GET nyata. Kolom "(probe lintas role)" = request halaman role lain yang HARUS ditolak.

| Role | Status | Route | Nama Route |
|---|---|---|---|
| anon | 200 | `//` | home |
| anon | 200 | `/login` | login |
| anon | 200 | `/products` | products.index |
| anon | 200 | `/products/kertas-hvs-paperone-a4-75gr-rim` | products.show |
| anon | 200 | `/register` | register |
| anon | 302 | `/addresses` | addresses.index |
| anon | 302 | `/addresses/1/edit` | addresses.edit |
| anon | 302 | `/admin/activity-logs` | admin.activity-logs.index |
| anon | 302 | `/admin/categories` | admin.categories.index |
| anon | 302 | `/admin/categories/1/edit` | admin.categories.edit |
| anon | 302 | `/admin/categories/create` | admin.categories.create |
| anon | 302 | `/admin/couriers` | admin.couriers.index |
| anon | 302 | `/admin/dashboard` | admin.dashboard |
| anon | 302 | `/admin/notifications` | admin.notifications.index |
| anon | 302 | `/admin/orders` | admin.orders.index |
| anon | 302 | `/admin/orders/1` | admin.orders.show |
| anon | 302 | `/admin/payments` | admin.payments.index |
| anon | 302 | `/admin/payments/1` | admin.payments.show |
| anon | 302 | `/admin/products` | admin.products.index |
| anon | 302 | `/admin/products/1` | admin.products.show |
| anon | 302 | `/admin/products/1/edit` | admin.products.edit |
| anon | 302 | `/admin/products/create` | admin.products.create |
| anon | 302 | `/admin/reports` | admin.reports.index |
| anon | 302 | `/admin/reports/export` | admin.reports.export |
| anon | 302 | `/admin/reports/print` | admin.reports.print |
| anon | 302 | `/admin/shipments` | admin.shipments.index |
| anon | 302 | `/admin/users` | admin.users.index |
| anon | 302 | `/admin/users/1/activities` | admin.users.activities |
| anon | 302 | `/admin/users/1/edit` | admin.users.edit |
| anon | 302 | `/admin/users/create` | admin.users.create |
| anon | 302 | `/ai` | ai.chat |
| anon | 302 | `/anggota/dashboard` | anggota.dashboard |
| anon | 302 | `/anggota/orders` | anggota.orders.index |
| anon | 302 | `/anggota/orders/1` | anggota.orders.show |
| anon | 302 | `/anggota/stock` | anggota.stock.index |
| anon | 302 | `/cart` | cart.index |
| anon | 302 | `/checkout` | checkout.index |
| anon | 302 | `/courier/dashboard` | courier.dashboard |
| anon | 302 | `/courier/shipments` | courier.shipments.index |
| anon | 302 | `/courier/shipments/1` | courier.shipments.show |
| anon | 302 | `/dashboard` | dashboard |
| anon | 302 | `/kurir/dashboard` | kurir.dashboard |
| anon | 302 | `/orders` | orders.index |
| anon | 302 | `/orders/1` | orders.show |
| anon | 302 | `/payments/1` | payments.show |
| anon | 302 | `/payments/1/proof` | payments.proof |
| anon | 302 | `/pelanggan/dashboard` | customer.dashboard |
| anon | 302 | `/penjual/dashboard` | penjual.dashboard |
| anon | 302 | `/products/scan/8990000000001` | products.scan |
| anon | 302 | `/profile` | profile.index |
| anon | 302 | `/seller/dashboard` | seller.dashboard |
| anon | 302 | `/seller/orders` | seller.orders.index |
| anon | 302 | `/seller/orders/1` | seller.orders.show |
| anon | 403 | `/storage/1` | storage.local |
| admin | 200 | `/admin/activity-logs` | admin.activity-logs.index |
| admin | 200 | `/admin/categories` | admin.categories.index |
| admin | 200 | `/admin/categories/1/edit` | admin.categories.edit |
| admin | 200 | `/admin/categories/create` | admin.categories.create |
| admin | 200 | `/admin/couriers` | admin.couriers.index |
| admin | 200 | `/admin/dashboard` | admin.dashboard |
| admin | 200 | `/admin/notifications` | admin.notifications.index |
| admin | 200 | `/admin/orders` | admin.orders.index |
| admin | 200 | `/admin/orders/1` | admin.orders.show |
| admin | 200 | `/admin/payments` | admin.payments.index |
| admin | 200 | `/admin/payments/1` | admin.payments.show |
| admin | 200 | `/admin/products` | admin.products.index |
| admin | 200 | `/admin/products/1` | admin.products.show |
| admin | 200 | `/admin/products/1/edit` | admin.products.edit |
| admin | 200 | `/admin/products/create` | admin.products.create |
| admin | 200 | `/admin/reports` | admin.reports.index |
| admin | 200 | `/admin/reports/export` | admin.reports.export |
| admin | 200 | `/admin/reports/print` | admin.reports.print |
| admin | 200 | `/admin/shipments` | admin.shipments.index |
| admin | 200 | `/admin/users` | admin.users.index |
| admin | 200 | `/admin/users/1/activities` | admin.users.activities |
| admin | 200 | `/admin/users/1/edit` | admin.users.edit |
| admin | 200 | `/admin/users/create` | admin.users.create |
| admin | 403 | `/ai` | (probe lintas role) |
| admin | 403 | `/cart` | (probe lintas role) |
| admin | 403 | `/checkout` | (probe lintas role) |
| admin | 403 | `/orders` | (probe lintas role) |
| anggota | 200 | `/anggota/dashboard` | anggota.dashboard |
| anggota | 200 | `/anggota/orders` | anggota.orders.index |
| anggota | 200 | `/anggota/orders/1` | anggota.orders.show |
| anggota | 200 | `/anggota/stock` | anggota.stock.index |
| anggota | 200 | `/penjual/dashboard` | penjual.dashboard |
| anggota | 200 | `/seller/dashboard` | seller.dashboard |
| anggota | 403 | `/admin/dashboard` | (probe lintas role) |
| anggota | 403 | `/admin/products` | (probe lintas role) |
| anggota | 403 | `/admin/users` | (probe lintas role) |
| anggota | 403 | `/cart` | (probe lintas role) |
| anggota | 403 | `/checkout` | (probe lintas role) |
| anggota | 403 | `/courier/dashboard` | (probe lintas role) |
| kurir | 200 | `/courier/dashboard` | courier.dashboard |
| kurir | 200 | `/courier/shipments` | courier.shipments.index |
| kurir | 200 | `/courier/shipments/1` | courier.shipments.show |
| kurir | 200 | `/kurir/dashboard` | kurir.dashboard |
| kurir | 403 | `/admin/dashboard` | (probe lintas role) |
| kurir | 403 | `/admin/orders` | (probe lintas role) |
| kurir | 403 | `/admin/products` | (probe lintas role) |
| kurir | 403 | `/anggota/dashboard` | (probe lintas role) |
| kurir | 403 | `/cart` | (probe lintas role) |
| pelanggan | 200 | `/addresses` | addresses.index |
| pelanggan | 200 | `/addresses/1/edit` | addresses.edit |
| pelanggan | 200 | `/cart` | cart.index |
| pelanggan | 200 | `/dashboard` | dashboard |
| pelanggan | 200 | `/orders` | orders.index |
| pelanggan | 200 | `/orders/1` | orders.show |
| pelanggan | 200 | `/payments/1` | payments.show |
| pelanggan | 200 | `/payments/1/proof` | payments.proof |
| pelanggan | 200 | `/pelanggan/dashboard` | customer.dashboard |
| pelanggan | 200 | `/profile` | profile.index |
| pelanggan | 302 | `/checkout` | checkout.index |
| pelanggan | 403 | `/admin/dashboard` | (probe lintas role) |
| pelanggan | 403 | `/admin/orders` | (probe lintas role) |
| pelanggan | 403 | `/admin/products` | (probe lintas role) |
| pelanggan | 403 | `/admin/users` | (probe lintas role) |
| pelanggan | 403 | `/anggota/dashboard` | (probe lintas role) |
| pelanggan | 403 | `/anggota/orders` | (probe lintas role) |
| pelanggan | 403 | `/courier/dashboard` | (probe lintas role) |


