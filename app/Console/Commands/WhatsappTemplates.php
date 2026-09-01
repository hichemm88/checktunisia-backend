<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\WhatsappCloudApi;
use App\Services\Whatsapp\WhatsappCloudConfig;
use Illuminate\Console\Command;

/**
 * Crée et surveille les modèles de message WhatsApp (Cloud API).
 *
 * Le modèle est la pièce qui ne dépend PAS de nous : Meta l'examine, et
 * l'approbation prend de quelques minutes à quelques jours. C'est donc le
 * chemin critique de la mise en service — d'où une commande qui sait à la
 * fois créer et interroger, plutôt qu'un passage par l'interface Meta dont il
 * ne resterait aucune trace dans le dépôt.
 *
 * Idempotente : un modèle déjà déclaré n'est pas recréé (Meta refuserait le
 * doublon), son statut est affiché.
 *
 *   php artisan whatsapp:templates            # état des modèles
 *   php artisan whatsapp:templates --create   # crée ce qui manque
 */
class WhatsappTemplates extends Command
{
    protected $signature = 'whatsapp:templates {--create : Crée les modèles manquants au lieu de seulement les lister}';

    protected $description = 'Crée / vérifie les modèles de message WhatsApp Cloud API (fiche de police).';

    public function handle(WhatsappCloudApi $api): int
    {
        if ($missing = WhatsappCloudConfig::missingForAdmin()) {
            $this->error(WhatsappCloudConfig::explain($missing));

            return self::FAILURE;
        }

        if (! $api->canManageTemplates()) {
            $this->error('Gestion des modèles indisponible : jeton ou identifiant de compte (WABA) refusé.');

            return self::FAILURE;
        }

        $definitions = $this->definitions();

        try {
            $existing = $api->listTemplates();
        } catch (\Throwable $e) {
            $this->error('Lecture des modèles impossible : '.$e->getMessage());

            return self::FAILURE;
        }

        $missing = 0;

        foreach ($definitions as $definition) {
            $key = $definition['name'].':'.$definition['language'];
            $found = $existing[$key] ?? null;

            if ($found !== null) {
                $this->renderStatus($key, (string) ($found['status'] ?? 'INCONNU'), $found);

                continue;
            }

            $missing++;

            if (! $this->option('create')) {
                $this->warn("{$key} : ABSENT — relancer avec --create pour le soumettre à Meta.");

                continue;
            }

            try {
                $created = $api->createTemplate($definition);
                $this->renderStatus($key, (string) ($created['status'] ?? 'PENDING'), $created);
                $this->line('  Soumis à Meta. L\'approbation est asynchrone : relancer cette commande pour suivre.');
            } catch (\Throwable $e) {
                $this->error("{$key} : création refusée — ".$e->getMessage());

                return self::FAILURE;
            }
        }

        if ($missing === 0) {
            $this->info('Tous les modèles sont déclarés.');
        }

        $this->newLine();
        $this->line('Rappel : tant qu\'un modèle n\'est pas APPROVED, aucune fiche ne peut partir hors fenêtre de 24 h.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $template
     */
    private function renderStatus(string $key, string $status, array $template): void
    {
        $line = "{$key} : {$status}";

        if ($status === 'REJECTED' && filled($template['rejected_reason'] ?? null)) {
            $line .= ' ('.$template['rejected_reason'].')';
        }

        match ($status) {
            'APPROVED' => $this->info($line),
            'REJECTED', 'DISABLED', 'PAUSED' => $this->error($line),
            default => $this->warn($line),
        };
    }

    /**
     * Définition du modèle principal.
     *
     * Les `example` ne sont pas décoratifs : Meta refuse un modèle sans
     * exemples, et les examine pour juger la conformité. Ils sont donc
     * FICTIFS — aucune donnée de voyageur réel ne part chez Meta pour une
     * demande d'approbation.
     *
     * L'URL du bouton est figée à l'approbation : la changer impose de
     * soumettre un nouveau modèle. C'est pourquoi seule la fin de l'URL est
     * variable.
     *
     * @return array<int,array<string,mixed>>
     */
    private function definitions(): array
    {
        $base = (string) config('whatsapp.cloud.template.fiche_url_base');

        return [[
            'name' => (string) config('whatsapp.cloud.template.name'),
            'language' => (string) config('whatsapp.cloud.template.language'),
            // UTILITY et non MARKETING : c'est une transmission administrative
            // consécutive à un événement, pas une sollicitation. Le mauvais
            // classement vaut un rejet, et fait payer le message plus cher.
            'category' => 'UTILITY',
            'components' => [
                [
                    'type' => 'HEADER',
                    'format' => 'TEXT',
                    'text' => 'Nouvelle fiche de police — {{1}}',
                    'example' => ['header_text' => ['RESIDENCE EXEMPLE']],
                ],
                [
                    'type' => 'BODY',
                    'text' => "Adresse : {{1}}\n"
                        ."Voyageur : {{2}} — {{3}}\n"
                        ."Document : {{4}}\n"
                        ."Séjour : du {{5}} au {{6}} — Chambre : {{7}}\n"
                        ."Accompagnants : {{8}}\n\n"
                        .'Les documents d\'identité sont consultables via le bouton ci-dessous.',
                    'example' => ['body_text' => [[
                        '12 rue de l\'Exemple - Tunis 1000',
                        'EXEMPLE Voyageur',
                        'Tunisie',
                        'Passeport n° X0000000',
                        '01/01/2026',
                        '03/01/2026',
                        '000',
                        'Aucun',
                    ]]],
                ],
                [
                    'type' => 'FOOTER',
                    'text' => 'Envoyé via Qayed',
                ],
                [
                    'type' => 'BUTTONS',
                    'buttons' => [[
                        'type' => 'URL',
                        'text' => 'Consulter la fiche',
                        'url' => $base.'{{1}}',
                        'example' => [$base.'00000000-0000-0000-0000-000000000000'],
                    ]],
                ],
            ],
        ]];
    }
}
