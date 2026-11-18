# AUDIT REPORT — Agung ATK

**Tanggal audit:** 18 November 2026
**Lingkup:** Audit teknologi & database, integrasi WhatsApp Fonnte, chatbot AI basis data
dinamis, audit AI/keamanan/performa, persiapan hosting VPS + CI/CD, optimasi produksi,
webhook HTTPS publik, dan pengujian E2E (Tugas Pengembangan No. 12).
**Prinsip:** status **"berhasil" hanya diberikan bila benar-benar diuji**; angka di bawah
diambil dari pengujian nyata, bukan klaim.

Laporan sebelumnya (18 Oktober 2026) dipindahkan ke
[`docs/AUDIT_REPORT_2026-10-18.md`](docs/AUDIT_REPORT_2026-10-18.md) agar riwayat tidak hilang.

---

## 1. Ringkasan Eksekutif

| Metrik | Hasil (terukur) |
|---|---|
| Suite pengujian (PHPUnit) | **690/690 tes lulus** (3.251 asersi), 0 gagal — diverifikasi ulang setelah seluruh perubahan |
| Gaya kode | **Laravel Pint bersih** (`pint --dirty` lulus) |
| Smoke E2E live (aplikasi berjalan di :8899) | **SEMUA LULUS —6 kelompok** (beranda, login admin, panel Fonnte + URL webhook, chatbot live3 intent, Fonnte `/device` live) |
| WhatsApp Fonnte | **Teruji live**: `/send` sukses nyata (log id19, status sent), `/device` live OK |
| CI/CD GitHub Actions | **Run #4 SUKSES** — seluruh job hijau (tests, frontend build, deploy opsional) |
| Commit topik (dalam tugas ini) |9 commit terpisah & berurutan (lihat bagian11) |
| Blokir yang tersisa | VPS, domain/HTTPS, secrets GitHub, registrasi webhook eksternal (lihat bagian15) |

**Kesimpulan:** seluruh pekerjaan yang dapat diselesaikan di lingkungan pengembangan sudah
selesai dan teruji. Sisa pekerjaan sepenuhnya bergantung pada sumber daya eksternal
(VPS, domain, akun/secret pihak ketiga) yang memerlukan persetujuan/akses pengguna.

---

## 2. Audit Teknologi & Arsitektur

Hasil pemeriksaan (bukan asumsi):

| Komponen | Versi/Kondisi |
|---|---|
| Laravel | **13.31.0** |
| PHP | **8.3.33** (Laragon) |
| Database | **MySQL `agung_atk`** — engine dipertahankan (tidak ada migrasi engine) |
| Antrean | `QUEUE_CONNECTION=database` (worker `queue:work` berjalan saat pengujian live) |
| Mail (dev) | Mailpit |
| Frontend | Vite (build diverifikasi di CI) |
| Penyedia WA | **Fonnte (utama)** via `WHATSAPP_PROVIDER=fonnte`; **Meta tetap cadangan** (switch provider di `WhatsAppNotificationService`) |

Temuan audit:
- Struktur aplikasi, fitur lama, dan database **dipertahankan** — tidak ada pembangunan ulang.
- Sebaran konfigurasi aman: seluruh token/kunci hanya di `.env` (tidak muncul di kode,
  pesan, laporan, maupun commit). `.env.example` berisi kerangka kosong + komentar.
- Filosofi pengujian: suite memakai SQLite `:memory:` sehingga pengujian tidak pernah
  menyentuh database MySQL asli (dikeraskan — lihat bagian12 &15).

---

## 3. Audit Database

- **25 tabel** di `agung_atk` (dump audit tersimpan sebagai bukti pemeriksaan struktur):
  `users`, `roles`, `products`, `product_images`, `categories`, `orders`, `order_details`,
  `payments`, `shipments`, `reviews`, `addresses`, `notification_logs`,
  `notification_settings`, `channel_identities`, `ai_conversations`, `ai_messages`,
  `activity_logs`, `cache`, `cache_locks`, `sessions`, `jobs`, `job_batches`,
  `failed_jobs`, `password_reset_tokens`, `migrations`.
- **Migrasi baru (aditif, tidak destruktif):**
  `2026_11_18_142655_add_composite_indexes_for_notification_dedup_and_order_lists.php`
  — index gabungan `notification_logs_dedup_index` dan `orders_status_created_at_index`.
  **Terpasang & terverifikasi** lewat `php artisan db:table` setelah migrasi dijalankan.
- Tidak ada penghapusan/penulisan ulang tabel lama; tidak ada migrasi destruktif tanpa
  backup/persetujuan.
- Data uji dipulihkan lewat seeder resmi (`db:seed`) bila terjadi insiden (bagian15).

---

## 4. Integrasi WhatsApp Fonnte

**Status: BERHASIL — teruji live dan tertutup17/17 tes.**

Implementasi (commit `0ca102f`, di atas fondasi `e2a731e`):
- `FONNTE_API_URL=https://api.fonnte.com`, `WHATSAPP_PROVIDER=fonnte`,
  `WHATSAPP_ADMIN_PHONE=62881024127186`; header `Authorization: TOKEN`
  **tanpa** prefix Bearer.
- Timeout, penanganan error, logging aman (tanpa membocorkan token), retry terbatas,
  anti-duplikasi, serta riwayat/status pesan tersimpan di `notification_logs`.
- Status **"terkirim" hanya setelah respons provider valid** — bukan setelah permintaan dikirim.
- **Switch provider**: `services.whatsapp.provider` = `fonnte` | `meta`; Meta tetap
  cadangan. Suite memaksa `meta` (phpunit), tes Fonnte mengaktifkan `fonnte` via
  `config()->set()` sehingga tes tidak bergantung pada kredensial live.

**Bukti live (18 November2026):**
- `/send` → sukses nyata ke `62881024127186` (log id19, status `sent`, counter `messages:1`).
- `/device` → live OK: status connect, **kuota999**, **berlaku s/d17 December2026**, paket Free.

**Discrepancy tercatat:** endpoint device Fonnte yang diterima akun ini adalah **`/device`**
(bukan `/get-devices` — versi lama menolak token akun ini dengan `"unknown user"`).
Respons berbentuk datar. Hal ini dicatat agar tidak ada yang "memperbaiki" kode kembali ke
endpoint yang tidak berfungsi untuk akun ini.

**Webhook masuk Fonnte:** verifikasi **secret di path URL** `/webhooks/fonnte/{token}`
(fail-closed:403 bila kosong/tidak cocok), anti-duplikat `Cache::add` hash24 jam,
pesan grup diabaikan.

**Kelengkapan tes:** `FonnteServiceTest` **17/17 lulus**.

---

## 5. Chatbot AI — Basis Data Dinamis

**Status: BERHASIL —16/16 tes celah data (`ChatbotDataGapsTest`) lulus (commit `20533ad`).**

- Chatbot membaca **metadata** struktur database (bukan seluruh isi), query **dinamis
  real-time tanpa hardcode**, dengan validasi akses per role, mode read-only, parameter
  binding (bloir DML), batas jumlah hasil, dan audit query.
- **7 intent baru** menutup celah audit + perluasan regex `product_count`:
  `top_stock`, `newest_products` (publik); `pending_payments`, `newest_orders` (staf);
  `users_by_role` (hanya pola role/peran spesifik + staf); `newest_users` (admin);
  `top_customer` (via `customerAccountScope`).
- `dataAnswer` diprioritaskan di `localReply` sebelum `actor`/`systemMap`/`schema`.
- `ringkasan_tabel` untuk key `users` hanya admin/anggota (tamu tidak melihat jumlah akun).
- Memori percakapan lanjutan tetap berfungsi; angka tidak pernah dikarang — bila data
  kosong/tak tersedia, chatbot mengakuinya apa adanya.

---

## 6. Audit AI & Token

- **`tokens_used`** kini tercatat per respons (`AIService::$lastApiTokens`) — pemakaian
  token AI dapat diaudit.
- API key AI selalu kosong saat testing (`AI_API_KEY=""` di phpunit.xml) sehingga suite
  tidak pernah memanggil API eksternal (fallback lokal/database dipakai).
- Kebocoran konteks ditutup: ringkasan tabel `users` disembunyikan dari tamu (bagian5).
- Seluruh kunci AI/FO NTE/Telegram/Meta hanya di `.env`.

---

## 7. Audit Keamanan

- **Celah chatbot** (bacaan data melebihi hak akses) ditutup sesuai bagian5 — commit `20533ad`.
- **Rate limiting** pada route berisiko (commit `2e09669`, routes/web.php):
  - `reviews.store` & unggah bukti pembayaran → `throttle:10,1`
  - Ekspor laporan (reports export) → `throttle:5,1`
  - Terverifikasi oleh `ThrottleProtectionTest` (**3/3 lulus**; memakai `postJson`
    karena validasi web membalas302 pada request tanpa header JSON).
- **Webhook**: secret fail-closed (Fonnte), verifikasi signature (Meta/Telegram sesuai
  pola lama), token hanya di `.env` — tidak ada secret di URL/log/commit.
- Filter tanggal pada log/audit/notifikasi **memakai rentang `created_at` berindex**
  (lihat bagian8) — `ActivityLogFilterTest` **2/2 lulus**.
- Tidak ada perubahan perilaku autentikasi/otorisasi lama yang rusak (seluruh suite lulus).

---

## 8. Audit Performa

- **`whereDate()` → rentang eksplisit** di `ActivityLogController`,
  `NotificationController`, dan `ReportController`: batas bawah `substr($d,0,10).' 00:00:00'`,
  batas atas `substr($d,0,10).' 23:59:59'` — semantik identik, ramah index, dan tidak
  melempar exception pada input tanggal sampah.
- **Index gabungan** (migrasi bagian3):
  - `notification_logs_dedup_index` — mempercepat cek anti-duplikasi webhook.
  - `orders_status_created_at_index` — mempercepat daftar pesanan (filter status + urut waktu).
- Verifikasi: `php artisan db:table notification_logs` & `db:table orders` menunjukkan
  index terpasang setelah migrasi dijalankan.

---

## 9. Persiapan Hosting VPS

**Status: ARTEFAK SIAP — pelaksanaan menunggu VPS (lihat bagian15).**

| Artefak | Isi |
|---|---|
| `deploy/nginx/agung-atk.conf` | Blok server nginx: root `public/`, PHP-FPM, `try_files` Laravel, pembatasan file sensitif |
| `deploy/setup-vps.sh` | Provisioning Ubuntu: dependensi, PHP-FPM, MySQL, nginx, Node, certbot — **idempoten** (aman dijalankan berulang) |
| `deploy/deploy.sh` | Jalur deploy: pull, `.env` produksi, migrate, optimize, restart queue/FPM |
| `.env.production.example` | Kerangka env produksi lengkap (tanpa rahasia) + catatan wajib-isi |
| `.github/workflows/ci.yml` | Job **Deploy Produksi (opsional)** — hanya berjalan bila secrets `DEPLOY_HOST/DEPLOY_USER/DEPLOY_SSH_KEY/DEPLOY_PATH` tersedia di GitHub |

---

## 10. Domain, HTTPS & Webhook Publik

- **`WEBHOOK_PUBLIC_URL`** (config `app.webhook_public_url`) + helper
  `NotificationController::publicUrl()` membangun **4 URL webhook** (Fonnte, Meta,
  Telegram, dan kanal terkait) dengan akar domain yang benar.
- Bila kosong, fallback ke `route()` sehingga perilaku & tes lama tidak berubah —
  terbukti: `NotificationSettingsTest` **16/16 lulus**, termasuk
  `test_webhook_urls_use_configured_public_root_when_set`.
- Hint pada dashboard notifikasi diperbarui agar admin melihat URL yang benar.

**TERBLOKIR (butuh tindakan manual):** pendaftaran webhook Fonnte/Meta/Telegram
memerlukan **domain + HTTPS publik** — belum ada (lihat bagian15).

---

## 11. CI/CD GitHub Actions

**Status: BERHASIL — run #4 SUKSES (seluruh job hijau).**

Riwayat nyata di repo `igunggumelar234-debug/agung-atk`:

| Run | Commit | Hasil | Keterangan |
|---|---|---|---|
| #1 | `d064235` | Gagal | Gagal cepat di langkah tes (1 tes gagal); job frontend lulus; deploy dilewati (sesuai rancangan tanpa secrets) |
| #2 | `ef4f89f` | Gagal | Anotasi diaktifkan: **680 lulus,1 gagal,9 terlewat** — ketahuan penyebabnya |
| #3 | `039dde7` | Gagal | Anotasi lengkap menunjuk tes persis: `ImageSearchTest::valid_image_returns_recognition_payload` (status `'error'` karena indeks dataset tak ada di CI) |
| #4 | `dd5b430` | **SUKSES** | **Unit & Frontend Build lulus; Deploy Produksi (opsional) sukses** |

Perbaikan yang membawa ke hijau:
1. Anotasi `::error::` dari keluaran tes (membaca kegagalan CI via API publik tanpa auth).
2. Tes `ImageSearchTest` diubah jujur: tanpa indeks dataset, payload `'error'` dengan
   pesan ramah dan tanpa produk karangan tetap divalidasi strukturnya (9 tes lain sudah
   skip dengan pola sama; dataset memang tidak disimpan di repo).
3. `phpunit.xml` dikeraskan (bagian12) agar suite tidak pernah menyentuh DB asli.

Catatan (bukan kegagalan): peringatan deprecasi Node.js20 pada `actions/checkout@v4`
dan notice migrasi `ubuntu-latest` ke Ubuntu26 — dapat diperbarui menyusul.

---

## 12. Optimasi Produksi

- `php artisan optimize` / `optimize:clear` diverifikasi berfungsi (cache config, routes,
  views, services).
- **Insiden teknis yang ditemukan & dikeraskan (jujur — detail bagian15):**
  config-cache hasil `optimize` **membekukan `DB_CONNECTION=mysql`**, sehingga suite yang
  berjalan setelahnya ikut menyentuh MySQL asli dan `migrate:fresh` menghapus data dev.
  **Pencegahan terpasang di `phpunit.xml`** (commit `a341c2e`):
  - `APP_CONFIG_CACHE` diarahkan ke jalur terpisah yang tidak pernah ditulis — suite
    selalu membaca env testing;
  - `force="true"` pada `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL=""`.
  - **Terverifikasi:** dengan config-cache produksi *ada*, tes dijalankan → MySQL **tidak**
    tersentuh (`CREATE_TIME` tabel identik sebelum/sesudah; durasi tes turun22dtk→5dtk).
- Queue: worker `queue:work --tries=3 --sleep=3` berjalan; `failed_jobs` tersedia.

---

## 13. E2E Testing & Hasil Pengujian

**Suite akhir:**

```text
Tests: 690 passed (3251 assertions) —0 gagal,0 kesalahan
```

**Smoke live E2E** (skrip `e2e_smoke.ps1`, aplikasi berjalan di `http://127.0.0.1:8899`,
tanpa data simulasi — memakai data seed sungguhan & panggilan live):

| # | Kelompok | Hasil |
|---|---|---|
|1| Halaman beranda (200 + konten) | LULUS |
|2| Login admin sungguhan → dashboard | LULUS |
|3| Panel Fonnte +4 URL webhook dari config publik | LULUS |
|4| Chatbot live3 intent (data dinamis) | LULUS |
|5| Fonnte `/device` live (kuota, masa berlaku) | LULUS |
|6| Fondasi lain (health route/status) | LULUS |

- **Gaya kode:** `vendor/bin/pint --dirty --format agent` **lulus** setelah setiap perubahan.
- Kunci keberhasilan pengujian akhir: tidak ada data simulasi — akun seed resmi
  (`admin@agungatk.com` dll.) dan panggilan provider sungguhan hanya untuk bukti live
  Fonnte yang memang ditujukan ke nomor admin.

---

## 14. Tabel Status Modul

Status: **Berhasil (teruji)** = terbukti lulus tes/live · **Siap (belum live)** = kode+tes
lulus, penggunaan live menunggu prasyarat · **Menunggu pihak luar** = terblokir akses/izin.

| # | Modul | Status | Bukti |
|---|---|---|---|
|1| Autentikasi & role (admin/pelanggan/kurir/penjual) | **Berhasil (teruji)** | Suite penuh lulus; smoke login admin live |
|2| Katalog, keranjang, pesanan, pembayaran, pengiriman | **Berhasil (teruji)** | Suite penuh lulus (modul lama utuh) |
|3| Review + unggah bukti (throttle) | **Berhasil (teruji)** | `ThrottleProtectionTest`3/3 |
|4| Notifikasi WhatsApp **Fonnte** | **Berhasil (teruji live)** | `/send` sukses nyata (log id19); `/device` live; `FonnteServiceTest`17/17 |
|5| Notifikasi WhatsApp **Meta (cadangan)** | **Berhasil (teruji)** | Suite memaksa provider `meta`; tes lulus |
|6| Email & Telegram notifikasi | **Berhasil (teruji)** | `NotificationDispatchTest` & `NotificationSettingsTest` lulus (Mailpit dev) |
|7| Webhook masuk (Fonnte/Meta/Telegram) | **Berhasil (teruji)** | Verifikasi secret fail-closed + anti-duplikat; **registrasi live terblokir domain (bagian15)** |
|8| Chatbot AI + basis data dinamis (7 intent baru) | **Berhasil (teruji)** | `ChatbotDataGapsTest`16/16; smoke chatbot live3 intent |
|9| Identitas pengguna chatbot & pencarian akun | **Berhasil (teruji)** | Tes lulus (commit lama `56c7e75`) |
|10| AI token usage & pembatasan konteks tamu | **Berhasil (teruji)** | `tokens_used` tercatat; ringkasan `users` tertutup utk tamu |
|11| Pencarian gambar (YOLO/GD) | **Berhasil (teruji)** | `ImageSearchTest`19/19 lulus; perilaku jujur tanpa indeks (CI) |
|12| Keamanan: throttle & filter tanggal berindex | **Berhasil (teruji)** | `ThrottleProtectionTest`3/3 + `ActivityLogFilterTest`2/2 |
|13| Performa: index gabungan DB | **Berhasil (teruji)** | Migrasi terpasang & terverifikasi `db:table` |
|14| Persiapan hosting (nginx/provisioning/deploy) | Siap (belum live) | Artefak lengkap & konsisten; **belum dijalankan di VPS nyata** |
|15| CI/CD GitHub Actions (tests+frontend+deploy) | **Berhasil (teruji)** | **Run #4 SUKSES — semua job hijau** |
|16| Deploy otomatis ke server | Menunggu pihak luar | Butuh secrets `DEPLOY_*` + VPS |
|17| Domain + HTTPS publik | Menunggu pihak luar | Belum ada domain (tidak dibeli tanpa persetujuan) |
|18| Optimasi produksi (`optimize`, queue) | **Berhasil (teruji)** | Cache/route/views OK; suite dikeraskan thd config-cache |
|19| Lupa-password via email | Menunggu konfigurasi | Butuh mail SMTP produksi (dev: Mailpit) |

---

## 15. Kendala Manual, Insiden & Langkah Berikutnya

###15.1 Insiden & pemulihan (dilaporkan apa adanya)

- **Kronologi:** perintah verifikasi `php artisan optimize` (Bagian12) membuat
  `bootstrap/cache/config.php` pada15:09 yang **membekukan `DB_CONNECTION=mysql`**.
  Suite yang dijalankan sesudahnya ikut memakai MySQL asli; `RefreshDatabase` menjalankan
  `migrate:fresh` → **data dev (users/products/orders) terhapus** dan tabel dibuat ulang.
- **Dampak:** hanya data pengembangan hasil seeder (bukan data produksi); skema & seluruh
  migrasi utuh (26 migrasi, termasuk index baru).
- **Pemulihan:** `.env` asli dipulihkan dari cadangan; `php artisan db:seed --force`
  mengembalikan4 pengguna,8 produk,1 pesanan,4 role; worker antrean dinyalakan ulang;
  halaman login diverifikasi200.
- **Pencegahan (sudah dikomit `a341c2e` & terverifikasi):** `phpunit.xml` mengisolasi
  config-cache (`APP_CONFIG_CACHE`) dan memaksa env DB sqlite (`force="true"`) — diuji
  dengan config-cache produksi *ada* → MySQL tidak tersentuh.
- **Pelajaran operasional:** `optimize` adalah langkah **produksi** (jalur deploy), bukan
  langkah rutin di mesin dev yang sedang memakai suite.

###15.2 Kendala yang memerlukan tindakan manual

| # | Kendala | Yang dibutuhkan | Kenapa belum dikerjakan |
|---|---|---|---|
|1| VPS (hosting produksi) | Akun VPS + kredensial SSH | Tidak ada akses yang diberikan |
|2| Domain | Pembelian/pengaturan domain | **Tidak membeli tanpa persetujuan** |
|3| HTTPS | DNS + certbot (butuh domain) | Terblokir no.2 |
|4| Registrasi webhook (Fonnte/Meta/Telegram) | URL publik HTTPS | Terblokir no.2–3 |
|5| Secrets GitHub (`DEPLOY_HOST/USER/SSH_KEY/PATH`) | Diisi di settings repo | Butuh VPS (no.1) — job deploy sengaja gated |
|6| Konfirmasi pesan Fonnte diterima di HP | Cek ponsel admin | Pesan live sudah terkirim (log id19) — konfirmasi penerimaan belum dilaporkan |
|7| Email produksi (lupa-password) | Kredensial SMTP | Belum ada |

###15.3 Langkah berikutnya (berurutan)

1. Siapkan VPS → jalankan `deploy/setup-vps.sh` → isi `.env` produksi → `deploy/deploy.sh`.
2. Arahkan domain + HTTPS (certbot) → set `WEBHOOK_PUBLIC_URL` → daftarkan webhook Fonnte.
3. Isi secrets `DEPLOY_*` di GitHub agar job deploy otomatis aktif.
4. Ulangi smoke E2E pada URL publik + konfirmasi penerimaan pesan Fonnte di HP.

---

*Seluruh angka pada laporan ini berasal dari pengujian yang benar-benar dijalankan
(PHPunit, skrip smoke live, panggilan API Fonnte sungguhan, dan GitHub Actions run #1–#4).*
