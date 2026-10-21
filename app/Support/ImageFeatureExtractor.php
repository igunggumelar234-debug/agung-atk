<?php

namespace App\Support;

/**
 * Ekstraktor fitur visual untuk Pencarian Produk ATK Berbasis Gambar (Visual Search).
 *
 * Semua perhitungan dilakukan murni di PHP dengan ekstensi GD (tanpa layanan
 * eksternal dan tanpa model ML tambahan). Satu gambar dikonversi menjadi vektor
 * fitur berdimensi tetap yang menggabungkan:
 *
 *  1. hue_sat  — histogram warna HSV (hue12 bin × saturasi 2 bin +2 bin grayscale).
 *  2. value    — histogram kecerahan (8 bin).
 *  3. grid     — warna rata-rata grid 4×4 (RGB) sebagai representasi spasial.
 *  4. silhouette — peta siluet objek 8×8 (piksel foreground vs latar terang).
 *  5. edges    — histogram orientasi tepi (Sobel,8 bin) + kepadatan tepi.
 *  6. csLbp    — histogram tekstur csLBP4×4 sel (16 bin/sel).
 *  7. aspect   — rasio aspek gambar.
 *
 * Tiap blok dinormalisasi (L2) lalu digabung dengan bobot sehingga jarak
 * kosinus antar-vektor mencerminkan kemiripan bentuk + warna + tekstur.
 * Bukan sekadar membandingkan nama file atau warna dominan.
 */
class ImageFeatureExtractor
{
    /** Ukuran kanonik gambar kerja (dataset sumber juga64×64). */
    public const SIZE = 64;

    /**
     * Bobot per blok fitur (dijumlahkan saat pembentukan vektor datar).
     *
     * @var array<string, float>
     */
    public const WEIGHTS = [
        'hue_sat' => 0.18,
        'value' => 0.04,
        'grid' => 0.15,
        'silhouette' => 0.24,
        'edges' => 0.12,
        'lbp' => 0.22,
        'aspect' => 0.05,
    ];

    /**
     * Ekstrak vektor fitur dari data biner gambar.
     *
     * @return array{blocks: array<string, list<float>>, vector: list<float>}|null
     *                                                                             null bila gambar tidak dapat dibaca/didekode.
     */
    public function extract(string $binary): ?array
    {
        if ($binary === '') {
            return null;
        }

        $src = @imagecreatefromstring($binary);
        if ($src === false) {
            return null;
        }

        $width = imagesx($src);
        $height = imagesy($src);
        if ($width < 8 || $height < 8) {
            imagedestroy($src);

            return null;
        }

        // Potong tengah menjadi persegi lalu skalakan ke ukuran kanonik.
        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);
        $side = min($width, $height);
        imagecopyresampled(
            $canvas, $src,
            0, 0, (int) (($width - $side) / 2), (int) (($height - $side) / 2),
            self::SIZE, self::SIZE, $side, $side
        );
        imagedestroy($src);

        $pixels = $this->readPixels($canvas);
        imagedestroy($canvas);

        $blocks = [
            'hue_sat' => $this->hueSatHistogram($pixels),
            'value' => $this->valueHistogram($pixels),
            'grid' => $this->colorGrid($pixels),
            'silhouette' => $this->silhouetteGrid($pixels),
            'edges' => $this->edgeHistogram($pixels),
            'lbp' => $this->csLbpGrid($pixels),
            'aspect' => $this->aspectFeature($width, $height),
        ];

        $vector = [];
        foreach ($blocks as $name => $block) {
            $normalized = $this->l2Normalize($block);
            $weight = self::WEIGHTS[$name] ?? 0.0;
            foreach ($normalized as $value) {
                $vector[] = $value * $weight;
            }
        }

        return ['blocks' => $blocks, 'vector' => $this->l2Normalize($vector)];
    }

    /**
     * Hitung vektor fitur dari file gambar di disk.
     *
     * @return array{blocks: array<string, list<float>>, vector: list<float>}|null
     */
    public function extractFromFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $binary = @file_get_contents($path);
        if ($binary === false) {
            return null;
        }

        return $this->extract($binary);
    }

    /**
     * Baca piksel kanonik menjadi larik [y][x] = [r, g, b].
     *
     * @return list<list<array{0: int, 1: int, 2: int}>>
     */
    private function readPixels($image): array
    {
        $pixels = [];
        for ($y = 0; $y < self::SIZE; $y++) {
            $row = [];
            for ($x = 0; $x < self::SIZE; $x++) {
                $color = imagecolorat($image, $x, $y);
                $row[] = [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
            }
            $pixels[] = $row;
        }

        return $pixels;
    }

    /**
     * Histogram warna HSV:12 bin hue ×2 bin saturasi +2 bin grayscale.
     *
     * @param  list<list<array{0: int, 1: int, 2: int}>>  $pixels
     * @return list<float>
     */
    private function hueSatHistogram(array $pixels): array
    {
        $bins = array_fill(0, 26, 0.0);

        foreach ($pixels as $row) {
            foreach ($row as [$r, $g, $b]) {
                [$h, $s, $v] = $this->rgbToHsv($r, $g, $b);

                if ($s < 0.15) {
                    // Piksel hampir tanpa warna → klasifikasi grayscale terang/gelap.
                    $bins[$v < 0.45 ? 24 : 25] += 1.0;

                    continue;
                }

                $hueBin = min(11, (int) floor($h * 12));
                $satBin = $s < 0.5 ? 0 : 1;
                $bins[$hueBin * 2 + $satBin] += 1.0;
            }
        }

        return $bins;
    }

    /**
     * Histogram kecerahan (8 bin).
     *
     * @param  list<list<array{0: int, 1: int, 2: int}>>  $pixels
     * @return list<float>
     */
    private function valueHistogram(array $pixels): array
    {
        $bins = array_fill(0, 8, 0.0);

        foreach ($pixels as $row) {
            foreach ($row as [$r, $g, $b]) {
                $v = max($r, $g, $b) / 255;
                $bins[min(7, (int) floor($v * 8))] += 1.0;
            }
        }

        return $bins;
    }

    /**
     * Warna rata-rata tiap sel grid4×4 (RGB ternormalisasi).
     *
     * @param  list<list<array{0: int, 1: int, 2: int}>>  $pixels
     * @return list<float>
     */
    private function colorGrid(array $pixels): array
    {
        $cell = intdiv(self::SIZE, 4);
        $grid = [];

        for ($cy = 0; $cy < 4; $cy++) {
            for ($cx = 0; $cx < 4; $cx++) {
                $sum = [0, 0, 0];
                $count = 0;
                for ($y = $cy * $cell; $y < ($cy + 1) * $cell; $y++) {
                    for ($x = $cx * $cell; $x < ($cx + 1) * $cell; $x++) {
                        $sum[0] += $pixels[$y][$x][0];
                        $sum[1] += $pixels[$y][$x][1];
                        $sum[2] += $pixels[$y][$x][2];
                        $count++;
                    }
                }
                foreach ([0, 1, 2] as $channel) {
                    $grid[] = $count > 0 ? ($sum[$channel] / $count) / 255 : 0.0;
                }
            }
        }

        return $grid;
    }

    /**
     * Peta siluet objek8×8: fraksi piksel foreground per sel.
     *
     * Latar diperkirakan dari warna median bingkai ( tepi gambar ) — median
     * lebih tahan terhadap objek yang menyentuh tepi dibanding rata-rata.
     * Piksel yang jauh dari warna latar dianggap objek.
     *
     * @param  list<list<array{0: int, 1: int, 2: int}>>  $pixels
     * @return list<float>
     */
    private function silhouetteGrid(array $pixels): array
    {
        $borderR = [];
        $borderG = [];
        $borderB = [];
        $last = self::SIZE - 1;

        for ($i = 0; $i < self::SIZE; $i++) {
            foreach ([[$i, 0], [$i, $last], [0, $i], [$last, $i]] as [$x, $y]) {
                $borderR[] = $pixels[$y][$x][0];
                $borderG[] = $pixels[$y][$x][1];
                $borderB[] = $pixels[$y][$x][2];
            }
        }
        sort($borderR);
        sort($borderG);
        sort($borderB);
        $mid = intdiv(count($borderR), 2);
        $bg = [$borderR[$mid], $borderG[$mid], $borderB[$mid]];

        $cell = intdiv(self::SIZE, 8);
        $map = [];

        for ($cy = 0; $cy < 8; $cy++) {
            for ($cx = 0; $cx < 8; $cx++) {
                $foreground = 0;
                $count = 0;
                for ($y = $cy * $cell; $y < ($cy + 1) * $cell; $y++) {
                    for ($x = $cx * $cell; $x < ($cx + 1) * $cell; $x++) {
                        $diff = max(
                            abs($pixels[$y][$x][0] - $bg[0]),
                            abs($pixels[$y][$x][1] - $bg[1]),
                            abs($pixels[$y][$x][2] - $bg[2])
                        );
                        if ($diff > 28) {
                            $foreground++;
                        }
                        $count++;
                    }
                }
                $map[] = $count > 0 ? $foreground / $count : 0.0;
            }
        }

        return $map;
    }

    /**
     * Histogram orientasi tepi Sobel (8 bin) + kepadatan tepi.
     *
     * @param  list<list<array{0: int, 1: int, 2: int}>>  $pixels
     * @return list<float>
     */
    private function edgeHistogram(array $pixels): array
    {
        $size = self::SIZE;
        $bins = array_fill(0, 8, 0.0);
        $edges = 0.0;
        $total = 0;

        for ($y = 1; $y < $size - 1; $y++) {
            for ($x = 1; $x < $size - 1; $x++) {
                $gx = $this->sobelX($pixels, $x, $y);
                $gy = $this->sobelY($pixels, $x, $y);
                $magnitude = hypot($gx, $gy);
                $total++;

                if ($magnitude < 40) {
                    continue;
                }

                $edges += 1.0;
                $angle = atan2($gy, $gx) + M_PI; // 0..2π
                $bin = min(7, (int) floor($angle / (M_PI / 4)));
                $bins[$bin] += 1.0;
            }
        }

        $bins[] = $total > 0 ? $edges / $total : 0.0;

        return $bins;
    }

    /**
     * Histogram tekstur csLBP ( center-symmetric Local Binary Pattern ):
     *4 bit per piksel dari perbandingan pasangan simetrik →16 bin per sel
     * grid4×4 (=256 dimensi). Membedakan tekstur logam, plastik, dan kertas
     * yang sulit dibedakan oleh warna saja.
     *
     * @param  list<list<array{0: int, 1: int, 2: int}>>  $pixels
     * @return list<float>
     */
    private function csLbpGrid(array $pixels): array
    {
        $size = self::SIZE;
        $cell = intdiv($size, 4);
        $threshold = 5.0;
        $grid = array_fill(0, 4 * 4 * 16, 0.0);

        for ($y = 1; $y < $size - 1; $y++) {
            for ($x = 1; $x < $size - 1; $x++) {
                $center = $this->gray($pixels, $x, $y);
                $code = 0;
                $pairs = [
                    [$x - 1, $y], [$x + 1, $y],
                    [$x, $y - 1], [$x, $y + 1],
                    [$x - 1, $y - 1], [$x + 1, $y + 1],
                    [$x + 1, $y - 1], [$x - 1, $y + 1],
                ];
                for ($bit = 0; $bit < 4; $bit++) {
                    $p = $this->gray($pixels, $pairs[$bit * 2][0], $pairs[$bit * 2][1]);
                    $q = $this->gray($pixels, $pairs[$bit * 2 + 1][0], $pairs[$bit * 2 + 1][1]);
                    if ($p - $q > $threshold) {
                        $code |= (1 << $bit);
                    }
                }
                $cellX = min(3, intdiv($x, $cell));
                $cellY = min(3, intdiv($y, $cell));
                $grid[($cellY * 4 + $cellX) * 16 + $code] += 1.0;
            }
        }

        return $grid;
    }

    /**
     * Fitur rasio aspek (dibungkus log, di-clamp agar stabil).
     *
     * @return list<float>
     */
    private function aspectFeature(int $width, int $height): array
    {
        $ratio = max(0.2, min(5.0, $width / max(1, $height)));

        return [log($ratio) / log(5.0)];
    }

    /** Grayscale lokal untuk operator Sobel. */
    private function gray(array $pixels, int $x, int $y): float
    {
        [$r, $g, $b] = $pixels[$y][$x];

        return 0.299 * $r + 0.587 * $g + 0.114 * $b;
    }

    private function sobelX(array $pixels, int $x, int $y): float
    {
        $k = [
            [-1, 0, 1],
            [-2, 0, 2],
            [-1, 0, 1],
        ];

        return $this->applyKernel($pixels, $x, $y, $k);
    }

    private function sobelY(array $pixels, int $x, int $y): float
    {
        $k = [
            [1, 2, 1],
            [0, 0, 0],
            [-1, -2, -1],
        ];

        return $this->applyKernel($pixels, $x, $y, $k);
    }

    /**
     * @param  list<list<int>>  $kernel
     */
    private function applyKernel(array $pixels, int $x, int $y, array $kernel): float
    {
        $sum = 0.0;
        for ($ky = -1; $ky <= 1; $ky++) {
            for ($kx = -1; $kx <= 1; $kx++) {
                $sum += $this->gray($pixels, $x + $kx, $y + $ky) * $kernel[$ky + 1][$kx + 1];
            }
        }

        return $sum;
    }

    /**
     * Konversi RGB → HSV (h:0..1, s:0..1, v:0..1).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function rgbToHsv(int $r, int $g, int $b): array
    {
        $rn = $r / 255;
        $gn = $g / 255;
        $bn = $b / 255;

        $max = max($rn, $gn, $bn);
        $min = min($rn, $gn, $bn);
        $delta = $max - $min;

        if ($delta == 0) {
            $h = 0.0;
        } elseif ($max == $rn) {
            $h = fmod((($gn - $bn) / $delta), 6) / 6;
        } elseif ($max == $gn) {
            $h = ((($bn - $rn) / $delta) + 2) / 6;
        } else {
            $h = ((($rn - $gn) / $delta) + 4) / 6;
        }
        if ($h < 0) {
            $h += 1;
        }

        $s = $max == 0 ? 0.0 : $delta / $max;

        return [$h, $s, $max];
    }

    /**
     * Normalisasi L2 sebuah vektor (aman terhadap vektor nol).
     *
     * @param  list<float>  $vector
     * @return list<float>
     */
    public function l2Normalize(array $vector): array
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
