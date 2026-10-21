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
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('courier_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('tracking_number')->unique()->index();
            $table->string('courier_name')->nullable();
            $table->string('status', 20)->default('waiting')->index(); // waiting, picked_up, shipping, delivered
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('proof_image')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
