<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fitur tema Terang/Gelap/Sistem: script anti-FOUC, komponen pemilih tema,
 * blok CSS mode gelap, dan dukungan warna grafik berbasis tema.
 */
class ThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_page_loads_theme_initializer_and_toggle(): void
    {
        $response = $this->get('/')->assertStatus(200);

        // Script inisialisasi anti-FOUC ada di <head> sebelum konten.
        $response->assertSee('atk-theme', false);
        $response->assertSee('prefers-color-scheme', false);
        $response->assertSee('data-theme', false);

        // Pemilih tema dengan tiga opsi tersedia di navbar.
        $response->assertSee('data-theme-option="light"', false);
        $response->assertSee('data-theme-option="dark"', false);
        $response->assertSee('data-theme-option="system"', false);
        $response->assertSee('Mode Terang');
        $response->assertSee('Mode Gelap');
        $response->assertSee('Mode Sistem');

        // Blok CSS mode gelap ikut dimuat.
        $response->assertSee('[data-theme="dark"]', false);
    }

    public function test_dashboard_loads_theme_initializer_and_toggle(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@agungatk.com')->firstOrFail();

        $response = $this->actingAs($admin)->get('/admin/dashboard')->assertStatus(200);

        $response->assertSee('atk-theme', false);
        $response->assertSee('data-theme-option="dark"', false);
        $response->assertSee('[data-theme="dark"]', false);
    }

    public function test_guest_can_see_theme_toggle_on_auth_pages(): void
    {
        foreach (['/login', '/register'] as $url) {
            $this->get($url)
                ->assertStatus(200)
                ->assertSee('data-theme-option="system"', false)
                ->assertSee('atk-theme', false);
        }
    }

    public function test_sales_chart_is_theme_aware(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@agungatk.com')->firstOrFail();

        $response = $this->actingAs($admin)->get('/admin/dashboard')->assertStatus(200);

        // Grafik membaca warna dari tema aktif dan mendengar event pergantian tema.
        $response->assertSee('atkChartColors', false);
        $response->assertSee('atk:theme-changed', false);
    }
}
