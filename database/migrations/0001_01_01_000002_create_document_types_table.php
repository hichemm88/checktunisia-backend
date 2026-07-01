<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name_en', 100);
            $table->string('name_fr', 100);
            $table->string('name_ar', 100)->nullable();
            $table->string('mrz_format', 20)->nullable(); // TD1 | TD2 | TD3
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
