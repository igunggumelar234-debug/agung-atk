<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Menambahkan field `label` dan `city` sesuai spesifikasi tabel addresses.
     * Kolom bersifat nullable agar data alamat yang sudah ada tetap valid.
     */
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('label', 100)->nullable()->after('user_id'); // Label alamat: Rumah, Kantor, Sekolah
            $table->string('city', 100)->nullable()->after('full_address'); // Kota/Kabupaten tujuan
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn(['label', 'city']);
        });
    }
};
