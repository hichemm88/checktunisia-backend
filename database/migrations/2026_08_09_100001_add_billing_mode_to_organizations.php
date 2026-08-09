<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue les comptes CLIENTS des comptes qui nous appartiennent.
 *
 * Jusqu'ici le système confondait « abonnement actif » et « client payant ».
 * Or nous exploitons nous-mêmes Qayed sur un compte à nous : il consomme le
 * produit sans rien acheter. Le compter dans le chiffre d'affaires fausse
 * toutes les métriques commerciales, et lui envoyer des factures n'a aucun
 * sens.
 *
 * `billing_mode` porte cette distinction sur l'ORGANISATION, pas sur
 * l'abonnement : c'est l'entité qui est nôtre ou cliente. Un abonnement créé
 * plus tard pour la même organisation hérite donc automatiquement du statut —
 * c'est ce qui empêche une future fonctionnalité de facturation de
 * réintroduire un compte interne dans le revenu par inadvertance.
 *
 *  - `commercial` (défaut) : client, facturé, compté dans les métriques ;
 *  - `internal`            : compte à nous, jamais facturé, jamais compté.
 *
 * `internal` n'est NI un rôle NI un privilège : les permissions, l'isolation
 * tenant et l'accès au produit sont strictement inchangés. C'est une exemption
 * commerciale, rien d'autre.
 *
 * Migration NON destructive : aucune facture, aucun paiement, aucun
 * dépassement historique n'est touché. L'historique commercial d'un compte
 * passé en interne reste intact et consultable ; seules les métriques
 * courantes et futures l'excluent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('billing_mode', 20)->default('commercial')->after('status');
            $table->text('billing_mode_note')->nullable()->after('billing_mode');
            $table->index('billing_mode', 'idx_organizations_billing_mode');
        });

        // KASBAHOST est notre propre société : elle exploite Qayed comme
        // compte interne. Marquage par nom parce qu'il n'existe pas d'autre
        // identifiant stable — c'est une migration de DONNÉES ponctuelle, et
        // surtout PAS une règle de code (aucun test métier ne connaît ce nom).
        // Idempotente et réexécutable : ne touche que la ligne concernée, et
        // seulement si elle est encore en mode commercial.
        DB::table('organizations')
            ->whereRaw('UPPER(name) = ?', ['KASBAHOST'])
            ->where('billing_mode', 'commercial')
            ->update([
                'billing_mode'      => 'internal',
                'billing_mode_note' => 'Société propriétaire de Qayed — compte interne d\'exploitation, hors périmètre commercial.',
            ]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex('idx_organizations_billing_mode');
            $table->dropColumn(['billing_mode', 'billing_mode_note']);
        });
    }
};
