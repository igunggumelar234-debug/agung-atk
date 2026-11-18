<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChannelIdentity;
use App\Models\NotificationLog;
use App\Models\NotificationSetting;
use App\Services\Notifications\FonnteService;
use App\Services\Notifications\NotificationManager;
use App\Services\Notifications\TelegramNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
                // Rentang eksplisit sepanjang hari (bukan DATE() pada kolom)
                // agar index created_at terpakai — cakupan identik whereDate.
                $day = substr((string) $filters['date'], 0, 10);
                $query->where('created_at', '>=', $day)
                    ->where('created_at', '<=', $day.' 23:59:59');
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
            'eventLabels' => array_merge(
                ['chatbot.reply' => 'Balasan Chatbot'],
                array_map(fn (array $event) => $event['label'] ?? null, $events),
            ),
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
            'integration' => $this->integrationData(),
            'system' => $this->systemStatus(),
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

    /**
     * Uji koneksi WhatsApp (Graph API) & Telegram (getMe + info webhook).
     *
     * Hanya membaca status — tidak pernah mengirim pesan. Hasil disimpan
     * satu jam agar halaman tidak memanggil API berulang.
     */
    public function testConnections(): RedirectResponse
    {
        $telegram = $this->telegram->getMe();

        if ($telegram['ok'] ?? false) {
            $telegram['webhook'] = $this->telegram->webhookInfo();

            $expected = (string) config('services.telegram.bot_username', '');

            $telegram['username_matches'] = $expected === ''
                ? null
                : strcasecmp((string) ($telegram['username'] ?? ''), $expected) === 0;
        }

        $whatsapp = $this->whatsappConnectionTest();

        Cache::put('chatbot.connections.test', [
            'at' => now()->toIso8601String(),
            'telegram' => $telegram,
            'whatsapp' => $whatsapp,
        ], now()->addHour());

        $parts = [];

        if ($telegram['ok'] ?? false) {
            $parts[] = 'Telegram OK (@'.($telegram['username'] ?? '?').')';
        } else {
            $parts[] = 'Telegram gagal: '.($telegram['error'] ?? 'tidak diketahui');
        }

        if ($whatsapp['ok'] ?? false) {
            $parts[] = 'WhatsApp OK (nomor '.$whatsapp['display_phone'].')';
        } else {
            $parts[] = 'WhatsApp gagal: '.($whatsapp['error'] ?? 'tidak diketahui');
        }

        $allOk = ($telegram['ok'] ?? false) && ($whatsapp['ok'] ?? false);
        $anyOk = ($telegram['ok'] ?? false) || ($whatsapp['ok'] ?? false);

        $message = 'Uji koneksi: '.implode(' | ', $parts).'.';

        if ($allOk) {
            return back()->with('success', $message);
        }

        if ($anyOk) {
            return back()->with('error', $message);
        }

        return back()->with('error', $message);
    }

    /**
     * Uji koneksi WhatsApp (baca status perangkat/nomor — bukan pengiriman).
     *
     * Mengikuti provider aktif (WHATSAPP_PROVIDER):
     * - 'fonnte' -> FonnteService::device() ke api.fonnte.com/device.
     * - 'meta'   -> Graph API Meta (baca data nomor pengirim).
     *
     * @return array{ok: bool, display_phone?: string, provider?: string, connected?: int, error?: string}
     */
    private function whatsappConnectionTest(): array
    {
        $status = $this->notifications->channelStatus('whatsapp');
        $provider = (string) config('services.whatsapp.provider', 'meta');

        if (! $status['configured']) {
            return [
                'ok' => false,
                'provider' => $provider,
                'error' => 'konfigurasi belum lengkap ('.implode(', ', $status['missing']).')',
            ];
        }

        if ($provider === 'fonnte') {
            $device = app(FonnteService::class)->device();

            if (! $device['ok']) {
                return [
                    'ok' => false,
                    'provider' => $provider,
                    'error' => (string) ($device['error'] ?? 'gagal memeriksa perangkat Fonnte'),
                ];
            }

            return [
                'ok' => true,
                'provider' => $provider,
                'display_phone' => (string) ($device['device'] ?? '-'),
                'connected' => $device['connected'],
                'device_status' => $device['device_status'],
                'quota' => $device['quota'],
            ];
        }

        try {
            $version = (string) config('services.whatsapp.api_version', 'v21.0');
            $phoneNumberId = (string) config('services.whatsapp.phone_number_id');

            $response = Http::withToken((string) config('services.whatsapp.access_token'))
                ->timeout((int) config('notifications.http_timeout', 15))
                ->get('https://graph.facebook.com/'.$version.'/'.$phoneNumberId);

            $response->throw();

            return [
                'ok' => true,
                'provider' => $provider,
                'display_phone' => (string) ($response->json('display_phone_number') ?? $phoneNumberId),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'provider' => $provider,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ];
        }
    }

    /**
     * Status sistem untuk dashboard: pengantrean notifikasi, provider AI,
     * dan jumlah job yang gagal — tanpa menampilkan rahasia apa pun.
     *
     * @return array<string, mixed>
     */
    private function systemStatus(): array
    {
        $aiLast = Cache::get('ai.last_status');

        return [
            'queue_enabled' => (bool) config('notifications.queue'),
            'queue_connection' => (string) config('queue.default'),
            'failed_jobs' => $this->failedJobCount(),
            'ai_url' => (string) config('ai.url'),
            'ai_model' => (string) config('ai.model'),
            'ai_key_set' => filled(config('ai.key')),
            'ai_last' => is_array($aiLast) ? $aiLast : null,
        ];
    }

    /**
     * Jumlah job gagal pada antrean (tabel failed_jobs) — aman bila tabel
     * belum ada (database baru).
     */
    private function failedJobCount(): int
    {
        try {
            return (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * URL absolut untuk webhook yang didaftarkan ke penyedia luar.
     *
     * Bila WEBHOOK_PUBLIC_URL diisi (root HTTPS publik produksi), seluruh
     * URL webhook dibangun dari root itu — konsisten walau admin membuka
     * dashboard lewat host/IP lain. Tanpa konfigurasi, mengikuti host
     * request saat ini (perilaku lama; dipertahankan agar tes yang ada
     * tidak berubah).
     */
    private function publicUrl(string $routeName, array $parameters = []): string
    {
        $root = rtrim((string) config('app.webhook_public_url'), '/');

        if ($root !== '') {
            return $root.route($routeName, $parameters, false);
        }

        return route($routeName, $parameters);
    }

    /**
     * Data integrasi kanal chatbot untuk dashboard (tanpa rahasia).
     *
     * @return array<string, mixed>
     */
    private function integrationData(): array
    {
        $test = Cache::get('chatbot.connections.test');
        $telegramStatus = $this->notifications->channelStatus('telegram');
        $whatsappStatus = $this->notifications->channelStatus('whatsapp');
        $delivery = Cache::get('chatbot.webhook.last_whatsapp_status');

        return [
            'whatsapp' => [
                'enabled' => (bool) config('services.whatsapp.enabled'),
                'configured' => $whatsappStatus['configured'],
                'missing' => $whatsappStatus['missing'],
                'provider' => (string) config('services.whatsapp.provider', 'meta'),
                // Nomor penerima notifikasi admin ditampilkan apa adanya
                // (konfigurasi milik admin sendiri, hanya terlihat oleh admin
                // yang login) — penerima lain pada riwayat tetap tersamarkan.
                'admin_number' => (string) config('notifications.admin_phone'),
                'message_mode' => (string) config('services.whatsapp.message_mode'),
                'webhook_url' => $this->publicUrl('webhooks.whatsapp.receive'),
                'verify_url' => $this->publicUrl('webhooks.whatsapp.verify'),
                // URL webhook Fonnte (secret di path) — hanya untuk provider
                // fonnte; dipakai admin saat mengatur Webhook URL di dashboard
                // Fonnte. Di produksi dibangun dari WEBHOOK_PUBLIC_URL/APP_URL
                // (HTTPS publik) — bukan host yang sedang dibuka admin.
                'fonnte_webhook_url' => filled(config('services.fonnte.webhook_secret'))
                    ? $this->publicUrl('webhooks.fonnte.receive', [
                        'token' => (string) config('services.fonnte.webhook_secret'),
                    ])
                    : null,
                'last_webhook_at' => Cache::get('chatbot.webhook.last_whatsapp'),
                'test' => is_array($test) ? ($test['whatsapp'] ?? null) : null,
                'last_sent' => $this->latestChannelLog('whatsapp'),
                'last_delivery' => is_array($delivery) ? $delivery : null,
            ],
            'telegram' => [
                'enabled' => (bool) config('services.telegram.enabled'),
                'configured' => $telegramStatus['configured'],
                'missing' => $telegramStatus['missing'],
                'bot_username' => filled(config('services.telegram.bot_username'))
                    ? '@'.config('services.telegram.bot_username')
                    : null,
                'chat_id' => config('services.telegram.admin_chat_id')
                    ?: config('services.telegram.chat_id'),
                'webhook_secret_set' => filled(config('services.telegram.webhook_secret')),
                'webhook_url' => $this->publicUrl('webhooks.telegram.receive'),
                'last_webhook_at' => Cache::get('chatbot.webhook.last_telegram'),
                'test' => is_array($test) ? ($test['telegram'] ?? null) : null,
                'last_sent' => $this->latestChannelLog('telegram'),
            ],
            'test_at' => is_array($test) ? ($test['at'] ?? null) : null,
            'last_sent_at' => NotificationLog::query()
                ->where('status', NotificationLog::STATUS_SENT)
                ->max('sent_at'),
            'linked_accounts' => ChannelIdentity::query()
                ->where('status', ChannelIdentity::STATUS_VERIFIED)
                ->count(),
        ];
    }

    /**
     * Pengiriman terakhir pada satu saluran untuk panel status dashboard.
     *
     * @return array{at: ?string, status: ?string, subject: ?string, error: ?string}|null
     */
    private function latestChannelLog(string $channel): ?array
    {
        $log = NotificationLog::query()
            ->where('channel', $channel)
            ->latestFirst()
            ->first(['status', 'sent_at', 'created_at', 'subject', 'error']);

        if ($log === null) {
            return null;
        }

        return [
            'at' => (string) ($log->sent_at ?? $log->created_at),
            'status' => (string) $log->status,
            'subject' => (string) $log->subject,
            'error' => $log->error !== null ? (string) $log->error : null,
        ];
    }

    /**
     * Periksa Status API WhatsApp (endpoint JSON untuk tombol dashboard).
     *
     * Menggabungkan: status kredensial (.env), uji koneksi langsung ke Graph
     * API (hanya baca — tidak mengirim pesan), pengiriman terakhir, status
     * pengiriman terakhir dari callback Meta, dan webhook terakhir.
     */
    public function whatsappApiStatus(): JsonResponse
    {
        $status = $this->notifications->channelStatus('whatsapp');

        return response()->json([
            'configured' => $status['configured'],
            'missing' => $status['missing'],
            'provider' => (string) config('services.whatsapp.provider', 'meta'),
            'enabled' => (bool) config('services.whatsapp.enabled'),
            'admin_number' => (string) config('notifications.admin_phone'),
            'message_mode' => (string) config('services.whatsapp.message_mode'),
            'connection' => $this->whatsappConnectionTest(),
            'last_sent' => $this->latestChannelLog('whatsapp'),
            'last_delivery' => is_array($delivery = Cache::get('chatbot.webhook.last_whatsapp_status'))
                ? $delivery
                : null,
            'last_webhook_at' => Cache::get('chatbot.webhook.last_whatsapp'),
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
