<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authority_organizations', function (Blueprint $table) {
            // Governorate scope for police stations (null = national scope for ministry)
            $table->string('governorate', 100)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('authority_organizations', function (Blueprint $table) {
            $table->dropColumn('governorate');
        });
    }
};
