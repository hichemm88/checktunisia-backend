<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ApiPartner;
use App\Models\EstablishmentPartnerLink;
use App\Models\FicheSession;
use App\Models\PartnerWebhookDelivery;
use App\Services\Audit\AuditLogger;
use App\Services\PartnerApi\ApiKeyService;
use App\Services\PartnerApi\EstablishmentLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Partenaires de l'API publique v1 (Diar, etc.) — vue platform_admin. */
class PartnerAdminController extends Controller
{
    public function __construct(
        private ApiKeyService $keys,
        private EstablishmentLinkService $links,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ApiPartner::withCount(['establishmentLinks', 'ficheSessions']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $query->where('name', 'ilike', "%{$request->search}%");
        }

        $partners = $query->orderBy('name')->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $partners->map(fn (ApiPartner $p) => $this->summarize($p)),
            'meta' => ['total' => $partners->total(), 'current_page' => $partners->currentPage(), 'per_page' => $partners->perPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'allowed_widget_origins' => ['nullable', 'array'],
            'allowed_widget_origins.*' => ['string', 'max:255'],
        ]);

        $partner = ApiPartner::create([
            'name' => $v['name'],
            'status' => 'active',
            'allowed_widget_origins' => $v['allowed_widget_origins'] ?? [],
        ]);

        AuditLogger::log('partner.created', $partner, [], $partner->toArray());

        return response()->json(['data' => $this->detail($partner)], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->detail(ApiPartner::findOrFail($id))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $partner = ApiPartner::findOrFail($id);

        $v = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,suspended'],
            'allowed_widget_origins' => ['sometimes', 'array'],
            'allowed_widget_origins.*' => ['string', 'max:255'],
        ]);

        $old = $partner->toArray();
        $partner->update($v);
        AuditLogger::log('partner.updated', $partner, $old, $partner->fresh()->toArray());

        return response()->json(['data' => $this->detail($partner->fresh())]);
    }

    /** Émet une nouvelle clé (live|test) — le clair n'est renvoyé qu'ici, une seule fois. */
    public function issueKey(Request $request, string $id): JsonResponse
    {
        $partner = ApiPartner::findOrFail($id);

        $v = $request->validate(['mode' => ['required', 'in:live,test']]);

        ['key' => $key, 'plaintext' => $plaintext] = $this->keys->issue($partner, $v['mode']);

        AuditLogger::log('partner.key_issued', $partner, [], ['mode' => $v['mode'], 'key_id' => $key->id]);

        return response()->json(['data' => [
            'id' => $key->id,
            'mode' => $key->mode,
            'prefix' => $key->prefix,
            'plaintext' => $plaintext,
        ]], 201);
    }

    public function revokeKey(string $id, string $keyId): JsonResponse
    {
        $key = ApiKey::where('partner_id', $id)->findOrFail($keyId);
        $this->keys->revoke($key);

        AuditLogger::log('partner.key_revoked', $key->partner, [], ['key_id' => $key->id]);

        return response()->json(['data' => ['id' => $key->id, 'revoked_at' => $key->fresh()->revoked_at]]);
    }

    public function links(string $id): JsonResponse
    {
        $links = EstablishmentPartnerLink::where('partner_id', $id)->with('hotel')->orderByDesc('linked_at')->get();

        return response()->json(['data' => $links->map(fn (EstablishmentPartnerLink $l) => [
            'id' => $l->id,
            'hotel_id' => $l->hotel_id,
            'hotel_name' => $l->hotel->name,
            'linked_at' => $l->linked_at,
            'revoked_at' => $l->revoked_at,
        ])]);
    }

    public function revokeLink(Request $request, string $id, string $linkId): JsonResponse
    {
        $link = EstablishmentPartnerLink::where('partner_id', $id)->findOrFail($linkId);
        $this->links->revoke($link, $request->user());

        AuditLogger::log('partner.link_revoked', $link, [], ['hotel_id' => $link->hotel_id]);

        return response()->json(['data' => ['id' => $link->id, 'revoked_at' => $link->fresh()->revoked_at]]);
    }

    public function metrics(string $id): JsonResponse
    {
        $sessionsCreated = FicheSession::where('partner_id', $id)->count();
        $submitted = FicheSession::where('partner_id', $id)->where('status', FicheSession::STATUS_SUBMITTED)->count();
        $errors = PartnerWebhookDelivery::whereHas('endpoint', fn ($q) => $q->where('partner_id', $id))
            ->where('status', PartnerWebhookDelivery::STATUS_FAILED)
            ->count();

        return response()->json(['data' => [
            'sessions_created' => $sessionsCreated,
            'fiches_submitted' => $submitted,
            'completion_rate' => $sessionsCreated > 0 ? round($submitted / $sessionsCreated, 4) : null,
            'webhook_errors' => $errors,
        ]]);
    }

    private function summarize(ApiPartner $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'status' => $p->status,
            'establishment_links_count' => $p->establishment_links_count,
            'fiche_sessions_count' => $p->fiche_sessions_count,
            'created_at' => $p->created_at,
        ];
    }

    private function detail(ApiPartner $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'status' => $p->status,
            'allowed_widget_origins' => $p->allowed_widget_origins,
            'keys' => $p->keys()->get()->map(fn (ApiKey $k) => [
                'id' => $k->id,
                'mode' => $k->mode,
                'prefix' => $k->prefix,
                'last_used_at' => $k->last_used_at,
                'revoked_at' => $k->revoked_at,
            ]),
            'created_at' => $p->created_at,
        ];
    }
}
