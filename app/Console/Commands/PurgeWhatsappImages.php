<?php

namespace App\Console\Commands;

use App\Models\DocumentScan;
use App\Models\WhatsappChannelOutage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * Purge les images de documents (scans) au-delà de la fenêtre de rétention
 * (24 h par défaut). Minimisation des données : les images de pièces d'identité
 * ne sont conservées que le temps nécessaire aux envois WhatsApp (retries max
 * 24 h), puis supprimées — fichier ET ligne.
 *
 * Les lignes whatsapp_send_log qui les référencent voient simplement leur
 * scan_id passer à null (nullOnDelete) : le journal reste, sans l'image.
 */
class PurgeWhatsappImages extends Command
{
    protected $signature = 'whatsapp:purge-images';

    protected $description = 'Supprime les images de documents au-delà de la rétention (relais WhatsApp, provisoire)';

    public function handle(): int
    {
        $hours = (int) config('whatsapp.image_retention_hours', 24);
        $cutoff = now()->subHours($hours);
        $disk = config('filesystems.passport_scan_disk', 'local');

        $scans = DocumentScan::query()->where('created_at', '<', $cutoff)->get();

        $files = 0;
        foreach ($scans as $scan) {
            if ($scan->file_path && Storage::disk($disk)->exists($scan->file_path)) {
                Storage::disk($disk)->delete($scan->file_path);
                $files++;
            }
            $scan->delete();
        }

        $this->info("[whatsapp] purge images : {$scans->count()} scan(s), {$files} fichier(s) supprimé(s) (> {$hours} h).");

        /*
         * Registre d'incapacité du canal : élagué au même passage.
         *
         * La borne est volontairement large — bien au-delà des 24 h d'abandon
         * — parce qu'une entrée dont l'horloge a été suspendue vit plus
         * longtemps que 24 h au mur, et que supprimer une période encore
         * chevauchée par une fiche vivante la ferait vieillir d'un coup de
         * tout le temps où elle n'a rien pu tenter.
         */
        $outages = WhatsappChannelOutage::purgeBefore(now()->subDays(30));

        if ($outages > 0) {
            $this->line("[whatsapp] registre d'incapacité : {$outages} période(s) closes supprimées (> 30 j).");
        }

        return self::SUCCESS;
    }
}
