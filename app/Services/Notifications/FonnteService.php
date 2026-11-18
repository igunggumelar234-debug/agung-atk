<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien gateway WhatsApp Fonnte (https://docs.fonnte.com/) — provider utama.
 *
 * Endpoint yang dipakai:
 * - POST {FONNTE_API_URL}/send   -> kirim pesan (target + message).
 * - POST {FONNTE_API_URL}/device -> status perangkat WhatsApp terhubung
 *   (versi lama /get-devices menolak token akun ini — lihat method device()).
 *
 * Autentikasi: header "Authorization: <TOKEN>" TANPA prefix "Bearer"
 * (sesuai dokumentasi resmi Fonnte). Token hanya dibaca dari .env
 * (FONNTE_API_TOKEN) dan tidak pernah dicatat ke log/tampilan.
 *
 * Kontrak:
 * - send()   : mengembalikan hasil terstruktur (tidak melempar) agar mudah
 *              dipakai controller JSON; WhatsAppNotificationService yang
 *              mengubah kegagalan menjadi RuntimeException untuk alur job.
 * - device() : status perangkat — TANPA menampilkan token perangkat.
 */
class FonnteService
{
    /**
     * Kirim satu pesan WhatsApp ke satu nomor.
     *
     * @return array{ok: bool, id: ?string, error: ?string}
     */
    public function send(string $to, string $text): array
    {
        $token = (string) config('services.fonnte.token');

        if (blank($token)) {
            return ['ok' => false, 'id' => null, 'error' => 'FONNTE_API_TOKEN belum diisi pada .env.'];
        }

        $target = WhatsAppNotificationService::normalizeInternational($to);

        if ($target === null) {
            return [
                'ok' => false,
                'id' => null,
                'error' => 'Nomor WhatsApp tidak valid: "'.$to.'" (contoh: 081234567890 atau 6281234567890).',
            ];
        }

        try {
            $response = $this->client($token)->post($this->url('/send'), [
                'target' => $target,
                // Batas pesan Fonnte: 60.000 karakter.
                'message' => mb_substr($text, 0, 60000),
                // '0' menonaktifkan penggantian awalan negara; target sudah
                // dinormalkan ke format internasional 628xxx oleh aplikasi.
                'countryCode' => '0',
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'error' => $this->redactSecret($e->getMessage(), $token)];
        }

        if ($response->status() >= 400) {
            return [
                'ok' => false,
                'id' => null,
                'error' => 'Fonnte HTTP '.$response->status().' — permintaan ditolak server.',
            ];
        }

        $body = $response->json();

        if (! is_array($body)) {
            return ['ok' => false, 'id' => null, 'error' => 'Respons Fonnte tidak terbaca (bukan JSON).'];
        }

        // Fonnte memakai "status" (huruf kecil) pada sebagian besar respons
        // dan "Status" (huruf besar) pada beberapa pesan galat — keduanya dicek.
        $ok = (bool) ($body['status'] ?? $body['Status'] ?? false);

        if (! $ok) {
            $reason = (string) ($body['reason'] ?? $body['detail'] ?? 'tidak diketahui');

            return [
                'ok' => false,
                'id' => null,
                'error' => 'Fonnte menolak pesan: '.$reason.'.',
            ];
        }

        $id = isset($body['id'][0]) ? (string) $body['id'][0] : null;

        return ['ok' => true, 'id' => $id, 'error' => null];
    }

    /**
     * Status perangkat WhatsApp pada akun Fonnte (baca saja, tanpa kirim).
     *
     * Endpoint: POST {FONNTE_API_URL}/device — endpoint validasi perangkat
     * yang diterima oleh token akun ini (dicoba langsung: versi lama
     * /get-devices mengembalikan "unknown user" untuk token yang sama,
     * sehingga /device yang dipakai; keduanya tercatat pada AUDIT_REPORT).
     *
     * @return array{
     *     ok: bool,
     *     connected: int,
     *     devices: int,
     *     device: ?string,
     *     device_status: ?string,
     *     name: ?string,
     *     package: ?string,
     *     quota: ?string,
     *     expired: ?string,
     *     messages: int,
     *     error: ?string
     * }
     */
    public function device(): array
    {
        $empty = [
            'ok' => false,
            'connected' => 0,
            'devices' => 0,
            'device' => null,
            'device_status' => null,
            'name' => null,
            'package' => null,
            'quota' => null,
            'expired' => null,
            'messages' => 0,
            'error' => null,
        ];

        $token = (string) config('services.fonnte.token');

        // Catatan: array_merge dipakai (bukan union "+") agar key 'ok' dan
        // 'error' pada template $empty benar-benar tertimpa nilai baru.
        if (blank($token)) {
            return array_merge($empty, ['error' => 'FONNTE_API_TOKEN belum diisi pada .env.']);
        }

        try {
            $response = $this->client($token)->post($this->url('/device'));
        } catch (\Throwable $e) {
            return array_merge($empty, ['error' => $this->redactSecret($e->getMessage(), $token)]);
        }

        if ($response->status() >= 400) {
            return array_merge($empty, ['error' => 'Fonnte HTTP '.$response->status().' — permintaan ditolak server.']);
        }

        $body = $response->json();

        if (! is_array($body)) {
            return array_merge($empty, ['error' => 'Respons Fonnte tidak terbaca (bukan JSON).']);
        }

        $ok = (bool) ($body['status'] ?? $body['Status'] ?? false);

        if (! $ok) {
            $reason = (string) ($body['reason'] ?? 'tidak diketahui');

            return array_merge($empty, ['error' => 'Fonnte: '.$reason.'.']);
        }

        $deviceNumber = isset($body['device']) ? (string) $body['device'] : null;
        $deviceStatus = isset($body['device_status']) ? (string) $body['device_status'] : null;

        // Token perangkat TIDAK pernah ada pada respons /device dan tidak
        // pernah ikut dikembalikan.
        return array_merge($empty, [
            'ok' => true,
            'connected' => $deviceStatus === 'connect' ? 1 : 0,
            'devices' => filled($deviceNumber) ? 1 : 0,
            'device' => $deviceNumber,
            'device_status' => $deviceStatus,
            'name' => isset($body['name']) ? (string) $body['name'] : null,
            'package' => isset($body['package']) ? (string) $body['package'] : null,
            'quota' => isset($body['quota']) ? (string) $body['quota'] : null,
            'expired' => isset($body['expired']) ? (string) $body['expired'] : null,
            'messages' => (int) ($body['messages'] ?? 0),
            'error' => null,
        ]);
    }

    /**
     * HTTP client bawaan: header Authorization mentah (tanpa Bearer),
     * timeout terkonfigurasi, dan retry terbatas (2x, jeda 250 ms) untuk
     * kegagalan sementara (koneksi putus / 5xx / 429).
     *
     * Keggagalan logis Fonnte datang sebagai HTTP 200 + status:false sehingga
     * TIDAK ikut diulang (retry hanya untuk gangguan transport).
     */
    private function client(string $token): PendingRequest
    {
        return Http::withHeaders(['Authorization' => $token])
            ->timeout((int) config('services.fonnte.timeout', 15))
            ->retry(2, 250);
    }

    /**
     * Gabungkan base URL Fonnte dengan path endpoint.
     */
    private function url(string $path): string
    {
        return rtrim((string) config('services.fonnte.url', 'https://api.fonnte.com'), '/').$path;
    }

    /**
     * Hapus jejak token dari pesan kesalahan sebelum dikembalikan/ dicatat.
     */
    private function redactSecret(string $message, string $token): string
    {
        if ($token !== '' && str_contains($message, $token)) {
            $message = str_replace($token, '[REDACTED]', $message);
        }

        return $message;
    }
}
