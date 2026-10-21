<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Konteks database & pengenalan peran pada system prompt chatbot:
 * tamu, pelanggan, admin, penjual, dan kurir menerima cakupan data
 * yang berbeda — privasi ditegakkan di server sebelum data ke model.
 */
class AiDatabaseContextTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $penjual;

    private User $pelanggan;

    private User $kurir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->penjual = User::where('email', 'penjual@agungatk.com')->firstOrFail();
        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
        $this->kurir = User::where('email', 'kurir@agungatk.com')->firstOrFail();
    }

    /**
     * Kirim chat dengan API aktif (palsu) lalu tangkap system prompt terkirim.
     */
    private function captureSystemPrompt(?User $user): string
    {
        config([
            'ai.key' => 'test-key-context',
            'ai.url' => 'https://ai.test/v1/chat/completions',
            'ai.model' => 'test-model',
        ]);

        Http::fake([
            'ai.test/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Baik, berikut ringkasannya.']]],
            ], 200),
        ]);

        $response = $this->sendChatAs($user, 'Tolong ringkas data saya.');

        $response->assertOk();

        $prompt = null;
        Http::assertSent(function ($request) use (&$prompt) {
            if ($request->url() === 'https://ai.test/v1/chat/completions') {
                $prompt = $request['messages'][0]['content'];

                return true;
            }

            return false;
        });

        $this->assertIsString($prompt, 'Request ke AI API tidak terkirim.');

        return $prompt;
    }

    private function sendChatAs(?User $user, string $message): TestResponse
    {
        if ($user !== null) {
            return $this->actingAs($user)
                ->postJson(route('home.chat'), ['message' => $message]);
        }

        return $this->withCredentials()
            ->withCookie(config('session.cookie'), hash('sha1', 'ctx-tamu'))
            ->postJson(route('home.chat'), ['message' => $message]);
    }

    private function createOrderFor(User $customer, string $prefix = 'ORD-CTX'): Order
    {
        return Order::create([
            'order_number' => $prefix.'-'.uniqid(),
            'user_id' => $customer->id,
            'address_id' => null,
            'total_amount' => 20000,
            'shipping_cost' => 0,
            'discount_amount' => 0,
            'grand_total' => 20000,
            'status' => 'ready_to_ship',
        ]);
    }

    /* =====================================================================
     | TAMU: RINGKASAN DATABASE TANPA DATA PRIBADI
     * ===================================================================*/

    public function test_guest_context_exposes_database_summary_without_private_data(): void
    {
        $seededOrder = Order::firstOrFail(); // pesanan seed milik pelanggan

        $prompt = $this->captureSystemPrompt(null);

        $this->assertStringContainsString('"peran":"tamu"', $prompt);
        $this->assertStringContainsString('ringkasan_tabel', $prompt);
        $this->assertStringContainsString('struktur seluruh database toko', $prompt);

        // Tamu tidak pernah menerima isi pesanan maupun akun siapa pun
        $this->assertStringNotContainsString($seededOrder->order_number, $prompt);
        $this->assertStringNotContainsString('@agungatk.com', $prompt);
    }

    /* =====================================================================
     | PELANGGAN: HANYA DATA MILIKNYA SENDIRI
     * ===================================================================*/

    public function test_customer_context_contains_own_orders_and_recognizes_role(): void
    {
        $other = Order::create([
            'order_number' => 'ORD-LAIN-'.uniqid(),
            'user_id' => $this->penjual->id,
            'address_id' => null,
            'total_amount' => 5000,
            'shipping_cost' => 0,
            'discount_amount' => 0,
            'grand_total' => 5000,
            'status' => 'pending',
        ]);

        $prompt = $this->captureSystemPrompt($this->pelanggan);

        $this->assertStringContainsString('"peran":"pelanggan"', $prompt);
        $this->assertStringContainsString('"peran_dikenali":"pelanggan"', $prompt);
        $this->assertStringContainsString('pesanan_saya', $prompt);
        $this->assertStringContainsString('ATK-20260909-0001', $prompt); // pesanan seed miliknya

        // Pesanan milik pengguna lain tidak pernah bocor ke konteksnya
        $this->assertStringNotContainsString($other->order_number, $prompt);
        $this->assertStringNotContainsString('@agungatk.com', $prompt);
    }

    /* =====================================================================
     | ADMIN: STATISTIK TOKO + PENGENALAN PERAN, TANPA EMAIL AKUN
     * ===================================================================*/

    public function test_admin_context_contains_store_statistics_and_recognizes_role(): void
    {
        $prompt = $this->captureSystemPrompt($this->admin);

        $this->assertStringContainsString('"peran":"admin"', $prompt);
        $this->assertStringContainsString('"peran_dikenali":"admin"', $prompt);
        $this->assertStringContainsString('statistik', $prompt);
        $this->assertStringContainsString('pesanan_per_status', $prompt);
        $this->assertStringContainsString('pembayaran_per_status', $prompt);
        $this->assertStringContainsString('user_terbaru', $prompt);
        $this->assertStringContainsString('Sapa user sesuai peran', $prompt);

        // Email akun tidak pernah ikut ke model demi privasi
        $this->assertStringNotContainsString('@agungatk.com', $prompt);
    }

    /* =====================================================================
     | KURIR: HANYA TUGAS YANG DITUGASKAN KEPADANYA
     * ===================================================================*/

    public function test_courier_context_contains_only_own_assignments(): void
    {
        $kurirLain = User::create([
            'name' => 'Kurir Lain',
            'email' => 'kurir-lain@agungatk.com',
            'password' => bcrypt('password'),
            'role_id' => Role::where('name', 'kurir')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $pesananSaya = $this->createOrderFor($this->pelanggan, 'ORD-KURIR');
        $pesananLain = $this->createOrderFor($this->pelanggan, 'ORD-KURIR2');

        $tugasSaya = Shipment::create([
            'order_id' => $pesananSaya->id,
            'courier_id' => $this->kurir->id,
            'tracking_number' => 'RESI-SAYA-'.uniqid(),
            'courier_name' => $this->kurir->name,
            'status' => 'waiting',
            'recipient_name' => 'Penerima Uji',
        ]);
        $tugasLain = Shipment::create([
            'order_id' => $pesananLain->id,
            'courier_id' => $kurirLain->id,
            'tracking_number' => 'RESI-LAIN-'.uniqid(),
            'courier_name' => $kurirLain->name,
            'status' => 'waiting',
            'recipient_name' => 'Penerima Lain',
        ]);

        $prompt = $this->captureSystemPrompt($this->kurir);

        $this->assertStringContainsString('"peran":"kurir"', $prompt);
        $this->assertStringContainsString('pengiriman_saya', $prompt);
        $this->assertStringContainsString($tugasSaya->tracking_number, $prompt);

        // Tugas milik kurir lain tidak pernah masuk konteksnya
        $this->assertStringNotContainsString($tugasLain->tracking_number, $prompt);
        $this->assertStringNotContainsString('Penerima Lain', $prompt);
    }

    /* =====================================================================
     | ANGOTA: DATA OPERASIONAL TANPA DAFTAR AKUN USER
     * ===================================================================*/

    public function test_anggota_context_gets_operational_data_without_user_directory(): void
    {
        $prompt = $this->captureSystemPrompt($this->penjual);

        $this->assertStringContainsString('"peran":"anggota"', $prompt);
        $this->assertStringContainsString('"peran_dikenali":"anggota"', $prompt);
        $this->assertStringContainsString('pesanan_per_status', $prompt);
        $this->assertStringContainsString('pesanan_terbaru', $prompt);
        $this->assertStringContainsString('stok_menipis', $prompt);
        $this->assertStringContainsString('produk_ringkas', $prompt);

        // Anggota TIDAK mendapat daftar akun user lain (privasi peran)
        $this->assertStringNotContainsString('user_terbaru', $prompt);
        $this->assertStringNotContainsString('@agungatk.com', $prompt);
    }
}
