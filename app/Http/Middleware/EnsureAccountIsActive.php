<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tolak sesi pengguna yang dinonaktifkan oleh Admin di SEMUA rute ber-webkit
 * (admin, anggota, kurir, pelanggan, profil, AJAX, unggahan) — pengguna
 * langsung logout dan diarahkan ke halaman login dengan pesan jelas.
 * Berjalan sebagai middleware grup 'web' sehingga berlaku tanpa perlu
 * menambahkan satu per satu di setiap route group.
 */
class EnsureAccountIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(401, 'Akun Anda sedang dinonaktifkan oleh Administrator.');
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Akun Anda sedang dinonaktifkan oleh Administrator. Silakan hubungi toko.',
            ]);
        }

        return $next($request);
    }
}
