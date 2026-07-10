<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-recipient notification feed for the mobile notification centre (§5.8 / §6).
     * One row per (recipient manager, event) so read/unread state is per user. Named
     * `app_notifications` to avoid any clash with Laravel's built-in `notifications`
     * (database channel) table.
     */
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();       // recipient
            $table->foreignUuid('hotel_id')->constrained('hotels')->cascadeOnDelete();      // property
            $table->foreignUuid('check_in_id')->nullable()->constrained('check_ins')->nullOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);   // check_in | check_out | fiche_updated | fiche_cancelled | fiche_pending
            $table->string('title', 200);
            $table->text('body');
            $table->jsonb('data')->default('{}');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at'], 'idx_app_notifications_user_read');
            $table->index(['hotel_id'],           'idx_app_notifications_hotel');
            $table->index(['created_at'],          'idx_app_notifications_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
