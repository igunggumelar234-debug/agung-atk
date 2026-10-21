<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerMiddleware
{
    /**
     * Handle an incoming request.
     * Hanya pengguna dengan role Pelanggan (Customer) yang diizinkan.
     * Catatan: Admin tidak otomatis dianggap sebagai pelanggan.
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

        // Pastikan admin tidak otomatis dianggap sebagai pelanggan
        if (! $user->isCustomer()) {
            abort(403, 'Akses Ditolak: Halaman ini khusus untuk Pelanggan. Akun Administrator atau peran lain tidak dapat mengakses area ini.');
        }

        return $next($request);
    }
}
