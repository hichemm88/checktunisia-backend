<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionAdminController extends Controller {
    public function index(string $hotelId): JsonResponse {
        $hotel = Hotel::findOrFail($hotelId);
        return response()->json(['data' => $hotel->subscriptions()->with('plan')->orderByDesc('created_at')->get()]);
    }
    public function store(Request $request, string $hotelId): JsonResponse {
        $hotel = Hotel::findOrFail($hotelId);
        $v = $request->validate(['plan_id'=>['required','exists:subscription_plans,id'],'billing_cycle'=>['in:monthly,yearly'],'started_at'=>['required','date'],'expires_at'=>['required','date','after:started_at'],'auto_renew'=>['boolean']]);
        $sub = DB::transaction(function() use($v, $hotel, $request) {
            $sub = Subscription::create(array_merge($v,['hotel_id'=>$hotel->id,'status'=>'active','created_by'=>$request->user()->id]));
            SubscriptionEvent::create(['subscription_id'=>$sub->id,'event_type'=>'activated','new_status'=>'active','performed_by'=>$request->user()->id,'created_at'=>now()]);
            cache()->forget("hotel_subscription_active:{$hotel->id}");
            AuditLogger::log('subscription.activated', $sub);
            return $sub;
        });
        return response()->json(['data'=>$sub->load('plan')], 201);
    }
    public function update(Request $request, string $hotelId, string $id): JsonResponse {
        $sub = Subscription::where('hotel_id',$hotelId)->findOrFail($id);
        $v = $request->validate(['status'=>['sometimes','in:active,suspended,cancelled,expired'],'expires_at'=>['sometimes','date'],'plan_id'=>['sometimes','exists:subscription_plans,id'],'suspended_reason'=>['nullable','string']]);
        $old = $sub->only(['status','plan_id']);
        $sub->update($v);
        SubscriptionEvent::create(['subscription_id'=>$sub->id,'event_type'=>'updated','previous_status'=>$old['status'],'new_status'=>$sub->status,'performed_by'=>$request->user()->id,'created_at'=>now()]);
        cache()->forget("hotel_subscription_active:{$hotelId}");
        return response()->json(['data'=>$sub->fresh()->load('plan')]);
    }
    public function invoices(string $hotelId): JsonResponse {
        $hotel = Hotel::findOrFail($hotelId);
        return response()->json(['data' => $hotel->invoices()->orderByDesc('created_at')->get()]);
    }
    public function createInvoice(Request $request, string $hotelId): JsonResponse {
        $hotel = Hotel::findOrFail($hotelId);
        $v = $request->validate(['subscription_id'=>['required','uuid'],'amount'=>['required','numeric','min:0'],'tax_amount'=>['numeric','min:0'],'due_at'=>['nullable','date'],'notes'=>['nullable','string']]);
        $v['total_amount'] = ($v['amount'] ?? 0) + ($v['tax_amount'] ?? 0);
        $v['hotel_id'] = $hotel->id;
        $v['invoice_number'] = 'INV-'.date('Y').'-'.str_pad(Invoice::whereYear('created_at',now()->year)->count()+1, 4, '0', STR_PAD_LEFT);
        $v['created_by'] = $request->user()->id;
        $invoice = Invoice::create($v);
        return response()->json(['data'=>$invoice], 201);
    }
    public function updateInvoice(Request $request, string $hotelId, string $id): JsonResponse {
        $invoice = Invoice::where('hotel_id',$hotelId)->findOrFail($id);
        $v = $request->validate(['status'=>['sometimes','in:draft,sent,paid,overdue,void'],'paid_at'=>['nullable','date'],'payment_method'=>['nullable','string'],'payment_reference'=>['nullable','string']]);
        $invoice->update($v);
        AuditLogger::log('invoice.updated', $invoice);
        return response()->json(['data'=>$invoice->fresh()]);
    }
}
