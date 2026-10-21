<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pengaturan notifikasi: per saluran (channel) dan per kejadian (event).
     *
     * Satu baris per kombinasi (group, key) dengan saklar enabled.
     * Baris yang belum pernah diubah dianggap aktif (default bawaan aplikasi).
     */
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 10);   // 'channel' | 'event'
            $table->string('key', 60);     // 'telegram' | 'order.new' | ...
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['group', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
