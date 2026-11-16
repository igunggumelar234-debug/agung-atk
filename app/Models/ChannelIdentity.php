<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hubungan terverifikasi antara akun aplikasi dan identitas kanal
 * (nomor WhatsApp / Chat ID Telegram) melalui kode verifikasi.
 */
class ChannelIdentity extends Model
{
    use HasFactory;

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_TELEGRAM = 'telegram';

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    /** Kode berlaku selama 10 menit sejak diminta. */
    public const CODE_TTL_MINUTES = 10;

    protected $fillable = [
        'user_id',
        'channel',
        'identifier',
        'status',
        'verification_code',
        'code_expires_at',
        'verified_at',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'code_expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * Relasi ke pemilik akun.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Identitas sudah diverifikasi (boleh memperoleh data akun pribadi).
     */
    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    /**
     * Kode verifikasi kedaluwarsa (atau baris tidak memuat kode).
     */
    public function isCodeExpired(): bool
    {
        return $this->code_expires_at === null || $this->code_expires_at->isPast();
    }

    /**
     * Ambil identitas terverifikasi milik satu pengguna.
     */
    public function scopeVerifiedFor(Builder $query, string $channel, int $userId): Builder
    {
        return $query
            ->where('channel', $channel)
            ->where('user_id', $userId)
            ->where('status', self::STATUS_VERIFIED);
    }

    /**
     * Cari identitas terverifikasi berdasarkan identifier kanal.
     */
    public function scopeVerifiedByIdentifier(Builder $query, string $channel, string $identifier): Builder
    {
        return $query
            ->where('channel', $channel)
            ->where('identifier', $identifier)
            ->where('status', self::STATUS_VERIFIED);
    }
}
