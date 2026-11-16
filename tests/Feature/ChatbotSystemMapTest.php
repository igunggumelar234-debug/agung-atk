<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ChatbotProfile;
use App\Support\ChatGuard;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Peta sistem chatbot — menu & fitur per peran berdasarkan sidebar
 * (layouts/dashboard.blade.php) dan route (routes/web.php) aktual:
 * jawaban menu per role, fitur tamu, pertanyaan kemampuan, peta di system
 * prompt (aturan 20), dan pastikan pertanyaan lama TIDAK tertangkap deteksi baru.
 */
class ChatbotSystemMapTest extends TestCase
{
    use RefreshDatabase;

    private User $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
    }

    /**
     * Kirim pesan ke chatbot beranda (tamu bila $user null).
     */
    private function chat(string $message, ?User $user = null): TestResponse
    {
        $request = $user === null ? $this : $this->actingAs($user);

        return $request->postJson(route('home.chat'), ['message' => $message]);
    }

    private function enableApi(string $replyContent): void
    {
        config([
            'ai.key' => 'test-key-sistem',
            'ai.url' => 'https://ai.test/v1/chat/completions',
            'ai.model' => 'test-model',
        ]);

        Http::fake([
            'ai.test/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $replyContent]]],
            ], 200),
        ]);
    }

    private function capturedSystemPrompt(): ?string
    {
        $systems = [];

        Http::recorded(function ($request) use (&$systems) {
            if ($request->url() === config('ai.url')) {
                $systems[] = $request['messages'][0]['content'] ?? null;
            }

            return false;
        });

        $last = end($systems);

        return is_string($last) ? $last : null;
    }

    public function test_menu_per_role_is_answered_from_the_actual_sidebar_map(): void
    {
        // Admin: 12 menu sidebar — uji label nyata
        $adminMenu = $this->chat('Menu apa saja setelah login sebagai admin?')->assertOk()->json('reply');
        $this->assertStringContainsString('Audit Log', $adminMenu);
        $this->assertStringContainsString('Laporan', $adminMenu);
        $this->assertStringContainsString('Profil Saya', $adminMenu);

        // Anggota: menu Stok Barang (route /anggota/stock)
        $staffMenu = $this->chat('Menu anggota setelah login')->assertOk()->json('reply');
        $this->assertStringContainsString('Stok Barang', $staffMenu);
        $this->assertStringContainsString('siap kirim', $staffMenu);

        // Kurir: hanya tugas miliknya
        $courierMenu = $this->chat('Menu apa saja setelah login sebagai kurir?')->assertOk()->json('reply');
        $this->assertStringContainsString('Pengiriman Saya', $courierMenu);
        $this->assertStringContainsString('Profil Saya', $courierMenu);

        // Pelanggan: menu sidebar + AI Assistant
        $customerMenu = $this->chat('Menu apa saja setelah login sebagai pelanggan?')->assertOk()->json('reply');
        $this->assertStringContainsString('Alamat Pengiriman', $customerMenu);
        $this->assertStringContainsString('AI Assistant', $customerMenu);
    }

    public function test_guest_features_are_answered(): void
    {
        $reply = $this->chat('Apa saja fitur untuk tamu yang belum login?')->assertOk()->json('reply');

        $this->assertStringContainsString('Tamu (belum login)', $reply);
        $this->assertStringContainsString('Katalog Produk', $reply);
        $this->assertStringContainsString('Registrasi', $reply);
        $this->assertStringContainsString('Chatbot Beranda', $reply);
    }

    public function test_generic_feature_question_lists_every_role(): void
    {
        $reply = $this->chat('Apa saja fitur website ini?')->assertOk()->json('reply');

        foreach (array_keys(ChatbotProfile::SYSTEM_MAP) as $role) {
            $this->assertStringContainsString($role, $reply, 'Peran tidak tercantum: '.$role);
        }

        $this->assertStringContainsString('Keranjang & Checkout', $reply);
        $this->assertStringContainsString('Notifikasi', $reply);
    }

    public function test_capability_question_is_answered_with_real_limits(): void
    {
        $reply = $this->chat('Apa yang bisa dilakukan kurir di aplikasi?')->assertOk()->json('reply');

        $this->assertStringContainsString('perbarui status', $reply);
        $this->assertStringContainsString('tugas yang ditugaskan', $reply);
        // Batas nyata: kurir tidak melihat seluruh data
        $this->assertStringNotContainsString('Audit Log', $reply);
    }

    public function test_menu_answer_is_scoped_to_the_requested_role(): void
    {
        $reply = $this->chat('Setelah login sebagai pelanggan, menu apa saja yang saya lihat?', $this->pelanggan)
            ->assertOk()
            ->json('reply');

        $this->assertStringContainsString('Pesanan Saya', $reply);
        $this->assertStringNotContainsString('Audit Log', $reply);
        $this->assertStringNotContainsString('Stok Barang', $reply);
    }

    public function test_feature_questions_are_not_refused_or_misrouted(): void
    {
        $reply = $this->chat('Menu apa saja setelah login sebagai admin?')->assertOk()->json('reply');

        // Bukan penolakan & bukan jawaban tugas aktor (jalur lain)
        $this->assertStringNotContainsString(ChatGuard::PRIVACY_REFUSAL, $reply);
        $this->assertStringNotContainsString(ChatGuard::SOURCE_REFUSAL, $reply);
        $this->assertStringNotContainsString('Tugas masing-masing aktor', $reply);
        $this->assertStringContainsString('Menu & fitur Agung ATK per peran', $reply);
    }

    public function test_system_prompt_contains_the_system_map(): void
    {
        $this->enableApi('Balasan uji.');

        $this->chat('Halo')->assertOk();

        $system = $this->capturedSystemPrompt();
        $this->assertIsString($system);

        $this->assertStringContainsString('PETA SISTEMA', $system);
        $this->assertStringContainsString('Audit Log', $system);
        $this->assertStringContainsString('Stok Barang', $system);
        $this->assertStringContainsString('Pengiriman Saya', $system);
        $this->assertStringContainsString('Login & Registrasi', $system);
    }

    public function test_old_questions_are_not_captured_by_feature_detection(): void
    {
        // Pertanyaan lama tetap di jalurnya masing-masing — bukan peta menu
        $education = $this->chat('Tuliskan script php untuk login user')->assertOk()->json('reply');
        $this->assertStringNotContainsString('Menu & fitur Agung ATK per peran', $education);

        $roles = $this->chat('Apa saja role yang ada di aplikasi ini?')->assertOk()->json('reply');
        $this->assertStringContainsString('Tugas masing-masing aktor', $roles);
        $this->assertStringNotContainsString('Menu & fitur Agung ATK per peran', $roles);

        $stock = $this->chat('Stok kertas A4 masih ada?')->assertOk()->json('reply');
        $this->assertStringNotContainsString('Menu & fitur Agung ATK per peran', $stock);
    }
}
