# Laporan Audit — Sistem Informasi Penjualan ATK Kuningan

Tanggal audit: 18 Oktober 2026
Ruang lingkup: seluruh source code pada workspace (backend, frontend, database, konfigurasi, pengujian).
Metode: audit statis menyeluruh (3 pemeriksaan paralel: keamanan, backend/database, frontend/operasional), implementasi perbaikan bertahap, lalu verifikasi dengan test suite PHPUnit, Pint, dan pemeriksaan browser.

---

## 1. Ringkasan Kondisi Awal Aplikasi

Aplikasi sudah berada dalam kondisi **baik sebelum audit** — tidak ditemukan temuan kritis (P0):

- Test suite sudah lulus penuh (**414/414 tes**) sebelum audit dimulai.
- Tidak ada SQL injection, XSS tersimpan (output mentah Blade), CSRF gap, IDOR, command injection, maupun SSRF yang dapat dieksploitasi.
- Inti transaksi stok (checkout) sudah benar: satu titik pengurangan stok, transaksi + `lockForUpdate`.
- Autentikasi rate-limited (login/register), password di-hash, role dibaca dari database.

Masalah yang ditemukan berada pada prioritas **P1–P3**: kondisi balapan (TOCTOU) pada transisi status pesanan/pembayaran/pengiriman, kredensial demo yang selalu tampil di halaman login publik, hilangnya header keamanan dasar, unggahan SVG, endpoint AI berbayar tanpa batas laju, PII sebagai nilai default konfigurasi, inkonsistensi kebijakan password, mode gelap yang kontrasnya rusak di 3 titik, aksesibilitas (label + tombol ikon), serta beberapa celah kecil validasi/error handling.

---

## 2. Teknologi dan Arsitektur yang Ditemukan

| Lapisan | Teknologi |
| --- | --- |
| Framework | Laravel 13.31 (PHP 8.3.33, ekstensi `gd` + `fileinfo`) |
| Antarmuka | Blade + Bootstrap 5.3.3 (CDN jsdelivr, kini bersertifikat SRI), CSS desain kustom (`partials/theme.blade.php`, Mode Terang/Gelap) |
| Basis data | MySQL (`agung_atk`) di dev; SQLite in-memory untuk suite tes (`phpunit.xml`) |
| Antrean & cache | `QUEUE_CONNECTION=sync`, `CACHE_STORE=array` (tes), `NOTIFICATIONS_ENABLED=false` (tes) |
| Autentikasi | Auth sesi bawaan Laravel + 4 role (admin, penjual, kurir, anggota, pelanggan) via middleware + pembacaan role dari DB |
| Pencarian visual | Pure PHP + GD: `ImageFeatureExtractor` (412 dimensi), `ATKImageRecognitionService`, `ImageSearchService` |
| Notifikasi | Telegram / WhatsApp Cloud API / Email — semua kredensial dari `.env` |
| Pengujian | PHPUnit 12 (fitur), 28 file tes; Pint (format); skrip E2E PowerShell (115 pemeriksaan) |

Fitur utama: katalog + pencarian, keranjang, checkout, pembayaran (transfer/QRIS/COD), pengiriman kurir, modul penjual, pencatatan stok anggota, admin (produk/kategori/pesanan/pembayaran/pengiriman/laporan/audit log), asisten AI (chatbot beranda + halaman AI), notifikasi multi-saluran, pencarian produk berbasis gambar (Visual Search ATK).

---

## 3. Daftar Masalah Berdasarkan Prioritas

### P0 — Kritis
**Tidak ada.**

### P1 — Tinggi

**Status: seluruh temuan P1 & P2 di bawah ini telah diperbaiki pada audit ini (detail di bagian 4).**

| # | Temuan | Lokasi | Akar masalah | Dampak |
| --- | --- | --- | --- | --- |
| 1 | Balapan TOCTOU pada pembatalan pesanan: validasi status dilakukan pada model route-binding di luar transaksi terkunci | `OrderCancellationService`, `Admin/OrderController`, `OrderController` (pelanggan) | Status dicek sebelum `lockForUpdate`, tidak dicek ulang di dalam transaksi | Pesanan yang baru terverifikasi/dikirim bersamaan tetap bisa dibatalkan + stok direstok ganda/penyimpangan |
| 2 | Balapan TOCTOU pada verifikasi/tolak pembayaran | `Admin/PaymentController::verify/reject` | `update()` tanpa cek status pada baris terkunci | Verifikasi menimpa pembayaran yang sudah ditolak/dibatalkan |
| 3 | Transisi status penjual/pengiriman tanpa pemeriksaan ulang terkunci | `Seller/OrderController`, `Admin/ShipmentController`, `Courier/ShipmentController` | Pemeriksaan status di luar transaksi; duplikat resi hanya ditolak unique index (500) | Klik ganda/permintaan bersamaan melangkahi langkah status atau membuat resi ganda (QueryException 500) |
| 4 | Kredensial/akun demo selalu dirender di halaman login publik | `resources/views/auth/login.blade.php` | Tidak ada gerbang lingkungan | Di produksi, 4 akun uji + upaya password dipublikasikan |
| 5 | Tidak ada header keamanan dasar sama sekali | seluruh aplikasi | Tidak ada middleware header | MIME-sniffing, clickjacking, kebocoran referrer tanpa mitigasi |
| 6 | Unggahan kategori mengizinkan SVG | `Admin/CategoryRequest` | `mimes:...svg` | Stored XSS bila file SVG berisi `<script>` dibuka langsung dari `/storage` |
| 7 | Endpoint AI (API berbayar) tanpa rate limit | `routes/web.php` rute `ai.*` | Rute tanpa `throttle` | Kuota API dapat dibakar satu sesi |
| 8 | PII (email & telepon pribadi) sebagai nilai default konfigurasi notifikasi | `config/notifications.php` | Nilai default tertanam di repo | Kebocoran data pribadi di repository; notifikasi bisa terkirim ke kontak lama |

### P2 — Sedang

| # | Temuan | Lokasi |
| --- | --- | --- |
| 9 | Kebijakan password tidak konsisten: registrasi `min:6` vs profil/admin `min:8` | `Auth/RegisterRequest` |
| 10 | Slug kategori manual bisa menabrak (QueryException 500) — validasi unique hanya memeriksa input mentah, bukan hasil `Str::slug` | `Admin/CategoryController` |
| 11 | Pesan error memuat `$e->getMessage()` mentah (bocor struktur DB/path ke browser) | `Admin/ProductController` store/update/destroy |
| 12 | Penghapusan produk tanpa pemeriksaan riwayat penjualan → QueryException mentah dari FK restrict | `Admin/ProductController::destroy` |
| 13 | Kegagalan penyusunan notifikasi ditelan `catch` tanpa jejak; `dispatch()` selalu mengembalikan koleksi kosong (laporan ringkasan harian selalu "tidak terkirim") | `NotificationManager`, `DailyNotificationSummary` |
| 14 | Middleware peran mengembalikan redirect HTML untuk klien JSON (fetch/AJAX) | 5 middleware (`EnsureAccountIsActive`, `Admin`, `Anggota`, `Courier`, `Customer`) |
| 15 | N+1 query saat pemeriksaan ambang stok checkout (`Product::find` per baris keranjang) | `CheckoutController` |
| 16 | Validasi produk tanpa batas atas numerik (overflow kolom) | `Admin/ProductRequest` |
| 17 | Tanpa indeks untuk kolom pengurutan `created_at` pada tabel panas (orders, activity_logs, notification_logs) | migrasi |
| 18 | 13 kontrol formulir tanpa label/aria, 12 tombol ikon-saja tanpa `aria-label`, modal tanpa `aria-labelledby` | berbagai view |
| 19 | Mode gelap kontras rusak di 3 titik: kartu hero beranda (inline `background: white`), kartu pilihan checkout (inline `#eff6ff`), kotak chat AI (inline `#f8fafc`) | `home`, `checkout/index`, `ai/chat` + theme |
| 20 | `confirm()` di pencatatan stok anggota menghasilkan SyntaxError JS (`Js::from` di dalam kutip) — dialog konfirmasi tak pernah muncul | `anggota/stock/index` |
| 21 | Aset CDN tanpa SRI; pustaka QR memakai URL cabang (konten bisa berubah) | `layouts/*`, `dashboard/admin`, `products/show` |
| 22 | Endpoint ganti password profil tanpa rate limit | `routes/web.php` |

### P3 — Rendah

| # | Temuan | Status |
| --- | --- | --- |
| 23 | Injeksi formula CSV pada ekspor laporan (`=`, `+`, `-`, `@` di awal sel) | **Diperbaiki** (lihat bagian 4) |
| 24 | Duplikasi kecil: `products()->count()` dipanggil 2×; pembentukan slug berulang di 2 controller | Diperbaiki (kategori); produk dicatat sebagai rekomendasi |
| 25 | CSP tidak dipasang | **Sengaja tidak dipasang** — aplikasi bergantung pada `<script>`/`<style>` inline skala besar; CSP yang berguna menuntut `unsafe-inline` sehingga nilainya kecil. Didokumentasikan. |
| 26 | Klaim "notifikasi alert merah-di-merah" pada mode terang | Diperiksa: aturan `.alert-*` mode gelap (`theme.blade.php:555-577`) benar; tidak terkonfirmasi dari kode. |
| 27 | Pipa Vite (`npm run build`) tidak dipakai view mana pun (semua aset via CDN) | Didokumentasikan — lihat bagian 7 & 12. |

---

## 4. File yang Diubah dan Alasan Perubahan

### Keamanan

| File | Perubahan |
| --- | --- |
| `app/Http/Middleware/SecurityHeaders.php` **(baru)** | Header dasar (nosniff, X-Frame-Options, Referrer-Policy, Permissions-Policy, HSTS) + konstanta `HEADERS` + `apply()` statis agar dipakai dua jalur. Kamera sengaja TIDAK dimatikan (Visual Search). |
| `bootstrap/app.php` | Daftarkan `SecurityHeaders` pada grup `web`; hook `Exceptions::respond` agar respons exception (redirect tamu, 403/404/422/500) juga membawa header. |
| `resources/views/auth/login.blade.php` | Kotak "Akun Uji Coba" digated `@if(config('app.env') !== 'production')`. |
| `app/Http/Requests/Admin/CategoryRequest.php` | Hapus `svg` dari `mimes` (+pesan). |
| `routes/web.php` | `throttle:20,1` untuk grup rute AI; `throttle:10,1` untuk `PUT /profile/password`. |
| `config/notifications.php` | Default `admin_email`/`admin_phone` → string kosong (saluran dilewati dengan aman oleh pemeriksaan `blank()`). |
| `app/Http/Requests/Auth/RegisterRequest.php` | `min:6` → `min:8` + pesan (konsisten dengan profil/admin). |
| `app/Http/Requests/Admin/ProductRequest.php` | Batas atas numerik: harga ≤ 999.999.999,99; stok/min/berat ≤ 2.147.483.647 (+pesan). |
| `app/Http/Controllers/Admin/ReportController.php` | Sel CSV yang diawali karakter formula (`=+-@`, tab, CR) diberi awalan kutip tunggal (OWASP CSV injection). |

### Integritas Data (transaksi terkunci)

| File | Perubahan |
| --- | --- |
| `app/Services/OrderCancellationService.php` | Parameter `$allowedStatuses`; daftar status **diperiksa ulang pada baris terkunci** di dalam transaksi (TOCTOU). |
| `app/Http/Controllers/OrderController.php`, `app/Http/Controllers/Admin/OrderController.php` | Meneruskan daftar status yang sah + pesan penolakan yang jujur untuk kedua kasus. |
| `app/Http/Controllers/Admin/PaymentController.php` | `verify`/`reject`: `lockForUpdate` + cek `pending` di dalam transaksi; pesan galat menyebut status saat ini. |
| `app/Http/Controllers/Seller/OrderController.php` | `processing`/`readyToShip`: transisi divalidasi ulang pada baris terkunci. |
| `app/Http/Controllers/Admin/ShipmentController.php` | `store`: kunci baris pesanan, cek status + "belum punya resi" di dalam transaksi (duplikat kini ditolak dengan ramah, bukan 500); `complete`: kunci shipment + pesanan, cek `delivered`. |
| `app/Http/Controllers/Courier/ShipmentController.php` | `updateStatus`: transisi divalidasi ulang pada shipment terkunci; sinkronisasi pesanan memakai baris terkunci. |
| `app/Http/Controllers/Admin/ProductController.php` | `updateStock`: kunci produk, baca stok lama **di dalam** transaksi (delta audit akurat); `toggleStatus`: update atomik bersyarat (klik ganda aman). |
| `app/Http/Controllers/Anggota/StockController.php` | Pola sama: kunci + baca nilai lama dalam transaksi. |

### Error Handling & Konsistensi

| File | Perubahan |
| --- | --- |
| `app/Http/Controllers/Admin/ProductController.php` | Semua `catch` → `Log::error` + pesan generik (tanpa `$e->getMessage()`); `destroy` menolak produk berjualan dengan pesan jelas sebelum FK restrict. |
| `app/Services/Notifications/NotificationManager.php` | `catch` → `Log::warning('notification.dispatch_failed')`; `dispatch()` menangkap hasil lewat referensi pada `afterCommit` (ringkasan harian kini melaporkan jumlah pengiriman nyata). |
| 5 middleware peran | Klien `expectsJson()` → `401` (bukan redirect HTML); akun nonaktif tetap logout + invalidate sesi. |
| `app/Http/Controllers/Admin/CategoryController.php` | Helper `uniqueSlug()` dipakai keempat cabang (auto/manual, store/update); `destroy` menghitung produk sekali. |
| `app/Http/Controllers/CheckoutController.php` | N+1 → satu query `whereIn(...)->get()->keyBy('id')`. |

### Frontend & Aksesibilitas

| File | Perubahan |
| --- | --- |
| `resources/views/home.blade.php` | Hapus inline `background: white` (kartu kini ikut `var(--atk-card-bg)` — tampilan terang identik, gelap benar). |
| `resources/views/checkout/index.blade.php` | 2 kartu pilihan → kelas `option-card is-selected` (terang **persis** `#1e3a8a`/`#eff6ff` lama, gelap ikut tema); modal alamat: 8 pasang `id`/`for` + `aria-labelledby`. |
| `resources/views/partials/theme.blade.php` | Override `[data-theme="dark"] #chatBox` (latar gelap agar gelembung bot kontras). |
| `resources/views/anggota/stock/index.blade.php` | `confirm()` diperbaiki: string dibentuk penuh oleh `Js::from(...)` (SyntaxError hilang). |
| 10 file view (lihat bagian 3, #18) | `aria-label` pada 12 tombol ikon-saja; `aria-label` input AI, qty, select filter, file input kategori; `for`/`id` label email profil. |
| `layouts/app`, `layouts/dashboard`, `dashboard/admin`, `products/show` | SRI `integrity` + `crossorigin` pada 6 aset CDN; URL pustaka QR dipin ke commit `04f46c6a` (konten identik byte, kini immutable). |

### Database

| File | Perubahan |
| --- | --- |
| `database/migrations/2026_10_18_045732_add_list_sort_indexes_to_hot_tables.php` **(baru)** | Indeks `created_at` pada `orders`, `activity_logs`, `notification_logs` (murni tambahan, `down()` mengembalikan). Sudah dijalankan di DB dev. |

### Pengujian

| File | Perubahan |
| --- | --- |
| `tests/Feature/SecurityHeadersTest.php` **(baru)** | 2 tes: header pada respons 200 & redirect. |
| `tests/Feature/AuthTest.php` | +3: password <8 ditolak; kotak demo tersembunyi di produksi; tetap tampil di non-produksi. |
| `tests/Feature/AdminCatalogTest.php` | +1: tabrakan slug manual → sufiks numerik (bukan 500). |
| `tests/Feature/RoleAccessTest.php` | +3: JSON tamu → 401 (admin & kurir); JSON akun nonaktif → 401 + logout. |
| `tests/Feature/OrderCancellationTest.php` | +1: pembatalan ditolak saat status terkunci di luar daftar yang diizinkan (stok tidak kembali). |

**Tidak ada dependency yang ditambah/dihapus/diubah versi.** Perubahan murni pada kode aplikasi.

---

## 5. Perbaikan Keamanan, Performa, Database, Frontend, Backend

- **Keamanan:** header keamanan dasar (dua jalur: middleware + exception handler), gerbang akun demo di produksi, blokir SVG, rate limit AI + ganti password, PII default dikosongkan, kebijakan password seragam (min:8), batas validasi numerik, pesan error tanpa detail exception, injeksi formula CSV dicegah, SRI untuk seluruh aset CDN + URL pustaka dipin.
- **Database/Backend:** seluruh transisi status keuangan/pengiriman kini divalidasi ulang di dalam baris terkunci (`lockForUpdate`) — TOCTOU tertutup; delta audit stok dihitung dari nilai terkunci; toggle status atomik; indeks `created_at` untuk tiga tabel panas; N+1 checkout dihapus.
- **Frontend:** 3 kerusakan mode gelap diperbaikan tanpa mengubah tampilan mode terang (warna terang dipertahankan persis); 13 label formulir + 12 tombol ikon + 1 modal diperbaiki aksesibilitasnya; `confirm()` rusak diperbaiki.
- **Error handling:** semua `catch` penting kini mencatat ke log; klien JSON menerima kode status yang benar (401) bukan redirect HTML; kegagalan notifikasi terlihat oleh admin.

---

## 6. Daftar Test yang Dijalankan Beserta Hasil Aktual

Semua angka berasal dari eksekusi nyata (bukan proyeksi):

| # | Jalankan | Cakupan | Hasil |
| --- | --- | --- | --- |
| 1 | `php artisan test --compact --filter="OrderCancellationTest\|PaymentManagementTest\|SellerModuleTest\|ShipmentModuleTest\|CheckoutTest"` | Inti transaksi setelah Batch perbaikan TOCTOU | **109/109 lulus** (500 asersi, 78,7 dtk) |
| 2 | `--filter="AdminCatalogTest\|AdminManagementTest\|AuthTest\|RoleAccessTest\|AccountPagesTest\|NotificationDispatchTest\|NotificationSettingsTest\|BusinessAuditTest"` | Admin, auth, notifikasi, audit setelah Batch error-handling | **156/156 lulus** (823 asersi, 102 dtk) |
| 3 | `--filter="SecurityHeadersTest\|AuthTest\|RoleAccessTest\|OrderCancellationTest\|AdminCatalogTest"` | 10 tes regresi baru | 87/88 — **1 gagal** (redirect exception-handler belum membawa header) → diperbaiki via hook `Exceptions::respond` |
| 4 | `--filter="SecurityHeadersTest"` setelah perbaikan | Header pada respons exception | **2/2 lulus** (16 asersi) |
| 5 | **`php artisan test --compact` (suite penuh)** | Seluruh aplikasi + 10 tes baru | **424/424 LULUS — 2.155 asersi, 515 dtk** (414 tes lama + 10 tes baru) |
| 6 | Perbaikan terakhir (placeholder registrasi + sanitasi CSV): `--filter="DashboardReportTest\|AuthTest"` | Menutup dua edit pasca-suite | **35/35 lulus** (211 asersi) |
| 7 | `vendor/bin/pint --dirty --format agent` (berulang) | Format kode | **Bersih** — run terakhir `passed`, 0 file tersisa |
| 8 | Migrasi | `php artisan migrate` di DB dev MySQL | 3 indeks `created_at` dibuat (`.. 1dt DONE`) |
| 9 | Verifikasi browser nyata (tab, `http://127.0.0.1:8899`) | Header HTTP + UI | 5 header keamanan hadir pada respons live; kotak demo tampil di non-produksi (4 tombol `fillLogin`); kartu hero: terang `#ffffff` (**identik desain lama**), gelap `#1e293b` dengan teks `#f1f5f9`; kotak `.bg-light` internal ikut gelap; tema dipulihkan; **0 error console** |
| 10 | E2E crawl (`e2e_audit_final.ps1`, curl, 5 peran, server :8899) | 115 pemeriksaan status + broken-link | **115/115 LULUS — 0 gagal, 0 broken link** ("E2E FINAL AUDIT: LULUS SEMUA"), termasuk ekspor CSV 200, batas akses 403 tiap role, 404 ramah, dan gerbang tombol batal berdasarkan status pesanan |

**Hasil E2E akhir:** **115/115 LULUS, 0 gagal, 0 broken link** — dieksekusi setelah seluruh perubahan audit (termasuk SRI, header keamanan, dan perbaikan backend).

**Yang tidak diklaim:** tidak ada pengujian performa/balapan paralel yang dijalankan (lihat bagian 7), dan tidak ada klaim akurasi Visual Search baru — angka presisi berasal dari evaluasi dataset yang sudah terdokumentasi sebelumnya.

---

## 7. Masalah yang Belum Diselesaikan dan Alasannya

| Masalah | Alasan |
| --- | --- |
| CSP (Content Security Policy) | Aplikasi bergantung pada skrip/gaya inline (theme, chatbot, visual-search). CSP yang ketat akan mematahkan UI; CSP dengan `unsafe-inline` memberi nilai kecil. Butuh nonce/pemindahan inline ke file .js — pekerjaan terpisah. |
| Laporan admin memuat agregat SQL penuh (bukan agregat di database) | Refactor besar pada `ReportController::buildReport`; volume data lokal masih kecil. Risiko perubahan > manfaat saat ini. |
| Review produk tanpa paginasi | Produk nyata punya review terbatas; menambah paginasi mengubah UX halaman produk dan perlu verifikasi visual menyeluruh. |
| Pipa Vite tidak terpakai (aset via CDN) | Memutuskan antara self-hosting aset vs menghapus konfigurasi Vite adalah keputusan arsitektural — butuh persetujuan pemilik (lihat rekomendasi). |
| Tabel responsif (beberapa view tanpa `wrapper`) | Perubahan layout murni tanpa verifikasi visual berisiko; perlu pemeriksaan layar per layar. |
| `SendNotificationJob` tanpa `ShouldBeUnique` | Dengan `QUEUE_CONNECTION=sync` (dev) tidak berdampak; penambahan butuh konfigurasi cache unik & pengujian antrean nyata. |
| Notifikasi "akun dibuat admin" memakai event `account.registered` | Perilaku sudah diuji dan dipakai tes; mengubah event menyentuh kontrak notifikasi yang luas — butuh persetujuan. |
| Pengujian balapan **truly paralel** (2 request serentak) | PHPUnit tidak menjalankan HTTP paralel; balapan ditutup lewat pola transaksi terkunci + tes unit guard-nya. Bukti penuh butuh uji beban (k6) di luar cakupan. |
| Klaim akurasi Visual Search | Tidak ada klaim baru — angka presisi 76,9% berasal dari evaluasi dataset `atk:image-evaluate` yang sudah ada. |

---

## 8. Dependency

- **Ditambah:** tidak ada.
- **Diperbarui:** tidak ada.
- **Dihapus:** tidak ada.
- Dependency bawaan tetap: Laravel 13, Pint, PHPUnit, Boost, Pail, Faker, Mockery.

---

## 9. Risiko yang Masih Ada

1. **Dukungan header lewat HTTPS** — HSTS hanya efektif setelah situs dilayani via HTTPS; aplikasi lokal saat ini HTTP murni (aman, header diabaikan browser).
2. **CDN pihak ketiga** — SRI melindungi integritas konten, tetapi ketersediaan tetap bergantung pada jsdelivr. (Alternatif self-hosting: lihat bagian 12.)
3. **Kecepatan balapan** — pola `lockForUpdate` benar untuk MySQL InnoDB; pada SQLite (tes) kunci dilemahkan menjadi no-op — perilaku terkunci diuji pada level guard logika, bukan isolasi konkret.
4. **Rate limit berbasis sesi/IP** — pelindung awal yang baik, bukan pertahanan tunggal (bot dengan IP berbeda tetap bisa menembus; pertimbangkan limit per-user untuk endpoint berbayar).
5. **Repo tanpa commit** — seluruh pekerjaan (lama & audit ini) belum di-commit; bila file hilang, riwayat tak bisa dipulihkan. **Segera lakukan commit.**

---

## 10. Instruksi Menjalankan & Menguji Ulang

```powershell
# 1. Dependensi & environment
composer install
copy .env.example .env      # lalu isi DB & APP_KEY
php artisan key:generate

# 2. Migrasi (menambah indeks performa — non-destruktif)
php artisan migrate

# 3. Server dev (port 8899 dipakai selama audit)
php artisan serve --port=8899

# 4. Test suite penuh (ekspektasi lihat bagian 6)
php artisan view:clear
php artisan test --compact

# 5. Format kode (wajib setelah mengubah file PHP)
vendor/bin/pint --dirty --format agent

# 6. E2E (opsional, 115 pemeriksaan; sesuaikan base URL)
powershell -File C:\Users\Acer\AppData\Local\Temp\opencode\e2e_audit_final.ps1
```

Akun dev (password `AgungATK-dev-2026`): `admin@` / `penjual@` / `kurir@` / `pelanggan@agungatk.com`. Kotak "Akun Uji Coba" hanya tampil bila `APP_ENV ≠ production`.

---

## 11. Backup, Rollback, Deployment

- **Backup:** `mysqldump agung_atk > backup_agung_atk.sql` sebelum menjalankan migrasi apa pun di lingkungan berisi data. Folder `storage/app/datasets/` berisi dataset visual — cadangkan terpisah (ukuran besar).
- **Rollback kode:** repo belum memiliki commit — **langkah pertama: `git add -A && git commit`** untuk mengunci kondisi saat ini. Setelah itu, `git revert <hash>` perubahan individual.
- **Rollback migrasi:** `php artisan migrate:rollback --step=1` (hanya mem-drop 3 indeks; tidak menyentuh data).
- **Deployment:** aplikasi ini berjalan lokal. Bila dipublikasikan: wajib `APP_ENV=production` + `APP_DEBUG=false` (memicu penghapusan kotak demo & halaman error generik), HTTPS (mengaktifkan HSTS), isi `NOTIFICATION_*` di `.env` (bukan kode), dan jalankan `php artisan migrate --force`.

---

## 12. Rekomendasi Pengembangan Berikutnya

1. **Commit sekarang** — lalu aktifkan CI (Pint + PHPUnit) agar regresi tertangkap sebelum ke mesin lain.
2. **Self-hosting aset CDN** (bootstrap, bootstrap-icons, chart.js, qrcodejs → `public/vendor/`) — menghilangkan ketergantungan jaringan & masalah SRI sekaligus; atau set minimal pertahankan SRI seperti sekarang.
3. **Pindahkan skrip inline ke berkas .js** sebagai prasyarat CSP yang ketat (nonce-based).
4. **Agregat SQL untuk laporan** (GROUP BY di database) bila volume transaksi tumbuh.
5. **Paginasi review produk** + `->withQueryString()` dengan verifikasi visual Mode Terang/Gelap.
6. **Uji beban ringan** (k6) untuk membuktikan penanganan balapan status secara paralel.
7. **Limit per-user** untuk endpoint AI (bukan hanya per-IP) karena API berbayar.
8. **Self-host / pin semua aset** dan pertimbangkan penghapusan pipa Vite bila tetap memakai CDN.

---

*Catatan integritas: seluruh angka hasil pengujian pada laporan ini berasal dari eksekusi nyata. Tidak ada klaim akurasi yang diuji ulang di luar evaluasi dataset yang sudah terdokumentasi.*
