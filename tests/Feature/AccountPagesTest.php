<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Audit Final — Halaman akun pelanggan:
 * Riwayat pesanan, detail pesanan, profil, dan kelola alamat.
 */
class AccountPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $pelanggan;

    private User $penjual;

    private User $kurir;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
        $this->penjual = User::where('email', 'penjual@agungatk.com')->firstOrFail();
        $this->kurir = User::where('email', 'kurir@agungatk.com')->firstOrFail();
        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
    }

    /**
     * Pelanggan baru tanpa pesanan & tanpa alamat (untuk skenario netral).
     */
    private function createFreshCustomer(): User
    {
        return User::create([
            'role_id' => Role::where('name', 'pelanggan')->firstOrFail()->id,
            'name' => 'Pelanggan Baru',
            'email' => 'baru@agungatk.com',
            'phone' => '08111111111',
            'password' => Hash::make('rahasia123'),
            'is_active' => true,
        ]);
    }

    /* =====================================================================
     | 1. AUTHENTICATION & AUTHORIZATION
     * ===================================================================*/

    public function test_guest_is_redirected_to_login_from_account_pages(): void
    {
        foreach (['/orders', '/profile', '/addresses'] as $route) {
            $this->get($route)->assertRedirect('/login');
        }
    }

    public function test_non_customer_roles_are_forbidden_from_account_pages(): void
    {
        // /profile kini berlaku untuk SEMUA role (data akun sendiri),
        // tetapi pesanan & alamat tetap khusus pelanggan.
        foreach ([$this->admin, $this->penjual, $this->kurir] as $user) {
            foreach (['/orders', '/addresses'] as $route) {
                $this->actingAs($user)->get($route)->assertStatus(403);
            }
        }
    }

    public function test_inactive_customer_cannot_access_account_pages(): void
    {
        // Middleware akun nonaktif: logout + redirect ke login dengan pesan jelas
        // (bukan 403), agar pengguna tahu akunnya dinonaktifkan admin.
        $this->pelanggan->update(['is_active' => false]);

        foreach (['/orders', '/profile'] as $route) {
            $this->actingAs($this->pelanggan)
                ->get($route)
                ->assertRedirect('/login')
                ->assertSessionHasErrors('email');
        }
    }

    /* =====================================================================
     | 2. RIWAYAT PESANAN (ORDERS INDEX)
     * ===================================================================*/

    public function test_customer_sees_own_orders_with_status_label(): void
    {
        $order = Order::where('user_id', $this->pelanggan->id)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->get('/orders')
            ->assertStatus(200)
            ->assertSee('Pesanan Saya')
            ->assertSee($order->order_number)
            ->assertSee('Selesai'); // label badge status completed
    }

    public function test_customer_does_not_see_other_users_orders(): void
    {
        $other = Order::create([
            'order_number' => 'ORD-LAIN-'.uniqid(),
            'user_id' => $this->penjual->id,
            'address_id' => null,
            'total_amount' => 5000,
            'shipping_cost' => 0,
            'discount_amount' => 0,
            'grand_total' => 5000,
            'status' => 'pending',
        ]);

        $this->actingAs($this->pelanggan)
            ->get('/orders')
            ->assertStatus(200)
            ->assertDontSee($other->order_number);
    }

    public function test_orders_status_filter_works_and_ignores_invalid_status(): void
    {
        $this->actingAs($this->pelanggan)
            ->get('/orders?status=completed')
            ->assertStatus(200)
            ->assertSee(Order::where('user_id', $this->pelanggan->id)->firstOrFail()->order_number);

        $this->actingAs($this->pelanggan)
            ->get('/orders?status=cancelled')
            ->assertStatus(200)
            ->assertSee('Belum ada pesanan dengan status ini');

        // status tidak valid diabaikan → daftar lengkap tetap tampil
        $this->actingAs($this->pelanggan)
            ->get('/orders?status=hax-bukan-status')
            ->assertStatus(200)
            ->assertSee(Order::where('user_id', $this->pelanggan->id)->firstOrFail()->order_number);
    }

    public function test_customer_without_orders_sees_empty_state_with_cta(): void
    {
        $fresh = $this->createFreshCustomer();

        $this->actingAs($fresh)
            ->get('/orders')
            ->assertStatus(200)
            ->assertSee('Anda belum memiliki pesanan')
            ->assertSee('Mulai Belanja');
    }

    /* =====================================================================
     | 3. DETAIL PESANAN (ORDERS SHOW)
     * ===================================================================*/

    public function test_customer_can_view_own_order_detail(): void
    {
        $order = Order::with('orderDetails')->where('user_id', $this->pelanggan->id)->firstOrFail();

        $this->actingAs($this->pelanggan)
            ->get('/orders/'.$order->id)
            ->assertStatus(200)
            ->assertSee($order->order_number)
            ->assertSee($order->orderDetails->first()->product_name)
            ->assertSee(number_format((float) $order->grand_total, 0, ',', '.'))
            ->assertSee('Alamat Pengiriman')
            ->assertSee('Status Pesanan');
    }

    public function test_customer_cannot_view_other_users_order_detail(): void
    {
        $other = Order::create([
            'order_number' => 'ORD-RAHASIA-'.uniqid(),
            'user_id' => $this->penjual->id,
            'address_id' => null,
            'total_amount' => 1000,
            'shipping_cost' => 0,
            'discount_amount' => 0,
            'grand_total' => 1000,
            'status' => 'pending',
        ]);

        $this->actingAs($this->pelanggan)
            ->get('/orders/'.$other->id)
            ->assertStatus(404);
    }

    public function test_order_detail_shows_payment_and_shipment_sections(): void
    {
        $order = Order::with(['payment', 'shipment'])->where('user_id', $this->pelanggan->id)->firstOrFail();

        $response = $this->actingAs($this->pelanggan)
            ->get('/orders/'.$order->id)
            ->assertStatus(200)
            ->assertSee('Pembayaran')
            ->assertSee('Pengiriman');

        if ($order->payment) {
            $response->assertSee($order->payment->payment_number);
        }
        if ($order->shipment) {
            $response->assertSee($order->shipment->tracking_number);
        }
    }

    /* =====================================================================
     | 4. PROFIL
     * ===================================================================*/

    public function test_profile_page_shows_account_data(): void
    {
        $this->actingAs($this->pelanggan)
            ->get('/profile')
            ->assertStatus(200)
            ->assertSee('Profil')
            ->assertSee($this->pelanggan->name)
            ->assertSee($this->pelanggan->email)
            ->assertSee('Ganti Password');
    }

    public function test_customer_can_update_name_and_phone(): void
    {
        $this->actingAs($this->pelanggan)
            ->put('/profile', ['name' => 'Nama Diubah', 'phone' => '08999999999'])
            ->assertRedirect('/profile');

        $this->assertDatabaseHas('users', [
            'id' => $this->pelanggan->id,
            'name' => 'Nama Diubah',
            'phone' => '08999999999',
        ]);
    }

    public function test_profile_update_requires_name(): void
    {
        $this->actingAs($this->pelanggan)
            ->put('/profile', ['name' => '', 'phone' => '08123'])
            ->assertSessionHasErrors('name');
    }

    public function test_password_change_requires_correct_current_password(): void
    {
        $seedPassword = config('app.seed_password');

        $this->actingAs($this->pelanggan)
            ->put('/profile/password', [
                'current_password' => 'salah-banget',
                'password' => 'barusaja123',
                'password_confirmation' => 'barusaja123',
            ])
            ->assertSessionHasErrors('current_password');

        // password lama tetap berlaku
        $this->assertTrue(
            Hash::check($seedPassword, $this->pelanggan->fresh()->password)
        );
    }

    public function test_password_change_succeeds_with_correct_current_password(): void
    {
        $this->actingAs($this->pelanggan)
            ->put('/profile/password', [
                'current_password' => config('app.seed_password'),
                'password' => 'password-baru-123',
                'password_confirmation' => 'password-baru-123',
            ])
            ->assertRedirect('/profile');

        $this->assertTrue(
            Hash::check('password-baru-123', $this->pelanggan->fresh()->password)
        );
    }

    public function test_password_change_validates_min_length_and_confirmation(): void
    {
        $seedPassword = config('app.seed_password');

        $this->actingAs($this->pelanggan)
            ->put('/profile/password', [
                'current_password' => $seedPassword,
                'password' => 'pendek',
                'password_confirmation' => 'pendek',
            ])
            ->assertSessionHasErrors('password');

        $this->actingAs($this->pelanggan)
            ->put('/profile/password', [
                'current_password' => $seedPassword,
                'password' => 'panjang-sekali-123',
                'password_confirmation' => 'tidak-sama',
            ])
            ->assertSessionHasErrors('password');
    }

    /* =====================================================================
     | 5. KELOLA ALAMAT
     * ===================================================================*/

    public function test_addresses_page_shows_saved_addresses(): void
    {
        $this->actingAs($this->pelanggan)
            ->get('/addresses')
            ->assertStatus(200)
            ->assertSee('Alamat Pengiriman')
            ->assertSee($this->pelanggan->addresses()->first()->label);
    }

    public function test_customer_can_add_address_from_addresses_page(): void
    {
        $count = Address::where('user_id', $this->pelanggan->id)->count();

        $this->actingAs($this->pelanggan)
            ->post('/addresses', [
                'back' => 'addresses',
                'label' => 'Kos Baru',
                'recipient_name' => 'Pelanggan',
                'phone' => '081234567890',
                'full_address' => 'Jl. Cijengkol No. 12',
                'city' => 'Kuningan',
                'district' => 'Kuningan',
                'postal_code' => '45511',
                'notes' => '',
            ])
            ->assertRedirect('/addresses');

        $this->assertSame($count + 1, Address::where('user_id', $this->pelanggan->id)->count());
        $this->assertDatabaseHas('addresses', ['user_id' => $this->pelanggan->id, 'label' => 'Kos Baru']);
    }

    public function test_first_address_becomes_primary_automatically(): void
    {
        $fresh = $this->createFreshCustomer();

        $this->actingAs($fresh)
            ->post('/addresses', [
                'label' => 'Pertama',
                'recipient_name' => 'Pelanggan Baru',
                'phone' => '08111111111',
                'full_address' => 'Jl. Pertama No. 1',
                'city' => 'Kuningan',
                'district' => 'Kuningan',
                'postal_code' => '',
                'notes' => '',
            ]);

        $this->assertTrue($fresh->addresses()->first()->is_primary);
    }

    public function test_address_store_validates_required_fields(): void
    {
        $this->actingAs($this->pelanggan)
            ->post('/addresses', ['label' => '', 'recipient_name' => '', 'phone' => ''])
            ->assertSessionHasErrors(['label', 'recipient_name', 'phone', 'full_address', 'city', 'district']);
    }

    public function test_customer_can_edit_address(): void
    {
        $address = $this->pelanggan->addresses()->first();

        $this->actingAs($this->pelanggan)
            ->get('/addresses/'.$address->id.'/edit')
            ->assertStatus(200)
            ->assertSee('Ubah Alamat');

        $this->actingAs($this->pelanggan)
            ->put('/addresses/'.$address->id, [
                'label' => 'Rumah Renovasi',
                'recipient_name' => 'Pelanggan',
                'phone' => '08111111111',
                'full_address' => 'Jl. Baru No. 9',
                'city' => 'Kuningan',
                'district' => 'Cip Manggu',
                'postal_code' => '45511',
                'notes' => 'Rumah cat hijau',
            ])
            ->assertRedirect('/addresses');

        $this->assertDatabaseHas('addresses', ['id' => $address->id, 'label' => 'Rumah Renovasi']);
    }

    public function test_customer_cannot_touch_other_users_address(): void
    {
        $otherAddress = Address::where('user_id', '!=', $this->pelanggan->id)->first();

        if (! $otherAddress) {
            $otherAddress = Address::create([
                'user_id' => $this->penjual->id,
                'label' => 'Toko',
                'recipient_name' => 'Penjual',
                'phone' => '082222222222',
                'full_address' => 'Jl. Toko No. 1',
                'city' => 'Kuningan',
                'district' => 'Kuningan',
                'postal_code' => '45511',
                'is_primary' => true,
                'notes' => '',
            ]);
        }

        $this->actingAs($this->pelanggan)->get('/addresses/'.$otherAddress->id.'/edit')->assertStatus(404);
        $this->actingAs($this->pelanggan)->put('/addresses/'.$otherAddress->id, [
            'label' => 'Dicolong', 'recipient_name' => 'X', 'phone' => '08',
            'full_address' => 'Y', 'city' => 'Z', 'district' => 'Z',
        ])->assertStatus(404);
        $this->actingAs($this->pelanggan)->patch('/addresses/'.$otherAddress->id.'/primary')->assertStatus(404);
        $this->actingAs($this->pelanggan)->delete('/addresses/'.$otherAddress->id)->assertStatus(404);
    }

    public function test_set_primary_switches_primary_flag(): void
    {
        $addresses = $this->pelanggan->addresses()->get();
        $this->assertGreaterThanOrEqual(2, $addresses->count());

        $target = $addresses->firstWhere('is_primary', false);
        if (! $target) {
            $this->markTestSkipped('Tidak ada alamat non-primary untuk diuji.');
        }

        $this->actingAs($this->pelanggan)
            ->patch('/addresses/'.$target->id.'/primary')
            ->assertRedirect();

        $this->assertTrue($target->fresh()->is_primary);
        $this->assertSame(
            1,
            $this->pelanggan->addresses()->where('is_primary', true)->count()
        );
    }

    public function test_address_used_by_order_cannot_be_deleted(): void
    {
        $order = Order::where('user_id', $this->pelanggan->id)->firstOrFail();
        $usedAddress = Address::findOrFail($order->address_id);

        $this->actingAs($this->pelanggan)
            ->delete('/addresses/'.$usedAddress->id)
            ->assertSessionHas('error');

        $this->assertDatabaseHas('addresses', ['id' => $usedAddress->id]);
    }

    public function test_unused_address_can_be_deleted_and_primary_promotes_next(): void
    {
        $fresh = $this->createFreshCustomer();

        foreach ([['Pertama', true], ['Kedua', false]] as [$label, $primary]) {
            Address::create([
                'user_id' => $fresh->id,
                'label' => $label,
                'recipient_name' => 'Pelanggan Baru',
                'phone' => '08111111111',
                'full_address' => 'Jl. '.$label,
                'city' => 'Kuningan',
                'district' => 'Kuningan',
                'postal_code' => '',
                'is_primary' => $primary,
                'notes' => '',
            ]);
        }

        $first = $fresh->addresses()->where('label', 'Pertama')->firstOrFail();
        $second = $fresh->addresses()->where('label', 'Kedua')->firstOrFail();

        $this->actingAs($fresh)
            ->delete('/addresses/'.$first->id)
            ->assertRedirect('/addresses');

        $this->assertDatabaseMissing('addresses', ['id' => $first->id]);
        $this->assertTrue($second->fresh()->is_primary);
    }

    /* =====================================================================
     | 6. TAUTAN SILANG (tidak ada broken link antar-halaman akun)
     * ===================================================================*/

    public function test_dashboard_links_to_new_account_pages(): void
    {
        $response = $this->actingAs($this->pelanggan)->get('/dashboard')->assertStatus(200);

        $response->assertSee(route('orders.index'), false);
        $response->assertSee(route('addresses.index'), false);
        $response->assertSee(route('profile.index'), false);
    }

    public function test_order_detail_links_to_payment_page_when_payment_exists(): void
    {
        $order = Order::with('payment')->where('user_id', $this->pelanggan->id)->firstOrFail();

        if (! $order->payment) {
            $this->markTestSkipped('Pesanan seed tidak memiliki pembayaran.');
        }

        $this->actingAs($this->pelanggan)
            ->get('/orders/'.$order->id)
            ->assertSee(route('payments.show', $order->payment), false);
    }

    public function test_all_new_account_pages_render_without_errors(): void
    {
        $order = Order::where('user_id', $this->pelanggan->id)->firstOrFail();
        $address = $this->pelanggan->addresses()->firstOrFail();

        foreach ([
            '/orders',
            '/orders/'.$order->id,
            '/profile',
            '/addresses',
            '/addresses/'.$address->id.'/edit',
        ] as $route) {
            $this->actingAs($this->pelanggan)
                ->get($route)
                ->assertStatus(200)
                ->assertDontSee('Whoops')
                ->assertDontSee('Undefined variable');
        }
    }

    public function test_product_dependency_still_resolves_on_detail_page(): void
    {
        // memastikan perubahan eager-load orderDetails.product tidak merusak apa pun
        $product = Product::firstOrFail();

        $this->get('/products/'.$product->slug)->assertStatus(200);
    }
}
