<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Halaman error ramah (resources/views/errors/*):
 * tampilan standalone tanpa detail teknis, mendukung tema, dan aman
 * ditampilkan bahkan ketika database bermasalah (tanpa query sesi/DB).
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_unknown_url_renders_friendly_404_page(): void
    {
        $this->get('/halaman-tidak-ada-untuk-uji')
            ->assertNotFound()
            ->assertSee('Halaman Tidak Ditemukan')
            ->assertSee('Kembali ke Beranda')
            ->assertDontSee('Whoops')
            ->assertDontSee('Exception');
    }

    public function test_forbidden_page_renders_friendly_403_for_lower_role(): void
    {
        $pelanggan = User::where('email', 'pelanggan@agungatk.com')->firstOrFail();

        $this->actingAs($pelanggan)
            ->get('/admin/dashboard')
            ->assertForbidden()
            ->assertSee('Akses Ditolak')
            ->assertSee('tidak memiliki hak akses');
    }

    public function test_server_error_renders_friendly_500_page_without_exception_details(): void
    {
        config(['app.debug' => false]);

        Route::get('/_uji-boom', function () {
            throw new \RuntimeException('DETAIL-RAHASIA-internal-tidak-boleh-tampil');
        });

        $this->get('/_uji-boom')
            ->assertStatus(500)
            ->assertSee('Terjadi Gangguan di Sisi Server')
            ->assertDontSee('DETAIL-RAHASIA-internal-tidak-boleh-tampil')
            ->assertDontSee('RuntimeException');
    }
}
