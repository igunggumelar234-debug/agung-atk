<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Buat akun development untuk setiap role (Admin, Penjual, Kurir, Pelanggan).
     * Password diambil dari environment SEED_PASSWORD melalui config/app.php,
     * sehingga tidak ada password rahasia yang ditulis ke dalam repository.
     * Jika SEED_PASSWORD kosong, pembuatan akun development dilewati.
     */
    public function run(): void
    {
        $password = config('app.seed_password');

        if (empty($password)) {
            $this->command?->warn(
                'SEED_PASSWORD belum diisi di file .env — pembuatan akun development dilewati.'
            );

            return;
        }

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $anggotaRole = Role::where('name', 'anggota')->firstOrFail();
        $courierRole = Role::where('name', 'kurir')->firstOrFail();
        $customerRole = Role::where('name', 'pelanggan')->firstOrFail();

        // 1. Akun Admin Development
        User::updateOrCreate(
            ['email' => 'admin@agungatk.com'],
            [
                'role_id' => $adminRole->id,
                'name' => 'Admin Agung ATK',
                'phone' => '081234567890',
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 2. Akun Anggota / Pegawai Toko (karyawan internal)
        User::updateOrCreate(
            ['email' => 'penjual@agungatk.com'],
            [
                'role_id' => $anggotaRole->id,
                'name' => 'Staf Toko Agung ATK',
                'phone' => '081234567891',
                'employee_code' => 'AGT-0001',
                'position' => 'Staf Operasional Toko',
                'employment_status' => 'permanent',
                'hired_at' => '2024-01-02',
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 3. Akun Kurir
        User::updateOrCreate(
            ['email' => 'kurir@agungatk.com'],
            [
                'role_id' => $courierRole->id,
                'name' => 'Kurir Cepat Kuningan',
                'phone' => '081234567892',
                'employee_code' => 'KUR-0001',
                'position' => 'Kurir Pengantaran',
                'employment_status' => 'contract',
                'hired_at' => '2024-03-01',
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // 4. Akun Pelanggan
        User::updateOrCreate(
            ['email' => 'pelanggan@agungatk.com'],
            [
                'role_id' => $customerRole->id,
                'name' => 'Budi Santoso (Kuningan)',
                'phone' => '081234567893',
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
