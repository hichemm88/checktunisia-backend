<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->string('number', 20);
            $table->smallInteger('floor')->nullable();
            $table->string('type', 50)->default('standard'); // standard | suite | apartment | dormitory | villa
            $table->smallInteger('capacity')->default(2);
            $table->string('status', 20)->default('available'); // available | occupied | maintenance | inactive
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hotel_id', 'number']);
            $table->index(['hotel_id'], 'idx_rooms_hotel');
            $table->index(['hotel_id', 'status'], 'idx_rooms_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
