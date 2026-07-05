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
                'id'           => $l->id,
                'action'       => $l->action,
                'subject_type' => $l->subject_type ? class_basename($l->subject_type) : null,
                'subject_id'   => $l->subject_id,
                'actor'        => $l->actor ? [
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
}
