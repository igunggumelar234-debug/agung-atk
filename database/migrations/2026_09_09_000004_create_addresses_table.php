<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('recipient_name');
            $table->string('phone');
            $table->text('full_address');
            $table->string('district')->index(); // Kecamatan di lingkungan Kota/Kabupaten Kuningan
            $table->string('postal_code', 10)->nullable();
            $table->boolean('is_primary')->default(false)->index();
            $table->text('notes')->nullable(); // Patokan lokasi
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
