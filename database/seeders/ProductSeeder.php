<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    use EnsuresSeedFiles;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $kertas = Category::where('slug', 'kertas-dan-karton')->first();
        $alatTulis = Category::where('slug', 'alat-tulis-dan-menggambar')->first();
        $buku = Category::where('slug', 'buku-dan-binder')->first();
        $arsip = Category::where('slug', 'pengarsipan-dan-map')->first();
        $meja = Category::where('slug', 'perlengkapan-meja-dan-kantor')->first();
        $perekat = Category::where('slug', 'alat-perekat-dan-koreksi')->first();

        $products = [
            [
                'category_id' => $kertas->id,
                'name' => 'Kertas HVS PaperOne A4 75gr (Rim)',
                'slug' => 'kertas-hvs-paperone-a4-75gr-rim',
                'sku' => 'ATK-KRT-001',
                'description' => 'Kertas HVS PaperOne ukuran A4 dengan gramatur 75 GSM. Isi 500 lembar per rim. Derajat putih tinggi dan tidak mudah tembus tinta. Cocok untuk dokumen kantor dan cetak tugas.',
                'price' => 48500,
                'cost_price' => 42000,
                'weight' => 2350, // 2.35 kg
                'stock' => 120,
                'min_stock' => 15,
                'is_active' => true,
                'image' => 'products/paperone-a4-75.jpg',
            ],
            [
                'category_id' => $kertas->id,
                'name' => 'Kertas HVS Sinar Dunia F4/Folio 70gr (Rim)',
                'slug' => 'kertas-hvs-sinar-dunia-f4-70gr-rim',
                'sku' => 'ATK-KRT-002',
                'description' => 'Kertas HVS SIDU ukuran Folio (F4) 70 GSM. Isi 500 lembar per rim. Standar berkas administrasi dinas dan instansi di Kuningan.',
                'price' => 52000,
                'cost_price' => 45000,
                'weight' => 2500,
                'stock' => 85,
                'min_stock' => 10,
                'is_active' => true,
                'image' => 'products/sidu-f4-70.jpg',
            ],
            [
                'category_id' => $alatTulis->id,
                'name' => 'Pulpen Gel Pilot G2 0.5mm Hitam (Pcs)',
                'slug' => 'pulpen-gel-pilot-g2-05mm-hitam-pcs',
                'sku' => 'ATK-PLP-001',
                'description' => 'Pulpen gel tinta hitam pekat dengan mata pena 0.5mm. Pegangan karet empuk dan lancar digunakan untuk tanda tangan resmi.',
                'price' => 17500,
                'cost_price' => 14000,
                'weight' => 25,
                'stock' => 200,
                'min_stock' => 20,
                'is_active' => true,
                'image' => 'products/pilot-g2-hitam.jpg',
            ],
            [
                'category_id' => $alatTulis->id,
                'name' => 'Pulpen Standard AE7 0.5mm Hitam (Pack 12 Pcs)',
                'slug' => 'pulpen-standard-ae7-05mm-hitam-pack',
                'sku' => 'ATK-PLP-002',
                'description' => 'Pulpen sejuta umat Standard AE7 warna hitam 0.5mm. 1 pack isi 12 pcs. Pilihan hemat untuk kantor dan sekolah.',
                'price' => 24000,
                'cost_price' => 19500,
                'weight' => 120,
                'stock' => 150,
                'min_stock' => 15,
                'is_active' => true,
                'image' => 'products/standard-ae7.jpg',
            ],
            [
                'category_id' => $buku->id,
                'name' => 'Buku Tulis Kiky 38 Lembar (Pack 10 Buku)',
                'slug' => 'buku-tulis-kiky-38-lembar-pack-10-buku',
                'sku' => 'ATK-BKU-001',
                'description' => 'Buku tulis berkualitas merk Kiky isi 38 lembar per buku. 1 pack berisi 10 buku tulis dengan motif sampul menarik.',
                'price' => 38000,
                'cost_price' => 31000,
                'weight' => 1200,
                'stock' => 60,
                'min_stock' => 10,
                'is_active' => true,
                'image' => 'products/buku-kiky-38.jpg',
            ],
            [
                'category_id' => $arsip->id,
                'name' => 'Ordner Bantex F4 7cm Biru (1465-01)',
                'slug' => 'ordner-bantex-f4-7cm-biru',
                'sku' => 'ATK-ARS-001',
                'description' => 'Ordner arsip premium Bantex ukuran Folio punggung 7 cm warna biru. Bahan karton tebal berlapis PVC tahan lembap.',
                'price' => 34500,
                'cost_price' => 28000,
                'weight' => 500,
                'stock' => 45,
                'min_stock' => 8,
                'is_active' => true,
                'image' => 'products/bantex-ordner-f4.jpg',
            ],
            [
                'category_id' => $meja->id,
                'name' => 'Stapler Joyko HD-10D + Free Isi Staples No. 10',
                'slug' => 'stapler-joyko-hd-10d-free-isi',
                'sku' => 'ATK-MJA-001',
                'description' => 'Alat hekter Joyko HD-10D bodi stainless kokoh bonus 1 box isi staples No. 10. Mampu menjepit hingga 20 lembar kertas.',
                'price' => 16000,
                'cost_price' => 12500,
                'weight' => 150,
                'stock' => 75,
                'min_stock' => 10,
                'is_active' => true,
                'image' => 'products/joyko-hd10d.jpg',
            ],
            [
                'category_id' => $perekat->id,
                'name' => 'Correction Tape Joyko CT-522 (Koreksi Kertas)',
                'slug' => 'correction-tape-joyko-ct-522',
                'sku' => 'ATK-PRK-001',
                'description' => 'Tipex pita kering Joyko CT-522 panjang 12 meter. Langsung kering dan dapat ditimpa tulisan tanpa noda.',
                'price' => 8500,
                'cost_price' => 6000,
                'weight' => 30,
                'stock' => 180,
                'min_stock' => 20,
                'is_active' => true,
                'image' => 'products/joyko-ct522.jpg',
            ],
        ];

        foreach ($products as $index => $pData) {
            $imagePath = $pData['image'];
            unset($pData['image']);

            // Barcode EAN-13-like untuk uji fitur scan kamera (899 + nomor urut).
            $barcode = '899'.str_pad((string) ($index + 1), 10, '0', STR_PAD_LEFT);
            $pData['barcode'] = $barcode;

            $product = Product::firstOrCreate(['sku' => $pData['sku']], $pData);

            // Self-heal: produk yang sudah terlanjur ada tanpa barcode diisi sekarang.
            if ($product->barcode === null) {
                $product->update(['barcode' => $barcode]);
            }

            ProductImage::firstOrCreate(
                ['product_id' => $product->id, 'image_path' => $imagePath],
                [
                    'is_primary' => true,
                    'sort_order' => 1,
                ]
            );

            $this->ensureSeedFile('public', $imagePath);
        }
    }
}
