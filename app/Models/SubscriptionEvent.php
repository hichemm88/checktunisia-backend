<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionEvent extends Model {
    public $timestamps = false;
    protected $fillable = ['subscription_id','event_type','previous_status','new_status','previous_plan_id','new_plan_id','notes','performed_by','created_at'];
    protected function casts(): array { return ['created_at'=>'datetime']; }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
    public function performer(): BelongsTo { return $this->belongsTo(User::class, 'performed_by'); }
}
