<?php

namespace App\Services\Whatsapp;

use App\Contracts\DeliveryResult;
use App\Models\AuthorityUserProfile;
use App\Models\User;
use App\Models\WhatsappBillableMessage;
use App\Models\WhatsappConversation;
use App\Models\WhatsappConversationMessage;
use App\Models\WhatsappSendLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Le fil de discussion avec une autorité : ouverture, messages entrants,
 * réponses depuis l'administration.
 *
 * ── Ce que ce service garantit ───────────────────────────────────────────
 *
 *  1. UN SEUL FIL PAR NUMÉRO. Le numéro est normalisé (chiffres seuls) avant
 *     toute recherche, et l'unicité est portée par la base : deux webhooks
 *     concurrents ne peuvent pas ouvrir deux fils pour le même agent.
 *  2. IDEMPOTENCE. Meta rejoue toute livraison de webhook non acquittée. Un
 *     entrant déjà connu par son `wamid` n'incrémente pas une deuxième fois le
 *     compteur de non-lus — sans quoi une boîte de réception afficherait « 7
 *     nouveaux » pour un seul message.
 *  3. AUCUNE INTERFÉRENCE avec l'envoi des fiches. Rien ici ne peut faire
 *     échouer un envoi : toute erreur d'écriture est avalée et journalisée.
 *     Une fiche non transmise coûte infiniment plus cher qu'une ligne de fil
 *     manquante.
 *
 * ── La fenêtre de service ────────────────────────────────────────────────
 *
 * Meta n'autorise le texte libre que dans les 24 h qui suivent un message
 * ENTRANT. Hors de cette fenêtre, seul un modèle approuvé passe — et nos deux
 * modèles (fiche, code de connexion) ne sont pas des réponses. Le service
 * refuse donc l'envoi plutôt que de le tenter : un refus 131047 côté Meta
 * arriverait de toute façon, mais après avoir laissé croire à l'administrateur
 * que son message était parti.
 */
class WhatsappConversationService
{
    /** Longueur de l'aperçu conservé sur le fil, en caractères. */
    private const PREVIEW_LENGTH = 160;

    public function __construct(
        private WhatsappCloudApi $api,
        private WhatsappCostRecorder $costs,
    ) {}

    // ── Ouverture du fil ─────────────────────────────────────────────────────

    /**
     * Le fil de ce numéro, créé au besoin.
     *
     * Rend null pour un numéro inexploitable (trop court, vide) plutôt que
     * d'ouvrir un fil fantôme que personne ne pourra jamais rattacher.
     */
    public function forPhone(?string $phone, ?string $contactName = null): ?WhatsappConversation
    {
        $normalized = WhatsappConversation::normalizePhone($phone);

        if ($normalized === null) {
            return null;
        }

        $conversation = WhatsappConversation::firstWhere('phone', $normalized);

        if ($conversation === null) {
            try {
                $conversation = DB::transaction(fn () => WhatsappConversation::create([
                    'phone' => $normalized,
                    'authority_user_profile_id' => $this->profileIdFor($normalized),
                    'contact_name' => $contactName,
                ]));
            } catch (QueryException $e) {
                // Course perdue contre un autre webhook : l'unicité de `phone`
                // a fait son travail, le fil existe désormais.
                $conversation = WhatsappConversation::firstWhere('phone', $normalized);
            }
        }

        if ($conversation === null) {
            return null;
        }

        $this->refreshIdentity($conversation, $contactName);

        return $conversation;
    }

    /**
     * Complète l'identité d'un fil sans jamais l'écraser à vide.
     *
     * Le rattachement à un agent est retenté à chaque passage : un fil peut
     * naître avant que l'admin n'ait saisi le numéro dans le profil, et il doit
     * se rattacher tout seul le jour où c'est fait.
     */
    private function refreshIdentity(WhatsappConversation $conversation, ?string $contactName): void
    {
        $updates = [];

        if ($conversation->authority_user_profile_id === null) {
            $profileId = $this->profileIdFor($conversation->phone);
            if ($profileId !== null) {
                $updates['authority_user_profile_id'] = $profileId;
            }
        }

        if (filled($contactName) && $contactName !== $conversation->contact_name) {
            $updates['contact_name'] = $contactName;
        }

        if ($updates !== []) {
            $conversation->forceFill($updates)->save();
        }
    }

    /** Agent dont le numéro correspond, s'il en existe un. */
    private function profileIdFor(string $normalizedPhone): ?int
    {
        return AuthorityUserProfile::query()
            ->whereNotNull('whatsapp_number')
            ->whereRaw("NULLIF(regexp_replace(whatsapp_number, '\\D', '', 'g'), '') = ?", [$normalizedPhone])
            ->value('id');
    }

    // ── Entrants ─────────────────────────────────────────────────────────────

    /**
     * Enregistre un message reçu.
     *
     * @param  array<string,mixed>  $message  objet `messages[]` du webhook
     * @param  array<int,array<string,mixed>>  $contacts  objet `contacts[]` de la même livraison
     */
    public function recordInbound(array $message, array $contacts = []): ?WhatsappConversationMessage
    {
        $wamid = $message['id'] ?? null;
        $from = $message['from'] ?? null;

        if (! is_string($wamid) || $wamid === '' || ! is_string($from)) {
            return null;
        }

        try {
            $conversation = $this->forPhone($from, $this->contactName($contacts, $from));

            if ($conversation === null) {
                return null;
            }

            /*
             * Le rejeu. Meta redélivre jusqu'à acquittement, et redélivre aussi
             * après ses propres incidents. Sans ce contrôle, le compteur de
             * non-lus monterait à chaque rejeu d'un message unique.
             */
            $existing = WhatsappConversationMessage::firstWhere('wamid', $wamid);
            if ($existing !== null) {
                return $existing;
            }

            $type = (string) ($message['type'] ?? 'unsupported');
            $occurredAt = $this->timestamp($message['timestamp'] ?? null);

            /*
             * Deux écritures pour un seul événement — enregistrer le message,
             * et faire avancer `last_inbound_at` sur la conversation — doivent
             * réussir ou échouer ENSEMBLE.
             *
             * Sans transaction, un redémarrage du conteneur (déploiement,
             * OOM) entre les deux laisse un message INBOUND en base dont la
             * conversation ne sait rien : `last_inbound_at` reste à sa valeur
             * précédente, et la fenêtre de service de 24 h — qui s'appuie
             * dessus — se calcule sur un message plus ancien, ou se referme
             * carrément (« NEVER_REPLIED ») si aucun inbound n'avait encore
             * été enregistré. L'agent voit alors « cette autorité n'a jamais
             * écrit » alors qu'elle vient de le faire, et la réponse reste
             * bloquée jusqu'à son PROCHAIN message.
             */
            $stored = DB::transaction(function () use ($conversation, $wamid, $type, $message, $occurredAt) {
                $stored = WhatsappConversationMessage::create([
                    'conversation_id' => $conversation->id,
                    'direction' => WhatsappConversationMessage::DIRECTION_INBOUND,
                    'wamid' => $wamid,
                    'type' => $type,
                    'body' => $this->inboundBody($message, $type),
                    'media_id' => $this->mediaField($message, $type, 'id'),
                    'media_mime' => $this->mediaField($message, $type, 'mime_type'),
                    'media_filename' => $this->mediaField($message, $type, 'filename'),
                    'context_wamid' => $message['context']['id'] ?? null,
                    'occurred_at' => $occurredAt,
                ]);

                $conversation->forceFill([
                    'last_inbound_at' => $occurredAt,
                    'last_message_at' => $occurredAt,
                    'last_message_direction' => WhatsappConversation::DIRECTION_INBOUND,
                    'last_message_preview' => $this->preview($stored),
                    'unread_count' => $conversation->unread_count + 1,
                ])->save();

                return $stored;
            });

            return $stored;
        } catch (\Throwable $e) {
            // Le webhook doit répondre 200 quoi qu'il arrive : un rejeu ne
            // rendrait pas ce message plus enregistrable.
            Log::warning('[whatsapp-inbox] message entrant non enregistré : '.$e->getMessage());

            return null;
        }
    }

    // ── Sortants : fiches ────────────────────────────────────────────────────

    /**
     * Rattache une fiche à son fil, à l'enfilage.
     *
     * Appelé depuis la création du job — donc AVANT tout envoi. Le fil s'ouvre
     * là : un poste de police à qui l'on écrit pour la première fois doit
     * apparaître dans la boîte de réception, même s'il ne répond jamais.
     */
    public function attachSendLog(WhatsappSendLog $job): void
    {
        try {
            $conversation = $this->forPhone($job->recipient);

            if ($conversation === null) {
                return;
            }

            $job->forceFill(['conversation_id' => $conversation->id])->save();
        } catch (\Throwable $e) {
            Log::warning('[whatsapp-inbox] rattachement de fiche impossible : '.$e->getMessage());
        }
    }

    /**
     * Une fiche vient de partir : le fil remonte en haut de la liste.
     *
     * L'aperçu ne porte JAMAIS le nom du voyageur — la liste des fils est un
     * écran de supervision, pas une liste de personnes contrôlées. Le détail
     * reste dans le fil, où il est déjà accessible à l'administrateur.
     */
    public function touchOutbound(WhatsappSendLog $job, ?Carbon $at = null): void
    {
        try {
            $conversation = $job->conversation_id !== null
                ? WhatsappConversation::find($job->conversation_id)
                : $this->forPhone($job->recipient);

            if ($conversation === null) {
                return;
            }

            if ($job->conversation_id === null) {
                $job->forceFill(['conversation_id' => $conversation->id])->save();
            }

            $at ??= now();

            $conversation->forceFill([
                'last_outbound_at' => $at,
                'last_message_at' => $at,
                'last_message_direction' => WhatsappConversation::DIRECTION_OUTBOUND,
                'last_message_preview' => 'Fiche de police transmise',
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('[whatsapp-inbox] mise à jour du fil impossible : '.$e->getMessage());
        }
    }

    // ── Sortants : réponses de l'administration ──────────────────────────────

    /**
     * Répond en texte libre à une autorité.
     *
     * La ligne est écrite DANS TOUS LES CAS, y compris quand Meta refuse :
     * un administrateur qui a cliqué « Envoyer » doit voir ce qu'il est advenu
     * de son message. Une tentative disparue passerait pour un message reçu.
     *
     * @throws ServiceWindowClosed quand Meta n'accepterait pas ce message
     */
    public function reply(WhatsappConversation $conversation, string $body, User $author): WhatsappConversationMessage
    {
        $body = trim($body);

        if ($body === '') {
            throw new \InvalidArgumentException('Le message est vide.');
        }

        /*
         * Coupe-circuit global, AVANT la fenêtre de service.
         *
         * C'est le geste d'exploitation qui arrête tout quand Meta signale la
         * qualité du numéro émetteur — il a déjà coûté un numéro à ce produit.
         * La boîte de réception ouvre un chemin d'émission de plus : sans ce
         * contrôle, un administrateur continuerait d'écrire à des postes de
         * police pendant la période où l'on cherche justement à ne plus rien
         * envoyer.
         *
         * Testé en premier parce qu'il prime : dire « la fenêtre est fermée »
         * alors que c'est le coupe-circuit qui bloque enverrait l'exploitant
         * chercher le problème du mauvais côté.
         */
        if (! config('whatsapp.guard.sending_enabled', true)) {
            throw new WhatsappSendingDisabled;
        }

        if (! $conversation->serviceWindowIsOpen()) {
            throw new ServiceWindowClosed($conversation);
        }

        $result = $this->api->sendText($conversation->phone, $body);

        $message = WhatsappConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => WhatsappConversationMessage::DIRECTION_OUTBOUND,
            'wamid' => $result->messageId,
            'type' => 'text',
            'body' => $body,
            'sent_by_user_id' => $author->id,
            'status' => $result->success
                ? WhatsappConversationMessage::STATUS_ACCEPTED
                : WhatsappConversationMessage::STATUS_FAILED,
            'error_message' => $result->success ? null : $result->error,
            'error_code' => $result->success ? null : $result->errorCode,
            'occurred_at' => now(),
        ]);

        if ($result->success) {
            /*
             * Catégorie SERVICE. Une réponse dans la fenêtre de 24 h n'est pas
             * un modèle : Meta la facture (ou non) au tarif « service », qui
             * est à 0 jusqu'au 30/09/2026 puis aligné sur utility. La compter
             * dès maintenant fait que la bascule sera un changement de
             * variable, et pas la découverte d'un poste de dépense.
             *
             * Sans établissement : une réponse est un échange avec une
             * autorité, pas un envoi imputable à un client hôtelier.
             */
            $this->costs->registerSend(
                $result->messageId,
                WhatsappBillableMessage::CATEGORY_SERVICE,
            );

            $conversation->forceFill([
                'last_outbound_at' => $message->occurred_at,
                'last_message_at' => $message->occurred_at,
                'last_message_direction' => WhatsappConversation::DIRECTION_OUTBOUND,
                'last_message_preview' => $this->preview($message),
            ])->save();
        }

        return $message;
    }

    /**
     * Accusé de réception d'une réponse admin, remonté par le webhook.
     *
     * @return bool true si la ligne concernée existait ici
     */
    public function applyOutboundStatus(
        string $wamid,
        string $state,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): bool {
        $message = WhatsappConversationMessage::query()
            ->where('wamid', $wamid)
            ->where('direction', WhatsappConversationMessage::DIRECTION_OUTBOUND)
            ->first();

        if ($message === null) {
            return false;
        }

        if ($state === WhatsappConversationMessage::STATUS_FAILED) {
            $message->forceFill([
                'status' => WhatsappConversationMessage::STATUS_FAILED,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ])->save();

            return true;
        }

        // Meta ne garantit pas l'ordre des accusés : un « sent » retardataire
        // ne doit pas faire reculer un message déjà lu.
        if ($this->rank($state) < $this->rank($message->status)) {
            return true;
        }

        $updates = ['status' => $state];

        if ($state === WhatsappConversationMessage::STATUS_DELIVERED) {
            $updates['delivered_at'] = $message->delivered_at ?? now();
        }

        if ($state === WhatsappConversationMessage::STATUS_READ) {
            $updates['read_at'] = $message->read_at ?? now();
            $updates['delivered_at'] = $message->delivered_at ?? now();
        }

        $message->forceFill($updates)->save();

        return true;
    }

    /** L'administration a ouvert le fil : le compteur de non-lus retombe. */
    public function markRead(WhatsappConversation $conversation): void
    {
        if ($conversation->unread_count === 0) {
            return;
        }

        $conversation->forceFill(['unread_count' => 0])->save();
    }

    // ── Interne ──────────────────────────────────────────────────────────────

    private function rank(?string $status): int
    {
        return match ($status) {
            WhatsappConversationMessage::STATUS_READ => 4,
            WhatsappConversationMessage::STATUS_DELIVERED => 3,
            WhatsappConversationMessage::STATUS_SENT => 2,
            WhatsappConversationMessage::STATUS_ACCEPTED => 1,
            default => 0,
        };
    }

    /**
     * Texte d'un message entrant, selon son type.
     *
     * Les types sans texte (image sans légende, audio, localisation) rendent
     * null : inventer un libellé ici le ferait passer pour un contenu écrit par
     * l'agent. L'écran, lui, sait dire « [image] » à partir du type.
     */
    private function inboundBody(array $message, string $type): ?string
    {
        $candidate = match ($type) {
            'text' => $message['text']['body'] ?? null,
            'button' => $message['button']['text'] ?? null,
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? null,
            'image', 'document', 'video', 'audio' => $message[$type]['caption'] ?? null,
            default => null,
        };

        if (! is_string($candidate) || trim($candidate) === '') {
            return null;
        }

        // Meta borne déjà à 4096, mais le corps arrive d'un tiers : on ne
        // stocke jamais plus que ce qu'on a décidé d'accepter.
        return mb_substr(trim($candidate), 0, WhatsappConversationMessage::MAX_BODY_LENGTH);
    }

    private function mediaField(array $message, string $type, string $field): ?string
    {
        if (! in_array($type, ['image', 'document', 'video', 'audio', 'sticker'], true)) {
            return null;
        }

        $value = $message[$type][$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function preview(WhatsappConversationMessage $message): string
    {
        if (filled($message->body)) {
            return mb_substr((string) $message->body, 0, self::PREVIEW_LENGTH);
        }

        return '['.$message->type.']';
    }

    /**
     * Nom de profil WhatsApp associé à ce numéro dans la livraison.
     *
     * @param  array<int,array<string,mixed>>  $contacts
     */
    private function contactName(array $contacts, string $from): ?string
    {
        foreach ($contacts as $contact) {
            if (($contact['wa_id'] ?? null) === $from) {
                $name = $contact['profile']['name'] ?? null;

                return is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 120) : null;
            }
        }

        return null;
    }

    /**
     * Horodatage Meta (secondes UNIX, en chaîne) → Carbon.
     *
     * Repli sur « maintenant » plutôt que sur l'époque UNIX : un message daté
     * de 1970 remonterait au fond du fil et passerait inaperçu, ce qui est
     * exactement l'inverse de ce qu'on veut d'un message qu'on n'a pas su
     * dater.
     */
    private function timestamp(mixed $raw): Carbon
    {
        return is_numeric($raw) ? Carbon::createFromTimestamp((int) $raw) : now();
    }
}
