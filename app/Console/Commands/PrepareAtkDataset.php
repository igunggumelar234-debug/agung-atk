<?php

namespace App\Console\Commands;

use App\Services\ATKImageRecognitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Menyiapkan dataset gabungan Visual Search ATK dari hasil ekstraksi ZIP.
 *
 * Membaca data mentah (raw/zip1, raw/zip2 — ekstraksi manual dari
 * DatasetBarangATK.zip dan ekspor images.cv/Roboflow) lalu membangun struktur
 * kanonik klasifikasi gambar:
 *
 *   datasets/atk-image-search/{train,validation,test}/{kelas}/*.jpg
 *
 * Aturan:
 *  - Hanya kelas relevan ATK yang dipakai sebagai kelas dikenali; kelas
 *    NON-ATK (printer, laptop, dll) ikut disimpan sebagai materi PENOLAKAN.
 *  - Validasi GD per gambar (gambar rusak dibuang & dicatat).
 *  - Deduplikasi md5 global dengan prioritas train > validation > test
 *    (mencegah kebocoran data test ke data latih).
 *  - Gambar asli di raw/ tidak diubah/dihapus.
 */
class PrepareAtkDataset extends Command
{
    protected $signature = 'atk:prepare-dataset {--force : Timpa struktur kanonik yang sudah ada}';

    protected $description = 'Siapkan dataset gabungan Visual Search ATK (validasi, normalisasi label, dedupe, split)';

    /**
     * Pemetaan label sumber → kelas kanonik (hanya kelas ATK).
     *
     * @var array<string, string>
     */
    private const LABEL_MAP = [
        'office' => 'office_supplies',
        'scissors' => 'scissors',
        'pen' => 'pen',
        'paper_notebook' => 'notebook',
        'stapler' => 'stapler',
        'punchers' => 'puncher',
        'ring_binder' => 'ring_binder',
        'tape_dispenser' => 'tape',
        'letter_tray' => 'letter_tray',
    ];

    /**
     * Kelas NON-ATK dari sumber yang disimpan sebagai materi penolakan.
     *
     * @var list<string>
     */
    private const NON_ATK_CLASSES = [
        'calculator', 'desk_chair', 'desk_lamp', 'file_cabinet',
        'laptop_computer', 'printer', 'trash_can',
    ];

    /** Peta split sumber → nama split kanonik. */
    private const SPLIT_MAP = ['train' => 'train', 'val' => 'validation', 'test' => 'test'];

    public function handle(): int
    {
        $rawPath = ATKImageRecognitionService::datasetPath('raw');
        $rootPath = ATKImageRecognitionService::datasetPath();

        if (! is_dir($rawPath)) {
            $this->error('Folder raw tidak ditemukan: '.$rawPath);
            $this->line('Ekstrak dulu DatasetBarangATK.zip dan ekspor images.cv ke folder raw/ (lihat README).');

            return self::FAILURE;
        }

        if (is_dir($rootPath.'/train') && ! $this->option('force')) {
            $this->error('Struktur kanonik sudah ada. Gunakan --force untuk membangun ulang.');

            return self::FAILURE;
        }

        foreach (['train', 'validation', 'test'] as $split) {
            File::deleteDirectory($rootPath.'/'.$split);
        }
        File::ensureDirectoryExists($rootPath.'/metadata');

        // Peta sumber: id sumber → folder data di dalam raw/.
        $sources = [];
        foreach (glob($rawPath.'/*') ?: [] as $sourceDir) {
            if (! is_dir($sourceDir)) {
                continue;
            }
            $dataDir = null;
            foreach (glob($sourceDir.'/*') ?: [] as $candidate) {
                if (is_dir($candidate) && is_dir($candidate.'/data')) {
                    $dataDir = $candidate.'/data';
                    break;
                }
            }
            if ($dataDir !== null) {
                $sources[basename($sourceDir)] = $dataDir;
            }
        }

        if ($sources === []) {
            $this->error('Tidak ada folder data sumber di dalam raw/.');

            return self::FAILURE;
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'sources' => [],
            'per_split' => [],
            'per_class' => [],
            'corrupt_images' => 0,
            'duplicates_removed' => 0,
            'unsupported_extension' => 0,
            'accepted_atk_classes' => array_values(self::LABEL_MAP),
            'non_atk_classes' => self::NON_ATK_CLASSES,
        ];

        // Hash md5 global: prioritas train > validation > test (aman dari
        // kebocoran test ke train bila ada gambar kembar antar sumber).
        $seenHashes = [];

        foreach ($sources as $sourceId => $dataDir) {
            $sourceStats = ['images' => 0, 'skipped_corrupt' => 0, 'skipped_duplicate' => 0, 'skipped_unknown_class' => 0];

            foreach (self::SPLIT_MAP as $rawSplit => $canonicalSplit) {
                $splitDir = $dataDir.'/'.$rawSplit;
                if (! is_dir($splitDir)) {
                    continue;
                }

                foreach (glob($splitDir.'/*') ?: [] as $classDir) {
                    if (! is_dir($classDir)) {
                        continue;
                    }

                    $rawClass = basename($classDir);
                    $label = self::LABEL_MAP[$rawClass]
                        ?? (in_array($rawClass, self::NON_ATK_CLASSES, true) ? $rawClass : null);

                    if ($label === null) {
                        $sourceStats['skipped_unknown_class'] += count(glob($classDir.'/*.{jpg,jpeg,png}', GLOB_BRACE) ?: []);
                        $this->warn("  Kelas '$rawClass' ($sourceId) tidak dikenal — dilewati.");

                        continue;
                    }

                    foreach (glob($classDir.'/*.{jpg,jpeg,png}', GLOB_BRACE) ?: [] as $imagePath) {
                        $binary = @file_get_contents($imagePath);
                        if ($binary === false) {
                            $report['corrupt_images']++;

                            continue;
                        }

                        // Validasi dekode GD (menangkap gambar rusak/non-gambar).
                        $probe = @imagecreatefromstring($binary);
                        if ($probe === false) {
                            $report['corrupt_images']++;
                            $sourceStats['skipped_corrupt']++;

                            continue;
                        }
                        imagedestroy($probe);

                        $hash = md5($binary);
                        if (isset($seenHashes[$hash])) {
                            $report['duplicates_removed']++;
                            $sourceStats['skipped_duplicate']++;

                            continue;
                        }

                        $targetDir = $rootPath.'/'.$canonicalSplit.'/'.$label;
                        File::ensureDirectoryExists($targetDir);
                        $targetName = $sourceId.'_'.basename($imagePath);
                        File::put($targetDir.'/'.$targetName, $binary);
                        $seenHashes[$hash] = true;

                        $sourceStats['images']++;
                        $report['per_split'][$canonicalSplit] = ($report['per_split'][$canonicalSplit] ?? 0) + 1;
                        $report['per_class'][$label] = ($report['per_class'][$label] ?? 0) + 1;
                    }
                }
            }

            $report['sources'][] = ['id' => $sourceId] + $sourceStats;
        }

        File::put($rootPath.'/metadata/dataset-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->writeSourcesMetadata($rootPath);

        $this->newLine();
        $this->info('Dataset kanonik siap.');
        $tableRows = [];
        foreach ($report['per_class'] as $label => $count) {
            $kind = in_array($label, self::NON_ATK_CLASSES, true) ? 'NON-ATK (penolakan)' : 'ATK';
            $tableRows[] = [$label, $kind, $count];
        }
        $this->table(['Kelas', 'Jenis', 'Gambar'], $tableRows);
        $this->line('Per split: '.json_encode($report['per_split']));
        $this->line('Gambar rusak dibuang: '.$report['corrupt_images'].' | Duplikat dibuang: '.$report['duplicates_removed']);

        return self::SUCCESS;
    }

    /**
     * Catat sumber & lisensi dataset (metadata penting — jangan mengarang lisensi).
     */
    private function writeSourcesMetadata(string $rootPath): void
    {
        $sources = [
            'generated_at' => now()->toIso8601String(),
            'sources' => [
                [
                    'id' => 'zip1',
                    'file' => 'DatasetBarangATK.zip',
                    'provider' => 'images.cv (www.images.cv)',
                    'original_classes' => ['office', 'scissors'],
                    'original_images' => 146,
                    'original_split' => ['train' => 100, 'val' => 27, 'test' => 19],
                    'license' => 'Tidak tercantum di dalam metadata ZIP. Verifikasi halaman sumber sebelum penggunaan komersial.',
                ],
                [
                    'id' => 'zip2',
                    'file' => 'c7p50of6w5ml147ocoug6h.zip (ekspor dataset "office items")',
                    'provider' => 'images.cv (www.images.cv) — ekspor format Roboflow Universe',
                    'original_classes' => [
                        'calculator', 'desk_chair', 'desk_lamp', 'file_cabinet', 'laptop_computer',
                        'letter_tray', 'paper_notebook', 'pen', 'printer', 'punchers', 'ring_binder',
                        'scissors', 'stapler', 'tape_dispenser', 'trash_can',
                    ],
                    'original_images' => 1914,
                    'original_split' => ['train' => 1381, 'val' => 261, 'test' => 272],
                    'license' => 'Tidak tercantum di dalam metadata ZIP. Roboflow Universe umumnya memakai lisensi per-dataset (CC BY/CC0) — verifikasi halaman sumber sebelum penggunaan komersial.',
                ],
            ],
            'policy' => [
                'kept_atk_classes' => array_values(self::LABEL_MAP),
                'non_atk_classes_used_for_rejection' => self::NON_ATK_CLASSES,
                'note' => 'Kelas NON-ATK sengaja disimpan agar sistem dapat MENOLAK gambar di luar lingkup toko ATK (bukan menampilkan produk non-ATK). Gambar test tidak pernah dipakai untuk membangun indeks latih.',
            ],
            'privacy' => 'Dataset disimpan privat di storage/app/datasets dan tidak pernah diekspos melalui URL publik. Dataset tidak dikirim ke layanan eksternal mana pun.',
        ];

        File::put(
            $rootPath.'/metadata/sources.json',
            json_encode($sources, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        File::put($rootPath.'/DATASET.md', implode("\n", [
            '# Dataset Visual Search ATK (Agung ATK)',
            '',
            'Dataset privat untuk pengenalan barang ATK dari foto. Disusun dari:',
            '',
            '1. `DatasetBarangATK.zip` (images.cv) — kelas `office`, `scissors` (146 gambar).',
            '2. Ekspor images.cv/Roboflow "office items" — 15 kelas (1914 gambar).',
            '',
            'Kelas ATK yang dikenali: office_supplies, pen, notebook, scissors, stapler,',
            'puncher, ring_binder, tape, letter_tray.',
            'Kelas NON-ATK (calculator, desk_chair, desk_lamp, file_cabinet, laptop_computer,',
            'printer, trash_can) disimpan HANYA sebagai materi penolakan gambar.',
            '',
            'Struktur: `train/`, `validation/`, `test/` per kelas (folder kelas = format',
            'klasifikasi gambar). Indeks fitur & laporan evaluasi ada di `index/` dan',
            '`metadata/`.',
            '',
            'Lisensi: metadata ZIP tidak memuat lisensi eksplisit — verifikasi halaman sumber',
            'sebelum penggunaan komersial. Lihat `metadata/sources.json`.',
            '',
            'Perintah terkait:',
            '- `php artisan atk:prepare-dataset` — susun dataset kanonik dari raw/.',
            '- `php artisan atk:image-index` — bangun indeks fitur (train+validation).',
            '- `php artisan atk:image-evaluate` — evaluasi pada split test.',
        ]));
    }
}
