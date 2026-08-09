<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un changement de plan demandé par le client. Voir la migration
 * `create_subscription_plan_changes_table` pour le cycle de vie complet.
 */
class SubscriptionPlanChange extends Model
{
    use HasUuids;

    public const KIND_UPGRADE   = 'upgrade';
    public const KIND_DOWNGRADE = 'downgrade';

    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_SCHEDULED       = 'scheduled';
    public const STATUS_APPLIED         = 'applied';
    public const STATUS_CANCELLED       = 'cancelled';
    public const STATUS_FAILED          = 'failed';

    /** Statuts « en cours » — ceux que l'index unique partiel empêche de doubler. */
    public const IN_FLIGHT = [self::STATUS_PENDING_PAYMENT, self::STATUS_SCHEDULED];

    protected $fillable = [
        'subscription_id', 'organization_id', 'from_plan_id', 'to_plan_id',
        'kind', 'status', 'effective_at', 'applied_at', 'invoice_id',
        'amount_due', 'credit_applied', 'next_renewal_amount',
        'from_conditions', 'conditions_change_accepted',
        'idempotency_key', 'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_at'               => 'datetime',
            'applied_at'                 => 'datetime',
            'amount_due'                 => 'decimal:3',
            'credit_applied'             => 'decimal:3',
            'next_renewal_amount'        => 'decimal:3',
            'from_conditions'            => 'array',
            'conditions_change_accepted' => 'boolean',
        ];
    }

    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function fromPlan(): BelongsTo     { return $this->belongsTo(SubscriptionPlan::class, 'from_plan_id'); }
    public function toPlan(): BelongsTo       { return $this->belongsTo(SubscriptionPlan::class, 'to_plan_id'); }
    public function invoice(): BelongsTo      { return $this->belongsTo(Invoice::class); }
    public function requester(): BelongsTo    { return $this->belongsTo(User::class, 'requested_by'); }

    public function isInFlight(): bool
    {
        return in_array($this->status, self::IN_FLIGHT, true);
    }
}
