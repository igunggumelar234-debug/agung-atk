<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Pembatalan pesanan dengan pengembalian stok yang aman.
 *
 * Kebijakan stok (satu-satunya titik pengurangan & pengembalian):
 * - Stok DIKURANGI satu kali saat checkout dibuat (CheckoutController).
 * - Stok DIKEMBALIKAN satu kali saat pesanan dibatalkan (service ini).
 * - Pesanan completed TIDAK mengembalikan stok (barang sudah terkirim).
 *
 * Anti double-restore: baris pesanan dikunci (lockForUpdate) dan restock
 * hanya berjalan bila status belum 'cancelled'.
 */
class OrderCancellationService
{
    /**
     * Status yang boleh dibatalkan oleh Admin (belum dikirim kurir).
     *
     * @var list<string>
     */
    public const CANCELLABLE_BY_ADMIN = ['pending', 'paid', 'processing', 'ready_to_ship'];

    /**
     * Status yang boleh dibatalkan pelanggan (sendiri, sebelum bayar).
     *
     * @var list<string>
     */
    public const CANCELLABLE_BY_CUSTOMER = ['pending'];

    /**
     * Batalkan pesanan & kembalikan stok dalam satu transaksi database.
     * Pembayaran yang masih pending ikut ditolak agar tidak diverifikasi
     * untuk pesanan yang sudah batal.
     *
     * @param  string  $reason  Alasan pembatalan (dicatat di audit, tanpa data sensitif)
     * @param  list<string>|null  $allowedStatuses  Daftar status yang masih boleh
     *                                              dibatalkan — DIPERIKSA ULANG di dalam transaksi terkunci sehingga
     *                                              perubahan status bersamaan (verifikasi pembayaran / kurir mengirim)
     *                                              tidak pernah menyebabkan pembatalan + restock pada pesanan yang
     *                                              sudah terlanjur maju (TOCTOU). null = semua status kecuali cancelled.
     */
    public function cancel(Order $order, string $reason, ?array $allowedStatuses = null): bool
    {
        return DB::transaction(function () use ($order, $reason, $allowedStatuses) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            // Idempoten: batalkan dua kali tidak mengembalikan stok dua kali.
            if ($locked === null || $locked->status === 'cancelled') {
                return false;
            }

            // Validasi status TERKUNCI (bukan model route-binding yang basi):
            // bila status sudah maju (mis. paid/delivered) sejak pemeriksaan
            // pemanggil, pembatalan dibatalkan dengan aman.
            if ($allowedStatuses !== null && ! in_array($locked->status, $allowedStatuses, true)) {
                return false;
            }

            foreach ($locked->orderDetails()->get() as $detail) {
                if ($detail->product_id === null) {
                    continue;
                }

                // Query builder (increment) -> tanpa event model; tidak ada
                // notifikasi stok ganda, dan aman dari race condition.
                Product::whereKey($detail->product_id)->increment('stock', (int) $detail->quantity);
            }

            // Pembayaran masih pending -> tolak otomatis (tidak ada verifikasi
            // dana untuk pesanan yang sudah dibatalkan).
            Payment::where('order_id', $locked->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);

            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_reason' => $reason,
            ]);

            return true;
        });
    }
}
