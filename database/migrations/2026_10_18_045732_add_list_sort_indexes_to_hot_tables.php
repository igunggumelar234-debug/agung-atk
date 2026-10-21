<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks untuk kolom pengurutan daftar yang TANPA indeks.
 *
 * - orders.created_at        : semua daftar pesanan memakai ->latest() (pelanggan,
 *                              admin, penjual, dashboard, laporan) + filter rentang tanggal.
 * - activity_logs.created_at : ->latest()->paginate() tiap halaman audit.
 * - notification_logs.created_at : filter rentang tanggal riwayat + jendela anti-dedupe.
 *
 * Murni tambahan (tidak mengubah tipe/kolom/data) — aman dijalankan tanpa downtime.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('created_at', 'orders_created_at_index');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index('created_at', 'activity_logs_created_at_index');
        });

        Schema::table('notification_logs', function (Blueprint $table) {
            $table->index('created_at', 'notification_logs_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_created_at_index');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_created_at_index');
        });

        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropIndex('notification_logs_created_at_index');
        });
    }
};
