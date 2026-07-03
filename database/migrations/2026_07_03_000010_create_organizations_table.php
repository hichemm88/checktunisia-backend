<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Société ou particulier
            $table->string('name', 255);                        // "Kasbahost", "Mohammed Ben Ali"
            $table->string('entity_type', 20)->default('company'); // company | individual
            $table->string('registration_number', 100)->nullable(); // RC (company) ou CIN (individual)
            $table->string('contact_email', 255);
            $table->string('contact_phone', 30)->nullable();
            $table->jsonb('address')->default('{}');            // {line1, city, governorate, postal_code}

            $table->string('status', 20)->default('pending');   // pending | active | suspended | closed
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('entity_type');
        });

        // Link organizations → hotels (properties belong to an org)
        Schema::table('hotels', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->after('id');
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->index('organization_id');
        });

        // Link organizations → users
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->after('id');
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->index('organization_id');
        });

        // Move subscriptions from hotel-level to org-level
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->after('hotel_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });
        Schema::dropIfExists('organizations');
    }
};
