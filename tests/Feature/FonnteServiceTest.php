<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Notifications\FonnteService;
use App\Services\Notifications\NotificationManager;
use App\Services\Notifications\WhatsAppNotificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Bagian 3 MASTER — integrasi WhatsApp Fonnte (provider utama).
 *
 * Mencakup: endpoint /send & /get-devices sesuai dokumentasi Fonnte,
 * header Authorization mentah tanpa prefix Bearer, dispatch provider pada
 * WhatsAppNotificationService, status kanal sadar-provider, endpoint
 * dashboard FonnteController, webhook masuk (secret path gagal-tertutup +
 * anti-duplikat), serta pencatatan riwayat notification_logs.
 *
 * Seluruh panggilan API memakai Http::fake — tidak ada pesan nyata
 * maupun token asli yang dipakai pada pengujian ini.
 */
class FonnteServiceTest extends TestCase
{
    use RefreshDatabase;

    protected const FONNTE_TOKEN = 'FONNTE-RAHASIA-UJI-12345';

    protected const ADMIN_PHONE = '62881024127186';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Aktifkan provider Fonnte dengan kredensial dummy.
     */
    private function useFonnte(string $token = self::FONNTE_TOKEN): void
    {
        config([
            'services.whatsapp.provider' => 'fonnte',
            'services.whatsapp.enabled' => true,
            'services.fonnte.url' => 'https://api.fonnte.com',
            'services.fonnte.token' => $token,
            'services.fonnte.timeout' => 15,
            'notifications.admin_phone' => self::ADMIN_PHONE,
        ]);
    }

    /**
     * Fake respons sukses Fonnte (/send dan /device).
     */
    private function fakeSendSuccess(): void
    {
        Http::fake([
            'api.fonnte.com/send' => Http::response([
                'detail' => 'success! message in queue',
                'id' => ['80367170'],
                'process' => 'pending',
                'status' => true,
                'target' => [self::ADMIN_PHONE],
            ]),
            // Bentuk respons POST /device (datar) — bukan /get-devices.
            'api.fonnte.com/device' => Http::response([
                'attachment' => false,
                'device' => '628111222333',
                'device_status' => 'connect',
                'expired' => '17 December 2026',
                'messages' => 12,
                'name' => 'agung-atk',
                'package' => 'Free',
                'quota' => '950',
                'status' => true,
            ]),
        ]);
    }

    public function test_send_uses_raw_authorization_header_and_documented_payload(): void
    {
        $this->useFonnte();
        $this->fakeSendSuccess();

        $result = app(FonnteService::class)->send('0881024127186', 'Halo dari Agung ATK');

        $this->assertTrue($result['ok']);
        $this->assertSame('80367170', $result['id']);
        $this->assertNull($result['error']);

        // Header Authorization berisi token MENTAH (tanpa prefix "Bearer"),
        // body memakai parameter target + message + countryCode sesuai docs.
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.fonnte.com/send'
                && $request->header('Authorization')[0] === self::FONNTE_TOKEN
                && $request['target'] === self::ADMIN_PHONE
                && $request['message'] === 'Halo dari Agung ATK'
                && $request['countryCode'] === '0';
        });
    }

    public function test_send_reports_provider_reason_when_rejected(): void
    {
        $this->useFonnte();

        // Fonnte kadang memakai "Status" (huruf besar) untuk kegagalan logis.
        Http::fake([
            'api.fonnte.com/send' => Http::response(['Status' => false, 'reason' => 'token invalid'], 200),
        ]);

        $result = app(FonnteService::class)->send(self::ADMIN_PHONE, 'pesan uji');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['id']);
        $this->assertStringContainsString('token invalid', (string) $result['error']);
        // Token tidak pernah bocor pada pesan kesalahan.
        $this->assertStringNotContainsString(self::FONNTE_TOKEN, (string) $result['error']);
    }

    public function test_send_without_token_fails_without_any_http_call(): void
    {
        $this->useFonnte(token: '');

        Http::fake();

        $result = app(FonnteService::class)->send(self::ADMIN_PHONE, 'pesan uji');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('FONNTE_API_TOKEN', (string) $result['error']);
        Http::assertNothingSent();
    }

    public function test_send_rejects_invalid_recipient_without_http_call(): void
    {
        $this->useFonnte();

        Http::fake();

        $result = app(FonnteService::class)->send('bukan-nomor', 'pesan uji');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('tidak valid', (string) $result['error']);
        Http::assertNothingSent();
    }

    public function test_device_returns_status_without_any_secret_fields(): void
    {
        $this->useFonnte();
        $this->fakeSendSuccess();

        $device = app(FonnteService::class)->device();

        $this->assertTrue($device['ok']);
        $this->assertSame(1, $device['connected']);
        $this->assertSame('628111222333', $device['device']);
        $this->assertSame('connect', $device['device_status']);
        $this->assertSame('950', $device['quota']);
        $this->assertSame(12, $device['messages']);
        // Respons /device tidak memuat token — dan tidak pernah
        // dikembalikan meski ada pada bentuk respons lain.
        $this->assertArrayNotHasKey('token', $device);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.fonnte.com/device'
                && $request->header('Authorization')[0] === self::FONNTE_TOKEN;
        });
    }

    public function test_device_without_token_fails_without_http_call(): void
    {
        $this->useFonnte(token: '');

        Http::fake();

        $device = app(FonnteService::class)->device();

        $this->assertFalse($device['ok']);
        $this->assertStringContainsString('FONNTE_API_TOKEN', (string) $device['error']);
        Http::assertNothingSent();
    }

    public function test_whatsapp_service_dispatches_to_fonnte_when_provider_selected(): void
    {
        $this->useFonnte();
        $this->fakeSendSuccess();

        app(WhatsAppNotificationService::class)->send(self::ADMIN_PHONE, 'Notifikasi uji Fonnte');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.fonnte.com/send'
                && $request['message'] === 'Notifikasi uji Fonnte';
        });
        // Jalur Graph API Meta tidak tersentuh sama sekali.
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com');
        });
    }

    public function test_whatsapp_service_throws_runtime_exception_when_fonnte_rejects(): void
    {
        $this->useFonnte();

        Http::fake([
            'api.fonnte.com/send' => Http::response(['status' => false, 'reason' => 'insufficient quota'], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fonnte menolak pesan');

        app(WhatsAppNotificationService::class)->send(self::ADMIN_PHONE, 'pesan uji');
    }

    public function test_channel_status_follows_active_provider(): void
    {
        // Provider Fonnte lengkap -> kanal terkonfigurasi tanpa kredensial Meta.
        $this->useFonnte();

        $configured = $this->app['config']->get('services.whatsapp.provider');
        $this->assertSame('fonnte', $configured);

        $status = app(NotificationManager::class)->channelStatus('whatsapp');

        $this->assertTrue($status['configured']);
        $this->assertSame([], $status['missing']);

        // Token Fonnte kosong -> hanya FONNTE_API_TOKEN yang kurang.
        $this->useFonnte(token: '');

        $missing = app(NotificationManager::class)->channelStatus('whatsapp');

        $this->assertFalse($missing['configured']);
        $this->assertContains('FONNTE_API_TOKEN', $missing['missing']);
        $this->assertNotContains('WHATSAPP_PHONE_NUMBER_ID', $missing['missing']);
    }

    public function test_status_api_endpoint_uses_fonnte_device_read(): void
    {
        $this->useFonnte();
        $this->fakeSendSuccess();

        $admin = User::where('email', 'admin@agungatk.com')->firstOrFail();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.notifications.whatsapp.status'));

        $response->assertOk()
            ->assertJsonPath('provider', 'fonnte')
            ->assertJsonPath('connection.ok', true)
            ->assertJsonPath('connection.display_phone', '628111222333');

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com');
        });
    }

    public function test_fonnte_device_endpoint_is_admin_only(): void
    {
        $this->useFonnte();

        $member = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();

        $this->actingAs($member)
            ->postJson(route('admin.fonnte.device'))
            ->assertForbidden();
    }

    public function test_fonnte_device_endpoint_returns_json_without_secrets(): void
    {
        $this->useFonnte();
        $this->fakeSendSuccess();

        $admin = User::where('email', 'admin@agungatk.com')->firstOrFail();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.fonnte.device'));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('provider', 'fonnte')
            ->assertJsonPath('connected', 1)
            ->assertJsonPath('device', '628111222333');

        $payload = $response->json();

        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('token', $payload);
        // Token akun Fonnte tidak pernah ikut dalam respons.
        $this->assertStringNotContainsString(self::FONNTE_TOKEN, json_encode($payload) ?: '');
    }

    public function test_fonnte_test_send_endpoint_records_real_log_after_provider_response(): void
    {
        $this->useFonnte();
        $this->fakeSendSuccess();

        config([
            'notifications.enabled' => true,
            'notifications.queue' => false,
        ]);

        $admin = User::where('email', 'admin@agungatk.com')->firstOrFail();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.fonnte.test'), ['message' => 'Pesan percobaan Fonnte']);

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('status', 'sent');

        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'whatsapp',
            'status' => 'sent',
        ]);
    }

    public function test_fonnte_test_send_endpoint_marks_failed_when_provider_rejects(): void
    {
        $this->useFonnte();

        Http::fake([
            'api.fonnte.com/*' => Http::response(['status' => false, 'reason' => 'device disconnected'], 200),
        ]);

        config([
            'notifications.enabled' => true,
            'notifications.queue' => false,
        ]);

        $admin = User::where('email', 'admin@agungatk.com')->firstOrFail();

        $response = $this->actingAs($admin)
            ->postJson(route('admin.fonnte.test'));

        // Status TIDAK dinyatakan berhasil sebelum respons provider valid.
        $response->assertStatus(502)->assertJsonPath('ok', false);

        $this->assertDatabaseHas('notification_logs', [
            'channel' => 'whatsapp',
            'status' => 'failed',
        ]);
    }

    public function test_fonnte_webhook_rejects_wrong_secret(): void
    {
        $this->useFonnte();
        config(['services.fonnte.webhook_secret' => 'RAHASIA-WEBHOOK']);

        $this->postJson('/webhooks/fonnte/salah-salah', [
            'sender' => '6281111111111',
            'message' => 'halo',
        ])->assertForbidden();

        $this->postJson('/webhooks/fonnte/'.self::FONNTE_TOKEN, [
            'sender' => '6281111111111',
            'message' => 'halo',
        ])->assertForbidden();
    }

    public function test_fonnte_webhook_fails_closed_when_secret_not_configured(): void
    {
        $this->useFonnte();
        config(['services.fonnte.webhook_secret' => '']);

        $this->postJson('/webhooks/fonnte/apapun', [
            'sender' => '6281111111111',
            'message' => 'halo',
        ])->assertForbidden();
    }

    public function test_fonnte_webhook_processes_message_and_replies_via_fonnte(): void
    {
        $this->useFonnte();
        config([
            'services.fonnte.webhook_secret' => 'RAHASIA-WEBHOOK',
            'notifications.enabled' => true,
            'notifications.queue' => false,
        ]);
        $this->fakeSendSuccess();

        $payload = [
            'device' => '628111222333',
            'sender' => '6281234567890',
            'message' => 'halo',
            'name' => 'Uji Fonnte',
            'timestamp' => '1795000000',
        ];

        $this->postJson('/webhooks/fonnte/RAHASIA-WEBHOOK', $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);

        // Balasan chatbot keluar lewat provider Fonnte (bukan Graph Meta).
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.fonnte.com/send'
                && $request['target'] === '6281234567890';
        });

        // Payload identik yang dikirim ulang oleh Fonnte -> anti-duplikat.
        $this->postJson('/webhooks/fonnte/RAHASIA-WEBHOOK', $payload)
            ->assertOk()
            ->assertJsonPath('ignored', 'duplicate');
    }
}
