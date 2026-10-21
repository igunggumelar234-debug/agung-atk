<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas pegawai (Anggota Toko & Kurir) yang dibuat Admin:
 * nomor pegawai unik, jabatan, status kerja, dan tanggal mulai bekerja.
 * Kolom nullable karena hanya berlaku untuk akun karyawan internal.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_code', 30)->nullable()->unique()->after('role_id');
            $table->string('position', 100)->nullable()->after('employee_code');
            $table->string('employment_status', 30)->nullable()->after('position');
            $table->date('hired_at')->nullable()->after('employment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_code']);
            $table->dropColumn(['employee_code', 'position', 'employment_status', 'hired_at']);
        });
    }
};
