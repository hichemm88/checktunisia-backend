<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Résiliation self-service : le client arrête le renouvellement lui-même.
 *
 * La résiliation n'est PAS une suppression et ne coupe rien immédiatement.
 * Elle se traduit par `auto_renew = false` (la colonne existe déjà et est
 * lue par BillingService::generateDueRenewalInvoices) plus l'horodatage de
 * la demande, qui sert à trois choses : afficher au client la date exacte
 * de fin de service, distinguer un compte résilié d'un compte simplement
 * créé sans auto-renouvellement, et permettre la réactivation.
 *
 * `cancellation_reason` est facultatif — un motif est précieux
 * commercialement, jamais bloquant pour partir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('cancellation_requested_at')->nullable()->after('cancelled_at');
            $table->string('cancellation_reason', 500)->nullable()->after('cancellation_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['cancellation_requested_at', 'cancellation_reason']);
        });
    }
};
