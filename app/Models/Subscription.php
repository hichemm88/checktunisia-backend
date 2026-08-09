<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'hotel_id', 'organization_id', 'plan_id', 'custom_price', 'is_legacy_plan', 'status', 'billing_cycle',
        'started_at', 'expires_at', 'cancelled_at', 'suspended_at',
        'suspended_reason', 'auto_renew', 'metadata', 'created_by',
        'cancellation_requested_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at'    => 'datetime',
            'expires_at'    => 'datetime',
            'cancelled_at'  => 'datetime',
            'suspended_at'  => 'datetime',
            'cancellation_requested_at' => 'datetime',
            'auto_renew'     => 'boolean',
            'is_legacy_plan' => 'boolean',
            'metadata'       => 'array',
        ];
    }

    public function hotel(): BelongsTo        { return $this->belongsTo(Hotel::class); }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class, 'organization_id'); }
    public function plan(): BelongsTo         { return $this->belongsTo(SubscriptionPlan::class, 'plan_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function events(): HasMany  { return $this->hasMany(SubscriptionEvent::class); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }
    public function planChanges(): HasMany { return $this->hasMany(SubscriptionPlanChange::class); }

    /** Résilié = renouvellement arrêté par le client ; le service court jusqu'à expires_at. */
    public function isCancellationScheduled(): bool
    {
        return $this->cancellation_requested_at !== null && !$this->auto_renew;
    }

    // ─── Périmètre commercial ────────────────────────────────────────────

    /**
     * Cet abonnement représente-t-il du revenu ?
     *
     * Décidé par l'ORGANISATION qui le détient (voir Organization::isCommercial).
     * Un abonnement historique rattaché à un établissement sans organisation
     * reste commercial : ce sont de vrais clients d'avant le modèle org.
     */
    public function isCommercial(): bool
    {
        $org = $this->organization ?? $this->hotel?->organization;

        return $org === null ? true : $org->isCommercial();
    }

    public function isInternal(): bool
    {
        return ! $this->isCommercial();
    }

    /**
     * Abonnements facturables / comptabilisables. Couvre les deux
     * rattachements : directement à l'organisation, ou via l'établissement
     * pour les abonnements historiques.
     */
    public function scopeCommercial(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(function ($q) {
            $q->whereHas('organization', fn ($o) => $o->commercial())
              ->orWhere(function ($qq) {
                  $qq->whereNull('organization_id')
                     ->where(function ($h) {
                         $h->whereHas('hotel.organization', fn ($o) => $o->commercial())
                           ->orWhereDoesntHave('hotel.organization');
                     });
              });
        });
    }

    public function isActive(): bool       { return $this->status === 'active'; }
    public function isExpired(): bool      { return $this->status === 'expired'; }
    public function isSuspended(): bool    { return $this->status === 'suspended'; }
    public function isTrial(): bool        { return $this->status === 'trial'; }
    public function isTrialExpired(): bool { return $this->status === 'trial_expired'; }

    public function getDaysRemainingAttribute(): int
    {
        if (!$this->expires_at) return 0;
        return max(0, (int) now()->diffInDays($this->expires_at, false));
    }
}
