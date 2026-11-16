<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'role_id',
        'employee_code',
        'position',
        'employment_status',
        'hired_at',
        'name',
        'email',
        'phone',
        'avatar',
        'is_active',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'hired_at' => 'date',
        ];
    }

    /**
     * Relasi ke role pengguna.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Identitas kanal terverifikasi (WhatsApp/Telegram) milik pengguna.
     */
    public function channelIdentities(): HasMany
    {
        return $this->hasMany(ChannelIdentity::class);
    }

    /**
     * Relasi ke daftar alamat pengguna.
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * Relasi ke daftar pesanan pengguna.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Relasi ke ulasan yang dibuat pengguna.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Relasi ke sesi percakapan dengan asisten AI.
     */
    public function aiConversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    /**
     * Relasi ke pengiriman yang ditugaskan kepada kurir ini.
     */
    public function courierShipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'courier_id');
    }

    /**
     * Relasi ke jejak audit aktivitas pengguna ini.
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Cek apakah user adalah Admin.
     */
    public function isAdmin(): bool
    {
        return $this->role?->name === 'admin';
    }

    /**
     * Cek apakah user adalah Anggota / Pegawai Toko (karyawan internal).
     * Mencakup nama legacy 'seller'/'penjual' agar data lama tetap aman.
     */
    public function isAnggota(): bool
    {
        return in_array($this->role?->name, ['anggota', 'seller', 'penjual'], true);
    }

    /**
     * Alias legacy untuk isAnggota() (nama role lama: penjual/seller).
     *
     * @deprecated Gunakan isAnggota().
     */
    public function isSeller(): bool
    {
        return $this->isAnggota();
    }

    /**
     * Cek apakah user adalah Kurir (Courier).
     */
    public function isCourier(): bool
    {
        return in_array($this->role?->name, ['courier', 'kurir'], true);
    }

    /**
     * Cek apakah user adalah Pelanggan (Customer).
     * Admin tidak otomatis dianggap sebagai pelanggan.
     */
    public function isCustomer(): bool
    {
        return in_array($this->role?->name, ['customer', 'pelanggan'], true);
    }
}
