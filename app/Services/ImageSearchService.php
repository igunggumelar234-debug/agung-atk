<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Pencarian produk katalog Agung ATK berdasarkan kelas hasil pengenalan gambar.
 *
 * Hanya produk AKTIF dari database yang dikembalikan — tidak pernah produk
 * karangan. Kecocokan dihitung dari kata kunci nama/merek/deskripsi/kategori
 * yang dipetakan per kelas ATK, lalu diperingkat (nama paling relevan, stok
 * tersedia diutamakan, produk bergambar diutamakan).
 */
class ImageSearchService
{
    /**
     * Pemetaan kelas kanonik → kata kunci pencarian katalog.
     *
     * `keywords` dicocokkan ke nama/merek/deskripsi produk (LIKE) — satu-satunya
     * syarat agar produk ikut kandidat untuk kelas spesifik, sehingga pesan
     * "berikut produk {kelas}" selalu jujur terhadap isi hasil.
     * `categories` HANYA dipakai sebagai penambah skor peringkat (bukan
     * filter), agar kelas seperti pembolong kertas tidak menampilkan produk
     * kategori terkait yang bukan termaksud. Label `office_supplies` (generik)
     * sengaja tanpa kunci → seluruh produk ATK aktif ikut dinilai.
     *
     * @var array<string, array{keywords: list<string>, categories: list<string>}>
     */
    public const LABEL_SEARCH = [
        'office_supplies' => [
            'keywords' => [],
            'categories' => [], // generic → seluruh produk ATK aktif
        ],
        'pen' => [
            'keywords' => ['pulpen', 'pen gel', 'ballpoint', 'bolpoin', 'tinta', 'pena'],
            'categories' => ['Alat Tulis & Menggambar'],
        ],
        'notebook' => [
            'keywords' => ['buku tulis', 'buku catatan', 'notebook', 'buku', 'diary'],
            'categories' => ['Buku & Binder'],
        ],
        'scissors' => [
            'keywords' => ['gunting', 'scissors'],
            'categories' => ['Alat Tulis & Menggambar', 'Perlengkapan Meja & Kantor'],
        ],
        'stapler' => [
            'keywords' => ['stapler', 'staples'],
            'categories' => ['Perlengkapan Meja & Kantor'],
        ],
        'puncher' => [
            'keywords' => ['pembolong', 'punch', 'lubang kertas'],
            'categories' => ['Perlengkapan Meja & Kantor'],
        ],
        'ring_binder' => [
            'keywords' => ['ordner', 'binder', 'map'],
            'categories' => ['Pengarsipan & Map', 'Buku & Binder'],
        ],
        'tape' => [
            'keywords' => ['selotip', 'tape', 'lakban', 'solasi', 'koreksi', 'correction'],
            'categories' => ['Alat Perekat & Koreksi'],
        ],
        'letter_tray' => [
            'keywords' => ['tempat kertas', 'tray', 'dokumen', 'arsip'],
            'categories' => ['Perlengkapan Meja & Kantor', 'Pengarsipan & Map'],
        ],
        'pencil' => [
            'keywords' => ['pensil', 'pencil'],
            'categories' => ['Alat Tulis & Menggambar'],
        ],
        'eraser' => [
            'keywords' => ['penghapus', 'eraser'],
            'categories' => ['Alat Tulis & Menggambar'],
        ],
        'ruler' => [
            'keywords' => ['penggaris', 'ruler'],
            'categories' => ['Alat Tulis & Menggambar'],
        ],
        'highlighter' => [
            'keywords' => ['stabilo', 'highlighter'],
            'categories' => ['Alat Tulis & Menggambar'],
        ],
        'marker' => [
            'keywords' => ['spidol', 'marker'],
            'categories' => ['Alat Tulis & Menggambar'],
        ],
        'compass' => [
            'keywords' => ['jangka'],
            'categories' => ['Alat Tulis & Menggambar'],
        ],
    ];

    /**
     * Cari produk katalog untuk satu kelas hasil pengenalan.
     *
     * @return list<array{
     *     id: int, name: string, category: string|null, price: float,
     *     price_formatted: string, stock: int, min_stock: int, unit: string|null,
     *     stock_available: bool, image_url: string|null, url: string
     * }>
     */
    public function searchByLabel(string $label): array
    {
        $config = self::LABEL_SEARCH[$label] ?? null;
        if ($config === null) {
            return [];
        }

        $keywords = $config['keywords'];
        $categories = $config['categories'];

        $query = Product::query()
            ->active()
            ->with(['category', 'primaryImage']);

        // Tanpa kunci (kelas generik) → seluruh produk ATK aktif jadi kandidat.
        if ($keywords !== []) {
            $query->where(function ($builder) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $like = '%'.$keyword.'%';
                    $builder->orWhere('name', 'like', $like)
                        ->orWhere('brand', 'like', $like)
                        ->orWhere('description', 'like', $like);
                }
            });
        }

        $products = $query->limit(60)->get();

        return $this->rank($products, $keywords, $categories);
    }

    /**
     * Pilih & peringkat produk: kecocokan kata kunci di nama paling berbobot,
     * lalu ketersediaan stok dan ketersediaan gambar.
     *
     * @param  Collection<int, Product>  $products
     * @param  list<string>  $keywords
     * @param  list<string>  $categories
     * @return list<array{
     *     id: int, name: string, category: string|null, price: float,
     *     price_formatted: string, stock: int, min_stock: int, unit: string|null,
     *     stock_available: bool, image_url: string|null, url: string
     * }>
     */
    private function rank(Collection $products, array $keywords, array $categories): array
    {
        $max = (int) config('services.image_search.max_results', 8);

        $scored = $products->map(function (Product $product) use ($keywords, $categories) {
            $name = mb_strtolower($product->name);
            $brand = mb_strtolower((string) $product->brand);
            $description = mb_strtolower((string) $product->description);
            $categoryName = (string) $product->category?->name;

            $score = 0.0;
            foreach ($keywords as $keyword) {
                $keyword = mb_strtolower($keyword);
                if (str_contains($name, $keyword)) {
                    $score += 6;
                }
                if ($brand !== '' && str_contains($brand, $keyword)) {
                    $score += 3;
                }
                if ($description !== '' && str_contains($description, $keyword)) {
                    $score += 1;
                }
            }
            if ($categories !== [] && in_array($categoryName, $categories, true)) {
                $score += 2;
            }
            if ($product->stock > 0) {
                $score += 2;
            }
            if ($product->primaryImage !== null) {
                $score += 1;
            }

            return ['score' => $score, 'product' => $product];
        })->filter(fn (array $row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($max)
            ->values();

        return $scored->map(function (array $row) {
            /** @var Product $product */
            $product = $row['product'];

            return [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category?->name,
                'price' => (float) $product->price,
                'price_formatted' => 'Rp '.number_format((float) $product->price, 0, ',', '.'),
                'stock' => (int) $product->stock,
                'min_stock' => (int) ($product->min_stock ?? 0),
                'unit' => $product->unit,
                'stock_available' => $product->stock > 0,
                'image_url' => $product->primaryImage
                    ? Storage::disk('public')->url($product->primaryImage->image_path)
                    : null,
                'url' => route('products.show', $product),
            ];
        })->all();
    }
}
