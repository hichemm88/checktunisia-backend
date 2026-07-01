<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authority_organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('type', 50); // police | immigration | customs | judiciary | tax | other
            $table->string('code', 50)->nullable()->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('authority_user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('authority_organizations');
            $table->string('badge_number', 50)->nullable();
            $table->string('rank', 100)->nullable();
            $table->foreignUuid('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->index(['organization_id'], 'idx_authority_profiles_org');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authority_user_profiles');
        Schema::dropIfExists('authority_organizations');
    }
};
