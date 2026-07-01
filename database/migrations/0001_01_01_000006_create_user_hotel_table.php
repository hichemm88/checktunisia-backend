<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_hotels', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();

            $table->unique(['user_id', 'hotel_id']);
            $table->index(['hotel_id'], 'idx_user_hotels_hotel');
            $table->index(['user_id'], 'idx_user_hotels_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_hotels');
    }
};
