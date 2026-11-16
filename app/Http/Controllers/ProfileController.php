<?php

namespace App\Http\Controllers;

use App\Models\ChannelIdentity;
use App\Services\Channels\ChannelIdentityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(private readonly ChannelIdentityService $channelIdentities) {}

    /**
     * Halaman profil pelanggan (data akun + form ubah profil & password
     * + tautan kanal chatbot WhatsApp/Telegram).
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('profile.index', [
            'user' => $user,
            'linkedChannels' => $this->channelIdentities->forUser($user),
            'pendingChannels' => ChannelIdentity::query()
                ->where('user_id', $user->id)
                ->where('status', ChannelIdentity::STATUS_PENDING)
                ->get()
                ->keyBy('channel'),
        ]);
    }

    /**
     * Perbarui data profil (nama & nomor telepon).
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
            'name.max' => 'Nama lengkap maksimal 255 karakter.',
            'phone.max' => 'Nomor telepon maksimal 20 karakter.',
        ]);

        $request->user()->update($data);

        return redirect()
            ->route('profile.index')
            ->with('success', 'Profil berhasil diperbarui.');
    }

    /**
     * Ganti password (wajib memasukkan password saat ini).
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ], [
            'current_password.required' => 'Password saat ini wajib diisi.',
            'password.required' => 'Password baru wajib diisi.',
            'password.min' => 'Password baru minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password baru tidak sama.',
        ]);

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            return back()->withErrors([
                'current_password' => 'Password saat ini salah.',
            ]);
        }

        $request->user()->update([
            'password' => Hash::make($data['password']),
        ]);

        return redirect()
            ->route('profile.index')
            ->with('success', 'Password berhasil diubah.');
    }

    /**
     * Buat kode verifikasi tautan kanal (WhatsApp/Telegram).
     *
     * Kode ditampilkan di halaman profil ini dan harus dikirim pengguna
     * dari kanalnya sendiri — tautan tidak pernah dibuat dari username
     * atau nomor mentah tanpa verifikasi.
     */
    public function requestChannelCode(Request $request, string $channel): RedirectResponse
    {
        abort_unless($this->isSupportedChannel($channel), 404);

        $this->channelIdentities->requestCode($request->user(), $channel);

        return redirect()
            ->route('profile.index')
            ->with(
                'channel_status',
                'Kode verifikasi '.ucfirst($channel).' dibuat. Kirim kode tersebut dari kanal Anda '
                .'untuk menautkan akun (berlaku '.ChannelIdentity::CODE_TTL_MINUTES.' menit).'
            );
    }

    /**
     * Cabut tautan kanal milik pengguna.
     */
    public function unlinkChannel(Request $request, string $channel): RedirectResponse
    {
        abort_unless($this->isSupportedChannel($channel), 404);

        $this->channelIdentities->unlink($request->user(), $channel);

        return redirect()
            ->route('profile.index')
            ->with('channel_status', 'Tautan '.ucfirst($channel).' dicabut.');
    }

    private function isSupportedChannel(string $channel): bool
    {
        return in_array($channel, [ChannelIdentity::CHANNEL_WHATSAPP, ChannelIdentity::CHANNEL_TELEGRAM], true);
    }
}
