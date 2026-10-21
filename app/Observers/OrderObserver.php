<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Notifications\NotificationManager;

class OrderObserver
{
    /**
     * Pemetaan transisi status pesanan ke kejadian notifikasi.
     *
     * event -> kunci kejadian pada config/notifications.php
     * from  -> status asal yang diizinkan (null = status apa pun)
     *
     * @var array<string, array{event: string, from: list<string>|null}>
     */
    private const STATUS_EVENTS = [
        'paid' => ['event' => 'order.paid', 'from' => ['pending']],
        'processing' => ['event' => 'order.processing', 'from' => ['paid']],
        'ready_to_ship' => ['event' => 'order.ready_to_ship', 'from' => ['processing']],
        'cancelled' => ['event' => 'order.cancelled', 'from' => null],
    ];

    public function __construct(private readonly NotificationManager $notifications) {}

    /**
     * Pesanan baru dibuat: notifikasi admin/penjual + konfirmasi & instruksi
     * pembayaran untuk pelanggan. Berjalan dalam transaksi checkout, sehingga
     * pengiriman ditunda sampai commit (afterCommit).
     */
    public function created(Order $order): void
    {
        $this->notifications->dispatch('order.new', ['order' => $order]);
        $this->notifications->dispatch('order.created', ['order' => $order]);
        $this->notifications->dispatch('payment.instructions', ['order' => $order]);
    }

    /**
     * Perubahan status pesanan -> kejadian yang sesuai.
     *
     * Status shipping/delivered tidak ditangani di sini: pengirimannya
     * ditangani ShipmentObserver agar tidak terjadi notifikasi ganda.
     */
    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        $transition = self::STATUS_EVENTS[$order->status] ?? null;

        if ($transition === null) {
            return;
        }

        $originalStatus = (string) $order->getOriginal('status');
        $allowedFrom = $transition['from'];

        if ($allowedFrom !== null && ! in_array($originalStatus, $allowedFrom, true)) {
            return;
        }

        $this->notifications->dispatch($transition['event'], ['order' => $order]);
    }
}
