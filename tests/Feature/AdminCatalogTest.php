<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();

        Storage::fake('public');
    }

    /* =====================================================================
     | AUTHORIZATION
     * ===================================================================*/

    public function test_admin_can_access_all_catalog_pages(): void
    {
        $product = Product::first();

        $this->actingAs($this->admin)->get('/admin/categories')->assertStatus(200);
        $this->actingAs($this->admin)->get('/admin/categories/create')->assertStatus(200);
        $this->actingAs($this->admin)->get('/admin/categories/'.Category::first()->id.'/edit')->assertStatus(200);
        $this->actingAs($this->admin)->get('/admin/products')->assertStatus(200);
        $this->actingAs($this->admin)->get('/admin/products/create')->assertStatus(200);
        $this->actingAs($this->admin)->get('/admin/products/'.$product->id)->assertStatus(200);
        $this->actingAs($this->admin)->get('/admin/products/'.$product->id.'/edit')->assertStatus(200);
    }

    public function test_non_admin_roles_are_forbidden_from_catalog_routes(): void
    {
        $product = Product::first();
        $category = Category::first();

        $routes = [
            '/admin/categories',
            '/admin/categories/create',
            '/admin/categories/'.$category->id.'/edit',
            '/admin/products',
            '/admin/products/create',
            '/admin/products/'.$product->id,
            '/admin/products/'.$product->id.'/edit',
        ];

        foreach (['penjual@agungatk.com', 'kurir@agungatk.com', 'pelanggan@agungatk.com'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            foreach ($routes as $route) {
                $this->actingAs($user)->get($route)->assertStatus(403);
            }
        }
    }

    public function test_non_admin_cannot_create_or_delete_catalog_data(): void
    {
        $seller = User::where('email', 'penjual@agungatk.com')->firstOrFail();
        $category = Category::first();
        $product = Product::first();

        $this->actingAs($seller)->post('/admin/categories', ['name' => 'Kategori Gelap'])->assertStatus(403);
        $this->actingAs($seller)->put('/admin/categories/'.$category->id, ['name' => 'Diubah'])->assertStatus(403);
        $this->actingAs($seller)->delete('/admin/categories/'.$category->id)->assertStatus(403);
        $this->actingAs($seller)->post('/admin/products', $this->validProductPayload(['name' => 'Produk Gelap']))->assertStatus(403);
        $this->actingAs($seller)->delete('/admin/products/'.$product->id)->assertStatus(403);
        $this->actingAs($seller)->patch('/admin/products/'.$product->id.'/stock', ['stock' => 1])->assertStatus(403);
    }

    public function test_guest_is_redirected_to_login_from_catalog_routes(): void
    {
        $this->get('/admin/categories')->assertRedirect('/login');
        $this->get('/admin/products')->assertRedirect('/login');
    }

    /* =====================================================================
     | CRUD KATEGORI
     * ===================================================================*/

    public function test_admin_can_create_category(): void
    {
        $response = $this->actingAs($this->admin)->post('/admin/categories', [
            'name' => 'Perangkat Presentasi',
            'description' => 'Proyektor, pointer, dan kebutuhan presentasi.',
            'is_active' => '1',
        ]);

        $category = Category::where('name', 'Perangkat Presentasi')->first();
        $this->assertNotNull($category);
        $this->assertSame('perangkat-presentasi', $category->slug);
        $response->assertRedirect('/admin/categories');
    }

    public function test_category_name_is_required(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/categories', ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame(6, Category::count());
    }

    public function test_category_image_must_be_a_valid_image(): void
    {
        $fakeText = UploadedFile::fake()->create('dokumen.txt', 10, 'text/plain');

        $this->actingAs($this->admin)
            ->post('/admin/categories', ['name' => 'Kategori Gambar', 'image' => $fakeText])
            ->assertSessionHasErrors('image');
    }

    public function test_admin_can_update_category(): void
    {
        $category = Category::first();

        $this->actingAs($this->admin)->put('/admin/categories/'.$category->id, [
            'name' => 'Kertas & Karton Premium',
            'description' => 'Diperbarui oleh admin.',
            'is_active' => '1',
        ])->assertRedirect('/admin/categories');

        $this->assertSame('Kertas & Karton Premium', $category->fresh()->name);
    }

    public function test_admin_can_delete_empty_category(): void
    {
        $category = Category::create([
            'name' => 'Kategori Kosong',
            'slug' => 'kategori-kosong',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->delete('/admin/categories/'.$category->id)
            ->assertRedirect('/admin/categories');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_category_with_products_cannot_be_deleted(): void
    {
        $category = Category::whereHas('products')->first();
        $this->assertNotNull($category);

        $this->actingAs($this->admin)
            ->delete('/admin/categories/'.$category->id)
            ->assertSessionHasErrors('error');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    /* =====================================================================
     | CRUD PRODUK
     * ===================================================================*/

    public function test_admin_can_create_product(): void
    {
        $category = Category::first();

        $response = $this->actingAs($this->admin)->post(
            '/admin/products',
            $this->validProductPayload(['category_id' => $category->id, 'name' => 'Pensil 2B Standar'])
        );

        $product = Product::where('name', 'Pensil 2B Standar')->first();
        $this->assertNotNull($product);
        $this->assertSame('pensil-2b-standar', $product->slug);
        $response->assertRedirect('/admin/products/'.$product->id);
    }

    public function test_product_requires_required_fields(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/products', [
                'name' => '',
                'sku' => '',
                'category_id' => '',
                'unit' => '',
                'price' => '',
                'stock' => '',
                'min_stock' => '',
                'weight' => '',
            ])
            ->assertSessionHasErrors(['name', 'sku', 'category_id', 'unit', 'price', 'stock', 'min_stock', 'weight']);
    }

    public function test_product_stock_and_price_cannot_be_negative(): void
    {
        $category = Category::first();

        $this->actingAs($this->admin)->post('/admin/products', $this->validProductPayload([
            'category_id' => $category->id,
            'sku' => 'ATK-NEG-001',
            'stock' => -5,
            'price' => -1000,
        ]))->assertSessionHasErrors(['stock', 'price']);
    }

    public function test_duplicate_sku_is_rejected(): void
    {
        $existing = Product::first();
        $category = Category::first();

        $this->actingAs($this->admin)->post('/admin/products', $this->validProductPayload([
            'category_id' => $category->id,
            'sku' => $existing->sku,
        ]))->assertSessionHasErrors('sku');
    }

    public function test_admin_can_update_product(): void
    {
        $product = Product::first();
        $payload = $this->validProductPayload([
            'name' => 'Produk Hasil Edit',
            'sku' => $product->sku,
            'category_id' => $product->category_id,
            'price' => 99999,
        ]);

        $this->actingAs($this->admin)
            ->put('/admin/products/'.$product->id, $payload)
            ->assertRedirect('/admin/products/'.$product->id);

        $fresh = $product->fresh();
        $this->assertSame('Produk Hasil Edit', $fresh->name);
        $this->assertSame('99999', (string) (int) $fresh->price);
    }

    public function test_admin_can_delete_product_and_its_images(): void
    {
        $product = Product::create([
            'category_id' => Category::first()->id,
            'name' => 'Produk Untuk Dihapus',
            'slug' => 'produk-untuk-dihapus',
            'sku' => 'ATK-DEL-001',
            'price' => 1000,
            'stock' => 1,
            'min_stock' => 1,
            'unit' => 'pcs',
            'weight' => 100,
        ]);

        Storage::disk('public')->put('products/akan-dihapus.jpg', 'dummy-image');
        $image = ProductImage::create([
            'product_id' => $product->id,
            'image_path' => 'products/akan-dihapus.jpg',
            'is_primary' => true,
        ]);

        $this->actingAs($this->admin)
            ->delete('/admin/products/'.$product->id)
            ->assertRedirect('/admin/products');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
        Storage::disk('public')->assertMissing('products/akan-dihapus.jpg');
    }

    /* =====================================================================
     | UPLOAD GAMBAR PRODUK
     * ===================================================================*/

    public function test_product_images_are_stored_on_public_disk_when_creating(): void
    {
        $category = Category::first();

        $this->actingAs($this->admin)->post('/admin/products', $this->validProductPayload([
            'category_id' => $category->id,
            'sku' => 'ATK-IMG-001',
            'name' => 'Produk Dengan Gambar',
            'images' => [
                UploadedFile::fake()->image('satu.jpg', 200, 200),
                UploadedFile::fake()->image('dua.jpg', 200, 200),
            ],
        ]));

        $product = Product::where('sku', 'ATK-IMG-001')->firstOrFail();
        $this->assertCount(2, $product->productImages);

        // Gambar pertama menjadi gambar utama
        $this->assertTrue($product->productImages()->where('is_primary', true)->count() === 1);

        foreach ($product->productImages as $image) {
            $this->assertStringStartsWith('products/', $image->image_path);
            Storage::disk('public')->assertExists($image->image_path);
        }
    }

    public function test_product_image_can_be_uploaded_via_show_page(): void
    {
        $product = Product::first();
        $before = $product->productImages()->count();

        $this->actingAs($this->admin)->post('/admin/products/'.$product->id.'/images', [
            'images' => [UploadedFile::fake()->image('baru.jpg', 300, 300)],
        ])->assertSessionHas('success');

        $this->assertSame($before + 1, $product->productImages()->count());
    }

    public function test_non_image_file_upload_is_rejected(): void
    {
        $product = Product::first();
        $before = $product->productImages()->count();

        $this->actingAs($this->admin)->post('/admin/products/'.$product->id.'/images', [
            'images' => [UploadedFile::fake()->create('bukan-gambar.pdf', 100, 'application/pdf')],
        ])->assertSessionHasErrors('images.0');

        $this->assertSame($before, $product->productImages()->count());
    }

    public function test_primary_image_can_be_changed(): void
    {
        $product = Product::create([
            'category_id' => Category::first()->id,
            'name' => 'Produk Dua Gambar',
            'slug' => 'produk-dua-gambar',
            'sku' => 'ATK-PRM-001',
            'price' => 5000,
            'stock' => 5,
            'min_stock' => 2,
            'unit' => 'pcs',
            'weight' => 100,
        ]);

        foreach (['first.jpg', 'second.jpg'] as $index => $file) {
            Storage::disk('public')->put('products/'.$file, 'data-'.$index);
            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => 'products/'.$file,
                'is_primary' => $index === 0,
                'sort_order' => $index,
            ]);
        }

        $second = $product->productImages()->where('image_path', 'products/second.jpg')->first();

        $this->actingAs($this->admin)
            ->patch('/admin/products/'.$product->id.'/images/'.$second->id.'/primary')
            ->assertSessionHas('success');

        $this->assertTrue($second->fresh()->is_primary);
        $this->assertSame(1, $product->productImages()->where('is_primary', true)->count());
    }

    public function test_product_image_delete_removes_file_and_reassigns_primary(): void
    {
        $product = Product::create([
            'category_id' => Category::first()->id,
            'name' => 'Produk Gambar Dihapus',
            'slug' => 'produk-gambar-dihapus',
            'sku' => 'ATK-DEL-IMG-001',
            'price' => 5000,
            'stock' => 5,
            'min_stock' => 2,
            'unit' => 'pcs',
            'weight' => 100,
        ]);

        foreach (['utama.jpg', 'cadangan.jpg'] as $index => $file) {
            Storage::disk('public')->put('products/'.$file, 'data-'.$index);
            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => 'products/'.$file,
                'is_primary' => $index === 0,
                'sort_order' => $index,
            ]);
        }

        $primary = $product->productImages()->where('image_path', 'products/utama.jpg')->first();

        $this->actingAs($this->admin)
            ->delete('/admin/products/'.$product->id.'/images/'.$primary->id)
            ->assertSessionHas('success');

        // File dan record terhapus, gambar berikutnya menjadi utama
        Storage::disk('public')->assertMissing('products/utama.jpg');
        $this->assertDatabaseMissing('product_images', ['id' => $primary->id]);
        $newPrimary = $product->productImages()->where('image_path', 'products/cadangan.jpg')->first();
        $this->assertTrue($newPrimary->fresh()->is_primary);
    }

    public function test_image_belonging_to_other_product_returns_404(): void
    {
        $productA = Product::whereHas('productImages')->first();
        $otherProduct = Product::whereDoesntHave('productImages')->first() ?? Product::where('id', '!=', $productA->id)->first();
        $foreignImage = $productA->productImages()->first();

        $this->actingAs($this->admin)
            ->delete('/admin/products/'.$otherProduct->id.'/images/'.$foreignImage->id)
            ->assertStatus(404);
    }

    /* =====================================================================
     | SEARCH, FILTER, PAGINATION
     * ===================================================================*/

    public function test_product_search_filters_results(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/products?q=PaperOne')
            ->assertStatus(200)
            ->assertSee('Kertas HVS PaperOne A4 75gr (Rim)')
            ->assertDontSee('Pulpen Gel Pilot G2');

        $this->actingAs($this->admin)
            ->get('/admin/products?q=tidak-ada-peluang-kosong')
            ->assertStatus(200)
            ->assertSee('tidak ada yang cocok');
    }

    public function test_product_search_by_sku_works(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/products?q=ATK-PLP-001')
            ->assertStatus(200)
            ->assertSee('Pulpen Gel Pilot G2');
    }

    public function test_product_category_filter_works(): void
    {
        $kertas = Category::where('slug', 'kertas-dan-karton')->firstOrFail();
        $expected = Product::where('category_id', $kertas->id)->pluck('name');

        $response = $this->actingAs($this->admin)->get('/admin/products?category_id='.$kertas->id);
        $response->assertStatus(200);

        foreach ($expected as $name) {
            $response->assertSee($name);
        }

        $otherCategoryProducts = Product::where('category_id', '!=', $kertas->id)->pluck('name');
        foreach ($otherCategoryProducts as $name) {
            $response->assertDontSee($name);
        }
    }

    public function test_product_status_filter_works(): void
    {
        Product::first()->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->get('/admin/products?status=inactive')
            ->assertStatus(200)
            ->assertSee('Nonaktif');

        $activeOnly = Product::where('is_active', true)->count();
        $response = $this->actingAs($this->admin)->get('/admin/products?status=active');
        $response->assertStatus(200);
        $this->assertSame(0, substr_count($response->getContent(), 'badge bg-secondary-subtle text-secondary">Nonaktif'));
        $this->assertGreaterThan(0, $activeOnly);
    }

    public function test_low_stock_filter_works(): void
    {
        $category = Category::first();
        Product::create([
            'category_id' => $category->id,
            'name' => 'Stok Menipis Produk',
            'slug' => 'stok-menipis-produk',
            'sku' => 'ATK-LOW-001',
            'price' => 1000,
            'stock' => 3,
            'min_stock' => 10,
            'unit' => 'pcs',
            'weight' => 50,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/products?stock_condition=low');
        $response->assertStatus(200)->assertSee('Stok Menipis Produk');
        $response->assertDontSee('Pulpen Gel Pilot G2');

        // Produk habus tidak ikut dalam filter "menipis"
        $this->actingAs($this->admin)->get('/admin/products?stock_condition=out')->assertStatus(200);
    }

    public function test_product_list_is_paginated(): void
    {
        $category = Category::first();

        // Seeder sudah menyediakan 8 produk; tambah agar melebihi 10 per halaman
        for ($i = 1; $i <= 5; $i++) {
            Product::create([
                'category_id' => $category->id,
                'name' => "Produk Tambahan {$i}",
                'slug' => "produk-tambahan-{$i}",
                'sku' => "ATK-PAG-{$i}",
                'price' => 1000 + $i,
                'stock' => 10,
                'min_stock' => 2,
                'unit' => 'pcs',
                'weight' => 100,
            ]);
        }

        $this->assertGreaterThan(10, Product::count());

        $page1 = $this->actingAs($this->admin)->get('/admin/products?page=1');
        $page1->assertStatus(200);
        $this->assertStringContainsString('page=2', $page1->getContent());

        $page2 = $this->actingAs($this->admin)->get('/admin/products?page=2');
        $page2->assertStatus(200);
        $page2->assertSee('11–13 dari 13 produk');
    }

    public function test_category_list_is_paginated(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            Category::create([
                'name' => "Kategori Tambahan {$i}",
                'slug' => "kategori-tambahan-{$i}",
                'is_active' => true,
            ]);
        }

        $this->assertGreaterThan(10, Category::count());

        $page1 = $this->actingAs($this->admin)->get('/admin/categories?page=1');
        $page1->assertStatus(200);
        $this->assertStringContainsString('page=2', $page1->getContent());

        $page2 = $this->actingAs($this->admin)->get('/admin/categories?page=2');
        $page2->assertStatus(200);
        $page2->assertSee('dari 12 kategori');
    }

    public function test_search_and_filters_are_preserved_in_pagination(): void
    {
        $category = Category::first();

        // Buat hasil pencarian "kertas" melebihi 10 item agar pagination tampil
        for ($i = 1; $i <= 9; $i++) {
            Product::create([
                'category_id' => $category->id,
                'name' => "Kertas Uji Pagination {$i}",
                'slug' => "kertas-uji-pagination-{$i}",
                'sku' => "ATK-QS-{$i}",
                'price' => 1000,
                'stock' => 10,
                'min_stock' => 2,
                'unit' => 'rim',
                'weight' => 100,
            ]);
        }

        $response = $this->actingAs($this->admin)->get('/admin/products?q=kertas&page=1');
        $response->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringContainsString('page=2', $content);
        // withQueryString() harus mempertahankan parameter pencarian di URL pagination
        $this->assertStringContainsString('q=kertas', $content);
    }

    /* =====================================================================
     | STOK
     * ===================================================================*/

    public function test_admin_can_update_stock(): void
    {
        $product = Product::first();

        $this->actingAs($this->admin)->patch('/admin/products/'.$product->id.'/stock', [
            'stock' => 77,
            'min_stock' => 8,
        ])->assertSessionHas('success');

        $fresh = $product->fresh();
        $this->assertSame(77, (int) $fresh->stock);
        $this->assertSame(8, (int) $fresh->min_stock);
    }

    public function test_stock_update_rejects_negative_value(): void
    {
        $product = Product::first();
        $originalStock = (int) $product->stock;

        $this->actingAs($this->admin)
            ->patch('/admin/products/'.$product->id.'/stock', ['stock' => -10])
            ->assertSessionHasErrors('stock');

        $this->assertSame($originalStock, (int) $product->fresh()->stock);
    }

    public function test_stock_update_requires_numeric_value(): void
    {
        $product = Product::first();

        $this->actingAs($this->admin)
            ->patch('/admin/products/'.$product->id.'/stock', ['stock' => 'bukan-angka'])
            ->assertSessionHasErrors('stock');
    }

    public function test_low_stock_products_are_reported_correctly(): void
    {
        $category = Category::first();

        Product::create([
            'category_id' => $category->id,
            'name' => 'Hampir Habis',
            'slug' => 'hampir-habis',
            'sku' => 'ATK-LOW-002',
            'price' => 1000,
            'stock' => 2,
            'min_stock' => 5,
            'unit' => 'pcs',
            'weight' => 10,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Sudah Habis',
            'slug' => 'sudah-habis',
            'sku' => 'ATK-OUT-001',
            'price' => 1000,
            'stock' => 0,
            'min_stock' => 5,
            'unit' => 'pcs',
            'weight' => 10,
        ]);

        // Stok rendah = stok <= min_stock DAN masih > 0
        $this->assertGreaterThanOrEqual(1, Product::lowStock()->count());

        // Tidak ada produk dengan stok negatif di database
        $this->assertSame(0, Product::where('stock', '<', 0)->count());

        $response = $this->actingAs($this->admin)->get('/admin/products');
        $response->assertSee('Hampir Habis')->assertSee('Sudah Habis');
        $response->assertSee('Stok Habis');
    }

    public function test_product_stock_cannot_go_negative_even_when_empty_string_sent(): void
    {
        $product = Product::first();
        $original = (int) $product->stock;

        $this->actingAs($this->admin)
            ->patch('/admin/products/'.$product->id.'/stock', ['stock' => ''])
            ->assertSessionHasErrors('stock');

        $this->assertSame($original, (int) $product->fresh()->stock);
    }

    /**
     * Slug manual yang dinormalisasi menjadi slug kategori lain (tabrakan)
     * harus mendapat sufiks numerik, bukan QueryException 500.
     */
    public function test_manual_category_slug_collision_receives_numeric_suffix_instead_of_error(): void
    {
        $name = 'Kategori Uji '.uniqid();
        $slug = Str::slug($name);

        $this->actingAs($this->admin)->post('/admin/categories', [
            'name' => $name,
            'slug' => $slug,
        ])->assertSessionHas('success');

        // Input mentah tidak identik dengan slug tersimpan (lolos validasi
        // unique) tetapi HASIL normalisasinya menabrak slug pertama.
        $this->actingAs($this->admin)->post('/admin/categories', [
            'name' => $name.' Premium',
            'slug' => strtoupper($name).'!',
        ])->assertSessionHas('success');

        $this->assertNotNull(Category::where('slug', $slug)->first());
        $this->assertNotNull(Category::where('slug', $slug.'-1')->first());
    }

    /* =====================================================================
     | HELPERS
     * ===================================================================*/

    /**
     * Payload valid untuk membuat produk (dapat dioverride per test).
     */
    private function validProductPayload(array $override = []): array
    {
        return array_merge([
            'name' => 'Buku Tulis Sidu 38 Lembar',
            'sku' => 'ATK-NEW-'.uniqid(),
            'category_id' => Category::first()->id,
            'brand' => 'Sidu',
            'unit' => 'pcs',
            'description' => 'Buku tulis untuk kebutuhan sekolah.',
            'price' => 8500,
            'cost_price' => 6000,
            'stock' => 25,
            'min_stock' => 5,
            'weight' => 120,
            'is_active' => '1',
        ], $override);
    }
}
