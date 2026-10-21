<?php

namespace App\Services;

use App\Support\ImageFeatureExtractor;
use Illuminate\Support\Facades\Log;

/**
 * Pengenalan jenis barang ATK dari gambar (foto kamera / unggah pengguna).
 *
 * Metode — dua mesin di belakang SATU kontrak hasil (driver
 * `services.image_search.driver` di config/services.php):
 *
 *  1. 'hybrid' (default) — deteksi objek YOLOv8 dijalankan layanan Python
 *     terpisah (ai-service/server.py; klien: YoloDetectionService). Selama
 *     layanan menjawab, keputusan (termasuk 'uncertain') dipercayakan ke YOLO;
 *     bila layanan tak terjangkau / balasan tidak valid, sistem otomatis jatuh
 *     ke mesin (2) sehingga fitur pencarian tidak pernah ikut mati.
 *  2. 'gd' — pencarian kemiripan visual (nearest neighbor) berbasis vektor
 *     fitur ( ImageFeatureExtractor ) terhadap indeks dataset ATK gabungan
 *     yang sudah diprakomputasi lewat perintah `php artisan atk:image-index`.
 *
 * Mesin k-NN memakai whitening per dimensi (mean/std indeks) sebelum kemiripan
 * kosinus dihitung. Skor per kelas = rerata kemiripan2 tetangga terdekat kelas
 * tersebut. Keputusan diterima bila:
 *   - skor kelas terbaik ≥ min_confidence,
 *   - kemiripan tetangga terbaik ≥ min_similarity, dan
 *   - selisih kelas terbaik vs runner-up ≥ min_margin.
 * Mesin YOLO memakai ambang yolo_min_confidence & yolo_min_margin yang nilainya
 * diturunkan dari hasil evaluasi split test ( ai-service/models/metrics.json ).
 *
 * Keluaran berupa salah satu status:
 *  - recognized → kelas ATK dikenali dengan keyakinan memadai.
 *  - not_atk    → gambar dikenali sebagai barang NON-ATK (mis. printer).
 *  - uncertain  → tidak ada kelas yang melewati ambang batas keyakinan.
 *  - error      → gambar tidak dapat dibaca, atau indeks belum tersedia.
 *
 * Ambang batas dikonfigurasi di config/services.php dan ditentukan dari hasil
 * evaluasi data test ( `php artisan atk:image-evaluate` ), bukan angka
 * sembarangan.
 */
class ATKImageRecognitionService
{
    /**
     * Metadata kelas kanonik dataset. `is_atk=false` dipakai untuk MENOLAK
     * gambar di luar lingkup toko ATK (elektronik, furnitur, dll).
     *
     * @var array<string, array{name: string, is_atk: bool}>
     */
    public const LABELS = [
        // --- Kelas ATK (dikenali → pencarian katalog) ---
        'office_supplies' => ['name' => 'Alat Tulis Kantor', 'is_atk' => true],
        'pen' => ['name' => 'Pulpen / Pena', 'is_atk' => true],
        'notebook' => ['name' => 'Buku Tulis', 'is_atk' => true],
        'scissors' => ['name' => 'Gunting', 'is_atk' => true],
        'stapler' => ['name' => 'Stapler', 'is_atk' => true],
        'puncher' => ['name' => 'Pembolong Kertas', 'is_atk' => true],
        'ring_binder' => ['name' => 'Ordner / Binder', 'is_atk' => true],
        'tape' => ['name' => 'Selotip / Tape / Koreksi', 'is_atk' => true],
        'letter_tray' => ['name' => 'Tempat Kertas / Arsip', 'is_atk' => true],
        // --- Kelas ATK tambahan (data Roboflow School Supplies bila tersedia) ---
        'pencil' => ['name' => 'Pensil', 'is_atk' => true],
        'eraser' => ['name' => 'Penghapus', 'is_atk' => true],
        'ruler' => ['name' => 'Penggaris', 'is_atk' => true],
        'highlighter' => ['name' => 'Stabilo / Highlighter', 'is_atk' => true],
        'marker' => ['name' => 'Spidol / Marker', 'is_atk' => true],
        'compass' => ['name' => 'Jangka', 'is_atk' => true],
        // --- Kelas NON-ATK (dikenali → gambar ditolak) ---
        'calculator' => ['name' => 'Kalkulator', 'is_atk' => false],
        'desk_chair' => ['name' => 'Kursi Kantor', 'is_atk' => false],
        'desk_lamp' => ['name' => 'Lampu Meja', 'is_atk' => false],
        'file_cabinet' => ['name' => 'Laci Arsip', 'is_atk' => false],
        'laptop_computer' => ['name' => 'Laptop', 'is_atk' => false],
        'printer' => ['name' => 'Printer', 'is_atk' => false],
        'trash_can' => ['name' => 'Tempat Sampah', 'is_atk' => false],
    ];

    /** Pesan baku sesuai spesifikasi produk. */
    public const MESSAGE_NOT_RECOGNIZED = 'Maaf, gambar belum dapat dikenali sebagai produk ATK. Silakan ambil foto yang lebih jelas atau unggah gambar alat tulis kantor.';

    /** @var array{entries: list<array{label: string, vector: list<float>}>, whitening: array{mean: list<float>, std: list<float>}|null}|null */
    private ?array $index = null;

    private ?ImageFeatureExtractor $extractor = null;

    private readonly YoloDetectionService $yolo;

    public function __construct(?ImageFeatureExtractor $extractor = null, ?YoloDetectionService $yolo = null)
    {
        $this->extractor = $extractor ?? new ImageFeatureExtractor;
        $this->yolo = $yolo ?? new YoloDetectionService;
    }

    public function extractor(): ImageFeatureExtractor
    {
        return $this->extractor;
    }

    /** Lokasi root dataset ( privat, di dalam storage/app ). */
    public static function datasetPath(string $part = ''): string
    {
        $base = rtrim((string) config('services.image_search.dataset_path', storage_path('app/datasets/atk-image-search')), '/\\');

        return $part === '' ? $base : $base.DIRECTORY_SEPARATOR.ltrim($part, '/\\');
    }

    /** Apakah indeks fitur sudah dibangun ( atk:image-index )? */
    public function isReady(): bool
    {
        return is_file(self::datasetPath('index/features.json'));
    }

    /**
     * Kenali isi gambar (YOLO dulu bila driver hybrid, lalu mesin k-NN bawaan).
     *
     * @return array{
     *     status: 'recognized'|'not_atk'|'uncertain'|'error',
     *     label: string|null, label_name: string|null,
     *     confidence: float, margin: float, best_similarity: float,
     *     alternatives: list<array{label: string, name: string, score: float}>,
     *     engine?: 'yolo'|'gd'
     * }
     */
    public function recognize(string $binary): array
    {
        $empty = [
            'status' => 'error', 'label' => null, 'label_name' => null,
            'confidence' => 0.0, 'margin' => 0.0, 'best_similarity' => 0.0, 'alternatives' => [],
        ];

        // Gambar rusak / format tak dikenal → 'error' langsung TANPA round-trip
        // ke layanan deteksi (pesan kesalahan tetap jujur, biaya hemat).
        if (@getimagesizefromstring($binary) === false) {
            return $empty;
        }

        // Jalur YOLO (bila driver bukan 'gd'). Selama layanan menjawab, keputusan
        // (termasuk 'uncertain') dipercayakan ke YOLO; null = layanan tak
        // terjangkau / balasan tidak valid → jatuh ke mesin k-NN bawaan.
        if ((string) config('services.image_search.driver', 'hybrid') !== 'gd') {
            $yolo = $this->recognizeWithYolo($binary);
            if ($yolo !== null) {
                $yolo['engine'] = 'yolo';

                return $yolo;
            }
        }

        $features = $this->extractor->extract($binary);
        if ($features === null) {
            return $empty;
        }

        $index = $this->loadIndex();
        if ($index === null || $index['entries'] === []) {
            return $empty;
        }

        // Transformasi query dengan statistik whitening indeks ( bila ada ).
        $query = $this->whiten($features['vector'], $index['whitening']);

        // Kemiripan kosinus terhadap seluruh entri indeks.
        $perClass = [];
        $bestSimilarity = -1.0;
        foreach ($index['entries'] as $entry) {
            $similarity = $this->dot($query, $entry['vector']);
            if ($similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
            }

            // Simpan2 kemiripan teratas per kelas.
            $class = $entry['label'];
            $perClass[$class][] = $similarity;
            if (count($perClass[$class]) > 2) {
                // Urutkan menaik lalu buang nilai terkecil → tersisa2 terbesar.
                sort($perClass[$class]);
                array_shift($perClass[$class]);
            }
        }

        // Skor kelas = rerata kemiripan2 tetangga terdekat kelas tersebut.
        $classScores = [];
        foreach ($perClass as $class => $similarities) {
            sort($similarities);
            $top = array_slice($similarities, -2);
            $classScores[$class] = array_sum($top) / count($top);
        }
        arsort($classScores);

        $sorted = array_keys($classScores);
        $bestLabel = $sorted[0];
        $confidence = $classScores[$bestLabel];
        $secondScore = isset($sorted[1]) ? $classScores[$sorted[1]] : 0.0;
        $margin = $confidence - $secondScore;

        $alternatives = [];
        foreach (array_slice($sorted, 0, 3) as $label) {
            $alternatives[] = [
                'label' => $label,
                'name' => self::LABELS[$label]['name'] ?? $label,
                'score' => round($classScores[$label], 4),
            ];
        }

        $result = [
            'status' => 'uncertain',
            'label' => $bestLabel,
            'label_name' => self::LABELS[$bestLabel]['name'] ?? $bestLabel,
            'confidence' => round($confidence, 4),
            'margin' => round($margin, 4),
            'best_similarity' => round(max(0.0, $bestSimilarity), 4),
            'alternatives' => $alternatives,
        ];

        $passesThreshold = $confidence >= (float) config('services.image_search.min_confidence', 0.90)
            && $margin >= (float) config('services.image_search.min_margin', 0.01)
            && $bestSimilarity >= (float) config('services.image_search.min_similarity', 0.75);

        // Mesin pengambil keputusan akhir pada jalur ini: k-NN bawaan (driver 'gd'
        // atau layanan YOLO tidak terjangkau / balasan tidak valid).
        $result['engine'] = 'gd';

        if (! $passesThreshold) {
            return $result;
        }

        $result['status'] = (self::LABELS[$bestLabel]['is_atk'] ?? true) ? 'recognized' : 'not_atk';

        return $result;
    }

    /**
     * Terjemahkan deteksi layanan YOLO ke kontrak hasil mesin k-NN.
     *
     * Keputusan: skor per kelas = deteksi tertinggi kelas tersebut; diterima
     * bila skor terbaik ≥ yolo_min_confidence DAN selisih vs kelas kedua
     * ≥ yolo_min_margin. Ambang default (0.80 / 0.00) adalah hasil sweep
     * `python ai-service/evaluate.py` pada split test (atk_precision 0.9065
     *
     * @ coverage 0.8487) — perbarui bila model dilatih ulang.
     * Label di luar LABELS tidak pernah dijadikan keputusan.
     *
     * @return array{
     *     status: 'recognized'|'not_atk'|'uncertain',
     *     label: string|null, label_name: string|null,
     *     confidence: float, margin: float, best_similarity: float,
     *     alternatives: list<array{label: string, name: string, score: float}>
     * }|null  null = layanan tak terjangkau / balasan invalid → pemanggil fallback.
     */
    private function recognizeWithYolo(string $binary): ?array
    {
        $payload = $this->yolo->detect($binary);
        if ($payload === null) {
            return null;
        }

        $minConfidence = (float) config('services.image_search.yolo_min_confidence', 0.80);
        $minMargin = (float) config('services.image_search.yolo_min_margin', 0.00);

        $byClass = [];
        foreach ($payload['detections'] as $detection) {
            $label = $detection['label'];
            if (! isset(self::LABELS[$label])) {
                continue;
            }
            $byClass[$label] = max($byClass[$label] ?? 0.0, (float) $detection['confidence']);
        }

        if ($byClass === []) {
            // Tidak ada deteksi yang dikenal sistem (di bawah ambang server / kosong).
            return [
                'status' => 'uncertain', 'label' => null, 'label_name' => null,
                'confidence' => 0.0, 'margin' => 0.0, 'best_similarity' => 0.0, 'alternatives' => [],
            ];
        }

        arsort($byClass);
        $labels = array_keys($byClass);
        $bestLabel = $labels[0];
        $confidence = (float) $byClass[$bestLabel];
        $secondScore = isset($labels[1]) ? (float) $byClass[$labels[1]] : 0.0;
        $margin = $confidence - $secondScore;

        $alternatives = [];
        foreach (array_slice($labels, 0, 3) as $label) {
            $alternatives[] = [
                'label' => $label,
                'name' => self::LABELS[$label]['name'] ?? $label,
                'score' => round((float) $byClass[$label], 4),
            ];
        }

        $result = [
            'status' => 'uncertain',
            'label' => $bestLabel,
            'label_name' => self::LABELS[$bestLabel]['name'] ?? $bestLabel,
            'confidence' => round($confidence, 4),
            'margin' => round($margin, 4),
            'best_similarity' => round($confidence, 4),
            'alternatives' => $alternatives,
        ];

        if ($confidence < $minConfidence || $margin < $minMargin) {
            return $result;
        }

        $result['status'] = (self::LABELS[$bestLabel]['is_atk'] ?? true) ? 'recognized' : 'not_atk';

        return $result;
    }

    /**
     * Muat indeks fitur dari disk ( dikunci proses, JSON besar ) + whitening
     * in-memory.
     *
     * @return array{entries: list<array{label: string, vector: list<float>}>, whitening: array{mean: list<float>, std: list<float>}|null}|null
     */
    private function loadIndex(): ?array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $path = self::datasetPath('index/features.json');
        if (! is_file($path)) {
            return null;
        }

        try {
            $raw = file_get_contents($path);
            $decoded = $raw === false ? null : json_decode($raw, true);
        } catch (\Throwable) {
            $decoded = null;
        }

        if (! is_array($decoded) || ! isset($decoded['entries']) || ! is_array($decoded['entries'])) {
            Log::warning('Indeks fitur pencarian gambar ATK tidak valid atau gagal dibaca.', ['path' => $path]);

            return null;
        }

        $whitening = null;
        if (isset($decoded['whitening']['mean'], $decoded['whitening']['std'])
            && is_array($decoded['whitening']['mean'])
            && is_array($decoded['whitening']['std'])) {
            $whitening = [
                'mean' => array_map('floatval', $decoded['whitening']['mean']),
                'std' => array_map('floatval', $decoded['whitening']['std']),
            ];
        }

        $entries = [];
        foreach ($decoded['entries'] as $entry) {
            if (! isset($entry['label'], $entry['vector']) || ! is_array($entry['vector'])) {
                continue;
            }
            $vector = array_map('floatval', $entry['vector']);
            if ($whitening !== null) {
                $vector = $this->l2Normalize($this->whiten($vector, $whitening));
            }
            $entries[] = ['label' => (string) $entry['label'], 'vector' => $vector];
        }

        return $this->index = ['entries' => $entries, 'whitening' => $whitening];
    }

    /**
     * Standardisasi per dimensi: v'[d] = ( v[d] − mean[d] ) / std[d].
     *
     * @param  list<float>  $vector
     * @param  array{mean: list<float>, std: list<float>}|null  $whitening
     * @return list<float>
     */
    private function whiten(array $vector, ?array $whitening): array
    {
        if ($whitening === null) {
            return $vector;
        }

        $mean = $whitening['mean'];
        $std = $whitening['std'];
        $count = min(count($vector), count($mean));

        for ($i = 0; $i < $count; $i++) {
            $divisor = $std[$i] ?? 1.0;
            if ($divisor < 1e-6) {
                $divisor = 1.0;
            }
            $vector[$i] = ($vector[$i] - $mean[$i]) / $divisor;
        }

        return $this->l2Normalize($vector);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $count = min(count($a), count($b));
        for ($i = 0; $i < $count; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function l2Normalize(array $vector): array
    {
        $sum = 0.0;
        foreach ($vector as $value) {
            $sum += $value * $value;
        }
        $norm = sqrt($sum);
        if ($norm < 1e-12) {
            return $vector;
        }

        return array_map(fn (float $value): float => $value / $norm, $vector);
    }
}
