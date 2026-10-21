<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
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
 * Arsitektur role & hak akses: admin, anggota, kurir, pelanggan.
 * - Role SELALU dibaca dari database, bukan dari input browser.
 * - Pendaftaran publik selalu menghasilkan pelanggan.
 * - Akun anggota/kurir hanya dibuat Admin.
 * - Endpoint sensitif dilindungi middleware & jejak aktivitas tercatat.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $anggota;

    private User $kurir;

    private User $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@agungatk.com')->firstOrFail();
        $this->anggota = User::where('email', 'penjual@agungatk.com')->firstOrFail();
        $this->kurir = User::where('email', 'kurir@agungatk.com')->firstOrFail();
        $this->pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();
    }

    /* =====================================================================
     | 1. PENDAFTARAN & PEMBUATAN AKUN
     * ===================================================================*/

    public function test_public_registration_always_creates_pelanggan_even_if_role_is_submitted(): void
    {
        $email = 'spoof_'.uniqid().'@example.com';

        // Coba manipulasi request: kirim role & role_id milik admin/anggota.
        $this->post('/register', [
            'name' => 'Penyusup Role',
            'email' => $email,
            'phone' => '08111111111',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'anggota',
            'role_id' => Role::where('name', 'admin')->first()->id,
            'is_active' => 1,
        ])->assertRedirect('/dashboard');

        $user = User::where('email', $email)->firstOrFail();

        $this->assertSame('pelanggan', $user->role?->name);
        $this->assertNull($user->employee_code);
        $this->assertTrue((bool) $user->is_active);
    }

    public function test_only_admin_can_create_accounts(): void
    {
        $payload = [
            'name' => 'Calon Pegawai',
            'email' => 'calon_'.uniqid().'@example.com',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'role' => 'anggota',
        ];

        // Tamu -> diarahkan ke login
        $this->post('/admin/users', $payload)->assertRedirect('/login');

        // Non-admin -> dilarang oleh middleware backend
        foreach ([$this->anggota, $this->kurir, $this->pelanggan] as $user) {
            $this->actingAs($user)->post('/admin/users', $payload)->assertStatus(403);
        }

        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_admin_can_create_anggota_with_generated_unique_employee_code(): void
    {
        $email = 'pegawai_'.uniqid().'@example.com';

        $this->actingAs($this->admin)->post('/admin/users', [
            'name' => 'Pegawai Baru',
            'email' => $email,
            'phone' => '081234567899',
            'password' => 'rahasia12345',
            'password_confirmation' => 'rahasia12345',
            'role' => 'anggota',
            'position' => 'Staf Gudang',
            'employment_status' => 'contract',
            'hired_at' => '2026-01-05',
        ])->assertRedirect(route('admin.users.index'));

        $user = User::where('email', $email)->firstOrFail();

        $this->assertSame('anggota', $user->role?->name);
        $this->assertNotNull($user->employee_code);
        $this->assertMatchesRegularExpression('/^AGT-\d{4}$/', $user->employee_code);
        $this->assertSame('Staf Gudang', $user->position);
        $this->assertSame('contract', $user->employment_status);
        $this->assertSame(1, User::where('employee_code', $user->employee_code)->count());

        // Jejak audit tercatat dengan role pelaku
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->admin->id,
            'role' => 'admin',
            'activity_type' => 'user.created',
            'subject_id' => $user->id,
        ]);
    }

    public function test_admin_can_create_kurir_account_via_protected_form(): void
    {
        $email = 'kurirbaru_'.uniqid().'@example.com';

        $this->actingAs($this->admin)->post('/admin/users', [
            'name' => 'Kurir Baru',
            'email' => $email,
            'password' => 'rahasia12345',
            'password_confirmation' => 'rahasia12345',
            'role' => 'kurir',
        ])->assertRedirect(route('admin.users.index'));

        $user = User::where('email', $email)->firstOrFail();
        $this->assertSame('kurir', $user->role?->name);
        $this->assertMatchesRegularExpression('/^KUR-\d{4}$/', $user->employee_code);
    }

    public function test_admin_account_cannot_be_created_through_the_form(): void
    {
        $this->actingAs($this->admin)->post('/admin/users', [
            'name' => 'Tambahan Admin',
            'email' => 'admin2_'.uniqid().'@example.com',
            'password' => 'rahasia12345',
            'password_confirmation' => 'rahasia12345',
            'role' => 'admin',
        ])->assertSessionHasErrors('role');

        $this->assertSame(1, User::whereHas('role', fn ($q) => $q->where('name', 'admin'))->count());
    }

    /* =====================================================================
     | 2. ROLE TIDAK BISA DIMANIPULASI MELALUI REQUEST
     * ===================================================================*/

    public function test_non_admin_cannot_update_users_or_change_roles(): void
    {
        foreach ([$this->anggota, $this->kurir, $this->pelanggan] as $user) {
            $this->actingAs($user)
                ->put('/admin/users/'.$this->pelanggan->id, [
                    'name' => 'Nama Diubah',
                    'email' => $this->pelanggan->email,
                    'role' => 'admin',
                ])
                ->assertStatus(403);

            $this->actingAs($user)
                ->patch('/admin/users/'.$this->pelanggan->id.'/reset-password', [
                    'password' => 'barusaja12345',
                    'password_confirmation' => 'barusaja12345',
                ])
                ->assertStatus(403);
        }

        // Role pelanggan tidak berubah
        $this->assertSame('pelanggan', $this->pelanggan->fresh()?->role?->name);
    }

    public function test_admin_cannot_change_own_role(): void
    {
        $this->actingAs($this->admin)->put('/admin/users/'.$this->admin->id, [
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'role' => 'anggota',
        ])->assertSessionHasErrors('role');

        $this->assertSame('admin', $this->admin->fresh()?->role?->name);
    }

    /* =====================================================================
     | 3. AKUN NONAKTIF
     * ===================================================================*/

    public function test_inactive_account_cannot_login(): void
    {
        $this->anggota->update(['is_active' => false]);

        $this->post('/login', [
            'email' => $this->anggota->email,
            'password' => config('app.seed_password'),
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_active_session_of_deactivated_user_is_terminated(): void
    {
        // Middleware grup web menutup sesi di SELURUH area (bukan hanya pelanggan)
        $this->anggota->update(['is_active' => false]);

        foreach (['/anggota/dashboard', '/anggota/orders', '/profile'] as $area) {
            $this->actingAs($this->anggota)
                ->get($area)
                ->assertRedirect('/login')
                ->assertSessionHasErrors('email');
        }
    }

    /* =====================================================================
     | 4. PROTEKSI ROUTE SENSITIF (TAMU & NON-ADMIN)
     * ===================================================================*/

    public function test_guest_is_redirected_from_sensitive_endpoints(): void
    {
        $product = Product::firstOrFail();

        $endpoints = [
            '/admin/users/create',
            '/admin/activity-logs',
            '/admin/reports/export',
            '/anggota/stock',
            '/anggota/orders',
            '/courier/shipments',
            '/profile',
        ];

        foreach ($endpoints as $endpoint) {
            $this->get($endpoint)->assertRedirect('/login');
        }

        $this->patch('/anggota/stock/'.$product->id, [
            'stock' => 100,
            'reason' => 'uji tamu',
        ])->assertRedirect('/login');
    }

    public function test_anggota_is_forbidden_from_admin_management_and_audit_pages(): void
    {
        $product = Product::firstOrFail();

        $this->actingAs($this->anggota)->get('/admin/users/create')->assertStatus(403);
        $this->actingAs($this->anggota)->get('/admin/activity-logs')->assertStatus(403);
        $this->actingAs($this->anggota)->get('/admin/reports')->assertStatus(403);
        $this->actingAs($this->anggota)->get('/admin/notifications')->assertStatus(403);

        // Anggota juga tidak boleh memakai endpoint stok admin
        $this->actingAs($this->anggota)->patch('/admin/products/'.$product->id.'/stock', [
            'stock' => 500,
        ])->assertStatus(403);

        // Anggota tidak boleh mengubah pengguna lain
        $this->actingAs($this->anggota)
            ->patch('/admin/users/'.$this->pelanggan->id.'/toggle-status')
            ->assertStatus(403);
    }

    public function test_activity_log_page_is_admin_only(): void
    {
        $this->actingAs($this->admin)->get('/admin/activity-logs')->assertStatus(200);

        foreach ([$this->anggota, $this->kurir, $this->pelanggan] as $user) {
            $this->actingAs($user)->get('/admin/activity-logs')->assertStatus(403);
        }
    }

    /* =====================================================================
     | 5. RESET PASSWORD AMAN + AUDIT
     * ===================================================================*/

    public function test_admin_can_reset_password_safely_with_audit_trail(): void
    {
        $oldPassword = config('app.seed_password');
        $newPassword = 'KataSandiBaru99';

        $this->actingAs($this->admin)
            ->patch('/admin/users/'.$this->anggota->id.'/reset-password', [
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])
            ->assertRedirect();

        $fresh = $this->anggota->fresh();

        $this->assertTrue(Hash::check($newPassword, $fresh->password));
        $this->assertFalse(Hash::check($oldPassword, $fresh->password));

        // Kata sandi TIDAK pernah muncul di jejak audit
        $log = ActivityLog::where('activity_type', 'user.password_reset')
            ->where('user_id', $this->admin->id)
            ->where('subject_id', $this->anggota->id)
            ->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString($newPassword, (string) $log->summary);

        // Validasi tetap berlaku: password pendek ditolak
        $this->actingAs($this->admin)
            ->patch('/admin/users/'.$this->anggota->id.'/reset-password', [
                'password' => 'pendek',
                'password_confirmation' => 'pendek',
            ])
            ->assertSessionHasErrors('password');
    }

    /* =====================================================================
     | 6. DASHBOARD & MODUL ANGGOTA
     * ===================================================================*/

    public function test_anggota_dashboard_shows_jobs_stock_and_own_activity(): void
    {
        $address = Address::where('user_id', $this->pelanggan->id)->first() ?? Address::firstOrFail();

        $order = Order::create([
            'order_number' => 'ATK-ROLE-'.uniqid(),
            'user_id' => $this->pelanggan->id,
            'address_id' => $address->id,
            'total_amount' => 50000,
            'shipping_cost' => 10000,
            'discount_amount' => 0,
            'grand_total' => 60000,
            'status' => 'paid',
        ]);

        $response = $this->actingAs($this->anggota)->get('/anggota/dashboard');

        $response->assertStatus(200)
            ->assertSee('Dashboard Anggota / Pegawai Toko')
            ->assertSee($order->order_number)
            ->assertSee('Peringatan Stok Rendah')
            ->assertSee('Riwayat Aktivitas Saya')
            ->assertSee(route('anggota.stock.index'), false);
    }

    public function test_anggota_can_record_stock_change_with_reason_and_audit(): void
    {
        $product = Product::firstOrFail();
        $oldStock = (int) $product->stock;

        $this->actingAs($this->anggota)
            ->patch('/anggota/stock/'.$product->id, [
                'stock' => $oldStock + 25,
                'reason' => 'Penerimaan barang dari supplier',
            ])
            ->assertRedirect();

        $this->assertSame($oldStock + 25, (int) $product->fresh()->stock);

        $log = ActivityLog::where('activity_type', 'stock.adjusted')
            ->where('user_id', $this->anggota->id)
            ->where('subject_id', $product->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('anggota', $log->role);
        $this->assertStringContainsString('Penerimaan barang dari supplier', $log->summary);
    }

    public function test_stock_change_rejects_negative_value_and_missing_reason(): void
    {
        $product = Product::firstOrFail();

        $this->actingAs($this->anggota)
            ->patch('/anggota/stock/'.$product->id, ['stock' => -5, 'reason' => 'uji'])
            ->assertSessionHasErrors('stock');

        $this->actingAs($this->anggota)
            ->patch('/anggota/stock/'.$product->id, ['stock' => 10, 'reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertNotSame(-5, (int) $product->fresh()->stock);
    }

    public function test_anggota_can_view_own_activity_history(): void
    {
        $response = $this->actingAs($this->anggota)
            ->get(route('anggota.stock.index'))
            ->assertStatus(200)
            ->assertSee('Ketersediaan Stok Barang');

        // Admin melihat riwayat aktivitas pegawai per orang
        $this->actingAs($this->admin)
            ->get('/admin/users/'.$this->anggota->id.'/activities')
            ->assertStatus(200)
            ->assertSee($this->anggota->name)
            ->assertSee('Aktivitas Tercatat');
    }

    /* =====================================================================
     | 7. MENU / NAVIGASI PER ROLE (tidak duplikat, tidak bocor)
     * ===================================================================*/

    public function test_sidebar_menus_are_role_specific_and_not_duplicated(): void
    {
        // [pengguna, url dashboard, menu yang HARUS ada, menu yang TIDAK boleh ada]
        $scenarios = [
            [
                $this->admin,
                '/admin/dashboard',
                ['/admin/orders', '/admin/activity-logs', '/admin/users', '/profile'],
                ['/anggota/orders', '/courier/shipments', '/addresses'],
            ],
            [
                $this->anggota,
                '/anggota/dashboard',
                ['/anggota/dashboard', '/anggota/orders', '/anggota/stock', '/profile'],
                ['/admin/orders', '/courier/shipments', '/addresses'],
            ],
            [
                $this->kurir,
                '/kurir/dashboard',
                ['/courier/dashboard', '/courier/shipments', '/profile'],
                ['/admin/orders', '/anggota/orders', '/addresses'],
            ],
            [
                $this->pelanggan,
                '/pelanggan/dashboard',
                ['/orders', '/addresses', '/profile'],
                ['/admin/orders', '/anggota/orders', '/courier/shipments'],
            ],
        ];

        foreach ($scenarios as [$user, $url, $requiredMenus, $forbiddenMenus]) {
            $html = $this->actingAs($user)->get($url)->assertStatus(200)->getContent();

            preg_match('/<nav class="nav flex-column.*?<\/nav>/s', $html, $matches);
            $sidebar = $matches[0] ?? '';
            $this->assertNotSame('', $sidebar, 'Sidebar tidak ditemukan untuk '.$url);

            preg_match_all('/href="([^"]+)"/', $sidebar, $hrefs);
            $counts = array_count_values($hrefs[1]);

            foreach ($counts as $href => $count) {
                $this->assertSame(1, $count, "Menu {$href} muncul {$count} kali di sidebar {$url}");
            }

            foreach ($requiredMenus as $menu) {
                $this->assertStringContainsString($menu, $sidebar, "Menu {$menu} hilang dari sidebar {$url}");
            }

            foreach ($forbiddenMenus as $menu) {
                $this->assertStringNotContainsString($menu, $sidebar, "Menu asing {$menu} bocor di sidebar {$url}");
            }
        }
    }

    public function test_login_form_does_not_offer_role_selection(): void
    {
        $html = $this->get('/login')->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('name="role"', $html);
        $this->assertStringNotContainsString('name="role_id"', $html);
        $this->assertStringNotContainsString('Login sebagai', $html);
    }

    /* =====================================================================
     | PERMINTAAN JSON (klien fetch/AJAX) MENDAPAT 401, BUKAN REDIRECT HTML
     * ===================================================================*/

    public function test_guest_json_request_to_admin_route_returns_401_instead_of_redirect(): void
    {
        $this->getJson('/admin/products')->assertUnauthorized();
    }

    public function test_guest_json_request_to_courier_route_returns_401_instead_of_redirect(): void
    {
        $this->getJson('/courier/dashboard')->assertUnauthorized();
    }

    public function test_json_request_for_deactivated_account_returns_401_and_logs_out(): void
    {
        $this->pelanggan->update(['is_active' => false]);

        $this->actingAs($this->pelanggan)
            ->getJson('/ai')
            ->assertUnauthorized();

        $this->assertGuest();
    }
}
