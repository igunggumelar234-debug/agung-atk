<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AiAssistantTest extends TestCase
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

    private function postMessage(string $message, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->pelanggan)
            ->postJson(route('ai.message'), ['message' => $message]);
    }

    /* =====================================================================
     | AKSES: HANYA PELANGGAN
     * ===================================================================*/

    public function test_guest_redirected_and_non_customer_forbidden(): void
    {
        // Tamu dulu (actingAs persisten dalam satu test)
        $this->get(route('ai.chat'))->assertRedirect('/login');
        $this->post(route('ai.message'), ['message' => 'Halo'])->assertRedirect('/login');
        $this->post(route('ai.reset'))->assertRedirect('/login');

        foreach ([$this->admin, $this->penjual, $this->kurir] as $user) {
            $this->actingAs($user)->get(route('ai.chat'))->assertStatus(403);
            $this->actingAs($user)->post(route('ai.message'), ['message' => 'Halo'])->assertStatus(403);
            $this->actingAs($user)->post(route('ai.reset'))->assertStatus(403);
        }
    }

    public function test_customer_sees_chat_interface(): void
    {
        $response = $this->actingAs($this->pelanggan)->get(route('ai.chat'));

        $response->assertStatus(200);
        $response->assertSee('Asisten AI Agung ATK');
        $response->assertSee('aiForm', false);
        $response->assertSee('/ai/message', false);
        $response->assertSee('Percakapan Baru');
        $response->assertSee('budget Rp50.000');
        $response->assertSee('tidak akan dikarang');
        // Menu sidebar pelanggan
        $response->assertSee('AI Assistant');
    }

    /* =====================================================================
     | KIRIM PESAN & PENYIMPANAN (ai_conversations + ai_messages)
     * ===================================================================*/

    public function test_customer_can_send_message_and_persistence(): void
    {
        $response = $this->postMessage('Halo butuh bantuan');

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertIsString($reply);
        $this->assertNotSame('', $reply);
        // Mode fallback (tanpa API key): sapaan mock yang benar
        $this->assertStringContainsString('Asisten AI Agung ATK', $reply);

        $conversation = AiConversation::where('user_id', $this->pelanggan->id)->firstOrFail();
        $this->assertSame('Halo butuh bantuan', $conversation->title);
        $this->assertSame($conversation->id, $response->json('conversation_id'));

        $this->assertDatabaseHas('ai_messages', [
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Halo butuh bantuan',
        ]);
        $this->assertDatabaseHas('ai_messages', [
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
        ]);
    }

    public function test_conversation_history_accumulates(): void
    {
        $ids = [];
        foreach (['Halo', 'Cari pulpen untuk menulis', 'Saya butuh kertas A4'] as $message) {
            $response = $this->postMessage($message);
            $response->assertStatus(200);
            $ids[] = $response->json('conversation_id');
        }

        // Semua pesan menempel pada percakapan yang sama
        $this->assertCount(1, array_unique($ids));
        $this->assertSame(6, AiMessage::count());

        $page = $this->actingAs($this->pelanggan)->get(route('ai.chat'));
        $page->assertStatus(200);
        $page->assertSee('Cari pulpen untuk menulis');
        $page->assertSee('Saya butuh kertas A4');
        $page->assertSee('Halo');
    }

    public function test_reset_starts_new_conversation_preserving_history(): void
    {
        $this->postMessage('Saya butuh lembaran kertas A4 untuk print tugas')->assertStatus(200);
        $this->assertSame(1, AiConversation::count());

        $this->actingAs($this->pelanggan)
            ->post(route('ai.reset'))
            ->assertRedirect(route('ai.chat'))
            ->assertSessionHas('success');

        // Riwayat lama tetap, percakapan baru kosong
        $this->assertSame(2, AiConversation::count());
        $this->assertSame(2, AiMessage::count());

        $page = $this->actingAs($this->pelanggan)->get(route('ai.chat'));
        $page->assertStatus(200);
        $page->assertDontSee('untuk print tugas');
        $page->assertSee('Halo! Aku Asisten AI Agung ATK');
    }

    public function test_user_cannot_see_other_users_conversation(): void
    {
        $role = Role::where('name', 'pelanggan')->firstOrFail();
        $other = User::create([
            'name' => 'Pelanggan Lain',
            'email' => 'pelanggan-lain@agungatk.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->postMessage('RAHASIA-PRIVASI-LAIN', $other)->assertStatus(200);

        $this->actingAs($this->pelanggan)
            ->get(route('ai.chat'))
            ->assertStatus(200)
            ->assertDontSee('RAHASIA-PRIVASI-LAIN');
    }

    /* =====================================================================
     | VALIDASI SERVER-SIDE
     * ===================================================================*/

    public function test_message_validation(): void
    {
        $this->postMessage('')->assertStatus(422)->assertJsonValidationErrors('message');
        $this->postMessage(str_repeat('x', 2001))->assertStatus(422)->assertJsonValidationErrors('message');

        // Tidak ada pesan yang tersimpan saat validasi gagal
        $this->assertSame(0, AiMessage::count());
    }

    /* =====================================================================
     | FALLBACK MOCK: DATA PRODUK NYATA, TANPA MENGARANG
     * ===================================================================*/

    public function test_keyword_reply_uses_real_product_data(): void
    {
        $product = Product::where('name', 'like', 'Pulpen Gel%')->firstOrFail();

        $reply = $this->postMessage('Cari pulpen untuk menulis')->json('reply');

        // Nama, harga, dan stok dikutip apa adanya dari database
        $this->assertStringContainsString($product->name, $reply);
        $this->assertStringContainsString(number_format((float) $product->price, 0, ',', '.'), $reply);
        $this->assertStringContainsString('stok: '.(int) $product->stock, $reply);
        $this->assertStringContainsString('Kategori:', $reply);
    }

    public function test_budget_recommendation_respects_budget_and_stock(): void
    {
        // Produk termurah (pasti tampil), produk stok 0 (wajib hilang), dan produk > budget
        $cheapest = Product::orderBy('price')->firstOrFail();
        $outOfStock = Product::where('name', 'like', 'Stapler%')->firstOrFail();
        $outOfStock->stock = 0;
        $outOfStock->save();
        $overBudget = Product::where('price', '>', 50000)->firstOrFail();

        $reply = $this->postMessage('Saya mahasiswa dan punya budget Rp50.000. Apa yang cocok?')->json('reply');

        $this->assertStringContainsString('Rp 50.000', $reply);              // budget dipahami
        $this->assertStringContainsString($cheapest->name, $reply);          // rekomendasi nyata
        $this->assertStringNotContainsString($overBudget->name, $reply);      // > budget tidak ditawarkan
        $this->assertStringNotContainsString($outOfStock->name, $reply);      // stok 0 tidak direkomendasikan
    }

    public function test_inactive_product_is_not_recommended(): void
    {
        $product = Product::where('name', 'like', 'Ordner%')->firstOrFail();
        $product->is_active = false;
        $product->save();

        $reply = $this->postMessage('Ordner Bantex F4 7cm Biru')->json('reply');

        $this->assertIsString($reply);
        $this->assertNotSame('', $reply);
        $this->assertStringNotContainsString($product->name, $reply);
        // Tidak ada kecocokan aktif → jawaban umum, bukan karangan
        $this->assertStringContainsString('belum menemukan', $reply);
    }

    public function test_unmatched_query_gets_polite_generic_reply(): void
    {
        $reply = $this->postMessage('Apakah ada jet pribadi di toko ini?')->json('reply');

        // Jawab sopan + menawarkan produk nyata dari database (bukan mengarang)
        $this->assertStringContainsString('belum menemukan', $reply);
        $this->assertStringContainsString('Beberapa produk aktif kami', $reply);

        $sample = Product::where('is_active', true)->where('stock', '>', 0)->orderBy('name')->firstOrFail();
        $this->assertStringContainsString($sample->name, $reply);
    }

    /* =====================================================================
     | AI API: DIPAKAI JIKA DIKONFIGURASI, Fallback JIKA GAGAL
     * ===================================================================*/

    public function test_api_used_when_key_configured(): void
    {
        config([
            'ai.key' => 'test-key-123',
            'ai.url' => 'https://ai.test/v1/chat/completions',
        ]);

        Http::fake([
            'https://ai.test/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Jawaban dari API model.']]],
            ], 200),
        ]);

        $reply = $this->postMessage('Cari pulpen untuk menulis')->json('reply');
        $this->assertSame('Jawaban dari API model.', $reply);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://ai.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key-123')
                && $request['messages'][0]['role'] === 'system'
                && str_contains($request['messages'][0]['content'], 'DATA PRODUK')
                && str_contains($request['messages'][0]['content'], 'DILARANG mengarang');
        });
    }

    public function test_api_failure_falls_back_to_mock(): void
    {
        config([
            'ai.key' => 'test-key-123',
            'ai.url' => 'https://ai.test/v1/chat/completions',
        ]);

        Http::fake(['https://ai.test/*' => Http::response('error', 500)]);

        $reply = $this->postMessage('Cari pulpen untuk menulis')->json('reply');

        // Balasan tetap ada dan tetap berbasis data database
        $this->assertStringContainsString('Pulpen Gel Pilot G2 0.5mm Hitam (Pcs)', $reply);
    }

    /* =====================================================================
     | EDUKASI PEMROGRAMAN / SQL (PROMPT 3): DIBANTU, BUKAN DITOLAK
     * ===================================================================*/

    public function test_chatbot_helps_programming_education_requests(): void
    {
        // PROMPT 3 bagian B & D: chatbot DIHARAPKAN menjawab pertanyaan
        // pemrograman/database. Tanpa API, jawaban lokal harus jujur soal
        // keterbatasannya — BUKAN respons penolakan larangan coding lama.
        $queries = [
            'Tolong buatkan coding kalkulator python',
            'Tuliskan script php untuk login user',
            'Bikin codingan javascript',
            'Bagaimana kode html membuat tombol?',
        ];

        foreach ($queries as $query) {
            $response = $this->postMessage($query);
            $response->assertStatus(200);

            $reply = $response->json('reply');
            $this->assertNotSame('', $reply);
            $this->assertStringNotContainsString('tidak diizinkan untuk membuat', $reply);
            $this->assertStringNotContainsString('larangan coding', $reply);
            // Jujur soal keterbatasan mode lokal (tanpa mengarang jawaban)
            $this->assertStringContainsString('mengarang', $reply);
        }
    }

    public function test_chatbot_returns_api_code_when_requested(): void
    {
        // Kode/query kini DIIZINKAN sebagai bagian edukasi (blok kode tidak
        // lagi difilter) — tetapi pagaran output tetap menyaring kebocoran
        // system prompt / rahasia teknis.
        config([
            'ai.key' => 'test-key-123',
            'ai.url' => 'https://ai.test/v1/chat/completions',
        ]);

        Http::fake([
            'https://ai.test/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => "Tentu, ini contohnya:\n```python\ndef hitung():\n    return 42\n```\nSemoga membantu!",
                    ],
                ]],
            ], 200),
        ]);

        $reply = $this->postMessage('Beri contoh fungsi sederhana python')->json('reply');

        // Blok kode edukatif diteruskan apa adanya oleh model
        $this->assertStringContainsString('def hitung()', $reply);
        $this->assertStringContainsString('```', $reply);
        // Namun identitas internal tidak boleh bocor
        $this->assertStringNotContainsString('Kamu adalah Asisten AI cerdas untuk toko ATK', $reply);
    }
}
