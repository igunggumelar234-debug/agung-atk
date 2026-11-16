<?php

namespace Tests\Feature;

use App\Jobs\SendChannelReplyJob;
use App\Models\Address;
use App\Models\NotificationLog;
use App\Models\NotificationSetting;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pemicu notifikasi otomatis: pesanan, pembayaran, pengiriman, stok,
 * pendaftaran akun, ringkasan harian, isolasi antar saluran, dan
 * anti-notifikasi-ganda.
 */
class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    private User $pelanggan;

    private User $admin;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->address = Address::where('user_id', $this->pelanggan->id)->firstOrFail();
    }

    /**
     * Aktifkan notifikasi untuk test ini.
     *
     * Dengan $withExternalChannels, kredensial Telegram & WhatsApp diisi
     * (nilai dummy) sehingga ketiga saluran dapat diuji.
     */
    private function enableNotifications(bool $withExternalChannels = true): void
    {
        config()->set('notifications.enabled', true);

        if ($withExternalChannels) {
            config()->set('services.telegram.bot_token', 'test-telegram-token');
            config()->set('services.telegram.admin_chat_id', '999888777');
            config()->set('services.whatsapp.access_token', 'test-whatsapp-token');
            config()->set('services.whatsapp.phone_number_id', '1234567890');
            config()->set('notifications.admin_phone', '6289697358716');
        }
    }

    /**
     * Fake API eksternal — Telegram gagal (opsional), WhatsApp sukses.
     * Permintaan lain dicegah agar tidak pernah menyentuh jaringan.
     */
    private function fakeExternalApis(int $telegramStatus = 200): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'api.telegram.org/*' => $telegramStatus === 200
                ? Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)
                : Http::response(['ok' => false, 'description' => 'Internal Server Error'], $telegramStatus),
            'graph.facebook.com/*' => Http::response(
                ['messages' => [['id' => 'wamid.TEST123']]],
                200
            ),
        ]);
    }

    /**
     * Pesanan berstatus tertentu + 1 rincian item (untuk alur pengiriman).
     */
    private function createOrderWithStatus(string $status): Order
    {
        $order = Order::create([
            'order_number' => 'ORD-NTF-'.uniqid(),
            'user_id' => $this->pelanggan->id,
            'address_id' => $this->address->id,
            'total_amount' => 50000,
            'shipping_cost' => 10000,
            'discount_amount' => 0,
            'grand_total' => 60000,
            'status' => $status,
        ]);

        OrderDetail::create([
            'order_id' => $order->id,
            'product_id' => Product::firstOrFail()->id,
            'product_name' => 'Produk Uji',
            'price' => 50000,
            'quantity' => 1,
            'subtotal' => 50000,
        ]);

        return $order;
    }

    /**
     * Order + payment pending (untuk alur verifikasi pembayaran).
     */
    private function createPendingPayment(): Payment
    {
        $order = Order::create([
            'order_number' => 'ORD-NTF-PAY-'.uniqid(),
            'user_id' => $this->pelanggan->id,
            'address_id' => $this->address->id,
            'total_amount' => 10000,
            'shipping_cost' => 10000,
            'discount_amount' => 0,
            'grand_total' => 20000,
            'status' => 'pending',
        ]);

        return Payment::create([
            'order_id' => $order->id,
            'payment_number' => 'PAY-NTF-'.uniqid(),
            'method' => 'transfer',
            'amount' => 20000,
            'status' => 'pending',
        ]);
    }

    /* =====================================================================
     | PESANAN BARU
     * ===================================================================*/

    public function test_new_order_dispatches_notifications_to_admin_seller_and_customer(): void
    {
        $this->enableNotifications();
        $this->fakeExternalApis();

        $product = Product::where('stock', '>', 5)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 1]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ])
            ->assertSessionHas('success');

        $order = Order::latest('id')->firstOrFail();

        // Admin menerima pesanan baru di ketiga saluran.
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'order.new',
            'channel' => 'email',
            'recipient' => config('notifications.admin_email'),
            'status' => NotificationLog::STATUS_SENT,
            'order_id' => $order->id,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'order.new',
            'channel' => 'telegram',
            'recipient' => '999888777',
            'status' => NotificationLog::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'order.new',
            'channel' => 'whatsapp',
            'recipient' => '6289697358716',
            'status' => NotificationLog::STATUS_SENT,
        ]);

        // Penjual menerima pesanan baru.
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'order.new',
            'channel' => 'email',
            'recipient' => 'penjual@agungatk.com',
            'status' => NotificationLog::STATUS_SENT,
        ]);

        // Pelanggan menerima konfirmasi & instruksi pembayaran.
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'order.created',
            'channel' => 'email',
            'recipient' => 'pelanggan@agungatk.com',
            'status' => NotificationLog::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'payment.instructions',
            'channel' => 'email',
            'recipient' => 'pelanggan@agungatk.com',
            'status' => NotificationLog::STATUS_SENT,
        ]);

        // API eksternal benar-benar dipanggil untuk saluran eksternal.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages'));
    }

    /* =====================================================================
     | PEMBAYARAN
     * ===================================================================*/

    public function test_payment_verification_notifies_customer_admin_and_seller(): void
    {
        $this->enableNotifications();
        $this->fakeExternalApis();

        $payment = $this->createPendingPayment();

        $this->actingAs($this->admin)
            ->patch('/admin/payments/'.$payment->id.'/verify')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'payment.verified',
            'channel' => 'email',
            'recipient' => 'pelanggan@agungatk.com',
            'status' => NotificationLog::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'payment.verified',
            'channel' => 'email',
            'recipient' => config('notifications.admin_email'),
            'status' => NotificationLog::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'payment.verified',
            'channel' => 'telegram',
            'recipient' => '999888777',
            'status' => NotificationLog::STATUS_SENT,
        ]);

        // Penjual diingatkan pesanan siap disiapkan (transisi pending -> paid).
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'order.paid',
            'channel' => 'email',
            'recipient' => 'penjual@agungatk.com',
            'status' => NotificationLog::STATUS_SENT,
        ]);

        // Tidak ada pengiriman ganda untuk satu kejadian verifikasi.
        $this->assertSame(
            1,
            NotificationLog::where('event_key', 'payment.verified')
                ->where('channel', 'email')
                ->where('recipient', 'pelanggan@agungatk.com')
                ->count()
        );
    }

    public function test_payment_rejection_notifies_customer_with_reason(): void
    {
        $this->enableNotifications(false);

        $payment = $this->createPendingPayment();

        $this->actingAs($this->admin)
            ->patch('/admin/payments/'.$payment->id.'/reject', [
                'rejection_reason' => 'Bukti transfer tidak terbaca dengan jelas.',
            ])
            ->assertSessionHas('success');

        $log = NotificationLog::where('event_key', 'payment.rejected')
            ->where('channel', 'email')
            ->where('recipient', 'pelanggan@agungatk.com')
            ->firstOrFail();

        $this->assertSame(NotificationLog::STATUS_SENT, $log->status);
        $this->assertStringContainsString('Bukti transfer tidak terbaca dengan jelas.', (string) $log->body);
    }

    /* =====================================================================
     | PENGIRIMAN
     * ===================================================================*/

    public function test_courier_status_updates_dispatch_shipment_notifications(): void
    {
        $this->enableNotifications(false);

        $order = $this->createOrderWithStatus('ready_to_ship');
        $kurir = User::where('email', 'kurir@agungatk.com')->firstOrFail();

        // Admin menugaskan kurir -> shipment.assigned (kurir + pelanggan).
        $this->actingAs($this->admin)->post('/admin/shipments', [
            'order_id' => $order->id,
            'courier_id' => $kurir->id,
        ])->assertSessionHas('success');

        $shipment = $order->shipment()->firstOrFail();

        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'shipment.assigned',
            'channel' => 'email',
            'recipient' => 'kurir@agungatk.com',
            'status' => NotificationLog::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'shipment.assigned',
            'channel' => 'email',
            'recipient' => 'pelanggan@agungatk.com',
            'status' => NotificationLog::STATUS_SENT,
        ]);

        // Kurir mengambil barang -> admin & pelanggan menerima pembaruan status.
        $this->actingAs($kurir)
            ->patch('/courier/shipments/'.$shipment->id.'/status', ['status' => 'picked_up'])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'shipment.status',
            'channel' => 'email',
            'recipient' => config('notifications.admin_email'),
            'status' => NotificationLog::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'shipment.status',
            'channel' => 'email',
            'recipient' => 'pelanggan@agungatk.com',
            'status' => NotificationLog::STATUS_SENT,
        ]);

        // Dikirim -> pelanggan diberi tahu pesanan sedang dikirim.
        $this->actingAs($kurir)
            ->patch('/courier/shipments/'.$shipment->id.'/status', ['status' => 'shipping']);

        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'shipment.shipping',
            'channel' => 'email',
            'recipient' => 'pelanggan@agungatk.com',
            'status' => NotificationLog::STATUS_SENT,
        ]);

        // Diterima -> pelanggan & admin diberi tahu pesanan diterima.
        $this->actingAs($kurir)
            ->patch('/courier/shipments/'.$shipment->id.'/status', ['status' => 'delivered']);

        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'shipment.delivered',
            'channel' => 'email',
            'recipient' => 'pelanggan@agungatk.com',
            'status' => NotificationLog::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'shipment.delivered',
            'channel' => 'email',
            'recipient' => config('notifications.admin_email'),
            'status' => NotificationLog::STATUS_SENT,
        ]);
    }

    /* =====================================================================
     | PESANAN DIPROSES (PELANGGAN & PENJUAL)
     * ===================================================================*/

    public function test_order_processing_notifies_customer_and_seller(): void
    {
        $this->enableNotifications(false);

        $order = $this->createOrderWithStatus('paid');

        $this->actingAs(User::where('email', 'penjual@agungatk.com')->firstOrFail())
            ->patch('/seller/orders/'.$order->id.'/processing')
            ->assertSessionHas('success');

        // Pelanggan & penjual sama-sama menerima notifikasi pesanan diproses.
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'order.processing',
            'channel' => 'email',
            'recipient' => 'pelanggan@agungatk.com',
            'status' => NotificationLog::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'order.processing',
            'channel' => 'email',
            'recipient' => 'penjual@agungatk.com',
            'status' => NotificationLog::STATUS_SENT,
        ]);
    }

    /* =====================================================================
     | FORMAT PESAN: WAKTU & TAUTAN DETAIL
     * ===================================================================*/

    public function test_message_contains_timestamp_and_role_detail_link(): void
    {
        $this->enableNotifications(false);

        $product = Product::where('stock', '>', 5)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 1]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ])
            ->assertSessionHas('success');

        $order = Order::latest('id')->firstOrFail();

        // Pesan admin memuat waktu + tautan detail admin.
        $adminLog = NotificationLog::where('event_key', 'order.new')
            ->where('channel', 'email')
            ->where('recipient', config('notifications.admin_email'))
            ->firstOrFail();

        $this->assertStringContainsString('Waktu:', (string) $adminLog->body);
        $this->assertStringContainsString(
            route('admin.orders.show', $order, true),
            (string) $adminLog->body
        );

        // Pesan pelanggan memuat tautan detail pelanggan.
        $customerLog = NotificationLog::where('event_key', 'order.created')
            ->where('channel', 'email')
            ->where('recipient', 'pelanggan@agungatk.com')
            ->firstOrFail();

        $this->assertStringContainsString('Waktu:', (string) $customerLog->body);
        $this->assertStringContainsString(
            route('orders.show', $order, true),
            (string) $customerLog->body
        );
    }

    /* =====================================================================
     | MASALAH SISTEM (KEGAGALAN JOB ANTREAN)
     * ===================================================================*/

    public function test_failed_queue_job_triggers_sanitized_system_issue_alert(): void
    {
        $this->enableNotifications(false);

        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn(SendChannelReplyJob::class);
        $job->shouldReceive('attempts')->andReturn(3);

        // Teks error sengaja memuat pola kredensial — wajib disanitasi
        // sebelum menjadi isi notifikasi admin.
        event(new JobFailed('database', $job, new \RuntimeException(
            'Gagal kirim ke https://api.telegram.org/bot123456:SECRETSECRETVALUE/sendMessage '
            .'access_token=EAAGXXYYZZ (HTTP 500)'
        )));

        $log = NotificationLog::query()
            ->where('event_key', 'system.issue')
            ->where('channel', 'email')
            ->where('recipient', config('notifications.admin_email'))
            ->first();

        $this->assertNotNull($log, 'Kegagalan job harus memberi tahu admin via email.');
        $this->assertSame(NotificationLog::STATUS_SENT, $log->status);
        $this->assertStringContainsString('Masalah Sistem', (string) $log->subject);
        $this->assertStringContainsString('SendChannelReplyJob', (string) $log->body);

        // Kredensial pada teks error tidak pernah bocor ke notifikasi.
        $this->assertStringContainsString('bot<token>', (string) $log->body);
        $this->assertStringContainsString('access_token=<redacted>', (string) $log->body);
        $this->assertStringNotContainsString('SECRETSECRETVALUE', (string) $log->body);
        $this->assertStringNotContainsString('EAAGXXYYZZ', (string) $log->body);
    }

    /* =====================================================================
     | STOK
     * ===================================================================*/

    public function test_stock_notifications_dispatch_when_threshold_reached(): void
    {
        $this->enableNotifications(false);

        $product = Product::where('is_active', true)->firstOrFail();

        // Stok tersentuh batas minimum -> stok.low (admin & anggota toko)
        $product->update(['min_stock' => 5, 'stock' => 5]);

        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'stock.low',
            'channel' => 'email',
            'recipient' => config('notifications.admin_email'),
            'status' => NotificationLog::STATUS_SENT,
        ]);

        // Stok habis -> stock.out (admin saja)
        $product->update(['stock' => 0]);

        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'stock.out',
            'channel' => 'email',
            'recipient' => config('notifications.admin_email'),
            'status' => NotificationLog::STATUS_SENT,
        ]);
    }

    /* =====================================================================
     | ISOLASI SALURAN & PENANGANAN GAGAL
     * ===================================================================*/

    public function test_channel_failure_does_not_affect_other_channels(): void
    {
        $this->enableNotifications();
        $this->fakeExternalApis(500);

        $product = Product::where('is_active', true)->firstOrFail();
        $product->update(['min_stock' => 5, 'stock' => 5]);

        // Saluran Telegram gagal (500)...
        $telegramLog = NotificationLog::where('event_key', 'stock.low')
            ->where('channel', 'telegram')
            ->firstOrFail();
        $this->assertSame(NotificationLog::STATUS_FAILED, $telegramLog->status);
        $this->assertNotEmpty($telegramLog->error);

        // ...tetapi Email & WhatsApp tetap terkirim.
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'stock.low',
            'channel' => 'email',
            'recipient' => config('notifications.admin_email'),
            'status' => NotificationLog::STATUS_SENT,
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'stock.low',
            'channel' => 'whatsapp',
            'recipient' => '6289697358716',
            'status' => NotificationLog::STATUS_SENT,
        ]);
    }

    public function test_failed_notification_does_not_break_checkout(): void
    {
        $this->enableNotifications();
        $this->fakeExternalApis(500);

        $product = Product::where('stock', '>', 5)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 1]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ])
            ->assertSessionHas('success');

        // Pesanan tetap tersimpan normal meski notifikasi Telegram gagal.
        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('pending', $order->status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'order.new',
            'channel' => 'telegram',
            'status' => NotificationLog::STATUS_FAILED,
        ]);
    }

    public function test_unconfigured_external_channels_are_skipped_without_errors(): void
    {
        // Saluran eksternal sengaja TIDAK dikonfigurasi (kondisi default .env testing).
        $this->enableNotifications(false);

        // Percobaan HTTP apa pun akan membuat test gagal -> bukti tidak ada panggilan API.
        Http::preventStrayRequests();

        $product = Product::where('stock', '>', 5)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 1]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ])
            ->assertSessionHas('success');

        $this->assertSame(0, NotificationLog::whereIn('channel', ['telegram', 'whatsapp'])->count());
        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'order.created',
            'channel' => 'email',
            'status' => NotificationLog::STATUS_SENT,
        ]);
    }

    /* =====================================================================
     | SAKLAR PENGATURAN & ANTI DUPLIKAT
     * ===================================================================*/

    public function test_disabled_channel_and_event_suppress_notifications(): void
    {
        $this->enableNotifications(false);

        $product = Product::where('is_active', true)->firstOrFail();

        // Nonaktifkan saluran email -> kejadian stok tidak menghasilkan log apa pun.
        NotificationSetting::set('channel', 'email', false);

        $product->update(['min_stock' => 5, 'stock' => 5]);
        $this->assertSame(0, NotificationLog::where('event_key', 'stock.low')->count());

        // Aktifkan kembali saluran, nonaktifkan kejadian -> tetap tidak ada log.
        NotificationSetting::set('channel', 'email', true);
        NotificationSetting::set('event', 'stock.low', false);

        $product->update(['stock' => 4]);
        $this->assertSame(0, NotificationLog::where('event_key', 'stock.low')->count());
    }

    public function test_same_event_is_not_sent_twice_within_window(): void
    {
        $this->enableNotifications(false);

        $product = Product::where('is_active', true)->firstOrFail();

        $product->update(['min_stock' => 5, 'stock' => 5]);
        $product->update(['stock' => 4]); // masih di bawah batas -> seharusnya TIDAK ganda
        $product->update(['stock' => 3]);

        // Satu kejadian stok.low = satu notifikasi per penerima dalam jendela.
        $this->assertSame(
            1,
            NotificationLog::where('event_key', 'stock.low')
                ->where('channel', 'email')
                ->where('recipient', config('notifications.admin_email'))
                ->count()
        );
        // Penerima anggota toko (audiens seller) juga tepat satu kali —
        // pembatasan jendela berlaku per penerima, bukan global.
        $this->assertSame(
            1,
            NotificationLog::where('event_key', 'stock.low')
                ->where('channel', 'email')
                ->where('recipient', 'penjual@agungatk.com')
                ->count()
        );
    }

    /* =====================================================================
     | PENDAFTARAN & RINGKASAN HARIAN
     * ===================================================================*/

    public function test_registration_dispatches_welcome_notification_to_new_account(): void
    {
        $this->enableNotifications(false);

        $email = 'pendaftar_'.uniqid().'@example.com';

        $this->post('/register', [
            'name' => 'Pendatang Baru',
            'email' => $email,
            'phone' => '08987654321',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect('/dashboard');

        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'account.registered',
            'channel' => 'email',
            'recipient' => $email,
            'status' => NotificationLog::STATUS_SENT,
        ]);
    }

    public function test_daily_summary_command_sends_once_per_day(): void
    {
        $this->enableNotifications(false);

        $this->artisan('notifications:daily-summary')->assertExitCode(0);
        $this->artisan('notifications:daily-summary')->assertExitCode(0);

        $this->assertSame(
            1,
            NotificationLog::where('event_key', 'report.daily')
                ->where('channel', 'email')
                ->where('recipient', config('notifications.admin_email'))
                ->count()
        );
    }

    /* =====================================================================
     | UNGGAH BUKTI PEMBAYARAN
     * ===================================================================*/

    public function test_payment_proof_upload_notifies_admin(): void
    {
        Storage::fake('local');
        $this->enableNotifications(false);

        $payment = $this->createPendingPayment();

        $this->actingAs($this->pelanggan)
            ->post('/payments/'.$payment->id.'/proof', [
                'proof' => UploadedFile::fake()->image('bukti-notifikasi.png', 400, 300),
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('notification_logs', [
            'event_key' => 'payment.proof_uploaded',
            'channel' => 'email',
            'recipient' => config('notifications.admin_email'),
            'status' => NotificationLog::STATUS_SENT,
            'order_id' => $payment->order_id,
        ]);
    }
}
