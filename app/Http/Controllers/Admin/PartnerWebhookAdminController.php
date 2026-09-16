<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartnerWebhookDelivery;
use App\Models\PartnerWebhookEndpoint;
use App\Services\Audit\AuditLogger;
use App\Services\PartnerApi\PartnerWebhookOutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Endpoints webhook + journal de livraison — vue platform_admin. */
class PartnerWebhookAdminController extends Controller
{
    public function __construct(private PartnerWebhookOutboxService $outbox) {}

    public function index(string $partnerId): JsonResponse
    {
        $endpoints = PartnerWebhookEndpoint::where('partner_id', $partnerId)->get();

        return response()->json(['data' => $endpoints->map(fn (PartnerWebhookEndpoint $e) => $this->summarize($e))]);
    }

    public function store(Request $request, string $partnerId): JsonResponse
    {
        $v = $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', 'in:fiche.submitted,fiche.failed,session.expired'],
        ]);

        $endpoint = PartnerWebhookEndpoint::create([
            'partner_id' => $partnerId,
            'url' => $v['url'],
            'secret' => bin2hex(random_bytes(32)),
            'events' => $v['events'] ?? ['fiche.submitted', 'fiche.failed', 'session.expired'],
            'active' => true,
        ]);

        AuditLogger::log('partner.webhook_created', $endpoint, [], ['url' => $endpoint->url]);

        // Le secret n'est renvoyé qu'à la création — même logique qu'une clé API.
        return response()->json(['data' => $this->summarize($endpoint) + ['secret' => $endpoint->secret]], 201);
    }

    public function update(Request $request, string $partnerId, string $endpointId): JsonResponse
    {
        $endpoint = PartnerWebhookEndpoint::where('partner_id', $partnerId)->findOrFail($endpointId);

        $v = $request->validate([
            'url' => ['sometimes', 'url', 'max:500'],
            'events' => ['sometimes', 'array'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $endpoint->update($v + ($v['active'] ?? null ? ['consecutive_failures' => 0] : []));

        return response()->json(['data' => $this->summarize($endpoint->fresh())]);
    }

    public function destroy(string $partnerId, string $endpointId): JsonResponse
    {
        PartnerWebhookEndpoint::where('partner_id', $partnerId)->findOrFail($endpointId)->delete();

        return response()->json(null, 204);
    }

    public function deliveries(Request $request, string $partnerId, string $endpointId): JsonResponse
    {
        $endpoint = PartnerWebhookEndpoint::where('partner_id', $partnerId)->findOrFail($endpointId);

        $deliveries = $endpoint->deliveries()->orderByDesc('queued_at')->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $deliveries->map(fn (PartnerWebhookDelivery $d) => [
                'id' => $d->id,
                'event_type' => $d->event_type,
                'status' => $d->status,
                'attempts' => $d->attempts,
                'last_response_status' => $d->last_response_status,
                'last_error' => $d->last_error,
                'queued_at' => $d->queued_at,
                'sent_at' => $d->sent_at,
                'payload' => $d->payload,
            ]),
            'meta' => ['total' => $deliveries->total(), 'current_page' => $deliveries->currentPage()],
        ]);
    }

    public function redrive(string $partnerId, string $endpointId, string $deliveryId): JsonResponse
    {
        $delivery = PartnerWebhookDelivery::where('endpoint_id', $endpointId)
            ->whereHas('endpoint', fn ($q) => $q->where('partner_id', $partnerId))
            ->findOrFail($deliveryId);

        $this->outbox->redrive($delivery);

        return response()->json(['data' => ['id' => $delivery->id, 'status' => $delivery->fresh()->status]]);
    }

    private function summarize(PartnerWebhookEndpoint $e): array
    {
        return [
            'id' => $e->id,
            'url' => $e->url,
            'events' => $e->events,
            'active' => $e->active,
            'consecutive_failures' => $e->consecutive_failures,
            'created_at' => $e->created_at,
        ];
    }
}
