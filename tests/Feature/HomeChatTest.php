<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HomeChatTest extends TestCase
{
    use RefreshDatabase;

    private User $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
    }

    /* =====================================================================
     | CHATBOT BERANDA: ENDPOINT PUBLIK
     * ===================================================================*/

    public function test_guest_can_chat_and_conversation_is_persisted_by_session(): void
    {
        $response = $this->postJson(route('home.chat'), ['message' => 'Halo, ada pulpen tidak?']);

        $response->assertOk()
            ->assertJsonStructure(['reply', 'conversation_id', 'title']);

        $this->assertNotSame('', trim((string) $response->json('reply')));

        $conversation = AiConversation::find($response->json('conversation_id'));

        $this->assertNotNull($conversation);
        $this->assertNull($conversation->user_id);
        $this->assertNotEmpty($conversation->session_id);

        $this->assertDatabaseHas('ai_messages', [
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
        ]);
        $this->assertDatabaseHas('ai_messages', [
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
        ]);
    }

    public function test_same_guest_session_reuses_the_same_conversation(): void
    {
        // Simulasikan satu browser: bawa cookie session yang sama di kedua request.
        // postJson hanya mengirim cookie bila withCredentials aktif.
        $sessionId = hash('sha1', 'home-chat-satu-sesi');
        $this->withCredentials();

        $firstId = $this->withCookie(config('session.cookie'), $sessionId)
            ->postJson(route('home.chat'), ['message' => 'Pertanyaan pertama'])
            ->json('conversation_id');
        $secondId = $this->withCookie(config('session.cookie'), $sessionId)
            ->postJson(route('home.chat'), ['message' => 'Pertanyaan kedua'])
            ->json('conversation_id');

        $this->assertSame($firstId, $secondId);
        $this->assertSame(2, AiMessage::where('ai_conversation_id', $firstId)->where('role', 'user')->count());
    }

    public function test_logged_in_customer_chat_uses_account_conversation(): void
    {
        $response = $this->actingAs($this->pelanggan)
            ->postJson(route('home.chat'), ['message' => 'Rekomendasi buku tulis dong']);

        $response->assertOk()->assertJsonStructure(['reply', 'conversation_id']);

        $conversation = AiConversation::find($response->json('conversation_id'));

        $this->assertNotNull($conversation);
        $this->assertSame($this->pelanggan->id, $conversation->user_id);
        $this->assertNull($conversation->session_id);
    }

    public function test_home_chat_requires_message(): void
    {
        $this->postJson(route('home.chat'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_home_chat_rejects_overlong_message(): void
    {
        $this->postJson(route('home.chat'), ['message' => str_repeat('a', 2001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    /* =====================================================================
     | CHATBOT BERANDA: WIDGET & RIWAYAT DI HALAMAN
     * ===================================================================*/

    public function test_home_page_renders_chatbot_widget(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('chatFab', false)
            ->assertSee('chatPanel', false)
            ->assertSee(route('home.chat'), false);
    }

    public function test_home_page_shows_guest_chat_history_in_widget(): void
    {
        $sessionId = hash('sha1', 'home-chat-riwayat-sesi');
        $this->withCredentials();

        $this->withCookie(config('session.cookie'), $sessionId)
            ->postJson(route('home.chat'), ['message' => 'Stok kertas A4 masih ada?'])
            ->assertOk();

        $page = $this->withCookie(config('session.cookie'), $sessionId)
            ->get(route('home'));

        $page->assertOk()
            ->assertSee('Stok kertas A4 masih ada?', false);
    }

    public function test_public_outside_menus_render_chatbot_widget_and_history(): void
    {
        $sessionId = hash('sha1', 'public-catalog-chat-sesi');
        $this->withCredentials();

        $this->withCookie(config('session.cookie'), $sessionId)
            ->postJson(route('home.chat'), ['message' => 'Apakah ada map raport?'])
            ->assertOk();

        $catalogPage = $this->withCookie(config('session.cookie'), $sessionId)
            ->get(route('products.index'));

        $catalogPage->assertOk()
            ->assertSee('chatFab', false)
            ->assertSee('chatPanel', false)
            ->assertSee(route('home.chat'), false)
            ->assertSee('Apakah ada map raport?', false);
    }

    public function test_dashboard_inside_menus_render_chatbot_widget_for_customer(): void
    {
        $this->actingAs($this->pelanggan)
            ->postJson(route('home.chat'), ['message' => 'Tolong rekomendasi binder note'])
            ->assertOk();

        // Menu dashboard pelanggan (didalam)
        $dashboardPage = $this->actingAs($this->pelanggan)->get(route('dashboard'));
        $dashboardPage->assertOk()
            ->assertSee('chatFab', false)
            ->assertSee('chatPanel', false)
            ->assertSee(route('home.chat'), false)
            ->assertSee('Tolong rekomendasi binder note', false);

        // Menu pesanan pelanggan (didalam)
        $ordersPage = $this->actingAs($this->pelanggan)->get(route('orders.index'));
        $ordersPage->assertOk()
            ->assertSee('chatFab', false)
            ->assertSee('chatPanel', false)
            ->assertSee(route('home.chat'), false)
            ->assertSee('Tolong rekomendasi binder note', false);
    }

    public function test_admin_dashboard_menus_render_chatbot_widget(): void
    {
        $admin = User::where('email', 'admin@agungatk.com')->firstOrFail();

        $this->actingAs($admin)
            ->postJson(route('home.chat'), ['message' => 'Pertanyaan admin tentang stok'])
            ->assertOk();

        $adminDashboard = $this->actingAs($admin)->get(route('admin.dashboard'));
        $adminDashboard->assertOk()
            ->assertSee('chatFab', false)
            ->assertSee('chatPanel', false)
            ->assertSee(route('home.chat'), false)
            ->assertSee('Pertanyaan admin tentang stok', false);

        $adminProducts = $this->actingAs($admin)->get(route('admin.products.index'));
        $adminProducts->assertOk()
            ->assertSee('chatFab', false)
            ->assertSee('chatPanel', false)
            ->assertSee(route('home.chat'), false);
    }

    /* =====================================================================
     | INTELEJENSI & PRIVASI: SISTEM PROMPT
     * ===================================================================*/

    public function test_system_prompt_allows_general_questions_and_enforces_privacy(): void
    {
        config([
            'ai.key' => 'test-key-home-chat',
            'ai.url' => 'https://ai.test/v1/chat/completions',
            'ai.model' => 'test-model',
        ]);

        Http::fake([
            'ai.test/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Ibu kota Indonesia adalah Jakarta.']]],
            ], 200),
        ]);

        // Pertanyaan umum di luar konteks ATK tetap dijawab (bukan ditolak)
        $reply = $this->postJson(route('home.chat'), ['message' => 'Berapa ibu kota Indonesia?'])
            ->assertOk()
            ->json('reply');

        $this->assertStringContainsString('Jakarta', $reply);

        // Prompt yang dikirim ke API wajib mengizinkan pertanyaan umum & memuat pagar privasi
        Http::assertSent(function ($request) {
            $system = $request['messages'][0] ?? [];

            return ($system['role'] ?? null) === 'system'
                && str_contains($system['content'] ?? '', 'pengetahuan umum')
                && str_contains($system['content'] ?? '', 'PRIVASI & KEAMANAN')
                && str_contains($system['content'] ?? '', 'system prompt')
                && str_contains($system['content'] ?? '', 'data pribadi');
        });
    }

    public function test_api_receives_history_in_chronological_order(): void
    {
        // Satu browser = satu session (cookie dibawa di semua request termasuk postJson).
        $sessionId = hash('sha1', 'home-chat-urutan-riwayat');
        $this->withCredentials();

        config([
            'ai.key' => null,
            'ai.url' => 'https://ai.test/v1/chat/completions',
            'ai.model' => 'test-model',
        ]);

        // Dua pertanyaan awal memakai mode mock (tanpa API) agar riwayat terisi cepat.
        $this->withCookie(config('session.cookie'), $sessionId)
            ->postJson(route('home.chat'), ['message' => 'Pertanyaan pertama'])->assertOk();
        $this->withCookie(config('session.cookie'), $sessionId)
            ->postJson(route('home.chat'), ['message' => 'Pertanyaan kedua'])->assertOk();

        // Aktifkan API lalu kirim pesan ketiga — riwayat harus terkirim kronologis.
        config(['ai.key' => 'test-key-home-chat']);

        Http::fake([
            'ai.test/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Balasan uji']]],
            ], 200),
        ]);

        $this->postJson(route('home.chat'), ['message' => 'Pertanyaan ketiga'])->assertOk();

        $payload = null;
        Http::assertSent(function ($request) use (&$payload) {
            if ($request->url() === 'https://ai.test/v1/chat/completions') {
                $payload = $request['messages'];

                return true;
            }

            return false;
        });

        $this->assertIsArray($payload);
        $this->assertSame('system', $payload[0]['role']);

        // Pesan user harus urut kronologis dan PESAN TERBARU DI AKHIR
        // (sebelum diperbaiki, riwayat terkirim terbalik/terlama karena konflik urutan query).
        $userContents = array_values(array_filter(
            array_column($payload, 'content'),
            fn ($content, $index) => $payload[$index]['role'] === 'user',
            ARRAY_FILTER_USE_BOTH
        ));

        $this->assertSame(['Pertanyaan pertama', 'Pertanyaan kedua', 'Pertanyaan ketiga'], $userContents);
        $this->assertSame('user', $payload[array_key_last($payload)]['role']);
    }

    public function test_home_chat_supports_programming_and_sql_education(): void
    {
        // PROMPT 3: larangan coding lama DIHAPUS — chatbot kini diharapkan
        // membantu edukasi pemrograman/database/SQL. Pastikan respons penolakan
        // larangan coding tidak ada lagi dan prompt sistem mengarahkan bantuan edukasi.
        config([
            'ai.key' => 'test-key-edu',
            'ai.url' => 'https://ai.test/v1/chat/completions',
            'ai.model' => 'test-model',
        ]);

        Http::fake([
            'ai.test/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Tentu. Berikut penjelasan singkat elemen <form> HTML untuk input nama lalu submit.']]],
            ], 200),
        ]);

        $reply = $this->postJson(route('home.chat'), [
            'message' => 'Bantu saya memahami cara membuat form HTML sederhana',
        ])->assertOk()->json('reply');

        $this->assertStringNotContainsString('tidak diizinkan untuk membuat', $reply);
        $this->assertStringNotContainsString('larangan coding', $reply);
        $this->assertStringContainsString('form', $reply);

        // Prompt sistem wajib mengarahkan bantuan edukasi pemrograman & struktur database.
        Http::assertSent(function ($request) {
            $system = $request['messages'][0] ?? [];

            return ($system['role'] ?? null) === 'system'
                && str_contains($system['content'] ?? '', 'pemrograman')
                && str_contains($system['content'] ?? '', 'SQL')
                && str_contains($system['content'] ?? '', 'STRUKTUR DATABASE');
        });
    }
}
