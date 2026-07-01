<?php
namespace App\Http\Controllers\Hotel;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SubscriptionController extends Controller {
    public function current(): JsonResponse {
        $hotel = app('tenant');
        $sub = $hotel->activeSubscription()->with('plan')->first();
        if (!$sub) return response()->json(['data' => ['status' => 'none']]);
        return response()->json(['data' => ['id'=>$sub->id,'plan'=>$sub->plan,'status'=>$sub->status,'billing_cycle'=>$sub->billing_cycle,'started_at'=>$sub->started_at,'expires_at'=>$sub->expires_at,'auto_renew'=>$sub->auto_renew,'days_remaining'=>$sub->days_remaining]]);
    }
}
