<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\Notifications\NotificationManager;

class PaymentObserver
{
    public function __construct(private readonly NotificationManager $notifications) {}

    /**
     * Perubahan pembayaran:
     * - bukti diunggah pelanggan   -> notifikasi admin
     * - pending -> verified/rejected -> notifikasi admin & pelanggan
     *
     * Berjalan di dalam transaksi verifikasi; pengiriman ditunda sampai commit.
     */
    public function updated(Payment $payment): void
    {
        if ($payment->wasChanged('proof_image') && filled($payment->proof_image)) {
            $this->notifications->dispatch('payment.proof_uploaded', [
                'payment' => $payment,
                'order' => $payment->order,
            ]);
        }

        if (! $payment->wasChanged('status')) {
            return;
        }

        // Hanya transisi dari 'pending' yang dipantau (sekali per kejadian).
        if ((string) $payment->getOriginal('status') !== 'pending') {
            return;
        }

        $event = match ($payment->status) {
            'verified' => 'payment.verified',
            'rejected' => 'payment.rejected',
            default => null,
        };

        if ($event !== null) {
            $this->notifications->dispatch($event, [
                'payment' => $payment,
                'order' => $payment->order,
            ]);
        }
    }
}
