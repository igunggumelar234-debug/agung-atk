<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Kertas & Karton',
                'slug' => 'kertas-dan-karton',
                'description' => 'Kertas HVS, folio bergaris, karton manila, kertas foto, dan plano untuk kantor dan percetakan.',
            ],
            [
                'name' => 'Alat Tulis & Menggambar',
                'slug' => 'alat-tulis-dan-menggambar',
                'description' => 'Pulpen, pensil, spidol whiteboard, highlighter, pensil warna, dan alat gambar berkualitas.',
            ],
            [
                'name' => 'Buku & Binder',
                'slug' => 'buku-dan-binder',
                'description' => 'Buku tulis sekolah, buku kas akuntansi, binder note, loose leaf, dan bloknot dinas.',
            ],
            [
                'name' => 'Pengarsipan & Map',
                'slug' => 'pengarsipan-dan-map',
                'description' => 'Ordner bantex, map snelhecter, map plastik zipper, pocket file, dan box arsip kantor.',
            ],
            [
                'name' => 'Perlengkapan Meja & Kantor',
                'slug' => 'perlengkapan-meja-dan-kantor',
                'description' => 'Stapler, perforator pembolong kertas, kalkulator, gunting, cutter, dan tempat pensil meja.',
            ],
            [
                'name' => 'Alat Perekat & Koreksi',
                'slug' => 'alat-perekat-dan-koreksi',
                'description' => 'Lakban cokelat/bening, double tape, lem kertas stick, correction tape (tipex kertas), dan staples.',
            ],
        ];

        foreach ($categories as $cat) {
            Category::firstOrCreate(['slug' => $cat['slug']], $cat);
        }
    }
}
