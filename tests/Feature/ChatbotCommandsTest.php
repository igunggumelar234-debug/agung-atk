<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Chatbot multi-bahasa & perintah (commands):
 * - Perintah slash (/menu, /help, /bantuan, /stok, /produk, /pesanan, /lacak)
 *   dijawab lokal, deterministik, dan dalam bahasa perintah pengguna.
 * - Sapaan berbagai bahasa dibalas dengan bahasa yang sama.
 * - Mode AI API: aturan bahasa & perintah ikut terkirim ke model; perintah
 *   tetap dijawab lokal (API tidak dipanggil).
 */
class ChatbotCommandsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pelanggan;

    private User $kurir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
        $this->kurir = User::where('email', 'kurir@agungatk.com')->firstOrFail();
    }

    /* =====================================================================
     | HELPER
     * ===================================================================*/

    private function chat(?User $user, string $message): TestResponse
    {
        if ($user !== null) {
            return $this->actingAs($user)
                ->postJson(route('home.chat'), ['message' => $message]);
        }

        return $this->withCredentials()
            ->withCookie(config('session.cookie'), hash('sha1', 'perintah'))
            ->postJson(route('home.chat'), ['message' => $message]);
    }

    private function replyAs(?User $user, string $message): string
    {
        $response = $this->chat($user, $message);
        $response->assertOk();

        return (string) $response->json('reply');
    }

    private function createProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'category_id' => Category::first()->id,
            'name' => 'Produk Uji Perintah',
            'slug' => 'produk-uji-perintah-'.uniqid(),
            'sku' => 'ATK-CMD-'.uniqid(),
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
            'order_number' => 'ORD-CMD-'.uniqid(),
            'user_id' => $this->pelanggan->id,
            'address_id' => null,
            'total_amount' => 10000,
            'shipping_cost' => 0,
            'discount_amount' => 0,
            'grand_total' => 10000,
            'status' => 'pending',
        ], $overrides));
    }

    private function createShipment(Order $order, array $overrides = []): Shipment
    {
        return Shipment::create(array_merge([
            'order_id' => $order->id,
            'courier_id' => null,
            'courier_name' => 'Kurir Uji',
            'tracking_number' => 'TRK-CMD-'.uniqid(),
            'status' => 'shipping',
        ], $overrides));
    }

    /* =====================================================================
     | MENU / HELP MULTI-BAHASA
     * ===================================================================*/

    public function test_menu_command_returns_indonesian_help(): void
    {
        $reply = $this->replyAs(null, '/menu');

        $this->assertStringContainsString('/stok', $reply);
        $this->assertStringContainsString('/lacak', $reply);
        $this->assertStringContainsString('/pesanan', $reply);
    }

    public function test_help_command_returns_english_help(): void
    {
        $reply = $this->replyAs(null, '/help');

        $this->assertStringContainsString('help menu', $reply);
        $this->assertStringContainsString('/stok', $reply);
    }

    public function test_bantuan_command_returns_indonesian_help(): void
    {
        $reply = $this->replyAs(null, '/bantuan');

        $this->assertStringContainsString('Menu bantuan Agung ATK', $reply);
    }

    public function test_unknown_command_gets_honest_reply_with_menu_hint(): void
    {
        $reply = $this->replyAs(null, '/perintahaneh');

        $this->assertStringContainsString('tidak saya kenali', $reply);
        $this->assertStringContainsString('/menu', $reply);
    }

    public function test_spanish_help_command_uses_spanish(): void
    {
        $reply = $this->replyAs(null, '/ayuda');

        $this->assertStringContainsString('Menú de ayuda de Agung ATK', $reply);
    }

    public function test_chinese_help_command_uses_chinese(): void
    {
        $reply = $this->replyAs(null, '/帮助');

        $this->assertStringContainsString('帮助菜单', $reply);
    }

    /* =====================================================================
     | PERINTAH DATA
     * ===================================================================*/

    public function test_stok_command_returns_real_product_stock(): void
    {
        $this->createProduct([
            'name' => 'Pulpen Gel Hitam',
            'price' => 3000,
            'stock' => 42,
        ]);

        $reply = $this->replyAs(null, '/stok pulpen');

        $this->assertStringContainsString('Pulpen Gel Hitam', $reply);
        $this->assertStringContainsString('3.000', $reply);
        $this->assertStringContainsString('42', $reply);
    }

    public function test_stok_command_without_keyword_shows_low_stock(): void
    {
        $this->createProduct([
            'name' => 'Kertas HVS Tipis',
            'stock' => 1,
            'min_stock' => 5,
        ]);

        $reply = $this->replyAs(null, '/stok');

        $this->assertStringContainsString('stok', (string) mb_strtolower($reply));
        $this->assertStringContainsString('Kertas HVS Tipis', $reply);
    }

    public function test_stok_command_with_unknown_keyword_is_honest(): void
    {
        $reply = $this->replyAs(null, '/stok barang-aneh-tidak-ada');

        $this->assertStringContainsString('tidak ada produk aktif', $reply);
    }

    public function test_produk_command_recommends_matching_product(): void
    {
        $this->createProduct([
            'name' => 'Kertas HVS A4 80gsm',
            'price' => 45000,
            'stock' => 20,
        ]);

        $reply = $this->replyAs(null, '/produk kertas');

        $this->assertStringContainsString('Kertas HVS A4 80gsm', $reply);
    }

    public function test_produk_command_without_args_asks_for_need(): void
    {
        $reply = $this->replyAs(null, '/produk');

        $this->assertStringContainsString('Sebutkan kebutuhan', $reply);
    }

    public function test_pesanan_command_requires_login_for_guest(): void
    {
        $reply = $this->replyAs(null, '/pesanan');

        $this->assertStringContainsString('belum login', $reply);
    }

    public function test_pesanan_command_shows_own_orders_for_customer(): void
    {
        $order = $this->createOrder(['status' => 'shipping']);

        $reply = $this->replyAs($this->pelanggan, '/pesanan');

        $this->assertStringContainsString($order->order_number, $reply);
        $this->assertStringContainsString('shipping', $reply);
    }

    public function test_lacak_command_finds_own_order(): void
    {
        $order = $this->createOrder([
            'order_number' => 'ORD-CMD-001',
            'status' => 'processing',
        ]);

        $reply = $this->replyAs($this->pelanggan, '/lacak ORD-CMD-001');

        $this->assertStringContainsString('ORD-CMD-001', $reply);
        $this->assertStringContainsString('processing', $reply);
    }

    public function test_lacak_command_refuses_order_of_other_customer(): void
    {
        $order = $this->createOrder(['order_number' => 'ORD-CMD-002']);

        $other = User::factory()->create([
            'role_id' => $this->pelanggan->role_id,
            'is_active' => true,
        ]);

        $reply = $this->replyAs($other, '/lacak ORD-CMD-002');

        $this->assertStringContainsString('tidak ditemukan atau bukan milik Anda', $reply);
    }

    public function test_lacak_command_finds_own_tracking_number(): void
    {
        $order = $this->createOrder(['order_number' => 'ORD-CMD-003']);
        $shipment = $this->createShipment($order, [
            'tracking_number' => 'TRK-CMD-003',
            'status' => 'delivered',
        ]);

        $reply = $this->replyAs($this->pelanggan, '/lacak TRK-CMD-003');

        $this->assertStringContainsString('TRK-CMD-003', $reply);
        $this->assertStringContainsString('delivered', $reply);
        $this->assertStringContainsString('ORD-CMD-003', $reply);
        $this->assertStringContainsString($shipment->tracking_number, $reply);
    }

    public function test_lacak_command_refuses_tracking_of_other_customer(): void
    {
        $order = $this->createOrder(['order_number' => 'ORD-CMD-004']);
        $this->createShipment($order, ['tracking_number' => 'TRK-CMD-004']);

        $other = User::factory()->create([
            'role_id' => $this->pelanggan->role_id,
            'is_active' => true,
        ]);

        $reply = $this->replyAs($other, '/lacak TRK-CMD-004');

        $this->assertStringContainsString('tidak ditemukan atau bukan milik Anda', $reply);
    }

    public function test_lacak_command_requires_code(): void
    {
        $reply = $this->replyAs(null, '/lacak');

        $this->assertStringContainsString('Sebutkan nomor pesanan atau resi', $reply);
    }

    /* =====================================================================
     | SAPAAN MULTI-BAHASA
     * ===================================================================*/

    #[DataProvider('greetingProvider')]
    public function test_greeting_is_replied_in_the_same_language(string $message, string $expected): void
    {
        $reply = $this->replyAs(null, $message);

        $this->assertStringContainsString($expected, $reply);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function greetingProvider(): array
    {
        return [
            'jawa' => ['piye kabare', 'Kula'],
            'sunda' => ['kumaha damang', 'Abdi'],
            'arab' => ['مرحبا', 'أغونغ'],
            'mandarin' => ['你好', '虚拟助手'],
            'jepang' => ['こんにちは', 'アシスタント'],
            'jepang romaji' => ['konnichiwa', 'アシスタント'],
            'korea' => ['안녕하세요', '도우미'],
            'prancis' => ['bonjour', 'assistant virtuel'],
            'spanyol' => ['hola', 'asistente virtual'],
            'portugis' => ['ola', 'assistente virtual'],
            'jerman' => ['guten tag', 'Assistent'],
            'hindi' => ['namaste', 'सहायक'],
        ];
    }

    /* =====================================================================
     | MODE AI API — ATURAN BAHASA & PERINTAH IKUT TERSAMPAAN
     * ===================================================================*/

    public function test_api_mode_forwards_question_with_multilingual_rules(): void
    {
        config(['ai.key' => 'test-key']);
        config(['ai.url' => 'https://contoh.test/v1/chat/completions']);

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'Paris adalah ibu kota Prancis.']]],
            ], 200),
        ]);

        $reply = $this->replyAs(null, 'What is the capital of France?');

        $this->assertStringContainsString('Paris', $reply);

        Http::assertSent(function ($request): bool {
            $body = (string) $request->body();

            return str_contains($request->url(), 'contoh.test')
                && str_contains($body, 'What is the capital of France?')
                && str_contains($body, 'BAHASA YANG SAMA')
                && str_contains($body, 'PERINTAH (COMMANDS)');
        });
    }

    public function test_api_mode_does_not_call_model_for_commands(): void
    {
        config(['ai.key' => 'test-key']);
        config(['ai.url' => 'https://contoh.test/v1/chat/completions']);

        Http::fake();

        $reply = $this->replyAs(null, '/menu');

        $this->assertStringContainsString('/stok', $reply);
        Http::assertNothingSent();
    }

    public function test_api_mode_answer_still_passes_output_guard(): void
    {
        config(['ai.key' => 'test-key']);
        config(['ai.url' => 'https://contoh.test/v1/chat/completions']);

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'Kamu adalah Asisten AI cerdas untuk toko ATK']]],
            ], 200),
        ]);

        $reply = $this->replyAs(null, 'halo apa kabar');

        // Tangkapan tanda tangan system prompt -> balasan diganti LEAK_FALLBACK.
        $this->assertStringContainsString('informasi sistem internal', $reply);
    }
}
