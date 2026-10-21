<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppNotificationService
{
    /**
     * Kirim pesan notifikasi WhatsApp melalui WhatsApp Business Cloud API resmi.
     *
     * Mode pesan (WHATSAPP_MESSAGE_MODE):
     * - 'text'     -> pesan teks bebas (percakapan yang sudah terbuka / uji coba).
     * - 'template' -> template pesan yang sudah disetujui Meta (wajib untuk
     *                 memulai percakapan baru ke pengguna). Baris isi pesan
     *                 dikirim sebagai parameter {{1}}, {{2}}, ... template.
     *
     * @throws RuntimeException bila kredensial belum lengkap, nomor tidak
     *                          valid, atau API WhatsApp gagal.
     */
    public function send(string $to, string $text): void
    {
        $token = config('services.whatsapp.access_token');
        $phoneNumberId = config('services.whatsapp.phone_number_id');

        if (blank($token)) {
            throw new RuntimeException('Konfigurasi WHATSAPP_ACCESS_TOKEN belum diisi pada .env.');
        }

        if (blank($phoneNumberId)) {
            throw new RuntimeException('Konfigurasi WHATSAPP_PHONE_NUMBER_ID belum diisi pada .env.');
        }

        $phone = static::normalizeInternational($to);

        if ($phone === null) {
            throw new RuntimeException(
                'Nomor WhatsApp tidak valid atau tidak didukung: "'.$to.'" '
                .'(gunakan format seperti 089697358716, +6289697358716, atau 6289697358716).'
            );
        }

        $payload = array_merge(
            ['messaging_product' => 'whatsapp', 'to' => $phone],
            $this->messagePayload($text)
        );

        $version = config('services.whatsapp.api_version') ?: 'v21.0';

        $response = $this->client($token)
            ->post('https://graph.facebook.com/'.$version.'/'.$phoneNumberId.'/messages', $payload);

        // Melempar exception untuk status HTTP error (401/429/500 dst.) beserta detailnya.
        $response->throw();

        if (is_array($response->json('error'))) {
            throw new RuntimeException(
                'WhatsApp menolak pesan: '.($response->json('error.message') ?? 'tidak diketahui')
            );
        }
    }

    /**
     * Validasi & normalisasi nomor telepon ke format internasional tanpa '+'.
     *
     * Format Indonesia: 089697358716 -> 6289697358716.
     * Mengembalikan null bila nomor tidak valid.
     */
    public static function normalizeInternational(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);
        $digits = preg_replace('/\D+/', '', $trimmed);

        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($trimmed, '+')) {
            $number = $digits;
        } elseif (str_starts_with($digits, '0')) {
            $number = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '62')) {
            $number = $digits;
        } elseif (str_starts_with($digits, '8')) {
            $number = '62'.$digits;
        } else {
            $number = $digits;
        }

        // Panjang wajib internasional 9–15 digit (E.164) dan tidak boleh nol di depan.
        if (strlen($number) < 9 || strlen($number) > 15 || ! preg_match('/^[1-9]\d{8,14}$/', $number)) {
            return null;
        }

        return $number;
    }

    /**
     * Susun bagian pesan sesuai mode (template atau teks bebas).
     *
     * @return array<string, mixed>
     */
    private function messagePayload(string $text): array
    {
        $mode = config('services.whatsapp.message_mode', 'text');

        if ($mode !== 'template') {
            return [
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $text,
                ],
            ];
        }

        $templateName = config('services.whatsapp.template_name');

        if (blank($templateName)) {
            throw new RuntimeException(
                'WHATSAPP_MESSAGE_MODE=template tetapi WHATSAPP_TEMPLATE_NAME belum diisi pada .env.'
            );
        }

        $parameters = collect(preg_split('/\R/', $text))
            ->filter(fn (string $line) => trim($line) !== '')
            ->take(10)
            ->values()
            ->map(fn (string $line) => ['type' => 'text', 'text' => $line])
            ->all();

        $template = [
            'name' => $templateName,
            'language' => [
                'code' => config('services.whatsapp.template_language', 'id'),
            ],
        ];

        if ($parameters !== []) {
            $template['components'] = [
                [
                    'type' => 'body',
                    'parameters' => $parameters,
                ],
            ];
        }

        return [
            'type' => 'template',
            'template' => $template,
        ];
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
