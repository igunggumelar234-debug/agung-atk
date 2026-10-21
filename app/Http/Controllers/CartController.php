<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\CartSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    /**
     * Halaman keranjang belanja pelanggan.
     *
     * Keranjang divalidasi ulang setiap kali dibuka: produk yang sudah
     * nonaktif/terhapus dibuang dari session, dan quantity yang melebihi
     * stok tersedia diturunkan secara otomatis.
     */
    public function index(): View
    {
        $cart = CartSession::items();

        $products = Product::with(['category', 'primaryImage'])
            ->whereIn('id', array_keys($cart))
            ->get()
            ->keyBy('id');

        $removed = [];
        $adjusted = [];

        foreach ($cart as $productId => $quantity) {
            $product = $products->get($productId);

            if (! $product || ! $product->is_active || $product->stock <= 0) {
                CartSession::remove($productId);
                $removed[] = $product?->name ?? 'Produk #'.$productId;

                continue;
            }

            if ($quantity > $product->stock) {
                CartSession::put($productId, $product->stock);
                $adjusted[] = $product->name;
            }
        }

        if ($removed !== []) {
            session()->flash('info', 'Produk berikut tidak lagi tersedia dan dihapus dari keranjang: '.implode(', ', $removed).'.');
        } elseif ($adjusted !== []) {
            session()->flash('info', 'Jumlah keranjang disesuaikan dengan stok tersedia untuk: '.implode(', ', $adjusted).'.');
        }

        // Susun daftar item untuk ditampilkan (quantity terbaru setelah sanitasi)
        $cart = CartSession::items();
        $items = [];
        foreach ($cart as $productId => $quantity) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $items[] = [
                'product' => $product,
                'quantity' => $quantity,
                'subtotal' => $quantity * (float) $product->price,
            ];
        }

        return view('cart.index', [
            'items' => $items,
            'total' => array_sum(array_column($items, 'subtotal')),
            'totalCount' => array_sum(array_column($items, 'quantity')),
        ]);
    }

    /**
     * Tambahkan produk ke keranjang.
     *
     * Validasi: quantity minimal 1, produk harus aktif, dan akumulasi
     * quantity tidak boleh melebihi stok tersedia.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ], [
            'product_id.required' => 'Produk harus dipilih.',
            'product_id.exists' => 'Produk yang dipilih tidak ditemukan.',
            'quantity.min' => 'Quantity minimal 1.',
        ]);

        $product = Product::findOrFail((int) $data['product_id']);

        if (! $product->is_active) {
            $message = 'Produk "'.$product->name.'" tidak aktif dan tidak dapat ditambahkan ke keranjang.';

            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $message], 422)
                : back()->withErrors(['product_id' => $message]);
        }

        $requested = (int) $data['quantity'];
        $current = CartSession::quantityOf($product->id);
        $total = $current + $requested;

        if ($total > $product->stock) {
            $message = 'Jumlah di keranjang akan melebihi stok tersedia. Stok "'.$product->name.'" saat ini: '.$product->stock.'.';

            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $message], 422)
                : back()->withErrors(['quantity' => $message]);
        }

        CartSession::put($product->id, $total);

        $message = 'Produk "'.$product->name.'" ditambahkan ke keranjang.';

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message])
            : back()->with('success', $message);
    }

    /**
     * Ubah quantity produk dalam keranjang.
     */
    public function update(Request $request, Product $product): RedirectResponse
    {
        if (! array_key_exists($product->id, CartSession::items())) {
            return back()->withErrors([
                'quantity' => 'Produk tersebut tidak ada di keranjang Anda.',
            ]);
        }

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ], [
            'quantity.required' => 'Quantity wajib diisi.',
            'quantity.min' => 'Quantity minimal 1.',
        ]);

        if (! $product->is_active) {
            return back()->withErrors([
                'quantity' => 'Produk "'.$product->name.'" sudah tidak aktif dan tidak dapat dibeli.',
            ]);
        }

        $quantity = (int) $data['quantity'];

        if ($quantity > $product->stock) {
            return back()->withErrors([
                'quantity' => 'Quantity tidak boleh melebihi stok tersedia ('.$product->stock.').',
            ]);
        }

        CartSession::put($product->id, $quantity);

        return back()->with('success', 'Jumlah "'.$product->name.'" diperbarui menjadi '.$quantity.'.');
    }

    /**
     * Hapus produk dari keranjang.
     */
    public function destroy(Product $product): RedirectResponse
    {
        CartSession::remove($product->id);

        return back()->with('success', 'Produk "'.$product->name.'" dihapus dari keranjang.');
    }
}
