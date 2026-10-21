<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderCancellationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pembatalan pesanan & pengembalian stok (OrderCancellationService).
 *
 * Kebijakan yang diuji:
 * - Stok DIKURANGI sekali saat checkout, DIKEMBALIKAN sekali saat dibatalkan.
 * - Pembatalan ganda TIDAK mengembalikan stok dua kali.
 * - Admin boleh membatalkan sebelum dikirim; pelanggan hanya sebelum bayar.
 * - Pembatalan orang lain ditolak (404); alasan wajib untuk admin.
 * - Setiap pembatalan tercatat pada audit log.
 */
class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pelanggan;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
        $this->address = Address::where('user_id', $this->pelanggan->id)->firstOrFail();
    }

    /**
     * Checkout nyata lewat HTTP (stok benar-benar dikurangi oleh aplikasi).
     *
     * @return array{0: Order, 1: Product, 2: int} order, produk, stok sebelum checkout
     */
    private function placeOrder(int $quantity): array
    {
        $product = Product::where('stock', '>', $quantity + 5)->firstOrFail();
        $stockBefore = (int) $product->stock;

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => $quantity]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ])
            ->assertSessionHas('success');

        $order = Order::latest('id')->firstOrFail();

        return [$order, Product::findOrFail($product->id), $stockBefore];
    }

    public function test_admin_cancelling_pending_order_restores_stock_and_records_audit(): void
    {
        [$order, $product, $stockBefore] = $this->placeOrder(2);
        $this->assertSame($stockBefore - 2, (int) $product->stock);

        $this->actingAs($this->admin)
            ->patch(route('admin.orders.cancel', $order), ['cancelled_reason' => 'Stok habis di gudang'])
            ->assertSessionHas('success');

        $order->refresh();

        $this->assertSame('cancelled', $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame('Stok habis di gudang', $order->cancelled_reason);
        $this->assertSame($stockBefore, (int) $product->fresh()->stock);
        $this->assertSame('rejected', $order->payment->status);
        $this->assertTrue(
            ActivityLog::where('activity_type', 'order.cancelled')
                ->where('subject_id', $order->id)
                ->where('subject_type', $order->getMorphClass())
                ->where('user_id', $this->admin->id)
                ->where('role', 'admin')
                ->exists()
        );
    }

    public function test_customer_can_cancel_own_pending_order_and_stock_is_restored(): void
    {
        [$order, $product, $stockBefore] = $this->placeOrder(3);

        $this->actingAs($this->pelanggan)
            ->patch(route('orders.cancel', $order))
            ->assertSessionHas('success');

        $order->refresh();

        $this->assertSame('cancelled', $order->status);
        $this->assertSame('Dibatalkan oleh pelanggan sebelum pembayaran.', $order->cancelled_reason);
        $this->assertSame($stockBefore, (int) $product->fresh()->stock);
        $this->assertTrue(
            ActivityLog::where('activity_type', 'order.cancelled')
                ->where('subject_id', $order->id)
                ->where('user_id', $this->pelanggan->id)
                ->where('role', 'pelanggan')
                ->exists()
        );
    }

    public function test_customer_cancel_is_rejected_after_the_order_is_paid(): void
    {
        [$order, $product, $stockBefore] = $this->placeOrder(2);
        $order->update(['status' => 'paid']);

        $this->actingAs($this->pelanggan)
            ->patch(route('orders.cancel', $order))
            ->assertSessionHasErrors('order');

        $order->refresh();

        $this->assertSame('paid', $order->status);
        $this->assertSame($stockBefore - 2, (int) $product->fresh()->stock);
    }

    public function test_cancelling_the_same_order_twice_restores_stock_only_once(): void
    {
        [$order, $product, $stockBefore] = $this->placeOrder(2);

        $this->actingAs($this->admin)
            ->patch(route('admin.orders.cancel', $order), ['cancelled_reason' => 'Pembatalan pertama'])
            ->assertSessionHas('success');

        $this->assertSame($stockBefore, (int) $product->fresh()->stock);

        // Percobaan kedua: status sudah cancelled -> ditolak, stok TIDAK bertambah lagi.
        $this->actingAs($this->admin)
            ->patch(route('admin.orders.cancel', $order), ['cancelled_reason' => 'Pembatalan kedua'])
            ->assertSessionHasErrors('order');

        $this->assertSame($stockBefore, (int) $product->fresh()->stock);
        $this->assertSame('Pembatalan pertama', $order->fresh()->cancelled_reason);
        $this->assertSame(
            1,
            ActivityLog::where('activity_type', 'order.cancelled')
                ->where('subject_id', $order->id)
                ->count()
        );
    }

    public function test_admin_cannot_cancel_order_that_is_already_shipping(): void
    {
        [$order, $product, $stockBefore] = $this->placeOrder(2);
        $order->update(['status' => 'shipping']);

        $this->actingAs($this->admin)
            ->patch(route('admin.orders.cancel', $order), ['cancelled_reason' => 'Terlambat'])
            ->assertSessionHasErrors('order');

        $this->assertSame('shipping', $order->fresh()->status);
        $this->assertSame($stockBefore - 2, (int) $product->fresh()->stock);
    }

    public function test_cancel_returns_404_for_order_owned_by_another_customer(): void
    {
        [$order, $product, $stockBefore] = $this->placeOrder(1);

        $otherCustomer = User::factory()->create([
            'role_id' => Role::where('name', 'pelanggan')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $this->actingAs($otherCustomer)
            ->patch(route('orders.cancel', $order))
            ->assertNotFound();

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame($stockBefore - 1, (int) $product->fresh()->stock);
    }

    public function test_admin_cancel_requires_a_reason_and_leaves_order_untouched(): void
    {
        [$order, $product, $stockBefore] = $this->placeOrder(2);

        $this->actingAs($this->admin)
            ->patch(route('admin.orders.cancel', $order), ['cancelled_reason' => ''])
            ->assertSessionHasErrors(['cancelled_reason' => 'Alasan pembatalan wajib diisi.']);

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame($stockBefore - 2, (int) $product->fresh()->stock);
    }

    /**
     * Servis memeriksa ulang status TERKUNCI terhadap daftar status yang
     * diizinkan pemanggil: pesanan yang statusnya maju sejak pemeriksaan
     * awal (mis. baru terverifikasi) TIDAK dapat dibatalkan & tidak
     * mengembalikan stok (perlindungan TOCTOU).
     */
    public function test_cancel_refuses_when_locked_status_is_outside_the_allowed_list(): void
    {
        [$order, $product, $stockBefore] = $this->placeOrder(2);

        // Majukan status pesanan seolah terverifikasi bersamaan.
        $order->update(['status' => 'paid']);

        $result = app(OrderCancellationService::class)->cancel(
            $order,
            'Tes penolakan daftar status',
            OrderCancellationService::CANCELLABLE_BY_CUSTOMER
        );

        $this->assertFalse($result);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame($stockBefore - 2, (int) $product->fresh()->stock);
    }
}
