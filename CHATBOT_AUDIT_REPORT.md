# Laporan Audit & Perbaikan Chatbot AI AGUNG ATK

Tanggal: 19 November 2026
Cakupan: audit menyeluruh chatbot yang ADA (tanpa membuat chatbot baru),
perbaikan langsung di source code, pembuktian lewat tes.

---

## 1. Ringkasan Eksekutif

Chatbot sebelumnya menjawab dua pertanyaan penting dengan **fallback yang
menyesatkan** — bukan karena data tidak ada, melainkan karena (a) intent
tidak dikenali dan (b) akhiran bahasa Indonesia merusak pencocokan regex.
Setelah audit + perbaikan:

- **12/12 tes wajib lulus** (60 asersi) — sebelum perbaikan 8 di antaranya
  gagal (bukti fase merah, lihat §6).
- **231/231 tes chatbot lulus** (990 asersi) = 219 tes lama yang TIDAK
  berubah jawabannya + 12 tes baru.
- **Suite penuh 702/702 lulus, 3.311 asersi** (~9,3 menit) — tidak ada
  regresi di seluruh aplikasi.
- Pint bersih (`vendor/bin/pint --dirty --format agent`).

---

## 2. Metode Audit

1. Membaca seluruh jalur pesan: `AIService::send` → `buildReply`
   (guard → catalog → slash → `data->reply/handle` → `askApi` →
   `localReply`) → `sanitizeOutput`.
2. Membaca seluruh `ChatbotDataService` (deteksi intent + jawaban),
   `ChatbotProfile`, `DatabaseKnowledgeService`, `ChatGuard`,
   `WorkflowKnowledge`, `AIController`, kedua view chatbot.
3. Menjalankan **baseline** tes chatbot lama: **219/219 lulus
   (930 asersi)** — patokan regresi.
4. Menulis 12 tes wajib lebih dulu (TDD) → fase merah membuktikan cacat:
   `12 tests, 4 passed, 8 failed, 36 assertions`.
5. Memperbaiki source code hingga semua hijau, lalu regresi penuh.

---

## 3. Penyebab Masalah (Temuan Audit)

### A. Intent katalog tidak pernah ada → jatuh ke jawaban generik
**Gejala:** "Berikan saya katalog produk yang tersedia." dijawab
*"belum menemukan produk yang cocok"*.
**Penyebab:** `detect()` tidak memiliki intent `product_catalog` —
permintaan daftar produk tertangkap jalur rekomendasi/generic.
**Bukti:** fase merah TEST1 gagal.

### B. Akhiran `-nya` memecah regex pencarian pelanggan
**Gejala:** "Siapa saja pelanggannya?" dijawab
*"saya belum memiliki informasi yang cukup"* (unavailableReply).
**Penyebab:** regex lama `\bpelanggan\b` memakai word-boundary; pada
"pelanggannya" posisi akhir kata adalah antara `n` dan `y` — bukan
boundary → tidak cocok → `null`.
**Bukti:** fase merah TEST2 gagal. (Data pelanggan sebenarnya ada dan
sah untuk role admin.)

### C. Tidak ada konteks antar-pesan
Pertanyaan lanjutan ("lanjut", "kalau kategori X?", "stoknya?")
tidak punya acuan ke pesan sebelumnya — tidak ada penyimpanan intent/halaman
sebelumnya.

### D. Cakupan intent sempit & tanpa paginasi
Tidak ada intent untuk harga (termurah/termahal), detail produk per nama,
produk per kategori, total stok, stok habis, daftar kategori, riwayat
pembelian pelanggan, pelanggan tanpa transaksi, pelanggan terbaru, pesanan
per status, status pembayaran, ringkasan transaksi, ikhtisar aplikasi, dan
aktivitas terbaru. Daftar yang ada memakai `take()` tanpa halaman.

### E. Sinonim & bahasa sehari-hari tidak dikenali
"pembeli" tidak diakui sebagai sinonim "pelanggan"; bentuk kepemilikan
("pesanan saya", "produkku") tidak dinormalisasi.

### F. Frontend menampilkan balasan sebagai teks polos
Tidak ada penataan baris/header untuk jawaban terstruktur (katalog/daftar).

### G. Knowledge base punya dua celah pola
"Di mana saya bisa melihat laporan?" tidak cocok pola `cara|bagaimana`;
"Apa fungsi menu kategori?" tidak masuk pola fitur.

---

## 4. File yang Diperbaiki

| # | File | Perubahan |
|---|---|---|
| 1 | `app/Support/ChatbotDataService.php` | **Perbaikan utama.** `normalize()` (sufiks `-nya/-ku/-mu`, klausa "pesananku→pesanan saya"); API baru `handle($lower, $user, $context)` → `{reply, intent, context}` (`reply()` tetap sebagai wrapper lama); deteksi pertanyaan lanjutan `detectFollowUp()` + `contextEntity()`; **15 intent baru** (lihat §5); matcher + `answer()` baru; sinonim `pembeli`; paginasi (`clampPage`/`pageFooter`, page size katalog 10) pada daftar lama & baru; urutan detect disusun spesifik→umum dengan guard `isPossessive`/`isHowto` agar 219 tes lama tidak bocor. |
| 2 | `app/Support/AIService.php` | `loadContext()`/`saveContext()` (cache per `conversationId`, TTL 30 menit → tidak tercampur antar-user); `buildReply` memanggil `handle()` + menyimpan konteks hanya bila ada intent data; `askApi()`/`systemPrompt()` menerima konteks → blok `KONTEKS PERCAKAPAN SEBELUMNYA` (JSON, bukan instruksi) + aturan 23b untuk follow-up. |
| 3 | `app/Support/ChatbotProfile.php` | `FEATURE_PATTERN` bertambah pola `\b(fungsi\|kegunaan\|manfaat\|guna)\b…\b(menu\|fitur\|halaman\|tombol)\b` → "Apa fungsi menu kategori?" terjawab peta menu peran. |
| 4 | `app/Support/WorkflowKnowledge.php` | Pola laporan bertambah `(di mana\|dimana\|mana saya bisa\|ke mana)…laporan` → "Di mana saya bisa melihat laporan?" terjawab. |
| 5 | `resources/views/partials/home-chatbot.blade.php` | Renderer `renderLines()` — baris per node (teks murni, aman dari injeksi), header kapital baris pertama diberi `.chatbot-bubble-head`. |
| 6 | `resources/views/ai/chat.blade.php` | Renderer `renderLines()` + `.msg-head`; saran pertanyaan baru "Berapa jumlah seluruh produk?". |
| 7 | `tests/Feature/ChatbotNaturalLanguageTest.php` | **Baru** — 12 tes wajib (TEST1–12). |

Tidak ada perubahan dependency, tidak ada migrasi, tidak ada data yang
diubah. `.env` tidak disentuh (token tetap hanya di `.env`).

---

## 5. Fitur yang Ditambahkan

**Intent baru (15):** `product_catalog`, `price_extremes`, `product_detail`,
`products_by_category`, `total_stock`, `out_of_stock`, `category_list`,
`customer_history`, `customers_without_orders`, `newest_customers`,
`orders_by_status`, `payment_status_counts`, `transaction_summary`,
`app_stats`, `recent_activities` (admin).

**Context awareness:** kunci cache `chatbot.context.{conversationId}`
(TTL 30 menit) menyimpan `{intent, page, entity}` → mendukung:
- "lanjut" / "halaman 2" → halaman berikut daftar yang sama;
- "kalau kategori X?" → `products_by_category` dengan entitas kategori;
- "stoknya? / harganya?" → `product_detail` produk yang sedang dibahas.

**Format katalog/daftar:** header CAPS + penomoran + `Jumlah produk: N` +
`Halaman X dari Y. Ketik "lanjut"`; rupiah selalu `Rp1.234.567`
(`number_format` gaya Indonesia).

**Fallback terbedakan (5 kondisi):**
1. Data kosong → "Katalog saat ini belum memiliki produk… / Belum ada…"
2. Tak dipahami → `null` → jalur chatbot lain (identitas/edukasi/generic)
3. Akses ditolak → pesan per role ("hanya untuk admin", "silakan login…")
4. DB gangguan → catch `Throwable` → "data belum berhasil diperiksa karena
   terjadi kendala teknis… dan saya tidak boleh menebak hasilnya"
   (+ log aman hanya `intent` + `class_basename`)
5. AI down → `askApi` gagle/null → `localReply` (jawaban lokal deterministik)

**Hak akses per role:** gate `isStaff`/`isAdmin`/`customerAccountScope`
ditegakkan di SERVER pada `answer()` — bukan klaim dalam percakapan.
Scope data pelanggan: admin (daftar/riwayat penuh), anggota (hanya pencarian
nama), pelanggan (akunnya sendiri), tamu (arahan login).

**Keamanan:** query read-only dengan binding terikat; LIKE di-escape
(`addcslashes('%_')`); nama produk/kategori dari pengguna tidak pernah
menjadi SQL; batas hasil selalu ada; audit log `chatbot.data_intent`
(intent + peran + halaman, tanpa isi pesan/data sensitif).

---

## 6. Hasil 12 Tes Wajib (TEST1–12)

| # | Tes | Menguji | Hasil |
|---|---|---|---|
| 1 | `test_catalog_request_returns_full_product_list_from_database` | Katalog penuh dari DB (penyebab A) | ✅ LULUS |
| 2 | `test_possessive_customer_question_is_understood_per_role` | "pelanggannya" dipahami + scope per role (penyebab B) | ✅ LULUS |
| 3 | `test_product_count_is_counted_live` | Jumlah produk dihitung live | ✅ LULUS |
| 4 | `test_cheapest_product_question_reads_live_prices` | Termurah dari harga aktual | ✅ LULUS |
| 5 | `test_stock_of_specific_product_is_read_from_database` | Stok produk spesifik dari DB | ✅ LULUS |
| 6 | `test_transactions_today_use_actual_database_totals` | Total transaksi hari ini dari DB | ✅ LULUS |
| 7 | `test_newest_customer_listed_for_admin` | Pelanggan terbaru (admin) | ✅ LULUS |
| 8 | `test_follow_up_keeps_catalog_context` | Konteks antar-pesan (kategori → termurah) | ✅ LULUS |
| 9 | `test_newly_added_product_is_visible_without_retraining` | Produk baru langsung terlihat | ✅ LULUS |
| 10 | `test_regular_user_is_denied_administrative_data` | Penolakan role untuk data admin | ✅ LULUS |
| 11 | `test_synonyms_and_daily_language_are_understood` | Sinonim (pembeli) & bahasa sehari-hari | ✅ LULUS |
| 12 | `test_database_failure_is_reported_honestly` | DB gagal → pesan jujur, tanpa kebocoran SQL | ✅ LULUS |

**Fase merah (sebelum perbaikan):** `12 tests, 4 passed, 8 failed,
36 assertions` — kegagalan tepat pada: katalog, pelanggannya, termurah,
pelanggan terbaru, follow-up konteks, produk baru, sinonim/pembeli, dan
kegagalan DB (500). Setelah perbaikan: **12/12, 60 asersi.**

### Regresi & suite penuh

| Pengujian | Hasil |
|---|---|
| `--filter=Chatbot` (219 lama + 12 baru) | **231/231 lulus, 990 asersi** |
| HomeChat / AiDatabaseContext / AiAssistant / BarcodeScan | **44/44 lulus, 256 asersi** |
| Suite penuh | **702/702 lulus, 3.311 asersi** |
| Pint | bersih |

219 tes lama tidak diubah sama sekali — semua frasa lama yang dikunci tes
(mis. "Cari pulpen untuk menulis" → `stok:`, "Berapa pendapatan toko?" →
jawaban operasional, "Apa fungsi tabel products?" → schema) tetap berjawab
persis seperti sebelumnya.

---

## 7. Kendala & Catatan

1. **TEST12 versi awal memicu HTTP 500.** Rencana awal mengubah nama tabel
   `users` untuk memaksa kegagalan query, tetapi `AIService::send`
   (baris ~92) me-load `$conversation->user` → `QueryException` terjadi di
   LUAR blok catch jawaban → 500. **Solusi:** uji kegagalan memakai
   rename tabel `roles` (query `usersByRoleReply` join `roles` gagal di
   DALAM blok catch) → balasan jujur sesuai desain. Ini juga yang
   membuktikan perlunya pesan "data belum berhasil diperiksa" (kondisi 4).
2. **`withCredentials()` tidak mereset `actingAs()`.** Tes yang berturut-turut
   mengirim permintaan sebagai admin lalu sebagai tamu ternyata masih
   ter-auth sebagai admin (guard belum dilupakan) → tamu "melihat" daftar
   admin. **Solusi:** `$this->app['auth']->forgetGuards()` sebelum permintaan
   tamu (pola yang sudah dipakai `ChatbotSimpleQuestionTest`).
3. **Mode API vs mode lokal.** `.env` berisi `AI_API_KEY` (mode API live),
   sedangkan suite tes berjalan pada fallback lokal (Http dimock/tidak
   terpanggil pada jalur tes) — konsisten dengan baseline; tidak ada tes
   yang bergantung pada kuota API eksternal.
4. **Frontend tidak tercakup tes otomatis.** Perubahan renderer
   (`renderLines`) diverifikasi lewat review struktur DOM: teks disisipkan
   sebagai `textContent` per baris (tetap aman dari XSS), tidak ada
   perubahan pada cara pesan dikirim/fitur loading/error/scroll yang sudah
   ada. Bila frontend tidak muncul di UI, jalur build tidak terlibat
   (view murni Blade + JS inline — tidak lewat Vite).
5. **Server live tidak tersedia saat verifikasi.** `php artisan serve`
   & `queue:work` di environment ini mati ("server restarted") — pembuktian
   akhir dilakukan lewat suite tes (702/702) bukan smoke manual. Smoke
   aplikasi (`e2e_smoke.ps1`, 6/6) dari TASK12 tidak terpengaruh perubahan
   ini (tidak menyentuh jalur chat).
6. **Di luar cakupan (tidak dikerjakan sesuai instruksi):** hosting VPS,
   domain, HTTPS, dan registrasi webhook tetap menunggu persetujuan &
   infrastruktur; tidak ada pembelian domain; tidak ada perubahan
   dependency; tidak ada `php artisan optimize` di dev.

---

## 8. Rekomendasi Lanjutan (opsional)

- Tambah tes renderer frontend bila nanti ada setup browser-test (Playwright).
- Bila volume percakapan besar, pertimbangkan migrasi konteks dari cache ke
  kolom `ai_conversations.context` (perlu persetujuan migrasi non-destruktif).
- Pantau log `chatbot.data_intent` (intent + peran + halaman) untuk
  menemukan frasa baru yang belum terpetakan.

---

## 9. Bukti Perubahan

- File laporan: `CHATBOT_AUDIT_REPORT.md` (ini) &
  `CHATBOT_DATABASE_MAPPING.md` (peta intent → tabel/kolom/query).
- Baseline audit TASK12 tetap berlaku: `AUDIT_REPORT.md` (15 bagian,
  commit `07da97a`).
- Kueri bukti: `php artisan test --compact tests/Feature/ChatbotNaturalLanguageTest.php`
  → `12 passed, 60 assertions`;
  `php artisan test --compact --filter=Chatbot` → `231 passed, 990 assertions`;
  `php artisan test --compact` → `702 passed, 3311 assertions`.
