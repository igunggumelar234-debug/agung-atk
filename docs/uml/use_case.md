# USE CASE DIAGRAM & SKENARIO — AGUNG ATK

Sumber kebenaran: route `routes/web.php` (111 route), middleware peran, dan controller aktual.
Aktor utama: **Admin, Anggota, Kurir, Pelanggan** (+ **Tamu** sebagai aktor publik). Aktor sekunder: **Sistem Notifikasi**, **Layanan AI (LLM)**, **Layanan YOLOv8**.

---

## 1. Diagram Use Case (Mermaid)

```mermaid
usecaseDiagram
    actor "Tamu" as G
    actor "Pelanggan" as P
    actor "Anggota (Pegawai Toko)" as A
    actor "Kurir" as K
    actor "Admin" as AD
    actor "Sistem Notifikasi" as N
    actor "Layanan AI (LLM)" as AI
    actor "Layanan YOLOv8" as Y

    rectangle "Sistem Informasi Penjualan Agung ATK" {
        usecase "Jelajahi Katalog Produk" as UC1
        usecase "Cari Produk (Teks)" as UC2
        usecase "Cari Produk via Gambar" as UC3
        usecase "Lihat Detail & Ulasan" as UC4
        usecase "Daftar / Masuk Akun" as UC5
        usecase "Kelola Keranjang & Checkout" as UC6
        usecase "Unggah Bukti Pembayaran" as UC7
        usecase "Lacak Pesanan Saya" as UC8
        usecase "Beri Ulasan" as UC9
        usecase "Kelola Profil & Keamanan" as UC10
        usecase "Tautkan Akun Kanal (WA/TG)" as UC11
        usecase "Chatbot Tanya-Jawab" as UC12
        usecase "Kelola Produk & Kategori" as UC13
        usecase "Kelola Stok" as UC14
        usecase "Pindai Barcode" as UC15
        usecase "Proses Pesanan" as UC16
        usecase "Verifikasi Pembayaran" as UC17
        usecase "Tugaskan Kurir" as UC18
        usecase "Kelola Pengguna & Pegawai" as UC19
        usecase "Lihat Laporan & Ekspor" as UC20
        usecase "Pantau Log Aktivitas" as UC21
        usecase "Pengaturan Notifikasi" as UC22
        usecase "Lihat Daftar Tugas Kirim" as UC23
        usecase "Perbarui Status Pengiriman" as UC24
        usecase "Terima & Proses Chat Kanal" as UC25
        usecase "Kirim Notifikasi Otomatis" as UC26
    }

    G --> UC1
    G --> UC2
    G --> UC4
    G --> UC5
    G --> UC12

    P --> UC3
    P --> UC6
    P --> UC7
    P --> UC8
    P --> UC9
    P --> UC10
    P --> UC11
    P --> UC12

    A --> UC15
    A --> UC14
    A --> UC16
    A --> UC13
    A --> UC12

    K --> UC23
    K --> UC24
    K --> UC12

    AD --> UC13
    AD --> UC16
    AD --> UC17
    AD --> UC18
    AD --> UC19
    AD --> UC20
    AD --> UC21
    AD --> UC22
    AD --> UC25

    N --> UC26
    AI --> UC12
    Y --> UC3

    UC6 ..> UC5 : <<include>>
    UC3 ..> UC1 : <<include>>
    UC17 ..> UC16 : <<extend>>
    UC18 ..> UC16 : <<extend>>
    UC12 ..> UC10 : <<include>>
    UC25 ..> UC12 : <<include>>
    UC26 ..> UC22 : <<include>>
```

**Keterangan relasi**
- `<<include>>` — wajib terjadi dalam use case induk.
- `<<extend>>` — terjadi kondisional (opsi/keputusan).

---

## 2. Definisi Aktor

| Aktor | Jenis | Hak akses ringkas |
|---|---|---|
| Tamu | Primer (publik) | Katalog, detail, daftar/masuk, chatbot dasar |
| Pelanggan | Primer | Semua fitur belanja + profil + tautan kanal |
| Anggota (pegawai toko) | Primer | Stok, katalog, pemrosesan pesanan, barcode |
| Kurir | Primer | Tugas kirim miliknya saja |
| Admin | Primer | Kendali penuh (produk, pengguna, bayar, laporan, log, notifikasi) |
| Sistem Notifikasi | Sekunder | Mengirim pesan pada kejadian terkonfigurasi |
| Layanan AI (LLM) | Sekunder | Menyusun jawaban umum chatbot (butuh API key valid) |
| Layanan YOLOv8 | Sekunder | Deteksi objek gambar (`ai-service/server.py`) |

> **Anggota ≠ Pelanggan.** Anggota adalah pegawai toko (punya `employee_code`, `position`, `employment_status`) dengan halaman stok/pemrosesan; Pelanggan hanya berbelanja.

---

## 3. Skenario Utama & Alternatif

### UC5 — Daftar / Masuk Akun
- **Kondisi awal:** Pengguna belum terautentikasi.
- **Skenario utama (utama):** Pengguna membuka `/login` → mengisi email & kata sandi → sistem memverifikasi hash → diarahkan ke dashboard sesuai role (`redirectBasedOnRole`).
- **Alternatif:**
  - A1. Email/salah sandi → pesan error, percobaan dibatasi **5/menit per email+IP** (`throttle:login`).
  - A2. Akun `is_active = false` → ditolak oleh `EnsureAccountIsActive` dengan pesan jelas.
  - A3. Sudah login → halaman login mengarahkan ke dashboard role (di luar group `guest`).
- **Kondisi akhir:** Sesi terbentuk (tabel `sessions`), pengguna berada di dashboard role.

### UC6 — Keranjang & Checkout (pelanggan)
- **Kondisi awal:** Pelanggan login, stok produk cukup.
- **Skenario utama:** Tambah produk ke keranjang → buka `/checkout` → pilih alamat → submit → **transaksi DB** membuat `orders` + `order_details` + pengurangan stok → redirect ke halaman pesanan + notifikasi kejadian `order.created`.
- **Alternatif:**
  - A1. Stok kurang saat submit → transaksi dibatalkan (rollback), pesan "stok tidak cukup".
  - A2. Belum login → dialihkan ke `/login`.
  - A3. Keranjang kosong → checkout ditolak dengan pesan.
- **Kondisi akhir:** Pesanan berstatus `pending` tercatat, stok berkurang konsisten.

### UC7 — Unggah Bukti Pembayaran → UC17 Verifikasi (extend)
- **Skenario utama:** Pelanggan memilih pesanan `pending` → unggah gambar bukti → `payments` tercatat status `pending` → **Admin** membuka `/admin/payments` → melihat bukti → klik verifikasi → status `verified`, kolom `verified_by` terisi, pelanggan menerima notifikasi.
- **Alternatif:** Admin menolak → `rejection_reason` wajib diisi → pelanggan mengunggah ulang.

### UC18 — Tugaskan Kurir (extend UC16)
- **Skenario utama:** Admin membuka pesanan `paid/processing` → memilih kurir → `shipments` dibuat dengan `tracking_number` → status pesanan menjadi `shipping` → notifikasi ke pelanggan & kurir.
- **Alternatif:** Kurir belum tersedia → pesan "belum ada kurir aktif".

### UC23/UC24 — Kurir
- **Skenario utama:** Kurir login → melihat **hanya** pengiriman dengan `courier_id = dirinya` (`courier/shipments`) → memperbarui status (`shipped`→`delivered`) + opsional bukti serah → `delivered_at` terisi, pesanan selesai.
- **Alternatif (keamanan):** Membuka pengiriman orang lain → **ditolak** (teruji: *"tidak ditugaskan kepada Anda"*).

### UC3 — Cari Produk via Gambar
- **Kondisi awal:** Pengguna memberi foto (kamera/unggah), maks 2 MB.
- **Skenario utama:** Foto dikirim ke `ATKImageRecognitionService` → driver `hybrid`: POST ke `ai-service/server.py` (`/detect`, YOLOv8) → kelas terdeteksi cocok dengan katalog → tampil daftar kecocokan produk + skor.
- **Alternatif:**
  - A1. Layanan YOLO mati → fallback pengenalan bawaan (aplikasi tetap berfungsi).
  - A2. Barang bukan ATK → pesan baku **"Barang Tidak Sesuai Kriteria Toko"**.
  - A3. Cocok tetapi `stock = 0` → pesan baku **"Stok Kosong"**.
  - A4. Keyakinan < ambang (`min_confidence`) → hasil `uncertain` → ditampilkan jujur sebagai tidak yakin, bukan mengarang.
- **Kondisi akhir:** Pengguna menerima saran produk valid atau penolakan baku.

### UC12 — Chatbot Tanya-Jawab
- **Kondisi awal:** Widget chat terbuka (tamu/login).
- **Skenario utama:** Pesan masuk → **pagaran input `ChatGuard`** (bila pola privasi/rahasia → respons tetap, model TIDAK dipanggil) → deteksi perintah `/…` (dijawab lokal) → deteksi intent data (query read-only ber-scope role) → selain itu → panggil **Layanan AI (LLM)** dengan system prompt (identitas, aturan bahasa, aturan peran) → **pagaran output** → balasan.
- **Alternatif:**
  - A1. API AI tidak tersedia (kunci 401/timeout) → fallback lokal jujur (*"belum memiliki informasi yang cukup…"*).
  - A2. Pertanyaan lintas pengguna ("data pelanggan lain") → penolakan tetap tanpa data.
  - A3. Mode webhook kanal (`webhooks/telegram|whatsapp`) → validasi secret/HMAC gagal → request ditolak (fail-closed).
- **Kondisi akhir:** Balasan tampil; pesan & balasan tersimpan di `ai_conversations`/`ai_messages`.

### UC26 — Kirim Notifikasi Otomatis
- **Skenario utama:** Kejadian bisnis (order.created, payment.verified, shipment.delivered, ringkasan harian 07:00) → `NotificationManager` menentukan **penerima per role** → cek saklar kanal (`TELEGRAM_ENABLED`/`WHATSAPP_ENABLED`, email aktif) → susun pesan per penerima → tulis `notification_logs` (status pending) → antre job (queue) → kirim via Telegram/SMTP/WhatsApp → status `sent`/`failed` (+ retry 3×).
- **Alternatif:** Kanal tidak dikonfigurasi → dilewati dengan aman, status `failed` + alasan; `NOTIFICATIONS_MOCK=true` → tanpa pengiriman nyata.

### UC19/UC20/UC21 — Admin
- **Kelola pengguna:** buat/ubah/reset password pegawai → log aktivitas tercatat.
- **Laporan:** filter tanggal → agregasi penjualan → tampil/ekspor/cetak (`/admin/reports/export|print`).
- **Log aktivitas:** melihat riwayat aksi (`activity_logs`) — hanya admin.

---

## 4. Aturan Otorisasi Ringkas (traceability ke kode)

| Use case | Middleware/Gate |
|---|---|
| UC13, UC17, UC18, UC19–UC22, UC25 | `AdminMiddleware` |
| UC14, UC15, UC16 (versi anggota) | `AnggotaMiddleware` |
| UC23, UC24 | `CourierMiddleware` |
| UC6–UC9, UC11 | `CustomerMiddleware` |
| UC1–UC5, UC4, UC12 (dasar) | publik / `guest` |
| Seluruh halaman login | `EnsureAccountIsActive` + `SecurityHeaders` |

Seluruh use case di atas memiliki padanan route dan tes fitur (lihat `docs/TESTING_CI_CD_AGUNG_ATK.md`).
