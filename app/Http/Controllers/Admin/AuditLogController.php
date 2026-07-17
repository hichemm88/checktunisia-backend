<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AuthoritySearchLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller {
    public function index(Request $request): JsonResponse {
        $query = AuditLog::with(['actor','hotel']);
        if ($request->filled('actor_id')) $query->where('actor_id',$request->actor_id);
        if ($request->filled('hotel_id'))  $query->where('hotel_id',$request->hotel_id);
        if ($request->filled('action'))    $query->where('action',$request->action);
        if ($request->filled('from'))      $query->where('created_at','>=',$request->from);
        if ($request->filled('to'))        $query->where('created_at','<=',$request->to);
        $logs = $query->orderByDesc('created_at')->paginate($request->integer('per_page',50));
        return response()->json(['data'=>$logs->items(),'meta'=>['total'=>$logs->total(),'current_page'=>$logs->currentPage(),'per_page'=>$logs->perPage()]]);
    }
    public function show(int $id): JsonResponse {
        return response()->json(['data' => AuditLog::with(['actor','hotel'])->findOrFail($id)]);
    }

    /** Valeurs distinctes du champ action — alimente le filtre du journal. */
    public function actions(): JsonResponse {
        return response()->json(['data' => AuditLog::query()->distinct()->orderBy('action')->pluck('action')]);
    }

    /** Export CSV du journal, mêmes filtres que l'index (chantier B3). */
    public function export(Request $request) {
        $query = AuditLog::with(['actor','hotel']);
        if ($request->filled('actor_id')) $query->where('actor_id',$request->actor_id);
        if ($request->filled('hotel_id'))  $query->where('hotel_id',$request->hotel_id);
        if ($request->filled('action'))    $query->where('action',$request->action);
        if ($request->filled('from'))      $query->where('created_at','>=',$request->from);
        if ($request->filled('to'))        $query->where('created_at','<=',$request->to);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 pour Excel.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Acteur', 'Email', 'Action', 'Entité', 'Entité ID', 'Établissement', 'IP'], ';');
            $query->orderByDesc('created_at')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $log) {
                    fputcsv($out, [
                        $log->created_at?->format('Y-m-d H:i:s'),
                        trim(($log->actor?->first_name ?? '').' '.($log->actor?->last_name ?? '')),
                        $log->actor?->email,
                        $log->action,
                        $log->subject_type,
                        $log->subject_id,
                        $log->hotel?->name,
                        $log->ip_address,
                    ], ';');
                }
            });
            fclose($out);
        }, 'journal-activite-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
    public function searchLogs(Request $request): JsonResponse {
        $query = AuthoritySearchLog::with(['user','organization']);
        if ($request->filled('user_id')) $query->where('user_id',$request->user_id);
        if ($request->filled('from'))    $query->where('created_at','>=',$request->from);
        if ($request->filled('to'))      $query->where('created_at','<=',$request->to);
        $logs = $query->orderByDesc('created_at')->paginate($request->integer('per_page',50));
        return response()->json(['data'=>$logs->items(),'meta'=>['total'=>$logs->total(),'current_page'=>$logs->currentPage(),'per_page'=>$logs->perPage()]]);
    }
}
