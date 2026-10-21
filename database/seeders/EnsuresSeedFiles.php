<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\Storage;

trait EnsuresSeedFiles
{
    /**
     * Salin aset gambar dari folder database/seeders/images ke disk target
     * bila file belum ada.
     *
     * Seeder mereferensikan path gambar (produk & bukti pembayaran/pengiriman);
     * tanpa langkah ini halaman menampilkan gambar rusak bila file belum tersedia
     * (misalnya pada instalasi baru).
     */
    private function ensureSeedFile(string $disk, string $path): void
    {
        $source = base_path('database/seeders/images/'.$path);

        if (Storage::disk($disk)->exists($path) || ! is_file($source)) {
            return;
        }

        Storage::disk($disk)->put($path, (string) file_get_contents($source));
    }
}
