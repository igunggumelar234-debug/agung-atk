<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Http\Requests\Admin\StockUpdateRequest;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProductController extends Controller
{
    /**
     * Tampilkan daftar produk ATK dengan fitur pencarian, filter kategori, filter status, dan pagination.
     */
    public function index(Request $request): View
    {
        $query = Product::with(['category', 'primaryImage', 'productImages']);

        // 1. Pencarian (Nama, SKU, Merek, Deskripsi)
        if ($request->filled('q')) {
            $search = $request->string('q')->trim();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // 2. Filter Kategori
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // 3. Filter Status (Aktif / Nonaktif)
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('is_active', true);
            } elseif ($request->status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        // 4. Filter Kondisi Stok
        if ($request->filled('stock_condition')) {
            if ($request->stock_condition === 'low') {
                $query->whereColumn('stock', '<=', 'min_stock')->where('stock', '>', 0);
            } elseif ($request->stock_condition === 'out') {
                $query->where('stock', '<=', 0);
            } elseif ($request->stock_condition === 'safe') {
                $query->whereColumn('stock', '>', 'min_stock');
            }
        }

        $products = $query->latest()->paginate(10)->withQueryString();
        $categories = Category::orderBy('name')->get();

        $stats = [
            'total' => Product::count(),
            'active' => Product::where('is_active', true)->count(),
            'low_stock' => Product::lowStock()->count(),
            'out_of_stock' => Product::where('stock', '<=', 0)->count(),
        ];

        return view('admin.products.index', compact('products', 'categories', 'stats'));
    }

    /**
     * Formulir tambah produk baru.
     */
    public function create(): View
    {
        $categories = Category::where('is_active', true)->orderBy('name')->get();

        return view('admin.products.create', compact('categories'));
    }

    /**
     * Simpan produk baru ke database dan upload gambar terkait.
     */
    public function store(ProductRequest $request): RedirectResponse
    {
        DB::beginTransaction();
        try {
            $data = $request->validated();

            // Buat slug produk yang unik
            $baseSlug = Str::slug($data['name']);
            $slug = $baseSlug;
            $counter = 1;
            while (Product::where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$counter++;
            }
            $data['slug'] = $slug;
            $data['is_active'] = $request->boolean('is_active', true);

            $product = Product::create($data);

            // Upload dan simpan gambar produk jika ada
            if ($request->hasFile('images')) {
                $sortOrder = 0;
                foreach ($request->file('images') as $imageFile) {
                    $imagePath = $imageFile->store('products', 'public');
                    ProductImage::create([
                        'product_id' => $product->id,
                        'image_path' => $imagePath,
                        'is_primary' => ($sortOrder === 0), // Gambar pertama dijadikan utama
                        'sort_order' => $sortOrder++,
                    ]);
                }
            }

            DB::commit();

            return redirect()->route('admin.products.show', $product)
                ->with('success', 'Produk "'.$product->name.'" berhasil ditambahkan.');
        } catch (\Throwable $e) {
            DB::rollBack();

            // Detail teknis hanya di log server — tidak pernah ke browser
            // (pesan mentah exception bisa membocorkan struktur DB/path).
            Log::error('admin.product.store_failed', ['error' => $e->getMessage(), 'exception' => $e::class]);

            return back()->withInput()->withErrors([
                'error' => 'Gagal menyimpan produk. Silakan coba lagi; bila berulang, hubungi administrator (detail tersimpan di log).',
            ]);
        }
    }

    /**
     * Tampilkan detail lengkap produk, galeri gambar, spesifikasi, dan riwayat stok.
     */
    public function show(Product $product): View
    {
        $product->load(['category', 'productImages', 'orderDetails']);

        return view('admin.products.show', compact('product'));
    }

    /**
     * Formulir edit data produk.
     */
    public function edit(Product $product): View
    {
        $product->load(['category', 'productImages']);
        $categories = Category::orderBy('name')->get();

        return view('admin.products.edit', compact('product', 'categories'));
    }

    /**
     * Perbarui data produk dan simpan gambar baru jika diunggah.
     */
    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        DB::beginTransaction();
        try {
            $data = $request->validated();

            // Perbarui slug jika nama berubah
            if ($product->name !== $data['name']) {
                $baseSlug = Str::slug($data['name']);
                $slug = $baseSlug;
                $counter = 1;
                while (Product::where('slug', $slug)->where('id', '!=', $product->id)->exists()) {
                    $slug = $baseSlug.'-'.$counter++;
                }
                $data['slug'] = $slug;
            }

            $data['is_active'] = $request->boolean('is_active', false);
            $product->update($data);

            // Tambahkan gambar baru jika ada
            if ($request->hasFile('images')) {
                $hasPrimary = $product->productImages()->where('is_primary', true)->exists();
                $lastSort = (int) $product->productImages()->max('sort_order');

                foreach ($request->file('images') as $index => $imageFile) {
                    $imagePath = $imageFile->store('products', 'public');
                    $isPrimary = (! $hasPrimary && $index === 0);

                    ProductImage::create([
                        'product_id' => $product->id,
                        'image_path' => $imagePath,
                        'is_primary' => $isPrimary,
                        'sort_order' => $lastSort + $index + 1,
                    ]);

                    if ($isPrimary) {
                        $hasPrimary = true;
                    }
                }
            }

            DB::commit();

            return redirect()->route('admin.products.show', $product)
                ->with('success', 'Data produk "'.$product->name.'" berhasil diperbarui.');
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('admin.product.update_failed', ['error' => $e->getMessage(), 'exception' => $e::class]);

            return back()->withInput()->withErrors([
                'error' => 'Gagal memperbarui produk. Silakan coba lagi; bila berulang, hubungi administrator (detail tersimpan di log).',
            ]);
        }
    }

    /**
     * Hapus produk dan seluruh file gambarnya dari storage.
     */
    public function destroy(Product $product): RedirectResponse
    {
        // Produk dengan riwayat penjualan ditolak dengan pesan jelas:
        // FK order_details bersifat restrict, tanpa pemeriksaan ini admin
        // menerima QueryException mentah (constraint violation).
        if ($product->orderDetails()->exists()) {
            return back()->withErrors([
                'error' => 'Produk "'.$product->name.'" tidak dapat dihapus karena memiliki riwayat penjualan. Nonaktifkan produk saja (agar riwayat pesanan tetap utuh).',
            ]);
        }

        DB::beginTransaction();
        try {
            // Hapus file gambar produk dari disk
            foreach ($product->productImages as $image) {
                if (Storage::disk('public')->exists($image->image_path)) {
                    Storage::disk('public')->delete($image->image_path);
                }
                $image->delete();
            }

            $productName = $product->name;
            $product->delete();

            DB::commit();

            return redirect()->route('admin.products.index')
                ->with('success', 'Produk "'.$productName.'" berhasil dihapus.');
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('admin.product.destroy_failed', ['error' => $e->getMessage(), 'exception' => $e::class, 'product_id' => $product->id]);

            return back()->withErrors([
                'error' => 'Gagal menghapus produk. Silakan coba lagi; bila berulang, hubungi administrator (detail tersimpan di log).',
            ]);
        }
    }

    /**
     * Perbarui jumlah stok produk (tanpa mengubah data lain).
     *
     * Stok tidak boleh negatif: divalidasi oleh StockUpdateRequest (min:0)
     * dan dijaga kembali di sini sebagai lapisan pengaman kedua.
     */
    public function updateStock(StockUpdateRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();

        $newStock = (int) $data['stock'];

        if ($newStock < 0) {
            return back()->withErrors([
                'stock' => 'Stok tidak boleh negatif.',
            ]);
        }

        $payload = ['stock' => $newStock];

        if (isset($data['min_stock']) && $data['min_stock'] !== null) {
            $payload['min_stock'] = (int) $data['min_stock'];
        }

        // Kunci baris produk: stok lama dibaca & ditulis di dalam transaksi
        // sehingga angka audit ("10 -> 14") selalu faktual dan penjualan yang
        // berjalan bersamaan tidak menimpa (atau ditimpa) secara diam-diam.
        $oldStock = (int) DB::transaction(function () use ($product, $payload) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->first();
            $old = (int) $locked->stock;
            $locked->update($payload);

            return $old;
        });

        $product->refresh();

        ActivityLog::record(
            'stock.adjusted',
            $product,
            'Admin menyesuaikan stok "'.$product->name.'": '.$oldStock.' → '.$newStock
        );

        return back()->with('success', 'Stok produk "'.$product->name.'" berhasil diperbarui menjadi '.$newStock.'.');
    }

    /**
     * Ubah status aktif/nonaktif produk secara instan.
     * Dilakukan lewat query builder atomik dengan syarat nilai lama sehingga
     * klik ganda tidak mengaktifkan/menonaktifkan dua kali secara diam-diam.
     */
    public function toggleStatus(Product $product): RedirectResponse
    {
        $newStatus = ! $product->is_active;

        $affected = Product::whereKey($product->id)
            ->where('is_active', $product->is_active)
            ->update(['is_active' => $newStatus]);

        if ($affected === 0) {
            return back()->with('info', 'Status produk "'.$product->name.'" sudah diperbarui oleh aksi lain — tidak diubah lagi.');
        }

        $product->is_active = $newStatus;

        $statusLabel = $newStatus ? 'diaktifkan' : 'dinonaktifkan';

        ActivityLog::record(
            'product.status_changed',
            $product,
            'Produk "'.$product->name.'" '.$statusLabel.' oleh admin'
        );

        return back()->with('success', 'Status produk "'.$product->name.'" berhasil '.$statusLabel.'.');
    }
}
