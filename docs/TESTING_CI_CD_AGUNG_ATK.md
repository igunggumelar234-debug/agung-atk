# TESTING & CI/CD — AGUNG ATK

**Versi:** 1.0 — 17 November 2026
**Berkas terkait:** `phpunit.xml`, `tests/` (40 file), `.github/workflows/ci.yml`

---

## 1. Strategi Pengujian

| Aspek | Keputusan |
|---|---|
| Framework | **PHPUnit** (bawaan Laravel — lihat `composer.json` dev) |
| Tipe test | **Feature test dominan** (HTTP + database nyata via Eloquent) + unit test ringan |
| Database | **SQLite `:memory:`** — terisolasi penuh, tidak menyentuh MySQL produksi |
| Email/pesan | `MAIL_MAILER=array`, `NOTIFICATIONS_ENABLED=false`, `AI_API_KEY=''` — **tidak pernah** mengirim email/pesan/panggilan API berbayar saat suite berjalan |
| HTTP eksternal | `Http::fake()` (WhatsApp, Telegram, LLM, YOLO) |
| Queue | `QUEUE_CONNECTION=sync` |
| Cache/Sesi | `array` / sesi uji internal |

**Prinsip:** setiap test memvalidasi **perilaku nyata** (response HTTP, isi database, pesan yang dikirim ke kanal via fake) — bukan sekadar "kode tidak error".

---

## 2. Inventaris Pengujian (41 file · 645 test · 3.050 asersi)

### 2.1 Autentikasi, role & akun
`AuthTest`, `RoleAccessTest`, `AccountPagesTest`, `SecurityHeadersTest`, `ErrorPagesTest`, `ThemeTest`, `AppTimezoneTest`

### 2.2 Bisnis penjualan
`CatalogCartTest`, `CheckoutTest`, `OrderCancellationTest`, `PaymentManagementTest`, `ShipmentModuleTest`, `SellerModuleTest`, `ReviewTest`, `AdminCatalogTest`, `AdminManagementTest`, `DashboardReportTest`, `BusinessAuditTest`, `BarcodeScanTest`

### 2.3 Notifikasi & integrasi kanal
`NotificationDispatchTest`, `NotificationSettingsTest`, `WhatsAppCloudApiTest`, `ChatbotChannelWebhookTest` (30 tes webhook WA/TG: verifikasi secret, dedupe, identitas kanal, job balasan)

### 2.4 Chatbot & AI
`HomeChatTest`, `ChatbotSimpleQuestionTest`, `ChatbotIdentityTest`, `ChatbotSystemMapTest`, `ChatbotSystemUnderstandingTest`, `ChatbotDataIntegrationTest`, `ChatbotIntelligenceTest`, `ChatbotCommandsTest` (perintah & 12 bahasa), `AiAssistantTest`, `AiDatabaseContextTest`

### 2.5 Computer vision (pencarian gambar)
`ImageSearchTest`, `YoloImageSearchTest` (mock HTTP ke layanan YOLO: deteksi, fallback, ambang keyakinan)

### 2.6 Data & integritas
`ModelRelationTest`, `RelationshipTest`

---

## 3. Cara Menjalankan

```bash
# seluruh suite (±5,6 menit)
php artisan test --compact

# per file / per nama
php artisan test --compact tests/Feature/ChatbotCommandsTest.php
php artisan test --compact --filter=test_menu_command_returns_indonesian_help

# formatter gaya kode
vendor/bin/pint --test          # periksa
vendor/bin/pint --dirty         # perbaiki file yang berubah
```

**Hasil terakhir (17 November 2026, dijalankan di workspace ini):**

```
{"tool":"phpunit","result":"passed","tests":620,"passed":620,"assertions":2940,"duration_ms":338997}
```

---

## 4. Pipeline GitHub Actions (`.github/workflows/ci.yml`)

### 4.1 Pemicu
`push` ke `main`/`master` dan setiap `pull_request`.

### 4.2 Job `tests` (Unit & Feature Tests)
1. **Checkout** repository.
2. **Setup PHP 8.3** + ekstensi (`pdo_sqlite`, `mbstring`, `dom`, `curl`, `zip`, `fileinfo`, `openssl`).
3. **`composer install`** (tanpa interaksi, prefer dist).
4. **Siapkan environment testing** — salin `.env.example` → `.env`, `php artisan key:generate`.
5. **`vendor/bin/pint --test`** — pemeriksaan gaya kode; **gagal bila ada file tidak sesuai format**.
6. **Migrasi database testing** — `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan migrate` (memvalidasi seluruh 25 migration).
7. **`php artisan test --compact`** — seluruh unit & feature test; **gagal bila ada test gagal**.

### 4.3 Job `frontend` (Frontend Build)
1. Checkout → **Setup Node.js 22** → `npm install` → **`npm run build`** (Vite).
   Catatan jujur: saat ini view memakai CDN + `<style>` inline (temuan audit A1); scaffold Vite tetap divalidasi agar tetap sehat bila diaktifkan kembali.

### 4.4 Jaminan kegagalan
Pipeline berstatus **gagal (merah)** bila: dependency tidak terpasang, Pint menolak format, migrasi error, test gagal, atau build gagal. Tidak ada langkah `continue-on-error`.

---

## 5. Status Eksekusi (jujur)

| Langkah | Status | Bukti |
|---|---|---|
| `composer install` + test lokal | ✅ Berhasil | Suite 620/620 dijalankan di workspace |
| `pint --test` seluruh repo | ✅ Lolos | `vendor/bin/pint --test` → `passed` |
| Migrasi SQLite | ✅ Berhasil | Dijalankan ulang setiap suite via `RefreshDatabase` (25 migration) |
| Build Vite (`npm run build`) | ⚠️ **Belum dieksekusi** | Node.js tidak terpasang di mesin pengembangan |
| **Eksekusi GitHub Actions** | ⚠️ **Belum berjalan** | **Remote GitHub belum ada** (`git remote -v` kosong) — pipeline baru berjalan setelah repository di-push |

> Klaim status pipeline **hijau di GitHub** baru boleh diberikan setelah job benar-benar berjalan di Actions. Instruksi penghubungan ada di §6.

---

## 6. Langkah Menghubungkan GitHub (untuk mahasiswa)

> Nama akun/repository **tidak dikarang** — isi sendiri sesuai akun Anda.

```bash
# 1. Buat repository kosong di GitHub (tanpa README/.gitignore awal)
# 2. Hubungkan dari lokal:
git remote add origin https://github.com/<username-mu>/<nama-repo>.git
git branch -M main
git add .
git commit -m "feat: proyek akhir Agung ATK — aplikasi, AI, notifikasi, dokumen OBE"
git push -u origin main
# 3. Buka tab Actions di repository — job CI akan berjalan otomatis.
```

Periksa hasilnya: **Actions → CI → run hijau/merah**. Jangan mengklaim hijau sebelum run terlihat.

---

## 7. Praktik Keamanan Pengujian

- ✅ Suite berjalan pada database **terpisah** (SQLite in-memory) — tidak ada aksi destruktif ke MySQL dev/produksi.
- ✅ Tidak ada pengiriman email/pesan/HTTP berbayar saat test (mock + saklar `NOTIFICATIONS_ENABLED=false`).
- ✅ Kredensial uji tidak ditulis ke file test (hanya value dummy seperti `test-key`).
- ✅ Pengujian akses antarpengguna (lintas role & lintas data) menjadi bagian suite regresi.
