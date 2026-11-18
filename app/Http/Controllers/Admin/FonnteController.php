<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Notifications\FonnteService;
use App\Services\Notifications\NotificationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint dashboard untuk integrasi WhatsApp Fonnte (provider utama).
 *
 * Seluruh endpoint memanggil service backend nyata (bukan sekadar
 * mengubah tampilan), tidak pernah menampilkan token Fonnte, dan hanya
 * dapat diakses admin (route berada dalam grup middleware admin).
 */
class FonnteController extends Controller
{
    public function __construct(
        private readonly FonnteService $fonnte,
        private readonly NotificationManager $notifications,
    ) {}

    /**
     * Periksa status perangkat Fonnte (POST admin/fonnte/device).
     *
     * Membaca POST {FONNTE_API_URL}/device — hanya baca, tanpa
     * pengiriman pesan apa pun.
     */
    public function device(): JsonResponse
    {
        $status = $this->notifications->channelStatus('whatsapp');
        $provider = (string) config('services.whatsapp.provider', 'meta');

        if ($provider !== 'fonnte') {
            return response()->json([
                'ok' => false,
                'provider' => $provider,
                'error' => 'Provider WhatsApp aktif bukan Fonnte (WHATSAPP_PROVIDER='.$provider.').',
            ], 422);
        }

        if (! $status['configured']) {
            return response()->json([
                'ok' => false,
                'provider' => 'fonnte',
                'missing' => $status['missing'],
                'error' => 'Konfigurasi belum lengkap. Variabel yang kurang: '.implode(', ', $status['missing']).'.',
            ], 422);
        }

        $device = $this->fonnte->device();

        return response()->json($device + [
            'provider' => 'fonnte',
            'checked_at' => now()->toIso8601String(),
        ], $device['ok'] ? 200 : 502);
    }

    /**
     * Kirim pesan percobaan ke nomor admin via Fonnte (POST admin/fonnte/test).
     *
     * Hasil pengiriman dicatat pada notification_logs (riwayat nyata) dan
     * status sukses/gagal hanya dinyatakan setelah respons provider.
     */
    public function sendTest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $provider = (string) config('services.whatsapp.provider', 'meta');

        if ($provider !== 'fonnte') {
            return response()->json([
                'ok' => false,
                'provider' => $provider,
                'error' => 'Provider WhatsApp aktif bukan Fonnte (WHATSAPP_PROVIDER='.$provider.').',
            ], 422);
        }

        $result = $this->notifications->sendTest(
            'whatsapp',
            filled($validated['message'] ?? null) ? (string) $validated['message'] : null,
        );

        $log = $result['log'];

        return response()->json([
            'ok' => $result['ok'],
            'provider' => 'fonnte',
            'status' => $result['status'],
            'error' => $result['error'],
            'recipient' => $log?->recipient !== null
                ? NotificationController::maskRecipient((string) $log->recipient)
                : null,
            'sent_at' => $log?->sent_at?->toIso8601String(),
        ], $result['ok'] ? 200 : 502);
    }
}
