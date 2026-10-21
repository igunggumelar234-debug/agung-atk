<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan dasar untuk SELURUH respons ber-web.
 *
 * - X-Content-Type-Options: cegah MIME-sniffing (mis. unggah gambar di /storage).
 * - X-Frame-Options / Referrer-Policy: cegah clickjacking & kebocoran URL.
 * - Permissions-Policy: nonaktifkan fitur perangkat yang tidak dipakai aplikasi
 *   (kamera TIDAK dimatikan — fitur Visual Search memakainya di origin yang sama).
 * - Strict-Transport-Security: diabaikan browser saat diakses lewat HTTP murni,
 *   aktif otomatis begitu situs dilayani lewat HTTPS.
 *
 * Respons yang dihasilkan exception handler (mis. redirect tamu ke /login)
 * tidak melewati middleware — hook Exceptions::respond di bootstrap/app.php
 * memanggil apply() yang sama sehingga header tetap terpasang di sana.
 *
 * CSP sengaja TIDAK dipasang: aplikasi memakai <script>/<style> inline skala besar
 * (theme, chatbot, visual-search) sehingga CSP yang berguna akan membutuhkan
 * 'unsafe-inline' yang nilainya kecil — lihat laporan AUDIT_REPORT.md.
 */
class SecurityHeaders
{
    /**
     * Daftar header keamanan dasar (nama => nilai).
     *
     * @var array<string, string>
     */
    public const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'microphone=(), geolocation=(), payment=()',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request));
    }

    /**
     * Pasang seluruh header keamanan dasar pada sebuah respons.
     */
    public static function apply(Response $response): Response
    {
        foreach (self::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
