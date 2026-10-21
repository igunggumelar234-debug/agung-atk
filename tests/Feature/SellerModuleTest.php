<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $penjual;

    private User $admin;

    private User $pelanggan;

    private User $kurir;

    private Address $address;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->penjual = User::where('email', 'penjual@agungatk.com')->firstOrFail();
        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
        $this->kurir = User::where('email', 'kurir@agungatk.com')->firstOrFail();
        $this->address = Address::where('user_id', $this->pelanggan->id)->firstOrFail();
        $this->product = Product::firstOrFail();
    }

    /**
     * Buat order + 1 detail + payment untuk pengujian.
     */
    private function createOrder(string $status, array $orderOverrides = [], array $paymentOverrides = []): Order
    {
        $order = Order::create(array_merge([
            'order_number' => 'ORD-SELL-'.uniqid(),
            'user_id' => $this->pelanggan->id,
            'address_id' => $this->address->id,
            'total_amount' => 50000,
            'shipping_cost' => 10000,
            'discount_amount' => 0,
            'grand_total' => 60000,
            'status' => $status,
        ], $orderOverrides));

        OrderDetail::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'price' => 50000,
            'quantity' => 1,
            'subtotal' => 50000,
        ]);

        Payment::create(array_merge([
            'order_id' => $order->id,
            'payment_number' => 'PAY-SELL-'.uniqid(),
            'method' => 'transfer',
            'amount' => 60000,
            'status' => 'verified',
        ], $paymentOverrides));

        return $order;
    }

    /* =====================================================================
     | AUTENTIKASI & AUTHORIZATION
     * ===================================================================*/

    public function test_guest_is_redirected_to_login(): void
    {
        $order = $this->createOrder('paid');

        $this->get('/seller/orders')->assertRedirect('/login');
        $this->get('/seller/orders/'.$order->id)->assertRedirect('/login');
        $this->patch('/seller/orders/'.$order->id.'/processing')->assertRedirect('/login');
        $this->patch('/seller/orders/'.$order->id.'/ready-to-ship')->assertRedirect('/login');
    }

    public function test_non_seller_roles_are_forbidden_from_seller_module(): void
    {
        $order = $this->createOrder('paid');

        foreach ([$this->admin, $this->pelanggan, $this->kurir] as $user) {
            $this->actingAs($user)->get('/seller/orders')->assertStatus(403);
            $this->actingAs($user)->get('/seller/orders/'.$order->id)->assertStatus(403);
            $this->actingAs($user)->patch('/seller/orders/'.$order->id.'/processing')->assertStatus(403);
            $this->actingAs($user)->patch('/seller/orders/'.$order->id.'/ready-to-ship')->assertStatus(403);
        }
    }

    public function test_seller_cannot_access_admin_dashboard(): void
    {
        $this->actingAs($this->penjual)->get('/admin/dashboard')->assertStatus(403);
        $this->actingAs($this->penjual)->get('/admin/payments')->assertStatus(403);
    }

    public function test_seller_cannot_edit_or_delete_products(): void
    {
        $this->actingAs($this->penjual)->get('/admin/products')->assertStatus(403);
        $this->actingAs($this->penjual)->get('/admin/products/'.$this->product->id.'/edit')->assertStatus(403);
        $this->actingAs($this->penjual)->delete('/admin/products/'.$this->product->id)->assertStatus(403);
        $this->actingAs($this->penjual)->put('/admin/products/'.$this->product->id, [
            'name' => 'Harga Dimanipulasi',
            'category_id' => $this->product->category_id,
            'price' => 1,
            'stock' => 1,
            'description' => 'x',
            'is_active' => 1,
        ])->assertStatus(403);
    }

    public function test_seller_cannot_modify_payments(): void
    {
        $order = $this->createOrder('paid');
        $payment = $order->payment;

        $this->actingAs($this->penjual)
            ->patch('/admin/payments/'.$payment->id.'/verify')
            ->assertStatus(403);

        $this->actingAs($this->penjual)
            ->patch('/admin/payments/'.$payment->id.'/reject', ['rejection_reason' => 'x'])
            ->assertStatus(403);

        $this->assertSame('verified', $payment->fresh()->status);
    }

    /* =====================================================================
     | SELLER DASHBOARD
     * ===================================================================*/

    public function test_seller_can_view_dashboard(): void
    {
        $this->createOrder('paid');
        $this->createOrder('processing');

        $this->actingAs($this->penjual)
            ->get('/seller/dashboard')
            ->assertStatus(200)
            ->assertSee('Pesanan Masuk (Perlu Diproses)')
            ->assertSee('Siap Diambil Kurir')
            ->assertSee('Kelola Semua Pesanan');
    }

    /* =====================================================================
     | ORDER LIST & FILTER
     * ===================================================================*/

    public function test_order_list_only_shows_paid_orders_and_beyond(): void
    {
        $paid = $this->createOrder('paid');
        $processing = $this->createOrder('processing');
        $ready = $this->createOrder('ready_to_ship');
        $completed = $this->createOrder('completed');
        $pending = $this->createOrder('pending');
        $cancelled = $this->createOrder('cancelled');

        $response = $this->actingAs($this->penjual)->get('/seller/orders')->assertStatus(200);

        $response->assertSee($paid->order_number);
        $response->assertSee($processing->order_number);
        $response->assertSee($ready->order_number);
        $response->assertSee($completed->order_number);

        // Pending & cancelled TIDAK boleh tampil (belum dibayar / batal)
        $response->assertDontSee($pending->order_number);
        $response->assertDontSee($cancelled->order_number);
    }

    public function test_order_list_can_filter_by_status(): void
    {
        $paid = $this->createOrder('paid');
        $processing = $this->createOrder('processing');

        $response = $this->actingAs($this->penjual)
            ->get('/seller/orders?status=processing')
            ->assertStatus(200);

        $response->assertSee($processing->order_number);
        $response->assertDontSee($paid->order_number);

        // Link filter status tersedia
        $response->assertSee('status=paid');
        $response->assertSee('status=ready_to_ship');
    }

    public function test_invalid_status_filter_is_ignored(): void
    {
        $paid = $this->createOrder('paid');

        $this->actingAs($this->penjual)
            ->get('/seller/orders?status=pending')
            ->assertStatus(200)
            ->assertSee($paid->order_number);
    }

    public function test_order_list_is_paginated(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->createOrder('paid');
        }

        $page1 = $this->actingAs($this->penjual)->get('/seller/orders')->assertStatus(200);
        $this->assertStringContainsString('page=2', $page1->getContent());
    }

    /* =====================================================================
     | ORDER DETAIL
     * ===================================================================*/

    public function test_seller_can_view_paid_order_detail(): void
    {
        $order = $this->createOrder('paid');
        $payment = $order->payment;

        $this->actingAs($this->penjual)
            ->get('/seller/orders/'.$order->id)
            ->assertStatus(200)
            ->assertSee($order->order_number)
            ->assertSee($this->pelanggan->name)
            ->assertSee($this->product->name)
            ->assertSee($payment->payment_number)
            ->assertSee('Rincian Item')
            ->assertSee('Proses Pesanan')
            ->assertSee('Informasi pembayaran hanya dapat dilihat', false);
    }

    public function test_detail_shows_ready_to_ship_action_for_processing_order(): void
    {
        $order = $this->createOrder('processing');

        $this->actingAs($this->penjual)
            ->get('/seller/orders/'.$order->id)
            ->assertStatus(200)
            ->assertSee('Tandai Siap Kirim')
            ->assertDontSee('Proses Pesanan (→ Processing)');
    }

    public function test_detail_has_no_action_for_ready_to_ship_order(): void
    {
        $order = $this->createOrder('ready_to_ship');

        $this->actingAs($this->penjual)
            ->get('/seller/orders/'.$order->id)
            ->assertStatus(200)
            ->assertSee('siap diambil kurir')
            ->assertDontSee('Proses Pesanan')
            ->assertDontSee('Tandai Siap Kirim');
    }

    public function test_pending_and_cancelled_order_detail_is_not_found(): void
    {
        $pending = $this->createOrder('pending');
        $cancelled = $this->createOrder('cancelled');

        $this->actingAs($this->penjual)->get('/seller/orders/'.$pending->id)->assertStatus(404);
        $this->actingAs($this->penjual)->get('/seller/orders/'.$cancelled->id)->assertStatus(404);
    }

    /* =====================================================================
     | STATUS FLOW: paid → processing → ready_to_ship
     * ===================================================================*/

    public function test_seller_can_process_paid_order_to_processing(): void
    {
        $order = $this->createOrder('paid');

        $this->actingAs($this->penjual)
            ->patch('/seller/orders/'.$order->id.'/processing')
            ->assertSessionHas('success');

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_seller_can_mark_processing_order_ready_to_ship(): void
    {
        $order = $this->createOrder('processing');

        $this->actingAs($this->penjual)
            ->patch('/seller/orders/'.$order->id.'/ready-to-ship')
            ->assertSessionHas('success');

        $this->assertSame('ready_to_ship', $order->fresh()->status);
    }

    public function test_full_flow_paid_to_ready_to_ship(): void
    {
        $order = $this->createOrder('paid');

        $this->actingAs($this->penjual)->patch('/seller/orders/'.$order->id.'/processing');
        $this->assertSame('processing', $order->fresh()->status);

        $this->actingAs($this->penjual)->patch('/seller/orders/'.$order->id.'/ready-to-ship');
        $this->assertSame('ready_to_ship', $order->fresh()->status);
    }

    public function test_processing_requires_paid_status(): void
    {
        foreach (['processing', 'ready_to_ship', 'shipping', 'completed'] as $status) {
            $order = $this->createOrder($status);

            $this->actingAs($this->penjual)
                ->patch('/seller/orders/'.$order->id.'/processing')
                ->assertSessionHasErrors('order');

            $this->assertSame($status, $order->fresh()->status, "Status {$status} seharusnya tidak berubah.");
        }
    }

    public function test_ready_to_ship_requires_processing_status(): void
    {
        // Tidak boleh lompat dari paid langsung ke ready_to_ship
        $paid = $this->createOrder('paid');
        $this->actingAs($this->penjual)
            ->patch('/seller/orders/'.$paid->id.'/ready-to-ship')
            ->assertSessionHasErrors('order');
        $this->assertSame('paid', $paid->fresh()->status);

        // Tidak boleh mundur dari ready_to_ship ke processing
        $ready = $this->createOrder('ready_to_ship');
        $this->actingAs($this->penjual)
            ->patch('/seller/orders/'.$ready->id.'/processing')
            ->assertSessionHasErrors('order');
        $this->assertSame('ready_to_ship', $ready->fresh()->status);
    }

    public function test_pending_and_cancelled_order_actions_are_not_found(): void
    {
        $pending = $this->createOrder('pending');
        $cancelled = $this->createOrder('cancelled');

        $this->actingAs($this->penjual)->patch('/seller/orders/'.$pending->id.'/processing')->assertStatus(404);
        $this->actingAs($this->penjual)->patch('/seller/orders/'.$pending->id.'/ready-to-ship')->assertStatus(404);
        $this->actingAs($this->penjual)->patch('/seller/orders/'.$cancelled->id.'/processing')->assertStatus(404);

        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    /* =====================================================================
     | PROTEKSI DATA LAIN
     * ===================================================================*/

    public function test_status_change_does_not_touch_payment_or_product(): void
    {
        $order = $this->createOrder('paid');
        $priceBefore = $this->product->fresh()->price;
        $stockBefore = $this->product->fresh()->stock;

        $this->actingAs($this->penjual)->patch('/seller/orders/'.$order->id.'/processing');

        $this->assertSame('verified', $order->payment->fresh()->status);
        $this->assertEquals($priceBefore, $this->product->fresh()->price);
        $this->assertSame($stockBefore, $this->product->fresh()->stock);
    }
}
