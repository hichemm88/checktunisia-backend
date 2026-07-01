<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotels', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->string('type', 50)->default('hotel'); // hotel | guesthouse | rental | hostel | resort
            $table->string('registration_number', 100)->nullable()->unique();
            $table->smallInteger('stars')->nullable();
            $table->smallInteger('room_count')->default(1);
            $table->string('status', 20)->default('pending'); // pending | active | suspended | closed
            $table->jsonb('metadata')->default('{}');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status'], 'idx_hotels_status');
            $table->index(['slug'], 'idx_hotels_slug');
        });

        Schema::create('hotel_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->string('line1', 255);
            $table->string('line2', 255)->nullable();
            $table->string('city', 100);
            $table->string('governorate', 100);
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2)->default('TN');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->index(['hotel_id'], 'idx_hotel_addresses_hotel');
        });

        Schema::create('hotel_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'key']);
            $table->index(['hotel_id'], 'idx_hotel_settings_hotel');
        });

        Schema::create('hotel_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->string('type', 30); // email | phone | fax | website | whatsapp
            $table->string('value', 255);
            $table->string('label', 100)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['hotel_id'], 'idx_hotel_contacts_hotel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_contacts');
        Schema::dropIfExists('hotel_settings');
        Schema::dropIfExists('hotel_addresses');
        Schema::dropIfExists('hotels');
    }
};
