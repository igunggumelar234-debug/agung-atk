<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    /**
     * Status pengiriman notifikasi.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'event_key',
        'channel',
        'recipient',
        'subject',
        'body',
        'status',
        'error',
        'reference',
        'order_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Pesanan yang terkait dengan notifikasi (bila relevan).
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Urutkan riwayat terbaru lebih dahulu.
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }
}
