<?php

namespace App\Console\Commands;

use App\Services\ATKImageRecognitionService;
use App\Support\ImageFeatureExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Membangun indeks fitur Visual Search ATK dari dataset kanonik.
 *
 * Hanya memakai gambar split train + validation (data test TIDAK PERNAH masuk
 * indeks — dipakai eksklusif untuk evaluasi, mencegah kebocoran data uji).
 *
 * Output: {dataset}/index/features.json
 */
class BuildImageSearchIndex extends Command
{
    protected $signature = 'atk:image-index';

    protected $description = 'Bangun indeks fitur vektor (k-NN) dari dataset Visual Search ATK (train+validation)';

    public function handle(ATKImageRecognitionService $recognition): int
    {
        $root = ATKImageRecognitionService::datasetPath();
        $extractor = new ImageFeatureExtractor;

        $entries = [];
        $fingerprintParts = [];
        $total = 0;

        foreach (['train', 'validation'] as $split) {
            foreach (glob($root.'/'.$split.'/*') ?: [] as $classDir) {
                if (! is_dir($classDir)) {
                    continue;
                }
                $label = basename($classDir);
                $count = 0;

                foreach (glob($classDir.'/*.{jpg,jpeg,png}', GLOB_BRACE) ?: [] as $imagePath) {
                    $features = $extractor->extractFromFile($imagePath);
                    if ($features === null) {
                        $this->warn('  Lewati gambar rusak: '.$imagePath);

                        continue;
                    }

                    $entries[] = [
                        'label' => $label,
                        'split' => $split,
                        'vector' => array_map(fn (float $v): float => round($v, 4), $features['vector']),
                    ];
                    $fingerprintParts[] = filesize($imagePath);
                    $count++;
                    $total++;
                }

                $this->line(sprintf('  %-16s %-11s %4d gambar', $label, $split, $count));
            }
        }

        if ($entries === []) {
            $this->error('Tidak ada gambar dataset. Jalankan `php artisan atk:prepare-dataset` dahulu.');

            return self::FAILURE;
        }

        // Statistik per dimensi ( whitening ) dari seluruh entri indeks:
        // v'[d] = ( v[d] − mean[d] ) / std[d]. Menetralkan dimensi yang hampir
        // konstan pada semua gambar sehingga dimensi informatif lebih berbobot
        // dalam perhitungan kemiripan kosinus.
        $dimension = count($entries[0]['vector']);
        $count = count($entries);
        $mean = array_fill(0, $dimension, 0.0);
        $meanSq = array_fill(0, $dimension, 0.0);
        foreach ($entries as $entry) {
            foreach ($entry['vector'] as $d => $value) {
                $mean[$d] += $value;
                $meanSq[$d] += $value * $value;
            }
        }
        $std = [];
        foreach ($mean as $d => $sum) {
            $avg = $sum / $count;
            $mean[$d] = $avg;
            $variance = max(0.0, $meanSq[$d] / $count - $avg * $avg);
            $std[] = max(1e-4, sqrt($variance));
        }

        $payload = [
            'fingerprint' => md5($count.':'.array_sum($fingerprintParts).':'.count($entries)),
            'built_at' => now()->toIso8601String(),
            'dimension' => $dimension,
            'entry_count' => count($entries),
            'splits' => ['train', 'validation'],
            'whitening' => [
                'mean' => array_map(fn (float $v): float => round($v, 6), $mean),
                'std' => array_map(fn (float $v): float => round($v, 6), $std),
            ],
            'entries' => $entries,
        ];

        File::ensureDirectoryExists($root.'/index');
        File::put(
            $root.'/index/features.json',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );

        $this->newLine();
        $this->info(sprintf(
            'Indeks fitur dibangun: %d entri, dimensi %d, %0.1f MB → %s',
            count($entries),
            $payload['dimension'],
            filesize($root.'/index/features.json') / 1048576,
            $root.'/index/features.json'
        ));
        $this->line('Siap dipakai '.ATKImageRecognitionService::class.' (jalankan atk:image-evaluate untuk evaluasi data test).');

        return self::SUCCESS;
    }
}
