<?php

namespace App\Http\Controllers\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\AuthorityUserProfile;
use App\Models\WhatsappSendLog;
use App\Models\WhatsappSessionState;
use App\Services\Delivery\DeliveryChannelManager;
use App\Services\Whatsapp\WhatsappCloudConfig;
use App\Services\Whatsapp\WhatsappOutboxService;
use App\Services\Whatsapp\WhatsappSendingGuard;
use App\Services\Whatsapp\WhatsappTemplateStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * Écran d'administration du relais WhatsApp (platform_admin) :
 * état de santé, journal filtrable, renvoi, message test, pause d'urgence.
 */
class WhatsappAdminController extends Controller
{
    public function __construct(private WhatsappOutboxService $outbox) {}

    /**
     * GET health/whatsapp — route PUBLIQUE, sans session.
     *
     * Strict minimum. Elle servait jusqu'ici la même charge utile que l'écran
     * d'administration : profondeur de file, état de session, motif de la
     * dernière déconnexion. Rien de tout cela n'est secret au sens d'un
     * jeton, mais rien de tout cela n'a à être lisible par n'importe qui :
     * la profondeur de file dit combien de voyageurs ont été enregistrés, et
     * le motif de blocage décrit notre configuration interne.
     *
     * Ce qui reste est ce dont les deux seuls appelants ont besoin : le
     * front (« puis-je annoncer que la fiche partira ? ») et une sonde
     * externe (« est-ce que ça va ? »). Le détail vit derrière
     * GET admin/whatsapp/health, authentifié.
     */
    public function publicHealth(DeliveryChannelManager $channels, WhatsappSendingGuard $guard): JsonResponse
    {
        $enabled = $this->outbox->enabled();

        return response()->json(['data' => [
            'enabled' => $enabled,
            // Verdict grossier, sans dire pourquoi : « degraded » suffit à
            // déclencher un regard humain, qui lira le détail côté admin.
            'status' => match (true) {
                !$enabled => 'disabled',
                $this->blockingReason($channels, $guard) !== null => 'degraded',
                default => 'ok',
            },
        ]]);
    }

    /**
     * GET admin/whatsapp/health — état complet, réservé aux administrateurs.
     *
     * Aucun secret ici non plus : ni jeton, ni identifiant Meta, ni numéro de
     * destinataire, ni jeton public de fiche. Des compteurs, un état de
     * session, et la raison pour laquelle rien ne part.
     */
    public function health(
        DeliveryChannelManager $channels,
        WhatsappSendingGuard $guard,
        WhatsappTemplateStatus $templates,
    ): JsonResponse {
        $state = WhatsappSessionState::current();
        $counts = WhatsappSendLog::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $throttle = $this->outbox->throttle($state);

        return response()->json(['data' => [
            'enabled' => $this->outbox->enabled(),
            'session' => $state->status,
            /*
             | Le statut ci-dessus ne décrit que le relais WhatsApp Web
             | historique (session appairée par QR) : sur le canal Cloud API
             | (PUSH, sans session ni QR — voir WhatsAppCloudChannel), il est
             | figé sur son dernier état avant bascule et ne signale plus rien
             | de réel. Sans ce champ, l'écran affiche indéfiniment
             | « ré-appairage nécessaire » pour un canal qui n'en a jamais eu
             | besoin.
             */
            'session_relevant' => !$channels->active()->supportsPush(),
            'reason' => $state->reason,
            'paused' => $state->paused,
            'last_ready_at' => $state->last_ready_at,
            'heartbeat_at' => $state->heartbeat_at,
            // Cadence en vigueur. Sans ça, une file bridée par le plafond
            // horaire est indiscernable d'une file en panne : « 14 en attente »
            // et rien qui part, sans la moindre explication à l'écran.
            'throttle' => [
                'sending' => $throttle['allowed'],
                'warmup' => $throttle['warmup'],
                'sent_last_hour' => $throttle['sent_last_hour'],
                'max_per_hour' => $throttle['max_per_hour'],
                'min_interval_seconds' => $throttle['min_interval_seconds'],
                'next_slot_at' => $throttle['next_slot_at'],
                'paired_at' => $state->paired_at,
            ],
            'queue' => [
                'pending' => (int) ($counts[WhatsappSendLog::STATUS_PENDING] ?? 0),
                'sent' => (int) ($counts[WhatsappSendLog::STATUS_SENT] ?? 0),
                'failed' => (int) ($counts[WhatsappSendLog::STATUS_FAILED] ?? 0),
                'cancelled' => (int) ($counts[WhatsappSendLog::STATUS_CANCELLED] ?? 0),
                // Fiches que « Renvoyer tout » débloquerait : échouées + en
                // attente d'un backoff (jusqu'à 4 h). Sans ce compteur, le bouton
                // restait caché alors que la file était figée.
                'stuck' => $this->outbox->stuckCount(),
                // Fiches ACCEPTEES par Meta dont la livraison n'est jamais
                // venue. Elles etaient rangees sous « envoyees », donc parmi
                // les succes : le poste de police n'avait rien recu et rien ne
                // le disait.
                'undelivered' => $this->outbox->undeliveredCount(),
            ],
            /*
             | Pourquoi rien ne part.
             |
             | Sans cette information, un canal bloqué par un garde-fou est
             | indiscernable d'un canal qui n'a rien à envoyer : la file gonfle
             | en silence, exactement comme pendant le bannissement du relais
             | Web. Aucun secret ici — un nom de canal, un booléen, une phrase.
             */
            'channel' => $channels->active()->name(),
            'sending_blocked' => $this->blockingReason($channels, $guard) !== null,
            'blocked_reason' => $this->blockingReason($channels, $guard),
            /*
             | État du modèle chez Meta.
             |
             | La pièce que l'écran ne montrait pas, et sans laquelle
             | « 40 en attente, rien ne part » n'avait aucune explication
             | lisible : le modèle attendait son approbation. Ce n'est pas la
             | même chose qu'une panne, et cela ne se corrige pas de la même
             | façon — cela ne se corrige même pas du tout, cela s'attend.
             */
            'template' => $templates->snapshot(),
            // Noms de variables absentes, jamais leurs valeurs : de quoi
            // corriger sans avoir à deviner, et sans rien divulguer.
            'missing_config' => WhatsappCloudConfig::missingForAdmin(),
        ]]);
    }

    /**
     * Motif de blocage — uniquement quand le canal actif transmet lui-même.
     *
     * Les garde-fous (bascule, débit, arriéré) protègent la réputation du
     * numéro émetteur de la Cloud API. Les appliquer à un canal en pull
     * afficherait « dégradé » sur un relais qui n'en dépend pas — un signal
     * faux, donc un signal qu'on apprend à ignorer.
     */
    private function blockingReason(DeliveryChannelManager $channels, WhatsappSendingGuard $guard): ?string
    {
        return $channels->active()->supportsPush() ? $guard->blockingReason() : null;
    }

    /** GET admin/whatsapp/logs — journal filtrable par propriété / statut / date. */
    public function logs(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'hotel_id' => 'nullable|uuid',
            'status' => 'nullable|in:pending,sent,failed,cancelled',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $logs = WhatsappSendLog::query()
            ->with(['hotel:id,name', 'guest:id,first_name,last_name'])
            ->when($filters['hotel_id'] ?? null, fn ($q, $v) => $q->where('hotel_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('queued_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('queued_at', '<=', $v))
            ->latest('queued_at')
            ->paginate($filters['per_page'] ?? 25);

        // Résolution destinataire (JID → nom d'agent) : la plupart des envois
        // vont désormais à un agent précis, l'admin doit voir lequel.
        $profilesByNumber = AuthorityUserProfile::whereNotNull('whatsapp_number')
            ->with(['user:id,first_name,last_name', 'organization:id,name'])
            ->get()
            ->keyBy(fn ($p) => preg_replace('/\D+/', '', (string) $p->whatsapp_number));

        $globalNumber = preg_replace('/\D+/', '', (string) config('whatsapp.recipient'));

        return response()->json([
            'data' => $logs->map(function (WhatsappSendLog $l) use ($profilesByNumber, $globalNumber) {
                $digits = preg_replace('/\D+/', '', (string) str_replace('@c.us', '', (string) $l->recipient));
                $prof = $profilesByNumber[$digits] ?? null;
                $recipientName = $prof
                    ? trim(((string) $prof->user?->first_name).' '.((string) $prof->user?->last_name))
                    : ($digits !== '' && $digits === $globalNumber ? 'Numéro global' : null);

                return [
                    'id' => $l->id,
                    'hotel' => $l->hotel?->name,
                    'hotel_id' => $l->hotel_id,
                    'guest' => $l->guest ? trim(strtoupper((string) $l->guest->last_name).' '.$l->guest->first_name) : null,
                    'check_in_id' => $l->check_in_id,
                    'status' => $l->status,
                    'attempts' => $l->attempts,
                    'last_error' => $l->last_error,
                    'is_test' => $l->is_test,
                    'has_photo' => (bool) $l->scan_id,
                    'message_id_whatsapp' => $l->message_id_whatsapp,
                    'recipient_number' => $digits ?: null,
                    'recipient_name' => $recipientName,
                    'recipient_org' => $prof?->organization?->name,
                    'queued_at' => $l->queued_at,
                    'sent_at' => $l->sent_at,
                    'next_attempt_at' => $l->next_attempt_at,
                ];
            }),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /** POST admin/whatsapp/logs/{id}/resend — remet un envoi échoué en file. */
    public function resend(string $id): JsonResponse
    {
        $job = WhatsappSendLog::findOrFail($id);
        $this->outbox->resend($job);

        return response()->json(['data' => ['ok' => true, 'status' => $job->fresh()->status]]);
    }

    /** POST admin/whatsapp/logs/resend-all — remet en file tous les envois échoués. */
    public function resendAll(): JsonResponse
    {
        $count = $this->outbox->resendAllFailed();

        return response()->json(['data' => ['ok' => true, 'requeued' => $count]]);
    }

    /**
     * POST admin/whatsapp/logs/dismiss-failed — annule (sans les supprimer)
     * toutes les fiches en échec définitif, au lieu de les relancer.
     */
    public function dismissFailed(): JsonResponse
    {
        $count = $this->outbox->dismissAllFailed();

        return response()->json(['data' => ['ok' => true, 'dismissed' => $count]]);
    }

    /** POST admin/whatsapp/test — enfile une fiche factice [TEST]. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['property_name' => 'nullable|string|max:120']);

        if (!$this->outbox->enabled()) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'WHATSAPP_DISABLED', 'message' => 'Le relais WhatsApp est désactivé (WHATSAPP_POLICE_ENABLED=false ou destinataire absent).', 'field' => null]],
            ], 422);
        }

        $job = $this->outbox->enqueueTest($data['property_name'] ?? null);

        return response()->json(['data' => ['ok' => true, 'id' => $job?->id]]);
    }

    /** POST admin/whatsapp/pause — coupe les envois immédiatement (sans redéploiement). */
    public function pause(): JsonResponse
    {
        $state = WhatsappSessionState::current();
        $state->forceFill(['paused' => true])->save();

        return response()->json(['data' => ['paused' => true]]);
    }

    /** POST admin/whatsapp/resume — relance les envois. */
    public function resume(): JsonResponse
    {
        $state = WhatsappSessionState::current();
        // `paused` ne couvrait que la pause ADMIN. Le worker a sa propre veille
        // (30 min après échecs répétés), interne à son processus : « Reprendre »
        // ne la levait pas, et rien d'autre ne le pouvait — il fallait attendre.
        // L'horodatage est relayé par control() ; le worker en déduit qu'une
        // reprise a été demandée après le début de sa veille, et la lève.
        $state->forceFill(['paused' => false, 'resume_requested_at' => now()])->save();

        return response()->json(['data' => ['paused' => false]]);
    }
}
