<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Pengetahuan struktur database Agung ATK untuk chatbot — READ-ONLY.
 *
 * Sumber kebenaran (tanpa mengarang):
 * 1. Introspeksi skema database aktif (Schema::getTableListing / getColumns /
 *    getIndexes / getForeignKeys) untuk SELURUH tabel domain — tabel baru
 *    otomatis dikenali tanpa mengubah kode (tabel framework dikecualikan).
 *    Hasilnya di-cache lintas request (SCHEMA_CACHE_KEY) dan disegarkan saat
 *    migrasi selesai (listener MigrationsEnded) atau via
 *    `php artisan chatbot:refresh-knowledge`.
 * 2. Fakta kurasi (fungsi tabel, alur status, aturan bisnis) yang diverifikasi
 *    dari migrasi + kode aplikasi — didokumentasikan di
 *    docs/CHATBOT_DATABASE_KNOWLEDGE.md.
 *
 * Batasan keamanan (PROMPT bagian E):
 * - Tidak PERNAH mengeksekusi SQL yang dibuat model/pengguna.
 * - Hanya mengembalikan struktur (nama tabel, kolom, tipe, PK, FK) — bukan
 *   isi tabel; data operasional disuntikkan AIService terpisah per hak akses.
 * - Tabel framework Laravel hanya disebut namanya, tanpa detail kolom.
 */
class DatabaseKnowledgeService
{
    /**
     * Kunci cache pengetahuan skema (lintas request).
     *
     * Disegarkan otomatis oleh listener MigrationsEnded setelah
     * `php artisan migrate`, dan manual melalui
     * `php artisan chatbot:refresh-knowledge`.
     */
    public const SCHEMA_CACHE_KEY = 'chatbot.schema.snapshot';

    /**
     * Tabel bawaan framework Laravel yang ikut ada di database (hanya nama).
     *
     * @var list<string>
     */
    public const FRAMEWORK_TABLES = [
        'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
        'jobs', 'failed_jobs', 'job_batches', 'migrations',
    ];

    /**
     * Fungsi setiap tabel (kurasi, diverifikasi terhadap migrasi + kode).
     *
     * @var array<string, string>
     */
    public const TABLE_PURPOSES = [
        'roles' => 'Daftar peran pengguna (admin, anggota, kurir, pelanggan) beserta label dan deskripsinya.',
        'users' => 'Akun pengguna: pelanggan, anggota toko, kurir, dan admin. Menyimpan nama, email, telepon, status aktif, serta identitas pegawai (employee_code, jabatan) untuk karyawan internal.',
        'categories' => 'Kategori produk ATK (mis. Kertas, Tulis, Arsip) untuk mengelompokkan katalog.',
        'products' => 'Katalog produk ATK: nama, merek, SKU, barcode, harga jual & modal, stok, satuan, batas stok minimum, dan status aktif.',
        'product_images' => 'Foto produk; satu foto utama (is_primary) plus foto pendukung dengan urutan tampil.',
        'addresses' => 'Alamat pengiriman milik pengguna (penerima, telepon, alamat lengkap, kota, kecamatan) — bisa lebih dari satu per pengguna.',
        'orders' => 'Pesanan pembelian: nomor pesanan unik, total, ongkir, diskon, grand total, status, dan alasan/waktu pembatalan.',
        'order_details' => 'Isi per baris pesanan: produk yang dipesan beserta nama & harga SNAPSHOT saat checkout, kuantitas, dan subtotal.',
        'payments' => 'Pembayaran pesanan (satu baris per pesanan): metode (transfer/qris/cod), jumlah, bukti, status verifikasi admin.',
        'shipments' => 'Pengiriman pesanan (satu baris per pesanan): resi, kurir penanggung jawab, status kirim, dan waktu kirim/terima.',
        'reviews' => 'Ulasan produk oleh pembeli (rating 1-5 + komentar) yang terikat pada pesanan; satu ulasan per (pesanan, produk, pengguna).',
        'ai_conversations' => 'Sesi percakapan chatbot AI: milik akun pengguna, atau sesi browser (session_id) bila tamu belum login.',
        'ai_messages' => 'Isi pesan dalam sesi chatbot (role: user/assistant/system) plus perkiraan tokens yang dipakai.',
        'notification_settings' => 'Saklar pengaturan notifikasi per saluran (channel) dan per kejadian (event).',
        'notification_logs' => 'Riwayat pengiriman notifikasi (audit trail): kejadian, saluran, penerima, isi pesan, status kirim.',
        'activity_logs' => 'Jejak audit aktivitas sistem: siapa (user + peran), apa (jenis aktivitas), objek (subject morph), kapan, dan ringkasan aman.',
        'channel_identities' => 'Tautan akun chatbot (WhatsApp/Telegram) ke pengguna: kanal, identifier, status verifikasi, kode & masa berlaku. Menyimpan identitas kanal — BUKAN kredensial bot.',
    ];

    /**
     * Status pesanan yang sah (diverifikasi dari kode: OrderController,
     * Seller/OrderController, Courier/ShipmentController, OrderObserver,
     * OrderCancellationService).
     *
     * @var list<string>
     */
    public const ORDER_STATUSES = [
        'pending', 'paid', 'processing', 'ready_to_ship',
        'shipping', 'delivered', 'completed', 'cancelled',
    ];

    /**
     * Status pembayaran: divalidasi saat admin memverifikasi.
     *
     * @var list<string>
     */
    public const PAYMENT_STATUSES = ['pending', 'verified', 'rejected'];

    /**
     * Metode pembayaran yang didukung (diverifikasi dari form & data).
     *
     * @var list<string>
     */
    public const PAYMENT_METHODS = ['transfer', 'qris', 'cod'];

    /**
     * Status pengiriman (App\Support\ShipmentStatus).
     *
     * @var list<string>
     */
    public const SHIPMENT_STATUSES = ['waiting', 'picked_up', 'shipping', 'delivered'];

    /**
     * Alias nama Indonesia → nama tabel (untuk mencocokkan pesan pengguna).
     *
     * @var array<string, string>
     */
    private const TABLE_ALIASES = [
        'produk' => 'products', 'product' => 'products',
        'kategori' => 'categories', 'category' => 'categories',
        'pengguna' => 'users', 'user' => 'users', 'akun' => 'users',
        'peran' => 'roles', 'role' => 'roles',
        'alamat' => 'addresses', 'address' => 'addresses',
        'pesanan' => 'orders', 'order' => 'orders',
        'detail pesanan' => 'order_details', 'rincian pesanan' => 'order_details',
        'pembayaran' => 'payments', 'payment' => 'payments',
        'pengiriman' => 'shipments', 'shipment' => 'shipments', 'resi' => 'shipments',
        'ulasan' => 'reviews', 'review' => 'reviews', 'rating' => 'reviews',
        'foto produk' => 'product_images',
        'percakapan ai' => 'ai_conversations',
        'pesan ai' => 'ai_messages',
        'pengaturan notifikasi' => 'notification_settings',
        'log notifikasi' => 'notification_logs',
        'log aktivitas' => 'activity_logs', 'jejak audit' => 'activity_logs',
        'channel_identities' => 'channel_identities', 'identitas kanal' => 'channel_identities',
        'tautan chatbot' => 'channel_identities',
    ];

    /**
     * Hasil introspeksi skema per request (skema tidak berubah dalam satu request).
     *
     * @var array<string, array{fungsi: string, kolom: array<string, string>, primary_key: list<string>, foreign_key: array<string, string>}>|null
     */
    private ?array $schemaCache = null;

    /**
     * Struktur skema sebagai JSON ringkas untuk system prompt chatbot.
     */
    public function schemaForPrompt(): string
    {
        $compact = [];

        foreach ($this->introspect() as $table => $info) {
            $compact[$table] = [
                'fungsi' => $info['fungsi'],
                'kolom' => array_map(
                    fn (string $name, string $type): string => $name.' ('.$type.')',
                    array_keys($info['kolom']),
                    array_values($info['kolom'])
                ),
                'primary_key' => $info['primary_key'],
                'foreign_key' => $info['foreign_key'],
            ];
        }

        return (string) json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Nama tabel domain aplikasi yang benar-benar ada di database aktif.
     *
     * @return list<string>
     */
    public function appTables(): array
    {
        return array_keys($this->introspect());
    }

    /**
     * Semua tabel whitelist yang disebut dalam pesan (alias Indonesia + nama
     * tabel eksplisit), terurut panjang alias terpanjang agar frasa seperti
     * "detail pesanan" terdeteksi lebih dulu daripada "pesanan".
     *
     * @return list<string>
     */
    public function tablesMentioned(string $message): array
    {
        $lower = mb_strtolower($message);

        $aliases = [];
        foreach (self::TABLE_ALIASES as $alias => $table) {
            $aliases[$alias] = $table;
        }
        foreach (array_keys($this->introspect()) as $table) {
            $aliases[$table] = $table;
        }

        uksort($aliases, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $found = [];
        foreach ($aliases as $alias => $table) {
            if (str_contains($lower, $alias) && ! in_array($table, $found, true)) {
                $found[] = $table;
            }
        }

        return $found;
    }

    /**
     * Penjelasan satu tabel untuk fallback lokal (deterministik, tanpa API).
     */
    public function explainTable(string $table): string
    {
        $info = $this->introspect()[$table] ?? null;

        if ($info === null) {
            return 'Maaf, tabel "'.$table.'" tidak ada dalam database Agung ATK. '
                .'Tabel yang tersedia: '.implode(', ', $this->appTables()).'.';
        }

        $lines = ['Tabel `'.$table.'` — '.$info['fungsi']];
        $lines[] = '';
        $lines[] = 'Primary key: '.implode(', ', ($info['primary_key'] === [] ? ['(terdefinisi di migrasi)'] : $info['primary_key']));

        if ($info['foreign_key'] !== []) {
            $fkLines = [];
            foreach ($info['foreign_key'] as $column => $reference) {
                $fkLines[] = '`'.$column.'` → `'.$reference.'`';
            }
            $lines[] = 'Foreign key: '.implode(', ', $fkLines);
        } else {
            $lines[] = 'Foreign key: tidak ada';
        }

        $lines[] = '';
        $lines[] = 'Kolom:';

        foreach ($info['kolom'] as $name => $type) {
            $lines[] = '- '.$name.' ('.$type.')';
        }

        $lines[] = '';
        $lines[] = 'Catatan: struktur ini diverifikasi dari database aktif. Asisten hanya menjelaskan struktur — '
            .'query SQL adalah ilustrasi dan tidak dieksekusi dari chat.';

        return implode("\n", $lines);
    }

    /**
     * Gambaran umum skema untuk pertanyaan struktur tanpa tabel tertentu.
     */
    public function overview(): string
    {
        $lines = [
            'Database Agung ATK (MySQL) memiliki '.count($this->appTables())
                .' tabel domain aplikasi plus tabel bawaan framework Laravel '
                .'('.implode(', ', self::FRAMEWORK_TABLES).').',
            '',
            'Tabel domain:',
        ];

        foreach ($this->introspect() as $table => $info) {
            $lines[] = '- `'.$table.'`: '.$info['fungsi'];
        }

        $lines[] = '';
        $lines[] = 'Alur utama: users memilih produk (products per kategori categories) → keranjang sesi → ';
        $lines[] = 'orders (pesanan) berisi order_details (snapshot nama & harga) → payments (pembayaran) ';
        $lines[] = '→ shipments (pengiriman) → reviews (ulasan). Tanyakan "Apa fungsi tabel X?" untuk detail kolom.';

        return implode("\n", $lines);
    }

    /**
     * Contoh JOIN yang dibangun dari foreign key skema NYATA antar dua tabel.
     * Mengembalikan null bila tidak ditemukan hubungan FK langsung.
     */
    public function joinExample(string $first, string $second): ?string
    {
        $schema = $this->introspect();

        foreach ([[$first, $second], [$second, $first]] as [$from, $to]) {
            $info = $schema[$to] ?? null;
            $fromInfo = $schema[$from] ?? null;

            if ($info === null || $fromInfo === null) {
                continue;
            }

            // Cari kolom pada $from yang merujuk ke $to.
            foreach ($fromInfo['foreign_key'] as $column => $reference) {
                if (str_starts_with($reference, $to.'.')) {
                    $targetKey = $info['primary_key'][0] ?? 'id';

                    return 'SELECT '.$from.'.*, '.$to.'.'.$targetKey."\n"
                        .'FROM `'.$from.'`'."\n"
                        .'JOIN `'.$to.'` ON `'.$from.'`.'.$column.' = `'.$to.'`.'.$targetKey.';';
                }
            }
        }

        return null;
    }

    /**
     * Contoh SELECT sederhana untuk satu tabel (kolom informatif, tanpa timestamps).
     */
    public function selectExample(?string $table): ?string
    {
        if ($table === null) {
            return null;
        }

        $info = $this->introspect()[$table] ?? null;

        if ($info === null) {
            return null;
        }

        $columns = array_keys(array_filter(
            $info['kolom'],
            fn (string $name): bool => ! in_array($name, ['created_at', 'updated_at', 'password', 'remember_token'], true),
            ARRAY_FILTER_USE_KEY
        ));

        $pick = array_slice($columns, 0, 4);

        if ($pick === []) {
            return null;
        }

        return 'SELECT '.implode(', ', $pick)."\n"
            .'FROM `'.$table.'`'."\n"
            .'LIMIT 10;';
    }

    /**
     * Ringkasan relasi dari foreign key database aktif.
     */
    public function relationsOverview(): string
    {
        $lines = ['Relasi antartabel Agung ATK (berdasarkan foreign key database aktif):', ''];

        foreach ($this->introspect() as $table => $info) {
            if ($info['foreign_key'] === []) {
                continue;
            }

            $parts = [];
            foreach ($info['foreign_key'] as $column => $reference) {
                $parts[] = '`'.$column.'` → `'.$reference.'`';
            }

            $lines[] = '- `'.$table.'`: '.implode(', ', $parts);
        }

        $lines[] = '';
        $lines[] = 'Catatan: satu pesanan memiliki banyak order_details (one-to-many), '
            .'tetapi hanya satu payments dan satu shipments (one-to-one via kolom order_id unique).';

        return implode("\n", $lines);
    }

    /**
     * Struktur skema (cache lintas request, disegarkan saat migrasi selesai).
     *
     * @return array<string, array{fungsi: string, kolom: array<string, string>, primary_key: list<string>, foreign_key: array<string, string>}>
     */
    public function introspect(): array
    {
        if ($this->schemaCache !== null) {
            return $this->schemaCache;
        }

        return $this->schemaCache = Cache::remember(
            self::SCHEMA_CACHE_KEY,
            now()->addHour(),
            fn (): array => $this->freshIntrospect()
        );
    }

    /**
     * Semua tabel domain pada database AKTIF (seluruh tabel minus tabel
     * framework) — penambahan tabel baru dikenali otomatis tanpa edit kode.
     *
     * @return list<string>
     */
    public function domainTables(): array
    {
        $tables = array_map(
            static fn (string $qualified): string => str_contains($qualified, '.')
                ? substr($qualified, (int) strrpos($qualified, '.') + 1)
                : $qualified,
            Schema::getTableListing(null, false)
        );

        $tables = array_values(array_diff($tables, self::FRAMEWORK_TABLES));
        sort($tables);

        return $tables;
    }

    /**
     * Introspeksi segar dari database (tanpa cache) — dipanggil oleh
     * introspect() saat cache kosong/disegarkan.
     *
     * @return array<string, array{fungsi: string, kolom: array<string, string>, primary_key: list<string>, foreign_key: array<string, string>}>
     */
    private function freshIntrospect(): array
    {
        $schema = [];

        foreach ($this->domainTables() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = [];
            foreach (Schema::getColumns($table) as $column) {
                $columns[(string) $column['name']] = (string) $column['type'];
            }

            $primaryKey = [];
            foreach (Schema::getIndexes($table) as $index) {
                if (! empty($index['primary'])) {
                    $primaryKey = array_map('strval', $index['columns']);
                }
            }

            $foreignKeys = [];
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $foreignColumns = $foreignKey['foreign_columns'];
                foreach ($foreignKey['columns'] as $position => $column) {
                    $foreignKeys[(string) $column] =
                        (string) $foreignKey['foreign_table'].'.'.$foreignColumns[$position];
                }
            }

            $schema[$table] = [
                'fungsi' => self::TABLE_PURPOSES[$table] ?? $this->autoPurpose($table, $columns),
                'kolom' => $columns,
                'primary_key' => $primaryKey,
                'foreign_key' => $foreignKeys,
            ];
        }

        return $schema;
    }

    /**
     * Deskripsi jujur untuk tabel yang belum dikurasi (tabel baru hasil
     * migrasi mendatang) — menyebut kolom nyata, tanpa mengarang fungsi.
     *
     * @param  array<string, string>  $columns
     */
    private function autoPurpose(string $table, array $columns): string
    {
        $names = array_keys($columns);
        $shown = implode(', ', array_slice($names, 0, 12));

        return 'Tabel aplikasi "'.$table.'" (deskripsi kurasi menyusul) — kolom: '
            .$shown.(count($names) > 12 ? ', …' : '').'.';
    }
}
