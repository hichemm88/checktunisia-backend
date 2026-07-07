<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company legal identity — used on invoice PDFs (raison sociale, MF, RC,
 * adresse siège) and as the bank-transfer beneficiary. MF/RC/address are
 * left empty (editable from the admin Paiements page) rather than guessed;
 * only the company name is known with certainty at this point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->string('company_name', 150)->nullable()->after('id');
            $table->string('company_mf', 50)->nullable()->after('company_name');
            $table->string('company_rc', 50)->nullable()->after('company_mf');
            $table->text('company_address')->nullable()->after('company_rc');
        });

        DB::table('platform_settings')->update([
            'company_name'         => 'Kasbahost Sarl',
            'virement_beneficiary' => 'Kasbahost Sarl',
        ]);
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'company_mf', 'company_rc', 'company_address']);
        });
    }
};
