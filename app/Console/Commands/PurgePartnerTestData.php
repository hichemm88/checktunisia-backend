<?php

namespace App\Console\Commands;

use App\Models\ApiPartner;
use App\Services\PartnerApi\PartnerTestDataPurgeService;
use App\Services\PartnerApi\PurgeConfirmationMismatch;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Purge des données créées avec les clés de TEST d'un partenaire API. Même
 * service que l'action admin (Admin → Partenaires API → détail) : voir
 * PartnerTestDataPurgeService pour ce qui est supprimé et ce qui ne l'est
 * jamais.
 *
 * Toujours un aperçu d'abord. Exécution : confirmation interactive, ou
 * --force en non interactif. Le jeton de l'aperçu est revérifié sous verrou :
 * si les données changent entre l'aperçu et l'exécution, rien n'est supprimé.
 */
class PurgePartnerTestData extends Command
{
    protected $signature = 'partner:purge-test-data
        {partner : Identifiant (UUID) ou slug du partenaire}
        {--dry-run : Affiche uniquement les comptes, ne supprime rien}
        {--force : Exécute sans demander de confirmation}';

    protected $description = "Supprime les sessions, brouillons, fiches et séjours créés avec les clés de TEST d'un partenaire API";

    public function handle(PartnerTestDataPurgeService $purge): int
    {
        $ref = (string) $this->argument('partner');
        $partner = Str::isUuid($ref)
            ? ApiPartner::find($ref)
            : ApiPartner::where('slug', $ref)->first();

        if (! $partner) {
            $this->error('Partenaire introuvable.');

            return self::FAILURE;
        }

        $counts = $purge->preview($partner);
        $total = PartnerTestDataPurgeService::deletableTotal($counts);

        $this->info("Partenaire : {$partner->name} ({$partner->id})");
        $this->table(['Type', 'Nombre'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->line("Éléments à supprimer : {$total}");

        if ($this->option('dry-run')) {
            $this->comment('Aperçu uniquement (--dry-run) : rien n\'a été supprimé.');

            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->info('Aucune donnée de test à supprimer.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Confirmation requise : relancez avec --force en mode non interactif.');

                return self::FAILURE;
            }
            if (! $this->confirm("Supprimer définitivement ces {$total} élément(s) de test ?", false)) {
                $this->comment('Annulé.');

                return self::FAILURE;
            }
        }

        try {
            $done = $purge->purge($partner, null, 'cli', $purge->confirmationToken($partner, $counts));
        } catch (PurgeConfirmationMismatch) {
            $this->error('Les données de test ont changé depuis l\'aperçu : rien n\'a été supprimé. Relancez la commande.');

            return self::FAILURE;
        }

        $this->info('Purge effectuée : '.PartnerTestDataPurgeService::deletableTotal($done).' élément(s) supprimé(s), '
            .$done['rooms_released'].' chambre(s) libérée(s), '.$done['kept_check_ins'].' check-in(s) conservé(s).');

        return self::SUCCESS;
    }
}
