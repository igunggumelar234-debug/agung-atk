<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\NotificationSetting;
use App\Services\Notifications\NotificationManager;
use App\Services\Notifications\TelegramNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationManager $notifications,
        private readonly TelegramNotificationService $telegram,
    ) {}

    /**
     * Halaman pengaturan notifikasi admin: status konfigurasi, saklar saluran
     * & kejadian, bantuan Chat ID Telegram, uji kirim, dan riwayat pengiriman
     * (dengan filter tanggal/saluran/status).
     *
     * Tidak pernah menampilkan token, kata sandi, atau kredensial rahasia.
     * Penerima email & nomor telepon ditampilkan dalam bentuk tersamarkan.
     */
    public function index(Request $request): View
    {
        $channels = config('notifications.channels', []);
        $events = config('notifications.events', []);

        $channelStates = [];
        $statuses = [];
        $defaultRecipients = [];

        foreach (array_keys($channels) as $channel) {
            $channelStates[$channel] = NotificationSetting::isEnabled('channel', $channel);
            $statuses[$channel] = $this->notifications->channelStatus($channel);
            $defaultRecipients[$channel] = $this->notifications->defaultRecipient($channel);
        }

        $eventStates = [];

        foreach (array_keys($events) as $eventKey) {
            $eventStates[$eventKey] = NotificationSetting::isEnabled('event', $eventKey);
        }

        // Filter riwayat: tanggal (Y-m-d), saluran, status — divalidasi longgar,
        // nilai yang tidak dikenali diabaikan (tidak memfilter apa pun).
        $filters = [
            'date' => $request->query('date'),
            'channel' => $request->query('channel'),
            'status' => $request->query('status'),
        ];

        $hasActiveFilter = collect($filters)
            ->contains(fn ($value) => filled($value) && $value !== 'all');

        $logs = NotificationLog::query()
            ->with('order')
            ->when(filled($filters['date']), function ($query) use ($filters) {
                $query->whereDate('created_at', $filters['date']);
            })
            ->when(
                filled($filters['channel']) && array_key_exists($filters['channel'], $channels),
                fn ($query) => $query->where('channel', $filters['channel'])
            )
            ->when(
                filled($filters['status']) && in_array($filters['status'], [
                    NotificationLog::STATUS_PENDING,
                    NotificationLog::STATUS_SENT,
                    NotificationLog::STATUS_FAILED,
                ], true),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->latestFirst()
            ->paginate(15)
            ->withQueryString();

        return view('admin.notifications.index', [
            'channels' => $channels,
            'events' => $events,
            'audiences' => config('notifications.audiences', []),
            'channelStates' => $channelStates,
            'eventStates' => $eventStates,
            'statuses' => $statuses,
            'defaultRecipients' => $defaultRecipients,
            'globalEnabled' => config('notifications.enabled'),
            'mockMode' => config('notifications.mock'),
            'queueEnabled' => config('notifications.queue'),
            'telegramChatId' => config('services.telegram.admin_chat_id')
                ?: config('services.telegram.chat_id'),
            'filters' => array_map(fn ($value) => (string) $value, $filters),
            'hasActiveFilter' => $hasActiveFilter,
            'logs' => $logs,
        ]);
    }

    /**
     * Samarkan penerima agar aman ditampilkan di layar:
     *
     * - Email: igunggumelar234@gmail.com -> igu***@gmail.com
     * - Nomor telepon: 6289697358716 -> 6289***58716
     * - Angka lain (mis. Chat ID Telegram) tidak disamarkan karena
     *   bukan kredensial dan perlu terlihat untuk verifikasi konfigurasi.
     */
    public static function maskRecipient(?string $recipient): string
    {
        if ($recipient === null || $recipient === '') {
            return '-';
        }

        // Email: samarkan bagian lokal, pertahankan domain.
        if (str_contains($recipient, '@')) {
            [$local, $domain] = explode('@', $recipient, 2);
            $visible = mb_substr($local, 0, 3);

            return $visible.'***@'.$domain;
        }

        // Nomor telepon / angka panjang: pertahankan 4 digit awal & 4 digit akhir.
        $digits = preg_replace('/\D+/', '', $recipient);

        if (strlen($digits) >= 10) {
            return mb_substr($recipient, 0, 4).'***'.mb_substr($recipient, -4);
        }

        return $recipient;
    }

    /**
     * Simpan pengaturan saluran & kejadian notifikasi.
     */
    public function updateSettings(Request $request): RedirectResponse
    {
        $request->validate([
            'channels' => ['required', 'array'],
            'events' => ['required', 'array'],
        ], [
            'channels.required' => 'Data saluran tidak ditemukan. Muat ulang halaman lalu coba lagi.',
            'events.required' => 'Data kejadian tidak ditemukan. Muat ulang halaman lalu coba lagi.',
        ]);

        DB::transaction(function () use ($request) {
            // Catatan: nama field form mengandung titik ('events[order.created]')
            // sehingga dibaca sebagai array biasa — bukan dot-notation input().
            $channels = $request->input('channels', []);
            $events = $request->input('events', []);

            foreach (array_keys(config('notifications.channels', [])) as $channel) {
                $enabled = array_key_exists($channel, $channels)
                    ? filter_var($channels[$channel], FILTER_VALIDATE_BOOLEAN)
                    : false;

                NotificationSetting::set('channel', $channel, $enabled);
            }

            foreach (array_keys(config('notifications.events', [])) as $eventKey) {
                $enabled = array_key_exists($eventKey, $events)
                    ? filter_var($events[$eventKey], FILTER_VALIDATE_BOOLEAN)
                    : false;

                NotificationSetting::set('event', $eventKey, $enabled);
            }
        });

        return back()->with('success', 'Pengaturan notifikasi disimpan.');
    }

    /**
     * Kirim pesan uji ke satu saluran (hasil langsung: terkirim/gagal).
     */
    public function test(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(array_keys(config('notifications.channels', [])))],
            'recipient' => ['nullable', 'string', 'max:190'],
            'message' => ['nullable', 'string', 'max:500'],
        ], [
            'channel.required' => 'Pilih saluran notifikasi yang akan diuji.',
            'channel.in' => 'Saluran notifikasi tidak dikenali.',
        ]);

        $result = $this->notifications->sendTest(
            $data['channel'],
            $data['message'] ?? null,
            filled($data['recipient'] ?? null) ? trim($data['recipient']) : null,
        );

        if ($result['ok']) {
            return back()->with(
                'success',
                'Pesan uji berhasil terkirim melalui '.ucfirst($data['channel'])
                .' ke '.$result['log']->recipient.'.'
            );
        }

        return back()->with('error', 'Pesan uji gagal: '.($result['error'] ?? 'kesalahan tidak diketahui.'));
    }

    /**
     * Bantuan Chat ID Telegram: ambil chat id dari pesan terbaru ke bot.
     *
     * Token tidak pernah ditampilkan — dipakai di sisi server saja.
     */
    public function resolveTelegramChatId(): RedirectResponse
    {
        $result = $this->telegram->resolveChatId();

        if (! ($result['ok'] ?? false)) {
            return back()->with('error', $result['error'] ?? 'Gagal mengambil Chat ID Telegram.');
        }

        $identity = trim(($result['name'] ?? '').' '.($result['username'] ?? null ? '(@'.$result['username'].')' : ''));

        return back()->with(
            'success',
            'Chat ID ditemukan: '.$result['chat_id']
            .($identity !== '' ? ' — '.$identity : '')
            .'. Tambahkan TELEGRAM_ADMIN_CHAT_ID='.$result['chat_id'].' ke file .env, lalu jalankan php artisan config:clear.'
        );
    }
}
