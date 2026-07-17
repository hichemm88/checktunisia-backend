<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model {
    protected $fillable = ['name','slug','scope','min_rooms','max_rooms','price_monthly','price_yearly','currency','features','marketing','is_active','sort_order','included_properties','extra_property_price'];
    protected $appends = ['effective_price_yearly'];
    protected function casts(): array { return ['features'=>'array','marketing'=>'array','is_active'=>'boolean','price_monthly'=>'decimal:3','price_yearly'=>'decimal:3','included_properties'=>'integer','extra_property_price'=>'decimal:3']; }
    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class, 'plan_id'); }
    public function isUnlimited(): bool { return is_null($this->max_rooms); }

    /** Yearly price with the "one month free" offer: explicit price_yearly if set, else 11 × monthly. */
    public function getEffectivePriceYearlyAttribute(): string {
        return $this->price_yearly ?? number_format((float) $this->price_monthly * 11, 3, '.', '');
    }
}
