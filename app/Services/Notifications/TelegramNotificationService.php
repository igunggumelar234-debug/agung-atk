<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramNotificationService
{
    /**
     * Kirim pesan teks ke satu Chat ID melalui Telegram Bot API.
     *
     * @throws RuntimeException bila token belum diisi, Chat ID tidak valid,
     *                          atau API Telegram mengembalikan kegagalan.
     */
    public function send(string $chatId, string $text): void
    {
        $token = config('services.telegram.bot_token');

        if (blank($token)) {
            throw new RuntimeException('Konfigurasi TELEGRAM_BOT_TOKEN belum diisi pada .env.');
        }

        $chatId = trim($chatId);

        // Nomor telepon BUKAN Chat ID — tolak sebelum memanggil API
        // agar tidak mengirim permintaan yang pasti gagal.
        if (! preg_match('/^-?\d{5,20}$/', $chatId)) {
            throw new RuntimeException(
                'Chat ID Telegram tidak valid ("'.$chatId.'"). '
                .'Gunakan angka dari getUpdates, bukan nomor telepon.'
            );
        }

        $response = $this->client($token)
            ->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ]);

        $response->throw();

        if (! $response->json('ok', false)) {
            throw new RuntimeException(
                'Telegram menolak pesan: '.($response->json('description') ?? 'tidak diketahui')
            );
        }
    }

    /**
     * Ambil Chat ID dari pesan terbaru yang masuk ke bot (getUpdates).
     *
     * Dipakai tombol bantuan halaman pengaturan: pengguna mengirim pesan
     * "/start" ke bot, lalu tombol ini mengambil chat id-nya.
     *
     * @return array{ok: bool, chat_id?: string, name?: string, username?: string, error?: string}
     */
    public function resolveChatId(): array
    {
        $token = config('services.telegram.bot_token');

        if (blank($token)) {
            return [
                'ok' => false,
                'error' => 'Isi TELEGRAM_BOT_TOKEN pada .env terlebih dahulu (dapatkan dari @BotFather).',
            ];
        }

        try {
            $response = $this->client($token)
                ->get('https://api.telegram.org/bot'.$token.'/getUpdates');

            $response->throw();

            $updates = collect($response->json('result', []));
            $latest = $updates
                ->filter(fn (array $update) => isset($update['message']['chat']['id']))
                ->last();

            if ($latest === null) {
                return [
                    'ok' => false,
                    'error' => 'Belum ada pesan masuk. Kirim pesan "/start" ke bot Anda, lalu coba lagi.',
                ];
            }

            $chat = $latest['message']['chat'];

            return [
                'ok' => true,
                'chat_id' => (string) $chat['id'],
                'name' => trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')),
                'username' => $chat['username'] ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Gagal menghubungi Telegram: '.mb_substr($e->getMessage(), 0, 300),
            ];
        }
    }

    /**
     * Client HTTP dengan token, timeout, dan retry terbatas.
     */
    private function client(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->timeout((int) config('notifications.http_timeout', 15))
            ->retry(2, 250);
    }
}
