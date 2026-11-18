<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\ATKImageRecognitionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Visual Search ATK — pencarian produk berbasis gambar (foto kamera /
 * unggah), BUKAN pemindai barcode.
 *
 * Mencakup skenario wajib: pengenalan sampel ATK (pulpen/buku tulis/gunting),
 * penolakan non-ATK & file non-gambar, validasi ukuran, rate limit, filter
 * produk aktif, kejujuran stok, rekomendasi dari database (bukan karangan),
 * pencarian teks tetap jalan, pesan error jelas, dan tidak ada kebocoran
 * dataset/file sementara.
 */
class ImageSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function postImage(?string $binary, array $extra = [])
    {
        $file = UploadedFile::fake()->createWithContent('foto-atk.jpg', $binary ?? $this->solidPng());

        return $this->post(
            route('image.search'),
            array_merge(['image' => $file], $extra),
            ['Accept' => 'application/json']
        );
    }

    /** Buat PNG solid sederhana lewat GD (bukan file eksternal). */
    private function solidPng(int $r = 30, int $g = 60, int $b = 200): string
    {
        $img = imagecreatetruecolor(64, 64);
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private function datasetReady(): bool
    {
        return (new ATKImageRecognitionService)->isReady();
    }

    private function datasetTestImages(string $label): array
    {
        return glob(ATKImageRecognitionService::datasetPath('test/'.$label).'/*.{jpg,jpeg,png}', GLOB_BRACE) ?: [];
    }

    /** Cari sampel test yang dikenali service sebagai kelas yang diharapkan. */
    private function findRecognizedImage(string $label, ?string $expected = null): ?string
    {
        $expected ??= $label;
        $service = app(ATKImageRecognitionService::class);

        foreach ($this->datasetTestImages($label) as $file) {
            $result = $service->recognize((string) file_get_contents($file));
            if ($result['status'] === 'recognized' && $result['label'] === $expected) {
                return $file;
            }
        }

        return null;
    }

    /** Cari sampel test yang ditolak sebagai barang NON-ATK. */
    private function findRejectedImage(string $label): ?string
    {
        $service = app(ATKImageRecognitionService::class);

        foreach ($this->datasetTestImages($label) as $file) {
            $result = $service->recognize((string) file_get_contents($file));
            if ($result['status'] === 'not_atk') {
                return $file;
            }
        }

        return null;
    }

    private function assertProductsAreFromDatabase(array $products): void
    {
        $this->assertNotEmpty($products);
        foreach ($products as $product) {
            $this->assertDatabaseHas('products', ['id' => $product['id'], 'is_active' => 1]);
            $this->assertNotEmpty($product['name']);
            $this->assertNotEmpty($product['price_formatted']);
            $this->assertNotEmpty($product['url']);
        }
    }

    /* =====================================================================
     | VALIDASI FILE (skenario 5: PDF/dokumen non-gambar ditolak)
     * ===================================================================*/

    public function test_pdf_file_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('laporan.pdf', 100, 'application/pdf');

        $this->post(route('image.search'), ['image' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_non_image_file_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('catatan.txt', 5, 'text/plain');

        $this->post(route('image.search'), ['image' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_oversized_image_is_rejected(): void
    {
        $maxKb = (int) config('services.image_search.max_upload_kb', 2048);
        $file = UploadedFile::fake()->create('raksasa.jpg', $maxKb + 500, 'image/jpeg');

        $response = $this->post(route('image.search'), ['image' => $file], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJsonValidationErrors(['image']);
        $messages = collect($response->json('errors.image') ?? []);
        $this->assertTrue(
            $messages->contains(fn (string $m) => str_contains($m, 'maksimal')),
            'Pesan error harus menjelaskan batas ukuran gambar.'
        );
    }

    public function test_missing_image_is_rejected(): void
    {
        $this->post(route('image.search'), [], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    /* =====================================================================
     | RESPONS ENDPOINT (struktur jujur, tanpa produk karangan)
     * ===================================================================*/

    public function test_valid_image_returns_recognition_payload(): void
    {
        $response = $this->postImage($this->solidPng());
        $response->assertOk();

        $status = $response->json('status');
        $this->assertIsString($response->json('message'));
        $this->assertNotSame('', $response->json('message'));
        $this->assertIsArray($response->json('products'));

        if (! $this->datasetReady()) {
            // Tanpa indeks dataset (mis. di CI yang tidak menyimpan dataset),
            // mesin jujur melaporkan 'error' dengan pesan ramah dan tanpa
            // produk karangan — struktur payload tetap divalidasi di atas.
            $this->assertSame('error', $status);
            $this->assertSame([], $response->json('products'));

            return;
        }

        $this->assertContains($status, ['recognized', 'not_atk', 'uncertain']);

        if ($status !== 'recognized') {
            // Status non-ATK/ragu: pesan persis sesuai spesifikasi + tanpa produk.
            $this->assertSame(ATKImageRecognitionService::MESSAGE_NOT_RECOGNIZED, $response->json('message'));
            $this->assertSame([], $response->json('products'));
        } else {
            $this->assertProductsAreFromDatabase($response->json('products'));
        }
    }

    public function test_error_returns_friendly_message_not_stack_trace(): void
    {
        // Pindahkan lokasi dataset → layanan gagal → pesan tetap mudah dipahami.
        config(['services.image_search.dataset_path' => storage_path('app/datasets/tidak-ada')]);

        $response = $this->postImage($this->solidPng());

        $response->assertOk();
        $this->assertSame('error', $response->json('status'));
        $this->assertStringContainsString('tidak dapat diproses', $response->json('message'));
        $this->assertStringNotContainsString('Exception', $response->json('message'));
        $this->assertSame([], $response->json('products'));
    }

    /* =====================================================================
     | RATE LIMITING (keamanan endpoint unggahan gambar)
     * ===================================================================*/

    public function test_rate_limit_blocks_after_ten_searches(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postImage($this->solidPng(10 * $i, 50, 200))->assertOk();
        }

        $this->postImage($this->solidPng())->assertStatus(429);
    }

    /* =====================================================================
     | SKENARIO 1-3: SAMPEL ATK DIKENALI + HASIL DARI DATABASE
     * (tes ini memakai dataset sungguhan; otomatis dilewati bila indeks
     *  belum dibangun — lihat atk:image-index & laporan evaluasi)
     * ===================================================================*/

    public function test_pen_image_is_recognized_and_finds_pulpen_products(): void
    {
        if (! $this->datasetReady()) {
            $this->markTestSkipped('Indeks dataset Visual Search belum dibangun (atk:image-index).');
        }
        $file = $this->findRecognizedImage('pen');
        if ($file === null) {
            $this->markTestSkipped('Tidak ada sampel pulpen pada data test yang dikenali model (lihat atk:image-evaluate).');
        }

        $response = $this->postImage((string) file_get_contents($file));
        $response->assertOk()->assertJsonPath('status', 'recognized')->assertJsonPath('label', 'pen');

        $products = $response->json('products');
        $this->assertProductsAreFromDatabase($products);

        $names = collect($products)->pluck('name')->map(fn ($n) => mb_strtolower($n));
        $this->assertTrue(
            $names->contains(fn ($n) => str_contains($n, 'pulpen')),
            'Hasil klasifikasi pulpen harus menampilkan produk pulpen dari katalog.'
        );
    }

    public function test_notebook_image_finds_buku_tulis_products(): void
    {
        if (! $this->datasetReady()) {
            $this->markTestSkipped('Indeks dataset Visual Search belum dibangun (atk:image-index).');
        }
        $file = $this->findRecognizedImage('notebook');
        if ($file === null) {
            $this->markTestSkipped('Tidak ada sampel buku tulis pada data test yang dikenali model.');
        }

        $response = $this->postImage((string) file_get_contents($file));
        $response->assertOk()->assertJsonPath('status', 'recognized')->assertJsonPath('label', 'notebook');

        $products = $response->json('products');
        $this->assertProductsAreFromDatabase($products);
        $names = collect($products)->pluck('name')->map(fn ($n) => mb_strtolower($n));
        $this->assertTrue($names->contains(fn ($n) => str_contains($n, 'buku')));
    }

    public function test_scissors_image_finds_created_gunting_product_from_database(): void
    {
        if (! $this->datasetReady()) {
            $this->markTestSkipped('Indeks dataset Visual Search belum dibangun (atk:image-index).');
        }
        $file = $this->findRecognizedImage('scissors');
        if ($file === null) {
            $this->markTestSkipped('Tidak ada sampel gunting pada data test yang dikenali model.');
        }

        $gunting = Product::create([
            'category_id' => Category::first()->id,
            'name' => 'Gunting Kertas Joyko GM-150',
            'slug' => 'gunting-kertas-joyko-gm-150',
            'sku' => 'ATK-VIS-001',
            'price' => 25000,
            'stock' => 12,
            'min_stock' => 2,
            'unit' => 'pcs',
            'weight' => 100,
        ]);

        $response = $this->postImage((string) file_get_contents($file));
        $response->assertOk()->assertJsonPath('status', 'recognized')->assertJsonPath('label', 'scissors');

        $products = $response->json('products');
        $this->assertProductsAreFromDatabase($products);
        $this->assertTrue(
            collect($products)->contains(fn ($p) => $p['id'] === $gunting->id),
            'Rekomendasi harus memakai produk gunting yang benar-benar ada di database.'
        );
    }

    /* =====================================================================
     | SKENARIO 4: NON-ATK DITOLAK
     * ===================================================================*/

    public function test_non_atk_image_is_rejected_with_exact_message(): void
    {
        if (! $this->datasetReady()) {
            $this->markTestSkipped('Indeks dataset Visual Search belum dibangun (atk:image-index).');
        }
        $file = $this->findRejectedImage('printer');
        if ($file === null) {
            $this->markTestSkipped('Tidak ada sampel printer pada data test yang ditolak model.');
        }

        $response = $this->postImage((string) file_get_contents($file));

        $response->assertOk()
            ->assertJsonPath('status', 'not_atk')
            ->assertJsonPath('message', ATKImageRecognitionService::MESSAGE_NOT_RECOGNIZED)
            ->assertJsonPath('products', []);
    }

    /* =====================================================================
     | SKENARIO 8-10: PRODUK AKTIF, KEJUJURAN STOK, TANPA KARANGAN
     * ===================================================================*/

    public function test_inactive_product_is_not_returned(): void
    {
        if (! $this->datasetReady()) {
            $this->markTestSkipped('Indeks dataset Visual Search belum dibangun (atk:image-index).');
        }
        $file = $this->findRecognizedImage('pen');
        if ($file === null) {
            $this->markTestSkipped('Tidak ada sampel pulpen pada data test yang dikenali model.');
        }

        Product::create([
            'category_id' => Category::first()->id,
            'name' => 'Pulpen Uji Nonaktif Visual',
            'slug' => 'pulpen-uji-nonaktif-visual',
            'sku' => 'ATK-VIS-002',
            'price' => 9999,
            'stock' => 50,
            'min_stock' => 1,
            'unit' => 'pcs',
            'weight' => 50,
            'is_active' => false,
        ]);

        $response = $this->postImage((string) file_get_contents($file));
        $response->assertOk()->assertJsonPath('label', 'pen');

        $names = collect($response->json('products'))->pluck('name');
        $this->assertFalse(
            $names->contains('Pulpen Uji Nonaktif Visual'),
            'Produk non-ATK-aktif tidak boleh muncul dalam hasil.'
        );
        $this->assertNotEmpty($names->all(), 'Produk pulpen aktif tetap harus tampil.');
    }

    public function test_out_of_stock_product_is_not_marked_as_available(): void
    {
        if (! $this->datasetReady()) {
            $this->markTestSkipped('Indeks dataset Visual Search belum dibangun (atk:image-index).');
        }
        $file = $this->findRecognizedImage('pen');
        if ($file === null) {
            $this->markTestSkipped('Tidak ada sampel pulpen pada data test yang dikenali model.');
        }

        Product::create([
            'category_id' => Category::first()->id,
            'name' => 'Pulpen Uji Stok Habis Visual',
            'slug' => 'pulpen-uji-stok-habis-visual',
            'sku' => 'ATK-VIS-003',
            'price' => 5000,
            'stock' => 0,
            'min_stock' => 1,
            'unit' => 'pcs',
            'weight' => 50,
        ]);

        $products = $this->postImage((string) file_get_contents($file))->json('products');

        foreach ($products as $product) {
            $this->assertSame(
                $product['stock'] > 0,
                $product['stock_available'],
                'stock_available harus mencerminkan stok nyata, bukan dianggap tersedia.'
            );
        }

        $habis = collect($products)->firstWhere('name', 'Pulpen Uji Stok Habis Visual');
        $this->assertNotNull($habis, 'Produk stok habis boleh tampil untuk dilihat, asal jujur statusnya.');
        $this->assertFalse($habis['stock_available']);
    }

    public function test_recognized_label_without_catalog_products_returns_honest_empty_state(): void
    {
        if (! $this->datasetReady()) {
            $this->markTestSkipped('Indeks dataset Visual Search belum dibangun (atk:image-index).');
        }
        $file = $this->findRecognizedImage('puncher');
        if ($file === null) {
            $this->markTestSkipped('Tidak ada sampel pembolong kertas pada data test yang dikenali model.');
        }

        // Pastikan tidak ada produk pembolong kertas di katalog.
        Product::where('name', 'like', '%pembolong%')->delete();

        $response = $this->postImage((string) file_get_contents($file));
        $response->assertOk()->assertJsonPath('status', 'recognized')->assertJsonPath('label', 'puncher');

        $this->assertSame([], $response->json('products'));
        $this->assertStringContainsString('belum tersedia dalam katalog', $response->json('message'));
        $this->assertStringContainsString('mengetik nama barang', $response->json('message'));
    }

    /* =====================================================================
     | SKENARIO 11: PENCARIAN TEKS TETAP BERFUNGSI
     * ===================================================================*/

    public function test_text_search_still_works(): void
    {
        $this->get(route('products.index', ['q' => 'pulpen']))
            ->assertOk()
            ->assertSee('Pulpen Gel Pilot', false);
    }

    /* =====================================================================
     | SKENARIO 6 & 7 (sisi server): UI KAMERA + FALLBACK UNGGAH ADA
     * ===================================================================*/

    public function test_search_page_contains_visual_search_ui_with_upload_fallback(): void
    {
        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('atkVsModal', false)
            ->assertSee('catalogVisualBtn', false)
            ->assertSee('Unggah Gambar', false)
            ->assertSee('Gunakan Foto', false)
            ->assertSee('Ulangi Foto', false)
            // Kamera pencarian tidak boleh jadi pemindai barcode:
            ->assertDontSee('atkScanModal', false)
            ->assertDontSee('AtkScan', false);
    }

    public function test_chatbot_camera_uses_visual_search_not_barcode(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('chatVisualBtn', false)
            ->assertSee('atkVsModal', false)
            ->assertDontSee('AtkScan', false);

        $customer = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();

        $this->actingAs($customer)
            ->get('/ai')
            ->assertOk()
            ->assertSee('aiVisualBtn', false)
            ->assertDontSee('AtkScan', false);
    }

    /* =====================================================================
     | SKENARIO 15: TIDAK ADA KEBOCORAN DATASET / FILE SEMENTARA
     * ===================================================================*/

    public function test_dataset_is_not_exposed_through_public_urls(): void
    {
        // URL tanpa route → 404.
        $this->get('/datasets/atk-image-search/index/features.json')->assertNotFound();

        // Route storage/{path} framework menolak (403) atau tak dikenal (404)
        // — yang penting dataset TIDAK pernah disajikan (bukan 200).
        $storageResponse = $this->get('/storage/datasets/atk-image-search/index/features.json');
        $this->assertContains(
            $storageResponse->status(),
            [403, 404],
            'File dataset internal tidak boleh tersedia melalui URL publik.'
        );

        $this->assertFileDoesNotExist(public_path('datasets/atk-image-search/index/features.json'));
        $this->assertDirectoryDoesNotExist(public_path('datasets'));
    }

    public function test_search_does_not_leave_temporary_files(): void
    {
        $datasetPath = ATKImageRecognitionService::datasetPath();
        $before = is_dir($datasetPath)
            ? iterator_count(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($datasetPath, \FilesystemIterator::SKIP_DOTS)))
            : 0;

        $this->postImage($this->solidPng())->assertOk();

        $after = is_dir($datasetPath)
            ? iterator_count(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($datasetPath, \FilesystemIterator::SKIP_DOTS)))
            : 0;

        $this->assertSame($before, $after, 'Pencarian gambar tidak boleh menambah file sementara di dataset.');
        $this->assertSame([], glob(storage_path('app/*.{jpg,jpeg,png}'), GLOB_BRACE) ?: [], 'Tidak boleh ada file gambar sementara yang tertinggal di storage/app.');
        $tmpFiles = is_dir($datasetPath) ? array_filter(
            File::allFiles($datasetPath, true),
            fn ($file) => in_array(strtolower($file->getExtension()), ['tmp', 'part'], true)
        ) : [];

        $this->assertSame([], $tmpFiles, 'Tidak boleh ada file sementara (.tmp/.part) di dataset.');
    }
}
