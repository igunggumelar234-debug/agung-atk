<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index gabungan ADITIF (tanpa mengubah/menghapus index lama) untuk dua
 * jalur query panas:
 *
 * 1. notification_logs — anti-duplikasi notifikasi (NotificationManager)
 *    menyaring event_key + channel + recipient + reference + status +
 *    rentang created_at; dengan index tunggal terpisah, MySQL harus
 *    memotong lewat index tunggal dulu lalu menyaring sisanya.
 * 2. orders — daftar/laporan pesanan menyaring status lalu mengurutkan
 *    (atau membatasi) created_at; pasangan (status, created_at) menutup
 *    keduanya dalam satu index.
 */
return new class extends Migration
{
    /**
     * Jalankan migrasi.
     */
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->index(
                ['event_key', 'channel', 'recipient', 'reference', 'status', 'created_at'],
                'notification_logs_dedup_index'
            );
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'orders_status_created_at_index');
        });
    }

    /**
     * Batalkan migrasi (hanya menurunkan index buatan migrasi ini).
     */
    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropIndex('notification_logs_dedup_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_created_at_index');
        });
    }
};
