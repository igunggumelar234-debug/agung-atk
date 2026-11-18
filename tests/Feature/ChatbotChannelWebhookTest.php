<?php

namespace Tests\Feature;

use App\Jobs\SendChannelReplyJob;
use App\Models\AiConversation;
use App\Models\ChannelIdentity;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Channels\ChannelIdentityService;
use App\Services\Channels\InboundChatService;
use App\Services\Notifications\NotificationManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PROMPT 9 §10 — integrasi chatbot WhatsApp & Telegram:
 * validasi konfigurasi, webhook (verifikasi, signature, secret),
 * anti-duplikat, komunikasi AI, akses role, tautan kode verifikasi,
 * kegagalan API, notifikasi per role, dashboard, dan privasi data.
 *
 * Seluruh pengiriman memakai Http::fake — tidak pernah ada pesan nyata.
 */
class ChatbotChannelWebhookTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pelanggan;

    private User $kurir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
        $this->kurir = User::where('email', 'kurir@agungatk.com')->firstOrFail();
    }

    /* =====================================================================
     | HELPER
     * ===================================================================*/

    /**
     * Aktifkan kedua kanal dengan kredensial dummy + webhook secret.
     */
    private function configureChannels(): void
    {
        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.access_token' => 'test-whatsapp-token',
            'services.whatsapp.phone_number_id' => '111222333',
            'services.whatsapp.verify_token' => 'wa-verify-token',
            'services.whatsapp.app_secret' => 'wa-app-secret',
            'services.whatsapp.message_mode' => 'text',
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => 'test-telegram-token',
            'services.telegram.webhook_secret' => 'test-webhook-secret',
            'services.telegram.admin_chat_id' => '111111',
        ]);
    }

    /**
     * Fake API eksternal (Telegram sukses, WhatsApp sukses).
     */
    private function fakeExternalApis(): void
    {
        Http::fake([
            'api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200),
        ]);
    }

    private function fakeTelegramSendFailure(): void
    {
        Http::fake([
            'api.telegram.org/*/sendMessage' => Http::response('Internal Server Error', 500),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200),
        ]);
    }

    /**
     * POST webhook Telegram (secret header default sesuai configureChannels()).
     */
    private function postTelegram(string $text, array $options = []): TestResponse
    {
        $chatId = (int) ($options['chat_id'] ?? 1887743171);

        $payload = [
            'update_id' => $options['update_id'] ?? random_int(100000000, 999999999),
            'message' => [
                'message_id' => random_int(1, 1000000),
                'date' => time(),
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Uji'],
                'text' => $text,
            ],
        ];

        return $this->withHeaders([
            'X-Telegram-Bot-Api-Secret-Token' => $options['secret'] ?? 'test-webhook-secret',
            'Accept' => 'application/json',
        ])->postJson(route('webhooks.telegram.receive'), $payload);
    }

    /**
     * POST webhook WhatsApp — body mentah + signature HMAC yang dihitung
     * dari app secret yang sama (atau signature rusak bila diminta).
     */
    private function postWhatsApp(string $text, array $options = []): TestResponse
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '6289697358716',
                            'phone_number_id' => '111222333',
                        ],
                        'messages' => [[
                            'id' => $options['wamid'] ?? 'wamid.UJI.'.random_int(100000, 999999),
                            'from' => $options['from'] ?? '6281111222333',
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];

        $raw = json_encode($payload);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        if (array_key_exists('signature', $options)) {
            $server['HTTP_X-Hub-Signature-256'] = $options['signature'];
        } elseif (filled(config('services.whatsapp.app_secret'))) {
            $server['HTTP_X-Hub-Signature-256'] = 'sha256='.hash_hmac(
                'sha256',
                $raw,
                (string) config('services.whatsapp.app_secret')
            );
        }

        return $this->call('POST', route('webhooks.whatsapp.receive'), [], [], [], $server, $raw);
    }

    private function createOrder(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'ORD-CHN-'.uniqid(),
            'user_id' => $this->pelanggan->id,
            'address_id' => null,
            'total_amount' => 10000,
            'shipping_cost' => 0,
            'discount_amount' => 0,
            'grand_total' => 10000,
            'status' => 'pending',
        ], $overrides));
    }

    private function createShipment(Order $order, array $overrides = []): Shipment
    {
        return Shipment::create(array_merge([
            'order_id' => $order->id,
            'courier_id' => $this->kurir->id,
            'tracking_number' => 'TRK-CHN-'.uniqid(),
            'status' => 'waiting',
        ], $overrides));
    }

    private function replyLogs(): Builder
    {
        return NotificationLog::query()->where('event_key', 'chatbot.reply');
    }

    /* =====================================================================
     | VERIFIKASI WEBHOOK WHATSAPP (GET hub.challenge)
     * ===================================================================*/

    public function test_whatsapp_webhook_verification_returns_challenge_for_valid_token(): void
    {
        $this->configureChannels();

        $this->get(route('webhooks.whatsapp.verify', [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'wa-verify-token',
            'hub.challenge' => '987654321',
        ]))
            ->assertOk()
            ->assertContent('987654321');
    }

    public function test_whatsapp_webhook_verification_rejects_wrong_token(): void
    {
        $this->configureChannels();

        $this->get(route('webhooks.whatsapp.verify', [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'token-salah',
            'hub.challenge' => '987654321',
        ]))->assertForbidden();
    }

    public function test_whatsapp_webhook_verification_rejects_when_token_not_configured(): void
    {
        $this->configureChannels();
        config(['services.whatsapp.verify_token' => '']);

        $this->get(route('webhooks.whatsapp.verify', [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => '',
            'hub.challenge' => '987654321',
        ]))->assertForbidden();
    }

    /* =====================================================================
     | PESAN MASUK WHATSAPP
     * ===================================================================*/

    public function test_whatsapp_inbound_message_gets_chatbot_reply(): void
    {
        $this->configureChannels();
        $this->fakeExternalApis();

        $this->postWhatsApp('siapa kamu?')->assertOk()->assertJson(['ok' => true]);

        // Percakapan tamu dibuat per identifier WhatsApp.
        $this->assertDatabaseHas('ai_conversations', [
            'user_id' => null,
            'session_id' => 'whatsapp:6281111222333',
        ]);

        $log = $this->replyLogs()
            ->where('channel', 'whatsapp')
            ->where('recipient', '6281111222333')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(NotificationLog::STATUS_SENT, $log->status);
        // Jawaban identitas baku + panduan tautan akun untuk tamu.
        $this->assertStringContainsString('Asisten Agung ATK', (string) $log->body);
        $this->assertStringContainsString('Integrasi Chatbot', (string) $log->body);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return str_contains($request->url(), 'graph.facebook.com')
                && (string) ($data['to'] ?? '') === '6281111222333'
                && str_contains((string) ($data['text']['body'] ?? ''), 'Asisten Agung ATK');
        });
    }

    public function test_whatsapp_inbound_rejects_invalid_signature(): void
    {
        $this->configureChannels();
        $this->fakeExternalApis();

        $this->postWhatsApp('halo', ['signature' => 'sha256=deadbeef'])->assertForbidden();

        $this->assertSame(0, $this->replyLogs()->count());
    }

    public function test_whatsapp_duplicate_message_is_processed_once(): void
    {
        $this->configureChannels();
        $this->fakeExternalApis();

        $options = ['wamid' => 'wamid.DUP-1'];

        $this->postWhatsApp('siapa kamu?', $options)->assertOk();
        $this->postWhatsApp('siapa kamu?', $options)->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(1, $this->replyLogs()->where('channel', 'whatsapp')->count());
    }

    public function test_whatsapp_inbound_ignored_when_disabled(): void
    {
        $this->configureChannels();
        config(['services.whatsapp.enabled' => false]);
        Http::fake();

        $this->postWhatsApp('siapa kamu?')
            ->assertOk()
            ->assertJson(['ok' => true, 'ignored' => 'disabled']);

        $this->assertSame(0, $this->replyLogs()->count());
        Http::assertNothingSent();
    }

    /* =====================================================================
     | PESAN MASUK TELEGRAM
     * ===================================================================*/

    public function test_oversized_incoming_message_gets_polite_reply_without_ai(): void
    {
        $this->configureChannels();
        $this->fakeExternalApis();

        $this->postWhatsApp(str_repeat('x', InboundChatService::MAX_MESSAGE_LENGTH + 1))
            ->assertOk()
            ->assertJson(['ok' => true]);

        // AI tidak dipanggil sama sekali: tidak ada percakapan yang dibuat.
        $this->assertSame(0, AiConversation::query()->count());

        $log = $this->replyLogs()->where('channel', 'whatsapp')->first();

        $this->assertNotNull($log, 'Pesan terlalu panjang tetap dibalas sopan.');
        $this->assertStringContainsString('terlalu panjang', (string) $log->body);
    }

    public function test_telegram_webhook_rejects_wrong_secret(): void
    {
        $this->configureChannels();
        Http::fake();

        $this->postTelegram('halo', ['secret' => 'salah'])->assertForbidden();

        $this->assertSame(0, $this->replyLogs()->count());
    }

    public function test_telegram_webhook_rejects_when_secret_not_configured(): void
    {
        $this->configureChannels();
        config(['services.telegram.webhook_secret' => '']);
        Http::fake();

        $this->postTelegram('halo')->assertForbidden();

        $this->assertSame(0, $this->replyLogs()->count());
    }

    public function test_telegram_webhook_ignored_when_disabled(): void
    {
        $this->configureChannels();
        config(['services.telegram.enabled' => false]);
        Http::fake();

        $this->postTelegram('halo')
            ->assertOk()
            ->assertJson(['ok' => true, 'ignored' => 'disabled']);

        $this->assertSame(0, $this->replyLogs()->count());
        Http::assertNothingSent();
    }

    public function test_telegram_linked_user_gets_own_account_data(): void
    {
        $this->configureChannels();
        $this->fakeExternalApis();

        ChannelIdentity::factory()->verified('1887743171')->create([
            'user_id' => $this->pelanggan->id,
            'channel' => ChannelIdentity::CHANNEL_TELEGRAM,
        ]);

        $order = $this->createOrder();
        $this->createShipment($order, [
            'tracking_number' => 'TRK-LINK-001',
            'status' => 'shipping',
        ]);

        $this->postTelegram('Pesanan saya sudah sampai mana?')->assertOk();

        $log = $this->replyLogs()->where('channel', 'telegram')->first();

        $this->assertNotNull($log);
        $this->assertSame('1887743171', $log->recipient);
        $this->assertSame(NotificationLog::STATUS_SENT, $log->status);
        $this->assertStringContainsString($order->order_number, (string) $log->body);
        $this->assertStringContainsString('TRK-LINK-001', (string) $log->body);

        // Percakapan memakai akun terhubung (konteks role dari session backend).
        $this->assertDatabaseHas('ai_conversations', [
            'user_id' => $this->pelanggan->id,
            'session_id' => null,
        ]);
        $this->assertDatabaseMissing('ai_conversations', [
            'session_id' => 'telegram:1887743171',
        ]);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return str_contains($request->url(), 'sendMessage')
                && (string) ($data['chat_id'] ?? '') === '1887743171'
                && str_contains((string) ($data['text'] ?? ''), 'TRK-LINK-001');
        });
    }

    public function test_telegram_guest_cannot_access_account_data(): void
    {
        $this->configureChannels();
        $this->fakeExternalApis();

        $order = $this->createOrder();
        $this->createShipment($order, ['tracking_number' => 'TRK-TAMU-001']);

        $this->postTelegram('Pesanan saya sudah sampai mana?')->assertOk();

        $log = $this->replyLogs()->where('channel', 'telegram')->first();

        $this->assertNotNull($log);
        // Tamu: diminta login, tanpa data pribadi, plus panduan tautan akun.
        $this->assertStringContainsString('Anda belum login', (string) $log->body);
        $this->assertStringContainsString('Integrasi Chatbot', (string) $log->body);
        $this->assertStringNotContainsString($order->order_number, (string) $log->body);
        $this->assertStringNotContainsString('TRK-TAMU-001', (string) $log->body);

        $this->assertDatabaseHas('ai_conversations', [
            'user_id' => null,
            'session_id' => 'telegram:1887743171',
        ]);
    }

    public function test_telegram_duplicate_update_is_ignored(): void
    {
        $this->configureChannels();
        $this->fakeExternalApis();

        $options = ['update_id' => 555000111];

        $first = $this->postTelegram('siapa kamu?', $options);
        $first->assertOk();
        $this->assertNull($first->json('ignored'));

        $second = $this->postTelegram('siapa kamu?', $options);
        $second->assertOk()->assertJson(['ignored' => 'duplicate']);

        $this->assertSame(1, $this->replyLogs()->where('channel', 'telegram')->count());
    }

    public function test_telegram_start_greets_with_link_instructions(): void
    {
        $this->configureChannels();
        $this->fakeExternalApis();

        $this->postTelegram('/start')->assertOk()->assertJson(['greeting' => true]);

        $log = $this->replyLogs()->where('channel', 'telegram')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('Integrasi Chatbot', (string) $log->body);
    }

    public function test_telegram_verification_code_links_account(): void
    {
        $this->configureChannels();
        $this->fakeExternalApis();

        $code = app(ChannelIdentityService::class)
            ->requestCode($this->pelanggan, ChannelIdentity::CHANNEL_TELEGRAM)
            ->verification_code;

        $this->postTelegram($code)->assertOk()->assertJson(['linked' => true]);

        $identity = ChannelIdentity::query()
            ->where('user_id', $this->pelanggan->id)
            ->where('channel', ChannelIdentity::CHANNEL_TELEGRAM)
            ->first();

        $this->assertNotNull($identity);
        $this->assertSame(ChannelIdentity::STATUS_VERIFIED, $identity->status);
        $this->assertSame('1887743171', $identity->identifier);

        $linkLog = $this->replyLogs()->where('channel', 'telegram')->first();
        $this->assertStringContainsString('berhasil dihubungkan', (string) $linkLog?->body);

        // Setelah terhubung, pertanyaan data memakai akunnya.
        $order = $this->createOrder();
        $this->createShipment($order, ['tracking_number' => 'TRK-SETELAH-LINK']);

        $this->postTelegram('Pesanan saya sudah sampai mana?')->assertOk();

        $replyLog = $this->replyLogs()
            ->where('channel', 'telegram')
            ->latest('id')
            ->first();

        $this->assertStringContainsString($order->order_number, (string) $replyLog?->body);
    }

    public function test_telegram_six_digit_text_without_matching_code_goes_to_chatbot(): void
    {
        $this->configureChannels();
        $this->fakeExternalApis();

        $this->postTelegram('123456')->assertOk();

        $this->assertNull($this->postTelegram('654321')->json('linked'));

        // Tidak ada klaim tautan; pesan tetap dijawab chatbot.
        $this->assertSame(
            0,
            ChannelIdentity::query()->where('status', ChannelIdentity::STATUS_VERIFIED)->count()
        );
        $this->assertDatabaseHas('ai_conversations', [
            'session_id' => 'telegram:1887743171',
        ]);
        $this->assertGreaterThanOrEqual(2, $this->replyLogs()->where('channel', 'telegram')->count());
    }

    public function test_telegram_wrong_pending_code_does_not_link(): void
    {
        $this->configureChannels();
        $this->fakeExternalApis();

        ChannelIdentity::factory()->create([
            'user_id' => $this->pelanggan->id,
            'channel' => ChannelIdentity::CHANNEL_TELEGRAM,
            'verification_code' => '654321',
            'code_expires_at' => now()->addMinutes(10),
        ]);

        $this->postTelegram('111111')->assertOk();

        $this->assertNull($this->postTelegram('111111')->json('linked'));

        $this->assertDatabaseHas('channel_identities', [
            'user_id' => $this->pelanggan->id,
            'channel' => ChannelIdentity::CHANNEL_TELEGRAM,
            'status' => ChannelIdentity::STATUS_PENDING,
            'verification_code' => '654321',
        ]);
        $this->assertSame(
            0,
            ChannelIdentity::query()->where('status', ChannelIdentity::STATUS_VERIFIED)->count()
        );
    }

    /* =====================================================================
     | KEGAGALAN API & KEBIJAKAN RETRY
     * ===================================================================*/

    public function test_reply_api_failure_is_logged_and_webhook_still_succeeds(): void
    {
        $this->configureChannels();
        $this->fakeTelegramSendFailure();

        // Webhook tetap sukses — kegagalan balasan tidak pernah menjatuhkannya.
        $this->postTelegram('halo')->assertOk()->assertJson(['ok' => true]);

        $log = $this->replyLogs()->where('channel', 'telegram')->first();

        $this->assertNotNull($log);
        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);
        $this->assertNotEmpty($log->error);
    }

    public function test_reply_job_declares_retry_policy(): void
    {
        $job = new SendChannelReplyJob('telegram', '12345', 'halo');

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30], $job->backoff);
    }

    /* =====================================================================
     | NOTIFIKASI BERDASARKAN ROLE (IDENTITAS TERVERIFIKASI)
     * ===================================================================*/

    private function enableNotificationDispatch(): void
    {
        config([
            'notifications.enabled' => true,
            'notifications.admin_phone' => '6289697358716',
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => 'test-telegram-token',
            'services.telegram.admin_chat_id' => '999888777',
            'services.whatsapp.access_token' => 'test-whatsapp-token',
            'services.whatsapp.phone_number_id' => '1234567890',
            'services.whatsapp.message_mode' => 'text',
        ]);

        $this->fakeExternalApis();
    }

    public function test_linked_courier_receives_telegram_notification_target(): void
    {
        $this->enableNotificationDispatch();

        ChannelIdentity::factory()->verified('555444333')->create([
            'user_id' => $this->kurir->id,
            'channel' => ChannelIdentity::CHANNEL_TELEGRAM,
        ]);

        $order = $this->createOrder();
        $this->createShipment($order); // observer -> shipment.assigned (courier + customer)

        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'shipment.assigned',
            'channel' => 'telegram',
            'recipient' => '555444333',
            'status' => NotificationLog::STATUS_SENT,
        ]);
    }

    public function test_linked_customer_receives_telegram_notification_target(): void
    {
        $this->enableNotificationDispatch();

        ChannelIdentity::factory()->verified('111222333')->create([
            'user_id' => $this->pelanggan->id,
            'channel' => ChannelIdentity::CHANNEL_TELEGRAM,
        ]);

        $this->createOrder(); // observer -> order.created (customer)

        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'order.created',
            'channel' => 'telegram',
            'recipient' => '111222333',
            'status' => NotificationLog::STATUS_SENT,
        ]);
    }

    public function test_disabled_channel_blocks_event_notifications(): void
    {
        $this->enableNotificationDispatch();
        config(['services.telegram.enabled' => false]);

        ChannelIdentity::factory()->verified('555444333')->create([
            'user_id' => $this->kurir->id,
            'channel' => ChannelIdentity::CHANNEL_TELEGRAM,
        ]);

        $order = $this->createOrder();
        $this->createShipment($order);

        $this->assertSame(
            0,
            NotificationLog::query()->where('channel', 'telegram')->count()
        );
    }

    public function test_sendtest_blocked_when_channel_disabled(): void
    {
        config([
            'notifications.enabled' => true,
            'services.telegram.enabled' => false,
            'services.telegram.bot_token' => 'test-telegram-token',
            'services.telegram.admin_chat_id' => '999888777',
        ]);

        $result = app(NotificationManager::class)->sendTest('telegram');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('TELEGRAM_ENABLED', (string) $result['error']);
    }

    /* =====================================================================
     | DASHBOARD ADMIN
     * ===================================================================*/

    public function test_dashboard_shows_integration_status_without_secrets(): void
    {
        config([
            'services.telegram.bot_token' => 'TG-TOKEN-RAHASIA123',
            'services.telegram.webhook_secret' => 'WEBHOOK-SECRET-RAHASIA456',
            'services.telegram.bot_username' => 'AGUNGATK_bot',
            'notifications.admin_phone' => '6289697358716',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee('Integrasi Chatbot')
            ->assertSee('/webhooks/telegram')
            ->assertSee('AGUNGATK_bot')
            // Nomor penerima notifikasi admin tampil apa adanya pada panel
            // konfigurasi (milik admin sendiri); penerima riwayat tetap
            // tersamarkan (lihat NotificationSettingsTest::test_history_table_masks_recipient).
            ->assertSee('6289697358716')
            ->assertDontSee('TG-TOKEN-RAHASIA123')
            ->assertDontSee('WEBHOOK-SECRET-RAHASIA456');
    }

    public function test_dashboard_connection_test_stores_result_without_real_send(): void
    {
        config([
            'services.telegram.bot_token' => 'test-telegram-token',
            'services.telegram.admin_chat_id' => '999888777',
            'services.telegram.bot_username' => 'AGUNGATK_bot',
        ]);

        Http::fake([
            'api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => ['username' => 'AGUNGATK_bot', 'first_name' => 'AGUNG'],
            ]),
            'api.telegram.org/*/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => ['url' => '', 'pending_update_count' => 0],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
            'graph.facebook.com/*' => Http::response(['display_phone_number' => '6289697358716'], 200),
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.notifications.connections'));

        $response->assertRedirect();
        $response->assertSessionHas('error'); // WhatsApp belum terkonfigurasi -> hasil parsial

        $test = Cache::get('chatbot.connections.test');

        $this->assertIsArray($test);
        $this->assertSame('AGUNGATK_bot', $test['telegram']['username']);
        $this->assertTrue($test['telegram']['username_matches']);
        $this->assertFalse($test['whatsapp']['ok']);
        $this->assertStringContainsString('WHATSAPP_ACCESS_TOKEN', (string) $test['whatsapp']['error']);

        // Uji koneksi hanya memanggil getMe/getWebhookInfo —
        // tidak ada pesan yang dikirim (sendMessage / graph messages).
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'sendMessage'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'graph.facebook.com'));

        $this->actingAs($this->admin)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee('AGUNGATK_bot');
    }

    /* =====================================================================
     | TAUTAN AKUN DI PROFIL
     * ===================================================================*/

    public function test_profile_shows_integration_card_and_creates_pending_code(): void
    {
        $this->actingAs($this->pelanggan)
            ->get(route('profile.index'))
            ->assertOk()
            ->assertSee('Integrasi Chatbot');

        $this->actingAs($this->pelanggan)
            ->post(route('profile.channel.code', ['channel' => 'telegram']))
            ->assertRedirect(route('profile.index'));

        $identity = ChannelIdentity::query()
            ->where('user_id', $this->pelanggan->id)
            ->where('channel', ChannelIdentity::CHANNEL_TELEGRAM)
            ->where('status', ChannelIdentity::STATUS_PENDING)
            ->first();

        $this->assertNotNull($identity);
        $this->assertNotEmpty($identity->verification_code);

        $this->actingAs($this->pelanggan)
            ->get(route('profile.index'))
            ->assertOk()
            ->assertSee((string) $identity->verification_code, false);
    }

    public function test_profile_linking_requires_login(): void
    {
        $this->post(route('profile.channel.code', ['channel' => 'telegram']))
            ->assertRedirect('/login');

        $this->assertSame(0, ChannelIdentity::query()->count());
    }

    public function test_profile_unlink_removes_identity(): void
    {
        ChannelIdentity::factory()->verified('1887743171')->create([
            'user_id' => $this->pelanggan->id,
            'channel' => ChannelIdentity::CHANNEL_TELEGRAM,
        ]);

        $this->actingAs($this->pelanggan)
            ->post(route('profile.channel.unlink', ['channel' => 'telegram']))
            ->assertRedirect(route('profile.index'));

        $this->assertSame(0, ChannelIdentity::query()->count());
    }

    /* =====================================================================
     | LAYANAN IDENTITAS KANAL
     * ===================================================================*/

    public function test_expired_verification_code_is_rejected(): void
    {
        $service = app(ChannelIdentityService::class);

        $identity = $service->requestCode($this->pelanggan, ChannelIdentity::CHANNEL_TELEGRAM);
        $identity->update(['code_expires_at' => now()->subMinute()]);

        $result = $service->verifyCode(
            ChannelIdentity::CHANNEL_TELEGRAM,
            '1887743171',
            (string) $identity->verification_code
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('kedaluwarsa', (string) ($result['error'] ?? ''));
    }

    public function test_identifier_already_linked_to_other_user_is_rejected(): void
    {
        ChannelIdentity::factory()->verified('999888777')->create([
            'user_id' => $this->admin->id,
            'channel' => ChannelIdentity::CHANNEL_TELEGRAM,
        ]);

        $result = app(ChannelIdentityService::class)->verifyCode(
            ChannelIdentity::CHANNEL_TELEGRAM,
            '999888777',
            '123456'
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sudah terhubung', (string) ($result['error'] ?? ''));
    }

    /* =====================================================================
     | CALLBACK STATUS PENGIRIMAN & PERIKSA STATUS API
     * ===================================================================*/

    public function test_whatsapp_status_callback_is_recorded_without_chatbot_processing(): void
    {
        $this->configureChannels();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '6281234567890',
                            'phone_number_id' => '111222333',
                        ],
                        'statuses' => [[
                            'id' => 'wamid.STATUS.UJI1',
                            'status' => 'delivered',
                            'recipient_id' => '62881024127186',
                            'timestamp' => '1795000000',
                        ]],
                    ],
                ]],
            ]],
        ];

        $raw = json_encode($payload);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'wa-app-secret'),
        ];

        $this->call('POST', route('webhooks.whatsapp.receive'), [], [], [], $server, $raw)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $stored = Cache::get('chatbot.webhook.last_whatsapp_status');

        $this->assertIsArray($stored, 'Callback status harus tercatat untuk dashboard.');
        $this->assertSame('delivered', $stored['status']);
        $this->assertSame('wamid.STATUS.UJI1', $stored['id']);
        $this->assertNotNull($stored['at']);

        // Callback status BUKAN pesan masuk — tidak ada balasan chatbot.
        $this->assertSame(0, $this->replyLogs()->where('channel', 'whatsapp')->count());

        // Callback ganda tidak diproses ulang (anti-duplikat).
        $this->call('POST', route('webhooks.whatsapp.receive'), [], [], [], $server, $raw)
            ->assertOk();

        $this->assertSame(0, $this->replyLogs()->where('channel', 'whatsapp')->count());
    }

    public function test_whatsapp_api_status_endpoint_returns_live_status_json(): void
    {
        $this->configureChannels();
        config(['notifications.admin_phone' => '62881024127186']);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'display_phone_number' => '62881024127186',
                'verified_name' => 'Agung ATK',
            ], 200),
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.notifications.whatsapp.status'));

        $response->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('missing', [])
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('admin_number', '62881024127186')
            ->assertJsonPath('connection.ok', true)
            ->assertJsonPath('connection.display_phone', '62881024127186')
            ->assertJsonPath('message_mode', 'text');

        $this->assertNotNull($response->json('checked_at'));
        $this->assertArrayHasKey('last_sent', $response->json());
        $this->assertArrayHasKey('last_delivery', $response->json());

        // Endpoint hanya membaca status — tidak pernah mengirim pesan.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/messages'));
    }

    public function test_whatsapp_api_status_endpoint_requires_admin(): void
    {
        $this->configureChannels();

        $this->actingAs($this->pelanggan)
            ->postJson(route('admin.notifications.whatsapp.status'))
            ->assertForbidden();
    }
}
