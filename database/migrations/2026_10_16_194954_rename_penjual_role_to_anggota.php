<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Migrasi data aman: penamaan role diseragamkan menjadi
 * admin, anggota, kurir, pelanggan.
 *
 * - 'penjual' (dan legacy 'seller') dipindahkan ke 'anggota'
 *   (Anggota / Pegawai Toko — karyawan internal, bukan pelanggan).
 * - Seluruh pengguna dipindahkan ke role kanonik SEBELUM role lama
 *   dihapus agar tidak ada data yatim (FK users.role_id restrictOnDelete).
 * - Tidak ada akun atau riwayat pesanan yang dihapus.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $anggotaLabel = 'Anggota / Pegawai Toko';
        $anggotaDescription = 'Karyawan internal toko: memproses pesanan, menyiapkan barang, dan memantau stok.';

        // 1. Jika role 'penjual' ada, jadikan role kanonik 'anggota'.
        $penjual = Role::where('name', 'penjual')->first();
        $anggota = Role::where('name', 'anggota')->first();

        if ($penjual && ! $anggota) {
            $penjual->update([
                'name' => 'anggota',
                'label' => $anggotaLabel,
                'description' => $anggotaDescription,
            ]);
            $anggota = $penjual;
        }

        // 2. Jika keduanya ada (data setengah bermigrasi), pindahkan pengguna
        //    dari 'penjual' ke 'anggota' lalu hapus duplikatnya.
        if ($penjual && $anggota && $penjual->id !== $anggota->id) {
            User::where('role_id', $penjual->id)->update(['role_id' => $anggota->id]);
            $penjual->delete();
            $anggota->refresh();
        }

        // 3. Legacy 'seller' -> 'anggota' (penggabungan seperti RoleSeeder).
        $seller = Role::where('name', 'seller')->first();
        if ($seller && $anggota) {
            User::where('role_id', $seller->id)->update(['role_id' => $anggota->id]);
            $seller->delete();
            $anggota->refresh();
        } elseif ($seller && ! $anggota) {
            $seller->update([
                'name' => 'anggota',
                'label' => $anggotaLabel,
                'description' => $anggotaDescription,
            ]);
            $anggota = $seller;
        }

        // 4. Perbarui label & deskripsi role anggota bila sudah tersedia.
        if ($anggota) {
            $anggota->update([
                'label' => $anggotaLabel,
                'description' => $anggotaDescription,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $anggota = Role::where('name', 'anggota')->first();

        if ($anggota && ! Role::where('name', 'penjual')->exists()) {
            $anggota->update([
                'name' => 'penjual',
                'label' => 'Penjual',
                'description' => 'Akses operasional toko, memproses pesanan, menyiapkan barang pesanan, dan memantau stok.',
            ]);
        }
    }
};
