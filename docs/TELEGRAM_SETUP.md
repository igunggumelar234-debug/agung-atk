# Setup Telegram - Agung ATK

Panduan mengaktifkan notifikasi & chatbot dua arah lewat **Telegram Bot API
resmi** (`api.telegram.org`).

> Fakta terverifikasi oleh pengujian `getMe` (HTTP 200): bot ini terdaftar
> dengan **username `AGUNGATK_bot`** dan nama tampilan `AGUNGATK.bot`.
> Username Telegram **tidak boleh berisi titik**, sehingga `@AGUNGATK.bot`
> tidak dapat dipakai sebagai username — konfigurasi memakai `AGUNGATK_bot`.

## 1. Membuat bot (satu kali)

1. Buka Telegram → chat **@BotFather** → `/newbot` (atau `/mybots` bila sudah ada).
2. Ikuti instruksi: beri nama tampilan (mis. `AGUNGATK.bot`) dan username
   (harus unik, huruf/angka/underscore — contoh: `AGUNGATK_bot`).
3. BotFather memberikan **Bot Token** (format `123456789:AA...`) — ini satu-satunya
   rahasia yang perlu disimpan.
4. Catat **Chat ID admin**: kirim pesan apa pun ke bot Anda, lalu ambil chat ID
   dari `https://api.telegram.org/bot<TOKEN>/getUpdates` (field
   `message.chat.id`). Chat ID proyek: `1887743171`.

## 2. Konfigurasi `.env`

```env
TELEGRAM_ENABLED=true
TELEGRAM_BOT_TOKEN=            # dari BotFather (RAHASIA — jangan di-commit)
TELEGRAM_BOT_USERNAME=AGUNGATK_bot   # tanpa '@' — hasil verifikasi getMe
TELEGRAM_ADMIN_CHAT_ID=1887743171    # penerima notifikasi admin
TELEGRAM_WEBHOOK_SECRET=            # string acak untuk validasi webhook masuk
```

- **Jangan pernah** memakai username sebagai token, atau token sebagai username.
- `TELEGRAM_WEBHOOK_SECRET` bisa dibuat sendiri (mis. hasil `php artisan key:generate
  --show` atau string acak lain) — akan dipakai sebagai header
  `X-Telegram-Bot-Api-Secret-Token` pada webhook.

## 3. Uji koneksi

- Dashboard **Notifikasi → Integrasi Chatbot → Uji Koneksi** (memanggil `getMe`
  + `webhookInfo` sungguhan), atau
- Langsung: `GET https://api.telegram.org/bot<TOKEN>/getMe`.

Hasil terakhir (17 Nov 2026): **HTTP 200** — `username: AGUNGATK_bot`,
`is_bot: true`. Pengiriman notifikasi tercatat `sent` pada riwayat dashboard.

## 4. Webhook (pesan masuk / dua arah)

Aplikasi menyediakan endpoint:

```
POST https://<domain-anda>/webhooks/telegram
```

Pemasangan (memakai perintah bawaan):

```bash
php artisan chatbot:webhook status     # lihat status webhook saat ini
php artisan chatbot:webhook set        # pasang (URL default: route aplikasi)
php artisan chatbot:webhook set --url=https://domain-anda.com/webhooks/telegram
php artisan chatbot:webhook remove     # cabut
```

Syarat:

1. **URL HTTPS publik** — Telegram menolak URL lokal/HTTP. Untuk pengembangan
   lokal gunakan domain publik atau terowongan (tunnel) yang mengarah ke
   `127.0.0.1:8899`, atau konfigurasikan `APP_URL` pada hosting.
2. **Secret valid** — endpoint menolak (403) semua permintaan tanpa/mirip
   secret (`TELEGRAM_WEBHOOK_SECRET`). Ini yang membuat webhook "fail-closed".
3. Setelah pasang, `chatbot:webhook status` menampilkan URL, jumlah update
   antre, dan `last_error` bila ada.

### Alur pemrosesan (aman)

- **Anti-duplikat**: `update_id` yang sama hanya diproses sekali (cache 24 jam).
- **Ukuran pesan**: pesan > 2000 karakter dibalas sopan tanpa memanggil AI.
- **Identitas**: chat ID diverifikasi ke akun aplikasi lewat **kode verifikasi**
  dari menu Profil → Integrasi Chatbot (bukan tebakan username/nomor).
  Tanpa tautan, pengguna diperlakukan sebagai **tamu** (tanpa data pribadi).
- Balasan dikirim via antrean (`SendChannelReplyJob`) agar webhook merespons cepat.

## 5. Tautan akun pengguna

1. Pengguna login → **Profil → Integrasi Chatbot** → muncul kode 6 digit.
2. Pengguna mengirim kode itu ke bot Telegram.
3. Sistem menautkan `chat_id` ke akunnya (`channel_identities`, status
   `verified`) → pertanyaan "pesanan saya" kini dijawab dengan data miliknya
   sesuai role.
4. Kode kedaluwarsa otomatis; tautan bisa dilepas dari halaman yang sama.

## 6. Pengujian

- Automated (API di-mock, tanpa pesan nyata): `tests/Feature/ChatbotChannelWebhookTest.php`
  — verifikasi secret, pesan masuk, duplikat, `/start`, kode tautan, privasi tamu.
- Nyata: tombol **Uji Koneksi** dashboard + kirim pesan manual ke bot.

## 7. Pemecahan masalah

| Gejala | Penyebab | Tindakan |
|---|---|---|
| `getMe` 401 | Token salah | Salin ulang token dari BotFather |
| Webhook 403 "Secret webhook tidak valid" | Secret belum/beda | Samakan `TELEGRAM_WEBHOOK_SECRET` lalu `config:clear` |
| `setWebhook` 409 | Webhook lama masih terpasang | `chatbot:webhook remove` lalu `set` ulang |
| Bot tidak membalas | Worker mati / `TELEGRAM_ENABLED=false` | Nyalakan worker & saklar |
| Username di dashboard berbeda dari harapan | Username harapan mengandung titik | Pakai `AGUNGATK_bot` (terverifikasi API) |
| Update menumpuk (`pending > 0`) | Webhook URL salah/mati | Perbaiki URL publik, cek `last_error` |
