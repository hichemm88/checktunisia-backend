<?php

namespace App\Console\Commands;

use App\Models\WhatsappSendLog;
use App\Services\Delivery\FichePdf;
use App\Services\Delivery\WhatsAppCloudChannel;
use App\Services\Whatsapp\WhatsappCloudConfig;
use Illuminate\Console\Command;

/**
 * Envoie UNE fiche par le canal Cloud API, sans basculer le canal de production.
 *
 * ── Pourquoi cette commande existe ───────────────────────────────────────────
 *
 * Sans elle, la seule façon d'exercer la Cloud API était de poser
 * WHATSAPP_CHANNEL=cloud : toutes les fiches réelles auraient alors été
 * routées vers un numéro de test qui ne parle qu'à cinq destinataires
 * déclarés. Valider un canal ne doit pas exiger de lui confier la production.
 *
 * ── Ce que la commande ne fait PAS ───────────────────────────────────────────
 *
 * Elle ne modifie JAMAIS l'état d'une fiche. Elle recopie une fiche réelle dans
 * un objet non persisté pour que le PDF, la photo et le destinataire soient
 * ceux de la vraie vie — mais un essai qui marquerait « envoyée » une fiche
 * encore due à l'autorité serait pire que pas d'essai du tout : la fiche
 * disparaîtrait de la file sans être parvenue à personne.
 */
class TestWhatsappCloud extends Command
{
    /**
     * Le destinataire est accepté en ARGUMENT autant qu'en option.
     *
     * La console web de Railway avale les doubles tirets : « --to=216… » y
     * arrive en « to216… », que bash tente d'exécuter comme une commande. Or
     * c'est précisément depuis cette console qu'on exerce le canal en
     * production. Un argument positionnel n'a pas ce problème.
     */
    protected $signature = 'whatsapp:cloud-test
        {destinataire? : Destinataire (chiffres internationaux). Défaut : WHATSAPP_RECIPIENT}
        {--to= : Idem, en option}
        {--fiche= : Identifiant d\'une ligne whatsapp_send_log à recopier (défaut : la plus récente avec photo)}';

    protected $description = 'Envoie une fiche de test via la Cloud API, sans toucher au canal de production';

    public function handle(): int
    {
        $to = (string) ($this->argument('destinataire') ?: $this->option('to') ?: config('whatsapp.recipient'));
        $to = preg_replace('/\D+/', '', $to);

        if (strlen((string) $to) < 8) {
            $this->error('Destinataire absent ou trop court. Exemple : php artisan whatsapp:cloud-test 21620123456');

            return self::FAILURE;
        }

        // Le canal est résolu par le conteneur — il a des dépendances (client
        // API, garde-fous) — mais SANS toucher à WHATSAPP_CHANNEL : l'essai ne
        // doit ni dépendre du canal de production, ni le modifier.
        $channel = app(WhatsAppCloudChannel::class);

        if ($missing = WhatsappCloudConfig::missingToSend()) {
            $this->error(WhatsappCloudConfig::explain($missing));

            return self::FAILURE;
        }

        if (!$channel->isConfigured()) {
            $this->error('Canal Cloud API non configuré (whatsapp.enabled ?).');

            return self::FAILURE;
        }

        /*
         * La bascule protège l'ARRIÉRÉ, pas les essais.
         *
         * Un essai est humain, délibéré et daté de maintenant : le bloquer
         * parce que WHATSAPP_CLOUD_API_CUTOVER_AT n'est pas encore posée
         * rendrait le canal impossible à éprouver AVANT de le mettre en
         * service — c'est-à-dire exactement au moment où l'on en a besoin.
         */
        if (blank(config('whatsapp.guard.cutover_at'))) {
            config(['whatsapp.guard.cutover_at' => now()->subMinute()->toIso8601String()]);
            $this->comment('Bascule non armée : ignorée pour cet essai (elle ne protège que l\'arriéré).');
        }

        $source = $this->sourceFiche();
        $job = $this->detachedCopy($source, (string) $to);

        $this->line('Canal    : '.$channel->name());
        $this->line('Vers     : '.$to);
        $this->line('Modèle   : '.config('whatsapp.cloud.template.name').' ('.config('whatsapp.cloud.template.language').')');
        $this->line('Fiche    : '.($source ? $source->id.($job->scan_id ? ' (avec photo)' : ' (sans photo)') : 'factice'));
        $this->line('PDF joint: '.($source && FichePdf::forJob($job) !== null ? 'oui' : 'non'));
        $this->newLine();

        $result = $channel->send($job);

        if ($result->success) {
            $this->info('Envoyé. Identifiant de message : '.($result->messageId ?? '—'));

            if ($source) {
                $this->comment('La fiche '.$source->id.' est restée « '.$source->status.' » — un essai ne la consomme pas.');
            }

            return self::SUCCESS;
        }

        // Le message de Meta est rendu tel quel : c'est lui qui indique quoi
        // corriger (modèle absent, destinataire non déclaré, jeton périmé…).
        $this->error('Échec : '.$result->error);
        $this->line($result->retryable ? 'Classé réessayable.' : 'Classé définitif — la requête est en cause.');

        return self::FAILURE;
    }

    /** Fiche réelle à recopier, pour que PDF et photo soient représentatifs. */
    private function sourceFiche(): ?WhatsappSendLog
    {
        if ($id = $this->option('fiche')) {
            return WhatsappSendLog::find($id);
        }

        return WhatsappSendLog::query()
            ->whereNotNull('check_in_id')
            ->whereNotNull('guest_id')
            ->orderByRaw('CASE WHEN scan_id IS NULL THEN 1 ELSE 0 END') // avec photo d'abord
            ->latest('queued_at')
            ->first();
    }

    /**
     * Copie NON PERSISTÉE : même contenu, même pièce, autre destinataire.
     * `newInstance` sans sauvegarde garantit qu'aucun `save()` du canal ne
     * pourrait toucher la ligne d'origine.
     */
    private function detachedCopy(?WhatsappSendLog $source, string $to): WhatsappSendLog
    {
        if (!$source) {
            $job = new WhatsappSendLog([
                'recipient' => $to,
                'caption' => '[TEST CLOUD] Vérification du canal officiel — aucune fiche réelle.',
                'is_test' => true,
            ]);
            // Sans check-in, FichePdf rend le document factice : l'en-tête du
            // modèle est obligatoire, un essai sans pièce jointe serait refusé.
            $job->created_at = now();

            return $job;
        }

        $copy = $source->replicate(['id', 'status', 'sent_at', 'message_id_whatsapp', 'claimed_at', 'attempts']);
        $copy->recipient = $to;
        $copy->exists = false;

        // La fiche recopiée peut dater de l'arriéré : le garde-fou de bascule
        // la refuserait, alors que l'ESSAI, lui, est bien postérieur. On date
        // la copie de maintenant — l'original n'est pas touché.
        $copy->created_at = now();

        return $copy;
    }
}
