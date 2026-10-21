<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Nama role resmi sesuai spesifikasi project.
     */
    private const CANONICAL_ROLES = [
        [
            'name' => 'admin',
            'label' => 'Administrator',
            'description' => 'Akses penuh pengelolaan toko, pengguna, produk, stok, laporan, dan konfigurasi AI.',
        ],
        [
            'name' => 'anggota',
            'label' => 'Anggota / Pegawai Toko',
            'description' => 'Karyawan internal toko: memproses pesanan, menyiapkan barang, dan memantau stok.',
        ],
        [
            'name' => 'kurir',
            'label' => 'Kurir',
            'description' => 'Pengantaran pesanan wilayah Kota/Kabupaten Kuningan dan konfirmasi serah terima.',
        ],
        [
            'name' => 'pelanggan',
            'label' => 'Pelanggan',
            'description' => 'Akses katalog ATK, keranjang belanja, checkout, tracking pesanan, ulasan, dan AI Assistant.',
        ],
    ];

    /**
     * Role lama (duplikat) yang dipindahkan ke nama resminya.
     * 'penjual'/'seller' menjadi 'anggota' (Anggota / Pegawai Toko).
     */
    private const LEGACY_ALIASES = [
        'seller' => 'anggota',
        'penjual' => 'anggota',
        'courier' => 'kurir',
        'customer' => 'pelanggan',
    ];

    /**
     * Run the database seeds.
     *
     * Membuat tepat 4 role sesuai spesifikasi: admin, anggota, kurir, pelanggan,
     * lalu memindahkan pengguna dari role duplikat lama (seller/penjual/
     * courier/customer) ke nama resminya sebelum role duplikat dihapus.
     */
    public function run(): void
    {
        foreach (self::CANONICAL_ROLES as $role) {
            Role::firstOrCreate(['name' => $role['name']], $role);
        }

        $this->mergeLegacyRoles();
    }

    /**
     * Pindahkan pengguna dari role duplikat lama ke role resmi, lalu hapus duplikatnya.
     * Dilakukan agar data lama tidak menjadi yatim (orphan) dan label role konsisten.
     */
    private function mergeLegacyRoles(): void
    {
        foreach (self::LEGACY_ALIASES as $legacyName => $canonicalName) {
            $legacyRole = Role::where('name', $legacyName)->first();
            $canonicalRole = Role::where('name', $canonicalName)->first();

            if (! $legacyRole || ! $canonicalRole) {
                continue;
            }

            // Pindahkan seluruh pengguna ke role resmi terlebih dahulu
            // (users.role_id memakai FK restrictOnDelete, jadi tidak boleh ada pemakai saat dihapus).
            User::where('role_id', $legacyRole->id)->update(['role_id' => $canonicalRole->id]);

            $legacyRole->delete();
        }
    }
}
