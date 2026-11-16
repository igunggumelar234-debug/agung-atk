<?php

namespace App\Jobs;

use App\Models\ChannelIdentity;
use App\Models\NotificationLog;
use App\Services\Notifications\TelegramNotificationService;
use App\Services\Notifications\WhatsAppNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Kirim balasan chatbot ke kanal asal (WhatsApp/Telegram) dan catat statusnya.
 *
 * Terpisah dari SendNotificationJob karena balasan percakapan bukan
 * notifikasi: saklar NOTIFICATIONS_ENABLED tidak mematikannya.
 */
class SendChannelReplyJob implements ShouldQueue
{
    use Queueable;

    /**
     * Batas percobaan untuk kegagalan tak terduga (mis. database).
     * Kegagalan pengiriman API sudah ditangani di handle() tanpa retry.
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
    public function __construct(
        private readonly string $channel,
        private readonly string $identifier,
        private readonly string $text,
    ) {}

    /**
     * Kirim satu balasan chatbot dan perbarui log riwayatnya.
     *
     * Seluruh kegagalan ditangkap agar webhook tidak pernah gagal
     * karena masalah pengiriman balasan.
     */
    public function handle(TelegramNotificationService $telegram, WhatsAppNotificationService $whatsapp): void
    {
        // Batas aman: Telegram membatasi pesan 4096 karakter.
        $text = mb_substr($this->text, 0, 4000);

        $log = NotificationLog::create([
            'event_key' => 'chatbot.reply',
            'channel' => $this->channel,
            'recipient' => mb_substr($this->identifier, 0, 190),
            'subject' => mb_substr('Balasan Chatbot - '.config('app.name'), 0, 150),
            'body' => $text,
            'status' => NotificationLog::STATUS_PENDING,
            'reference' => null,
        ]);

        try {
            if (config('notifications.mock')) {
                $log->forceFill([
                    'status' => NotificationLog::STATUS_SENT,
                    'sent_at' => now(),
                    'error' => null,
                ])->save();

                return;
            }

            match ($this->channel) {
                ChannelIdentity::CHANNEL_TELEGRAM => $telegram->send($this->identifier, $text),
                ChannelIdentity::CHANNEL_WHATSAPP => $whatsapp->send($this->identifier, $text),
                default => throw new \RuntimeException('Kanal balasan tidak dikenali: '.$this->channel),
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

            Log::warning('chatbot.reply_failed', [
                'channel' => $this->channel,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tandai log gagal bila pekerjaan gagal total (mis. database bermasalah).
     */
    public function failed(?\Throwable $e): void
    {
        NotificationLog::query()
            ->where('event_key', 'chatbot.reply')
            ->where('channel', $this->channel)
            ->where('recipient', $this->identifier)
            ->where('status', NotificationLog::STATUS_PENDING)
            ->update([
                'status' => NotificationLog::STATUS_FAILED,
                'error' => mb_substr($e?->getMessage() ?? 'Pekerjaan balasan chatbot gagal total.', 0, 500),
            ]);
    }
}
