<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat pengiriman notifikasi (audit trail).
     *
     * Tidak pernah menyimpan token API atau kata sandi — hanya jenis kejadian,
     * saluran, penerima, isi pesan yang dikirim, status, dan pesan kesalahan.
     */
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 40)->index();       // 'order.new', 'test', ...
            $table->string('channel', 20)->index();         // 'telegram' | 'whatsapp' | 'email'
            $table->string('recipient', 190);               // email / nomor / chat id tujuan
            $table->string('subject', 150)->nullable();     // judul (dipakai untuk email)
            $table->text('body')->nullable();               // isi pesan yang dikirim (dipotong)
            $table->string('status', 10)->default('pending')->index(); // pending | sent | failed
            $table->text('error')->nullable();              // pesan kesalahan (tanpa kredensial)
            $table->string('reference', 100)->nullable()->index(); // 'order:5', 'product:3', 'daily:2026-10-16'
            $table->foreignId('order_id')->nullable()
                ->constrained('orders')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
