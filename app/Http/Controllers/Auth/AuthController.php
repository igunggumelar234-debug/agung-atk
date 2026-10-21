<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthController extends Controller
{
    /**
     * Tampilkan halaman formulir login.
     */
    public function showLoginForm(): View|RedirectResponse
    {
        if (Auth::check()) {
            return $this->redirectBasedOnRole(Auth::user());
        }

        return view('auth.login');
    }

    /**
     * Proses autentikasi login pengguna dengan session and password verification.
     */
    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->only('email', 'password');
        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            return back()->withInput($request->only('email', 'remember'))->withErrors([
                'email' => 'Kombinasi email dan password tidak sesuai dengan data kami.',
            ]);
        }

        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Akun Anda sedang dinonaktifkan oleh Administrator. Silakan hubungi toko.',
            ]);
        }

        $request->session()->regenerate();

        ActivityLog::record('auth.login', $user, 'Login ke sistem.');

        return $this->redirectBasedOnRole($user)
            ->with('success', 'Selamat datang kembali, '.$user->name.'!');
    }

    /**
     * Tampilkan halaman formulir registrasi pelanggan baru.
     */
    public function showRegisterForm(): View|RedirectResponse
    {
        if (Auth::check()) {
            return $this->redirectBasedOnRole(Auth::user());
        }

        return view('auth.register');
    }

    /**
     * Proses pendaftaran pelanggan baru (otomatis role: pelanggan/customer).
     */
    public function register(RegisterRequest $request): RedirectResponse
    {
        $customerRole = Role::whereIn('name', ['pelanggan', 'customer'])->firstOrFail();

        $user = User::create([
            'role_id' => $customerRole->id,
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'password' => Hash::make($request->validated('password')),
            'is_active' => true,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        ActivityLog::record('account.registered', $user, 'Registrasi akun pelanggan baru.');

        return redirect()->to('/dashboard')
            ->with('success', 'Pendaftaran berhasil! Selamat bergabung di Agung ATK Kuningan.');
    }

    /**
     * Proses logout pengguna (menghapus session autentikasi).
     */
    public function logout(Request $request): RedirectResponse
    {
        ActivityLog::record('auth.logout', Auth::user(), 'Keluar dari sistem.');

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('info', 'Anda telah berhasil keluar dari sistem.');
    }

    /**
     * Alihkan pengguna ke dashboard sesuai role yang dibaca dari DATABASE
     * (bukan dari input/pilihan browser):
     * - Admin     -> /admin/dashboard
     * - Anggota   -> /anggota/dashboard
     * - Kurir     -> /kurir/dashboard
     * - Pelanggan -> /pelanggan/dashboard
     */
    protected function redirectBasedOnRole(User $user): RedirectResponse
    {
        if ($user->isAdmin()) {
            return redirect()->to('/admin/dashboard');
        }

        if ($user->isAnggota()) {
            return redirect()->to('/anggota/dashboard');
        }

        if ($user->isCourier()) {
            return redirect()->to('/kurir/dashboard');
        }

        if ($user->isCustomer()) {
            return redirect()->to('/pelanggan/dashboard');
        }

        return redirect('/');
    }
}
