<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CourierMiddleware
{
    /**
     * Handle an incoming request.
     * Hanya pengguna dengan role Kurir (Courier) yang diizinkan.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
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

        if (! $user->isCourier()) {
            abort(403, 'Akses Ditolak: Halaman ini khusus untuk Kurir Pengantaran Wilayah Kuningan.');
        }

        return $next($request);
    }
}
