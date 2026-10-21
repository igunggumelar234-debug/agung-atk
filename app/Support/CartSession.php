<?php

namespace App\Support;

use Illuminate\Support\Facades\Session;

/**
 * Penyimpanan keranjang belanja berbasis session (MVP).
 *
 * Tabel cart sengaja tidak ditambahkan ke database agar skema tetap
 * konsisten dengan migration yang sudah disepakati. Struktur session:
 *
 *   'cart' => [product_id => quantity, ...]
 */
class CartSession
{
    private const KEY = 'cart';

    /**
     * Ambil seluruh isi keranjang dalam bentuk [product_id => quantity].
     *
     * @return array<int, int>
     */
    public static function items(): array
    {
        return Session::get(self::KEY, []);
    }

    /**
     * Jumlah total item (akumulasi quantity) di keranjang.
     */
    public static function count(): int
    {
        return array_sum(self::items());
    }

    /**
     * Simpan/update quantity untuk satu produk.
     */
    public static function put(int $productId, int $quantity): void
    {
        $items = self::items();
        $items[$productId] = $quantity;
        Session::put(self::KEY, $items);
    }

    /**
     * Hapus satu produk dari keranjang.
     */
    public static function remove(int $productId): void
    {
        $items = self::items();
        unset($items[$productId]);
        Session::put(self::KEY, $items);
    }

    /**
     * Kosongkan seluruh keranjang.
     */
    public static function clear(): void
    {
        Session::forget(self::KEY);
    }

    /**
     * Ambil quantity produk tertentu (0 jika tidak ada di keranjang).
     */
    public static function quantityOf(int $productId): int
    {
        return self::items()[$productId] ?? 0;
    }
}
