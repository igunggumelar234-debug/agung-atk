<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;

class ReviewController extends Controller
{
    /**
     * Hapus review yang tidak sesuai (hanya admin).
     */
    public function destroy(Review $review): RedirectResponse
    {
        $productName = $review->product?->name;

        $review->delete();

        return back()->with(
            'success',
            'Review produk'.($productName ? ' "'.$productName.'"' : '').' berhasil dihapus.'
        );
    }
}
