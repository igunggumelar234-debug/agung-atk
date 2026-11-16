<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationLog;
use App\Services\Notifications\NotificationManager;
use App\Services\Notifications\WhatsAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PROMPT 7 — WhatsApp Business Cloud API: normalisasi nomor & kode negara
 * terkonfigurasi, penanganan error/timeout API (token tidak pernah bocor ke
 * log), idempotensi job saat redelivery, mode template, dan mode nonaktif.
 * Seluruh pengujian memakai Http::fake — TIDAK PERNAH mengirim pesan nyata.
 */
class WhatsAppCloudApiTest extends TestCase
{
    use RefreshDatabase;

    private function configureWhatsApp(): void
    {
        config()->set('notifications.enabled', true);
        config()->set('notifications.mock', false);
        config()->set('services.whatsapp.access_token', 'RAHASIA-TOKEN-WA-123');
        config()->set('services.whatsapp.phone_number_id', '1234567890');
        config()->set('services.whatsapp.default_country_code', '62');
    }

    private function pendingWhatsAppLog(): NotificationLog
    {
        return NotificationLog::create([
            'event_key' => 'order.new',
            'channel' => 'whatsapp',
            'recipient' => '6289697358716',
            'subject' => 'Uji WhatsApp',
            'body' => 'Agung ATK: Ada pesanan baru yang perlu diproses.',
            'status' => NotificationLog::STATUS_PENDING,
        ]);
    }

    /* =====================================================================
     | NORMALISASI NOMOR & KODE NEGARA
     * ===================================================================*/

    public function test_indonesian_phone_formats_normalize_to_international(): void
    {
        $this->assertSame('6289697358716', WhatsAppNotificationService::normalizeInternational('089697358716'));
        $this->assertSame('6289697358716', WhatsAppNotificationService::normalizeInternational('+6289697358716'));
        $this->assertSame('6289697358716', WhatsAppNotificationService::normalizeInternational('6289697358716'));
        $this->assertSame('6289697358716', WhatsAppNotificationService::normalizeInternational('89697358716'));
        $this->assertSame('6289697358716', WhatsAppNotificationService::normalizeInternational(' 0896-9735-8716 '));
    }

    public function test_invalid_phone_returns_null_without_api_call(): void
    {
        Http::preventStrayRequests();

        $this->assertNull(WhatsAppNotificationService::normalizeInternational(null));
        $this->assertNull(WhatsAppNotificationService::normalizeInternational(''));
        $this->assertNull(WhatsAppNotificationService::normalizeInternational('abc'));
        $this->assertNull(WhatsAppNotificationService::normalizeInternational('12345'));
        $this->assertNull(WhatsAppNotificationService::normalizeInternational('0896'));
    }

    public function test_default_country_code_is_configurable(): void
    {
        config()->set('services.whatsapp.default_country_code', '60');

        // Nomor lokal Malaysia 0123456789 -> 60123456789 (bukan 62...).
        $this->assertSame('60123456789', WhatsAppNotificationService::normalizeInternational('0123456789'));

        // Kembali ke konfigurasi Indonesia.
        config()->set('services.whatsapp.default_country_code', '62');
        $this->assertSame('6289697358716', WhatsAppNotificationService::normalizeInternational('089697358716'));
    }

    /* =====================================================================
     | PENANGANAN ERROR & TIMEOUT API (TOKEN TIDAK PERNAH BOCOR)
     * ===================================================================*/

    public function test_api_error_marks_log_failed_without_leaking_token(): void
    {
        $this->configureWhatsApp();

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190],
            ], 401),
        ]);

        $log = $this->pendingWhatsAppLog();
        (new SendNotificationJob($log->id))->handle();
        $log->refresh();

        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);
        $this->assertNotEmpty($log->error);
        $this->assertStringNotContainsString('RAHASIA-TOKEN-WA-123', (string) $log->error);
    }

    public function test_connection_timeout_marks_log_failed_without_leaking_token(): void
    {
        $this->configureWhatsApp();

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);

        $log = $this->pendingWhatsAppLog();
        (new SendNotificationJob($log->id))->handle();
        $log->refresh();

        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('timed out', (string) $log->error);
        $this->assertStringNotContainsString('RAHASIA-TOKEN-WA-123', (string) $log->error);
    }

    public function test_api_success_marks_log_sent(): void
    {
        $this->configureWhatsApp();

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST123']]], 200),
        ]);

        $log = $this->pendingWhatsAppLog();
        (new SendNotificationJob($log->id))->handle();
        $log->refresh();

        $this->assertSame(NotificationLog::STATUS_SENT, $log->status);
        $this->assertNull($log->error);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/1234567890/messages'));
    }

    /* =====================================================================
     | IDEMPOTENSI: REDELIVERY JOB TIDAK MENGHASILKAN DUPLIKAT
     * ===================================================================*/

    public function test_redelivered_job_does_not_send_twice(): void
    {
        $this->configureWhatsApp();

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200),
        ]);

        $log = $this->pendingWhatsAppLog();

        (new SendNotificationJob($log->id))->handle();
        (new SendNotificationJob($log->id))->handle(); // redelivery dari queue
        (new SendNotificationJob($log->id))->handle();

        // Hanya SATU panggilan API untuk satu log.
        Http::assertSentCount(1);
        $this->assertSame(NotificationLog::STATUS_SENT, $log->refresh()->status);
    }

    public function test_missing_log_is_skipped_safely(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        (new SendNotificationJob(999999))->handle();

        Http::assertNothingSent();
    }

    /* =====================================================================
     | MODE TEMPLATE (PERSETUJUAN META)
     * ===================================================================*/

    public function test_template_mode_sends_approved_template_payload(): void
    {
        $this->configureWhatsApp();
        config()->set('services.whatsapp.message_mode', 'template');
        config()->set('services.whatsapp.template_name', 'notif_agung_atk');
        config()->set('services.whatsapp.template_language', 'id');

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TPL']]], 200),
        ]);

        app(WhatsAppNotificationService::class)->send(
            '089697358716',
            "Agung ATK: Ada pesanan baru.\nSilakan buka aplikasi."
        );

        Http::assertSent(function ($request) {
            $json = $request->data();

            return str_contains($request->url(), '/messages')
                && ($json['type'] ?? null) === 'template'
                && ($json['template']['name'] ?? null) === 'notif_agung_atk'
                && ($json['template']['language']['code'] ?? null) === 'id'
                && ($json['to'] ?? null) === '6289697358716';
        });
    }

    public function test_template_mode_without_name_fails_with_clear_error(): void
    {
        $this->configureWhatsApp();
        config()->set('services.whatsapp.message_mode', 'template');
        config()->set('services.whatsapp.template_name', null);

        Http::preventStrayRequests();
        Http::fake();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WHATSAPP_TEMPLATE_NAME');

        app(WhatsAppNotificationService::class)->send('089697358716', 'Pesan uji.');
    }

    /* =====================================================================
     | MODE NONAKTIF & KONFIGURASI BELUM LENGKAP
     * ===================================================================*/

    public function test_globally_disabled_notifications_reject_test_send(): void
    {
        config()->set('notifications.enabled', false);
        config()->set('services.whatsapp.access_token', 'token-dummy');
        config()->set('services.whatsapp.phone_number_id', '1234567890');

        Http::preventStrayRequests();
        Http::fake();

        $result = app(NotificationManager::class)->sendTest('whatsapp');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('NOTIFICATIONS_ENABLED', (string) $result['error']);
        Http::assertNothingSent();
    }

    public function test_unconfigured_credentials_fail_before_any_api_call(): void
    {
        config()->set('notifications.enabled', true);
        config()->set('notifications.mock', false);
        config()->set('services.whatsapp.access_token', null);
        config()->set('services.whatsapp.phone_number_id', null);

        Http::preventStrayRequests();
        Http::fake();

        $result = app(NotificationManager::class)->sendTest('whatsapp');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('WHATSAPP_ACCESS_TOKEN', (string) $result['error']);
        Http::assertNothingSent();
    }
}
