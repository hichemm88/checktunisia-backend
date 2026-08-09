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

    // ─── Période de grâce ────────────────────────────────────────────────
    //
    // Le recouvrement annonce au client : facture à l'échéance, relances à
    // J+3 / J+7 / J+14, suspension à J+21. Le service doit donc rester
    // utilisable jusqu'à ce terme — sinon les relances promettent un délai
    // que le produit ne donne pas, et l'établissement se retrouve privé
    // d'une déclaration LÉGALEMENT obligatoire pour un motif comptable.
    //
    // Le terme est celui du recouvrement, pas un second réglage : il vient
    // de BillingService::DUNNING_SUSPEND_DAYS. Deux valeurs finiraient par
    // diverger, et la divergence se paierait en clients coupés trop tôt.

    /**
     * Fin de la tolérance après échéance, ou null si la notion ne s'applique
     * pas (essai, résiliation, abonnement sans échéance).
     *
     * Résilier, c'est choisir de partir : aucune facture ne suivra, il n'y a
     * donc rien à recouvrer et pas de grâce à accorder. Le service s'arrête
     * au terme payé, exactement comme annoncé au client.
     */
    public function graceEndsAt(): ?\Illuminate\Support\Carbon
    {
        if ($this->status !== 'active' || !$this->auto_renew || !$this->expires_at) {
            return null;
        }

        return $this->expires_at->copy()->addDays(\App\Services\Billing\BillingService::DUNNING_SUSPEND_DAYS);
    }

    /** L'échéance est passée mais le terme de recouvrement n'est pas atteint. */
    public function isInGracePeriod(): bool
    {
        $end = $this->graceEndsAt();

        return $end !== null && $this->expires_at->isPast() && $end->isFuture();
    }

    /**
     * Payload lisible par le client : il doit savoir qu'il est en sursis et
     * jusqu'à quand, sans avoir à déduire une date d'un statut « actif ».
     *
     * @return array{active: bool, ends_at: string|null, days_left: int|null}
     */
    public function gracePayload(): array
    {
        if (!$this->isInGracePeriod()) {
            return ['active' => false, 'ends_at' => null, 'days_left' => null];
        }

        $end = $this->graceEndsAt();

        return [
            'active'    => true,
            'ends_at'   => $end->toIso8601String(),
            'days_left' => max(0, (int) ceil(now()->diffInDays($end, false))),
        ];
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
