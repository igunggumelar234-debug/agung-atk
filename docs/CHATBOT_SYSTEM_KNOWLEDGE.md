# CHATBOT SYSTEM KNOWLEDGE — Agung ATK

Pengetahuan sistem untuk chatbot **Asisten Agung ATK**. Semua isi dokumen ini
diverifikasi terhadap **kode aktual** (`routes/web.php`, controller, model,
migration, config, view) dan database aktif — bukan asumsi. Setiap perubahan
struktur sistem HARUS diikuti pembaruan dokumen ini.

- Pemegang fakta database detail: [`CHATBOT_DATABASE_KNOWLEDGE.md`](CHATBOT_DATABASE_KNOWLEDGE.md)
- Implementasi chatbot: `app/Support/{AIService,ChatGuard,ChatbotProfile,ChatbotDataService,DatabaseKnowledgeService,WorkflowKnowledge}.php`

---

## 1. Identitas dan Fungsi Agung ATK

- **Nama**: Agung ATK (Agung ATK Kuningan) — toko **alat tulis kantor** (ATK).
- **Bentuk**: website e-commerce Laravel (PHP 8.3) dengan dashboard berbasis role,
  chatbot AI, pencarian produk teks + barcode + gambar, dan notifikasi otomatis.
- **Fungsi**: katalog & penjualan produk ATK online, pemrosesan pesanan oleh
  pegawai toko, pengiriman oleh kurir toko, laporan & pengaturan oleh admin.

## 2. Tujuan Utama Website

1. Pelanggan dapat menelusuri, mencari (teks/barcode/gambar), dan membeli produk ATK.
2. Toko memproses pesanan secara berjenjang: pembayaran diverifikasi admin,
   pesanan diproses anggota, dikirim kurir — dengan jejak Audit Log.
3. Admin memantau operasional: stok, transaksi, laporan, notifikasi, akun.

## 3. Role Pengguna (Diterapkan di Database `roles`)

Empat role kanonik (terverifikasi via seeder & kode middleware):

| Nama role | Label | Middleware | Grup route |
|---|---|---|---|
| `admin` | Administrator | `admin` | `/admin/*` |
| `anggota` | Anggota / Pegawai Toko | `anggota` | `/anggota/*` (+ alias legacy `/seller`, `/penjual`) |
| `kurir` | Kurir | `courier` | `/courier/*` (+ alias `/kurir`) |
| `pelanggan` | Pelanggan | `customer` | `/dashboard`, `/pelanggan/*`, `/cart`, `/checkout`, `/orders`, `/ai` |

Catatan: role lama `penjual` dimigrasikan ke `anggota` oleh migrasi rename.
Chatbot AI adalah "aktor" kelima **tanpa kewenangan operasional**.

## 4. Fungsi dan Kewenangan per Role

### Admin
- Dashboard admin, pesanan semua pelanggan (detail, batalkan → stok kembali).
- Kategori (CRUD), Produk (CRUD + gambar + stok + aktif/nonaktif).
- Pembayaran: verifikasi/tolak bukti (→ `paid`/`rejected`).
- Pengiriman: buat penugasan untuk pesanan `ready_to_ship`, pilih kurir aktif, tandai selesai.
- Kurir: daftar + saklar aktif/nonaktif.
- Pengguna & pegawai: buat/ubah/nonaktifkan/reset password/riwayat aktivitas.
- Audit Log seluruh pengguna; Laporan (layar/cetak-PDF/ekspor CSV); Notifikasi
  (Telegram/WhatsApp/Email, saklar per kejadian, uji kirim, bantuan Chat ID).

### Anggota / Pegawai Toko
- Pesanan **yang sudah dibayar** (`paid`…`completed`): `paid → processing`, `processing → ready_to_ship`.
- Stok Barang: pantau & perbarui stok.
- **TIDAK punya**: manajemen akun, laporan, verifikasi pembayaran, pengaturan.

### Kurir
- Hanya tugas pengiriman **miliknya** (`courier_id` = akun login; selain itu 404).
- Perbarui status **maju satu langkah**: `waiting → picked_up → shipping → delivered`
  (divalidasi server + kunci baris; status pesanan ikut menyesuaikan).
- Melihat alamat & telepon penerima untuk tugasnya; **tidak** melihat seluruh
  data pelanggan/transaksi.

### Pelanggan
- Katalog, detail produk, scan barcode, pencarian gambar, ulasan (pesanan
  `completed` miliknya yang belum diulas), keranjang, checkout (alamat milik
  sendiri; metode `transfer`/`qris`/`cod`), unggah bukti pembayaran, riwayat &
  pembatalan pesanan (`pending` saja), kelola alamat, profil, AI Assistant.

## 5. Halaman dan Menu

### Publik (tamu)
`/` beranda (+ kotak chatbot, throttle 20/menit), `/products` katalog
(pencarian `q` pada nama/SKU/merek/deskripsi + filter kategori), `/products/{slug}`
detail, `/products/scan/{code}` hasil scan, `/image-search` (POST, throttle 10/menit),
`/login`, `/register`.

### Menu sidebar per role (sumber: `layouts/dashboard.blade.php`)
- **Admin** (12): Dashboard Admin, Pesanan, Pembayaran, Kategori, Produk & Stok,
  Pengiriman, Kurir, Pengguna & Pegawai, Audit Log, Laporan, Notifikasi, Profil Saya.
- **Anggota** (4): Dashboard Anggota, Pesanan, Stok Barang, Profil Saya.
- **Kurir** (3): Dashboard Tugas Kirim, Pengiriman Saya, Profil Saya.
- **Pelanggan** (5): Dashboard Pelanggan, Pesanan Saya, Alamat Pengiriman,
  Profil Saya, AI Assistant (+ Keranjang & Checkout via ikon atas).

Semua role login: Profil Saya (ubah data & ganti password, throttle 10/menit).

## 6. Fungsi Fitur & Tombol Penting (Terverifikasi Kode)

| Fitur | Lokasi | Fungsi nyata |
|---|---|---|
| Scan barcode | katalog/beranda | cocokkan `barcode`/`sku` produk aktif → halaman produk, else pencarian katalog |
| Pencarian gambar | katalog/chatbot | `ATKImageRecognitionService` (engine YOLO lokal `ai-service/server.py`, fallback GD) → `ImageSearchService` menyarankan produk **nyata**; bukan-ATK/tidak yakin → pesan jujur |
| Keranjang | session server (`CartSession`) | qty dibatasi stok; produk nonaktif otomatis dibuang |
| Checkout | `/checkout` | validasi alamat milik sendiri + metode; `DB::transaction` + `lockForUpdate` + cache lock anti double-submit; stok −1×; nomor `ORD-YYMMDD-XXXX` |
| Bukti bayar | `/payments/{id}/proof` | unggah bukti (pelanggan pemilik) → notifikasi admin |
| Verifikasi bayar | `/admin/payments` | `verify` → order `paid`; `reject` → `rejected` |
| Proses pesanan | `/anggota/orders` | aksi maju satu langkah dengan cek ulang status terkunci |
| Penugasan kurir | `/admin/shipments` | buat shipment + resi untuk pesanan `ready_to_ship` |
| Status kurir | `/courier/shipments` | maju satu langkah; sinkron status pesanan |
| Ulasan | halaman produk | syarat: pelanggan + pesanan `completed` + berisi produk + belum diulas |
| Reset password | `/admin/users` | admin mereset password akun operasional |
| Notifikasi | `/admin/notifications` | saklar saluran & kejadian, uji kirim, bantuan Chat ID Telegram |
| Laporan | `/admin/reports` | layar, `/print` (cetak/PDF), `/export` (CSV), filter tanggal |

## 7. Alur Login dan Autentikasi

1. `/login` (email + password, `throttle:login` 5/menit per email+IP) — `Auth::attempt` + cek `is_active` (akun nonaktif → logout + penolakan).
2. `session()->regenerate()`; jejak `ActivityLog` (`auth.login`).
3. `redirectBasedOnRole` membaca **role dari database** (bukan input browser):
   admin `/admin/dashboard`, anggota `/anggota/dashboard`, kurir `/kurir/dashboard`,
   pelanggan `/pelanggan/dashboard`.
4. `/register` (throttle 5/menit/IP) → role otomatis `pelanggan`, hash password.
5. `/logout` → sesi dihapus + token diganti.

## 8. Alur Kerja per Aktor

- **Pelanggan**: belanja → bayar → pantau → terima → ulas.
- **Anggota**: notifikasi pesanan baru → cek `paid` → proses → siap kirim; pantau stok.
- **Admin**: verifikasi pembayaran → tugaskan kurir → pantau pengiriman →
  selesaikan; kelola katalog/akun; laporan & notifikasi.
- **Kurir**: menerima penugasan → ambil → antar → tandai terkirim (dengan
  riwayat waktu otomatis).

## 9. Alur Transaksi (Pesanan)

`pending` → `paid` → `processing` → `ready_to_ship` → `shipping` → `delivered` → `completed`
(atau `cancelled`).

- Pembayaran: `pending` → `verified`/`rejected`; metode `transfer`, `qris`, `cod`.
- Pembatalan: pelanggan hanya dari `pending`; admin dari `pending`…`ready_to_ship`;
  stok **dikembalikan tepat satu kali** (`OrderCancellationService`).
- Stok dikurangi tepat satu kali saat checkout (dalam transaksi, baris terkunci).

## 10. Alur Pengiriman

- Shipment dibuat admin untuk pesanan `ready_to_ship` + kurir aktif + nomor resi.
- Status shipment: `waiting → picked_up → shipping → delivered`
  (`App\Support\ShipmentStatus`) — kurir hanya boleh maju satu langkah.
- Sinkronisasi pesanan: `picked_up`/`shipping` → order `shipping`; `delivered` →
  order `delivered`. Waktu `shipped_at`/`delivered_at` tercatat otomatis.
- **Tidak ada** status gagal/retur di sistem.

## 11. Struktur Database

24 tabel terverifikasi live (users, roles, categories, products, product_images,
carts?, addresses, orders, order_details, payments, shipments, reviews,
notification_settings, notification_logs, activity_logs, …) dengan PK/FK/relasi
lengkap: lihat **`CHATBOT_DATABASE_KNOWLEDGE.md`** (ERD Mermaid, status enum,
mapping baca/tulis chatbot). Chatbot hanya mengintrospeksi skema **read-only**
dari tabel domain aktual (discoveri dinamis, tanpa daftar putih).

## 12. Integrasi AI

- `App\Support\AIService` — system prompt (identitas, aktor, peta sistem, langkah
  penggunaan, konteks peran JSON), routing jawaban lokal deterministik, pemanggilan
  AI API opsional (`config/ai.php`: `AI_API_KEY`, `AI_MODEL`, `AI_URL`, `AI_TIMEOUT`).
- `App\Support\ChatGuard` — pagaran INPUT (privasi + source code/kredensial →
  respons tetap sebelum model) dan pagaran OUTPUT (deteksi kebocoran prompt +
  redaksi rahasia).
- `App\Support\ChatbotProfile` — identitas ("Asisten Agung ATK"), tugas aktor,
  peta menu per role, jawaban "siapa saya"/"login sebagai apa" dari role session
  (tamu & role tak terverifikasi punya teks jujur khusus), scope jawaban
  "menu/tugas saya" dari role login.
- `App\Support\DatabaseKnowledgeService` — introspeksi skema read-only
  (discoveri dinamis seluruh tabel domain; cache disegarkan otomatis setelah
  migrasi, perintah `chatbot:refresh-knowledge`).
- `App\Support\ChatbotDataService` — lapisan DATA AKTUAL: intent baca-data
  (jumlah produk, stok menipis, terlaris, pendapatan/jumlah pesanan berrentang,
  tracking & lookup pesanan terotorisasi, penjelasan status pembayaran) → query
  Eloquent read-only dengan otorisasi role dari session; jawaban disuntikkan
  sebagai blok `JAWABAN DATA AKTUAL` pada mode API dan dikembalikan langsung
  pada mode lokal. Detail: `docs/CHATBOT_DATABASE_INTEGRATION.md`.
- `App\Support\WorkflowKnowledge` — langkah kerja per topik + daftar fitur belum
  tersedia (jujur).
- Endpoint: `POST /home/chat` (publik, throttle 20/menit) dan `/ai/*`
  (pelanggan, throttle 20/menit, percakapan per akun + reset).
- Tanpa `AI_API_KEY`: seluruh jawaban berasal dari fallback lokal (tanpa
  mengarang); dengan API: konteks JSON disertakan, keluaran tetap lewat pagaran.

## 13. Validasi, Batasan, dan Aturan Bisnis

- Validasi server-side pada FormRequest (login/register), checkout
  (alamat milik sendiri, metode `Rule::in`), unggah gambar (mimes + ukuran),
  ulasan (rating 1–5), status (Rule::in + transisi maju satu langkah).
- Rate limit: login/register, chat (20/menit), image-search (10/menit), uji
  kirim notifikasi (5/menit), ganti password (10/menit).
- Otorisasi: middleware role per grup route + cek pemilik di controller
  (`abort_unless` 404 untuk resource orang lain).
- Notifikasi: saklar global `NOTIFICATIONS_ENABLED`, mode mock untuk test,
  deduplikasi per jendela; kredensial **hanya** dari `.env`.

## 14. Fitur yang BELUM/TIDAK Tersedia (chatbot wajib jujur)

- Pengiriman gagal / retur / refund — tidak ada status & alurnya.
- Chat langsung ke kurir.
- Cetak struk untuk pelanggan (cetak/PDF hanya laporan admin).
- Pembayaran otomatis/PG (semua verifikasi manual admin).
- Dataset Roboflow untuk 6 kelas ATK opsional belum terlatih di server YOLO
  lokal (deteksi tetap jalan untuk kelas dasar + fallback GD).
- Notifikasi (status nyata 17 Nov 2026): **Telegram terhubung & teruji**
  (`getMe` HTTP 200); **email teruji lewat SMTP Mailpit** (pengiriman lokal
  berhasil; ganti ke Gmail menunggu App Password); **WhatsApp menunggu
  kredensial Meta yang valid** (token ditolak code 190 → dilewati aman).
  Detail: `docs/NOTIFICATION_INTEGRATION.md`.

## 15. Penanganan Error Umum

- **403** middleware role (akun salah grup) → halaman ditolak; arahkan login
  sesuai role.
- **404** resource orang lain / produk nonaktif → dianggap tidak ada (privasi).
- **429** rate limit → tunggu satu menit (chat/scan/login).
- **Validasi** → redirect back + pesan error bahasa Indonesia di field.
- **Sesi kedaluwarsa** → redirect ke `/login`; data chatbot tetap tersedia di
  beranda tanpa login (tanpa data internal).
- **Stok habis/kurang saat checkout** → pesan validation + keranjang disanitasi.
- **DB tidak tersedia di beranda** → landing page tetap tampil tanpa produk.

---

## Pemeliharaan Dokumen

Perbarui dokumen ini (dan `SYSTEM_MAP`/`ACTOR_DUTIES` di `ChatbotProfile`,
`WorkflowKnowledge`, serta `TABLE_PURPOSES`/`TABLE_ALIASES` di
`DatabaseKnowledgeService`) ketika: role/menu/route berubah, alur
pesanan/pengiriman berubah, tabel baru ditambahkan, atau fitur baru (termasuk
yang saat ini "belum tersedia") diaktifkan.

Tabel/kolom baru **tidak perlu** mengubah kode pengetahuan skema — struktur
dibaca langsung dari database (discoveri dinamis) dan cache-nya disegarkan
otomatis setelah `php artisan migrate` (lihat
`docs/CHATBOT_DATABASE_KNOWLEDGE.md` bagian "Pembaruan pengetahuan").
