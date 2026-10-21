<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CheckoutTest extends TestCase
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

    /* =====================================================================
     | AUTHORIZATION & HALAMAN CHECKOUT
     * ===================================================================*/

    public function test_guest_is_redirected_to_login_from_checkout(): void
    {
        $this->get('/checkout')->assertRedirect('/login');
        $this->post('/checkout', ['address_id' => 1, 'payment_method' => 'transfer'])->assertRedirect('/login');
        $this->post('/addresses', ['label' => 'Rumah'])->assertRedirect('/login');
    }

    public function test_non_customer_roles_are_forbidden_from_checkout(): void
    {
        foreach ([$this->admin, User::where('email', 'penjual@agungatk.com')->firstOrFail()] as $user) {
            $this->actingAs($user)->get('/checkout')->assertStatus(403);
            $this->actingAs($user)->post('/checkout', ['address_id' => $this->address->id, 'payment_method' => 'transfer'])->assertStatus(403);
            $this->actingAs($user)->post('/addresses', ['label' => 'Kantor', 'recipient_name' => 'X', 'phone' => '08', 'full_address' => 'Jl. X', 'city' => 'Kuningan', 'district' => 'Kuningan'])->assertStatus(403);
        }
    }

    public function test_checkout_page_shows_summary_addresses_and_payment_methods(): void
    {
        $product = Product::where('stock', '>', 5)->firstOrFail();

        $response = $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->get('/checkout');

        $response->assertStatus(200)
            ->assertSee('Ringkasan Pesanan')
            ->assertSee('Metode Pembayaran')
            ->assertSee('Alamat Pengiriman')
            ->assertSee($this->address->recipient_name)
            ->assertSee('Transfer Bank')
            ->assertSee('QRIS')
            ->assertSee('COD (Bayar di Tempat)')
            ->assertSee('Ongkir')
            ->assertSee('Diskon');
    }

    public function test_checkout_page_shows_correct_totals(): void
    {
        $product = Product::where('stock', '>', 5)->firstOrFail();
        $quantity = 3;
        $subtotal = $quantity * (float) $product->price;
        $shipping = (float) config('app.shipping_fee');
        $total = $subtotal + $shipping;

        $response = $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => $quantity]])
            ->get('/checkout');

        $response->assertStatus(200)
            ->assertSee(number_format($subtotal, 0, ',', '.'))
            ->assertSee(number_format($shipping, 0, ',', '.'))
            ->assertSee(number_format($total, 0, ',', '.'));
    }

    public function test_checkout_redirects_to_cart_when_cart_is_empty(): void
    {
        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => []])
            ->get('/checkout')
            ->assertRedirect(route('cart.index'));
    }

    /* =====================================================================
     | MEMBUAT PESANAN — BERHASIL
     * ===================================================================*/

    public function test_checkout_creates_order_with_pending_status(): void
    {
        $product = Product::where('stock', '>', 10)->firstOrFail();
        $ordersBefore = Order::count();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ])
            ->assertSessionHas('success');

        $this->assertSame($ordersBefore + 1, Order::count());

        $order = Order::where('user_id', $this->pelanggan->id)->latest('id')->firstOrFail();
        $this->assertSame('pending', $order->status);
        $this->assertSame($this->address->id, (int) $order->address_id);
        $this->assertMatchesRegularExpression('/^ORD-\d{6}-[A-Z0-9]{8}$/', $order->order_number);
    }

    public function test_checkout_stores_order_details(): void
    {
        $product = Product::where('stock', '>', 10)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'qris',
            ]);

        $order = Order::where('user_id', $this->pelanggan->id)->latest('id')->firstOrFail();
        $this->assertCount(1, $order->orderDetails);

        $detail = $order->orderDetails->first();
        $this->assertSame($product->id, (int) $detail->product_id);
        $this->assertSame($product->name, $detail->product_name);
        $this->assertSame(2, $detail->quantity);
        $this->assertEquals((float) $product->price, (float) $detail->price);
        $this->assertEquals(2 * (float) $product->price, (float) $detail->subtotal);
    }

    public function test_checkout_reduces_stock(): void
    {
        $product = Product::where('stock', '>', 10)->firstOrFail();
        $stockBefore = (int) $product->stock;

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 3]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'cod',
            ]);

        $this->assertSame($stockBefore - 3, (int) $product->fresh()->stock);
        $this->assertGreaterThanOrEqual(0, (int) $product->fresh()->stock);
    }

    public function test_checkout_creates_pending_payment(): void
    {
        $product = Product::where('stock', '>', 10)->firstOrFail();
        $paymentsBefore = Payment::count();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'qris',
            ]);

        $this->assertSame($paymentsBefore + 1, Payment::count());

        $order = Order::where('user_id', $this->pelanggan->id)->latest('id')->firstOrFail();
        $payment = $order->payment;

        $this->assertNotNull($payment);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('qris', $payment->method);
        $this->assertMatchesRegularExpression('/^PAY-\d{6}-[A-Z0-9]{8}$/', $payment->payment_number);
        $this->assertEquals((float) $order->grand_total, (float) $payment->amount);
    }

    public function test_checkout_clears_cart_after_success(): void
    {
        $product = Product::where('stock', '>', 10)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ])
            ->assertSessionMissing('cart');
    }

    public function test_checkout_rejects_concurrent_submission_while_first_is_processing(): void
    {
        $product = Product::where('stock', '>', 10)->firstOrFail();
        $ordersBefore = Order::count();

        // Simulasikan proses checkout pertama yang masih berjalan (klik ganda /
        // request paralel dari pengguna yang sama) dengan memegang kunci anti
        // double-submit 'checkout:{user_id}'.
        $lock = Cache::lock('checkout:'.$this->pelanggan->id, 15);
        $this->assertTrue($lock->get());

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ])
            ->assertRedirect(route('checkout.index'))
            ->assertSessionHas('error');

        $this->assertSame($ordersBefore, Order::count());

        // Kunci dilepas -> checkout berikutnya berjalan normal (tidak macet).
        $lock->release();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ])
            ->assertSessionHas('success');

        $this->assertSame($ordersBefore + 1, Order::count());
    }

    public function test_checkout_total_formula_is_correct(): void
    {
        $productA = Product::where('stock', '>', 10)->firstOrFail();
        $productB = Product::where('id', '!=', $productA->id)->where('stock', '>', 5)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$productA->id => 2, $productB->id => 1]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ]);

        $order = Order::where('user_id', $this->pelanggan->id)->latest('id')->firstOrFail();

        // subtotal = SUM(quantity × price)
        $expectedSubtotal = 2 * (float) $productA->price + 1 * (float) $productB->price;
        $this->assertEquals($expectedSubtotal, (float) $order->total_amount);

        // total = subtotal + shipping_fee - discount
        $shipping = (float) config('app.shipping_fee');
        $this->assertEquals($shipping, (float) $order->shipping_cost);
        $this->assertEquals(0.0, (float) $order->discount_amount);
        $this->assertEquals($expectedSubtotal + $shipping - 0, (float) $order->grand_total);

        // Dua baris detail, masing-masing stok berkurang
        $this->assertCount(2, $order->orderDetails);
    }

    public function test_checkout_reduces_stock_for_multiple_items(): void
    {
        $productA = Product::where('stock', '>', 10)->firstOrFail();
        $productB = Product::where('id', '!=', $productA->id)->where('stock', '>', 5)->firstOrFail();
        $stockA = (int) $productA->stock;
        $stockB = (int) $productB->stock;

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$productA->id => 2, $productB->id => 3]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ]);

        $this->assertSame($stockA - 2, (int) $productA->fresh()->stock);
        $this->assertSame($stockB - 3, (int) $productB->fresh()->stock);
    }

    public function test_all_supported_payment_methods_work(): void
    {
        foreach (['transfer', 'qris', 'cod'] as $method) {
            $product = Product::where('stock', '>', 5)->firstOrFail();

            $this->actingAs($this->pelanggan)
                ->withSession(['cart' => [$product->id => 1]])
                ->post('/checkout', [
                    'address_id' => $this->address->id,
                    'payment_method' => $method,
                ])
                ->assertSessionHas('success');

            $order = Order::where('user_id', $this->pelanggan->id)->latest('id')->firstOrFail();
            $this->assertSame($method, $order->payment->method);
        }
    }

    /* =====================================================================
     | VALIDASI & ROLLBACK
     * ===================================================================*/

    public function test_checkout_requires_address(): void
    {
        $product = Product::first();
        $ordersBefore = Order::count();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 1]])
            ->post('/checkout', ['payment_method' => 'transfer'])
            ->assertSessionHasErrors('address_id');

        $this->assertSame($ordersBefore, Order::count());
    }

    public function test_checkout_rejects_address_belonging_to_other_user(): void
    {
        $product = Product::first();
        $foreignAddress = Address::create([
            'user_id' => $this->admin->id,
            'label' => 'Kantor Admin',
            'recipient_name' => 'Admin Toko',
            'phone' => '08111111111',
            'full_address' => 'Jl. Admin No. 1',
            'city' => 'Kab. Kuningan',
            'district' => 'Kuningan',
            'is_primary' => false,
        ]);
        $ordersBefore = Order::count();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 1]])
            ->post('/checkout', [
                'address_id' => $foreignAddress->id,
                'payment_method' => 'transfer',
            ])
            ->assertSessionHasErrors('address_id');

        $this->assertSame($ordersBefore, Order::count());
    }

    public function test_checkout_requires_valid_payment_method(): void
    {
        $product = Product::first();
        $ordersBefore = Order::count();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 1]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'bitcoin',
            ])
            ->assertSessionHasErrors('payment_method');

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 1]])
            ->post('/checkout', ['address_id' => $this->address->id])
            ->assertSessionHasErrors('payment_method');

        $this->assertSame($ordersBefore, Order::count());
    }

    public function test_checkout_with_empty_cart_is_rejected(): void
    {
        $ordersBefore = Order::count();
        $paymentsBefore = Payment::count();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => []])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ])
            ->assertRedirect(route('cart.index'));

        $this->assertSame($ordersBefore, Order::count());
        $this->assertSame($paymentsBefore, Payment::count());
    }

    public function test_checkout_fails_and_rolls_back_when_stock_insufficient(): void
    {
        $product = Product::where('stock', '>', 5)->firstOrFail();
        $product->update(['stock' => 1]);

        $stockBefore = (int) $product->fresh()->stock;
        $ordersBefore = Order::count();
        $paymentsBefore = Payment::count();
        $detailsBefore = OrderDetail::count();

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 5]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ])
            ->assertSessionHasErrors('cart');

        // Transaksi dibatalkan sepenuhnya
        $this->assertSame($ordersBefore, Order::count());
        $this->assertSame($paymentsBefore, Payment::count());
        $this->assertSame($detailsBefore, OrderDetail::count());

        // Stok tidak berubah dan tidak negatif
        $this->assertSame($stockBefore, (int) $product->fresh()->stock);
        $this->assertGreaterThanOrEqual(0, (int) $product->fresh()->stock);

        // Keranjang TIDAK dikosongkan saat gagal
        $this->assertSame([$product->id => 5], session('cart'));
    }

    public function test_checkout_fails_when_product_became_inactive(): void
    {
        $product = Product::where('stock', '>', 5)->firstOrFail();
        $stockBefore = (int) $product->stock;
        $ordersBefore = Order::count();

        // Produk menjadi nonaktif setelah masuk keranjang
        $product->update(['is_active' => false]);

        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ])
            ->assertSessionHasErrors('cart');

        $this->assertSame($ordersBefore, Order::count());
        $this->assertSame($stockBefore, (int) $product->fresh()->stock);
        $this->assertSame([$product->id => 2], session('cart'));
    }

    public function test_stock_never_goes_negative_after_checkout(): void
    {
        $product = Product::where('stock', '>', 5)->firstOrFail();
        $product->update(['stock' => 2]);

        // Checkout valid dengan qty persis stok
        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'cod',
            ]);

        $this->assertSame(0, (int) $product->fresh()->stock);
        $this->assertSame(0, Product::where('stock', '<', 0)->count());
    }

    /* =====================================================================
     | TAMBAH ALAMAT
     * ===================================================================*/

    public function test_customer_can_add_address_from_checkout(): void
    {
        $this->actingAs($this->pelanggan)
            ->post('/addresses', [
                'label' => 'Kos Baru',
                'recipient_name' => 'Budi Santoso',
                'phone' => '0812999888777',
                'full_address' => 'Jl. Diasutami No. 12, Kelurahan Cipicina',
                'city' => 'Kab. Kuningan',
                'district' => 'Cilimus',
                'postal_code' => '45556',
            ])
            ->assertRedirect(route('checkout.index', ['address_id' => Address::latest('id')->first()->id]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('addresses', [
            'user_id' => $this->pelanggan->id,
            'label' => 'Kos Baru',
            'district' => 'Cilimus',
        ]);
    }

    public function test_new_address_of_customer_is_not_primary_when_one_exists(): void
    {
        $this->actingAs($this->pelanggan)
            ->post('/addresses', [
                'label' => 'Alamat Kedua',
                'recipient_name' => 'Budi',
                'phone' => '08123',
                'full_address' => 'Jl. Baru No. 9',
                'city' => 'Kab. Kuningan',
                'district' => 'Cigugur',
            ]);

        $newAddress = Address::where('user_id', $this->pelanggan->id)->latest('id')->first();
        $this->assertFalse((bool) $newAddress->is_primary);
    }

    public function test_first_address_becomes_primary(): void
    {
        $newCustomer = User::create([
            'name' => 'Pelanggan Baru',
            'email' => 'baru@agungatk.com',
            'password' => bcrypt('password-baru-123'),
            'role_id' => Role::where('name', 'pelanggan')->first()->id,
            'is_active' => true,
        ]);

        $this->actingAs($newCustomer)
            ->post('/addresses', [
                'label' => 'Rumah',
                'recipient_name' => 'Pelanggan Baru',
                'phone' => '08555',
                'full_address' => 'Jl. Pertama No. 1',
                'city' => 'Kab. Kuningan',
                'district' => 'Kuningan',
            ]);

        $address = Address::where('user_id', $newCustomer->id)->firstOrFail();
        $this->assertTrue((bool) $address->is_primary);
    }

    public function test_address_requires_required_fields(): void
    {
        $addressesBefore = Address::count();

        $this->actingAs($this->pelanggan)
            ->post('/addresses', [
                'label' => '',
                'recipient_name' => '',
                'phone' => '',
                'full_address' => '',
                'city' => '',
                'district' => '',
            ])
            ->assertSessionHasErrors(['label', 'recipient_name', 'phone', 'full_address', 'city', 'district']);

        $this->assertSame($addressesBefore, Address::count());
    }

    /* =====================================================================
     | ALUR END-TO-END DALAM SATU APLIKASI
     * ===================================================================*/

    public function test_full_flow_from_cart_to_checkout(): void
    {
        $product = Product::where('stock', '>', 10)->firstOrFail();
        $stockBefore = (int) $product->stock;

        // 1. Tambah ke keranjang
        $this->actingAs($this->pelanggan)
            ->post('/cart', ['product_id' => $product->id, 'quantity' => 2])
            ->assertSessionHas('cart', [$product->id => 2]);

        // 2. Buka checkout
        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->get('/checkout')
            ->assertStatus(200);

        // 3. Buat pesanan
        $this->actingAs($this->pelanggan)
            ->withSession(['cart' => [$product->id => 2]])
            ->post('/checkout', [
                'address_id' => $this->address->id,
                'payment_method' => 'transfer',
            ])
            ->assertSessionHas('success')
            ->assertSessionMissing('cart');

        // 4. Verifikasi semua data
        $order = Order::where('user_id', $this->pelanggan->id)->latest('id')->firstOrFail();
        $this->assertSame('pending', $order->status);
        $this->assertSame('pending', $order->payment->status);
        $this->assertCount(1, $order->orderDetails);
        $this->assertSame($stockBefore - 2, (int) $product->fresh()->stock);
    }
}
