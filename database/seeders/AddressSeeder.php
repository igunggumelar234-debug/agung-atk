<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\User;
use Illuminate\Database\Seeder;

class AddressSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $customer = User::where('email', 'pelanggan@agungatk.com')->first();

        if ($customer) {
            // Alamat Utama (Kecamatan Kuningan)
            Address::firstOrCreate(
                [
                    'user_id' => $customer->id,
                    'district' => 'Kuningan',
                    'is_primary' => true,
                ],
                [
                    'recipient_name' => 'Budi Santoso',
                    'phone' => '081234567893',
                    'full_address' => 'Jl. Siliwangi No. 45, RT 02 / RW 04, Kelurahan Purwawinangun, Kecamatan Kuningan',
                    'postal_code' => '45511',
                    'notes' => 'Dekat Bundaran Cijoho, pagar hitam depan kantor pos.',
                ]
            );

            // Alamat Tambahan (Kecamatan Cigugur)
            Address::firstOrCreate(
                [
                    'user_id' => $customer->id,
                    'district' => 'Cigugur',
                    'is_primary' => false,
                ],
                [
                    'recipient_name' => 'Budi Santoso (Kantor Cabang Cigugur)',
                    'phone' => '081234567893',
                    'full_address' => 'Jl. Raya Cigugur No. 88, RT 01 / RW 02, Kelurahan Cigugur, Kecamatan Cigugur',
                    'postal_code' => '45552',
                    'notes' => 'Ruko lantai 2 samping Masjid Cigugur.',
                ]
            );
        }
    }
}
