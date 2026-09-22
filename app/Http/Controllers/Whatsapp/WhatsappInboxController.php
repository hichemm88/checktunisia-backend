<?php

namespace App\Http\Controllers\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappConversation;
use App\Models\WhatsappConversationMessage;
use App\Models\WhatsappSendLog;
use App\Services\Audit\AuditLogger;
use App\Services\Whatsapp\ServiceWindowClosed;
use App\Services\Whatsapp\WhatsappConversationService;
use App\Services\Whatsapp\WhatsappMediaUnavailable;
use App\Services\Whatsapp\WhatsappSendingDisabled;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Boîte de réception des autorités (platform_admin).
 *
 * Trois écrans, trois endpoints : la liste des fils, un fil, l'envoi d'une
 * réponse.
 *
 * ── La chronologie est une FUSION ────────────────────────────────────────
 *
 * Un fil mêle deux sources qui ne sont pas dans la même table, et c'est
 * délibéré :
 *
 *  - les FICHES viennent de `whatsapp_send_log`, où elles ont leur file, leurs
 *    tentatives, leur PDF et leurs statuts de livraison ;
 *  - les MESSAGES (réponses des agents, réponses de l'administration) viennent
 *    de `whatsapp_conversation_messages`.
 *
 * Recopier les fiches dans la seconde table donnerait deux vérités pour un
 * même envoi, et la copie finirait par diverger de l'original. Le tri commun
 * se fait ici, à la lecture, sur une fenêtre bornée.
 *
 * ── Ce que la liste ne montre pas ────────────────────────────────────────
 *
 * Aucune identité de voyageur dans la liste des fils : c'est un écran de
 * supervision du canal, pas un registre de personnes contrôlées. Les noms
 * n'apparaissent qu'à l'ouverture d'un fil, où ils sont déjà accessibles à un
 * administrateur par le journal d'envoi.
 */
class WhatsappInboxController extends Controller
{
    /** Profondeur d'un fil. Au-delà, l'écran n'est plus lisible et la requête plus bornée. */
    private const TIMELINE_LIMIT = 200;

    public function __construct(private WhatsappConversationService $conversations) {}

    /**
     * GET admin/whatsapp/inbox
     *
     * @queryParam search  nom d'agent, numéro, ou service
     * @queryParam filter  all | unread | replied | awaiting  (défaut : all)
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:120',
            'filter' => 'nullable|in:all,unread,replied,awaiting',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $filter = $filters['filter'] ?? 'all';

        $query = WhatsappConversation::query()
            ->with([
                'authorityProfile:id,user_id,organization_id,badge_number,rank',
                'authorityProfile.user:id,first_name,last_name',
                'authorityProfile.organization:id,name',
            ])
            ->when($search !== '', function ($q) use ($search) {
                /*
                 * La recherche porte sur l'INTERLOCUTEUR, jamais sur le
                 * contenu : les corps de messages sont chiffrés au repos, et
                 * les déchiffrer pour filtrer obligerait à tout charger en
                 * mémoire. Nom, numéro, matricule, service — c'est ce qu'un
                 * administrateur cherche réellement.
                 */
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $digits = preg_replace('/\D+/', '', $search);

                $q->where(function ($sub) use ($like, $digits) {
                    $sub->where('contact_name', 'ilike', $like)
                        ->orWhereHas('authorityProfile.user', fn ($u) => $u
                            ->where('first_name', 'ilike', $like)
                            ->orWhere('last_name', 'ilike', $like))
                        ->orWhereHas('authorityProfile', fn ($p) => $p->where('badge_number', 'ilike', $like))
                        ->orWhereHas('authorityProfile.organization', fn ($o) => $o->where('name', 'ilike', $like));

                    // Un numéro partiel ne doit pas être traité comme du texte :
                    // « 20 12 34 56 » et « 20123456 » désignent le même agent.
                    if ($digits !== '') {
                        $sub->orWhere('phone', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->when($filter === 'unread', fn ($q) => $q->where('unread_count', '>', 0))
            // « awaiting » : l'agent a écrit, et rien n'est reparti depuis.
            // C'est la file de travail réelle d'un administrateur.
            ->when($filter === 'awaiting', fn ($q) => $q
                ->whereNotNull('last_inbound_at')
                ->where(fn ($s) => $s->whereNull('last_outbound_at')
                    ->orWhereColumn('last_outbound_at', '<', 'last_inbound_at')))
            ->when($filter === 'replied', fn ($q) => $q->whereNotNull('last_inbound_at'))
            // `latest` sur une colonne nullable placerait les fils sans activité
            // en tête sous PostgreSQL (NULLS FIRST en tri descendant).
            ->orderByRaw('last_message_at DESC NULLS LAST');

        $page = $query->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (WhatsappConversation $c) => $this->summary($c))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                // Compteur global : le badge de la navigation ne doit pas
                // dépendre de la page ni du filtre affichés.
                'unread_total' => (int) WhatsappConversation::where('unread_count', '>', 0)->sum('unread_count'),
            ],
        ]);
    }

    /**
     * GET admin/whatsapp/inbox/unread-count
     *
     * Endpoint dédié pour le badge de navigation : il doit rester lisible
     * depuis n'importe quel écran de l'administration, pas seulement depuis
     * cette page. Même définition que `meta.unread_total` ci-dessus — c'est
     * la MÊME requête, pas une deuxième version qui pourrait diverger.
     */
    public function unreadCount(): JsonResponse
    {
        return response()->json([
            'data' => [
                'count' => (int) WhatsappConversation::where('unread_count', '>', 0)->sum('unread_count'),
            ],
        ]);
    }

    /**
     * GET admin/whatsapp/inbox/{id}
     *
     * Ouvrir un fil le marque lu CÔTÉ ADMINISTRATION uniquement. Rien n'est
     * renvoyé à Meta : poser un accusé de lecture sur WhatsApp dirait à l'agent
     * qu'un humain a lu son message, ce que l'ouverture d'un écran ne prouve
     * pas.
     */
    public function show(string $id): JsonResponse
    {
        $conversation = WhatsappConversation::with([
            'authorityProfile.user:id,first_name,last_name',
            'authorityProfile.organization:id,name',
        ])->findOrFail($id);

        $timeline = $this->timeline($conversation);

        $this->conversations->markRead($conversation);

        return response()->json([
            'data' => [
                'conversation' => $this->summary($conversation->fresh([
                    'authorityProfile.user', 'authorityProfile.organization',
                ])),
                'timeline' => $timeline,
                'reply' => $this->replyCapability($conversation),
            ],
        ]);
    }

    /**
     * POST admin/whatsapp/inbox/{id}/reply
     *
     * Réponse en texte libre. Refusée AVANT l'appel à Meta quand la fenêtre de
     * 24 h est fermée — la réponse porte alors le motif et l'instant de
     * fermeture, pour que l'écran explique au lieu d'échouer.
     */
    public function reply(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'message' => 'required|string|max:'.WhatsappConversationMessage::MAX_BODY_LENGTH,
        ]);

        $conversation = WhatsappConversation::findOrFail($id);

        try {
            $message = $this->conversations->reply($conversation, $data['message'], $request->user());
        } catch (WhatsappSendingDisabled $e) {
            // 503 et non 422 : la demande est valide, c'est le service qui est
            // volontairement coupé. Un 422 ferait chercher l'erreur dans le
            // message saisi.
            return response()->json([
                'data' => null,
                'errors' => [[
                    'code' => 'WHATSAPP_SENDING_DISABLED',
                    'message' => $e->getMessage(),
                    'field' => null,
                ]],
            ], 503);
        } catch (ServiceWindowClosed $e) {
            return response()->json([
                'data' => null,
                'errors' => [[
                    'code' => 'SERVICE_WINDOW_CLOSED',
                    'message' => $e->getMessage(),
                    'field' => 'message',
                ]],
                'meta' => ['reply' => $this->replyCapability($conversation)],
            ], 422);
        }

        /*
         * Journal d'audit. Répondre à un poste de police depuis Qayed est un
         * acte qui engage l'éditeur : il doit être imputable à un compte, avec
         * son heure. Le CONTENU n'entre pas dans le journal d'audit — il vit
         * dans le fil, chiffré ; le dupliquer en clair ici annulerait ce
         * chiffrement.
         */
        AuditLogger::log(
            action: 'whatsapp.inbox.reply',
            subject: $conversation,
            newValues: [
                'status' => $message->status,
                'length' => mb_strlen($data['message']),
            ],
        );

        if ($message->status === WhatsappConversationMessage::STATUS_FAILED) {
            return response()->json([
                'data' => null,
                'errors' => [[
                    'code' => 'REPLY_REFUSED',
                    'message' => $message->error_message ?? 'Message refusé par WhatsApp.',
                    'field' => null,
                ]],
            ], 422);
        }

        return response()->json(['data' => $this->message($message)], 201);
    }

    /**
     * GET admin/whatsapp/inbox/{id}/messages/{messageId}/media
     *
     * Pièce jointe REÇUE d'un agent (image, document…), rapatriée à la
     * demande — jamais stockée côté Qayed. Chaque appel retélécharge depuis
     * Meta ; passé sa fenêtre de conservation (~30 jours), Meta ne la rend
     * plus, ce que l'écran doit distinguer d'une panne (410, pas 500).
     */
    public function media(string $id, string $messageId): Response|JsonResponse
    {
        $message = WhatsappConversationMessage::where('conversation_id', $id)
            ->where('id', $messageId)
            ->firstOrFail();

        if (blank($message->media_id)) {
            abort(404);
        }

        try {
            $media = $this->conversations->fetchMedia($message->media_id);
        } catch (WhatsappMediaUnavailable $e) {
            return response()->json([
                'data' => null,
                'errors' => [[
                    'code' => 'MEDIA_UNAVAILABLE',
                    'message' => 'Ce média n\'est plus disponible côté WhatsApp (expiré après ~30 jours, ou déjà purgé).',
                    'field' => null,
                ]],
            ], 410);
        }

        $filename = $message->media_filename ?? 'media';

        return response($media['bytes'])
            ->header('Content-Type', $media['mime'] ?? $message->media_mime ?? 'application/octet-stream')
            ->header('Content-Disposition', 'inline; filename="'.str_replace('"', '', $filename).'"')
            // Une réponse rapatriée à la demande depuis un tiers dont l'URL
            // est temporaire : rien à mettre en cache, et surtout pas de cache
            // partagé (proxy) sur une pièce jointe de police.
            ->header('Cache-Control', 'private, max-age=0, no-store');
    }

    // ── Interne ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function summary(WhatsappConversation $c): array
    {
        $profile = $c->authorityProfile;
        $user = $profile?->user;

        $name = $user !== null
            ? trim(strtoupper((string) $user->last_name).' '.$user->first_name)
            : null;

        $closesAt = $c->serviceWindowClosesAt();

        return [
            'id' => $c->id,
            'phone' => $c->phone,
            // Nom de profil WhatsApp : indicatif, choisi par l'agent lui-même.
            // Servi à part du nom enregistré pour qu'on ne les confonde pas.
            'contact_name' => $c->contact_name,
            'authority' => $profile === null ? null : [
                'profile_id' => $profile->id,
                'name' => $name,
                'badge_number' => $profile->badge_number,
                'rank' => $profile->rank,
                'organization' => $profile->organization?->name,
            ],
            'unread_count' => $c->unread_count,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last_message_direction' => $c->last_message_direction,
            'last_message_preview' => $c->last_message_preview,
            'last_inbound_at' => $c->last_inbound_at?->toIso8601String(),
            'last_outbound_at' => $c->last_outbound_at?->toIso8601String(),
            'service_window_open' => $c->serviceWindowIsOpen(),
            'service_window_closes_at' => $closesAt?->toIso8601String(),
        ];
    }

    /**
     * Ce que l'écran a besoin de savoir pour afficher — ou griser — le champ
     * de réponse, et pour DIRE POURQUOI quand il est grisé.
     *
     * @return array<string,mixed>
     */
    private function replyCapability(WhatsappConversation $c): array
    {
        $open = $c->serviceWindowIsOpen();

        return [
            'allowed' => $open,
            'window_closes_at' => $c->serviceWindowClosesAt()?->toIso8601String(),
            'max_length' => WhatsappConversationMessage::MAX_BODY_LENGTH,
            'reason' => $open ? null : ($c->last_inbound_at === null
                ? 'NEVER_REPLIED'
                : 'WINDOW_EXPIRED'),
        ];
    }

    /**
     * Chronologie fusionnée du fil : fiches + messages, du plus ancien au plus
     * récent.
     *
     * Chaque source est bornée à TIMELINE_LIMIT AVANT la fusion : un fil très
     * bavard ne doit pas pouvoir charger tout le journal d'envoi en mémoire.
     *
     * Les RÉACTIONS n'y figurent jamais comme entrées à part : sur WhatsApp,
     * un emoji n'est pas un message, c'est une annotation d'un message
     * existant. On les résout à part (voir `resolveReactions`) et on les
     * accroche à l'entrée qu'elles visent.
     *
     * @return array<int,array<string,mixed>>
     */
    private function timeline(WhatsappConversation $c): array
    {
        $fiches = WhatsappSendLog::query()
            ->where('conversation_id', $c->id)
            ->with(['guest:id,first_name,last_name', 'hotel:id,name'])
            ->orderByDesc('queued_at')
            ->limit(self::TIMELINE_LIMIT)
            ->get();

        $messages = WhatsappConversationMessage::query()
            ->where('conversation_id', $c->id)
            ->with('sender:id,first_name,last_name')
            ->orderByDesc('occurred_at')
            ->limit(self::TIMELINE_LIMIT)
            ->get();

        $reactions = $this->resolveReactions($c);

        $ficheEntries = $fiches->map(fn (WhatsappSendLog $l) => $this->ficheEntry($l, $reactions));

        $messageEntries = $messages
            ->reject(fn (WhatsappConversationMessage $m) => $m->isReaction())
            ->map(fn (WhatsappConversationMessage $m) => $this->message($m, $reactions));

        return Collection::make($ficheEntries)
            ->merge($messageEntries)
            ->sortBy('at')
            ->values()
            ->all();
    }

    /**
     * État courant des réactions du fil, par wamid VISÉ.
     *
     * Meta ne redonne jamais l'historique : chaque webhook `reaction` porte
     * l'état à cet instant. La ligne la plus récente par cible EST l'état
     * courant — une réaction plus récente en remplace une plus ancienne sur
     * la même cible, et un retrait (`reaction_emoji` NULL) fait disparaître
     * la cible de la carte rendue ici.
     *
     * @return array<string,array{emoji:string,at:?string}>
     */
    private function resolveReactions(WhatsappConversation $c): array
    {
        $latestPerTarget = WhatsappConversationMessage::query()
            ->where('conversation_id', $c->id)
            ->where('type', WhatsappConversationMessage::TYPE_REACTION)
            ->whereNotNull('target_wamid')
            ->orderByDesc('occurred_at')
            ->get()
            ->unique('target_wamid');

        $map = [];

        foreach ($latestPerTarget as $reaction) {
            if ($reaction->reaction_emoji === null) {
                continue; // Retirée : rien à accrocher sur la cible.
            }

            $map[$reaction->target_wamid] = [
                'emoji' => $reaction->reaction_emoji,
                'at' => $reaction->occurred_at?->toIso8601String(),
            ];
        }

        return $map;
    }

    /** @param array<string,array{emoji:string,at:?string}> $reactions */
    private function reactionFor(?string $wamid, array $reactions): ?array
    {
        return $wamid !== null ? ($reactions[$wamid] ?? null) : null;
    }

    /**
     * @param  array<string,array{emoji:string,at:?string}>  $reactions
     * @return array<string,mixed>
     */
    private function ficheEntry(WhatsappSendLog $l, array $reactions = []): array
    {
        return [
            'kind' => 'fiche',
            'id' => $l->id,
            'direction' => WhatsappConversation::DIRECTION_OUTBOUND,
            'at' => ($l->sent_at ?? $l->queued_at)?->toIso8601String(),
            'wamid' => $l->message_id_whatsapp,
            // Identité du voyageur : visible dans le fil, jamais dans la liste.
            'guest' => $l->guest
                ? trim(strtoupper((string) $l->guest->last_name).' '.$l->guest->first_name)
                : null,
            'establishment' => $l->hotel?->name,
            'check_in_id' => $l->check_in_id,
            'is_test' => (bool) $l->is_test,
            'status' => $l->status,
            'delivery_status' => $l->delivery_status,
            'queued_at' => $l->queued_at?->toIso8601String(),
            'sent_at' => $l->sent_at?->toIso8601String(),
            'delivered_at' => $l->delivered_at?->toIso8601String(),
            'read_at' => $l->read_at?->toIso8601String(),
            'error' => $l->last_error,
            'reaction' => $this->reactionFor($l->message_id_whatsapp, $reactions),
        ];
    }

    /**
     * @param  array<string,array{emoji:string,at:?string}>  $reactions
     * @return array<string,mixed>
     */
    private function message(WhatsappConversationMessage $m, array $reactions = []): array
    {
        $sender = $m->sender;

        return [
            'kind' => 'message',
            'id' => $m->id,
            'direction' => $m->direction,
            'at' => $m->occurred_at?->toIso8601String(),
            'wamid' => $m->wamid,
            'type' => $m->type,
            'body' => $m->body,
            // L'identifiant Meta du média, pas le fichier : il expire en 30
            // jours chez Meta, et rapatrier des pièces jointes de police serait
            // une décision de conservation à prendre à part.
            'has_media' => filled($m->media_id),
            'media_mime' => $m->media_mime,
            'media_filename' => $m->media_filename,
            'context_wamid' => $m->context_wamid,
            'status' => $m->status,
            'delivered_at' => $m->delivered_at?->toIso8601String(),
            'read_at' => $m->read_at?->toIso8601String(),
            'error' => $m->error_message,
            'sent_by' => $sender ? trim($sender->first_name.' '.$sender->last_name) : null,
            'reaction' => $this->reactionFor($m->wamid, $reactions),
        ];
    }
}
