<?php
namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Activity feed for hotel_admin — everything staff (mainly receptionists) do across the org's properties. */
class ActivityLogController extends Controller
{
    private function manageableHotelIds(): Collection
    {
        $hotel = app('tenant');
        return $hotel->organization_id
            ? Hotel::where('organization_id', $hotel->organization_id)->pluck('id')
            : collect([$hotel->id]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::with('actor')
            ->whereIn('hotel_id', $this->manageableHotelIds());

        if ($request->filled('role')) {
            $query->where('actor_role', $request->string('role'));
        }
        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->string('actor_id'));
        }
        if ($request->filled('action')) {
            $query->where('action', 'like', $request->string('action').'%');
        }

        $logs = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 30));

        return response()->json([
            'data' => collect($logs->items())->map(fn(AuditLog $l) => [
                'id'            => $l->id,
                'action'        => $l->action,
                'subject_type'  => $l->subject_type ? class_basename($l->subject_type) : null,
                'subject_id'    => $l->subject_id,
                'subject_label' => $this->subjectLabel($l),
                'actor'         => $l->actor ? [
                    'id'   => $l->actor->id,
                    'name' => trim($l->actor->first_name.' '.$l->actor->last_name),
                    'role' => $l->actor_role,
                ] : null,
                'created_at'   => $l->created_at,
            ]),
            'meta' => [
                'total'        => $logs->total(),
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * Best-effort human-readable pointer to what the action was about — e.g. a
     * check-in reference, a guest's name, a room number — pulled from the
     * old/new value snapshots already captured at log time (never a fresh DB
     * lookup, so this stays accurate even after the subject is deleted).
     */
    private function subjectLabel(AuditLog $l): ?string
    {
        $new = $l->new_values ?? [];
        $old = $l->old_values ?? [];
        $pick = fn(string $key) => $new[$key] ?? $old[$key] ?? null;

        return match (class_basename($l->subject_type ?? '')) {
            'CheckIn' => $pick('reference'),
            'Guest'   => trim(($pick('first_name') ?? '').' '.($pick('last_name') ?? '')) ?: null,
            'Room'    => $pick('number') !== null ? "N° {$pick('number')}" : null,
            'User'    => $pick('email') ?? trim(($pick('first_name') ?? '').' '.($pick('last_name') ?? '')) ?: null,
            default   => null,
        };
    }
}
