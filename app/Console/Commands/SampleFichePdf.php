<?php

namespace App\Console\Commands;

use App\Models\WhatsappSendLog;
use App\Services\Delivery\FichePdf;
use Illuminate\Console\Command;

/**
 * Écrit sur disque le PDF d'une fiche, tel qu'il partirait en pièce jointe.
 *
 * Sert à contrôler de visu ce que reçoit un destinataire — présence de la
 * pièce d'identité, lisibilité du numéro, accompagnants, poids du fichier —
 * sans envoyer quoi que ce soit et sans attendre qu'une fiche parte.
 *
 * ── STRICTEMENT EN LECTURE ───────────────────────────────────────────────────
 *
 * Aucune écriture en base, sur aucune table. La ligne d'outbox n'est ni
 * réclamée, ni datée, ni marquée : son statut, ses tentatives et son jeton
 * public sont exactement les mêmes après qu'avant.
 *
 * Ce n'est pas une précaution de principe. `whatsapp_send_log` EST le registre
 * de transmission des fiches de police : un outil de diagnostic qui y écrit
 * fabrique de la preuve. Et `FichePdf::forJob()` lit `checkIn`, `guest` et le
 * scan associé — de quoi produire le document sans rien toucher.
 *
 * Le PDF, lui, contient des données personnelles réelles : il est déposé dans
 * storage/app/whatsapp/samples/, hors du dépôt, et n'a pas à en sortir.
 */
class SampleFichePdf extends Command
{
    protected $signature = 'whatsapp:sample-pdf {send_log_id : Identifiant de la ligne whatsapp_send_log}';

    protected $description = 'Écrit le PDF d\'une fiche tel qu\'il partirait par WhatsApp (lecture seule).';

    public function handle(): int
    {
        $job = WhatsappSendLog::with([
            'hotel',
            'guest',
            'checkIn.hotel.address',
            'checkIn.room',
            'checkIn.guests.documents',
        ])->find($this->argument('send_log_id'));

        if (! $job) {
            $this->error('Aucune ligne d\'outbox avec cet identifiant.');

            return self::FAILURE;
        }

        $pdf = FichePdf::forJob($job);

        if ($pdf === null) {
            // Cas réel et pas un incident : la ligne peut ne plus porter de
            // check-in ni de voyageur (séjour supprimé depuis l'enfilage).
            $this->error('Cette ligne ne produit pas de fiche : check-in ou voyageur absent.');

            return self::FAILURE;
        }

        $directory = storage_path('app/whatsapp/samples');

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("Répertoire non créable : {$directory}");

            return self::FAILURE;
        }

        $path = $directory.'/'.FichePdf::filenameFor($job);

        if (file_put_contents($path, $pdf) === false) {
            $this->error("Écriture impossible : {$path}");

            return self::FAILURE;
        }

        $this->info($path);
        $this->line(number_format(strlen($pdf) / 1024, 0, ',', ' ').' Ko');

        // Le seuil n'est pas celui de Meta (100 Mo) mais celui du confort : une
        // pièce jointe lourde s'ouvre mal sur un téléphone en 3G, et c'est là
        // qu'elle est lue.
        if (strlen($pdf) > 2 * 1024 * 1024) {
            $this->warn('Au-delà de 2 Mo : chargement lent sur mobile.');
        }

        $this->line('Contient des données personnelles réelles — à ne pas diffuser.');

        return self::SUCCESS;
    }
}
