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
        return response()->json(['data'=>$logs->items(),'meta'=>['total'=>$logs->total(),'current_page'=>$logs->currentPage()]]);
    }
    public function show(int $id): JsonResponse {
        return response()->json(['data' => AuditLog::with(['actor','hotel'])->findOrFail($id)]);
    }
    public function searchLogs(Request $request): JsonResponse {
        $query = AuthoritySearchLog::with(['user','organization']);
        if ($request->filled('user_id')) $query->where('user_id',$request->user_id);
        if ($request->filled('from'))    $query->where('created_at','>=',$request->from);
        if ($request->filled('to'))      $query->where('created_at','<=',$request->to);
        $logs = $query->orderByDesc('created_at')->paginate($request->integer('per_page',50));
        return response()->json(['data'=>$logs->items(),'meta'=>['total'=>$logs->total()]]);
    }
}
