<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;

/**
 * Jejak audit aktivitas pengguna (siapa, peran, apa, objek, kapan).
 * Ringkasan hanya berisi informasi aman — tanpa kata sandi/token.
 */
class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'role',
        'activity_type',
        'subject_type',
        'subject_id',
        'summary',
    ];

    /**
     * Pengguna yang melakukan aktivitas (null bila sistem/tamu).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Objek terkait (Order, Product, User, Shipment, dst.).
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Catat aktivitas dengan identitas & role pengguna saat ini.
     *
     * @param  string  $activityType  Jenis aktivitas, mis. 'stock.adjusted', 'user.created'
     * @param  Model|null  $subject  Objek terkait (opsional)
     * @param  string|null  $summary  Ringkasan aman dari perubahan (opsional)
     */
    public static function record(string $activityType, ?Model $subject = null, ?string $summary = null): ?self
    {
        $user = Auth::user();

        return self::create([
            'user_id' => $user?->id,
            'role' => $user?->role?->name,
            'activity_type' => $activityType,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'summary' => $summary,
        ]);
    }
}
