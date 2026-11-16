# ERD — AGUNG ATK

Sumber: struktur tabel **aktual MySQL** (`information_schema`, database `agung_atk`, 25 tabel) — konsisten dengan migration & model Laravel.
Legenda: **(PK)** primary key, **(FK)** foreign key, `?` = nullable.

---

## 1. ERD Inti Bisnis (Mermaid)

```mermaid
erDiagram
    roles ||--o{ users : "memiliki"
    users ||--o{ addresses : "memiliki"
    users ||--o{ orders : "membuat"
    users ||--o{ reviews : "menulis"
    users ||--o{ activity_logs : "mencatat aksi"
    users ||--o{ channel_identities : "menautkan kanal"
    users ||--o{ ai_conversations : "memiliki percakapan"
    users ||--o{ shipments : "menjadi kurir"
    users ||--o{ payments : "memverifikasi"
    categories ||--o{ products : "berisi"
    products ||--o{ product_images : "memiliki gambar"
    products ||--o{ order_details : "dipesan"
    products ||--o{ reviews : "dinilai"
    orders ||--|| addresses : "dikirim ke"
    orders ||--o{ order_details : "memuat"
    orders ||--o| payments : "dibayar"
    orders ||--o| shipments : "dikirim"
    orders ||--o{ reviews : "menghasilkan ulasan"
    ai_conversations ||--o{ ai_messages : "memuat pesan"

    roles {
        bigint id PK
        varchar name
        varchar label
        text description
    }
    users {
        bigint id PK
        bigint role_id FK
        varchar employee_code
        varchar position
        varchar employment_status
        date hired_at
        varchar name
        varchar email
        varchar phone
        varchar avatar
        bool is_active
        varchar password
    }
    categories {
        bigint id PK
        varchar name
        varchar slug
        text description
        varchar image
    }
    products {
        bigint id PK
        bigint category_id FK
        varchar name
        varchar brand
        varchar slug
        varchar sku
        varchar barcode
        decimal price
        decimal cost_price
        int stock
        varchar unit
        int min_stock
        bool is_active
    }
    orders {
        bigint id PK
        varchar order_number
        bigint user_id FK
        bigint address_id FK
        decimal total_amount
        decimal shipping_cost
        decimal discount_amount
        decimal grand_total
        varchar status
        text notes
        datetime completed_at
    }
    order_details {
        bigint id PK
        bigint order_id FK
        bigint product_id FK
        varchar product_name
        decimal price
        int quantity
        decimal subtotal
    }
    payments {
        bigint id PK
        bigint order_id FK
        varchar payment_number
        varchar method
        decimal amount
        varchar status
        varchar proof_image
        bigint verified_by FK
        text rejection_reason
    }
    shipments {
        bigint id PK
        bigint order_id FK
        bigint courier_id FK
        varchar tracking_number
        varchar courier_name
        varchar status
        datetime shipped_at
        datetime delivered_at
    }
```

---

## 2. ERD Pendukung (audit, notifikasi, AI, kanal, ulasan, alamat)

```mermaid
erDiagram
    users ||--o{ addresses : "punya"
    users ||--o{ reviews : "beri ulasan"
    orders ||--o{ reviews : "untuk pesanan"
    products ||--o{ reviews : "untuk produk"
    orders ||--o{ notification_logs : "terkait"
    users ||--o{ activity_logs : "pelaku"
    users ||--o{ ai_conversations : "pemilik"
    ai_conversations ||--o{ ai_messages : "riwayat"
    channel_identities }o--|| users : "tertaut ke akun"

    addresses {
        bigint id PK
        bigint user_id FK
        varchar recipient_name
        varchar phone
        text full_address
        varchar city
        varchar postal_code
        bool is_primary
    }
    reviews {
        bigint id PK
        bigint order_id FK
        bigint product_id FK
        bigint user_id FK
        tinyint rating
        text comment
        bool is_approved
    }
    activity_logs {
        bigint id PK
        bigint user_id FK
        varchar activity_type
        varchar subject_type
        bigint subject_id
        text summary
        timestamp created_at
    }
    notification_logs {
        bigint id PK
        varchar event_key
        varchar channel
        varchar recipient
        varchar subject
        text body
        varchar status
        text error
        bigint order_id FK
        timestamp sent_at
    }
    notification_settings {
        bigint id PK
        varchar group
        varchar key
        bool enabled
    }
    channel_identities {
        bigint id PK
        bigint user_id FK
        varchar channel
        varchar identifier
        varchar status
        varchar verification_code
        timestamp code_expires_at
        timestamp verified_at
    }
    ai_conversations {
        bigint id PK
        bigint user_id FK
        varchar session_id
        varchar title
    }
    ai_messages {
        bigint id PK
        bigint ai_conversation_id FK
        varchar role
        longtext content
        int tokens_used
    }
    product_images {
        bigint id PK
        bigint product_id FK
        varchar image_path
        bool is_primary
        int sort_order
    }
```

---

## 3. Tabel Infrastruktur (bukan domain bisnis)

| Tabel | Peran |
|---|---|
| `migrations` | jejak migrasi |
| `sessions` | sesi login (driver database) |
| `cache`, `cache_locks` | cache aplikasi |
| `jobs`, `failed_jobs`, `job_batches` | antrean & retry notifikasi/balasan |
| `password_reset_tokens` | token reset (alur admin) |

---

## 4. Cardinality & Keterangan Relasi

| Relasi | Tipe | Keterangan |
|---|---|---|
| roles → users | 1 : N | 4 peran (admin, anggota, kurir, pelanggan) |
| users → orders | 1 : N | satu pembeli banyak pesanan |
| orders → order_details | 1 : N | baris item (snapshot `product_name`/`price`) |
| products → order_details | 1 : N | produk banyak dipesan → **junction many-to-many orders ↔ products** |
| orders → payments | 1 : 0..1 | 1 order minimal 1 bukti bayar (opsi verifikasi ulang) |
| orders → shipments | 1 : 0..1 | kirim internal (satu paket per pesanan) |
| users(courier) → shipments | 1 : N | satu kurir banyak tugas |
| orders → reviews | 1 : N | ulasan per item pesanan |
| users ↔ products (via reviews) | N : M | banyak pembeli menilai banyak produk |
| categories → products | 1 : N | klasifikasi ATK |
| products → product_images | 1 : N | satu gambar utama (`is_primary`) |
| users → channel_identities | 1 : N | tautan WA/TG (unik per `channel`+`identifier`) |
| users → ai_conversations → ai_messages | 1 : N : N | riwayat chat (tamu memakai `session_id` tanpa `user_id`) |
| users → activity_logs | 1 : N | jejak audit |
| orders → notification_logs | 1 : N | jejak notifikasi per kejadian |

**Konsistensi:** seluruh FK di atas tercatat pada migration `database/migrations/*` dan direlasikan di model Eloquent (terverifikasi lewat `ModelRelationTest`/`RelationshipTest`).
