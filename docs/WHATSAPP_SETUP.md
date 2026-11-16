# Setup Notifikasi WhatsApp — Agung ATK

Panduan menghubungkan notifikasi otomatis Agung ATK ke **WhatsApp Business
Platform Cloud API resmi Meta**. Seluruh kredensial dibaca dari `.env` —
**jangan pernah** menaruh token di source code, frontend, database, atau log.

> Integrasi **chatbot dua arah** (webhook masuk, tautan akun, balasan AI) ada
> di [`CHATBOT_WHATSAPP_TELEGRAM_SETUP.md`](CHATBOT_WHATSAPP_TELEGRAM_SETUP.md).

Integrasi memakai arsitektur notifikasi yang sudah ada:

| Komponen | Peran |
|---|---|
| `App\Services\Notifications\WhatsAppNotificationService` | Kirim pesan via Graph API (`{version}/{phone_number_id}/messages`), timeout 15 dtk, retry 2×, normalisasi nomor |
| `App\Services\Notifications\NotificationManager` | Menyusun penerima per audiens (admin/penjual/pelanggan/kurir), anti-duplikat (jendela 360 menit), `DB::afterCommit` |
| `App\Jobs\SendNotificationJob` | Pengiriman lewat antrean; `$tries = 3`, `backoff [10, 30]`; idempoten (log yang sudah `sent`/`failed` tidak dikirim ulang) |
| `App\Observers\{Order,Payment,Shipment}Observer` | Pemicu kejadian pada transisi status transaksi yang tersimpan |
| Halaman admin **Notifikasi** (`/admin/notifications`) | Status konfigurasi, saklar saluran & kejadian, preferensi penerima, riwayat (penerima tersamarkan), tombol uji kirim |

Kejadian yang memicu notifikasi (lihat `config/notifications.php` → `events`):
`order.new`, `order.created`, `payment.instructions`, `payment.proof_uploaded`,
`payment.verified`, `payment.rejected`, `order.paid`, `order.processing`,
`order.ready_to_ship`, `order.cancelled`, `shipment.assigned`, `shipment.status`,
`shipment.shipping`, `shipment.delivered`, `stock.low`, `stock.out`,
`account.registered`, `report.daily`.

Penerima WhatsApp (dari `NotificationManager::recipients`):

- **Admin** → `WHATSAPP_ADMIN_PHONE` (operasional toko).
- **Penjual** → nomor pada akun penjual (role anggota) yang terdaftar.
- **Pelanggan** → nomor pada akunnya sendiri / alamat pengirimannya (transaksi miliknya saja).
- **Kurir** → nomor pada akun kurir yang menerima penugasan pengiriman.

Pesan yang dikirim ringkas dan tanpa data sensitif (tidak ada password/token;
rincian transaksi dibatasi pada nomor pesanan & status).

---

## 1. Membuat aplikasi di Meta for Developers

1. Buka <https://developers.facebook.com> → **My Apps** → **Create App**.
2. Pilih tipe **Business** (disarankan; untuk uji cepat bisa memakai **Other →
   Untagged** lalu menautkan WhatsApp).
3. Pada dasbor aplikasi, tambahkan produk **WhatsApp** → **Set up**.
4. Meta memberi **test number** + **temporary access token** yang langsung bisa
   dipakai uji (berlaku terbatas — lihat bagian 8 untuk produksi).

## 2. Mendapatkan Phone Number ID dan Access Token

- **Phone Number ID**: menu **WhatsApp → API Setup** — deret *From* menampilkan
  nomor uji beserta *Phone number ID* (contoh format: `100123456789012`).
  Untuk nomor bisnis sendiri: **WhatsApp Manager → Settings → Phone numbers**
  (atau **Business Settings → WhatsApp accounts**).
- **Access Token**:
  - *Uji cepat*: token sementara di **API Setup** (kedaluwarsa ±24 jam).
  - *Produksi*: buat **System User** di **Business Settings → System Users**,
    berikan peran *Admin* pada aset WhatsApp Business Account, lalu **Generate
    new token** dengan izin `whatsapp_business_messaging` dan
    `whatsapp_business_management`. Token sistem user tidak kedaluwarsa —
    simpan sekali, jangan bagikan.
- Opsional: **WhatsApp Business Account ID** (menu **WhatsApp → Getting
  Started**) disimpan ke `WHATSAPP_BUSINESS_ACCOUNT_ID` untuk keperluan
  pengelolaan template.

## 3. Mengisi environment

Salin variabel berikut ke `.env` (templat lengkap ada di `.env.example`):

```env
WHATSAPP_ACCESS_TOKEN=           # token dari System User / API Setup
WHATSAPP_PHONE_NUMBER_ID=        # Phone Number ID pengirim
WHATSAPP_BUSINESS_ACCOUNT_ID=    # opsional, untuk pengelolaan template
WHATSAPP_CLOUD_API_VERSION=v21.0 # versi Graph API (nama resmi Meta; WHATSAPP_API_VERSION tetap didukung)
WHATSAPP_DEFAULT_COUNTRY_CODE=62 # kode negara untuk nomor lokal (08xx -> 628xx)
WHATSAPP_MESSAGE_MODE=text       # 'text' | 'template' (lihat bagian 7)
WHATSAPP_TEMPLATE_NAME=          # wajib bila mode 'template'
WHATSAPP_TEMPLATE_LANGUAGE=id    # bahasa template
WHATSAPP_ENABLED=true            # saklar integrasi (dashboard: "Menunggu Kredensial" bila kredensial kosong)

WHATSAPP_VERIFY_TOKEN=           # token verifikasi GET webhook (buat sendiri, rahasia)
WHATSAPP_APP_SECRET=             # App Secret Meta untuk validasi X-Hub-Signature-256 (disarankan)
WHATSAPP_ADMIN_PHONE=628xxxxxxxxxx   # nomor admin (format lokal 08xx juga diterima)
```

Catatan konvensi & saklar pengaman (setara `WHATSAPP_ENABLED` pada spesifikasi
umum — proyek ini memakai tiga lapis pengaman yang lebih granular):

| Saklar | Efek |
|---|---|
| `NOTIFICATIONS_ENABLED=false` | Mematikan SELURUH notifikasi (Telegram, WhatsApp, Email) |
| `NOTIFICATIONS_MOCK=true` | Semua notifikasi ditandai terkirim TANPA memanggil API luar (mode demo/pengembangan) |
| `WHATSAPP_ENABLED=false` | Mematikan integrasi WhatsApp di level `.env` (dashboard menampilkan "Nonaktif") |
| Saklar saluran **whatsapp** di halaman admin Notifikasi | Mematikan hanya WhatsApp, per saluran |
| Saklar per-kejadian di halaman admin | Mematikan mis. hanya `stock.low` |

Kredensial kosong pun aman: saluran dilewati otomatis tanpa panggilan API apa
pun (ada diuji pada `NotificationDispatchTest`).

## 4. Menjalankan queue worker

Pengiriman berjalan lewat antrean `database` (`QUEUE_CONNECTION=database`,
`NOTIFICATIONS_QUEUE=true`) sehingga checkout tidak menunggu API Meta.

- **Sekaligus dengan server (disarankan untuk pengembangan):**

  ```bash
  composer run dev        # menjalankan server + queue worker bersamaan
  ```

- **Manual (terminal terpisah):**

  ```bash
  php artisan queue:work --tries=3 --sleep=3
  ```

- **Produksi (Windows Server):** jalankan worker via Task Scheduler, NSSM, atau
  layanan Windows lainnya dengan perintah yang sama, atau supervisor pada Linux.
  Tanpa worker, pesan mengendap di tabel `jobs` dan TIDAK terkirim.

- **Ringkasan harian** (`report.daily`) butuh scheduler: Task Scheduler
  Windows memanggil `php artisan schedule:run` setiap menit.

## 5. Menguji pengiriman pesan

1. Isi `WHATSAPP_ACCESS_TOKEN` & `WHATSAPP_PHONE_NUMBER_ID` di `.env`.
2. Buka **Notifikasi** (menu admin) → bagian **Kirim Pesan Uji** → pilih
   saluran **whatsapp** → kirim. Hasil sinkron: *terkirim* / *gagal* +
   pesan error yang aman.
   (Tombol ini hanya untuk admin; dibatasi 5 uji kirim/menit.)
3. Periksa **Riwayat Pengiriman** di halaman yang sama — status `sent`/`failed`,
   penerima ditampilkan tersamar (`6289***8716`).
4. Uji alur nyata: buat pesanan baru dari akun pelanggan → notifikasi
   `order.new` muncul di riwayat dan masuk ke nomor admin.
5. Otomatisasi: `php artisan test tests/Feature/WhatsAppCloudApiTest.php`
   (semua panggilan API di-mock — tidak pernah mengirim pesan nyata).

## 6. Menangani error API

| Gejala | Penyebab umum | Tindakan |
|---|---|---|
| `401 Unauthorized` / OAuthException code 190 | Access token salah atau kedaluwarsa | Regenerate token (bagian 2), perbarui `.env` |
| `100` (invalid parameter) | `phone_number_id` salah atau `to` bukan nomor WhatsApp valid | Periksa Phone Number ID; nomor dinormalisasi otomatis (08xx → 628xx) |
| `131026` / template errors | Mode `template` memakai template yang belum disetujui | Lihat bagian 7 |
| `80007`, `130429` / rate limit | Terlalu banyak pesan dalam waktu singkat | Kurangi volume; job otomatis mencoba ulang dengan backoff 10s/30s |
| `cURL error 28: timed out` | Jaringan lambat/terputus | Cek konektivitas server; job mencoba ulang otomatis (maks. 3 percobaan) |

Setiap kegagalan tercatat sebagai log `failed` pada **Riwayat Pengiriman**
dengan pesan error yang aman — **tanpa access token** dan tanpa isi sensitif
(diuji: `WhatsAppCloudApiTest::test_api_error_marks_log_failed_without_leaking_token`).
Kegagalan notifikasi TIDAK PERNAH membatalkan pesanan/pembayaran yang sudah
tersimpan, dan status transaksi tidak pernah berubah karena hasil kirim pesan.
Pengulangan aman: redelivery job yang sama tidak menghasilkan pesan ganda
(diuji: `test_redelivered_job_does_not_send_twice`).

## 7. Template pesan & persetujuan penerima

- **`WHATSAPP_MESSAGE_MODE=text`** — pesan teks bebas. Hanya boleh dikirim ke
  pengguna yang baru saja membalas bot (jendela percakapan 24 jam) atau untuk
  tujuan uji. Cocok untuk pengembangan/internal.
- **`WHATSAPP_MESSAGE_MODE=template`** — wajib untuk memulai percakapan baru
  (mis. notifikasi "pesanan baru" ke admin). Nama template diisi ke
  `WHATSAPP_TEMPLATE_NAME`, bahasa ke `WHATSAPP_TEMPLATE_LANGUAGE`.
  - Buat & ajukan template di **WhatsApp Manager → Message templates**.
  - Isi pesan internal (baris `subject`/`body`) dikirim sebagai parameter
    `{{1}}`, `{{2}}`, … maksimal 10 parameter.
  - Template butuh **persetujuan Meta** (biasanya <24 jam); kategori
    *Utility* paling sesuai untuk notifikasi transaksi.
- **Persetujuan penerima**: kirim hanya ke nomor yang berhak menerima
  (lihat daftar penerima di atas). Hormati preferensi per-kejadian pada
  halaman admin; jangan mengirim ke pihak ketiga yang tidak terkait.

## 8. Mengaktifkan integrasi pada server produksi

1. Salin `.env.example` → `.env` produksi, isi seluruh variabel bagian 3
   dengan kredensial System User (token non-expiring) & nomor bisnis sendiri.
2. Pastikan `NOTIFICATIONS_ENABLED=true` dan `NOTIFICATIONS_MOCK=false`.
3. Pastikan `queue:work` berjalan terus-menerus (bagian 4) dan scheduler
   aktif untuk ringkasan harian.
4. Di halaman **Notifikasi**, aktifkan saluran **whatsapp** serta kejadian
   yang diinginkan, dan isi/pastikan `WHATSAPP_ADMIN_PHONE` benar.
5. Uji kirim dari halaman Notifikasi, lalu uji satu alur nyata (pesanan baru).
6. Pantau **Riwayat Pengiriman** pada hari pertama; perbaiki bila ada `failed`.
7. Keamanan: rotasi token bila token pernah terekspos; jangan taruh token di
   repository (`.env` sudah di-`.gitignore`); akses halaman notifikasi
   terbatas role admin (dikeuji).

---

## Status saat ini (jujur)

Pengujian API Graph nyata (17 Nov 2026): `GET /v21.0/me` dengan token di `.env`
→ **HTTP 400 code 190** `Invalid OAuth access token — Cannot parse access token`
(token bukan token Meta), dan `WHATSAPP_PHONE_NUMBER_ID` /
`WHATSAPP_BUSINESS_ACCOUNT_ID` / `WHATSAPP_APP_SECRET` masih kosong → status
dashboard: **Menunggu Kredensial**. `WHATSAPP_ENABLED=true` sudah menyala agar
saklar kanal siap begitu kredensial valid diisi.

*Terakhir diperbarui: 17 Nov 2026 — integrasi diuji otomatis (Http::fake,
`WhatsAppCloudApiTest` + NotificationDispatch/Settings, seluruh suite 620/620
lulus); pengiriman nyata menunggu pengisian kredensial Meta yang valid pada
`.env` (lihat `docs/NOTIFICATION_INTEGRATION.md` bagian 6).*
