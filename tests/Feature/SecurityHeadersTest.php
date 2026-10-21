<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Header keamanan dasar (App\Http\Middleware\SecurityHeaders) pada respons web.
 *
 * - nosniff: cegah MIME-sniffing (mis. file unggahan di /storage).
 * - SAMEORIGIN: cegah clickjacking (halaman aplikasi boleh diframe sesama origin).
 * - Referrer-Policy & Permissions-Policy: batasi kebocoran konteks & fitur perangkat.
 * - HSTS: aktif otomatis saat situs dilayani HTTPS (diabaikan pada HTTP murni).
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_web_responses_carry_basic_security_headers(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'microphone=(), geolocation=(), payment=()');
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_security_headers_are_applied_to_redirect_responses(): void
    {
        $response = $this->get('/admin/products'); // tamu -> redirect ke /login

        $response->assertRedirect();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
