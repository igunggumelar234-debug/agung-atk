<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\ChatGuard;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fitur scan barang via kamera: kolom barcode produk, route scan
 * (pencarian), pengelolaan barcode oleh admin, dan kode produk
 * pada prompt chatbot.
 */
class BarcodeScanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
    }

    /* =====================================================================
     | SKEMA & DATA SEED
     * ===================================================================*/

    public function test_products_have_barcode_column_and_seeded_values(): void
    {
        $this->assertTrue(Schema::hasColumn('products', 'barcode'));

        $product = Product::where('sku', 'ATK-KRT-001')->firstOrFail();
        $this->assertNotNull($product->barcode);
        $this->assertSame('8990000000001', $product->barcode); // 899 + nomor urut seed
    }

    /* =====================================================================
     | ROUTE SCAN (dipakai hasil scan kamera pada pencarian)
     * ===================================================================*/

    public function test_scan_by_barcode_redirects_to_product_detail(): void
    {
        $product = Product::where('sku', 'ATK-PLP-001')->firstOrFail();

        $this->get(route('products.scan', $product->barcode))
            ->assertRedirect(route('products.show', $product->slug));
    }

    public function test_scan_by_sku_also_finds_product(): void
    {
        $product = Product::where('sku', 'ATK-KRT-002')->firstOrFail();

        $this->get(route('products.scan', $product->sku))
            ->assertRedirect(route('products.show', $product->slug));
    }

    public function test_scan_unknown_code_falls_back_to_catalog_search(): void
    {
        $this->get(route('products.scan', 'KODE-TIDAK-ADA123'))
            ->assertRedirect(route('products.index', ['q' => 'KODE-TIDAK-ADA123']));
    }

    public function test_scan_ignores_inactive_products(): void
    {
        $product = Product::where('is_active', true)->firstOrFail();
        $product->update(['is_active' => false]);

        $this->get(route('products.scan', $product->barcode))
            ->assertRedirect(route('products.index', ['q' => $product->barcode]));
    }

    /* =====================================================================
     | ADMIN: KELOLA BARCODE PADA FORM PRODUK
     * ===================================================================*/

    public function test_admin_can_create_product_with_barcode(): void
    {
        $this->actingAs($this->admin)->post('/admin/products', [
            'name' => 'Pensil 2B Uji Scan',
            'sku' => 'ATK-UJI-001',
            'barcode' => '8999999999991',
            'category_id' => Category::firstOrFail()->id,
            'unit' => 'Pcs',
            'price' => 2000,
            'stock' => 10,
            'min_stock' => 2,
            'weight' => 10,
        ])->assertRedirect();

        $this->assertDatabaseHas('products', [
            'sku' => 'ATK-UJI-001',
            'barcode' => '8999999999991',
        ]);
    }

    public function test_admin_cannot_use_duplicate_barcode(): void
    {
        $existing = Product::whereNotNull('barcode')->firstOrFail();

        $this->actingAs($this->admin)->post('/admin/products', [
            'name' => 'Produk Uji Duplikat',
            'sku' => 'ATK-UJI-002',
            'barcode' => $existing->barcode,
            'category_id' => Category::firstOrFail()->id,
            'unit' => 'Pcs',
            'price' => 3000,
            'stock' => 5,
            'min_stock' => 1,
            'weight' => 10,
        ])->assertRedirect()->assertSessionHasErrors('barcode');

        $this->assertDatabaseMissing('products', ['sku' => 'ATK-UJI-002']);
    }

    /* =====================================================================
     | CHATBOT: KODE & BARCODE PRODUK IKUT KE MODEL
     * ===================================================================*/

    public function test_scan_message_template_is_not_mistaken_for_privacy_request(): void
    {
        // Template pesan otomatis hasil scan kamera (partials/home-chatbot.blade.php
        // & ai/chat.blade.php) tidak boleh tertangkap pagaran privasi ChatGuard
        // sehingga info produk barcode tetap terjawab normal.
        $template = 'Tolong info produk barcode ATK-PLP-001';

        $this->assertFalse((new ChatGuard)->isPrivacyRequest($template));
    }

    public function test_chatbot_prompt_includes_product_codes_for_scanning(): void
    {
        config([
            'ai.key' => 'test-key-scan',
            'ai.url' => 'https://ai.test/v1/chat/completions',
            'ai.model' => 'test-model',
        ]);

        Http::fake([
            'ai.test/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Baik.']]],
            ], 200),
        ]);

        $product = Product::where('sku', 'ATK-KRT-001')->firstOrFail();

        $this->actingAs($this->admin)
            ->postJson(route('home.chat'), ['message' => 'Cek produk kode ATK-KRT-001'])
            ->assertOk();

        $prompt = null;
        Http::assertSent(function ($request) use (&$prompt) {
            if ($request->url() === 'https://ai.test/v1/chat/completions') {
                $prompt = $request['messages'][0]['content'];

                return true;
            }

            return false;
        });

        $this->assertIsString($prompt);
        $this->assertStringContainsString('"kode":"ATK-KRT-001"', $prompt);
        $this->assertStringContainsString('"barcode":"'.$product->barcode.'"', $prompt);
        $this->assertStringContainsString('kode hasil scan kamera', $prompt);
    }
}
