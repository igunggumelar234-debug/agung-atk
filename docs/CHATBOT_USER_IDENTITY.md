# Chatbot: Identitas Pengguna & Pencarian Akun Pelanggan

Dokumen ini menjelaskan cara chatbot Agung ATK mengenali nama pengguna yang
sedang login, mengambil nama dari database, mencari nama pelanggan, menerapkan
izin per role, menyegarkan data, dan menegakkan privasi.

Sumber kode utama:

| File | Peran |
|------|-------|
| `app/Support/ChatbotProfile.php` | Pola pertanyaan identitas diri + jawaban "siapa saya" / "sudah terdaftar" |
| `app/Support/ChatbotDataService.php` | Intent data akun pelanggan (hitung, daftar, pencarian, nama pelanggan pesanan) |
| `app/Support/AIService.php` | Routing jawaban: pagaran privasi → intent data → model API → fallback lokal |
| `app/Support/ChatGuard.php` | Pagaran input/output privasi (menangkap "pelanggan lain", dsb.) |
| `tests/Feature/ChatbotUserIdentityTest.php` | 25 tes skenario identitas & privasi akun |

## 1. Sumber data aktual (hasil audit)

Chatbot TIDAK berasumsi — seluruh jawaban dibaca dari struktur database nyata:

- **Tabel `users`**: `id`, `role_id`, `employee_code`, `position`,
  `employment_status`, `hired_at`, **`name`** (NOT NULL), **`email`**
  (unik = identitas login), `phone`, `avatar`, **`is_active`**, `password`,
  `remember_token`, timestamps.
- **Tabel `roles`**: `id`, `name` (`admin`, `anggota`, `kurir`, `pelanggan`),
  `label`, `description`.
- **TIDAK ADA kolom `username`** dan tidak ada tabel `pelanggan`/`anggota`/
  `kurir`/profil/permission terpisah — keempat role adalah isi tabel `roles`,
  dan "pelanggan" adalah akun `users` ber-`role_id` role pelanggan.
- Relasi: `User->role`, `User->orders`, `Order->user`, `Order->shipment`,
  `Shipment->order`, `Shipment->courier_id → users`.

Karena itu, "username" dalam pencarian diartikan sebagai **email login**
(`users.email`), dan nama tampilan diambil dari `users.name`.

## 2. Cara chatbot mengenali pengguna yang sedang login

1. Sesi chat tersimpan pada `ai_conversations.user_id` — diisi dari
   **autentikasi Laravel di server** (bukan dari teks ketikan pengguna).
2. `AIService::send()` mengambil `$user = $conversation->user` lalu
   meneruskannya ke seluruh lapisan jawaban. `null` = tamu.
3. Identitas tidak pernah datang dari klaim dalam pesan: mengirim
   "saya admin" tidak mengubah `$user` yang dibaca dari session.
4. Pada kanal WhatsApp/Telegram, akun tertaut lewat `channel_identities`
   (verifikasi kode) juga menghasilkan `user_id` pada percakapan — sehingga
   jawaban identitas sama dengan di website. Percakapan kanal tanpa akun
   tertaut diperlakukan sebagai tamu.

## 3. Pertanyaan identitas diri ("siapa saya?", "sudah terdaftar?")

### Deteksi

- `ChatbotProfile::WHOAMI_PATTERN` — "siapa saya", "aku siapa", "nama akun
  saya siapa", "kamu tahu nama aku?", "akun ini atas nama siapa?", "apa nama
  saya?", "saya login menggunakan akun apa?", "login sebagai apa", typo
  ("siapa sya"), dan singkatan sehari-hari.
- `ChatbotProfile::REGISTERED_PATTERN` — "apakah saya sudah terdaftar?",
  "saya sudah terdaftar belum?", "apakah akun ini sudah terdaftar?" —
  **mensyaratkan kata ganti diri** agar pertanyaan tentang akun pelanggan
  lain jatuh ke jalur pencarian data (§5), bukan ke jalur identitas.

### Jawaban

- **Login**: `whoAmIReply(?User $user)` menyusun:
  `Nama akun Anda adalah {users.name}. Anda sedang menggunakan Agung ATK
  dengan peran {roles.name}. Saya dapat membantu menjelaskan fitur yang
  tersedia untuk {roles.name}.`
  - Fallback nama: `users.name` → `employee_code` → "Nama akun belum
    tercatat pada profil Anda." (tidak pernah mengarang nama).
  - Role tak dikenal di luar empat role → `UNVERIFIED_ROLE_REPLY`.
- **Tamu**: `GUEST_IDENTITY_REPLY` — jujur bahwa identitas belum dapat
  diverifikasi karena belum login.
- **"Apakah saya sudah terdaftar?"**: `registeredReply(?User $user)` —
  keberadaan akun + `users.is_active` dari database:
  `Ya, akun Anda terdaftar di Agung ATK atas nama {name} dengan peran
  {role}. Status akun: aktif.` (atau `nonaktif — hubungi administrator`).
- Akun nonaktif **diblokir middleware dari sesi chat (HTTP 401)**; jalur
  jawaban `nonaktif` dipakai bila akun dinonaktifkan di tengah sesi atau
  lewat kanal tertaut.

## 4. Cara chatbot mencari & menampilkan nama pelanggan

Empat intent baru pada `ChatbotDataService` (semua query Eloquent
read-only dengan parameter binding — tanpa SQL bebas):

| Intent | Pertanyaan contoh | Jawaban |
|--------|-------------------|---------|
| `customer_count` | "Berapa jumlah pelanggan yang terdaftar?", "berapa orang pelanggan yang ada?", "brp jml pelanggan terdaftar?" | `Terdapat {N} pelanggan terdaftar di sistem Agung ATK ({aktif} aktif dan {non} nonaktif).` |
| `customer_list` | "Sebutkan pelanggan yang terdaftar", "siapa saja pelanggan yang terdaftar" | Daftar nama + status (maks. 10), **tanpa email/HP** |
| `customer_search` | "Apakah pelanggan bernama Agung Gumelar ada?", "ada pelanggan namanya agung?", "agung gumelar sudah terdaftar belum?", "apakah akun pelanggan dengan username X tersedia?" | Ditemukan / tidak ditemukan / minta pembeda |
| `shipment_customer` | "Siapa nama pelanggan pesanan yang saya antar?" | Nama pelanggan penugasan aktif milik kurir, atau diminta nomor pesanan untuk staf |

`orderSummary()` (lookup `ORD-...`) kini menyertakan baris
`Pelanggan: {name}.` untuk penerima yang sudah lolos otorisasi.

### Cara kerja pencarian akun

1. **Ekstrak kata kunci**: bila pesan memuat email, email itu dipakai apa
   adanya; selain itu kata penghubung ("pelanggan", "bernama", "sudah
   terdaftar", "berapa", ...) dibuang sehingga tersisa nama/username.
2. **Query**: `WHERE role = pelanggan AND (name LIKE ? OR email LIKE ?)`
   dengan `%`/`_` milik pengguna di-escape (`addcslashes`) — wildcard tidak
   bisa disalahgunakan.
3. **Hasil**:
   - 0 → `Maaf, saya tidak menemukan akun pelanggan dengan nama tersebut.`
   - 1 → `Ya, pelanggan dengan nama {name} terdaftar di sistem Agung ATK —
     status akun {aktif|nonaktif}.` (bila kata kunci email:
     `... terdaftar atas nama {name}`).
   - >1 → **tidak menampilkan daftar**; diminta pembeda yang aman:
     `Ditemukan lebih dari satu pelanggan dengan nama serupa. Untuk
     mempersempit, sebutkan username/email atau ID akun yang berhak Anda
     akses.`
   - Kata kunci kosong → klarifikasi, bukan jawaban karangan.
4. Kecocokan nama persis (case-insensitive) diprioritaskan di antara
   kecocokan ganda.

## 5. Matriks izin per role (ditegakkan di SERVER)

`customerAccountScope(?User $user)` → `full` | `search` | `none` | `guest`:

| Aksi | Admin | Anggota | Kurir | Pelanggan | Tamu |
|------|-------|---------|-------|-----------|------|
| Jumlah pelanggan | ✅ | ✅ | ❌ | ❌ | ❌ |
| Daftar seluruh nama | ✅ | ⚠️ diarahkan ke pencarian | ❌ | ❌ | ❌ |
| Cari nama/username | ✅ | ✅ | ❌ | ❌ | ❌ |
| Nama pelanggan pesanan | via `ORD-...` | via `ORD-...` | ✅ penugasan aktif miliknya saja | pesanan miliknya sendiri | ❌ |

Penolakan baku untuk `none`: **"Maaf, Anda tidak memiliki izin untuk melihat
informasi akun tersebut."** Tamu menerima pesan untuk login lebih dahulu.
Pertanyaan "siapa pelanggan **lain** yang terdaftar?" ditangani lebih dulu
oleh `ChatGuard::PRIVACY_REFUSAL`.

## 6. Cara kerja pembaruan data akun

- **Tanpa cache nama/jumlah**: setiap pertanyaan membaca database secara
  live. Admin mengganti nama / menambah / menonaktifkan pelanggan →
  pertanyaan berikutnya langsung memakai data terbaru (terbukti oleh tes
  `test_renamed_customer_is_visible_on_next_question` dan
  `test_self_identity_shows_renamed_name_immediately`).
- Daftar nama pelanggan **tidak pernah** ditulis manual ke system prompt;
  model API menerima `user.nama` milik pengguna sendiri pada konteks
  `KONTEKS DATABASE & PENGGUNA`, sementara jawaban angka/daftar/pencarian
  disuntikkan sebagai blok `JAWABAN DATA AKTUAL` hasil query backend.
- Cache yang ada hanya untuk **snapshot skema database**
  (`chatbot.schema.snapshot`, TTL 1 jam) — bukan data akun — dan
  diinvalidasi oleh listener `MigrationsEnded` serta perintah
  `php artisan chatbot:refresh-knowledge`.

## 7. Aturan privasi & keamanan

- Pelanggan hanya mengetahui identitas & akunnya sendiri; daftar/ pencarian
  akun lain ditolak dengan sopan.
- Kurir hanya mendapat nama pelanggan pada pengiriman yang ditugaskan
  kepadanya (penugasan kurir lain tidak pernah muncul).
- Anggota tidak memperoleh daftar direktori akun (konsisten dengan konteks
  prompt yang juga tidak menyertakan `user_terbaru`).
- Admin: daftar/cari sesuai kewenangan; **email & telepon pelanggan tidak
  ditampilkan lewat chat** — cukup nama + status (detail akun ada di
  dashboard Pengguna & Pegawai).
- Password, hash, API key, token, dan isi `.env` tidak pernah muncul
  (dicek `ChatGuard::sanitizeOutput` + asersi tes `$2y$`).
- Semua query memakai binding parameter; input identitas datang dari
  session server, bukan dari ketikan.

## 8. Hasil pengujian

- `tests/Feature/ChatbotUserIdentityTest.php`: **25/25 tes lulus**
  (skenario 1–8 + variasi bahasa/typo + privasi + kesegaran data).
- **Suite penuh: 645/645 tes lulus (3.050 asersi)** — tanpa regresi
  (sebelumnya 620/620; +25 tes baru).
- Pint (`vendor/bin/pint --dirty`): lulus.

Cakupan skenario wajib:

| # | Skenario | Tes |
|---|----------|-----|
| 1 | "Siapa saya?" → nama akun aktual | `test_siapa_saya_shows_actual_account_name_and_role_per_role`, `test_identity_variations_and_typo_are_understood` |
| 2 | "Apakah saya sudah terdaftar?" → database | `test_registered_question_verified_against_database`, `test_inactive_account_is_blocked_from_chat_and_reported_honestly`, `test_registered_question_as_guest_is_honest` |
| 3 | Jumlah pelanggan aktual per izin | `test_customer_count_is_actual_for_admin_and_anggota`, `test_customer_count_denied_for_courier_and_customer`, `test_customer_count_as_guest_requires_login` |
| 4 | Pencarian nama ada/tidak ada/ganda | `test_admin_finds_customer_by_name`, `test_search_reports_honestly_when_customer_not_found`, `test_multiple_name_matches_ask_for_safe_differentiator`, `test_search_by_username_email_works`, `test_anggota_can_search_specific_customer_name`, `test_search_without_keyword_asks_for_clarification` |
| 5 | Privasi pelanggan lain | `test_customer_asking_for_other_customers_gets_privacy_refusal`, `test_customer_list_search_denied_for_customer_and_courier`, `test_anggota_gets_search_hint_instead_of_full_customer_list`, `test_admin_list_contains_names_without_emails` |
| 6 | Kurir hanya penugasan sendiri | `test_courier_gets_customer_name_only_for_assigned_shipment`, `test_courier_without_assignment_gets_honest_empty_state`, `test_staff_are_asked_for_order_number_instead_of_global_lookup`, `test_order_lookup_shows_customer_name_for_authorized_role` |
| 7 | Perubahan nama langsung terbaca | `test_renamed_customer_is_visible_on_next_question`, `test_self_identity_shows_renamed_name_immediately` |
| 8 | Tamu belum login | `test_guest_identity_cannot_be_verified` + tes tamu pada tiap intent |

## 9. Batasan yang diakui jujur

- Kolom `username` tidak ada di database; pencarian "username" memakai
  `users.email`. Menambahkan kolom username terpisah adalah keputusan
  desain basis data yang belum ada di aplikasi.
- Pencarian nama bebas (substring) — "agung" cocok dengan semua nama yang
  mengandung "agung"; kecocokan ganda meminta pembeda, bukan menebak.
- Chatbot hanya MENAMPILKAN data; mengubah data akun tetap lewat fitur
  bisnis resmi (validasi + otorisasi + konfirmasi), bukan lewat percakapan.
