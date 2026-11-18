# Pemetaan Database Chatbot AGUNG ATK

Dokumen ini memetakan setiap maksud (intent) chatbot ke tabel, kolom, dan
query backend-nya — sebagai bukti bahwa chatbot **membaca data aktual dari
database**, bukan angka hardcode/dummy.

Prinsip yang berlaku di seluruh pemetaan ini:

- **Read-only** — chatbot hanya pernah menulis ke tabel chat itu sendiri
  (`ai_conversations`, `ai_messages`) dan log intent; tidak pernah
  mengubah data bisnis.
- **Tanpa SQL bebas** — semua query adalah Eloquent dengan nama
  tabel/kolom tetap di kode (`app/Support/ChatbotDataService.php`);
  nilai dari pesan pengguna selalu masuk lewat *parameter binding*
  (`where`, `whereHas`, `withCount`) — tidak pernah string SQL mentah.
- **Batas hasil** — daftar selalu dibatasi (`forPage`/`take`,
  page size 10 untuk katalog/daftar, 10 untuk list operasional) dan
  diberi penanda halaman bila total >1 halaman.
- **Otorisasi di server** — cakupan query ditentukan role NYATA dari
  session (`isStaff` / `isAdmin` / `customerAccountScope`), bukan klaim
  di dalam percakapan.

---

## 1. Tabel yang Dibaca Chatbot

Database aplikasi memiliki 25 tabel (audit TASK12). Chatbot membaca
sebagian kecil darinya:

| Model Eloquent | Tabel | Kolom yang dipakai chatbot |
|---|---|---|
| `Product` | `products` | `id`, `category_id`, `name`, `brand`, `sku`, `barcode`, `price`, `stock`, `unit`, `min_stock`, `is_active`, `created_at` |
| `Category` | `categories` | `id`, `name` (+ `products_count` via `withCount`) |
| `User` | `users` | `id`, `name`, `role_id`, `is_active`, `created_at` |
| `Role` | `roles` | `id`, `name`, `label` |
| `Order` | `orders` | `id`, `order_number`, `user_id`, `status`, `grand_total`, `created_at` |
| `OrderDetail` | `order_details` | `order_id`, `product_id`/`product_name`, `quantity`, `subtotal` |
| `Payment` | `payments` | `id`, `order_id`, `payment_number`, `status`, `amount`, `verified_at`, `rejection_reason` |
| `Shipment` | `shipments` | `id`, `order_id`, `tracking_number`, `courier_name`, `status`, `shipped_at`, `delivered_at` |
| `ActivityLog` | `activity_logs` | `id`, `user_id`, `role`, `activity_type`, `summary`, `created_at` |

Tabel yang TIDAK disentuh chatbot: `addresses`, `product_images`,
`reviews`, `carts`/keranjang, `notification_*`, `channel_identities`,
`cache`, `jobs`, dsb. — kecuali introspeksi skema untuk prompt AI (lihat §4).

---

## 2. Peta Intent → Query → Hak Akses

### 2.1 Publik (tamu pun boleh — tidak ada data pribadi)

| Intent | Contoh pertanyaan | Query (ringkas) | Hasil |
|---|---|---|---|
| `product_catalog` | "Berikan saya katalog produk yang tersedia." | `Product::where('is_active', true)->with('category')->orderBy('name')->forPage($page, 10)` | Nama, kategori, harga, stok, status per produk + `COUNT` total |
| `product_count` | "Berapa jumlah produk?" | `Product::count()` (+ `where('is_active', true)->count()`) | Jumlah produk & jumlah aktif |
| `total_stock` | "Berapa jumlah seluruh barang?" | `SUM(stock)`, `COUNT` produk, `COUNT` stok ≤0, `COUNT` stok ≤ `min_stock` | Total unit stok + ringkasan |
| `price_extremes` | "Produk apa yang paling murah?" | `Product::where('is_active', true)->orderBy('price', asc/desc)->take(3)` | 3 termurah / termahal |
| `product_detail` | "Berapa stok pulpen?" / "harga buku tulis?" | `Product::where('is_active', true)` + `where('name', 'like', %kata%)` per token (di-escape `addcslashes('%_')`) | Detail 1 produk / daftar kandidat bila ambigu |
| `products_by_category` | "Produk kategori buku?" / follow-up "kalau kategori X?" | `Category::where('name', 'like', ...)` lalu `Product::where('category_id', ...)->forPage($page, 10)` | Daftar produk satu kategori |
| `category_list` | "Apa saja kategori?" | `Category::withCount(['products' => ...->where('is_active', true)])` | Nama kategori + jumlah produk aktif |
| `top_stock` | "Stok terbanyak?" | `Product::where('is_active', true)->orderByDesc('stock')->forPage($page, 10)` | 10 stok terbesar |
| `low_stock` | "Produk yang stoknya hampir habis?" | `where('is_active', true)->where(stock <= min_stock OR stock = 0)->orderBy('stock')->forPage($page, 10)` | Daftar menipis |
| `out_of_stock` | "Stok yang habis?" | `where('stock', '<=', 0)->orderBy('name')->forPage($page, 10)` | Daftar habis |
| `best_selling` | "Produk paling laris?" | `OrderDetail` join `orders` (status penjualan) → `SUM(quantity)` group by produk → `take(10)` | Peringkat terjual |
| `newest_products` | "Produk terbaru?" | `Product::latest('id')->forPage($page, 10)` | 10 terbaru |

### 2.2 Wajib login — data milik sendiri

| Intent | Contoh | Query | Scope |
|---|---|---|---|
| `payment_pending` | "Pembayaran saya belum dikonfirmasi?" | `Payment` milik user + `where('status', 'pending')` | Hanya `user_id` session |
| `order_lookup` | "Pesanan saya sudah diproses?" | `Order` milik user → `payments` & `shipments` terkait | Hanya milik sendiri |
| `tracking_lookup` | "Resi JK123456" | `Shipment::where('tracking_number', ...)` | Milik sendiri (staf: semua) |
| `my_shipment` | "Pengiriman saya" | `Shipment` yang ditugaskan ke kurir login | Kurir (tugasnya sendiri) |
| `shipment_customer` | "Siapa pelanggan pesanan yang saya antar?" | `Order::with('user')` dari pengiriman milik kurir | Kurir |

### 2.3 Staf (admin & anggota) — operasional toko

| Intent | Contoh | Query | Gate |
|---|---|---|---|
| `revenue` | "Berapa pendapatan bulan ini?" | `Order::whereIn('status', SALES_STATUSES)->whereBetween('created_at', rentang)->sum('grand_total')` | `isStaff` |
| `orders_count` | "Berapa jumlah pesanan?" | `Order::count()` + `whereBetween` bila ada rentang | `isStaff` |
| `orders_by_status` | "Berapa pesanan selesai?" | `Order::where('status', ...)->count()` + `take(10)` contoh terbaru | `isStaff` |
| `payment_status_counts` | "Pembayaran berhasil berapa?" | `Payment::selectRaw('status, COUNT(*)')->groupBy('status')` | `isStaff` |
| `transaction_summary` | "Ringkasan transaksi." | `Order::count()`, group by status, `sum(grand_total)` berbayar, hitung hari ini/bulan ini | `isStaff` |
| `app_stats` | "Status aplikasi." | `COUNT` produk/kategori/users/orders/payments/shipments | `isStaff` |
| `newest_orders` | "Pesanan terbaru." | `Order::latest('id')->forPage($page, 10)` | `isStaff` |
| `pending_payments` | "Pembayaran belum diverifikasi." | `Payment::where('status', 'pending')` + range | `isStaff` |
| `users_by_role` | "Berapa jumlah pengguna berdasarkan role?" | `User::count()` + join `roles` group by role | `isStaff` |
| `customer_count` | "Berapa jumlah pelanggan?" | `customerQuery()` (role = `pelanggan`) → `count()` | `isStaff` |
| `recent_activities` | "Apa aktivitas terbaru?" | `ActivityLog::latest('id')->take(10)` (ringkasan aman, tanpa kredensial) | **admin saja** |
| `newest_users` | "Pengguna terbaru." | `User::latest('id')->forPage($page, 10)` | **admin saja** |

### 2.4 Pelanggan — daftar & riwayat (scope `customerAccountScope`)

| Intent | Contoh | Query | Scope |
|---|---|---|---|
| `customer_list` | "Siapa saja pelanggan?" | `customerQuery()->orderBy('name')->forPage($page, 10)` | admin: seluruh daftar; anggota: hanya arahan pencarian; lainnya: pesan login/ditolak |
| `customer_history` | "Riwayat pembelian pelanggan." | `customerQuery()->withCount('orders')->withMax('orders as last_order_at', 'created_at')->orderByDesc('orders_count')` | sama seperti daftar |
| `customers_without_orders` | "Pelanggan yang belum pernah transaksi." | `customerQuery()->whereNotIn('id', Order::select('user_id'))` | sama seperti daftar |
| `newest_customers` | "Siapa pelanggan terbaru?" | `customerQuery()->latest('id')->forPage($page, 10)` | sama seperti daftar |
| `customer_search` | "Pelanggan bernama Budi ada?" | `customerQuery()->where(name/username like ...)` | anggota boleh cari nama tertentu |

`customerQuery()` = `User::whereHas('role', fn ($q) => $q->where('name', 'pelanggan'))`
— hanya akun berperan pelanggan; tidak pernah mengekspos email/HP.

---

## 3. Normalisasi & Sinonim Bahasa

Sebelum intent dideteksi, pesan dinormalisasi (`ChatbotDataService::normalize`):

| Bentuk asli | Setelah normalisasi | Alasan |
|---|---|---|
| "pelanggannya", "stoknya", "harganya", "kategorinya" | "pelanggan", "stok", "harga", "kategori" | Akhiran `-nya` memecah `\b` pada regex lama (akar masalah B) |
| "produkku", "stokku" | "produk", "stok" | Sufiks `-ku` (dasar ≥4 huruf; "buku/kuku/menu" aman) |
| "pesananku", "pesananmu" | "pesanan saya" | Kepemilikan tetap terbaca sebagai milik sendiri |
| "semuanya" | "semua" | Menyeragamkan penegas daftar |

Sinonim yang ditambahkan pada matcher: **`pembeli` ≡ `pelanggan` ≡ `customer`**
(mis. "Siapa saja pembelinya?" → intent `customer_list`).

---

## 4. Konteks Percakapan (bukan tabel)

Konteks antar-pesan **tidak** disimpan di database — disimpan di **cache**
dengan kunci per percakapan:

```
Key   : chatbot.context.{conversationId}
Isi   : { intent, page, entity, ts }
TTL   : 30 menit
Store : default aplikasi (phpunit → array, terisolasi per tes)
```

- Kunci `conversationId` berasal dari `AiConversation` milik user tsb.
  → konteks **tidak pernah tercampur antar-user** (tiap user punya
  percakapan & kunci sendiri).
- Dipakai untuk: "lanjut" (halaman berikut), "kalau kategori X?",
  "stoknya/harganya?" (merujuk objek sebelumnya).
- Ditulis ulang hanya bila pesan menghasilkan intent data; pesan obrolan
  biasa tidak merusak topik.

Untuk prompt AI (`systemPrompt`), konteks yang sama dikirim sebagai blok
`KONTEKS PERCAKAPAN SEBELUMNYA` (JSON) — penegasan bahwa blok tersebut
bukan instruksi (aturan 23b).

---

## 5. Skema Database untuk Prompt AI

`DatabaseKnowledgeService` membaca daftar tabel & kolom lewat introspeksi
schema (`Schema::getTableListing` / `getColumns`) — **read-only**, hanya
metadata (nama tabel/kolom, bukan isi baris) — lalu menyusun blok
`KONTEKS DATABASE & PENGGUNA` pada system prompt agar AI menjawab
pertanyaan pemrograman/SQL dengan nama struktur yang benar.

Data yang dikirim ke AI selalu sudah difilter sesuai peran (mis. pelanggan
tidak mendapat daftar pengguna internal).

---

## 6. Bukti Angka Aktual (tanpa hardcode)

- Semua angka pada jawaban dihitung saat pesan diterima
  (`count`, `sum`, `groupBy`) — tidak ada angka yang ditulis di kode.
- Tes `test_product_count_is_counted_live` &
  `test_newly_added_product_is_visible_without_retraining`
  membuktikan produk baru langsung terlihat tanpa pelatihan ulang.
- Format rupiah selalu `number_format(..., 0, ',', '.')` (mis. `Rp 12.500`).
- Query gagal (mis. tabel hilang) → pesan jujur "data belum berhasil
  diperiksa…" — bukan angka karangan (tes `test_database_failure_...`).
