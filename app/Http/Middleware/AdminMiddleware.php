<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     * Hanya pengguna dengan role Admin yang diizinkan.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            // Klien AJAX/JSON mendapat 401, bukan redirect HTML yang membingungkan
            // konsumen fetch() (visual search, cart, chat).
            abort_unless(! $request->expectsJson(), 401, 'Unauthenticated.');

            return redirect()->route('login');
        }

        if (! $user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(401, 'Akun Anda sedang dinonaktifkan oleh Administrator.');
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Akun Anda sedang dinonaktifkan oleh Administrator.',
            ]);
        }

        if (! $user->isAdmin()) {
            abort(403, 'Akses Ditolak: Halaman ini hanya dapat diakses oleh Administrator Toko.');
        }

        return $next($request);
    }
}
