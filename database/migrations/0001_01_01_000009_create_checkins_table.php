<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('date_of_birth');
            $table->char('sex', 1)->default('M'); // M | F | X
            $table->char('nationality_code', 3); // ISO 3166-1 alpha-3
            $table->char('country_of_birth', 3)->nullable();
            $table->string('place_of_birth', 150)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['nationality_code'], 'idx_guests_nationality');
            $table->index(['date_of_birth'], 'idx_guests_dob');
        });

        Schema::create('check_ins', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('hotel_id')->constrained('hotels');
            $table->foreignUuid('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->string('reference', 50)->unique(); // QYD-20250701-0042
            $table->string('booking_reference', 100)->nullable();
            $table->string('booking_source', 50)->nullable(); // direct | booking | airbnb | expedia | phone
            $table->date('check_in_date');
            $table->date('expected_check_out_date');
            $table->date('actual_check_out_date')->nullable();
            $table->string('status', 20)->default('draft'); // draft | active | completed | cancelled | no_show
            $table->smallInteger('adults_count')->default(1);
            $table->smallInteger('children_count')->default(0);
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['hotel_id'], 'idx_check_ins_hotel');
            $table->index(['hotel_id', 'check_in_date', 'expected_check_out_date'], 'idx_check_ins_dates');
            $table->index(['hotel_id', 'status'], 'idx_check_ins_status');
            $table->index(['room_id'], 'idx_check_ins_room');
        });

        Schema::create('check_in_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('check_in_id')->constrained('check_ins')->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained('guests');
            $table->boolean('is_primary')->default(false);
            $table->foreignUuid('added_by')->constrained('users');
            $table->timestamp('added_at')->useCurrent();

            $table->unique(['check_in_id', 'guest_id']);
            $table->index(['check_in_id'], 'idx_cig_check_in');
            $table->index(['guest_id'], 'idx_cig_guest');
        });

        Schema::create('travel_documents', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('guest_id')->constrained('guests')->cascadeOnDelete();
            $table->string('type', 50)->default('passport'); // passport | national_id | residence_permit | visa
            $table->string('document_number', 100);
            $table->char('issuing_country_code', 2);
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('mrz_line1', 50)->nullable();
            $table->string('mrz_line2', 50)->nullable();
            $table->string('mrz_line3', 50)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->unique(['type', 'document_number', 'issuing_country_code']);
            $table->index(['guest_id'], 'idx_travel_docs_guest');
            $table->index(['document_number'], 'idx_travel_docs_number');
            $table->index(['expiry_date'], 'idx_travel_docs_expiry');
        });

        Schema::create('document_scans', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('check_in_id')->constrained('check_ins')->cascadeOnDelete();
            $table->foreignUuid('travel_document_id')->nullable()->constrained('travel_documents')->nullOnDelete();
            $table->foreignUuid('guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->string('file_path', 500);
            $table->char('file_hash', 64);
            $table->integer('file_size_bytes')->nullable();
            $table->string('mime_type', 50)->default('image/jpeg');
            $table->string('ocr_status', 20)->default('pending'); // pending | processing | completed | failed | skipped
            $table->jsonb('ocr_raw_result')->nullable();
            $table->decimal('ocr_confidence', 5, 4)->nullable();
            $table->timestamp('ocr_processed_at')->nullable();
            $table->text('ocr_error')->nullable();
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestamps();

            $table->index(['check_in_id'], 'idx_doc_scans_check_in');
            $table->index(['ocr_status'], 'idx_doc_scans_ocr_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_scans');
        Schema::dropIfExists('travel_documents');
        Schema::dropIfExists('check_in_guests');
        Schema::dropIfExists('check_ins');
        Schema::dropIfExists('guests');
    }
};
