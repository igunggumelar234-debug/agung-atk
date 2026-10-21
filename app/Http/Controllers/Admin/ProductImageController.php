<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductImageController extends Controller
{
    /**
     * Upload satu atau beberapa gambar baru untuk produk tertentu.
     */
    public function store(Request $request, Product $product): RedirectResponse
    {
        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:8'],
            'images.*' => ['image', 'mimes:jpeg,png,jpg,webp', 'max:3072'],
        ], [
            'images.required' => 'Pilih minimal satu berkas gambar.',
            'images.*.image' => 'Berkas harus berupa gambar.',
            'images.*.mimes' => 'Format gambar harus jpeg, png, jpg, atau webp.',
            'images.*.max' => 'Ukuran setiap gambar maksimal 3MB.',
        ]);

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

        return back()->with('success', 'Gambar produk berhasil diunggah.');
    }

    /**
     * Tentukan gambar terpilih sebagai gambar utama produk.
     */
    public function setPrimary(Product $product, ProductImage $image): RedirectResponse
    {
        if ($image->product_id !== $product->id) {
            abort(404, 'Gambar tidak ditemukan pada produk ini.');
        }

        // Reset semua gambar produk ini menjadi non-utama
        $product->productImages()->update(['is_primary' => false]);

        // Jadikan gambar ini sebagai gambar utama
        $image->update(['is_primary' => true]);

        return back()->with('success', 'Gambar utama produk berhasil diperbarui.');
    }

    /**
     * Hapus gambar produk dari database dan storage.
     */
    public function destroy(Product $product, ProductImage $image): RedirectResponse
    {
        if ($image->product_id !== $product->id) {
            abort(404, 'Gambar tidak ditemukan pada produk ini.');
        }

        $wasPrimary = $image->is_primary;

        if (Storage::disk('public')->exists($image->image_path)) {
            Storage::disk('public')->delete($image->image_path);
        }

        $image->delete();

        // Jika gambar yang dihapus adalah gambar utama, alihkan gambar utama ke gambar berikutnya
        if ($wasPrimary) {
            $nextImage = $product->productImages()->orderBy('sort_order')->first();
            if ($nextImage) {
                $nextImage->update(['is_primary' => true]);
            }
        }

        return back()->with('success', 'Gambar produk berhasil dihapus.');
    }
}
