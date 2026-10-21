<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jejak audit untuk aktivitas bisnis penting (activity_logs):
 * checkout, verifikasi/penolakan pembayaran, penugasan kurir, status kurir,
 * penyelesaian pesanan, transisi anggota, dan penyesuaian stok admin.
 *
 * Setiap entri wajib menyimpan pelaku (user_id), peran saat itu (role),
 * jenis aktivitas, objek terkait, dan ringkasan aman.
 */
class BusinessAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $anggota;

    private User $kurir;

    private User $pelanggan;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->anggota = User::where('email', 'penjual@agungatk.com')->firstOrFail();
        $this->kurir = User::where('email', 'kurir@agungatk.com')->firstOrFail();
        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
        $this->address = Address::where('user_id', $this->pelanggan->id)->firstOrFail();
    }

    /**
     * Pesanan buatan langsung (tanpa dampak stok) + pembayaran pending.
     *
     * @param  array<string, mixed>  $paymentOverrides
     */
    private function createOrder(string $status, array $paymentOverrides = []): Order
    {
        $order = Order::create([
            'order_number' => 'ORD-AUD-'.uniqid(),
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
            'product_name' => 'Produk Uji Audit',
            'price' => 50000,
            'quantity' => 1,
            'subtotal' => 50000,
        ]);

        Payment::create(array_merge([
            'order_id' => $order->id,
            'payment_number' => 'PAY-AUD-'.uniqid(),
            'method' => 'transfer',
            'amount' => 60000,
            'status' => 'pending',
        ], $paymentOverrides));

        return $order;
    }

    /**
     * Cari entri audit milik pelaku tertentu untuk objek terkait.
     */
    private function findLog(string $activityType, User $actor, int $subjectId): ActivityLog
    {
        return ActivityLog::where('activity_type', $activityType)
            ->where('user_id', $actor->id)
            ->where('subject_id', $subjectId)
            ->firstOrFail();
    }

    public function test_checkout_records_order_created_activity(): void
    {
        $product = Product::where('stock', '>', 10)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'qris',
            ])
            ->assertSessionHas('success');

        $order = Order::latest('id')->firstOrFail();
        $log = $this->findLog('order.created', $this->pelanggan, $order->id);

        $this->assertSame('pelanggan', $log->role);
        $this->assertStringContainsString($order->order_number, (string) $log->summary);
        $this->assertStringNotContainsString('password', strtolower((string) $log->summary));
    }

    public function test_payment_verification_records_activity_by_admin(): void
    {
        $order = $this->createOrder('pending');
        $payment = $order->payment;

        $this->actingAs($this->admin)
            ->patch(route('admin.payments.verify', $payment))
            ->assertSessionHas('success');

        $this->assertSame('verified', $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->status);

        $log = $this->findLog('payment.verified', $this->admin, $payment->id);

        $this->assertSame('admin', $log->role);
        $this->assertStringContainsString($payment->payment_number, (string) $log->summary);
    }

    public function test_payment_rejection_records_activity_with_the_reason(): void
    {
        $order = $this->createOrder('pending');
        $payment = $order->payment;

        $this->actingAs($this->admin)
            ->patch(route('admin.payments.reject', $payment), [
                'rejection_reason' => 'Bukti transfer tidak terbaca',
            ])
            ->assertSessionHas('success');

        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);

        $log = $this->findLog('payment.rejected', $this->admin, $payment->id);

        $this->assertStringContainsString('Bukti transfer tidak terbaca', (string) $log->summary);
    }

    public function test_courier_assignment_records_activity(): void
    {
        $order = $this->createOrder('ready_to_ship');

        $this->actingAs($this->admin)
            ->post(route('admin.shipments.store'), [
                'order_id' => $order->id,
                'courier_id' => $this->kurir->id,
            ])
            ->assertSessionHas('success');

        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();

        $log = $this->findLog('shipment.assigned', $this->admin, $order->id);

        $this->assertSame('admin', $log->role);
        $this->assertStringContainsString($this->kurir->name, (string) $log->summary);
        $this->assertSame($this->kurir->id, (int) $shipment->courier_id);
    }

    public function test_courier_status_update_records_activity(): void
    {
        $order = $this->createOrder('ready_to_ship');
        $shipment = Shipment::create([
            'order_id' => $order->id,
            'courier_id' => $this->kurir->id,
            'tracking_number' => 'SHIP-AUD-'.uniqid(),
            'courier_name' => $this->kurir->name,
            'status' => 'waiting',
            'recipient_name' => $this->pelanggan->name,
        ]);

        $this->actingAs($this->kurir)
            ->patch(route('courier.shipments.status', $shipment), ['status' => 'picked_up'])
            ->assertSessionHas('success');

        $this->assertSame('picked_up', $shipment->fresh()->status);

        $log = $this->findLog('shipment.status_updated', $this->kurir, $shipment->id);

        $this->assertSame('kurir', $log->role);
        $this->assertStringContainsString('PICKED_UP', (string) $log->summary);
    }

    public function test_order_completion_records_activity(): void
    {
        $order = $this->createOrder('delivered');
        $shipment = Shipment::create([
            'order_id' => $order->id,
            'courier_id' => $this->kurir->id,
            'tracking_number' => 'SHIP-AUD-'.uniqid(),
            'courier_name' => $this->kurir->name,
            'status' => 'delivered',
            'recipient_name' => $this->pelanggan->name,
            'delivered_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->patch(route('admin.shipments.complete', $shipment))
            ->assertSessionHas('success');

        $order->refresh();

        $this->assertSame('completed', $order->status);
        $this->assertNotNull($order->completed_at);
        $this->findLog('order.completed', $this->admin, $order->id);
    }

    public function test_anggota_order_transition_records_activity(): void
    {
        $order = $this->createOrder('paid');

        $this->actingAs($this->anggota)
            ->patch(route('anggota.orders.processing', $order))
            ->assertSessionHas('success');

        $this->assertSame('processing', $order->fresh()->status);

        $log = $this->findLog('order.processing', $this->anggota, $order->id);

        $this->assertSame('anggota', $log->role);
    }

    public function test_admin_stock_adjustment_records_activity_with_old_and_new_value(): void
    {
        $product = Product::firstOrFail();
        $oldStock = (int) $product->stock;
        $newStock = $oldStock + 5;

        $this->actingAs($this->admin)
            ->patch(route('admin.products.stock', $product), ['stock' => $newStock])
            ->assertSessionHas('success');

        $this->assertSame($newStock, (int) $product->fresh()->stock);

        $log = $this->findLog('stock.adjusted', $this->admin, $product->id);

        $this->assertSame('admin', $log->role);
        $this->assertStringContainsString($oldStock.' → '.$newStock, (string) $log->summary);
    }
}
