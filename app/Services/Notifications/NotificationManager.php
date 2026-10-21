<?php

namespace App\Services\Notifications;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationLog;
use App\Models\NotificationSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationManager
{
    /**
     * Cache pengaturan dalam satu kali dispatch (menghindari query berulang).
     *
     * @var array<string, bool>|null
     */
    private ?array $settings = null;

    public function __construct(private readonly NotificationMessageFactory $messages) {}

    /**
     * Kirim notifikasi untuk satu kejadian ke seluruh audiens & saluran aktif.
     *
     * Aturan keamanan:
     * - Tidak pernah memanggil API bila saluran belum dikonfigurasi.
     * - Duplikat dicegah (kejadian + saluran + penerima + referensi dalam jendela).
     * - Kegagalan hanya dicatat pada log — tidak pernah membatalkan alur bisnis.
     * - Pekerjaan dikirim setelah commit transaksi (afterCommit).
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, NotificationLog> log yang berhasil dibuat
     */
    public function dispatch(string $eventKey, array $data): Collection
    {
        // Seluruh pemeriksaan & pembuatan log ditunda sampai transaksi commit
        // (atau dijalankan langsung bila tidak ada transaksi aktif):
        // - Pesan dibangun dari data yang sudah ter-commit (relasi fresh).
        // - Transaksi yang rollback tidak meninggalkan log & tidak mengirim apa pun.
        // - Pekerjaan tetap diantrikan setelah commit via afterCommit pada job.
        $logs = collect();

        // Hasil ditangkap lewat referensi: bila transaksi manajer mengeksekusi
        // callback secara langsung (tanpa transaksi nyata, atau transaksi tes
        // RefreshDatabase yang diabaikan), nilai balik log ikut tersedia —
        // dipakai ringkasan harian untuk melaporkan jumlah pengiriman.
        // Bila ditunda sampai commit (transaksi produksi), $logs tetap kosong.
        DB::afterCommit(function () use ($eventKey, $data, &$logs): void {
            try {
                $logs = $this->dispatchNow($eventKey, $data);
            } catch (\Throwable $e) {
                // Kegagalan penyusunan notifikasi tidak pernah merusak alur bisnis,
                // tetapi TETAP dicatat agar admin tahu bila notifikasi tersendat
                // (tanpa log, kegagalan total tidak pernah terlihat siapa pun).
                Log::warning('notification.dispatch_failed', [
                    'event' => $eventKey,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            }
        });

        return $logs;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return Collection<int, NotificationLog>
     */
    private function dispatchNow(string $eventKey, array $data): Collection
    {
        $logs = collect();

        if (! config('notifications.enabled')) {
            return $logs;
        }

        // Catatan: kunci kejadian mengandung titik ('order.new') sehingga
        // diakses sebagai array — bukan dot-notation config().
        $event = config('notifications.events')[$eventKey] ?? null;

        if (! is_array($event) || empty($event['audiences'])) {
            return $logs;
        }

        $reference = $this->referenceFor($data);
        $order = $data['order'] ?? $data['payment']?->order ?? null;
        $orderId = $order?->id ?? $data['payment']?->order_id ?? null;
        $window = (int) ($event['window'] ?? config('notifications.dedupe_window', 360));

        foreach ($this->recipients($event['audiences'], $data) as $channel => $targets) {
            if (! $this->channelEventActive($channel, $eventKey)) {
                continue;
            }

            // Saluran yang kredensialnya belum diisi dilewati dengan aman —
            // tidak ada log, tidak ada panggilan API.
            if (! $this->channelStatus($channel)['configured']) {
                continue;
            }

            $seen = [];

            foreach ($targets as $target) {
                $recipient = $target['to'];
                $audience = $target['audience'];

                if ($recipient === null || $recipient === '') {
                    continue;
                }

                // Hindari penerima ganda dalam satu saluran untuk kejadian yang sama.
                if ($seen[(string) $recipient] ?? false) {
                    continue;
                }

                $seen[(string) $recipient] = true;

                if ($this->recentlyNotified($eventKey, $channel, (string) $recipient, $reference, $window)) {
                    continue;
                }

                // Pesan disusun per penerima agar tautan detail sesuai perannya.
                $message = $this->messages->make($eventKey, $data, $audience);

                $log = NotificationLog::create([
                    'event_key' => $eventKey,
                    'channel' => $channel,
                    'recipient' => mb_substr((string) $recipient, 0, 190),
                    'subject' => mb_substr($message['subject'], 0, 150),
                    'body' => mb_substr($message['body'], 0, 4000),
                    'status' => NotificationLog::STATUS_PENDING,
                    'reference' => $reference,
                    'order_id' => $orderId,
                ]);

                $logs->push($log);

                $this->queue($log);
            }
        }

        return $logs;
    }

    /**
     * Kirim pesan uji dari halaman pengaturan admin.
     *
     * Berjalan menyinkronkan agar admin langsung melihat hasil sebenarnya
     * (terkirim / gagal), bukan status antrean.
     *
     * @return array{ok: bool, status: string, error: ?string, log: ?NotificationLog}
     */
    public function sendTest(string $channel, ?string $message = null, ?string $recipient = null): array
    {
        if (! array_key_exists($channel, config('notifications.channels', []))) {
            return ['ok' => false, 'status' => 'failed', 'error' => 'Saluran notifikasi tidak dikenali.', 'log' => null];
        }

        if (! config('notifications.enabled')) {
            return $this->recordFailure(
                $channel,
                '-',
                'Notifikasi dinonaktifkan secara global (NOTIFICATIONS_ENABLED=false pada .env).'
            );
        }

        // Status konfigurasi diperiksa lebih dahulu agar pesan error menyebut
        // nama variabel .env yang belum diisi.
        $status = $this->channelStatus($channel);

        if (! $status['configured']) {
            return $this->recordFailure(
                $channel,
                '-',
                'Konfigurasi belum lengkap. Variabel yang kurang: '.implode(', ', $status['missing']).'.'
            );
        }

        $target = filled($recipient) ? trim((string) $recipient) : $this->defaultRecipient($channel);

        if (blank($target)) {
            return $this->recordFailure(
                $channel,
                '-',
                'Tujuan uji kirim belum dikonfigurasi untuk saluran '.$channel.'.'
            );
        }

        $invalid = $this->invalidRecipientReason($channel, $target);

        if ($invalid !== null) {
            return $this->recordFailure($channel, $target, $invalid);
        }

        $log = NotificationLog::create([
            'event_key' => 'test',
            'channel' => $channel,
            'recipient' => mb_substr($target, 0, 190),
            'subject' => mb_substr('Uji Kirim Notifikasi - '.config('app.name'), 0, 150),
            'body' => filled($message)
                ? mb_substr(trim((string) $message), 0, 4000)
                : 'Pesan uji dari '.config('app.name').'. Jika Anda menerima ini, saluran notifikasi berfungsi.',
            'status' => NotificationLog::STATUS_PENDING,
            'reference' => null,
        ]);

        // Uji kirim selalu disinkronkan: hasilnya harus langsung terlihat oleh admin.
        SendNotificationJob::dispatchSync($log->id);
        $log->refresh();

        return [
            'ok' => $log->status === NotificationLog::STATUS_SENT,
            'status' => $log->status,
            'error' => $log->error,
            'log' => $log,
        ];
    }

    /**
     * Status konfigurasi sebuah saluran (tanpa menampilkan nilai rahasia).
     *
     * @return array{configured: bool, missing: list<string>, mode: ?string}
     */
    public function channelStatus(string $channel): array
    {
        $missing = match ($channel) {
            'telegram' => $this->missingTelegram(),
            'whatsapp' => $this->missingWhatsApp(),
            'email' => $this->missingEmail(),
            default => ['SALURAN_TIDAK_DIKENALI'],
        };

        $mode = $channel === 'email' ? config('mail.default') : null;

        return [
            'configured' => $missing === [],
            'missing' => $missing,
            'mode' => $mode,
        ];
    }

    /**
     * Tujuan bawaan uji kirim per saluran (kontak admin dari .env).
     */
    public function defaultRecipient(string $channel): ?string
    {
        return match ($channel) {
            'email' => config('notifications.admin_email'),
            'whatsapp' => config('notifications.admin_phone'),
            'telegram' => config('services.telegram.admin_chat_id')
                ?: config('services.telegram.chat_id'),
            default => null,
        };
    }

    /**
     * Antrikan (atau jalankan) pekerjaan pengiriman log setelah commit transaksi.
     */
    private function queue(NotificationLog $log): void
    {
        if (config('notifications.queue')) {
            SendNotificationJob::dispatch($log->id)->afterCommit();
        } else {
            SendNotificationJob::dispatch($log->id)->onConnection('sync')->afterCommit();
        }
    }

    /**
     * Catat kegagalan uji kirim sebagai log riwayat.
     *
     * @return array{ok: bool, status: string, error: ?string, log: NotificationLog}
     */
    private function recordFailure(string $channel, string $recipient, string $error): array
    {
        $log = NotificationLog::create([
            'event_key' => 'test',
            'channel' => $channel,
            'recipient' => mb_substr($recipient, 0, 190),
            'subject' => mb_substr('Uji Kirim Notifikasi - '.config('app.name'), 0, 150),
            'body' => null,
            'status' => NotificationLog::STATUS_FAILED,
            'error' => mb_substr($error, 0, 500),
            'reference' => null,
        ]);

        return ['ok' => false, 'status' => $log->status, 'error' => $log->error, 'log' => $log];
    }

    /**
     * Alasan bila format tujuan tidak valid untuk saluran terkait (null = valid).
     */
    private function invalidRecipientReason(string $channel, string $target): ?string
    {
        return match ($channel) {
            'email' => filter_var($target, FILTER_VALIDATE_EMAIL)
                ? null
                : 'Alamat email tujuan tidak valid: "'.$target.'".',
            'whatsapp' => WhatsAppNotificationService::normalizeInternational($target) !== null
                ? null
                : 'Nomor WhatsApp tidak valid: "'.$target.'" (contoh format: 089697358716 atau 6289697358716).',
            'telegram' => preg_match('/^-?\d{5,20}$/', $target)
                ? null
                : 'Chat ID Telegram tidak valid: "'.$target.'" (harus angka dari getUpdates, bukan nomor telepon).',
            default => null,
        };
    }

    /**
     * Susun daftar penerima per saluran berdasarkan audiens kejadian.
     *
     * Setiap target memuat 'to' (tujuan) dan 'audience' (peran penerima)
     * agar pesan & tautan detail dapat disusun sesuai peran masing-masing.
     *
     * @param  list<string>  $audiences
     * @param  array<string, mixed>  $data
     * @return array<string, list<array{to: string|null, audience: string}>>
     */
    private function recipients(array $audiences, array $data): array
    {
        $targets = ['telegram' => [], 'whatsapp' => [], 'email' => []];
        $audiences = array_values(array_unique($audiences));

        $order = $data['order'] ?? $data['payment']?->order ?? null;
        $customer = $data['customer'] ?? $data['user'] ?? $order?->user;

        if (in_array('admin', $audiences, true)) {
            $targets['email'][] = ['to' => config('notifications.admin_email'), 'audience' => 'admin'];
            $targets['whatsapp'][] = ['to' => config('notifications.admin_phone'), 'audience' => 'admin'];
            $targets['telegram'][] = [
                'to' => config('services.telegram.admin_chat_id') ?: config('services.telegram.chat_id'),
                'audience' => 'admin',
            ];
        }

        if (in_array('customer', $audiences, true) && $customer instanceof User) {
            $targets['email'][] = ['to' => $customer->email, 'audience' => 'customer'];

            $targets['whatsapp'][] = [
                'to' => $customer->phone
                    ?: $customer->addresses()->orderByDesc('is_primary')->value('phone'),
                'audience' => 'customer',
            ];
        }

        if (in_array('seller', $audiences, true)) {
            // 'seller' = kunci audiens internal; role kanonik di DB: anggota
            // (mencakup nama legacy penjual/seller agar data lama tetap aman).
            $roleIds = Role::whereIn('name', ['anggota', 'penjual', 'seller'])->pluck('id');

            $sellers = User::query()
                ->whereIn('role_id', $roleIds)
                ->where('is_active', true)
                ->get(['id', 'email', 'phone']);

            foreach ($sellers as $seller) {
                $targets['email'][] = ['to' => $seller->email, 'audience' => 'seller'];

                if ($seller->phone) {
                    $targets['whatsapp'][] = ['to' => $seller->phone, 'audience' => 'seller'];
                }
            }
        }

        if (in_array('courier', $audiences, true)) {
            $courier = $data['courier'] ?? ($data['shipment'] ?? null)?->courier;

            if ($courier instanceof User) {
                $targets['email'][] = ['to' => $courier->email, 'audience' => 'courier'];

                if ($courier->phone) {
                    $targets['whatsapp'][] = ['to' => $courier->phone, 'audience' => 'courier'];
                }
            }
        }

        return $targets;
    }

    /**
     * Referensi anti-duplikat untuk sebuah konteks kejadian.
     *
     * @param  array<string, mixed>  $data
     */
    private function referenceFor(array $data): ?string
    {
        if (isset($data['reference'])) {
            return (string) $data['reference'];
        }

        if (isset($data['order'])) {
            return 'order:'.$data['order']->id;
        }

        if (isset($data['payment'])) {
            return 'payment:'.$data['payment']->id;
        }

        if (isset($data['product'])) {
            return 'product:'.$data['product']->id;
        }

        if (isset($data['user'])) {
            return 'user:'.$data['user']->id;
        }

        if (isset($data['shipment'])) {
            return 'order:'.$data['shipment']->order_id;
        }

        return null;
    }

    /**
     * Cek apakah kejadian ini sudah pernah dikirim ke penerima yang sama
     * dalam jendela waktu (mencegah notifikasi ganda).
     */
    private function recentlyNotified(
        string $eventKey,
        string $channel,
        string $recipient,
        ?string $reference,
        int $windowMinutes,
    ): bool {
        return NotificationLog::query()
            ->where('event_key', $eventKey)
            ->where('channel', $channel)
            ->where('recipient', $recipient)
            ->when(
                $reference !== null,
                fn ($query) => $query->where('reference', $reference),
                fn ($query) => $query->whereNull('reference')
            )
            ->whereIn('status', [NotificationLog::STATUS_PENDING, NotificationLog::STATUS_SENT])
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->exists();
    }

    /**
     * Saluran & kejadian sama-sama aktif (saklar global sudah dicek di dispatch).
     */
    private function channelEventActive(string $channel, string $eventKey): bool
    {
        return $this->settingEnabled('channel', $channel)
            && $this->settingEnabled('event', $eventKey);
    }

    /**
     * Status aktif pengaturan dengan cache per-instance.
     */
    private function settingEnabled(string $group, string $key): bool
    {
        if ($this->settings === null) {
            $this->settings = NotificationSetting::query()
                ->get()
                ->mapWithKeys(fn (NotificationSetting $setting) => [
                    $setting->group.'|'.$setting->key => (bool) $setting->enabled,
                ])
                ->all();
        }

        return $this->settings[$group.'|'.$key] ?? true;
    }

    /**
     * Variabel .env yang belum diisi untuk saluran Telegram.
     *
     * @return list<string>
     */
    private function missingTelegram(): array
    {
        $missing = [];

        if (blank(config('services.telegram.bot_token'))) {
            $missing[] = 'TELEGRAM_BOT_TOKEN';
        }

        if (blank(config('services.telegram.admin_chat_id')) && blank(config('services.telegram.chat_id'))) {
            $missing[] = 'TELEGRAM_ADMIN_CHAT_ID';
        }

        return $missing;
    }

    /**
     * Variabel .env yang belum diisi untuk saluran WhatsApp.
     *
     * @return list<string>
     */
    private function missingWhatsApp(): array
    {
        $missing = [];

        if (blank(config('services.whatsapp.access_token'))) {
            $missing[] = 'WHATSAPP_ACCESS_TOKEN';
        }

        if (blank(config('services.whatsapp.phone_number_id'))) {
            $missing[] = 'WHATSAPP_PHONE_NUMBER_ID';
        }

        if (config('services.whatsapp.message_mode') === 'template'
            && blank(config('services.whatsapp.template_name'))) {
            $missing[] = 'WHATSAPP_TEMPLATE_NAME';
        }

        if (blank(config('notifications.admin_phone'))) {
            $missing[] = 'WHATSAPP_ADMIN_PHONE';
        }

        return $missing;
    }

    /**
     * Variabel .env yang belum diisi untuk saluran Email.
     *
     * @return list<string>
     */
    private function missingEmail(): array
    {
        $missing = [];

        if (blank(config('mail.default'))) {
            $missing[] = 'MAIL_MAILER';
        } elseif (config('mail.default') === 'smtp') {
            if (blank(config('mail.mailers.smtp.host'))) {
                $missing[] = 'MAIL_HOST';
            }

            if (blank(config('mail.mailers.smtp.username'))) {
                $missing[] = 'MAIL_USERNAME';
            }
        }

        if (blank(config('mail.from.address'))) {
            $missing[] = 'MAIL_FROM_ADDRESS';
        }

        if (blank(config('notifications.admin_email'))) {
            $missing[] = 'NOTIFICATION_ADMIN_EMAIL';
        }

        return $missing;
    }
}
