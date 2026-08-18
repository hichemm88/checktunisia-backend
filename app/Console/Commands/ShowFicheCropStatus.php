<?php

namespace App\Console\Commands;

use App\Models\DocumentScan;
use Illuminate\Console\Command;

/**
 * Dit pourquoi une pièce d'identité est cadrée comme elle l'est.
 *
 * Sans cette commande, « le cadrage est mauvais » n'est pas diagnosticable : le
 * détourage par modèle de vision est silencieux par conception — toute panne
 * retombe sur le cadrage géométrique sans rien interrompre. On ne peut donc pas
 * distinguer, en regardant le PDF, une détection désactivée d'une détection
 * qui a échoué, ou d'une détection qui a bien eu lieu mais dont le résultat
 * déplaît.
 *
 * Aucun tiret dans l'invocation : la console web de Railway les avale.
 */
class ShowFicheCropStatus extends Command
{
    protected $signature = 'fiche:crop-status {limite=10 : Nombre de pièces récentes à examiner}';

    protected $description = 'État du détourage des pièces d\'identité (configuration + dernières pièces)';

    public function handle(): int
    {
        $enabled = (bool) config('fiche.ai_crop.enabled');
        $hasKey = filled(config('fiche.ai_crop.api_key'));

        $this->line('Détourage IA   : '.($enabled ? 'activé' : 'DÉSACTIVÉ (FICHE_AI_CROP)'));
        $this->line('Clé Anthropic  : '.($hasKey ? 'présente' : 'ABSENTE (ANTHROPIC_API_KEY)'));
        $this->line('Modèle         : '.config('fiche.ai_crop.model'));
        $this->line('Marge          : '.(100 * (float) config('fiche.ai_crop.margin')).' %');
        $this->line('Cadre de sortie: '.config('fiche.photo_width').'x'.config('fiche.photo_height')
            .' ('.config('fiche.photo_fit').')');
        $this->newLine();

        if (!$enabled || !$hasKey) {
            // La cause la plus probable d'un rendu décevant, et la moins
            // visible : le détourage n'a simplement jamais tourné.
            $this->warn('Le détourage ne tourne pas : les pièces sont seulement mises au format.');
            $this->line('Une photo prise en portrait donne alors un document étroit entouré de blanc.');
            $this->newLine();
        }

        $scans = DocumentScan::query()->latest('created_at')
            ->take(max(1, (int) $this->argument('limite')))
            ->get(['id', 'created_at', 'crop_box', 'crop_detected_at']);

        if ($scans->isEmpty()) {
            $this->warn('Aucune pièce en base.');

            return self::SUCCESS;
        }

        $this->table(
            ['Pièce', 'Déposée', 'Analysée', 'Cadre retenu', 'Part de l\'image'],
            $scans->map(function (DocumentScan $s) {
                $box = $s->crop_box;

                return [
                    substr($s->id, 0, 8),
                    $s->created_at?->format('d/m H:i'),
                    $s->crop_detected_at ? $s->crop_detected_at->format('d/m H:i') : 'jamais',
                    $box ? sprintf('x%.2f y%.2f l%.2f h%.2f', $box['x'], $box['y'], $box['width'], $box['height']) : '—',
                    // La part de l'image retenue dit tout : proche de 100 %, le
                    // modèle n'a rien détouré ; très basse, il s'est peut-être
                    // trompé de sujet.
                    $box ? round(100 * $box['width'] * $box['height']).' %' : '—',
                ];
            })->all(),
        );

        $analysed = $scans->whereNotNull('crop_detected_at')->count();
        $found = $scans->filter(fn ($s) => $s->crop_box !== null)->count();

        $this->newLine();
        $this->line("Analysées : {$analysed}/{$scans->count()} — document situé : {$found}");

        if ($analysed > 0 && $found === 0) {
            $this->warn('Analysées mais aucun cadre retenu : réponses jugées invraisemblables, ou API en échec.');
            $this->line('Voir les journaux : « détection du cadrage indisponible ».');
        }

        return self::SUCCESS;
    }
}
