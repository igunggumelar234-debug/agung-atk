<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    /**
     * Katalog produk publik: hanya produk aktif, dengan pencarian,
     * filter kategori, dan pagination.
     */
    public function index(Request $request): View
    {
        $query = Product::with(['category', 'primaryImage'])
            ->active()
            ->orderByDesc('id');

        // Pencarian (nama, SKU, merek, deskripsi)
        if ($request->filled('q')) {
            $search = $request->string('q')->trim();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter kategori (hanya kategori aktif yang ditawarkan)
        $categories = Category::where('is_active', true)
            ->withCount(['products' => fn ($q) => $q->active()])
            ->orderBy('name')
            ->get();

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        $products = $query->paginate(12)->withQueryString();

        return view('products.index', [
            'products' => $products,
            'categories' => $categories,
        ]);
    }

    /**
     * Hasil scan kamera (barcode/SKU) diarahkan ke produk yang cocok;
     * jika tidak ada, jatuhkan ke pencarian katalog dengan kode tersebut.
     */
    public function scan(Request $request, string $code): RedirectResponse
    {
        $code = trim($code);

        $product = Product::active()
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('sku', $code))
            ->first();

        if ($product !== null) {
            return redirect()->route('products.show', $product->slug);
        }

        return redirect()->route('products.index', ['q' => $code]);
    }

    /**
     * Detail produk berdasarkan slug. Produk nonaktif dianggap tidak ada (404).
     *
     * Termasuk ringkasan rating, daftar review, dan pesanan milik pelanggan
     * yang layak untuk diulas (completed & belum direview).
     */
    public function show(Product $product): View
    {
        abort_unless($product->is_active, 404);

        $product->load(['category', 'productImages']);

        $reviews = $product->reviews()->with('user')->latest()->get();
        $reviewCount = $reviews->count();
        $avgRating = $reviewCount > 0 ? round($reviews->avg('rating'), 1) : null;

        // Kelayakan menulis review: hanya pelanggan, pesanan completed
        // miliknya yang memuat produk ini dan belum diulas.
        $eligibleOrders = collect();
        $user = auth()->user();

        if ($user && $user->isCustomer()) {
            $reviewedOrderIds = Review::where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->pluck('order_id');

            $eligibleOrders = Order::where('user_id', $user->id)
                ->where('status', 'completed')
                ->whereHas('orderDetails', fn ($q) => $q->where('product_id', $product->id))
                ->whereNotIn('id', $reviewedOrderIds)
                ->latest()
                ->get();
        }

        return view('products.show', [
            'product' => $product,
            'reviews' => $reviews,
            'reviewCount' => $reviewCount,
            'avgRating' => $avgRating,
            'eligibleOrders' => $eligibleOrders,
        ]);
    }
}
