<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Services\ATKImageRecognitionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integrasi YOLOv8 — deteksi objek lewat layanan Python terpisah
 * (ai-service/server.py) untuk endpoint /image-search (katalog & chatbot).
 *
 * Layanan SELALU dipalsukan lewat Http::fake sehingga suite tidak pernah
 * bergantung pada proses Python eksternal. Setiap test mengunci satu keputusan:
 *   - keputusan dari YOLO (engine 'yolo') + produk dari database,
 *   - penolakan kelas non-ATK oleh model,
 *   - keyakinan rendah / margin tipis / label asing → uncertain,
 *   - layanan mati → fallback ke mesin bawaan (engine 'gd'),
 *   - driver 'gd' tidak memanggil layanan sama sekali,
 *   - konsistensi hasil katalog vs chatbot untuk gambar yang sama,
 *   - harga & stok persis nilai database (bukan karangan),
 *   - gambar rusak → respons 'error' yang jujur.
 *
 * (phpunit.xml memakai IMAGE_SEARCH_DRIVER=gd untuk suite lama; tes di sini
 * mengaktifkan integrasi YOLO secara eksplisit lewat config()->set().)
 */
class YoloImageSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        config()->set('services.image_search.driver', 'hybrid');
        config()->set('services.image_search.service_url', 'http://yolo.test');
        config()->set('services.image_search.yolo_min_confidence', 0.45);
        config()->set('services.image_search.yolo_min_margin', 0.02);
    }

    /* ===================== helper (pola ImageSearchTest) ===================== */

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

    /** Pasang fake balasan POST /detect milik layanan YOLO. */
    private function fakeDetect(array $detections): void
    {
        Http::fake([
            'yolo.test/*' => Http::response([
                'ok' => true,
                'detections' => $detections,
                'elapsed_ms' => 7.5,
                'model' => 'yolov8n-atk.pt',
            ]),
        ]);
    }

    private function datasetReady(): bool
    {
        return (new ATKImageRecognitionService)->isReady();
    }

    /** Produk nyata di database dengan harga & stok yang diketahui persis. */
    private function createProduct(string $name, string $slug, string $sku, float $price, int $stock): Product
    {
        return Product::create([
            'category_id' => Category::first()->id,
            'name' => $name,
            'slug' => $slug,
            'sku' => $sku,
            'price' => $price,
            'stock' => $stock,
            'min_stock' => 2,
            'unit' => 'pcs',
            'weight' => 100,
        ]);
    }

    /* ============ keputusan YOLO: dikenali + produk database yang jujur ============ */

    public function test_yolo_pen_detection_returns_recognized_with_exact_database_price_and_stock(): void
    {
        $pulpen = $this->createProduct('Pulpen Gel Pilot 0.5mm (Tes YOLO)', 'pulpen-gel-pilot-05mm-yolo', 'ATK-YOLO-001', 45000, 9);

        $this->fakeDetect([
            ['label' => 'pen', 'confidence' => 0.87, 'box' => [12.0, 10.0, 54.0, 58.0]],
        ]);

        $response = $this->postImage($this->solidPng());
        $response->assertOk()
            ->assertJsonPath('status', 'recognized')
            ->assertJsonPath('label', 'pen')
            ->assertJsonPath('engine', 'yolo');

        $products = $response->json('products');
        $match = collect($products)->firstWhere('id', $pulpen->id);
        $this->assertNotNull($match, 'Produk pulpen dari database harus ikut direkomendasikan.');
        $this->assertSame(45000.0, (float) $match['price']);
        $this->assertSame('Rp 45.000', $match['price_formatted']);
        $this->assertSame(9, $match['stock']);
        $this->assertTrue($match['stock_available']);

        foreach ($products as $product) {
            $this->assertDatabaseHas('products', ['id' => $product['id'], 'is_active' => 1]);
        }
    }

    public function test_pencil_detection_maps_to_atk_label_and_real_products(): void
    {
        $pensil = $this->createProduct('Pensil 2B Zebra (Tes YOLO)', 'pensil-2b-zebra-yolo', 'ATK-YOLO-002', 3500, 24);

        $this->fakeDetect([
            ['label' => 'pencil', 'confidence' => 0.78, 'box' => [8.0, 8.0, 56.0, 56.0]],
        ]);

        $response = $this->postImage($this->solidPng());
        $response->assertOk()
            ->assertJsonPath('status', 'recognized')
            ->assertJsonPath('label', 'pencil')
            ->assertJsonPath('label_name', 'Pensil')
            ->assertJsonPath('engine', 'yolo');

        $this->assertTrue(
            collect($response->json('products'))->contains(fn (array $p) => $p['id'] === $pensil->id),
            'Deteksi pensil harus menampilkan produk pensil yang benar-benar ada di database.'
        );
    }

    /* ================= keputusan YOLO: penolakan & keyakinan rendah ================= */

    public function test_yolo_non_atk_detection_is_rejected_with_no_products(): void
    {
        $this->fakeDetect([
            ['label' => 'printer', 'confidence' => 0.91, 'box' => [4.0, 6.0, 60.0, 58.0]],
        ]);

        $this->postImage($this->solidPng())
            ->assertOk()
            ->assertJsonPath('status', 'not_atk')
            ->assertJsonPath('label', 'printer')
            ->assertJsonPath('engine', 'yolo')
            ->assertJsonPath('message', ATKImageRecognitionService::MESSAGE_NOT_RECOGNIZED)
            ->assertJsonPath('products', []);
    }

    public function test_yolo_detection_below_confidence_threshold_is_uncertain(): void
    {
        // Server memang sudah difilter pada ambang config, tetapi PHP tetap
        // memeriksa ulang (pertahanan berlapis): balasan di bawah ambang tidak
        // boleh pernah dianggap dikenali.
        $this->fakeDetect([
            ['label' => 'pen', 'confidence' => 0.30, 'box' => [10.0, 10.0, 50.0, 50.0]],
        ]);

        $this->postImage($this->solidPng())
            ->assertOk()
            ->assertJsonPath('status', 'uncertain')
            ->assertJsonPath('label', null)
            ->assertJsonPath('engine', 'yolo')
            ->assertJsonPath('products', []);
    }

    public function test_yolo_detection_with_margin_below_threshold_is_uncertain(): void
    {
        // Selisih kelas terbaik vs runner-up 0.01 < yolo_min_margin 0.02 → ragu.
        $this->fakeDetect([
            ['label' => 'pen', 'confidence' => 0.80, 'box' => [10.0, 10.0, 50.0, 50.0]],
            ['label' => 'notebook', 'confidence' => 0.79, 'box' => [10.0, 10.0, 50.0, 50.0]],
        ]);

        $this->postImage($this->solidPng())
            ->assertOk()
            ->assertJsonPath('status', 'uncertain')
            ->assertJsonPath('engine', 'yolo')
            ->assertJsonPath('products', []);
    }

    public function test_detection_with_label_outside_canonical_list_is_uncertain(): void
    {
        // Label di luar LABELS tidak pernah dijadikan keputusan sistem.
        $this->fakeDetect([
            ['label' => 'banana', 'confidence' => 0.97, 'box' => [0.0, 0.0, 64.0, 64.0]],
        ]);

        $this->postImage($this->solidPng())
            ->assertOk()
            ->assertJsonPath('status', 'uncertain')
            ->assertJsonPath('engine', 'yolo')
            ->assertJsonPath('products', []);
    }

    /* ============= ketersediaan layanan & konfigurasi driver (tanpa ubah kode) ============= */

    public function test_unreachable_yolo_service_falls_back_to_builtin_recognizer(): void
    {
        if (! $this->datasetReady()) {
            $this->markTestSkipped('Indeks dataset Visual Search belum dibangun (atk:image-index).');
        }

        Http::fake(['yolo.test/*' => Http::failedConnection()]);

        $response = $this->postImage($this->solidPng());
        $response->assertOk();

        $status = $response->json('status');
        $this->assertContains($status, ['recognized', 'not_atk', 'uncertain']);
        $this->assertSame('gd', $response->json('engine'), 'Saat layanan mati, keputusan harus berasal dari mesin bawaan.');
        $this->assertIsString($response->json('message'));
    }

    public function test_gd_driver_bypasses_yolo_service_entirely(): void
    {
        if (! $this->datasetReady()) {
            $this->markTestSkipped('Indeks dataset Visual Search belum dibangun (atk:image-index).');
        }

        config()->set('services.image_search.driver', 'gd');

        // Fake siap menjawab dengan deteksi kuat — driver 'gd' tidak boleh memakainya.
        $this->fakeDetect([
            ['label' => 'pen', 'confidence' => 0.99, 'box' => [0.0, 0.0, 64.0, 64.0]],
        ]);

        $response = $this->postImage($this->solidPng());
        $response->assertOk();
        $this->assertSame('gd', $response->json('engine'));
        Http::assertNothingSent();
    }

    /* =================== konsistensi katalog vs chatbot =================== */

    public function test_chatbot_and_catalog_contexts_return_identical_yolo_results(): void
    {
        $this->fakeDetect([
            ['label' => 'pen', 'confidence' => 0.87, 'box' => [12.0, 10.0, 54.0, 58.0]],
        ]);

        $catalog = $this->postImage($this->solidPng());
        $chatbot = $this->postImage($this->solidPng(), ['context' => 'chatbot']);

        $catalog->assertOk();
        $chatbot->assertOk();

        $this->assertSame($catalog->json('status'), $chatbot->json('status'));
        $this->assertSame($catalog->json('label'), $chatbot->json('label'));
        $this->assertSame($catalog->json('confidence'), $chatbot->json('confidence'));
        $this->assertSame($catalog->json('engine'), $chatbot->json('engine'));
        $this->assertSame(
            array_column($catalog->json('products'), 'id'),
            array_column($chatbot->json('products'), 'id'),
            'Hasil produk katalog dan chatbot harus konsisten untuk gambar yang sama.'
        );
    }

    /* ======================= gambar rusak ditangani jujur ======================= */

    public function test_truncated_png_returns_honest_error_without_ai_decision(): void
    {
        // PNG ber-header IHDR utuh tetapi tanpa data piksel: lolos sniffing mime,
        // tetapi mustahil diproses. Layanan deteksi menolaknya (422) → sistem
        // harus menjawab 'error' yang jujur, bukan keputusan tebakan.
        Http::fake([
            'yolo.test/*' => Http::response(['ok' => false, 'error' => 'invalid_image'], 422),
        ]);

        $truncated = substr($this->solidPng(), 0, 33); // signature + chunk IHDR saja

        $this->postImage($truncated)
            ->assertOk()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('label', null)
            ->assertJsonPath('products', []);
    }
}
