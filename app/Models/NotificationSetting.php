<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    protected $fillable = [
        'group',
        'key',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * Ambil status aktif sebuah pengaturan.
     *
     * Baris yang belum pernah disimpan dianggap aktif (default bawaan),
     * sehingga tidak perlu seeding dan fitur langsung berfungsi.
     */
    public static function isEnabled(string $group, string $key): bool
    {
        $setting = static::query()
            ->where('group', $group)
            ->where('key', $key)
            ->first();

        return $setting === null ? true : (bool) $setting->enabled;
    }

    /**
     * Simpan (atau perbarui) status aktif sebuah pengaturan.
     */
    public static function set(string $group, string $key, bool $enabled): void
    {
        static::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['enabled' => $enabled]
        );
    }
}
