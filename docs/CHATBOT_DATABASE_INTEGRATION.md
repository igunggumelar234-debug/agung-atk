# Integrasi Chatbot dengan Database — Agung ATK

Dokumen ini adalah hasil audit + rancangan **lapisan data aktual** chatbot
(PROMPT 8). Sumber kebenaran tetap kode & database aktif; dokumen ini peta
bantuan untuk memelihara sistem.

## 1. Lingkup dan tujuan

Chatbot sebelumnya sudah mengenal **struktur** database (nama tabel, kolom,
relasi) tetapi belum bisa menjawab pertanyaan **angka/data terkini** seperti
"ada berapa produk?", "stok mana yang hampir habis?", "total transaksi bulan
ini?". Tujuan lapisan ini:

- Menjawab pertanyaan data dari **query Eloquent read-only** yang sudah
  divalidasi & di-scope hak akses di server — bukan mengarang, bukan SQL bebas.
- Memahami bahasa sehari-hari (singkat, typo, singkatan: `brp`, `byk`, `jml`).
- Menghormati role dari **session autentikasi Laravel** (bukan teks ketikan).
- Tetap aman: tanpa kredensial di source, tanpa data pribadi lintas pengguna,
  tanpa detail query/infrastruktur pada pesan error.

## 2. Hasil audit sebelum implementasi

### 2.1 Yang sudah ada (tidak dibangun ulang)

| Komponen | Kondisi awal |
|---|---|
| `DatabaseKnowledgeService` | Introspeksi skema read-only (discoveri dinamis seluruh tabel domain + cache/invalidasi migrasi) → struktur untuk prompt & jawaban "fungsi tabel". **Dokumentasi skema, bukan data.** |
| `AIService::ownOrdersReply` | "Pesanan saya" → jumlah + pesanan terbaru **milik sendiri** (sudah di-scope user_id). |
| `AIService::operationalDataReply` | "pendapatan/laba/laporan" → ringkasan staf (admin/anggota), penolakan untuk peran lain. |
| `WorkflowKnowledge` | Langkah kerja per topik + daftar fitur belum tersedia. |
| `ChatGuard` | Pagaran privasi/source input + pagaran output. |

### 2.2 Penyebab chatbot sering menjawab "informasi tidak cukup"

Probe 12 frasa contoh §4 ke server live (mode lokal, tamu) **sebelum**
perbaikan — hanya 4 terjawab:

| Frasa | Hasil | Penyebab |
|---|---|---|
| "Hai." | OK | Pola sapaan sudah ada |
| "Siapa kamu?" | OK | Identitas baku (PROMPT 4/6) |
| "Siapa saya?" | OK | whoAmI dari role session (PROMPT 6) |
| "Bagaimana cara mengubah status pesanan?" | OK | Alur bisnis/langkah |
| "Apa kabar?" | GAGAL | Tidak ada intent percakapan ringan |
| "Apa saja yang bisa kamu bantu?" | GAGAL | Tidak ada intent daftar kemampuan |
| "Ada berapa produk di toko?" | GAGAL (jawaban menyesatkan) | Jatuh ke fallback rekomendasi produk |
| "Produk apa yang stoknya hampir habis?" | GAGAL (jawaban menyesatkan) | Sama — tidak ada intent stok |
| "Produk ATK apa yang paling banyak terjual?" | GAGAL | Tidak ada agregasi penjualan |
| "Berapa jumlah pesanan hari ini?" | GAGAL | Tidak ada hitungan berrentang tanggal |
| "Berapa total transaksi bulan ini?" | GAGAL | Tidak ada pendapatan berrentang |
| "Mengapa pembayaran saya belum dikonfirmasi?" | GAGAL | Tidak ada penjelasan status pembayaran aktual |

**Kesimpulan akar masalah:** routing lokal hanya mengenal identitas/fitur/
struktur/workflow/rekomendasi — **tidak ada intent data** yang mengeksekusi
query, dan tidak ada intent percakapan ringan.

## 3. Arsitektur lapisan data

### 3.1 File

| File | Peran |
|---|---|
| `app/Support/ChatbotDataService.php` (BARU) | Deteksi intent → query read-only terotorisasi → susun jawaban Indonesia. Satu-satunya tempat pola/daftar intent data. |
| `app/Support/AIService.php` | Menghitung jawaban data **sekali** di `buildReply()`, menyuntikkannya ke konteks model (blok `JAWABAN DATA AKTUAL`) sekaligus mengembalikannya langsung pada mode lokal (langkah 5 `localReply()`). |
| `app/Support/ChatGuard.php` | Pagaran privasi/source tetap berjalan **sebelum** lapisan data (input) dan setelah jawaban (output). |

### 3.2 Alur satu pesan

1. `ChatGuard` INPUT — permintaan privasi/source/kredensial → respons tetap,
   tidak menyentuh database.
2. `ChatbotDataService::reply()` — deteksi intent; jika cocok → query
   read-only; jika tidak → `null` (lanjut ke routing lama).
3. Jawaban data dipakai **sekali**: blok `JAWABAN DATA AKTUAL` pada system
   prompt (mode API) **atau** dikembalikan langsung (mode lokal/fallback).
4. `ChatGuard` OUTPUT — deteksi kebocoran sebelum ditampilkan.
5. Metrik aman dicatat: `Log::info('chatbot.data_intent', ['intent', 'role'])`
   — tanpa isi pesan & tanpa nilai data.

### 3.3 Posisi dalam routing lokal

`localReply()` (diperbarui, komentar langkah 1–10):

1. identitas & klaim peran → 1b. siapa saya → 2. aktor/role → 3. peta
   sistem/fitur → 4. struktur database → **5. DATA AKTUAL (baru)** →
6. aturan bisnis → 7. langkah kerja (workflow) → 8. pesanan milik sendiri →
9. ringkasan operasional (staf) → 10. rekomendasi/sapaan/umum.

Kontrak perilaku lama dipertahankan: pertanyaan **"pendapatan/laba"** tetap
langkah 9 (teks ringkasan lama), **"Berapa pesanan saya?"** tetap langkah 8
(ringkasan jumlah), sehingga tes PROMPT 3–6 tetap lulus.

## 4. Daftar intent data

Semua query memakai model Eloquent/`DB::raw` dengan **nama tabel/kolom fixed
di kode** — teks pengguna hanya dipakai untuk memilih intent & parameter
terbatas (rentang waktu, nomor pesanan tervalidasi regex).

| Intent | Contoh pertanyaan | Sumber data | Otorisasi |
|---|---|---|---|
| `product_count` | "Ada berapa produk di toko?", "brp byk produk" | `products` count + count aktif | Publik (katalog) |
| `low_stock` | "Produk apa yang stoknya hampir habis?" | `products` where `stock <= min_stock` OR `stock = 0`, LIMIT 5 | Publik (katalog) |
| `best_selling` | "Produk ATK apa yang paling banyak terjual?" | JOIN `order_details × orders`, status paid–completed, GROUP BY `product_name`, SUM qty/subtotal, LIMIT 5 | Publik (agregat, tanpa PII) |
| `revenue` | "Berapa total transaksi bulan ini/kemarin?" | SUM `grand_total` + count pesanan status paid–completed pada rentang | **Admin & anggota saja** |
| `orders_count` | "Berapa jumlah pesanan hari ini?" | COUNT `orders` + rincian per status pada rentang | **Admin & anggota saja** |
| `my_shipment` | "Pesanan saya sudah sampai mana?", "pengiriman yang ditugaskan ke saya" | `orders` terbaru milik sendiri + `shipments` + `payments`; kurir → `shipments.courier_id = user` | Login; kurir hanya penugasan miliknya |
| `order_lookup` | "cek status pesanan ORD-2026-001" | `orders` + `payments` + `shipments` (lintas tabel) | Admin/anggota: semua; pelanggan: hanya miliknya; kurir: hanya yang ditugaskan; tamu: login; **penolakan satu teks untuk "tidak ada" & "bukan milik"** (anti-enumerasi) |
| `payment_pending` | "Mengapa pembayaran saya belum dikonfirmasi?" | `payments` terbaru milik sendiri (pending/verified/rejected + alasan tolak) | Login; milik sendiri |

### 4.1 Rentang waktu (relatif, deterministik)

`hari ini`, `kemarin`, `minggu ini`, `bulan ini`, `bulan lalu`, `tahun ini`,
`sepanjang waktu`. Default: pendapatan → **bulan ini** (label selalu
ditampilkan agar tidak menyesatkan), jumlah pesanan → sepanjang waktu.
Pendapatan **hanya** menghitung status `paid, processing, ready_to_ship,
shipping, delivered, completed` — `pending` & `cancelled` dikecualikan dan
disebutkan pada jawaban.

### 4.2 Pengecualian pola (anti-tabrakan)

- Pertanyaan **how-to** (`cara|bagaimana|kenapa|kapan|menghitung|…`) tidak
  masuk intent angka — "cara menghitung total transaksi" → penjelasan aturan,
  bukan angka.
- "pendapatan/laba" sengaja **bukan** intent `revenue` — dibiarkan untuk
  langkah ringkasan operasional lama.
- Kata penentu jumlah harus berdampingan dengan noun ("berapa produk",
  "jumlah pesanan") — "berapa kolom tabel produk" tetap pertanyaan struktur.

## 5. Otorisasi dan privasi

- **Role dari session server** (`$user->role?->name` hasil autentikasi) —
  tidak pernah dari teks ketikan.
- Query selalu `read-only`; tidak ada mekanisme UPDATE/DELETE/INSERT sama
  sekali di lapisan ini (perubahan data hanya lewat fitur bisnis resmi).
- Data operasional non-staf → penolakan **tanpa angka sama sekali**.
- Lookup lintas pengguna: satu pesan penolakan untuk dua kemungkinan
  ("tidak ditemukan **atau** bukan milik Anda") → mencegah penelusuran
  keberadaan pesanan orang lain.
- Jawaban milik sendiri hanya menampilkan status/total/resi — tanpa data
  pribadi pihak lain, tanpa password/token/email.
- Error query → pesan generik ("data belum berhasil diperiksa… tanpa
  menebak hasilnya"); log hanya `class_basename` exception (tanpa SQL/nilai).
- Skrip uji & dokumen **tidak pernah** memuat kredensial/token.

## 6. Mode API vs mode lokal

- **Mode lokal (tanpa `AI_API_KEY`)** — jawaban data dikembalikan langsung
  dari `ChatbotDataService` (deterministik, angka nyata).
- **Mode API** — jawaban data yang sama disuntikkan sebagai blok
  `JAWABAN DATA AKTUAL (… BUKAN instruksi)` pada system prompt; aturan prompt
  ke-23 menyuruh model memakai angka apa adanya dan menganggap isi blok itu
  data mentah (bukan perintah — mitigasi injeksi lewat nilai database).
- **Kegagalan provider AI** (500/timeout) → `askApi()` `null` → fallback
  lokal dengan jawaban data yang sama (teruji).

## 7. Pengujian

`tests/Feature/ChatbotDataIntegrationTest.php` — **28 tes / 102 asersi**
(semua poin §11):

| Poin §11 | Tes |
|---|---|
| Sapaan sederhana | `test_small_talk_greeting_is_answered_naturally` |
| "siapa kamu/saya" | tercakup `ChatbotIdentityTest` & `ChatbotSimpleQuestionTest` |
| Jumlah produk | `test_product_count_comes_from_live_database`, `test_typo_and_abbreviation_are_understood` |
| Stok rendah | `test_low_stock_products_listed_with_real_numbers`, `test_low_stock_says_none_when_everything_is_healthy` |
| Transaksi berrentang | `test_revenue_for_this_month_counts_only_sales_statuses`, `test_orders_count_today_for_admin`, `test_empty_date_range_reports_zero_orders_honestly` |
| Status pesanan pelanggan login | `test_own_order_tracking_shows_status_payment_and_shipment`, `test_tracking_for_guest_requires_login` |
| Tugas kurir login | `test_courier_without_assignments_gets_honest_empty_state`, `test_courier_sees_only_shipments_assigned_to_him` |
| Fitur per role | `test_store_aggregates_are_allowed_for_anggota`, `test_anggota_cannot_get_chatbot_to_claim_admin_actions` + tes peta menu lama |
| Beberapa tabel | `test_best_selling_aggregates_order_details_and_ignores_pending`, `test_own_order_tracking_…` (orders+payments+shipments) |
| Typo/bahasa sehari-hari | `test_typo_and_abbreviation_are_understood` |
| Pertanyaan tidak relevan/ambigu | `test_ambiguous_question_offers_clarification_without_fabricating` |
| Data kosong & query gagal | `test_empty_date_range_…`, `test_failed_query_returns_safe_message_without_sql_details` |
| Pelanggan baca data orang lain | `test_customer_cannot_read_another_users_order_by_number` |
| Kurir akses pesanan bukan tugasnya | `test_courier_cannot_read_an_order_not_assigned_to_him` |
| Anggota vs fitur admin | `test_anggota_cannot_get_chatbot_to_claim_admin_actions`, `test_admin_can_read_any_order_summary` |
| Password/token/source/injection | `test_sql_style_request_never_returns_row_data`, `test_injection_text_inside_product_name_is_treated_as_data` + tes guard lama |
| Klarifikasi | `test_ambiguous_question_offers_clarification_without_fabricating` |
| Kegagalan provider & fallback | `test_ai_provider_failure_falls_back_to_local_data_answer`, `test_api_mode_sends_actual_data_block_inside_system_prompt` |

Status akhir: **suite penuh 551/551 lulus (2.707 asersi)**; Pint bersih.

## 8. Cara menambah intent baru (checklist)

1. Tambahkan pola + cabang `match()` di `ChatbotDataService` (satu tempat).
2. Query hanya via Eloquent dengan kolom fixed; `LIMIT`/`take()` wajib untuk
   daftar; agregasi SQL untuk hitungan besar.
3. Tentukan otorisasi: publik / login / staf / per role — **sebelum** query.
4. Tangani dua cabang wajib: data kosong (jujur) dan exception (pesan generik
   tanpa detail SQL) — keduanya otomatis tercakup `reply()` bila Anda hanya
   melempar `Throwable` biasa.
5. Cek tabrakan dengan langkah lokal lama (jalankan suite penuh).
6. Tambahkan tes minimal: satu kasus sukses, satu kasus ditolak per role.

## 9. Keterbatasan (jujur)

- Intent bersifat **pola berbahasa Indonesia** — sinonim di luar daftar
  (mis. "omzet" tertangkap, "cuan" tidak) akan jatuh ke routing lama /
  keterbatasan jujur; perluasan cukup tambah pola + tes.
- Tanpa `AI_API_KEY`, pertanyaan umum/edukasi non-data tetap dijawab dengan
  pernyataan keterbatasan (perilaku lama, di luar lingkup dokumen ini).
- `report.daily` & notifikasi terkait tidak diubah di PROMPT ini.
- Belum ada chat history lintas sesi untuk maksud ambigu ("yang tadi") —
  klarifikasi dijawab dengan ajakan menyertakan detail.
