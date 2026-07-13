<?php

namespace App\Http\Controllers\Whatsapp;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSendLog;
use App\Models\WhatsappSessionState;
use App\Services\Whatsapp\WhatsappOutboxService;
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
     * État de santé du relais — sans aucun secret (ni le destinataire).
     * Sert aussi la route publique GET /health/whatsapp.
     */
    public function health(): JsonResponse
    {
        $state = WhatsappSessionState::current();
        $counts = WhatsappSendLog::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return response()->json(['data' => [
            'enabled' => $this->outbox->enabled(),
            'session' => $state->status,
            'reason' => $state->reason,
            'paused' => $state->paused,
            'last_ready_at' => $state->last_ready_at,
            'heartbeat_at' => $state->heartbeat_at,
            'queue' => [
                'pending' => (int) ($counts[WhatsappSendLog::STATUS_PENDING] ?? 0),
                'sent' => (int) ($counts[WhatsappSendLog::STATUS_SENT] ?? 0),
                'failed' => (int) ($counts[WhatsappSendLog::STATUS_FAILED] ?? 0),
                'cancelled' => (int) ($counts[WhatsappSendLog::STATUS_CANCELLED] ?? 0),
            ],
        ]]);
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

        return response()->json([
            'data' => $logs->map(fn (WhatsappSendLog $l) => [
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
                'queued_at' => $l->queued_at,
                'sent_at' => $l->sent_at,
                'next_attempt_at' => $l->next_attempt_at,
            ]),
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

    /** POST admin/whatsapp/test — enfile une fiche factice [TEST]. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate(['property_name' => 'nullable|string|max:120']);

        if (! $this->outbox->enabled()) {
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
        $state->forceFill(['paused' => false])->save();

        return response()->json(['data' => ['paused' => false]]);
    }
}
