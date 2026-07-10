<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 255)->unique();
            $table->string('platform', 10)->nullable(); // ios | android | web
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id'], 'idx_device_tokens_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
