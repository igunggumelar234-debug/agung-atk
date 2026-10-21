<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    /**
     * Simpan review pelanggan untuk produk yang benar-benar sudah dibeli.
     *
     * Aturan server-side:
     * - pesanan harus milik pelanggan yang login;
     * - pesanan harus berstatus completed;
     * - produk harus termasuk dalam pesanan;
     * - satu review per (pesanan, produk, pelanggan) — anti duplikat;
     * - rating 1..5, komentar 3..1000 karakter.
     */
    public function store(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->is_active, 404);

        $user = $request->user();

        $data = $request->validate([
            'order_id' => ['required', 'integer', Rule::exists('orders', 'id')->where('user_id', $user->id)],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'order_id.required' => 'Pesanan harus dipilih.',
            'order_id.exists' => 'Pesanan tidak ditemukan atau bukan milik Anda.',
            'rating.required' => 'Rating wajib dipilih.',
            'rating.integer' => 'Rating harus berupa angka.',
            'rating.min' => 'Rating minimal 1.',
            'rating.max' => 'Rating maksimal 5.',
            'comment.required' => 'Komentar wajib diisi.',
            'comment.min' => 'Komentar minimal 3 karakter.',
            'comment.max' => 'Komentar maksimal 1000 karakter.',
        ]);

        $order = Order::with('orderDetails')->findOrFail($data['order_id']);

        if ($order->status !== 'completed') {
            return back()->withErrors([
                'order_id' => 'Review hanya dapat diberikan untuk pesanan yang sudah selesai (COMPLETED). Status saat ini: '
                    .strtoupper($order->status).'.',
            ]);
        }

        if (! $order->orderDetails->contains('product_id', $product->id)) {
            return back()->withErrors([
                'order_id' => 'Produk ini tidak termasuk dalam pesanan yang dipilih.',
            ]);
        }

        $alreadyReviewed = Review::where('order_id', $order->id)
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyReviewed) {
            return back()->withErrors([
                'order_id' => 'Anda sudah memberikan review untuk produk ini pada pesanan tersebut.',
            ]);
        }

        try {
            DB::transaction(function () use ($user, $order, $product, $data) {
                Review::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'rating' => $data['rating'],
                    'comment' => $data['comment'],
                    'is_approved' => true,
                ]);
            });
        } catch (QueryException $e) {
            // Pelanggaran unique constraint (order, product, user) sebagai jaring pengaman terakhir.
            if ($e->getCode() === '23000') {
                return back()->withErrors([
                    'order_id' => 'Anda sudah memberikan review untuk produk ini pada pesanan tersebut.',
                ]);
            }

            throw $e;
        }

        return redirect()
            ->route('products.show', $product)
            ->with('success', 'Terima kasih! Review Anda untuk "'.$product->name.'" telah tersimpan.');
    }
}
