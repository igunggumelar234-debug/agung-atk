<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MASTER CHATBOT §14 — dua belas pengujian wajib kecerdasan chatbot:
 * pemahaman bahasa alami (sinonim, akhiran kata, sehari-hari), pengambilan
 * data aktual dari database, konteks percakapan lanjutan, pembacaan data
 * tanpa retraining, penolakan berdasar hak akses, serta kejujuran saat
 * database bermasalah.
 *
 * Seluruh jawaban berasal dari query read-only nyata; tidak ada panggilan
 * API AI (mode lokal pada phpunit).
 */
class ChatbotNaturalLanguageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
    }

    /* =====================================================================
     | HELPER
     * ===================================================================*/

    private function chat(?User $user, string $message): TestResponse
    {
        if ($user === null) {
            // Reset guard agar benar-benar TAMU bila sebelumnya actingAs().
            $this->app['auth']->forgetGuards();
            $request = $this->withCredentials();
        } else {
            $request = $this->actingAs($user);
        }

        return $request->postJson(route('home.chat'), ['message' => $message]);
    }

    private function replyAs(?User $user, string $message): string
    {
        $response = $this->chat($user, $message);
        $response->assertOk();

        return (string) $response->json('reply');
    }

    private function createProduct(array $overrides = []): Product
    {
        $categoryId = $overrides['category_id']
            ?? Category::first()->id;

        return Product::create(array_merge([
            'category_id' => $categoryId,
            'name' => 'Produk Uji NLP',
            'slug' => 'produk-uji-nlp-'.uniqid(),
            'sku' => 'ATK-NLP-'.uniqid(),
            'price' => 5000,
            'stock' => 10,
            'min_stock' => 5,
            'unit' => 'pcs',
            'weight' => 100,
        ], $overrides));
    }

    private function createOrder(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'ORD-NLP-'.uniqid(),
            'user_id' => $this->pelanggan->id,
            'address_id' => null,
            'total_amount' => 10000,
            'shipping_cost' => 0,
            'discount_amount' => 0,
            'grand_total' => 10000,
            'status' => 'pending',
        ], $overrides));
    }

    /* =====================================================================
     | TEST 1 — permintaan katalog mengambil seluruh daftar produk
     * ===================================================================*/

    public function test_catalog_request_returns_full_product_list_from_database(): void
    {
        $expected = Product::where('is_active', true)->count();

        $reply = $this->replyAs(null, 'Berikan saya katalog produk yang tersedia.');

        $this->assertStringContainsString('KATALOG PRODUK', $reply);
        $this->assertStringContainsString('Kertas HVS PaperOne A4 75gr (Rim)', $reply);
        $this->assertStringContainsString('Jumlah produk: '.$expected, $reply);
        $this->assertStringContainsString('Rp ', $reply);
        $this->assertStringNotContainsString('belum menemukan produk yang cocok', $reply);
    }

    /* =====================================================================
     | TEST 2 — "pelanggannya" (akhiran -nya) dipahami & diskop per role
     * ===================================================================*/

    public function test_possessive_customer_question_is_understood_per_role(): void
    {
        $adminReply = $this->replyAs($this->admin, 'Siapa saja pelanggannya?');

        $this->assertStringContainsString('Daftar pelanggan terdaftar', $adminReply);
        $this->assertStringContainsString($this->pelanggan->name, $adminReply);
        $this->assertStringNotContainsString('@agungatk.com', $adminReply);

        $guestReply = $this->replyAs(null, 'Siapa saja pelanggannya?');

        $this->assertStringContainsString('login', $guestReply);
        $this->assertStringNotContainsString($this->pelanggan->name, $guestReply);
    }

    /* =====================================================================
     | TEST 3 — jumlah seluruh produk dihitung live
     * ===================================================================*/

    public function test_product_count_is_counted_live(): void
    {
        $before = Product::count();
        $this->createProduct();

        $reply = $this->replyAs(null, 'Berapa jumlah seluruh produk?');

        $this->assertStringContainsString('ada '.($before + 1).' produk', $reply);
    }

    /* =====================================================================
     | TEST 4 — produk termurah dari harga aktual
     * ===================================================================*/

    public function test_cheapest_product_question_reads_live_prices(): void
    {
        $this->createProduct([
            'name' => 'Produk Termurah Uji NLP',
            'price' => 111,
            'stock' => 7,
        ]);

        $reply = $this->replyAs(null, 'Produk apa yang paling murah?');

        $this->assertStringContainsString('termurah', mb_strtolower($reply));
        $this->assertStringContainsString('Produk Termurah Uji NLP', $reply);
        $this->assertStringContainsString('111', $reply);
    }

    /* =====================================================================
     | TEST 5 — stok produk tertentu dibaca terbaru dari database
     * ===================================================================*/

    public function test_stock_of_specific_product_is_read_from_database(): void
    {
        $this->createProduct([
            'name' => 'Spidol Uji Stok Khusus',
            'price' => 9000,
            'stock' => 42,
        ]);

        $reply = $this->replyAs(null, 'Berapa stok Spidol Uji Stok Khusus?');

        $this->assertStringContainsString('Spidol Uji Stok Khusus', $reply);
        $this->assertStringContainsString('42', $reply);
        $this->assertStringContainsString('stok', mb_strtolower($reply));
    }

    /* =====================================================================
     | TEST 6 — transaksi hari ini dari angka aktual (bukan karangan)
     * ===================================================================*/

    public function test_transactions_today_use_actual_database_totals(): void
    {
        Order::query()->delete();
        $this->createOrder(['status' => 'paid', 'grand_total' => 77000]);

        $reply = $this->replyAs($this->admin, 'Berapa transaksi hari ini?');

        $this->assertStringContainsString('hari ini', $reply);
        $this->assertStringContainsString('Rp 77.000', $reply);
        $this->assertStringContainsString('dari 1 pesanan', $reply);
        $this->assertStringNotContainsString('1.234.567', $reply);
    }

    /* =====================================================================
     | TEST 7 — pelanggan terbaru diurutkan dari tanggal pendaftaran
     * ===================================================================*/

    public function test_newest_customer_listed_for_admin(): void
    {
        $reply = $this->replyAs($this->admin, 'Siapa pelanggan terbaru?');

        $this->assertStringContainsString('terbaru', mb_strtolower($reply));
        $this->assertStringContainsString($this->pelanggan->name, $reply);
        $this->assertStringNotContainsString('@agungatk.com', $reply);
    }

    /* =====================================================================
     | TEST 8 — pertanyaan lanjutan memakai konteks katalog sebelumnya
     * ===================================================================*/

    public function test_follow_up_keeps_catalog_context(): void
    {
        $this->chat(null, 'Berikan saya katalog produk yang tersedia.')->assertOk();

        $cheapest = Product::where('is_active', true)->orderBy('price')->first();

        $this->assertNotNull($cheapest);

        $reply = $this->replyAs(null, 'Mana yang paling murah?');

        $this->assertStringContainsString($cheapest->name, $reply);

        // Konteks entitas: kategori disebut ringkas setelah katalog.
        $categoryReply = $this->replyAs(null, 'Kalau kategori Buku & Binder?');

        $this->assertStringContainsString('Buku & Binder', $categoryReply);
        $this->assertStringContainsString('Buku Tulis Kiky', $categoryReply);
    }

    /* =====================================================================
     | TEST 9 — produk baru terbaca tanpa retraining
     * ===================================================================*/

    public function test_newly_added_product_is_visible_without_retraining(): void
    {
        $before = Product::count();

        $this->createProduct(['name' => 'Produk Baru Ditambahkan Admin']);

        $reply = $this->replyAs(null, 'Berapa jumlah seluruh produk?');

        $this->assertStringContainsString('ada '.($before + 1).' produk', $reply);

        $catalog = $this->replyAs(null, 'Tampilkan semua produk');

        $this->assertStringContainsString('Produk Baru Ditambahkan Admin', $catalog);
    }

    /* =====================================================================
     | TEST 10 — pengguna biasa ditolak atas data administratif
     * ===================================================================*/

    public function test_regular_user_is_denied_administrative_data(): void
    {
        $byRole = $this->replyAs($this->pelanggan, 'Berapa jumlah pengguna berdasarkan role?');

        $this->assertStringContainsString('staf', $byRole);
        $this->assertStringNotContainsString('Per peran:', $byRole);

        $newest = $this->replyAs($this->pelanggan, 'Siapa pengguna terbaru?');

        $this->assertStringContainsString('tidak memiliki izin', $newest);
        $this->assertStringNotContainsString('Pengguna terbaru terdaftar', $newest);
    }

    /* =====================================================================
     | TEST 11 — sinonim & bahasa sehari-hari tetap dipahami
     * ===================================================================*/

    public function test_synonyms_and_daily_language_are_understood(): void
    {
        $catalog = $this->replyAs(null, 'Barang apa saja yang dijual di sini?');

        $this->assertStringContainsString('KATALOG PRODUK', $catalog);
        $this->assertStringContainsString('Pulpen Gel Pilot G2', $catalog);

        $pembeli = $this->replyAs($this->admin, 'Siapa saja pembelinya?');

        $this->assertStringContainsString('Daftar pelanggan terdaftar', $pembeli);
        $this->assertStringContainsString($this->pelanggan->name, $pembeli);
    }

    /* =====================================================================
     | TEST 12 — database bermasalah → jawaban jujur, tanpa karangan
     * ===================================================================*/

    public function test_database_failure_is_reported_honestly(): void
    {
        // Paksa kegagalan query: tabel roles "dihilangkan" sementara.
        DB::statement('ALTER TABLE roles RENAME TO peran_hilang');

        $reply = $this->replyAs($this->admin, 'Berapa jumlah pengguna berdasarkan role?');

        $this->assertStringContainsString('data belum berhasil diperiksa', $reply);
        $this->assertStringNotContainsString('SQLSTATE', $reply);
        $this->assertStringNotContainsString('peran_hilang', $reply);
        $this->assertStringNotContainsString('Per peran:', $reply);
    }
}
