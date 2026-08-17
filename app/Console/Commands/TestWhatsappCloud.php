<?php

namespace App\Console\Commands;

use App\Models\WhatsappSendLog;
use App\Services\Delivery\FichePdf;
use App\Services\Delivery\WhatsAppCloudChannel;
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
    protected $signature = 'whatsapp:cloud-test
        {--to= : Destinataire (chiffres internationaux). Défaut : WHATSAPP_RECIPIENT}
        {--fiche= : Identifiant d\'une ligne whatsapp_send_log à recopier (défaut : la plus récente avec photo)}
        {--text : Force le texte libre au lieu du modèle (ne marche que dans la fenêtre de 24 h)}';

    protected $description = 'Envoie une fiche de test via la Cloud API, sans toucher au canal de production';

    public function handle(): int
    {
        $to = (string) ($this->option('to') ?: config('whatsapp.recipient'));
        $to = preg_replace('/\D+/', '', $to);

        if (strlen((string) $to) < 8) {
            $this->error('Destinataire absent ou trop court. Utilisez --to=216XXXXXXXX.');

            return self::FAILURE;
        }

        // Le canal est instancié directement : l'essai ne doit dépendre ni de
        // WHATSAPP_CHANNEL, ni le modifier.
        $channel = new WhatsAppCloudChannel;

        if (!$channel->isConfigured()) {
            $this->error('Cloud API non configurée. Vérifiez WHATSAPP_CLOUD_TOKEN et WHATSAPP_CLOUD_PHONE_NUMBER_ID.');

            return self::FAILURE;
        }

        if ($this->option('text')) {
            // Le repli texte n'est légitime qu'en essai : en production tous nos
            // envois sont hors fenêtre de 24 h et exigent un modèle.
            config(['whatsapp.cloud.template_name' => null]);
        }

        $source = $this->sourceFiche();
        $job = $this->detachedCopy($source, (string) $to);

        $this->line('Canal    : '.$channel->name());
        $this->line('Vers     : '.$to);
        $this->line('Modèle   : '.(config('whatsapp.cloud.template_name') ?: '(texte libre)'));
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
            return new WhatsappSendLog([
                'recipient' => $to,
                'caption' => '[TEST CLOUD] Vérification du canal officiel — aucune fiche réelle.',
                'is_test' => true,
            ]);
        }

        $copy = $source->replicate(['id', 'status', 'sent_at', 'message_id_whatsapp', 'claimed_at', 'attempts']);
        $copy->recipient = $to;
        $copy->exists = false;

        return $copy;
    }
}
