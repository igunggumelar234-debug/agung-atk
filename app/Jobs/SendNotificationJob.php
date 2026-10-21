<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\Notifications\EmailNotificationService;
use App\Services\Notifications\TelegramNotificationService;
use App\Services\Notifications\WhatsAppNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    /**
     * Batas percobaan bila terjadi kegagalan tak terduga (mis. database).
     * Kegagalan pengiriman yang sudah ditangani tidak diulang otomatis.
     */
    public int $tries = 3;

    /**
     * Antrean mundur antar percobaan (detik).
     *
     * @var list<int>
     */
    public array $backoff = [10, 30];

    /**
     * Create a new class instance.
     */
    public function __construct(private readonly int $logId) {}

    /**
     * Kirim satu baris log notifikasi sesuai salurannya.
     *
     * Seluruh kegagalan ditangkap di sini sehingga kegagalan notifikasi
     * tidak pernah merusak alur pesanan/pembayaran yang sudah tersimpan.
     */
    public function handle(): void
    {
        $log = NotificationLog::find($this->logId);

        // Lewati bila log sudah diproses (perlindungan terhadap dispatch ganda).
        if ($log === null || $log->status !== NotificationLog::STATUS_PENDING) {
            return;
        }

        try {
            if (config('notifications.mock')) {
                // Mode mock: tidak memanggil API luar apa pun.
                $log->forceFill([
                    'status' => NotificationLog::STATUS_SENT,
                    'sent_at' => now(),
                    'error' => null,
                ])->save();

                return;
            }

            match ($log->channel) {
                'telegram' => app(TelegramNotificationService::class)
                    ->send($log->recipient, (string) $log->body),
                'whatsapp' => app(WhatsAppNotificationService::class)
                    ->send($log->recipient, (string) $log->body),
                'email' => app(EmailNotificationService::class)
                    ->send($log->recipient, (string) $log->subject, (string) $log->body),
                default => throw new \RuntimeException('Saluran notifikasi tidak dikenali: '.$log->channel),
            };

            $log->forceFill([
                'status' => NotificationLog::STATUS_SENT,
                'sent_at' => now(),
                'error' => null,
            ])->save();
        } catch (\Throwable $e) {
            $log->forceFill([
                'status' => NotificationLog::STATUS_FAILED,
                'error' => mb_substr($e->getMessage() !== '' ? $e->getMessage() : class_basename($e), 0, 500),
            ])->save();
        }
    }

    /**
     * Tandai log gagal bila pekerjaan gagal total (mis. database bermasalah).
     */
    public function failed(?\Throwable $e): void
    {
        NotificationLog::query()
            ->whereKey($this->logId)
            ->where('status', NotificationLog::STATUS_PENDING)
            ->update([
                'status' => NotificationLog::STATUS_FAILED,
                'error' => mb_substr($e?->getMessage() ?? 'Pekerjaan pengiriman notifikasi gagal total.', 0, 500),
            ]);
    }
}
