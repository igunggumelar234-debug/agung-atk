# Integrasi Notifikasi - Agung ATK

Dokumen pusat untuk seluruh kanal notifikasi: WhatsApp, Telegram, dan Email
(Gmail/SMTP), termasuk pemetaan kejadian per peran, antrean, dashboard, dan
status pengujian nyata.

> Prinsip utama: **status koneksi hanya dinyatakan berhasil setelah pengujian
> API/SMTP nyata** — variabel `.env` terisi BUKAN bukti koneksi.

## 1. Arsitektur

```
Observer (Order/Payment/Product/Shipment/User)
        │  dispatch('order.new', [...]) dst.
        ▼
NotificationManager ── cek: global on? → event aktif? → saklar saluran?
        │               → kredensial lengkap? → dedupe jendela waktu
        ▼                    (setelah commit transaksi bisnis)
NotificationLog (audit: pending/sent/failed + error aman)
        │
        ├─ SendNotificationJob (antrean database, retry)
        ▼
┌───────────────────┬───────────────────┬────────────────────┐
│ WhatsAppService   │ TelegramService   │ EmailService       │
│ Graph Cloud API   │ Bot API           │ SMTP (Mailpit/Gmail)│
└───────────────────┴───────────────────┴────────────────────┘
```

| Komponen | File | Peran |
|---|---|---|
| Pusat koordinasi | `app/Services/NotificationManager.php` | `dispatch()` per kejadian, `sendTest()`, `channelStatus()`, `defaultRecipient()`, dedupe, penerima per peran |
| Pesan | `app/Services/Notifications/NotificationMessageFactory.php` | Menyusun subjek+badan per kejadian & peran; sanitasi teks error (`sanitizeError`) |
| Saluran | `WhatsAppNotificationService`, `TelegramNotificationService`, `EmailNotificationService` | Pengiriman per kanal + uji koneksi (`getMe`, `webhookInfo`) |
| Pemicu | `app/Observers/*.php` (5 pengamat) | Pesanan, pembayaran, stok, pengiriman, akun |
| Ringkasan harian | `app/Console/Commands/DailyNotificationSummary.php` | `report.daily` (jadwal scheduler) |
| Masalah sistem | Listener `JobFailed` di `app/Providers/AppServiceProvider.php` | Job antrean gagal → kejadian `system.issue` ke admin |
| Antrean | `app/Jobs/SendNotificationJob.php`, `SendChannelReplyJob.php` | Pengiriman asinkron + kebijakan retry |
| Saklar per kejadian/saluran | tabel `notification_settings` | Via halaman admin Notifikasi |
| Audit | tabel `notification_logs` | Riwayat kirim/gagal + filter di dashboard |
| Konfigurasi | `config/notifications.php`, `config/services.php` | Kanal, audiens, kejadian, kredensial |

Tidak ada service duplikat — `app/Services/NotificationService.php` tidak
dibuat karena peran tersebut sudah dipegang `NotificationManager`.

## 2. Kanal & konfigurasi

| Kanal | Variabel utama | Saklar |
|---|---|---|
| WhatsApp | `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_BUSINESS_ACCOUNT_ID`, `WHATSAPP_CLOUD_API_VERSION` (setara `WHATSAPP_API_VERSION`), `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_APP_SECRET`, `WHATSAPP_ADMIN_NUMBER` | `WHATSAPP_ENABLED` |
| Telegram | `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_ADMIN_CHAT_ID`, `TELEGRAM_WEBHOOK_SECRET` | `TELEGRAM_ENABLED` |
| Email | `MAIL_*` (lihat `docs/GMAIL_SETUP.md`), `NOTIFICATION_ADMIN_EMAIL` | tidak ada saklar terpisah |

Saklar global: `NOTIFICATIONS_ENABLED` (semua kanal),
`NOTIFICATIONS_MOCK` (tanpa panggilan API luar), `NOTIFICATIONS_QUEUE`
(kirim via antrean atau langsung).

Saluran dengan kredensial kosong **dilewati tanpa log & tanpa panggilan API**
(diuji: `NotificationDispatchTest::test_disabled_channel_and_event_suppress_notifications`).

## 3. Kejadian & audiens per peran

Sumber: `config/notifications.php` → `events` (label + audiences + jendela dedupe).

| Kejadian | Keterangan | Penerima |
|---|---|---|
| `order.new` | Pesanan baru dari pelanggan | Admin, Anggota |
| `order.created` | Pesanan berhasil dibuat | Pelanggan |
| `payment.instructions` | Instruksi pembayaran tersedia | Pelanggan |
| `payment.proof_uploaded` | Bukti pembayaran diunggah | Admin |
| `payment.verified` | Pembayaran diverifikasi | Admin, Pelanggan |
| `payment.rejected` | Pembayaran ditolak | Admin, Pelanggan |
| `order.paid` | Sudah dibayar (perlu disiapkan) | Anggota |
| `order.processing` | Sedang diproses | Pelanggan, Anggota |
| `order.ready_to_ship` | Siap dikirim | Admin |
| `order.cancelled` | Pesanan dibatalkan | Admin, Pelanggan |
| `stock.low` | Stok hampir habis | Admin, Anggota |
| `stock.out` | Stok habis | Admin |
| `shipment.assigned` | Penugasan pengiriman baru | Kurir (yang ditugaskan), Pelanggan |
| `shipment.status` | Status pengiriman berubah | Pelanggan, Admin, Kurir (yang ditugaskan) |
| `shipment.shipping` | Sedang dikirim | Pelanggan |
| `shipment.delivered` | Diterima pelanggan | Admin, Pelanggan |
| `account.registered` | Pendaftaran akun berhasil | Pelanggan |
| `report.daily` | Ringkasan penjualan harian | Admin |
| `system.issue` | Pekerjaan antrean gagal (job `JobFailed`) | Admin |

Catatan keamanan audiens:

- `courier` **bukan** siaran ke semua kurir — `NotificationManager::recipients()`
  hanya menargetkan kurir pada shipment terkait (`$data['courier']` /
  `$data['shipment']->courier`).
- `customer` selalu diikat ke pemilik pesanan/pembayaran terkait — tidak pernah
  menyeberang ke pelanggan lain.
- `seller` = seluruh staf aktif role anggota/penjual (pemberitahuan operasional toko).

## 4. Alur & jaminan pengiriman

1. **Setelah commit** — dispatch ditunda lewat `DB::afterCommit`; transaksi yang
   rollback tidak mengirim apa pun.
2. **Dedupe** — `recentlyNotified(event, channel, recipient, reference, window)`
   mencegah pesan ganda dalam jendela (default 360 detik; `report.daily` 1440,
   `proof_uploaded` 60, `system.issue` 60).
3. **Antrean** — `SendNotificationJob` di antrean `database` dengan retry;
   worker: `php artisan queue:work --tries=3 --sleep=3`.
4. **Status jujur** — setiap percakapan tercatat `pending` → `sent`/`failed`
   + pesan error aman (tanpa rahasia) di `notification_logs`.
5. **Gagal tidak merusak bisnis** — kegagalan penyusunan/pengiriman notifikasi
   hanya dicatat (log warning), alur checkout tetap jalan
   (`test_failed_notification_does_not_break_checkout`).

## 5. Dashboard admin (Notifikasi)

Halaman **Admin → Notifikasi** menampilkan:

1. Status konfigurasi tiap saluran + variabel `.env` yang belum diisi.
2. Integrasi chatbot: status WhatsApp/Telegram, username, chat ID, URL webhook,
   pesan webhook terakhir, hasil **Uji Koneksi** (nyata), waktu uji terakhir.
3. **Status Sistem & Provider AI**: status antrean + `QUEUE_CONNECTION`,
   jumlah job gagal, provider AI (endpoint/model/kunci) + hasil percobaan
   terakhir (dicatat `AIService::recordApiStatus`).
4. Saklar saluran & kejadian.
5. Hubungkan akun Telegram (kode verifikasi).
6. Kirim Pesan Uji (per saluran, terbatas 5 uji/menit).
7. Riwayat Pengiriman + filter tanggal/saluran/status (termasuk filter
   "Gagal" untuk melihat riwayat pesan gagal).

Token/secret **tidak pernah** ditampilkan; penerima ditampilkan tersamar.

## 6. Status pengujian nyata (per 17 Nov 2026)

| Kanal | Pengujian | Hasil |
|---|---|---|
| Telegram API | `getMe` langsung ke `api.telegram.org` | **HTTP 200 OK** — bot terdaftar, username `AGUNGATK_bot` |
| Telegram webhook | Belum dipasang | Butuh URL HTTPS publik + konfirmasi pemilik (lihat `docs/TELEGRAM_SETUP.md`) |
| WhatsApp Graph API | `GET /{version}/me` dengan token di `.env` | **HTTP 400 code 190** — token bukan token Meta yang valid; `WHATSAPP_PHONE_NUMBER_ID`/`BUSINESS_ACCOUNT_ID`/`APP_SECRET` masih kosong → status dashboard "Menunggu Kredensial" |
| Email SMTP | Kirim nyata ke Mailpit lokal (`127.0.0.1:1025`) | **TERKIRIM** — jalur SMTP Laravel berfungsi; ganti ke Gmail setelah App Password diisi |
| AI provider | Chat nyata | **401** — kunci ditolak provider → chatbot memakai mode fallback lokal (jujur, terdokumentasi) |

Automated test: **620/620 lulus** (2.940 asersi) — seluruh panggilan API luar
di-mock, tidak ada pesan nyata saat suite berjalan.

## 7. Peta pengujian (dokumen `L` pada spesifikasi)

| Area | Tes |
|---|---|
| Konfigurasi & kirim WhatsApp | `WhatsAppCloudApiTest` |
| Konfigurasi & kirim Telegram | `ChatbotChannelWebhookTest` |
| SMTP/email | `NotificationSettingsTest::test_email_channel_requires_smtp_password_to_report_configured`, pengiriman email di `NotificationDispatchTest` |
| Webhook WA/TG, anti-duplikat | `ChatbotChannelWebhookTest` (verifikasi, signature, secret, duplikat) |
| Jawaban chatbot (umum/data/stok/transaksi/status/role) | `ChatbotSimpleQuestionTest`, `ChatbotDataIntegrationTest`, `ChatbotCommandsTest`, `ChatbotIntelligenceTest`, `ChatbotSystemUnderstandingTest` |
| Perubahan data → jawaban segar | `ChatbotDataIntegrationTest::test_product_count_comes_from_live_database`, `test_low_stock_says_none_when_everything_is_healthy` |
| Perubahan struktur & cache pengetahuan | `AiDatabaseContextTest::test_schema_knowledge_discovers_domain_tables_and_excludes_framework`, `test_schema_cache_is_forgotten_when_migrations_end` |
| Privasi per peran & penolakan kredensial | `ChatbotDataIntegrationTest` (bagian akses & injeksi), `RoleAccessTest` |
| Kegagalan provider AI & notifikasi | `ChatbotDataIntegrationTest::test_ai_provider_failure_falls_back_to_local_data_answer`, `NotificationDispatchTest::test_channel_failure_does_not_affect_other_channels` |
| Pesan duplikat | `test_whatsapp_duplicate_message_is_processed_once`, `test_telegram_duplicate_update_is_ignored` |
| Masalah sistem (`system.issue`) + sanitasi | `NotificationDispatchTest::test_failed_queue_job_triggers_sanitized_system_issue_alert` |
| Batas ukuran pesan masuk | `ChatbotChannelWebhookTest::test_oversized_incoming_message_gets_polite_reply_without_ai` |
| Kartu status sistem/AI di dashboard | `NotificationSettingsTest::test_dashboard_shows_system_queue_and_ai_provider_status` |

## 8. Langkah manual pemilik

1. **WhatsApp**: buat aplikasi di Meta for Developers → ambil Phone Number ID,
   Access Token sistem-user, App Secret (lihat `docs/WHATSAPP_SETUP.md`) →
   isi `.env` → uji "Uji Koneksi" di dashboard → daftarkan webhook.
2. **Telegram**: konfirmasi penggunaan username `AGUNGATK_bot` → siapkan URL
   HTTPS publik (domain/tunnel) → `php artisan chatbot:webhook set`
   (lihat `docs/TELEGRAM_SETUP.md`).
3. **Gmail**: buat App Password Google → ikuti `docs/GMAIL_SETUP.md`.
4. Jalankan worker antrean dan scheduler bila belum (bagian 4).

## 9. Pemecahan masalah

| Gejala | Penyebab umum | Tindakan |
|---|---|---|
| Status "Belum lengkap" | Variabel `.env` kosong | Isi sesuai daftar yang ditampilkan dashboard |
| Telegram `getMe` gagal 401 | Token salah/kedaluwarsa | Ambil ulang dari BotFather |
| WhatsApp code 190 | Access token bukan token Meta | Ambil token System User Meta (format `EAAG...`) |
| Pesan `pending` terus | Worker mati | `php artisan queue:work --tries=3 --sleep=3` |
| Email `failed` (Gmail) | App Password belum diisi / 2FA | Ikuti `docs/GMAIL_SETUP.md` |
| Notifikasi dobel | — | Jendela dedupe per penerima; periksa `notification_logs` |
| Job gagal membanjiri admin | Kegagalan beruntun | Dedupe `system.issue` 60 detik; perbaiki penyebab via `php artisan queue:failed` |
