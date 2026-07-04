<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per calendar day; locked with lockForUpdate() to atomically
        // hand out the next check-in reference number (QYD-YYYYMMDD-NNNN)
        // and avoid the race condition that let two requests read the same
        // COUNT() and generate a duplicate reference.
        Schema::create('check_in_sequences', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_in_sequences');
    }
};
