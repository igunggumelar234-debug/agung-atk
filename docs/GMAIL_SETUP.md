# Setup Gmail (Email SMTP) - Agung ATK

Panduan mengaktifkan notifikasi email lewat **SMTP Gmail resmi** memakai
**App Password** (bukan password akun biasa).

> Status jujur (17 Nov 2026): jalur SMTP aplikasi **teruji berfungsi** dengan
> server lokal Mailpit (`127.0.0.1:1025`, pengiriman nyata berhasil).
> Pengalihan ke Gmail **belum aktif** karena App Password belum diberikan —
> tanpa App Password, status email di dashboard sengaja menampilkan
> **"Belum lengkap (`MAIL_PASSWORD`)"**.

## 1. Mengapa App Password

Google tidak mengizinkan SMTP dengan password akun biasa bila verifikasi
dua langkah (2FA) aktif — yang dibutuhkan adalah **App Password** 16 karakter
khusus untuk aplikasi non-Google.

Syarat: akun Google dengan **2-Step Verification aktif**.

## 2. Membuat App Password (satu kali)

1. Buka `https://myaccount.google.com/security` → aktifkan **2-Step Verification**.
2. Buka `https://myaccount.google.com/apppasswords` (atau: Security →
   2-Step Verification → App passwords).
3. Beri nama (mis. `agung-atk-smtp`) → **Create**.
4. Salin 16 karakter (tanpa spasi) — hanya ditampilkan sekali.
5. Simpan di `.env` saja — **jangan** pernah dimasukkan ke source code,
   dokumen, screenshot, atau commit.

## 3. Konfigurasi `.env`

Blok siap-aktifkan sudah tersedia di `.env` (berkomentar) dan di
`.env.example`. Aktifkan dengan mengganti nilai (bukan menumpuk dua blok):

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=akun-anda@gmail.com   # alamat Gmail pengirim
MAIL_PASSWORD=<app-password-16-karakter>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=akun-anda@gmail.com
MAIL_FROM_NAME="Agung ATK"
```

Catatan:

- `MAIL_HOST`/`MAIL_PORT`/`MAIL_ENCRYPTION` wajib `smtp`/`587`/`tls` untuk Gmail.
- `NOTIFICATION_ADMIN_EMAIL` (alamat penerima notifikasi admin) boleh berbeda
  dari alamat pengirim.
- Saat masih memakai Mailpit lokal, biarkan blok `MAIL_*` Mailpit yang aktif
  (`127.0.0.1:1025`) — keduanya tidak boleh aktif bersamaan.

## 4. Mengaktifkan & menguji

1. Isi `MAIL_PASSWORD` (App Password) → `php artisan config:clear`.
2. Buka **Admin → Notifikasi**:
   - Kartu **Status Konfigurasi Saluran → Email** harus berubah menjadi
     **"Terkonfigurasi"** (sebelumnya "Belum lengkap" dengan `MAIL_PASSWORD`).
   - Kirim lewat **Kirim Pesan Uji → email** — hasil *terkirim*/*gagal* +
     pesan error aman.
3. Periksa **Riwayat Pengiriman** (filter saluran `email`, status `sent`).
4. Uji alur nyata: pesanan baru dari pelanggan → notifikasi `order.new`
   ke email admin.
5. Automated test (tanpa email nyata): `php artisan test tests/Feature/NotificationSettingsTest.php`.

## 5. Penanganan kegagalan

- Pengiriman berjalan di antrean (`NOTIFICATIONS_QUEUE=true`) dengan retry —
  kegagalan SMTP sementara tidak memblokir request aplikasi.
- Setiap percakapan tercatat `failed` + pesan error tersanitasi (tanpa
  kredensial) di `notification_logs` — terlihat di dashboard (filter "Gagal").
- Gagal total email TIDAK menggagalkan alur bisnis (checkout tetap jalan —
  diuji `NotificationDispatchTest::test_failed_notification_does_not_break_checkout`).
- Gmail membatasi jumlah pesan/hari; untuk volume besar pertimbangkan
  layanan email transaksional lain (konfigurasi SMTP-nya sama).

## 6. Keamanan

- App Password hanya di `.env` (tidak di-commit; `.env.example` hanya
  berisi placeholder/komentar).
- Dashboard tidak pernah menampilkan `MAIL_PASSWORD`.
- Rotasi App Password: hapus di Google Account → buat baru → perbarui `.env`
  → `config:clear`. App Password lama otomatis tidak berlaku.
