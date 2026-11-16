# Keamanan Chatbot & Data - Agung ATK

Dokumen ini menjelaskan pagar keamanan chatbot AI: otorisasi, privasi,
kerahasiaan kredensial, dan pertahanan terhadap prompt injection — beserta
bukti pengujiannya.

## 1. Prinsip dasar

1. **Otorisasi di backend, bukan di prompt** — identitas & role diambil dari
   sesi autentikasi server; teks pengguna TIDAK PERNAH menentukan hak akses.
2. **Chatbot bersifat READ-ONLY** — tidak ada eksekusi SQL bebas, tidak ada
   operasi tulis (ubah role, ubah data, hapus) lewat chat. Operasi bisnis
   hanya lewat controller/route resmi yang tervalidasi.
3. **Tanpa kredensial ke model** — password, hash, token, `.env`, source code,
   dan system prompt tidak pernah disuntikkan ke API eksternal.
4. **Jujur atas karangan** — data kosong/keterbatasan dijawab terbuka, bukan
   ditebak.

## 2. Pagar data per peran (server-side)

Konteks disusun SEBELUM model melihat pesan (`AIService` + `ChatbotDataService`
+ `ChatbotProfile`), dibatasi role login:

| Role | Dapat dijawab chatbot | Tidak pernah |
|---|---|---|
| Tamu (belum login) | Katalog/produk publik, cara pakai, struktur sistem | Statistik, pesanan, data pribadi siapa pun |
| Pelanggan | Produk/harga/stok, **pesanan/pengiriman/pembayaran miliknya sendiri** | Pesanan/telepon/alamat pelanggan lain |
| Kurir | **Pengiriman yang ditugaskan kepadanya** + panduan proses | Tugas kurir lain, data pribadi penerima selain yang ditugaskan |
| Anggota | Operasional toko: stok, ringkasan pesanan, panduan fitur | Daftar akun pengguna lain, angka pendapatan penuh |
| Admin | Statistik, laporan, data operasional (sesuai permission) | Kredensial, hash, token, source |

Bukti: `ChatbotDataIntegrationTest` (mis.
`test_customer_cannot_read_another_users_order_by_number`,
`test_courier_cannot_read_an_order_not_assigned_to_him`,
`test_revenue_is_denied_for_customer_without_any_numbers`,
`test_anggota_cannot_get_chatbot_to_claim_admin_actions`),
`AiDatabaseContextTest`, `RoleAccessTest`.

## 3. Privasi & identitas kanal

- Tautan akun WhatsApp/Telegram memakai **kode verifikasi 6 digit** dari menu
  Profil (bukan asumsi username/nomor). Kode punya masa berlaku.
- Pesan dari kanal tanpa tautan diperlakukan sebagai **tamu** — jawaban tidak
  memuat data akun; disertai petunjuk cara menautkan akun.
- Pengguna lain tidak pernah menerima data pribadi Anda; pertanyaan lintas
  identitas dijawab dengan penolakan sopan.
- Penerima notifikasi selalu terikat per peran (lihat
  `docs/NOTIFICATION_INTEGRATION.md` bagian 3).

## 4. Pertahanan prompt injection

Diterapkan pada `App\Support\ChatGuard` (input) dan `AIService` (output):

- **Input (sebelum model dipanggil)**: permintaan mengambil source code/file
  internal, kredensial, system prompt, atau membongkar aturan keamanan dijawab
  dengan respons tetap — model tidak dipanggil sama sekali.
- **Output**: deteksi kebocoran system prompt/konteks dan redaksi pola rahasia
  sebelum balasan dikirim.
- **Data sebagai data**: teks injeksi yang tersembunyi di dalam nama produk
  diperlakukan sebagai data, bukan instruksi
  (`test_injection_text_inside_product_name_is_treated_as_data`).
- Model menerima instruksi sistem yang melarang mengarang data; angka operasional
  selalu dari kueri backend yang sudah difilter role.

## 5. Kerahasiaan kredensial

- Kunci/secret hanya di `.env` — tidak pernah di source, dokumen, commit,
  atau log. Dashboard hanya menampilkan status (terisi/belum) dan variabel
  yang kurang, bukan nilainya.
- **Sanitasi error**: teks pengecualian yang berpotensi memuat kredensial
  (token bot Telegram, `access_token=`, `Bearer …`) dibersihkan oleh
  `NotificationMessageFactory::sanitizeError` sebelum menjadi notifikasi —
  diuji `test_failed_queue_job_triggers_sanitized_system_issue_alert`.
- Log AI (`AIService`) hanya memuat status HTTP + potongan badan tanpa header
  atau kunci.
- Webhook menolak permintaan tanpa kredensial yang benar (lihat bagian 6).

## 6. Keamanan webhook (kanal masuk)

| Pagar | WhatsApp | Telegram |
|---|---|---|
| Verifikasi | Token verifikasi GET (`WHATSAPP_VERIFY_TOKEN`) | — |
| Signasi | `X-Hub-Signature-256` HMAC dengan `WHATSAPP_APP_SECRET` (bila diisi) | Header `X-Telegram-Bot-Api-Secret-Token` == `TELEGRAM_WEBHOOK_SECRET` (**fail-closed**: tanpa secret, semua permintaan ditolak) |
| Anti-duplikat | `wamid` (cache 24 jam) | `update_id` (cache 24 jam) |
| Laju | `throttle:60,1` per IP pada route `webhooks/*` | sama |
| Ukuran pesan | Maksimal **2000 karakter** — lebih dibalas sopan tanpa memanggil AI | sama |
| CSRF | Dikecualikan khusus pola `webhooks/*` (divalidasi signasi/secret) | sama |

Bukti: `ChatbotChannelWebhookTest` (verifikasi, signature salah → 403,
secret salah → 403, duplikat → diproses sekali, pesan raksasa → balasan sopan).

## 7. Batasan eksekusi & audit

- Chatbot tidak menjalankan SQL yang dibuat model/pengguna; hanya membaca
  struktur skema (read-only) dan kueri Eloquent terprogram dengan LIMIT.
- Tidak ada jalur perubahan role/permission lewat chat.
- Aktivitas penting aplikasi tercatat di `activity_logs`; pengiriman notifikasi
  di `notification_logs`; kegagalan job di antrean (`failed_jobs`,
  `php artisan queue:failed`) + notifikasi `system.issue` ke admin.
- ChatGuard membatasi ukuran/isi input; riwayat percakapan dibatasi jumlah
  pesan yang dikirim ke model.

## 8. Ringkasan pengujian keamanan

| Area | Tes |
|---|---|
| Penolakan kredensial via chat | `ChatbotDataIntegrationTest::test_sql_style_request_never_returns_row_data` + bagian injeksi |
| Privasi lintas pengguna | `test_customer_cannot_read_another_users_order_by_number`, `test_courier_cannot_read_an_order_not_assigned_to_him` |
| Penolakan akses tak sah | `test_revenue_is_denied_for_customer_without_any_numbers`, `test_order_count_is_denied_for_kurir` |
| Injaksi prompt | `test_injection_text_inside_product_name_is_treated_as_data`, `ChatbotIntelligenceTest` |
| Webhook palsu | `ChatbotChannelWebhookTest` (signature/secret salah → 403) |
| Kebocoran rahasia di error | `NotificationDispatchTest::test_failed_queue_job_triggers_sanitized_system_issue_alert` |
| Uji tanpa pesan nyata | Seluruh suite: `Http::fake` / mailer `array` (lihat `docs/NOTIFICATION_INTEGRATION.md` bagian 7) |
