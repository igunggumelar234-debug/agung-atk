<?php

namespace App\Observers;

use App\Models\Shipment;
use App\Services\Notifications\NotificationManager;

class ShipmentObserver
{
    public function __construct(private readonly NotificationManager $notifications) {}

    /**
     * Penugasan pengiriman baru dibuat admin -> notifikasi kurir & pelanggan.
     */
    public function created(Shipment $shipment): void
    {
        $this->notifications->dispatch('shipment.assigned', [
            'shipment' => $shipment,
            'order' => $shipment->order,
        ]);
    }

    /**
     * Kurir memperbarui status pengiriman:
     * - semua perubahan status  -> notifikasi admin (shipment.status)
     * - menuju 'shipping'       -> notifikasi pelanggan (shipment.shipping)
     * - menuju 'delivered'      -> notifikasi admin & pelanggan (shipment.delivered)
     */
    public function updated(Shipment $shipment): void
    {
        if (! $shipment->wasChanged('status')) {
            return;
        }

        $this->notifications->dispatch('shipment.status', [
            'shipment' => $shipment,
            'order' => $shipment->order,
        ]);

        if ($shipment->status === 'shipping') {
            $this->notifications->dispatch('shipment.shipping', [
                'shipment' => $shipment,
                'order' => $shipment->order,
            ]);
        }

        if ($shipment->status === 'delivered') {
            $this->notifications->dispatch('shipment.delivered', [
                'shipment' => $shipment,
                'order' => $shipment->order,
            ]);
        }
    }
}
