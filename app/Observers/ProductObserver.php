<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\Notifications\StockLevelNotifier;

class ProductObserver
{
    public function __construct(private readonly StockLevelNotifier $stockNotifier) {}

    /**
     * Perubahan stok / stok minimum produk -> periksa ambang stok rendah & habis.
     *
     * Pembuatan produk sengaja tidak memicu notifikasi (seeder & form admin
     * membuat banyak produk sekaligus); pemicunya adalah PERUBAHAN stok.
     */
    public function updated(Product $product): void
    {
        if ($product->wasChanged('stock') || $product->wasChanged('min_stock')) {
            $this->stockNotifier->check($product);
        }
    }
}
