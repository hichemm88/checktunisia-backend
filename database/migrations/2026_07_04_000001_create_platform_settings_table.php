<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            // Flouci (online payment)
            $table->boolean('flouci_enabled')->default(false);
            $table->string('flouci_app_token', 255)->nullable();
            $table->string('flouci_app_secret', 255)->nullable();
            // Virement bancaire (bank transfer)
            $table->boolean('virement_enabled')->default(true);
            $table->string('virement_rib', 50)->nullable();
            $table->string('virement_iban', 34)->nullable();
            $table->string('virement_bank_name', 100)->nullable();
            $table->string('virement_beneficiary', 150)->nullable();
            $table->text('virement_details')->nullable(); // free-text additional info
            $table->timestamps();
        });

        // Seed one row so we can always do UPDATE instead of UPSERT
        DB::table('platform_settings')->insert([
            'flouci_enabled'      => false,
            'virement_enabled'    => true,
            'virement_bank_name'  => 'Banque de Tunisie',
            'virement_beneficiary'=> 'CHECKTUNISIA',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
