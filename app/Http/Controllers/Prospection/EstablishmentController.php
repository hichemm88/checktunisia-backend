<?php

namespace App\Http\Controllers\Prospection;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prospection\StoreEstablishmentRequest;
use App\Http\Requests\Prospection\UpdateEstablishmentRequest;
use App\Models\Prospection\Establishment;
use App\Services\Prospection\EstablishmentStatusUpdater;
use App\Support\Prospection\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EstablishmentController extends Controller
{
    /**
     * Pipeline filtrable (§ Écran 2) : statut, zone, priorité, recherche par
     * nom, archivés. Sans filtre `archived`, les archivés sont exclus par
     * défaut — un prospect archivé ne doit pas revenir hanter le pipeline
     * actif d'une recherche non filtrée.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Establishment::query();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('zone')) {
            $query->where('zone', $request->string('zone'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }
        if ($request->filled('q')) {
            $query->where('name', 'ilike', '%'.$request->string('q').'%');
        }
        $query->where('archived', $request->boolean('archived', false));

        $establishments = $query->orderBy('name')->get();

        return response()->json([
            'data' => $establishments->map(fn (Establishment $e) => $this->present($e)),
        ]);
    }

    /**
     * Écran 1 "Aujourd'hui" : relances dues ou en retard (retard d'abord),
     * puis démos planifiées du jour.
     */
    public function today(): JsonResponse
    {
        $now = now();
        $endOfDay = $now->copy()->endOfDay();

        $dueOrLate = Establishment::query()
            ->where('archived', false)
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<=', $endOfDay)
            ->where('status', '!=', 'demo_planifiee')
            ->orderBy('next_action_at')
            ->get();

        $demosToday = Establishment::query()
            ->where('archived', false)
            ->where('status', 'demo_planifiee')
            ->whereNotNull('next_action_at')
            ->whereBetween('next_action_at', [$now->copy()->startOfDay(), $endOfDay])
            ->orderBy('next_action_at')
            ->get();

        return response()->json([
            'data' => [
                'relances' => $dueOrLate->map(fn (Establishment $e) => $this->present($e, withOverdue: true)),
                'demos' => $demosToday->map(fn (Establishment $e) => $this->present($e)),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $establishment = Establishment::findOrFail($id);

        return response()->json(['data' => $this->present($establishment, detailed: true)]);
    }

    public function store(StoreEstablishmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['whatsapp_phone'] = $this->normalizedPhoneOrFail($request->input('whatsapp_phone'));
        $data['created_by'] = $request->user()->id;

        $establishment = Establishment::create($data);

        return response()->json(['data' => $this->present($establishment, detailed: true)], 201);
    }

    public function update(UpdateEstablishmentRequest $request, string $id): JsonResponse
    {
        $establishment = Establishment::findOrFail($id);
        $data = $request->validated();

        if (array_key_exists('whatsapp_phone', $data)) {
            $data['whatsapp_phone'] = $this->normalizedPhoneOrFail($request->input('whatsapp_phone'));
        }

        $newStatus = $request->input('status');
        $nextActionAt = $request->input('next_action_at');
        $nextActionAtSubmitted = array_key_exists('next_action_at', $data);

        DB::connection('prospection')->transaction(function () use ($establishment, $data, $newStatus, $nextActionAt, $nextActionAtSubmitted, $request) {
            $establishment->fill($data);
            $establishment->save();

            if ($newStatus && $newStatus !== $establishment->getOriginal('status')) {
                EstablishmentStatusUpdater::apply($establishment, $newStatus, $nextActionAt, $request->user()->id);
            }

            // Une démo reportée doit être re-rappelée (§ Notifications push,
            // SendDemoRemindersCommand) : sans ce reset, une démo déplacée à
            // plus tard ne recevrait plus jamais son rappel, l'ancienne date
            // ayant déjà marqué demo_reminder_sent_at.
            if ($nextActionAtSubmitted && $establishment->status === 'demo_planifiee' && $establishment->demo_reminder_sent_at) {
                $establishment->forceFill(['demo_reminder_sent_at' => null])->save();
            }
        });

        return response()->json(['data' => $this->present($establishment->fresh(), detailed: true)]);
    }

    /**
     * Suppression définitive (admin uniquement — § Garde-fous "RGPD-like").
     * Les actions liées partent avec (cascadeOnDelete), le journal
     * append-only n'a pas vocation à survivre au prospect qu'il documente.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if (!$request->user()?->isAdmin()) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'PERMISSION_DENIED', 'message' => 'Suppression réservée aux administrateurs.', 'field' => null]],
            ], 403);
        }

        Establishment::findOrFail($id)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function normalizedPhoneOrFail(?string $raw): ?string
    {
        if (!$raw) {
            return null;
        }

        $normalized = PhoneNumber::toTunisianE164($raw);

        if (!$normalized) {
            throw ValidationException::withMessages([
                'whatsapp_phone' => ["Numéro WhatsApp invalide : « {$raw} » n'est pas un numéro tunisien reconnaissable."],
            ]);
        }

        return $normalized;
    }

    private function present(Establishment $e, bool $detailed = false, bool $withOverdue = false): array
    {
        $payload = [
            'id' => $e->id,
            'name' => $e->name,
            'zone' => $e->zone,
            'locality' => $e->locality,
            'size' => $e->size,
            'segment' => $e->segment,
            'priority' => $e->priority,
            'status' => $e->status,
            'whatsapp_phone' => $e->whatsapp_phone,
            'next_action_at' => $e->next_action_at,
            'out_of_scope' => $e->out_of_scope,
            'archived' => $e->archived,
        ];

        if ($withOverdue) {
            $payload['is_overdue'] = $e->next_action_at !== null && $e->next_action_at->isBefore(now()->startOfDay());
        }

        if ($detailed) {
            $payload += [
                'address' => $e->address,
                'decision_maker_name' => $e->decision_maker_name,
                'decision_maker_role' => $e->decision_maker_role,
                'origin_channel' => $e->origin_channel,
                'qualification_notes' => $e->qualification_notes,
                'target_plan' => $e->target_plan,
                'created_at' => $e->created_at,
                'updated_at' => $e->updated_at,
            ];
        }

        return $payload;
    }
}
