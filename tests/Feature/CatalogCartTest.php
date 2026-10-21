<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogCartTest extends TestCase
{
    use RefreshDatabase;

    private User $pelanggan;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();

        Storage::fake('public');
    }

    /* =====================================================================
     | KATALOG — PUBLIK
     * ===================================================================*/

    public function test_guest_can_browse_catalog(): void
    {
        $this->get('/products')
            ->assertStatus(200)
            ->assertSee('Katalog Produk ATK');
    }

    public function test_catalog_only_shows_active_products(): void
    {
        $inactive = Product::where('name', 'Pulpen Gel Pilot G2 0.5mm Hitam (Pcs)')->firstOrFail();
        $inactive->update(['is_active' => false]);

        $this->get('/products')
            ->assertStatus(200)
            ->assertSee('Kertas HVS PaperOne A4 75gr (Rim)')
            ->assertDontSee($inactive->name);
    }

    public function test_product_detail_shows_price_stock_and_images(): void
    {
        $product = Product::whereHas('productImages')->firstOrFail();

        $response = $this->get('/products/'.$product->slug);
        $response->assertStatus(200)
            ->assertSee($product->name)
            ->assertSee(number_format((float) $product->price, 0, ',', '.'))
            ->assertSee('Tersedia: '.$product->stock)
            ->assertSee('mainImage');
    }

    public function test_inactive_product_detail_returns_404(): void
    {
        $product = Product::first();
        $product->update(['is_active' => false]);

        $this->get('/products/'.$product->slug)->assertStatus(404);
    }

    public function test_catalog_search_works(): void
    {
        $this->get('/products?q=PaperOne')
            ->assertStatus(200)
            ->assertSee('Kertas HVS PaperOne A4 75gr (Rim)')
            ->assertDontSee('Pulpen Gel Pilot G2');

        $this->get('/products?q=ATK-PLP-001')
            ->assertStatus(200)
            ->assertSee('Pulpen Gel Pilot G2');
    }

    public function test_catalog_category_filter_works(): void
    {
        $kertas = Category::where('slug', 'kertas-dan-karton')->firstOrFail();
        $expected = Product::active()->where('category_id', $kertas->id)->pluck('name');

        $response = $this->get('/products?category_id='.$kertas->id);
        $response->assertStatus(200);

        foreach ($expected as $name) {
            $response->assertSee($name);
        }

        foreach (Product::active()->where('category_id', '!=', $kertas->id)->pluck('name') as $name) {
            $response->assertDontSee($name);
        }
    }

    public function test_catalog_shows_empty_state_when_no_match(): void
    {
        $this->get('/products?q=produk-tidak-mungkin-ada')
            ->assertStatus(200)
            ->assertSee('Produk Tidak Ditemukan');
    }

    public function test_catalog_is_paginated(): void
    {
        $category = Category::first();
        Product::query()->update(['is_active' => true]);

        for ($i = 1; $i <= 5; $i++) {
            Product::create([
                'category_id' => $category->id,
                'name' => "Produk Katalog {$i}",
                'slug' => "produk-katalog-{$i}",
                'sku' => "ATK-CAT-{$i}",
                'price' => 1000,
                'stock' => 10,
                'min_stock' => 2,
                'unit' => 'pcs',
                'weight' => 100,
                'is_active' => true,
            ]);
        }

        $this->assertGreaterThan(12, Product::active()->count());

        $page1 = $this->get('/products?page=1');
        $page1->assertStatus(200);
        $this->assertStringContainsString('page=2', $page1->getContent());

        $this->get('/products?page=2')->assertStatus(200);
    }

    public function test_home_page_shows_featured_products_and_catalog_link(): void
    {
        $this->get('/')
            ->assertStatus(200)
            ->assertSee('Produk Unggulan')
            ->assertSee('Kertas HVS PaperOne A4 75gr (Rim)')
            ->assertSee(route('products.index'));
    }

    public function test_guest_sees_login_button_instead_of_add_to_cart(): void
    {
        // Tamu melihat tombol "Masuk untuk Membeli" dan TIDAK ada form/tombol
        // tambah-ke-keranjang yang dirender (label "Tambah ke Keranjang" pada
        // partial visual-search hanya teks di dalam <script>, dan tombolnya
        // baru dibuat JS bila cfg.canAddToCart true / pelanggan login).
        $this->get('/products')
            ->assertStatus(200)
            ->assertSee('Masuk untuk Membeli')
            ->assertDontSee('action="'.route('cart.store').'"', false);
    }

    public function test_customer_sees_add_to_cart_form_in_catalog(): void
    {
        $this->actingAs($this->pelanggan)
            ->get('/products')
            ->assertStatus(200)
            ->assertSee('Tambah ke Keranjang');
    }

    /* =====================================================================
     | KERANJANG — AUTHORIZATION
     * ===================================================================*/

    public function test_guest_is_redirected_to_login_from_cart(): void
    {
        $this->get('/cart')->assertRedirect('/login');
        $this->post('/cart', ['product_id' => 1, 'quantity' => 1])->assertRedirect('/login');
    }

    public function test_non_customer_roles_are_forbidden_from_cart(): void
    {
        foreach ([$this->admin, User::where('email', 'penjual@agungatk.com')->firstOrFail(), User::where('email', 'kurir@agungatk.com')->firstOrFail()] as $user) {
            $product = Product::first();

            $this->actingAs($user)->get('/cart')->assertStatus(403);
            $this->actingAs($user)->post('/cart', ['product_id' => $product->id, 'quantity' => 1])->assertStatus(403);
            $this->actingAs($user)->patch('/cart/'.$product->id, ['quantity' => 1])->assertStatus(403);
            $this->actingAs($user)->delete('/cart/'.$product->id)->assertStatus(403);
        }
    }

    public function test_customer_can_access_cart_with_empty_state(): void
    {
        $this->actingAs($this->pelanggan)
            ->get('/cart')
            ->assertStatus(200)
            ->assertSee('Keranjang Anda Masih Kosong');
    }

    /* =====================================================================
     | KERANJANG — TAMBAH PRODUK (VALIDASI)
     * ===================================================================*/

    public function test_customer_can_add_product_to_cart(): void
    {
        $product = Product::where('stock', '>', 0)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->post('/cart', ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHas('cart', [$product->id => 1])
            ->assertSessionHas('success');
    }

    public function test_adding_same_product_increments_quantity(): void
    {
        $product = Product::where('stock', '>', 5)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->post('/cart', ['product_id' => $product->id, 'quantity' => 3])
            ->assertSessionHas('cart', [$product->id => 5]);
    }

    public function test_quantity_must_be_at_least_one(): void
    {
        $product = Product::first();

        $this->actingAs($this->pelanggan)
            ->post('/cart', ['product_id' => $product->id, 'quantity' => 0])
            ->assertSessionHasErrors('quantity');
    }

    public function test_quantity_must_be_numeric(): void
    {
        $product = Product::first();

        $this->actingAs($this->pelanggan)
            ->post('/cart', ['product_id' => $product->id, 'quantity' => 'banyak'])
            ->assertSessionHasErrors('quantity');
    }

    public function test_quantity_cannot_exceed_stock(): void
    {
        $product = Product::where('stock', '>', 0)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->post('/cart', ['product_id' => $product->id, 'quantity' => $product->stock + 1])
            ->assertSessionHasErrors('quantity');

        $this->assertArrayNotHasKey($product->id, session('cart', []));
    }

    public function test_accumulated_quantity_cannot_exceed_stock(): void
    {
        $product = Product::where('stock', '>', 5)->firstOrFail();
        $product->update(['stock' => 6]);

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 5]])
            ->post('/cart', ['product_id' => $product->id, 'quantity' => 5])
            ->assertSessionHasErrors('quantity');

        // Quantity lama tidak berubah
        $this->assertSame([$product->id => 5], session('cart'));
    }

    public function test_inactive_product_cannot_be_added_to_cart(): void
    {
        $product = Product::first();
        $product->update(['is_active' => false]);

        $this->actingAs($this->pelanggan)
            ->post('/cart', ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHasErrors('product_id');

        $this->assertArrayNotHasKey($product->id, session('cart', []));
    }

    public function test_unknown_product_cannot_be_added(): void
    {
        $this->actingAs($this->pelanggan)
            ->post('/cart', ['product_id' => 999999, 'quantity' => 1])
            ->assertSessionHasErrors('product_id');
    }

    /* =====================================================================
     | KERANJANG — UBAH & HAPUS
     * ===================================================================*/

    public function test_customer_can_update_quantity(): void
    {
        $product = Product::where('stock', '>', 5)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 1]])
            ->patch('/cart/'.$product->id, ['quantity' => 4])
            ->assertSessionHas('cart', [$product->id => 4])
            ->assertSessionHas('success');
    }

    public function test_update_rejects_quantity_below_one(): void
    {
        $product = Product::first();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->patch('/cart/'.$product->id, ['quantity' => 0])
            ->assertSessionHasErrors('quantity');

        $this->assertSame([$product->id => 2], session('cart'));
    }

    public function test_update_rejects_quantity_above_stock(): void
    {
        $product = Product::first();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 1]])
            ->patch('/cart/'.$product->id, ['quantity' => $product->stock + 5])
            ->assertSessionHasErrors('quantity');

        $this->assertSame([$product->id => 1], session('cart'));
    }

    public function test_update_rejects_inactive_product(): void
    {
        $product = Product::first();
        $product->update(['is_active' => false]);

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 1]])
            ->patch('/cart/'.$product->id, ['quantity' => 2])
            ->assertSessionHasErrors('quantity');
    }

    public function test_update_rejects_product_not_in_cart(): void
    {
        $product = Product::first();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => []])
            ->patch('/cart/'.$product->id, ['quantity' => 2])
            ->assertSessionHasErrors('quantity');
    }

    public function test_customer_can_remove_product_from_cart(): void
    {
        $product = Product::first();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2, Product::latest('id')->first()->id => 1]])
            ->delete('/cart/'.$product->id)
            ->assertSessionHas('success');

        $this->assertArrayNotHasKey($product->id, session('cart'));
    }

    /* =====================================================================
     | KERANJANG — TAMPILAN & SANITASI
     * ===================================================================*/

    public function test_cart_page_shows_items_subtotal_and_total(): void
    {
        $productA = Product::where('stock', '>', 3)->firstOrFail();
        $productB = Product::where('id', '!=', $productA->id)->where('stock', '>', 1)->firstOrFail();

        $expectedSubtotalA = 3 * (float) $productA->price;
        $expectedTotal = $expectedSubtotalA + 1 * (float) $productB->price;

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$productA->id => 3, $productB->id => 1]])
            ->get('/cart')
            ->assertStatus(200)
            ->assertSee($productA->name)
            ->assertSee($productB->name)
            ->assertSee(number_format($expectedSubtotalA, 0, ',', '.'))
            ->assertSee(number_format($expectedTotal, 0, ',', '.'))
            ->assertDontSee('Keranjang Anda Masih Kosong');
    }

    public function test_cart_removes_inactive_product_when_viewed(): void
    {
        $product = Product::first();
        $product->update(['is_active' => false]);

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->get('/cart')
            ->assertStatus(200)
            ->assertSessionHas('cart', [])
            ->assertSessionHas('info');
    }

    public function test_cart_removes_product_with_zero_stock_when_viewed(): void
    {
        $product = Product::where('stock', '>', 0)->firstOrFail();
        $product->update(['stock' => 0]);

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->get('/cart')
            ->assertStatus(200)
            ->assertSessionHas('cart', [])
            ->assertSessionHas('info');
    }

    public function test_cart_adjusts_quantity_when_stock_decreased(): void
    {
        $product = Product::where('stock', '>', 5)->firstOrFail();
        $product->update(['stock' => 3]);

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 10]])
            ->get('/cart')
            ->assertStatus(200)
            ->assertSessionHas('cart', [$product->id => 3])
            ->assertSessionHas('info');
    }

    public function test_cart_quantity_in_database_never_negative(): void
    {
        // Semua produk tetap non-negatif setelah seluruh interaksi keranjang
        $product = Product::where('stock', '>', 0)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 1]])
            ->get('/cart');

        $this->assertSame(0, Product::where('stock', '<', 0)->count());
    }
}
