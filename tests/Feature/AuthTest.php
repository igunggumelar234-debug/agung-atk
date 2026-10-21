<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Pastikan halaman login dapat diakses oleh guest.
     */
    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Masuk ke Agung ATK');
    }

    /**
     * Pastikan halaman registrasi dapat diakses oleh guest.
     */
    public function test_register_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertSee('Daftar Akun Pelanggan');
    }

    /**
     * Pastikan pelanggan baru dapat mendaftar dan otomatis diarahkan ke /dashboard.
     */
    public function test_new_customer_can_register_and_redirect_to_dashboard(): void
    {
        $response = $this->post('/register', [
            'name' => 'Siti Nurhaliza',
            'email' => 'siti_'.uniqid().'@example.com',
            'phone' => '08987654321',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
    }

    /**
     * Pastikan Admin diarahkan ke /admin/dashboard setelah login.
     */
    public function test_admin_is_redirected_to_admin_dashboard_after_login(): void
    {
        $admin = User::where('email', 'admin@agungatk.com')->first();

        $response = $this->post('/login', [
            'email' => $admin->email,
            'password' => config('app.seed_password'),
        ]);

        $this->assertAuthenticatedAs($admin);
        $response->assertRedirect('/admin/dashboard');
    }

    /**
     * Pastikan Anggota/Pegawai diarahkan ke /anggota/dashboard setelah login
     * (role dibaca dari database, bukan dipilih di form login).
     */
    public function test_anggota_is_redirected_to_anggota_dashboard_after_login(): void
    {
        $anggota = User::where('email', 'penjual@agungatk.com')->first();

        $response = $this->post('/login', [
            'email' => $anggota->email,
            'password' => config('app.seed_password'),
        ]);

        $this->assertAuthenticatedAs($anggota);
        $response->assertRedirect('/anggota/dashboard');
    }

    /**
     * Pastikan Kurir diarahkan ke /kurir/dashboard setelah login.
     */
    public function test_courier_is_redirected_to_courier_dashboard_after_login(): void
    {
        $courier = User::where('email', 'kurir@agungatk.com')->first();

        $response = $this->post('/login', [
            'email' => $courier->email,
            'password' => config('app.seed_password'),
        ]);

        $this->assertAuthenticatedAs($courier);
        $response->assertRedirect('/kurir/dashboard');
    }

    /**
     * Pastikan Pelanggan diarahkan ke /pelanggan/dashboard setelah login.
     */
    public function test_customer_is_redirected_to_customer_dashboard_after_login(): void
    {
        $customer = User::where('email', 'pelanggan@agungatk.com')->first();

        $response = $this->post('/login', [
            'email' => $customer->email,
            'password' => config('app.seed_password'),
        ]);

        $this->assertAuthenticatedAs($customer);
        $response->assertRedirect('/pelanggan/dashboard');
    }

    /**
     * Pastikan login dengan password salah ditolak.
     */
    public function test_login_fails_with_invalid_credentials(): void
    {
        $response = $this->post('/login', [
            'email' => 'admin@agungatk.com',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    /**
     * Pastikan pengguna dapat logout dan session dihapus.
     */
    public function test_user_can_logout(): void
    {
        $user = User::where('email', 'pelanggan@agungatk.com')->first();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    /**
     * Pastikan Pelanggan TIDAK dapat mengakses halaman Admin (/admin/dashboard).
     */
    public function test_customer_cannot_access_admin_dashboard(): void
    {
        $customer = User::where('email', 'pelanggan@agungatk.com')->first();

        $response = $this->actingAs($customer)->get('/admin/dashboard');

        $response->assertStatus(403);
    }

    /**
     * Pastikan Admin TIDAK otomatis dianggap sebagai pelanggan (/dashboard).
     */
    public function test_admin_is_not_treated_as_customer(): void
    {
        $admin = User::where('email', 'admin@agungatk.com')->first();

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertStatus(403);
    }

    /**
     * Pastikan Penjual dan Kurir tidak dapat mengakses area Admin.
     */
    public function test_seller_and_courier_cannot_access_admin_dashboard(): void
    {
        $seller = User::where('email', 'penjual@agungatk.com')->first();
        $responseSeller = $this->actingAs($seller)->get('/admin/dashboard');
        $responseSeller->assertStatus(403);

        $courier = User::where('email', 'kurir@agungatk.com')->first();
        $responseCourier = $this->actingAs($courier)->get('/admin/dashboard');
        $responseCourier->assertStatus(403);
    }

    /**
     * Pastikan setiap role dapat mengakses dashboard miliknya (kanonik
     * maupun alias legacy) dengan status 200.
     */
    public function test_each_role_can_access_their_own_dashboard(): void
    {
        $admin = User::where('email', 'admin@agungatk.com')->first();
        $this->actingAs($admin)->get('/admin/dashboard')->assertStatus(200);

        $anggota = User::where('email', 'penjual@agungatk.com')->first();
        $this->actingAs($anggota)->get('/anggota/dashboard')->assertStatus(200);
        $this->actingAs($anggota)->get('/seller/dashboard')->assertStatus(200);

        $courier = User::where('email', 'kurir@agungatk.com')->first();
        $this->actingAs($courier)->get('/kurir/dashboard')->assertStatus(200);
        $this->actingAs($courier)->get('/courier/dashboard')->assertStatus(200);

        $customer = User::where('email', 'pelanggan@agungatk.com')->first();
        $this->actingAs($customer)->get('/pelanggan/dashboard')->assertStatus(200);
        $this->actingAs($customer)->get('/dashboard')->assertStatus(200);
    }

    /**
     * Matrix otorisasi: setiap role DILARANG mengakses area dashboard role lain.
     * Mencakup URL kanonik (sesuai spesifikasi) dan alias legacy.
     *
     * Route area per role:
     * - Admin     -> /admin/dashboard
     * - Anggota   -> /anggota/dashboard (alias: /seller, /penjual)
     * - Kurir     -> /kurir/dashboard (alias: /courier)
     * - Pelanggan -> /pelanggan/dashboard (alias: /dashboard)
     */
    public function test_each_role_cannot_access_other_roles_dashboard(): void
    {
        $areas = [
            '/admin/dashboard' => 'admin@agungatk.com',
            '/anggota/dashboard' => 'penjual@agungatk.com',
            '/seller/dashboard' => 'penjual@agungatk.com',
            '/penjual/dashboard' => 'penjual@agungatk.com',
            '/kurir/dashboard' => 'kurir@agungatk.com',
            '/courier/dashboard' => 'kurir@agungatk.com',
            '/pelanggan/dashboard' => 'pelanggan@agungatk.com',
            '/dashboard' => 'pelanggan@agungatk.com',
        ];

        $users = [
            'admin@agungatk.com',
            'penjual@agungatk.com',
            'kurir@agungatk.com',
            'pelanggan@agungatk.com',
        ];

        foreach ($users as $email) {
            $user = User::where('email', $email)->first();
            $this->assertNotNull($user, "Akun {$email} tidak ditemukan.");

            $ownAreas = $this->ownAreasFor($email);

            foreach ($areas as $area => $ownerEmail) {
                if (in_array($area, $ownAreas, true)) {
                    continue; // area milik sendiri diuji pada test terpisah
                }

                $this->actingAs($user)
                    ->get($area)
                    ->assertStatus(403, "Akun {$email} seharusnya DILARANG mengakses {$area}.");
            }
        }
    }

    /**
     * Seluruh area dashboard (kanonik + alias legacy) milik sebuah akun.
     *
     * @return array<int, string>
     */
    private function ownAreasFor(string $email): array
    {
        return match ($email) {
            'admin@agungatk.com' => ['/admin/dashboard'],
            'penjual@agungatk.com' => ['/anggota/dashboard', '/seller/dashboard', '/penjual/dashboard'],
            'kurir@agungatk.com' => ['/kurir/dashboard', '/courier/dashboard'],
            default => ['/pelanggan/dashboard', '/dashboard'],
        };
    }

    /**
     * Pastikan tamu (belum login) diarahkan ke halaman login untuk semua area.
     */
    public function test_guest_is_redirected_to_login_from_all_areas(): void
    {
        $areas = [
            '/admin/dashboard',
            '/anggota/dashboard',
            '/seller/dashboard',
            '/kurir/dashboard',
            '/courier/dashboard',
            '/pelanggan/dashboard',
            '/dashboard',
        ];

        foreach ($areas as $area) {
            $this->get($area)->assertRedirect('/login');
        }
    }

    /**
     * Pastikan percobaan login dibatasi lajunya per email (anti brute-force).
     */
    public function test_login_returns_429_after_five_attempts_for_the_same_email(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', [
                'email' => 'admin@agungatk.com',
                'password' => 'wrong-password-'.$attempt,
            ])->assertSessionHasErrors('email');
        }

        $this->post('/login', [
            'email' => 'admin@agungatk.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);

        $this->assertGuest();
    }

    /**
     * Pastikan formulir pendaftaran dibatasi lajunya per IP (anti pendaftaran massal).
     */
    public function test_register_returns_429_after_five_requests_from_the_same_ip(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/register', [])->assertStatus(302);
        }

        $this->post('/register', [])->assertStatus(429);
    }

    /**
     * Pastikan password kurang dari 8 karakter ditolak saat registrasi —
     * konsisten dengan kebijakan minimum 8 karakter (profil & admin).
     */
    public function test_register_with_password_shorter_than_eight_characters_is_rejected(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name' => 'Siti Nurhaliza',
            'email' => 'siti_'.uniqid().'@example.com',
            'phone' => '08987654321',
            'password' => 'rahasia',
            'password_confirmation' => 'rahasia',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('password');
    }

    /**
     * Pastikan kotak "Akun Uji Coba" TIDAK dirender pada produksi
     * (APP_ENV=production) agar akun uji tidak dipublikasikan.
     */
    public function test_demo_account_quick_login_box_is_hidden_in_production(): void
    {
        $this->app['config']->set('app.env', 'production');

        $response = $this->get('/login');

        $response->assertOk();
        $response->assertDontSee('Akun Uji Coba');
        $response->assertDontSee("fillLogin('admin@agungatk.com'", false);
    }

    /**
     * Pastikan kotak demo tetap tampil di lingkungan non-production (kemudahan dev).
     */
    public function test_demo_account_quick_login_box_is_visible_outside_production(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Akun Uji Coba');
    }
}
