<?php

namespace App\Services\Notifications;

use App\Models\Product;

class StockLevelNotifier
{
    public function __construct(private readonly NotificationManager $manager) {}

    /**
     * Periksa ambang stok produk dan kirim notifikasi bila tersentuh.
     *
     * - stok = 0                -> kejadian 'stock.out'
     * - 0 < stok <= min_stock   -> kejadian 'stock.low'
     *
     * Dipanggil oleh ProductObserver (ubah stok via admin/edit produk) dan
     * CheckoutController (pengurangan stok memakai query builder sehingga
     * tidak memicu event model). Anti-duplikat ditangani NotificationManager.
     */
    public function check(Product $product): void
    {
        $stock = (int) $product->stock;

        if ($stock <= 0) {
            $this->manager->dispatch('stock.out', ['product' => $product]);

            return;
        }

        if ($stock <= (int) $product->min_stock) {
            $this->manager->dispatch('stock.low', ['product' => $product]);
        }
    }
}
