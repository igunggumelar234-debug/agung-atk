<?php

namespace App\Services\Channels;

use App\Models\ChannelIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Tautan akun aplikasi dengan identitas kanal (WhatsApp/Telegram)
 * melalui kode verifikasi — bukan sekadar username atau nomor mentah.
 */
class ChannelIdentityService
{
    /** Batas kegagalan verifikasi per identifier (anti brute-force kode). */
    public const MAX_VERIFY_ATTEMPTS = 5;

    /** Jendela penguncian setelah kegagalan beruntun (menit). */
    public const LOCKOUT_MINUTES = 15;

    /**
     * Buat kode verifikasi baru untuk pengguna + kanal.
     *
     * Kode pending lama pada kanal yang sama dihapus sehingga hanya
     * kode terbaru yang berlaku (10 menit sejak diminta).
     */
    public function requestCode(User $user, string $channel): ChannelIdentity
    {
        $this->assertChannel($channel);

        ChannelIdentity::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->where('status', ChannelIdentity::STATUS_PENDING)
            ->delete();

        return ChannelIdentity::create([
            'user_id' => $user->id,
            'channel' => $channel,
            'identifier' => null,
            'status' => ChannelIdentity::STATUS_PENDING,
            'verification_code' => (string) random_int(100000, 999999),
            'code_expires_at' => now()->addMinutes(ChannelIdentity::CODE_TTL_MINUTES),
        ]);
    }

    /**
     * Verifikasi kode yang dikirim pengguna dari kanal asal.
     *
     * @param  array<string, mixed>  $meta  data tambahan untuk log bila gagal
     * @return array{ok: bool, identity?: ChannelIdentity, error?: string}
     */
    public function verifyCode(string $channel, string $identifier, string $code, array $meta = []): array
    {
        $this->assertChannel($channel);

        $identifier = trim($identifier);
        $code = trim($code);

        if ($identifier === '' || $code === '') {
            return ['ok' => false, 'error' => 'Identifier atau kode kosong.'];
        }

        if ($this->attemptsLocked($channel, $identifier)) {
            return [
                'ok' => false,
                'error' => 'Terlalu banyak percobaan gagal. Coba lagi dalam '.self::LOCKOUT_MINUTES.' menit.',
            ];
        }

        // Identifier yang sudah terhubung ke akun lain tidak boleh dipindahkan.
        $claimed = ChannelIdentity::query()
            ->verifiedByIdentifier($channel, $identifier)
            ->first();

        if ($claimed !== null) {
            $this->clearAttempts($channel, $identifier);

            return ['ok' => false, 'error' => 'Identifier ini sudah terhubung ke akun aplikasi lain.'];
        }

        $pending = ChannelIdentity::query()
            ->where('channel', $channel)
            ->where('status', ChannelIdentity::STATUS_PENDING)
            ->where('verification_code', $code)
            ->whereNotNull('code_expires_at')
            ->where('code_expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($pending === null) {
            $this->recordFailedAttempt($channel, $identifier);

            return ['ok' => false, 'error' => 'Kode tidak ditemukan atau sudah kedaluwarsa.'];
        }

        // Bersihkan baris lain yang memakai identifier ini (sisa klaim lama)
        // agar unique constraint tidak menolak pembaruan.
        ChannelIdentity::query()
            ->where('channel', $channel)
            ->where('identifier', $identifier)
            ->delete();

        $pending->update([
            'identifier' => $identifier,
            'status' => ChannelIdentity::STATUS_VERIFIED,
            'verification_code' => null,
            'code_expires_at' => null,
            'verified_at' => now(),
        ]);

        $this->clearAttempts($channel, $identifier);

        return ['ok' => true, 'identity' => $pending->fresh()];
    }

    /**
     * Cabut tautan kanal milik pengguna.
     */
    public function unlink(User $user, string $channel): void
    {
        $this->assertChannel($channel);

        ChannelIdentity::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->delete();
    }

    /**
     * Cari identitas terverifikasi berdasarkan identifier kanal.
     */
    public function findVerified(string $channel, string $identifier): ?ChannelIdentity
    {
        return ChannelIdentity::query()
            ->verifiedByIdentifier($channel, trim($identifier))
            ->first();
    }

    /**
     * Status tautan kedua kanal milik satu pengguna.
     *
     * @return array{whatsapp: ?ChannelIdentity, telegram: ?ChannelIdentity}
     */
    public function forUser(User $user): array
    {
        return [
            ChannelIdentity::CHANNEL_WHATSAPP => ChannelIdentity::query()
                ->verifiedFor(ChannelIdentity::CHANNEL_WHATSAPP, $user->id)
                ->first(),
            ChannelIdentity::CHANNEL_TELEGRAM => ChannelIdentity::query()
                ->verifiedFor(ChannelIdentity::CHANNEL_TELEGRAM, $user->id)
                ->first(),
        ];
    }

    /**
     * Catat pesan masuk terakhir (untuk indikator aktivitas tautan).
     */
    public function touchLastMessage(?ChannelIdentity $identity): void
    {
        $identity?->update(['last_message_at' => now()]);
    }

    private function attemptsKey(string $channel, string $identifier): string
    {
        return 'chatbot:link_attempts:'.$channel.':'.$identifier;
    }

    private function attemptsLocked(string $channel, string $identifier): bool
    {
        return (int) Cache::get($this->attemptsKey($channel, $identifier), 0)
            >= self::MAX_VERIFY_ATTEMPTS;
    }

    private function recordFailedAttempt(string $channel, string $identifier): void
    {
        $key = $this->attemptsKey($channel, $identifier);
        $current = (int) Cache::get($key, 0) + 1;

        Cache::put(
            $key,
            $current,
            now()->addMinutes(self::LOCKOUT_MINUTES),
        );
    }

    private function clearAttempts(string $channel, string $identifier): void
    {
        Cache::forget($this->attemptsKey($channel, $identifier));
    }

    private function assertChannel(string $channel): void
    {
        if (! in_array($channel, [ChannelIdentity::CHANNEL_WHATSAPP, ChannelIdentity::CHANNEL_TELEGRAM], true)) {
            throw new RuntimeException('Kanal tidak dikenali: '.$channel);
        }
    }
}
