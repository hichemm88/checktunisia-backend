<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model {
    protected $fillable = ['name','slug','scope','min_rooms','max_rooms','price_monthly','price_yearly','currency','features','is_active','sort_order'];
    protected function casts(): array { return ['features'=>'array','is_active'=>'boolean','price_monthly'=>'decimal:3','price_yearly'=>'decimal:3']; }
    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class, 'plan_id'); }
    public function isUnlimited(): bool { return is_null($this->max_rooms); }
}
