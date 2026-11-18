<?php

namespace Tests\Feature;

use App\Models\AiMessage;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Bagian 4 & 5 MASTER — penutup celah audit chatbot DB dan AI:
 * intent baru (stok terbanyak, produk terbaru, pengguna per role &
 * terbaru, transaksi belum dibayar, transaksi terbaru, pelanggan
 * dengan transaksi terbanyak), pola jumlah produk "seluruh",
 * pencatatan token API ke ai_messages.tokens_used, serta penghapusan
 * jumlah akun pengguna dari konteks tamu.
 *
 * Seluruh jawaban berasal dari query read-only nyata (bukan fixture
 * teks) dan tidak ada panggilan API AI nyata (Http::fake bila mode API).
 */
class ChatbotDataGapsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $penjual;

    private User $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->penjual = User::where('email', 'penjual@agungatk.com')->firstOrFail();
        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
    }

    /* =====================================================================
     | HELPER
     * ===================================================================*/

    private function chat(?User $user, string $message): TestResponse
    {
        $request = $user === null ? $this->withCredentials() : $this->actingAs($user);

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
        return Product::create(array_merge([
            'category_id' => Category::first()->id,
            'name' => 'Produk Uji Celah',
            'slug' => 'produk-uji-celah-'.uniqid(),
            'sku' => 'ATK-GAP-'.uniqid(),
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
            'order_number' => 'ORD-GAP-'.uniqid(),
            'user_id' => $this->pelanggan->id,
            'address_id' => null,
            'total_amount' => 10000,
            'shipping_cost' => 0,
            'discount_amount' => 0,
            'grand_total' => 10000,
            'status' => 'pending',
        ], $overrides));
    }

    /**
     * Aktifkan mode API AI dengan fake (tanpa panggilan nyata).
     */
    private function enableApi(string $replyContent, ?array $usage = null): void
    {
        config([
            'ai.key' => 'test-key-gaps',
            'ai.url' => 'https://ai.test/v1/chat/completions',
            'ai.model' => 'test-model',
        ]);

        $payload = [
            'choices' => [['message' => ['role' => 'assistant', 'content' => $replyContent]]],
        ];

        if ($usage !== null) {
            $payload['usage'] = $usage;
        }

        Http::fake(['ai.test/*' => Http::response($payload, 200)]);
    }

    /**
     * Seluruh system prompt yang terkirim ke AI (urut seperti pengiriman).
     *
     * @return list<string>
     */
    private function capturedSystemPrompts(): array
    {
        $systems = [];

        Http::recorded(function ($request) use (&$systems) {
            if ($request->url() === config('ai.url')) {
                $content = $request['messages'][0]['content'] ?? null;

                if (is_string($content)) {
                    $systems[] = $content;
                }
            }

            return false;
        });

        return $systems;
    }

    /**
     * Ekstrak segmen ringkasan_tabel dari system prompt.
     */
    private function ringkasanSegment(string $prompt): ?string
    {
        return preg_match('/"ringkasan_tabel":\{[^}]*\}/', $prompt, $matches) === 1
            ? $matches[0]
            : null;
    }

    /* =====================================================================
     | INTENT BARU — PRODUK (PUBLIK)
     * ===================================================================*/

    public function test_top_stock_question_ranks_products_by_live_stock(): void
    {
        $this->createProduct(['name' => 'Kertas HVS Stok Kecil', 'stock' => 3]);
        $this->createProduct(['name' => 'Pulpen Stok Raksasa', 'stock' => 999999]);

        $reply = $this->replyAs(null, 'Produk apa yang stoknya paling banyak?');

        $this->assertStringContainsString('stok terbanyak', $reply);
        $this->assertStringContainsString('Pulpen Stok Raksasa', $reply);
        $this->assertStringContainsString('999999', $reply);
        $this->assertStringNotContainsString('Rp', $reply);
    }

    public function test_newest_products_question_lists_latest_catalog_entries(): void
    {
        $this->createProduct(['name' => 'Produk Terbaru Celah']);

        $reply = $this->replyAs(null, 'Tampilkan produk terbaru');

        $this->assertStringContainsString('Produk terbaru di katalog', $reply);
        $this->assertStringContainsString('Produk Terbaru Celah', $reply);
    }

    public function test_product_count_understands_seluruh_connector(): void
    {
        $before = Product::count();
        $this->createProduct();
        $this->createProduct();

        $reply = $this->replyAs(null, 'Berapa seluruh produk yang ada?');

        $this->assertStringContainsString('ada '.($before + 2).' produk', $reply);
    }

    /* =====================================================================
     | INTENT BARU — TRANSAKSI BELUM DIBAYAR (STAF)
     * ===================================================================*/

    public function test_pending_payments_answer_counts_real_unpaid_orders(): void
    {
        $pendingBefore = Order::where('status', 'pending')->count();
        $withProofBefore = Order::where('status', 'pending')->whereHas('payment')->count();

        $withProof = $this->createOrder(['order_number' => 'ORD-GAP-PROOF']);
        Payment::create([
            'order_id' => $withProof->id,
            'payment_number' => 'PAY-GAP-'.uniqid(),
            'method' => 'transfer',
            'amount' => 10000,
            'status' => 'pending',
        ]);
        $this->createOrder(['order_number' => 'ORD-GAP-NOPROOF']);
        $this->createOrder(['order_number' => 'ORD-GAP-LUNAS', 'status' => 'completed']);

        $reply = $this->replyAs($this->admin, 'Berapa transaksi yang belum dibayar?');

        // BUKAN jawaban pendapatan (intent lama sebelumnya menangkap ini).
        $this->assertStringNotContainsString('Total transaksi', $reply);
        $this->assertStringContainsString(
            'sepanjang waktu: '.($pendingBefore + 2).' pesanan',
            $reply
        );
        $this->assertStringContainsString(
            'menunggu verifikasi admin): '.($withProofBefore + 1).' pesanan',
            $reply
        );
        $this->assertStringContainsString(
            'Belum ada unggahan bukti: '.($pendingBefore + 1 - $withProofBefore).' pesanan',
            $reply
        );
    }

    public function test_pending_payments_is_denied_for_customer(): void
    {
        $reply = $this->replyAs($this->pelanggan, 'Berapa transaksi yang belum dibayar?');

        $this->assertStringContainsString('staf', $reply);
        $this->assertStringNotContainsString('pesanan.', $reply);
    }

    /* =====================================================================
     | INTENT BARU — PESANAN TERBARU (STAF)
     * ===================================================================*/

    public function test_newest_orders_lists_latest_orders_for_staff(): void
    {
        $this->createOrder(['order_number' => 'ORD-GAP-TERBARU', 'status' => 'paid']);

        $reply = $this->replyAs($this->admin, 'Transaksi apa yang paling baru?');

        $this->assertStringContainsString('Pesanan terbaru masuk', $reply);
        $this->assertStringContainsString('ORD-GAP-TERBARU', $reply);
        $this->assertStringContainsString('status paid', $reply);
    }

    public function test_newest_orders_is_denied_for_guest(): void
    {
        $reply = $this->replyAs(null, 'Transaksi apa yang paling baru?');

        $this->assertStringContainsString('staf', $reply);
        $this->assertStringNotContainsString('ORD-GAP', $reply);
    }

    /* =====================================================================
     | INTENT BARU — PENGGUNA PER ROLE & TERBARU
     * ===================================================================*/

    public function test_users_by_role_breakdown_is_answered_for_staff(): void
    {
        $reply = $this->replyAs($this->admin, 'Berapa jumlah pengguna berdasarkan role?');

        $this->assertStringContainsString('Per peran:', $reply);
        $this->assertStringContainsString('admin (', $reply);
        $this->assertStringContainsString('pelanggan (', $reply);
        $this->assertStringNotContainsString('@agungatk.com', $reply);
    }

    public function test_users_by_role_is_denied_for_customer(): void
    {
        $reply = $this->replyAs($this->pelanggan, 'Berapa jumlah pengguna berdasarkan role?');

        $this->assertStringContainsString('staf', $reply);
        $this->assertStringNotContainsString('Per peran:', $reply);
    }

    public function test_newest_users_listed_for_admin_without_emails(): void
    {
        $reply = $this->replyAs($this->admin, 'Siapa pengguna terbaru?');

        $this->assertStringContainsString('Pengguna terbaru terdaftar', $reply);
        $this->assertStringNotContainsString('@agungatk.com', $reply);
    }

    public function test_newest_users_is_denied_for_customer(): void
    {
        $reply = $this->replyAs($this->pelanggan, 'Siapa pengguna terbaru?');

        $this->assertStringContainsString('tidak memiliki izin', $reply);
        $this->assertStringNotContainsString('Pengguna terbaru terdaftar', $reply);
    }

    /* =====================================================================
     | INTENT BARU — PELANGGAN DENGAN TRANSAKSI TERBANYAK
     * ===================================================================*/

    public function test_top_customer_ranking_is_answered_for_admin(): void
    {
        $this->createOrder(['status' => 'completed']);
        $this->createOrder(['status' => 'paid']);

        $reply = $this->replyAs($this->admin, 'Pelanggan dengan transaksi terbanyak?');

        $this->assertStringContainsString('transaksi (jumlah pesanan) terbanyak', $reply);
        $this->assertStringContainsString($this->pelanggan->name, $reply);
        $this->assertStringContainsString('pesanan', $reply);
    }

    public function test_top_customer_ranking_is_denied_for_customer_and_limited_for_anggota(): void
    {
        $denied = $this->replyAs($this->pelanggan, 'Pelanggan dengan transaksi terbanyak?');
        $this->assertStringContainsString('tidak memiliki izin', $denied);

        $limited = $this->replyAs($this->penjual, 'Pelanggan dengan transaksi terbanyak?');
        $this->assertStringContainsString('hanya tersedia untuk admin', $limited);
    }

    /* =====================================================================
     | BAGIAN 5 — PENCATATAN TOKEN API & KONTEKS TAMU
     * ===================================================================*/

    public function test_api_usage_total_tokens_are_recorded_on_assistant_message(): void
    {
        $this->enableApi('Balasan bertoken.', [
            'prompt_tokens' => 120,
            'completion_tokens' => 30,
            'total_tokens' => 150,
        ]);

        $this->chat(null, 'Apa ibu kota Prancis?')->assertOk();

        $assistant = AiMessage::where('role', 'assistant')->latest('id')->firstOrFail();

        $this->assertSame(150, $assistant->tokens_used);
    }

    public function test_missing_usage_leaves_tokens_used_null(): void
    {
        $this->enableApi('Balasan tanpa blok usage.');

        $this->chat(null, 'Apa ibu kota Jepang?')->assertOk();

        $assistant = AiMessage::where('role', 'assistant')->latest('id')->firstOrFail();

        $this->assertNull($assistant->tokens_used);
    }

    public function test_guest_system_prompt_omits_user_account_totals_but_staff_sees_them(): void
    {
        $this->enableApi('Balasan uji konteks.');

        // Tamu — ringkasan_tabel TANPA jumlah akun pengguna.
        $this->chat(null, 'Berapa pendapatan toko?')->assertOk();
        $guestPrompt = $this->capturedSystemPrompts()[0] ?? '';
        $guestRingkasan = $this->ringkasanSegment($guestPrompt);

        $this->assertNotNull($guestRingkasan, 'Segmen ringkasan_tabel harus ada pada prompt tamu.');
        $this->assertStringContainsString('"products":', $guestRingkasan);
        $this->assertStringNotContainsString('"users":', $guestRingkasan);

        // Staf (admin) — jumlah akun tetap tersedia.
        $this->chat($this->admin, 'Berapa pendapatan toko?')->assertOk();
        $prompts = $this->capturedSystemPrompts();
        $adminRingkasan = $this->ringkasanSegment(end($prompts));

        $this->assertNotNull($adminRingkasan, 'Segmen ringkasan_tabel harus ada pada prompt admin.');
        $this->assertStringContainsString('"users":', $adminRingkasan);
    }
}
