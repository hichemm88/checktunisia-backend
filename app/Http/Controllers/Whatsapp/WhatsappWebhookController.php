<?php

namespace App\Http\Controllers\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSendLog;
use App\Services\Whatsapp\WhatsappCloudErrors;
use App\Services\Whatsapp\WhatsappConversationService;
use App\Services\Whatsapp\WhatsappCostRecorder;
use App\Services\Whatsapp\WhatsappOutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook WhatsApp Cloud API (Meta).
 *
 * C'est le seul endroit qui apprend ce qu'est DEVENU un message. L'appel
 * d'envoi ne dit que « Meta a accepté » ; la suite — remis, lu, ou refusé, et
 * pour quelle raison — n'arrive que par ici.
 *
 * Deux exigences dictent la forme de ce contrôleur :
 *
 *  1. AUTHENTICITÉ. L'URL est publique. Sans vérification de signature,
 *     n'importe qui pourrait déclarer une fiche « remise » — c'est-à-dire
 *     falsifier la preuve de transmission d'un document légal. Le POST est
 *     donc refusé sans `X-Hub-Signature-256` valide, et refusé aussi quand
 *     `WHATSAPP_APP_SECRET` n'est pas configuré : mieux vaut ne rien traiter
 *     que traiter n'importe quoi.
 *
 *  2. RAPIDITÉ. Meta rejoue toute livraison non acquittée en quelques
 *     secondes, puis finit par désactiver le webhook. On répond donc 200 même
 *     quand un événement est inexploitable — un rejeu ne le rendrait pas plus
 *     exploitable — et on journalise plutôt que d'échouer.
 */
class WhatsappWebhookController extends Controller
{
    public function __construct(
        private WhatsappOutboxService $outbox,
        private WhatsappCostRecorder $costs,
        private WhatsappConversationService $conversations,
    ) {}

    /**
     * GET — défi de vérification de Meta, joué une fois à l'enregistrement de
     * l'URL puis à chaque modification de l'abonnement.
     *
     * La réponse doit être le `hub.challenge` BRUT, en texte : encapsulé en
     * JSON, Meta refuse l'URL.
     */
    public function verify(Request $request): Response
    {
        $expected = (string) config('whatsapp.cloud.webhook_verify_token');
        $provided = (string) $request->query('hub_verify_token', '');

        $valid = $request->query('hub_mode') === 'subscribe'
            && $expected !== ''
            && hash_equals($expected, $provided);

        if (! $valid) {
            Log::warning('[whatsapp-webhook] défi de vérification refusé', [
                'mode' => $request->query('hub_mode'),
                'token_configured' => $expected !== '',
            ]);

            return response('Forbidden', 403);
        }

        return response((string) $request->query('hub_challenge', ''), 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * POST — accusés de réception et messages entrants.
     *
     * Le traitement est synchrone mais borné : Meta groupe au plus quelques
     * dizaines d'événements par livraison, chacun se résolvant en une lecture
     * indexée et une écriture. Passer par la file ferait dépendre l'accusé de
     * réception du drainage — lequel est justement ce qu'on surveille.
     */
    public function handle(Request $request): JsonResponse
    {
        if (! $this->signatureIsValid($request)) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'INVALID_SIGNATURE', 'message' => 'Invalid signature.', 'field' => null]],
            ], 401);
        }

        try {
            foreach ((array) $request->input('entry', []) as $entry) {
                foreach ((array) ($entry['changes'] ?? []) as $change) {
                    $value = $change['value'] ?? [];

                    foreach ((array) ($value['statuses'] ?? []) as $status) {
                        $this->applyStatus($status);
                    }

                    foreach ((array) ($value['messages'] ?? []) as $message) {
                        $this->acknowledgeInbound($message, (array) ($value['contacts'] ?? []));
                    }
                }
            }
        } catch (\Throwable $e) {
            // Un événement malformé ne doit pas provoquer de rejeu en boucle :
            // le rejeu livrerait le même événement malformé.
            Log::error('[whatsapp-webhook] traitement interrompu : '.$e->getMessage());
        }

        return response()->json(['data' => ['received' => true], 'errors' => []]);
    }

    // ── Interne ──────────────────────────────────────────────────────────────

    /**
     * HMAC-SHA256 du corps BRUT avec le secret de l'application.
     *
     * Le corps brut, et surtout pas le JSON ré-encodé : le moindre écart
     * d'espacement ou d'ordre de clés invaliderait une signature pourtant
     * légitime. `$request->getContent()` renvoie bien le corps original —
     * Laravel ne le consomme pas.
     */
    private function signatureIsValid(Request $request): bool
    {
        $secret = (string) config('whatsapp.cloud.app_secret');

        if ($secret === '') {
            Log::error('[whatsapp-webhook] WHATSAPP_APP_SECRET absent — livraison refusée.');

            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        // Comparaison en temps constant : une comparaison naïve laisse fuir la
        // signature attendue, octet par octet, par mesure du temps de réponse.
        return hash_equals($expected, substr($header, 7));
    }

    /**
     * Un accusé de réception : sent / delivered / read / failed.
     *
     * @param  array<string,mixed>  $status
     */
    private function applyStatus(array $status): void
    {
        $wamid = $status['id'] ?? null;
        $state = $status['status'] ?? null;

        if (! is_string($wamid) || ! is_string($state)) {
            return;
        }

        // Meta empile les erreurs ; la première porte la cause. Lue avant le
        // routage : les deux destinations possibles (fiche, réponse admin) en
        // ont besoin, et la relire deux fois ferait diverger les deux lectures.
        $error = ($status['errors'][0] ?? []);
        $rawCode = $error['code'] ?? null;
        $code = is_numeric($rawCode) ? (int) $rawCode : null;
        $title = $error['title'] ?? ($error['message'] ?? null);

        $job = WhatsappSendLog::where('message_id_whatsapp', $wamid)->first();

        if ($job === null) {
            /*
             * Pas de ligne d'outbox — et ce n'est PAS forcément un message
             * étranger : les codes de connexion partent hors file et n'en ont
             * jamais eu, et les réponses envoyées depuis la boîte de réception
             * vivent dans le fil, pas dans la file des fiches.
             */
            $isReply = $this->conversations->applyOutboundStatus(
                $wamid,
                $state,
                $code !== null ? (string) $code : null,
                WhatsappCloudErrors::describe($code, is_string($title) ? $title : null),
            );

            /*
             * Facturation. C'est le seul endroit où la livraison des codes de
             * connexion — et des réponses — est observable, donc le seul où
             * leur coût peut être compté. Le registre tranche : s'il connaît ce
             * wamid, on compte ; sinon le message vient d'ailleurs et on
             * n'invente rien.
             */
            if (in_array($state, [WhatsappSendLog::DELIVERY_DELIVERED, WhatsappSendLog::DELIVERY_READ], true)) {
                $this->costs->recordDelivered($wamid);
            }

            if (! $isReply) {
                // Cas normal et non alarmant : messages envoyés par un autre
                // système sur le même numéro, ou lignes purgées du journal.
                Log::info('[whatsapp-webhook] statut sans correspondance', ['status' => $state]);
            }

            return;
        }

        if ($state !== WhatsappSendLog::DELIVERY_FAILED) {
            $this->outbox->recordDeliveryUpdate($job, $state);

            return;
        }

        $this->outbox->recordDeliveryUpdate(
            $job,
            WhatsappSendLog::DELIVERY_FAILED,
            $code !== null ? (string) $code : null,
            WhatsappCloudErrors::describe($code, is_string($title) ? $title : null),
            // Un échec de LIVRAISON se rejuge comme un échec d'envoi : une
            // limitation de débit se retente, un numéro sans compte WhatsApp
            // non. Le 200 initial ne dit rien de cette distinction.
            WhatsappCloudErrors::isRetryable($code, 200),
        );
    }

    /**
     * Message entrant — la réponse d'une autorité.
     *
     * Elle est désormais CONSERVÉE, dans le fil du numéro, et lisible depuis la
     * boîte de réception de l'administration. C'est la seule information de
     * retour que le canal produise : la jeter revenait à faire écrire les
     * agents dans le vide.
     *
     * Le JOURNAL, lui, ne change pas : type, numéro tronqué, wamid. Le contenu
     * va en base (chiffré), jamais dans les logs — un journal se copie, se
     * réexpédie et s'archive ailleurs, et rien de tout cela n'est vrai d'une
     * colonne chiffrée.
     *
     * @param  array<string,mixed>  $message
     * @param  array<int,array<string,mixed>>  $contacts
     */
    private function acknowledgeInbound(array $message, array $contacts = []): void
    {
        $this->conversations->recordInbound($message, $contacts);

        Log::info('[whatsapp-webhook] message entrant', [
            'type' => $message['type'] ?? null,
            'from' => $this->maskNumber($message['from'] ?? null),
            'wamid' => $message['id'] ?? null,
        ]);
    }

    private function maskNumber(mixed $number): ?string
    {
        if (! is_string($number) || $number === '') {
            return null;
        }

        return strlen($number) <= 6
            ? str_repeat('•', strlen($number))
            : substr($number, 0, 3).'…'.substr($number, -3);
    }
}
