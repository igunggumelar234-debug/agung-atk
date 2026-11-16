<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     *
     * Tautan akun Agung ATK dengan identitas kanal (WhatsApp/Telegram)
     * melalui verifikasi kode — bukan sekadar username/nomor mentah.
     * identifier diisi saat verifikasi berhasil sehingga baris "pending"
     * boleh memiliki NULL (beberapa permintaan kode dapat hidup bersamaan).
     */
    public function up(): void
    {
        Schema::create('channel_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20); // whatsapp | telegram
            $table->string('identifier', 50)->nullable(); // E.164 / chat id (diisi saat verifikasi)
            $table->string('status', 20)->default('pending'); // pending | verified
            $table->string('verification_code', 10)->nullable();
            $table->timestamp('code_expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'identifier']);
            $table->index(['channel', 'status']);
            $table->index(['user_id', 'channel']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_identities');
    }
};
