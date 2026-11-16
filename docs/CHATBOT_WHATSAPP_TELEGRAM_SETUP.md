# Integrasi Chatbot WhatsApp & Telegram — Agung ATK

Panduan menghubungkan **AI chatbot** Agung ATK ke WhatsApp Business Platform
Cloud API (Meta) dan Telegram Bot API, termasuk penerimaan pesan masuk
(webhook), tautan akun terverifikasi, pengiriman balasan, dan penanganan
kegagalan. Notifikasi *keluar* (peristiwa pesanan/pembayaran) sudah
didokumentasikan terpisah di [`WHATSAPP_SETUP.md`](WHATSAPP_SETUP.md).

> **Aturan emas:** token, access token, app secret, dan webhook secret hanya
> hidup di `.env` (gitignored). Jangan pernah menaruhnya di source code,
> frontend, database, dokumentasi, commit, atau log.

## Arsitektur

| Komponen | Peran |
|---|---|
| `App\Http\Controllers\WebhookController` | Endpoint resmi: verifikasi GET WhatsApp (hub.challenge), validasi signature HMAC Meta, validasi secret token Telegram, anti-duplikat (`wamid` / `update_id`) |
| `App\Services\Channels\ChannelIdentityService` | Kode verifikasi tautan akun (6 digit, berlaku 10 menit, anti brute-force) |
| `App\Services\Channels\InboundChatService` | Pesan masuk → identitas terverifikasi → percakapan AI → antrean balasan |
| `App\Jobs\SendChannelReplyJob` | Kirim balasan chatbot via antrian + catat ke `notification_logs` (`event_key = chatbot.reply`); `$tries = 3`, `backoff [10, 30]` |
| `App\Support\AIService` + `ChatbotDataService` | Jawaban AI (bahasa Indonesia sehari-hari) + data database sesuai hak akses role |
| Profil → **Integrasi Chatbot** | Pembuatan/pencabutan kode tautan oleh pengguna login |
| Admin → Pengaturan → Notifikasi | Status integrasi, riwayat webhook, tombol **Uji Koneksi** |

Alur pesan masuk:

```
Pengguna WhatsApp/Telegram
  → webhook Laravel (GET verifikasi / POST pesan)
  → validasi sumber (signature HMAC / secret token)
  → anti-duplikat (cache: wamid / update_id)
  → identitas kanal terverifikasi (channel_identities)
  → chatbot AI (role & data dari session backend, bukan dari platform)
  → pemeriksaan permission (guard privasi)
  → balasan via antrian (SendChannelReplyJob) ke kanal asal
```

---

## 1. Cara mendapatkan WhatsApp Cloud API

1. Buka <https://developers.facebook.com> → **My Apps** → **Create App**
   (tipe **Business**).
2. Tambahkan produk **WhatsApp** → **Set up**.
3. Meta memberi nomor uji + token sementara untuk uji cepat.
4. Detail langkah demi langkah (termasuk produksi & template) ada di
   [`WHATSAPP_SETUP.md`](WHATSAPP_SETUP.md) bagian 1–2.

## 2. Cara mendapatkan Phone Number ID

- **WhatsApp → API Setup**: deret *From* menampilkan nomor beserta
  *Phone number ID* (format: `100123456789012`).
- Nomor bisnis sendiri: **WhatsApp Manager → Settings → Phone numbers**.
- Isi ke `.env`:

  ```env
  WHATSAPP_PHONE_NUMBER_ID=100123456789012
  WHATSAPP_BUSINESS_ACCOUNT_ID=<opsional>
  WHATSAPP_API_VERSION=v21.0
  ```

> Catatan: nomor **pengirim** Cloud API berasal dari Meta (Phone Number ID
> di atas). `WHATSAPP_ADMIN_NUMBER` adalah nomor **penerima** notifikasi
> admin — jangan tertukar.

## 3. Cara mengatur Access Token

- *Uji cepat*: token sementara di **API Setup** (±24 jam).
- *Produksi*: **Business Settings → System Users → Generate new token**
  dengan izin `whatsapp_business_messaging` + `whatsapp_business_management`
  (tidak kedaluwarsa).
- Isi ke `.env`:

  ```env
  WHATSAPP_ACCESS_TOKEN=<token>
  WHATSAPP_MESSAGE_MODE=text          # 'template' bila memakai template resmi
  WHATSAPP_TEMPLATE_NAME=
  WHATSAPP_TEMPLATE_LANGUAGE=id
  ```

**Token webhook (dibutuhkan chatbot masuk):**

```env
WHATSAPP_ENABLED=true
WHATSAPP_VERIFY_TOKEN=<acak-64-karakter>   # diverifikasi Meta saat GET
WHATSAPP_APP_SECRET=<app-secret-meta>      # validasi X-Hub-Signature-256
WHATSAPP_DEFAULT_COUNTRY_CODE=62
```

- `WHATSAPP_VERIFY_TOKEN`: buat sendiri string acak (contoh:
  `php artisan tinker --execute "echo bin2hex(random_bytes(32));"`).
- `WHATSAPP_APP_SECRET`: dari **App Settings → Basic → App Secret**.
  Dengan app secret, setiap POST webhook wajib bertanda tangan HMAC-SHA256
  yang benar — POST tanpa signature yang cocok dijawab `403`.

## 4. Cara membuat Telegram Bot melalui BotFather

1. Buka Telegram → cari **@BotFather** → kirim `/newbot`.
2. Ikuti instruksi (nama tampilan lalu **username** — username wajib
   berakhiran `bot`).
3. BotFather memberi **Bot Token** (format: `123456789:AAE...`).
4. **Username vs display name:** yang dapat dipanggil pengguna adalah
   *username* (mis. `AGUNGATK_bot`); nama titik (mis. `AGUNGATK.bot`)
   hanyalah nama tampilan dan **bukan** username yang terdaftar.

## 5. Cara mengatur Bot Token

Isi ke `.env` (jangan pernah commit):

```env
TELEGRAM_ENABLED=true
TELEGRAM_BOT_TOKEN=<token-dari-botfather>
TELEGRAM_BOT_USERNAME=AGUNGATK_bot      # username aktual, tanpa '@'
TELEGRAM_BOT_CHAT_ID=1887743171         # alias TELEGRAM_CHAT_ID
TELEGRAM_ADMIN_CHAT_ID=1887743171
TELEGRAM_WEBHOOK_SECRET=<acak-64-karakter>
```

- Token **bukan** username; username **bukan** chat id. Tiga hal berbeda.
- `TELEGRAM_WEBHOOK_SECRET` wajib terisi agar webhook masuk bisa divalidasi
  (endpoint gagal-tertutup: bila kosong, semua POST dijawab `403`).
- Verifikasi token & username kapan saja lewat admin → Notifikasi →
  **Uji Koneksi** (memanggil `getMe` — tidak mengirim pesan).

## 6. Cara mendapatkan Chat ID

1. Pengguna membuka bot → tekan **Start** → kirim pesan `/start`.
2. Ambil chat id lewat salah satu cara:
   - Admin → Notifikasi → **Ambil Chat ID dari Pesan Terbaru**; atau
   - buka `https://api.telegram.org/bot<TOKEN>/getUpdates` (token dari
     `.env`, jangan tempel ke tempat publik).
3. Simpan sebagai `TELEGRAM_ADMIN_CHAT_ID` (admin) atau catat untuk proses
   tautan akun pengguna (bagian *Tautan akun* di bawah).

> Chat id pengguna **tidak** cukup untuk mengakses data akun — harus lewat
  kode verifikasi dari halaman Profil (bagian *Tautan akun*).

## 7. Cara mengatur webhook

### URL webhook

| Kanal | Endpoint |
|---|---|
| WhatsApp (verifikasi GET) | `https://<domain>/webhooks/whatsapp` |
| WhatsApp (pesan POST) | `https://<domain>/webhooks/whatsapp` |
| Telegram (pesan POST) | `https://<domain>/webhooks/telegram` |

Dev lokal **butuh URL HTTPS publik** — gunakan terowongan seperti ngrok
(`ngrok http http://127.0.0.1:8899`) atau Cloudflare Tunnel, lalu pakai
domain hasilnya.

### Telegram (otomatis dari aplikasi)

```bash
php artisan chatbot:webhook status    # cek webhook terpasang & galat terakhir
php artisan chatbot:webhook set       # pasang (URL default: route aplikasi)
php artisan chatbot:webhook set --url=https://domainanda.com/webhooks/telegram
php artisan chatbot:webhook remove    # cabut
```

Perintah `set` memasang `secret_token` dari `TELEGRAM_WEBHOOK_SECRET` sehingga
setiap POST dari Telegram diverifikasi header
`X-Telegram-Bot-Api-Secret-Token`.

### WhatsApp (dari dasbor Meta)

1. **WhatsApp → Configuration → Webhook → Callback URL**.
2. Isi `https://<domain>/webhooks/whatsapp` + **Verify token** = nilai
   `WHATSAPP_VERIFY_TOKEN` (persis sama).
3. Meta melakukan GET verifikasi → aplikasi membalas `hub.challenge`.
4. Aktifkan field **messages**.
5. Isi **App Secret** ke `WHATSAPP_APP_SECRET` agar signature
   `X-Hub-Signature-256` divalidasi (disarankan).

> Pendaftaran webhook Telegram otomatis **sengaja dihentikan** sampai pemilik
> mengonfirmasi username bot (lihat *Status* di bagian 12).

## 8. Cara menjalankan queue Laravel

Balasan chatbot & notifikasi dikirim lewat antrean:

```bash
php artisan queue:work --tries=3 --sleep=3
```

- Tanpa worker, pesan menumpuk di tabel `jobs` (status log tetap `pending`).
- Agar worker hidup terus: `php artisan queue:listen` di supervisor/cron, atau
  Windows Task Scheduler menjalankan perintah di atas saat login.
- Uji cepat tanpa worker: kirim pesan uji dari halaman admin (jalankan
  sinkron `dispatchSync`).

## 9. Cara menguji kedua platform

**Tanpa pesan nyata (otomatis):**

```bash
php artisan test --compact tests/Feature/ChatbotChannelWebhookTest.php
```

30 tes menutup: validasi konfigurasi, verifikasi challenge, signature/secret,
anti-duplikat, komunikasi AI, akses role, tautan kode, kegagalan API,
notifikasi per role, dashboard, dan privasi — seluruhnya dengan `Http::fake`.

**Dengan API resmi (manual, tanpa pesan ke pengguna):**

1. Admin → Notifikasi → **Uji Koneksi** (Telegram `getMe` + info webhook,
   WhatsApp baca data nomor Graph API).
2. Admin → Notifikasi → **Kirim Pesan Uji** → pilih `telegram` /
   `whatsapp` (hanya mengirim ke penerima uji milik Anda sendiri).

**Menguji pesan masuk (webhook):**

1. Pastikan URL publik terpasang (bagian 7) dan `queue:work` berjalan.
2. Telegram: kirim `/start` ke bot → balasan sambutan + panduan tautan.
3. Tautan akun: Profil → Integrasi Chatbot → **Buat Kode Verifikasi** →
   kirim kode itu dari WhatsApp/Telegram Anda → status *Terhubung*.
4. Setelah terhubung: kirim *“Pesanan saya sudah sampai mana?”* → balasan
   memakai data akun Anda. Tanpa tautan, balasan hanya menjawab sebagai tamu
   (tanpa data pribadi).
5. Pantau: Admin → Notifikasi → **Riwayat Pengiriman** (kejadian
   `chatbot.reply`) & *Pesan webhook terakhir* pada kartu integrasi.

## 10. Cara mengatasi kegagalan pengiriman

| Gejala | Penyebab | Solusi |
|---|---|---|
| Webhook WhatsApp `403` saat GET | `WHATSAPP_VERIFY_TOKEN` tidak cocok | Samakan nilai di Meta & `.env`, `config:clear` |
| Webhook WhatsApp `403` saat POST | Signature salah / `WHATSAPP_APP_SECRET` kosong-tidak-cocok | Isi app secret; pastikan POST datang apa adanya dari Meta |
| Webhook Telegram `403` | `TELEGRAM_WEBHOOK_SECRET` kosong atau tidak cocok | Isi secret di `.env`, jalankan `chatbot:webhook set` ulang |
| Balasan tidak datang padahal webhook masuk | Worker mati | Jalankan `queue:work` — lihat status log `pending` di Riwayat |
| Log `chatbot.reply` = *Gagal* | API menolak (token salah, nomor salah, template wajib) | Baca kolom keterangan; `WHATSAPP_MESSAGE_MODE=template` bila jendela 24 jam tertutup |
| Pesan masuk diabaikan (`ignored=disabled`) | `TELEGRAM_ENABLED=false` / `WHATSAPP_ENABLED=false` | Ubah ke `true` + `config:clear` |
| Pesan sama diproses dua kali | (sudah dicegah) dedupe `wamid`/`update_id` 24 jam | Jika terjadi lagi, kirim log `update_id`/`wamid` untuk ditelusuri |
| Notifikasi admin tidak terkirim | Saklar saluran/kejadian mati di halaman Notifikasi, atau `NOTIFICATIONS_ENABLED=false` | Cek status konfigurasi di halaman admin |
| Telegram `getMe` gagal | Token kosong/salah | Ulangi langkah 5; token hanya di `.env` |
| Webhook tidak pernah masuk (dev) | Domain bukan HTTPS publik | Pakai ngrok/Cloudflare Tunnel (bagian 7) |

Kegagalan pengiriman **tidak pernah** menjatuhkan webhook (dijawab `200`,
kegagalan dicatat ke `notification_logs`) dan tidak membatalkan alur bisnis.

---

## Tautan akun (verifikasi, bukan tebakan)

- Pengguna login → **Profil → Integrasi Chatbot** → *Buat Kode Verifikasi*
  (6 digit, berlaku 10 menit).
- Kode dikirim pengguna **dari kanalnya sendiri** → webhook memverifikasi →
  `channel_identities.status = verified`.
- Tanpa tautan: chatbot menjawab sebagai **tamu** (tanpa data pribadi) plus
  panduan penghubungan.
- Identifier yang sudah terhubung ke akun lain ditolak; percobaan gagal
  beruntun dikunci 15 menit per identifier.
- Penerima notifikasi per role (kurir/pelanggan/anggota) juga memakai
  identitas terverifikasi ini; nomor telepon lama tetap fallback.

## Konfigurasi `.env` (lengkap)

| Variabel | Fungsi | Contoh/Status |
|---|---|---|
| `WHATSAPP_ENABLED` | Saklar integrasi WhatsApp | `false` (menunggu kredensial) |
| `WHATSAPP_CLOUD_API_VERSION` / `WHATSAPP_API_VERSION` | Versi Graph API | `v21.0` |
| `WHATSAPP_PHONE_NUMBER_ID` | Nomor pengirim resmi Meta | **belum diisi** |
| `WHATSAPP_BUSINESS_ACCOUNT_ID` | Akun bisnis | opsional |
| `WHATSAPP_ACCESS_TOKEN` | Token Graph API | **belum diisi** |
| `WHATSAPP_VERIFY_TOKEN` | Verifikasi webhook GET | sudah diisi (acak) |
| `WHATSAPP_APP_SECRET` | Validasi signature POST | **belum diisi** |
| `WHATSAPP_ADMIN_NUMBER` | Nomor penerima admin | `6289697358716` |
| `WHATSAPP_DEFAULT_COUNTRY_CODE` | Kode negara normalisasi | `62` |
| `TELEGRAM_ENABLED` | Saklar integrasi Telegram | `true` |
| `TELEGRAM_BOT_USERNAME` | Username bot (tanpa `@`) | `AGUNGATK_bot` |
| `TELEGRAM_BOT_TOKEN` | Token BotFather | sudah diisi |
| `TELEGRAM_BOT_CHAT_ID` | Chat id (alias `TELEGRAM_CHAT_ID`) | `1887743171` |
| `TELEGRAM_WEBHOOK_SECRET` | Secret header webhook | sudah diisi (acak) |

## Status koneksi saat ini (jujur)

| Kanal | Status | Bukti |
|---|---|---|
| Telegram — API (kirim) | **Terhubung & teruji** | `getMe` HTTP 200 (17 Nov 2026): username `AGUNGATK_bot`, `is_bot: true`; pengiriman notifikasi tercatat `sent` pada riwayat |
| Telegram — webhook (masuk) | **Belum dipasang** — menunggu URL HTTPS publik | Username terverifikasi API: yang terdaftar `AGUNGATK_bot` (nama tampilan `AGUNGATK.bot`; username Telegram tidak boleh berisi titik). Setelah ada URL publik: `php artisan chatbot:webhook set` |
| WhatsApp — kirim & masuk | **Menunggu kredensial valid** | `getMe` Graph API ulang (17 Nov 2026) → **HTTP 400 code 190** `Invalid OAuth access token — Cannot parse access token` (kunci bukan token Meta); `WHATSAPP_PHONE_NUMBER_ID` / `WHATSAPP_BUSINESS_ACCOUNT_ID` / `WHATSAPP_APP_SECRET` masih kosong; `WHATSAPP_ENABLED=true` (saklar kanal menyala — status dashboard jujur: "Menunggu Kredensial") |

Setelah kredensial valid diterima: daftarkan URL webhook di dasbor Meta
(WA) dan jalankan `php artisan chatbot:webhook set` (Telegram), lalu uji ulang
bagian 9. Jangan menyatakan kanal “aktif” sebelum uji nyata API resmi di atas
berhasil.
