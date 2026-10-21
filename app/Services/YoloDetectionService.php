<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Klien layanan inferensi deteksi objek YOLO (ai-service/server.py — Python/FastAPI).
 *
 * Laravel TIDAK menjalankan model Python secara langsung; model berjalan sebagai
 * proses terpisah dan diakses lewat HTTP (lihat ai-service/README.md untuk cara
 * menjalankannya). URL layanan & ambang batas dikonfigurasi lewat .env
 * (config/services.php) — tanpa kredensial di kode sumber.
 *
 * Metode detect() bersifat "best-effort": setiap kegagalan (layanan mati,
 * balasan tidak valid, timeout) mengembalikan null agar pemanggil bisa jatuh ke
 * mesin pengenalan bawaan — fitur pencarian visual tidak pernah ikut mati.
 */
class YoloDetectionService
{
    /** Apakah URL layanan terisi (env IMAGE_SEARCH_SERVICE_URL)? */
    public function enabled(): bool
    {
        return filled(config('services.image_search.service_url'));
    }

    /**
     * Kirim satu gambar ke POST /detect.
     *
     * @param  string  $binary  isi berkas gambar (jpg/png/webp)
     * @param  float|null  $minConfidence  ambang deteksi dikirim ke server
     *                                     (default: config yolo_min_confidence)
     * @return array{detections: list<array{label: string, confidence: float, box: list<float>}>, elapsed_ms: float}|null
     *                                                                                                                    null = layanan tak terjangkau / balasan tidak valid → wajib fallback.
     */
    public function detect(string $binary, ?float $minConfidence = null): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $url = rtrim((string) config('services.image_search.service_url'), '/');
        $timeout = (float) config('services.image_search.service_timeout', 10);
        $conf = $minConfidence ?? (float) config('services.image_search.yolo_min_confidence', 0.45);

        try {
            $response = $this->client($timeout)
                ->attach('image', $binary, 'photo.jpg')
                ->post($url.'/detect', [
                    'conf' => $conf,
                ]);

            if (! $response->successful()) {
                Log::warning('Layanan deteksi YOLO merespons non-200 — fallback ke pengenalan bawaan.', [
                    'endpoint' => $url.'/detect',
                    'status' => $response->status(),
                ]);

                return null;
            }

            $payload = $response->json();
            if (! is_array($payload) || ($payload['ok'] ?? null) !== true || ! is_array($payload['detections'] ?? null)) {
                Log::warning('Balasan layanan deteksi YOLO tidak valid — fallback ke pengenalan bawaan.', [
                    'endpoint' => $url.'/detect',
                    'payload' => is_array($payload) ? array_keys($payload) : gettype($payload),
                ]);

                return null;
            }

            $detections = [];
            foreach ($payload['detections'] as $item) {
                if (! is_array($item) || ! isset($item['label'], $item['confidence'])) {
                    continue;
                }

                $confidence = (float) $item['confidence'];
                if (! is_finite($confidence)) {
                    continue;
                }

                $box = [];
                foreach ((array) ($item['box'] ?? []) as $coord) {
                    if (is_numeric($coord)) {
                        $box[] = (float) $coord;
                    }
                }

                $detections[] = [
                    'label' => (string) $item['label'],
                    'confidence' => min(1.0, max(0.0, $confidence)),
                    'box' => $box,
                ];
            }

            return [
                'detections' => $detections,
                'elapsed_ms' => (float) ($payload['elapsed_ms'] ?? 0.0),
            ];
        } catch (Throwable $e) {
            Log::warning('Layanan deteksi YOLO tidak terjangkau — fallback ke pengenalan bawaan.', [
                'endpoint' => $url.'/detect',
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return null;
        }
    }

    /** Susunan request: timeout tegas (connect jauh lebih pendek dari total). */
    private function client(float $timeout): PendingRequest
    {
        return Http::connectTimeout((float) config('services.image_search.service_connect_timeout', 2))
            ->timeout(max(1.0, $timeout));
    }
}
