<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\Notifications\NotificationManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Halaman pengaturan notifikasi admin: akses, saklar saluran & kejadian,
 * status konfigurasi saat kredensial belum tersedia, dan uji kirim.
 */
class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
    }

    /* =====================================================================
     | AKSES HALAMAN
     * ===================================================================*/

    public function test_guest_is_redirected_to_login_from_settings_page(): void
    {
        $this->get('/admin/notifications')->assertRedirect('/login');
    }

    public function test_non_admin_roles_are_forbidden_from_settings_page(): void
    {
        foreach (['pelanggan@agungatk.com', 'penjual@agungatk.com', 'kurir@agungatk.com'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $this->actingAs($user)->get('/admin/notifications')->assertStatus(403);
            $this->actingAs($user)->post('/admin/notifications/test', ['channel' => 'email'])->assertStatus(403);
        }
    }

    public function test_webhook_urls_use_configured_public_root_when_set(): void
    {
        config()->set('app.webhook_public_url', 'https://contoh.test');

        $response = $this->actingAs($this->admin)
            ->get('/admin/notifications')
            ->assertStatus(200);

        // URL webhook dibangun dari WEBHOOK_PUBLIC_URL — bukan host yang
        // sedang dibuka admin (http://localhost pada lingkungan tes).
        $response->assertSee('https://contoh.test/webhooks/whatsapp');
        $response->assertDontSee('http://localhost/webhooks/whatsapp');
    }

    public function test_settings_page_shows_configuration_status_without_leaking_secrets(): void
    {
        // Kredensial rahasia disuntikkan ke konfigurasi — halaman tidak boleh menampilkannya.
        config()->set('services.telegram.bot_token', 'RAHASIA-XYZ-987');
        config()->set('services.whatsapp.access_token', 'WA-RAHASIA-123');

        $response = $this->actingAs($this->admin)
            ->get('/admin/notifications')
            ->assertStatus(200);

        $response->assertSee('Status Konfigurasi');
        $response->assertSee('Kejadian Notifikasi');
        $response->assertSee('Riwayat Pengiriman');
        $response->assertSee('Kirim Pesan Uji');

        // Status "belum lengkap" + nama variabel .env yang belum diisi.
        // (Kedua kredensial di atas dummy, tetapi Chat ID & penerima lain masih kosong
        // pada environment testing sehingga status sebagian tetap "Belum lengkap".)
        $response->assertSee('TELEGRAM_ADMIN_CHAT_ID');
        $response->assertDontSee('RAHASIA-XYZ-987');
        $response->assertDontSee('WA-RAHASIA-123');
    }

    public function test_settings_page_lists_missing_env_variables_when_credentials_absent(): void
    {
        // Environment testing: seluruh kredensial eksternal kosong.
        $response = $this->actingAs($this->admin)
            ->get('/admin/notifications')
            ->assertStatus(200);

        $response->assertSee('TELEGRAM_BOT_TOKEN');
        $response->assertSee('WHATSAPP_ACCESS_TOKEN');
        $response->assertSee('WHATSAPP_PHONE_NUMBER_ID');
        $response->assertSee('Belum lengkap');
    }

    /* =====================================================================
     | STATUS KREDENSIAL & KARTU SISTEM
     * ===================================================================*/

    public function test_email_channel_requires_smtp_password_to_report_configured(): void
    {
        // SMTP dengan username tetapi tanpa kata sandi (mis. Gmail App
        // Password belum diisi) -> status jujur "belum lengkap".
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'akun@contoh.com',
            'mail.mailers.smtp.password' => null,
            'mail.from.address' => 'akun@contoh.com',
        ]);

        $status = app(NotificationManager::class)->channelStatus('email');

        $this->assertFalse($status['configured']);
        $this->assertContains('MAIL_PASSWORD', $status['missing']);
    }

    public function test_dashboard_shows_system_queue_and_ai_provider_status(): void
    {
        config([
            'ai.key' => null,
            'ai.url' => 'https://ai.test/v1/chat/completions',
            'ai.model' => 'model-tes-ai',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/admin/notifications')
            ->assertStatus(200);

        $response->assertSee('Status Sistem');
        $response->assertSee('Pengantrean Notifikasi');
        $response->assertSee('Provider AI Chatbot');
        $response->assertSee('model-tes-ai');

        // Tanpa kunci AI: status jujur + penjelasan mode fallback.
        $response->assertSee('Belum dikonfigurasi');
        $response->assertSee('AI_API_KEY');
    }

    /* =====================================================================
     | PENYIMPANAN PENGATURAN
     * ===================================================================*/

    public function test_admin_can_disable_channel_and_event_settings(): void
    {
        $channels = ['__sentinel' => '1', 'telegram' => '0', 'whatsapp' => '1', 'email' => '1'];

        $events = ['__sentinel' => '1'];
        foreach (array_keys(config('notifications.events')) as $eventKey) {
            $events[$eventKey] = $eventKey === 'order.new' ? '0' : '1';
        }

        $this->actingAs($this->admin)
            ->from('/admin/notifications')
            ->post('/admin/notifications', ['channels' => $channels, 'events' => $events])
            ->assertRedirect('/admin/notifications')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('notification_settings', [
            'group' => 'channel',
            'key' => 'telegram',
            'enabled' => false,
        ]);
        $this->assertDatabaseHas('notification_settings', [
            'group' => 'channel',
            'key' => 'email',
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('notification_settings', [
            'group' => 'event',
            'key' => 'order.new',
            'enabled' => false,
        ]);
        $this->assertDatabaseHas('notification_settings', [
            'group' => 'event',
            'key' => 'order.created',
            'enabled' => true,
        ]);

        $this->assertFalse(NotificationSetting::isEnabled('channel', 'telegram'));
        $this->assertTrue(NotificationSetting::isEnabled('channel', 'whatsapp'));
    }

    public function test_settings_form_rejects_missing_payload(): void
    {
        $this->actingAs($this->admin)
            ->from('/admin/notifications')
            ->post('/admin/notifications', [])
            ->assertSessionHasErrors(['channels', 'events']);
    }

    /* =====================================================================
     | UJI KIRIM
     * ===================================================================*/

    public function test_unconfigured_channel_reports_clear_error_on_test_send(): void
    {
        // Notifikasi aktif secara global, tetapi token Telegram kosong
        // (kondisi default environment testing) -> status konfigurasi jelas.
        config()->set('notifications.enabled', true);

        $response = $this->actingAs($this->admin)
            ->from('/admin/notifications')
            ->post('/admin/notifications/test', ['channel' => 'telegram'])
            ->assertRedirect('/admin/notifications');

        $response->assertSessionHas('error');
        $this->assertStringContainsString(
            'TELEGRAM_BOT_TOKEN',
            (string) session('error')
        );

        $log = NotificationLog::where('event_key', 'test')
            ->where('channel', 'telegram')
            ->firstOrFail();
        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('TELEGRAM_BOT_TOKEN', (string) $log->error);
    }

    public function test_admin_can_send_successful_telegram_test_message(): void
    {
        config()->set('notifications.enabled', true);
        config()->set('services.telegram.bot_token', 'test-telegram-token');
        config()->set('services.telegram.admin_chat_id', '999888777');

        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]], 200),
        ]);

        $response = $this->actingAs($this->admin)
            ->from('/admin/notifications')
            ->post('/admin/notifications/test', ['channel' => 'telegram'])
            ->assertRedirect('/admin/notifications');

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'test',
            'channel' => 'telegram',
            'recipient' => '999888777',
            'status' => NotificationLog::STATUS_SENT,
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    }

    public function test_admin_can_send_email_test_message(): void
    {
        config()->set('notifications.enabled', true);

        // Mailer array (phpunit) = terkonfigurasi tanpa jaringan.
        $response = $this->actingAs($this->admin)
            ->from('/admin/notifications')
            ->post('/admin/notifications/test', [
                'channel' => 'email',
                'message' => 'Pesan uji internal Agung ATK.',
            ])
            ->assertRedirect('/admin/notifications');

        $response->assertSessionHas('success');

        $log = NotificationLog::where('event_key', 'test')
            ->where('channel', 'email')
            ->firstOrFail();
        $this->assertSame(NotificationLog::STATUS_SENT, $log->status);
        $this->assertStringContainsString('Pesan uji internal Agung ATK.', (string) $log->body);
    }

    public function test_invalid_test_recipient_is_rejected_without_api_call(): void
    {
        config()->set('notifications.enabled', true);
        config()->set('services.whatsapp.access_token', 'test-whatsapp-token');
        config()->set('services.whatsapp.phone_number_id', '1234567890');
        config()->set('notifications.admin_phone', '6289697358716');

        // Tidak ada fake -> permintaan HTTP apa pun akan gagal;
        // seharusnya tidak ada panggilan sama sekali karena tujuan tidak valid.
        Http::preventStrayRequests();

        $response = $this->actingAs($this->admin)
            ->from('/admin/notifications')
            ->post('/admin/notifications/test', [
                'channel' => 'whatsapp',
                'recipient' => 'bukan-nomor-telepon',
            ])
            ->assertRedirect('/admin/notifications');

        $response->assertSessionHas('error');

        $log = NotificationLog::where('event_key', 'test')
            ->where('channel', 'whatsapp')
            ->firstOrFail();
        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('tidak valid', (string) $log->error);
    }

    /* =====================================================================
     | PENERIMA TERSAMARKAN & FILTER RIWAYAT
     * ===================================================================*/

    public function test_history_table_masks_recipient(): void
    {
        NotificationLog::create([
            'event_key' => 'order.new',
            'channel' => 'email',
            'recipient' => 'igunggumelar234@gmail.com',
            'subject' => 'Pesanan baru #ORD-TEST',
            'body' => 'Isi pesan',
            'status' => NotificationLog::STATUS_SENT,
        ]);

        NotificationLog::create([
            'event_key' => 'order.new',
            'channel' => 'whatsapp',
            'recipient' => '6289697358716',
            'subject' => 'Pesanan baru #ORD-TEST',
            'body' => 'Isi pesan',
            'status' => NotificationLog::STATUS_SENT,
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/admin/notifications')
            ->assertStatus(200);

        // Penerima tersamarkan, nilai asli tidak bocor ke halaman.
        $response->assertSee('igu***@gmail.com');
        $response->assertSee('6289***8716');
        $response->assertDontSee('igunggumelar234@gmail.com');
        $response->assertDontSee('6289697358716');
    }

    public function test_history_filter_by_status_channel_and_date(): void
    {
        $today = now()->toDateString();

        NotificationLog::create([
            'event_key' => 'order.new',
            'channel' => 'email',
            'recipient' => 'admin@example.com',
            'subject' => 'A', 'body' => 'A',
            'status' => NotificationLog::STATUS_SENT,
            'created_at' => now(),
        ]);
        NotificationLog::create([
            'event_key' => 'stock.low',
            'channel' => 'whatsapp',
            'recipient' => '6289697358716',
            'subject' => 'B', 'body' => 'B',
            'status' => NotificationLog::STATUS_FAILED,
            'error' => 'Token belum diisi',
            'created_at' => now(),
        ]);
        $oldLog = NotificationLog::create([
            'event_key' => 'report.daily',
            'channel' => 'email',
            'recipient' => 'admin@example.com',
            'subject' => 'C', 'body' => 'C',
            'status' => NotificationLog::STATUS_SENT,
        ]);
        // created_at tidak ada di $fillable — forceFill untuk menyetel
        // timestamp historis secara eksplisit.
        $oldLog->forceFill(['created_at' => now()->subDays(3)])->save();

        // Filter status = failed -> hanya 1 baris (stock.low) yang tampil.
        $this->actingAs($this->admin)
            ->get('/admin/notifications?status=failed')
            ->assertStatus(200)
            ->assertSee('1 catatan')
            ->assertSee('Token belum diisi');

        // Filter saluran = whatsapp -> hanya 1 baris.
        $this->actingAs($this->admin)
            ->get('/admin/notifications?channel=whatsapp')
            ->assertStatus(200)
            ->assertSee('1 catatan');

        // Filter tanggal = hari ini -> 2 baris (laporan harian 3 hari lalu terbuang).
        $this->actingAs($this->admin)
            ->get('/admin/notifications?date='.$today)
            ->assertStatus(200)
            ->assertSee('2 catatan');

        // Tanpa filter -> semua 3 baris tampil.
        $this->actingAs($this->admin)
            ->get('/admin/notifications')
            ->assertStatus(200)
            ->assertSee('3 catatan');
    }

    /* =====================================================================
     | RATE LIMIT PESAN UJI
     * ===================================================================*/

    public function test_test_send_is_rate_limited(): void
    {
        config()->set('notifications.enabled', true);

        // throttle:5,1 -> 5 permintaan pertama lolos, ke-6 ditolak (429).
        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($this->admin)
                ->post('/admin/notifications/test', ['channel' => 'email'])
                ->assertStatus(302);
        }

        $this->actingAs($this->admin)
            ->post('/admin/notifications/test', ['channel' => 'email'])
            ->assertStatus(429);
    }
}
