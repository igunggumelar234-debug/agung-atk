<?php

namespace App\Support;

/**
 * Status pengiriman (shipment) beserta aturan transisinya.
 *
 * Alur: waiting → picked_up → shipping → delivered
 */
class ShipmentStatus
{
    public const STATUSES = ['waiting', 'picked_up', 'shipping', 'delivered'];

    public const LABELS = [
        'waiting' => 'Menunggu Diambil',
        'picked_up' => 'Diambil Kurir',
        'shipping' => 'Dalam Perjalanan',
        'delivered' => 'Terkirim',
    ];

    /**
     * Status berikutnya yang sah dari status saat ini (null = sudah final).
     */
    public static function next(?string $current): ?string
    {
        return match ($current) {
            'waiting' => 'picked_up',
            'picked_up' => 'shipping',
            'shipping' => 'delivered',
            default => null,
        };
    }

    public static function labels(): array
    {
        return self::LABELS;
    }
}
