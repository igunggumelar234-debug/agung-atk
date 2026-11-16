# ACTIVITY DIAGRAM — AGUNG ATK

Semua alur di bawah mengikuti kode aktual (controller/service disebut sebagai catatan).

---

## 1. Login & Deteksi Role

```mermaid
flowchart TD
    A([Mulai: pengguna buka /login]) --> B[Isi email + kata sandi]
    B --> C{Rate limit throttle:login<br/>5/menit per email+IP?}
    C -- lewat --> D[AuthController@login:<br/>verifikasi hash password]
    C -- melebihi --> E[Respons 429: terlalu banyak percobaan] --> Z([Selesai])
    D -- gagal --> F[Pesan: kredensial salah] --> B
    D -- berhasil --> G{is_active?}
    G -- false --> H[EnsureAccountIsActive:<br/>tolak + pesan akun nonaktif] --> Z
    G -- true --> I[redirectBasedOnRole]
    I --> J{role.name}
    J -- admin --> K[admin/dashboard]
    J -- anggota --> L[anggota/dashboard]
    J -- kurir --> M[kurir/dashboard]
    J -- pelanggan --> N[pelanggan/dashboard]
    K & L & M & N --> O[Catat sesi ke tabel sessions] --> Z([Selesai]))
```

---

## 2. Pengelolaan Produk (Admin/Anggota)

```mermaid
flowchart TD
    A([Mulai: buka /admin/products]) --> B{Middleware role?}
    B -- bukan admin/anggota --> X[403 Forbidden] --> Z([Selesai])
    B -- lolos --> C[Pilih aksi]
    C -->|Tambah| D[Form produk + gambar]
    C -->|Ubah| E[Form edit]
    C -->|Hapus/nonaktif| F[Set is_active = 0]
    D & E --> G[Validasi server-side:<br/>nama, slug unik, sku, harga > 0,<br/>stok >= 0, kategori ada]
    G -- gagal --> H[Kembali ke form + error per field] --> D
    G -- lolos --> I[Transaksi DB:<br/>simpan produk / product_images]
    I --> J[Tulis activity_logs<br/>(aktor, aksi, objek)]
    J --> K[Redirect + flash sukses] --> Z
    F --> J
```

---

## 3. Pencarian Produk — Teks & Gambar

```mermaid
flowchart TD
    A([Mulai]) --> B{Mode pencarian}
    B -- teks --> C[GET /products?q=...]
    C --> D[Eloquent: scopeActive + LIKE<br/>nama/brand/sku, limit halaman]
    D --> E[Tampilkan kartu produk] --> Z([Selesai])

    B -- gambar/kamera --> F[Unggah foto<br/>maks 2048 KB, tipe gambar]
    F --> G[ATKImageRecognitionService<br/>driver = hybrid]
    G --> H{Layanan YOLO hidup?<br/>POST /detect :8090}
    H -- ya --> I[YOLOv8 deteksi objek:<br/>kelas + confidence + margin]
    H -- timeout/gagal --> J[Fallback pengenalan bawaan<br/>fitur tetap berfungsi]
    I --> K{confidence >= 0.80<br/>dan margin cukup?}
    K -- tidak --> L[Hasil uncertain:<br/>tampilkan jujur tanpa mengarang] --> Z
    K -- ya --> J
    J --> M{Kelas cocok<br/>dengan produk katalog?}
    M -- bukan ATK --> N[Pesan baku:<br/>Barang Tidak Sesuai Kriteria Toko] --> Z
    M -- cocok & stock = 0 --> O[Pesan baku: Stok Kosong] --> Z
    M -- cocok & stok ada --> P[Tampilkan saran produk<br/>(nama, harga, stok, tautan)] --> Z
```

---

## 4. Chatbot AI (alur satu pesan)

```mermaid
flowchart TD
    A([Pesan masuk:<br/>widget /ai atau webhook kanal]) --> B[Simpan pesan user<br/>ai_messages]
    B --> C{Webhook kanal?}
    C -- ya --> D[Validasi secret/HMAC + dedupe update]
    D -- gagal --> E[HTTP 401/403 fail-closed] --> Z([Selesai])
    D -- lolos --> F[Identitas masuk:<br/>ChannelIdentityService]
    C -- tidak --> F
    F --> G[ChatGuard INPUT:<br/>pola privasi/rahasia/injeksi?]
    G -- cocok --> H[Respons tetap penolakan<br/>model TIDAK dipanggil] --> S
    G -- bersih --> I{Perintah slash /menu /stok<br/>/produk /pesanan /lacak?}
    I -- ya --> J[Dijawab lokal deterministik<br/>bahasa perintah] --> S
    I -- tidak --> K{Intent data read-only?<br/>ChatbotDataService}
    K -- ya --> L[Query Eloquent ber-scope role<br/>LIMIT/agregasi, tanpa SQL bebas]
    L --> M[Jawaban angka nyata] --> S
    K -- tidak --> N{API AI tersedia?<br/>AI_API_KEY valid}
    N -- ya --> O[Panggil LLM:<br/>system prompt (identitas, bahasa,<br/>peran, aturan data) + konteks JSON]
    N -- tidak/tidak valid --> P[Fallback lokal jujur:<br/>tak mengarang informasi] --> S
    O --> Q[ChatGuard OUTPUT:<br/>saring kebocoran sistem/rahasia]
    Q --> R[Sanitasi + simpan balasan] --> S
    P --> R
    S[Tampilkan balasan<br/>+ catat notification/log bila via kanal] --> Z
```

---

## 5. Pemesanan (Checkout)

```mermaid
flowchart TD
    A([Pelanggan klik Checkout]) --> B[CustomerMiddleware: login + aktif]
    B --> C[Keranjang + alamat terpilih]
    C --> D{Validasi server:<br/>barang ada, stok cukup, alamat valid?}
    D -- gagal --> E[Flash error, kembali] --> C
    D -- lolos --> F[BEGIN TRANSAKSI]
    F --> G[Buat orders:<br/>order_number, grand_total, status pending]
    G --> H[Buat order_details per item<br/>+ snapshot nama & harga]
    H --> I[UPDATE products.stock<br/>stok - quantity]
    I --> J{Semua stok cukup?}
    J -- tidak --> K[ROLLBACK transaksi<br/>pesan stok tidak cukup] --> C
    J -- ya --> L[COMMIT]
    L --> M[event order.created<br/>→ NotificationManager]
    M --> N[Redirect /orders + flash sukses] --> Z([Selesai])
```

---

## 6. Pembayaran & Verifikasi

```mermaid
flowchart TD
    A([Pelanggan unggah bukti]) --> B[Validasi file gambar + ukuran]
    B -- gagal --> C[Flash error] --> A
    B -- lolos --> D[Buat/ubah payments:<br/>status pending, proof_image]
    D --> E[Notifikasi: pembayaran menunggu verifikasi]
    E --> F([Menunggu admin])

    F --> G([Admin buka /admin/payments])
    G --> H{Keputusan}
    H -- Verifikasi --> I[status = verified<br/>verified_by = admin id<br/>paid_at terisi]
    H -- Tolak --> J[status = rejected<br/>rejection_reason wajib]
    I --> K[orders.status → processing/paid]
    J --> L[Notifikasi penolakan<br/>→ pelanggan unggah ulang]
    K --> M[event payment.verified<br/>→ notifikasi pelanggan & admin] --> Z([Selesai])
    L --> Z
```

---

## 7. Pemrosesan Pesanan → Penugasan Kurir → Pengiriman

```mermaid
flowchart TD
    A([Pesanan verified]) --> B[Anggota/Admin memproses:<br/>stok siap, pesanan disiapkan]
    B --> C[Admin membuka pesanan<br/>/admin/orders/id]
    C --> D{Kurir aktif tersedia?}
    D -- tidak --> E[Flash: belum ada kurir] --> C
    D -- ya --> F[Buat shipments:<br/>courier_id, tracking_number,<br/>status waiting]
    F --> G[orders.status → shipping<br/>+ notifikasi kurir & pelanggan]
    G --> H([Kurir login /courier/shipments]))
    H --> I[Hanya melihat shipment<br/>courier_id = dirinya]
    I --> J[Update status: shipped<br/>shipped_at terisi]
    J --> K[Sampai tujuan → delivered<br/>delivered_at + bukti serah]
    K --> L[orders.status → completed<br/>completed_at terisi]
    L --> M[event shipment.delivered<br/>→ notifikasi pelanggan & admin] --> Z([Selesai])
```

---

## 8. Notifikasi Otomatis (satu kejadian)

```mermaid
flowchart TD
    A([Kejadian bisnis<br/>order.created / payment.verified /<br/>shipment.delivered / ringkasan 07:00]) --> B[NotificationManager::dispatch]
    B --> C[Tentukan penerima per role:<br/>admin → kontak admin,<br/>pelanggan → akunnya,<br/>anggota → seluruh pegawai aktif,<br/>kurir → tugasnya]
    C --> D{Saklar kanal aktif?<br/>TELEGRAM_ENABLED / WHATSAPP_ENABLED / email}
    D -- tidak --> E[Lewati kanal, aman]
    D -- ya --> F{Sudah pernah dikirim?<br/>dedupe window + recentlyNotified}
    F -- ya --> E
    F -- tidak --> G[Tulis notification_logs: pending]
    G --> H[NOTIFICATIONS_QUEUE?<br/>ya → Queue job<br/>tidak → sinkron]
    H --> I[Kirim via:<br/>Telegram Bot API /<br/>SMTP Mailpit /<br/>WhatsApp Cloud API]
    I -- sukses --> J[status = sent, sent_at terisi]
    I -- gagal --> K[status = failed + error<br/>retry 3× backoff 10s/30s]
    J & K --> Z([Selesai])
```

---

## 9. Tautan Akun Kanal (WA/TG → Akun Login)

```mermaid
flowchart TD
    A([Pengguna minta kode di Profil]) --> B[POST /profile/channels/channel/code<br/>throttle 6/menit]
    B --> C[Buat kode 6 digit<br/>code_expires_at = +10 menit]
    C --> D[Kirim kode via kanal]
    D --> E([Pengguna kirim kode ke bot])
    E --> F{Kode cocok & belum kedaluwarsa?<br/>+ anti brute-force 5 gagal = kunci 15 menit}
    F -- tidak --> G[Penolakan jelas] --> Z([Selesai])
    F -- ya --> H{Identifier sudah diklaim<br/>akun lain?}
    H -- ya --> I[Tolak: sudah tertaut] --> Z
    H -- tidak --> J[status = verified<br/>verified_at terisi]
    J --> K[Percakapan berikutnya:<br/>identity = akun user aktif,<br/>bukan tamu] --> Z
```
