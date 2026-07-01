<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id(); // BIGSERIAL for high volume
            $table->uuid('request_id')->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 50)->nullable();
            $table->foreignUuid('hotel_id')->nullable()->constrained('hotels')->nullOnDelete();
            $table->string('action', 100); // check_in.created | guest.added | authority.search | etc.
            $table->string('subject_type', 100)->nullable(); // App\Models\CheckIn
            $table->string('subject_id', 36)->nullable();    // UUID or int
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // No updated_at — immutable
            $table->index(['actor_id'], 'idx_audit_actor');
            $table->index(['hotel_id'], 'idx_audit_hotel');
            $table->index(['action'], 'idx_audit_action');
            $table->index(['subject_type', 'subject_id'], 'idx_audit_subject');
            $table->index(['created_at'], 'idx_audit_date');
            $table->index(['request_id'], 'idx_audit_request');
        });

        Schema::create('authority_search_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_log_id')->constrained('audit_logs')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('authority_organizations')->nullOnDelete();
            $table->jsonb('search_params');
            $table->integer('result_count')->default(0);
            $table->integer('execution_time_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id'], 'idx_auth_search_user');
            $table->index(['created_at'], 'idx_auth_search_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authority_search_logs');
        Schema::dropIfExists('audit_logs');
    }
};
