<?php

namespace App\Console\Commands;

use App\Services\ATKImageRecognitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Evaluasi pengenalan Visual Search ATK pada data TEST (yang tidak pernah
 * dipakai untuk membangun indeks).
 *
 * Melaporkan:
 *  - akurasi klasifikasi kelas ATK per kelas,
 *  - laju penolakan barang NON-ATK (specificity),
 *  - laju "tidak yakin" (uncertain),
 *  - sweep ambang min_confidence sebagai dasar kalibrasi ambang batas.
 *
 * Output: {dataset}/metadata/evaluation.json
 */
class EvaluateImageSearch extends Command
{
    protected $signature = 'atk:image-evaluate';

    protected $description = 'Evaluasi akurasi pengenalan Visual Search ATK pada split test + kalibrasi ambang batas';

    public function handle(ATKImageRecognitionService $recognition): int
    {
        $root = ATKImageRecognitionService::datasetPath();
        $testDir = $root.'/test';

        if (! is_dir($testDir) || ! $recognition->isReady()) {
            $this->error('Dataset test atau indeks fitur belum tersedia. Jalankan atk:prepare-dataset lalu atk:image-index.');

            return self::FAILURE;
        }

        $images = [];
        foreach (glob($testDir.'/*') ?: [] as $classDir) {
            if (! is_dir($classDir)) {
                continue;
            }
            foreach (glob($classDir.'/*.{jpg,jpeg,png}', GLOB_BRACE) ?: [] as $imagePath) {
                $images[] = ['label' => basename($classDir), 'path' => $imagePath];
            }
        }

        if ($images === []) {
            $this->error('Tidak ada gambar test.');

            return self::FAILURE;
        }

        $this->info('Mengevaluasi '.count($images).' gambar test dengan ambang config saat ini...');

        $confusion = [];
        $atKTotal = 0;
        $atKCorrect = 0;
        $nonAtkTotal = 0;
        $nonAtkRejected = 0;
        $uncertainCount = 0;
        $perClass = [];

        foreach ($images as $image) {
            $binary = (string) file_get_contents($image['path']);
            $result = $recognition->recognize($binary);
            $truth = $image['label'];
            $isTruthAtk = self::isAtkLabel($truth);

            $predicted = $result['status'] === 'uncertain' || $result['status'] === 'error'
                ? '(uncertain)'
                : (string) $result['label'];

            $confusion[$truth][$predicted] = ($confusion[$truth][$predicted] ?? 0) + 1;
            $perClass[$truth] = $perClass[$truth] ?? ['total' => 0, 'correct' => 0];
            $perClass[$truth]['total']++;

            if ($result['status'] === 'uncertain' || $result['status'] === 'error') {
                $uncertainCount++;

                continue;
            }

            if ($isTruthAtk) {
                $atKTotal++;
                if ($result['label'] === $truth) {
                    $atKCorrect++;
                    $perClass[$truth]['correct']++;
                }
            } else {
                $nonAtkTotal++;
                if ($result['status'] === 'not_atk') {
                    $nonAtkRejected++;
                }
            }
        }

        $accuracy = $atKTotal > 0 ? $atKCorrect / $atKTotal : 0.0;
        $specificity = $nonAtkTotal > 0 ? $nonAtkRejected / $nonAtkTotal : 0.0;

        $this->newLine();
        $this->info('=== Hasil dengan ambang aktif ===');
        $this->line(sprintf('Akurasi kelas ATK (dari %d gambar ATK test terklasifikasi): %d benar = %.1f%%', $atKTotal, $atKCorrect, $accuracy * 100));
        $this->line(sprintf('Penolakan NON-ATK (dari %d gambar non-ATK test terklasifikasi): %d ditolak = %.1f%%', $nonAtkTotal, $nonAtkRejected, $specificity * 100));
        $this->line(sprintf('Tidak yakin/uncertain: %d dari %d (%.1f%%)', $uncertainCount, count($images), $uncertainCount / max(1, count($images)) * 100));

        $rows = [];
        foreach ($perClass as $label => $stat) {
            $rows[] = [$label, $stat['total'], $stat['correct'], $stat['total'] > 0 ? round($stat['correct'] / $stat['total'] * 100, 1).'%' : '-'];
        }
        $this->newLine();
        $this->table(['Kelas (test)', 'Jumlah', 'Benar', 'Akurasi'], $rows);

        // Hitung jumlah test ATK vs non-ATK sebagai denominasi sweep.
        $atkTestCount = 0;
        $nonAtkTestCount = 0;
        foreach ($images as $image) {
            if (self::isAtkLabel($image['label'])) {
                $atkTestCount++;
            } else {
                $nonAtkTestCount++;
            }
        }

        // Sweep ambang min_confidence (margin & similarity tetap) untuk
        // menentukan ambang yang menekan salah-terima sekaligus menjaga recall.
        $this->newLine();
        $this->info('=== Sweep ambang min_confidence ===');
        $sweep = [];
        $rows = [];
        foreach ([0.50, 0.55, 0.60, 0.62, 0.65, 0.68, 0.70, 0.75, 0.80, 0.85] as $threshold) {
            config()->set('services.image_search.min_confidence', $threshold);

            $tp = 0;
            $fp = 0;
            $fn = 0;
            $tn = 0;
            $unc = 0;
            $fw = 0; // gambar ATK salah kelas (tetap diterima → rekomendasi keliru)
            foreach ($images as $image) {
                $binary = (string) file_get_contents($image['path']);
                $result = $recognition->recognize($binary);
                $truthAtk = self::isAtkLabel($image['label']);

                if ($result['status'] === 'uncertain' || $result['status'] === 'error') {
                    $unc++;
                    if ($truthAtk) {
                        $fn++;
                    } else {
                        $tn++;
                    }

                    continue;
                }
                if ($truthAtk) {
                    if ($result['label'] === $image['label']) {
                        $tp++;
                    } else {
                        $fn++;
                        $fw++;
                    }
                } elseif ($result['status'] === 'not_atk') {
                    $tn++;
                } else {
                    $fp++;
                }
            }
            $acceptedAsAtk = $tp + $fw + $fp;
            $rows[] = [
                $threshold,
                $atkTestCount > 0 ? round($tp / $atkTestCount * 100, 1).'%' : '-',
                $acceptedAsAtk > 0 ? round($tp / $acceptedAsAtk * 100, 1).'%' : '-',
                $nonAtkTestCount > 0 ? round($fp / $nonAtkTestCount * 100, 1).'%' : '-',
                $unc,
            ];
            $sweep[] = [
                'threshold' => $threshold,
                'atk_correct' => $tp,
                'atk_total' => $atkTestCount,
                'atk_wrong_class' => $fw,
                'false_accept_non_atk' => $fp,
                'non_atk_total' => $nonAtkTestCount,
                'precision_of_recommended' => $acceptedAsAtk > 0 ? round($tp / $acceptedAsAtk, 4) : null,
                'uncertain' => $unc,
            ];
        }
        $this->table(['min_confidence', 'Cakupan ATK', 'Presisi rekomendasi', 'Salah-terima non-ATK', 'Uncertain'], $rows);
        config()->set('services.image_search.min_confidence', (float) env('IMAGE_SEARCH_MIN_CONFIDENCE', 0.55));

        File::put($root.'/metadata/evaluation.json', json_encode([
            'evaluated_at' => now()->toIso8601String(),
            'test_images' => count($images),
            'thresholds' => [
                'min_confidence' => (float) env('IMAGE_SEARCH_MIN_CONFIDENCE', 0.55),
                'min_margin' => (float) env('IMAGE_SEARCH_MIN_MARGIN', 0.02),
                'min_similarity' => (float) env('IMAGE_SEARCH_MIN_SIMILARITY', 0.45),
            ],
            'atk_accuracy_classified' => round($accuracy, 4),
            'non_atk_specificity_classified' => round($specificity, 4),
            'uncertain_rate' => round($uncertainCount / max(1, count($images)), 4),
            'per_class' => $perClass,
            'confusion' => $confusion,
            'confidence_sweep' => $sweep,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->line('Laporan lengkap: '.$root.'/metadata/evaluation.json');

        return self::SUCCESS;
    }

    private static function isAtkLabel(string $label): bool
    {
        return array_key_exists($label, ATKImageRecognitionService::LABELS)
            && (ATKImageRecognitionService::LABELS[$label]['is_atk'] ?? false);
    }
}
