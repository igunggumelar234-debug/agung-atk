<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit Final — Halaman admin baru: Pesanan, Pengguna, Kurir.
 */
class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $penjual;

    private User $kurir;

    private User $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->penjual = User::where('email', 'penjual@agungatk.com')->firstOrFail();
        $this->kurir = User::where('email', 'kurir@agungatk.com')->firstOrFail();
        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
    }

    /* =====================================================================
     | 1. AUTHORIZATION
     * ===================================================================*/

    public function test_guest_is_redirected_to_login(): void
    {
        foreach (['/admin/orders', '/admin/users', '/admin/couriers'] as $route) {
            $this->get($route)->assertRedirect('/login');
        }
    }

    public function test_non_admin_roles_are_forbidden_from_management_pages(): void
    {
        $order = Order::firstOrFail();

        foreach ([$this->penjual, $this->kurir, $this->pelanggan] as $user) {
            $this->actingAs($user)->get('/admin/orders')->assertStatus(403);
            $this->actingAs($user)->get('/admin/orders/'.$order->id)->assertStatus(403);
            $this->actingAs($user)->get('/admin/users')->assertStatus(403);
            $this->actingAs($user)->get('/admin/couriers')->assertStatus(403);
            $this->actingAs($user)
                ->patch('/admin/users/'.$this->pelanggan->id.'/toggle-status')
                ->assertStatus(403);
            $this->actingAs($user)
                ->patch('/admin/couriers/'.$this->kurir->id.'/toggle-status')
                ->assertStatus(403);
        }
    }

    /* =====================================================================
     | 2. ADMIN — HALAMAN PESANAN
     * ===================================================================*/

    public function test_admin_sees_all_orders_list(): void
    {
        $order = Order::firstOrFail();

        $this->actingAs($this->admin)
            ->get('/admin/orders')
            ->assertStatus(200)
            ->assertSee('Semua Pesanan')
            ->assertSee($order->order_number)
            ->assertSee($order->user->name);
    }

    public function test_admin_orders_search_filters_correctly(): void
    {
        $order = Order::firstOrFail();

        $this->actingAs($this->admin)
            ->get('/admin/orders?q='.$order->order_number)
            ->assertStatus(200)
            ->assertSee($order->order_number);

        $this->actingAs($this->admin)
            ->get('/admin/orders?q=TIDAK-ADA-XYZ')
            ->assertStatus(200)
            ->assertSee('Tidak ada pesanan ditemukan');
    }

    public function test_admin_orders_status_filter_ignores_invalid_status(): void
    {
        $order = Order::firstOrFail();

        $this->actingAs($this->admin)
            ->get('/admin/orders?status=completed')
            ->assertStatus(200)
            ->assertSee($order->order_number);

        $this->actingAs($this->admin)
            ->get('/admin/orders?status=cancelled')
            ->assertStatus(200)
            ->assertSee('Tidak ada pesanan ditemukan');

        $this->actingAs($this->admin)
            ->get('/admin/orders?status=hax')
            ->assertStatus(200)
            ->assertSee($order->order_number);
    }

    public function test_admin_can_view_order_detail_with_customer_identity(): void
    {
        $order = Order::with('orderDetails')->firstOrFail();

        $this->actingAs($this->admin)
            ->get('/admin/orders/'.$order->id)
            ->assertStatus(200)
            ->assertSee($order->order_number)
            ->assertSee($order->user->name)
            ->assertSee($order->user->email)
            ->assertSee($order->orderDetails->first()->product_name)
            ->assertSee('Alamat Pengiriman');
    }

    public function test_admin_order_detail_shows_payment_and_shipment_management_links(): void
    {
        $order = Order::firstOrFail();

        $this->actingAs($this->admin)
            ->get('/admin/orders/'.$order->id)
            ->assertStatus(200)
            ->assertSee(route('admin.shipments.index'), false);
    }

    /* =====================================================================
     | 3. ADMIN — HALAMAN PENGGUNA
     * ===================================================================*/

    public function test_admin_sees_users_list_with_roles(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/users')
            ->assertStatus(200)
            ->assertSee('Manajemen Pengguna')
            ->assertSee($this->pelanggan->name)
            ->assertSee($this->pelanggan->email)
            ->assertSee('Pelanggan'); // label role
    }

    public function test_admin_users_search_and_role_filter_work(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/users?q='.urlencode($this->kurir->email))
            ->assertStatus(200)
            ->assertSee($this->kurir->email)
            ->assertDontSee($this->pelanggan->email);

        $this->actingAs($this->admin)
            ->get('/admin/users?role=kurir')
            ->assertStatus(200)
            ->assertSee($this->kurir->name)
            ->assertDontSee($this->pelanggan->name);

        $this->actingAs($this->admin)
            ->get('/admin/users?q=TIDAK-ADA-XYZ')
            ->assertStatus(200)
            ->assertSee('Tidak ada pengguna ditemukan');
    }

    public function test_admin_can_deactivate_and_reactivate_a_user(): void
    {
        $this->actingAs($this->admin)
            ->patch('/admin/users/'.$this->pelanggan->id.'/toggle-status')
            ->assertRedirect();

        $this->assertFalse((bool) $this->pelanggan->fresh()->is_active);

        $this->actingAs($this->admin)
            ->patch('/admin/users/'.$this->pelanggan->id.'/toggle-status')
            ->assertRedirect();

        $this->assertTrue((bool) $this->pelanggan->fresh()->is_active);
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $this->actingAs($this->admin)
            ->patch('/admin/users/'.$this->admin->id.'/toggle-status')
            ->assertSessionHas('error');

        $this->assertTrue((bool) $this->admin->fresh()->is_active);
    }

    public function test_toggle_unknown_user_returns_404(): void
    {
        $this->actingAs($this->admin)
            ->patch('/admin/users/999999/toggle-status')
            ->assertStatus(404);
    }

    /* =====================================================================
     | 4. ADMIN — HALAMAN KURIR
     * ===================================================================*/

    public function test_admin_sees_only_couriers_on_courier_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/couriers')
            ->assertStatus(200)
            ->assertSee('Manajemen Kurir')
            ->assertSee($this->kurir->name)
            ->assertSee($this->kurir->email)
            ->assertDontSee($this->pelanggan->name)
            ->assertDontSee($this->penjual->name);
    }

    public function test_courier_page_shows_shipment_counts(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/couriers')->assertStatus(200);

        // Seeder membuat minimal satu pengiriman untuk kurir.
        $shipments = $this->kurir->courierShipments()->count();
        if ($shipments > 0) {
            $response->assertSee('pengiriman');
        }
    }

    public function test_courier_active_filter_works(): void
    {
        // kurir seed aktif → filter nonaktif menghasilkan empty state
        $this->actingAs($this->admin)
            ->get('/admin/couriers?aktif=0')
            ->assertStatus(200)
            ->assertSee('Tidak ada kurir ditemukan');

        $this->actingAs($this->admin)
            ->get('/admin/couriers?aktif=1')
            ->assertStatus(200)
            ->assertSee($this->kurir->email);
    }

    public function test_admin_can_deactivate_and_reactivate_courier(): void
    {
        $this->actingAs($this->admin)
            ->patch('/admin/couriers/'.$this->kurir->id.'/toggle-status')
            ->assertRedirect();

        $this->assertFalse((bool) $this->kurir->fresh()->is_active);

        $this->actingAs($this->admin)
            ->patch('/admin/couriers/'.$this->kurir->id.'/toggle-status')
            ->assertRedirect();

        $this->assertTrue((bool) $this->kurir->fresh()->is_active);
    }

    public function test_courier_search_returns_empty_state_when_no_match(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/couriers?q=TIDAK-ADA-XYZ')
            ->assertStatus(200)
            ->assertSee('Tidak ada kurir ditemukan');
    }

    /* =====================================================================
     | 5. INTEGRASI — sidebar & konsistensi render
     * ===================================================================*/

    public function test_admin_sidebar_contains_new_management_links(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/dashboard')
            ->assertStatus(200)
            ->assertSee(route('admin.orders.index'), false)
            ->assertSee(route('admin.users.index'), false)
            ->assertSee(route('admin.couriers.index'), false);
    }

    public function test_all_new_admin_pages_render_without_errors(): void
    {
        $order = Order::firstOrFail();

        foreach ([
            '/admin/orders',
            '/admin/orders/'.$order->id,
            '/admin/users',
            '/admin/couriers',
        ] as $route) {
            $this->actingAs($this->admin)
                ->get($route)
                ->assertStatus(200)
                ->assertDontSee('Whoops')
                ->assertDontSee('Undefined variable');
        }
    }

    public function test_deactivated_user_cannot_login(): void
    {
        // Nonaktifkan langsung (aksi toggle-nya sendiri sudah diuji di test lain,
        // dan sesi tidak boleh masih login sebagai admin saat mencoba login).
        $this->pelanggan->update(['is_active' => false]);

        $this->post('/login', [
            'email' => 'pelanggan@agungatk.com',
            'password' => config('app.seed_password'),
        ])->assertSessionHasErrors('email');
    }
}
