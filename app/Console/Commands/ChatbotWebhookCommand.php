<?php

namespace App\Console\Commands;

use App\Services\Notifications\TelegramNotificationService;
use Illuminate\Console\Command;

/**
 * Kelola webhook chatbot: pemasangan/cabut webhook Telegram dan
 * menampilkan status URL webhook WhatsApp & Telegram.
 *
 * Contoh:
 *   php artisan chatbot:webhook status
 *   php artisan chatbot:webhook set            (URL default: route webhook Telegram)
 *   php artisan chatbot:webhook set --url=https://domain-anda.com/webhooks/telegram
 *   php artisan chatbot:webhook remove
 */
class ChatbotWebhookCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chatbot:webhook
        {action : Pilihan tindakan (set, remove, status)}
        {--url= : URL webhook Telegram pengganti (bawaan: route webhook aplikasi)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Atur & status webhook chatbot WhatsApp/Telegram (set, remove, status)';

    /**
     * Execute the console command.
     */
    public function handle(TelegramNotificationService $telegram): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'status' => $this->status($telegram),
            'set' => $this->set($telegram),
            'remove' => $this->remove($telegram),
            default => $this->invalidAction($action),
        };
    }

    private function invalidAction(string $action): int
    {
        $this->error('Tindakan tidak dikenali: "'.$action.'". Gunakan: set, remove, atau status.');

        return self::FAILURE;
    }

    private function status(TelegramNotificationService $telegram): int
    {
        $this->info('Status webhook chatbot:');

        $telegramUrl = route('webhooks.telegram.receive');
        $whatsappVerify = route('webhooks.whatsapp.verify');
        $whatsappReceive = route('webhooks.whatsapp.receive');

        $this->table(
            ['Kanal', 'URL', 'Konfigurasi'],
            [
                ['Telegram (masuk)', $telegramUrl, config('services.telegram.enabled') && config('services.telegram.webhook_secret') ? 'saklar aktif & secret terisi' : 'periksa TELEGRAM_ENABLED / TELEGRAM_WEBHOOK_SECRET'],
                ['WhatsApp (masuk)', $whatsappReceive, config('services.whatsapp.enabled') && config('services.whatsapp.verify_token') ? 'saklar aktif & verify token terisi' : 'periksa WHATSAPP_ENABLED / WHATSAPP_VERIFY_TOKEN'],
                ['WhatsApp (verifikasi)', $whatsappVerify, 'dipakai Meta saat mendaftarkan webhook'],
            ]
        );

        $info = $telegram->webhookInfo();

        if (! ($info['ok'] ?? false)) {
            $this->warn('Info webhook Telegram tidak tersedia: '.($info['error'] ?? 'tidak diketahui'));

            return self::FAILURE;
        }

        if (blank($info['url'] ?? null)) {
            $this->warn('Webhook Telegram BELUM dipasang. Jalankan: php artisan chatbot:webhook set');

            return self::SUCCESS;
        }

        $this->info('Webhook Telegram terpasang: '.$info['url']);
        $this->line('Update antre: '.($info['pending'] ?? 0));

        if (! empty($info['last_error'])) {
            $this->warn('Galat terakhir Telegram: '.$info['last_error']);
        }

        return self::SUCCESS;
    }

    private function set(TelegramNotificationService $telegram): int
    {
        $url = (string) ($this->option('url') ?: route('webhooks.telegram.receive'));
        $secret = (string) config('services.telegram.webhook_secret', '');

        if (blank($secret)) {
            $this->error('TELEGRAM_WEBHOOK_SECRET belum diisi pada .env — generate dulu (mis. php artisan tinker --execute "echo bin2hex(random_bytes(32));").');

            return self::FAILURE;
        }

        $result = $telegram->setWebhook($url, $secret);

        if (! ($result['ok'] ?? false)) {
            $this->error('Gagal memasang webhook: '.($result['error'] ?? 'tidak diketahui'));

            return self::FAILURE;
        }

        $this->info('Webhook Telegram terpasang: '.$url);

        return self::SUCCESS;
    }

    private function remove(TelegramNotificationService $telegram): int
    {
        $result = $telegram->deleteWebhook();

        if (! ($result['ok'] ?? false)) {
            $this->error('Gagal mencabut webhook: '.($result['error'] ?? 'tidak diketahui'));

            return self::FAILURE;
        }

        $this->info('Webhook Telegram dicabut.');

        return self::SUCCESS;
    }
}
