<?php

namespace App\Services\PartnerApi;

use App\Models\CheckIn;
use App\Models\FicheSession;
use App\Models\WhatsappSendLog;
use Illuminate\Support\Facades\Log;

/**
 * v1.2 — webhooks `fiche.delivered` et `fiche.delivery_failed`.
 *
 * Appelé par WhatsappSendLog (crochet `saved`) chaque fois qu'un envoi atteint
 * une issue définitive (sent, failed, cancelled) : c'est le seul endroit par
 * lequel passent TOUS les chemins qui fixent l'issue d'un envoi (worker Web,
 * boucle Cloud API, accusé de livraison Meta, annulations admin, fiche bloquée
 * à l'enfilage). Recalcule alors le statut agrégé de la fiche
 * (FicheDeliveryStatus) et n'émet que si celui-ci est lui-même définitif.
 *
 * Garde-fous :
 *  - fiches créées via l'API uniquement (check-in rattaché à une FicheSession) ;
 *  - mode live uniquement (session is_test=false ET fiche sans test_mode) ;
 *  - idempotent : clé = fiche_id, donc un seul événement par fiche, par issue
 *    et par endpoint (contrainte unique de partner_webhook_deliveries) ;
 *  - jamais bloquant : un incident ici ne doit jamais gêner la transmission.
 */
class FicheDeliveryWebhookNotifier
{
    public const EVENT_DELIVERED = 'fiche.delivered';

    public const EVENT_DELIVERY_FAILED = 'fiche.delivery_failed';

    public function __construct(
        private FicheDeliveryStatus $status,
        private PartnerWebhookOutboxService $webhooks,
    ) {}

    public function onSendLogSettled(WhatsappSendLog $log): void
    {
        if (! $log->check_in_id || $log->is_test) {
            return;
        }

        if (! in_array($log->status, [WhatsappSendLog::STATUS_SENT, WhatsappSendLog::STATUS_FAILED, WhatsappSendLog::STATUS_CANCELLED], true)) {
            return;
        }

        try {
            $session = FicheSession::query()
                ->where('check_in_id', $log->check_in_id)
                ->where('is_test', false)
                ->orderByDesc('created_at')
                ->first();

            if (! $session) {
                return;
            }

            $checkIn = CheckIn::with('hotel.organization')->find($log->check_in_id);

            if (! $checkIn || (bool) ($checkIn->metadata['test_mode'] ?? false)) {
                return;
            }

            $deliveryStatus = $this->status->forCheckIn($checkIn);

            $event = match ($deliveryStatus) {
                FicheDeliveryStatus::SENT => self::EVENT_DELIVERED,
                FicheDeliveryStatus::FAILED => self::EVENT_DELIVERY_FAILED,
                default => null,
            };

            if ($event === null) {
                return;
            }

            $payload = [
                'fiche_id' => $checkIn->id,
                'session_id' => $session->id,
                'booking_ref' => $checkIn->booking_reference,
                'establishment_id' => $checkIn->hotel_id,
                'delivery_status' => $deliveryStatus,
                'occurred_at' => now()->toIso8601String(),
            ];

            if ($event === self::EVENT_DELIVERY_FAILED) {
                // Motif technique générique : jamais le texte d'erreur brut du
                // canal (il peut citer un numéro de destinataire).
                $payload['reason'] = $log->status === WhatsappSendLog::STATUS_CANCELLED
                    ? 'transmission_cancelled'
                    : 'transmission_failed';
            }

            $this->webhooks->enqueueForSession($session, $event, $payload, $checkIn->id);
        } catch (\Throwable $e) {
            Log::warning('[partner-webhooks] statut d\'envoi non notifié pour le check-in '.$log->check_in_id.' : '.get_class($e));
        }
    }
}
