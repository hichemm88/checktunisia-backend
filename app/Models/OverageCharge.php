<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dépassement de quota d'un mois clôturé : tranches entamées et montant.
 *
 * status :
 *  - pending          calculé, facture pas encore générée
 *  - invoiced         facture générée (invoice_id renseigné)
 *  - excluded_legacy  compte grandfathered — jamais facturé automatiquement,
 *                     visible dans l'admin pour le pilotage commercial
 *  - waived           dépassement abandonné manuellement par l'admin
 */
class OverageCharge extends Model
{
    public const STATUS_PENDING         = 'pending';
    public const STATUS_INVOICED        = 'invoiced';
    public const STATUS_EXCLUDED_LEGACY = 'excluded_legacy';
    /** Compte interne : le dépassement reste mesuré, il n'est jamais facturé. */
    public const STATUS_EXCLUDED_INTERNAL = 'excluded_internal';
    public const STATUS_WAIVED          = 'waived';

    protected $fillable = [
        'organization_id', 'subscription_id', 'period', 'checkins_count', 'quota',
        'overage_count', 'bundle_size', 'bundle_count', 'unit_price', 'amount',
        'status', 'invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'period'     => 'date',
            'unit_price' => 'decimal:3',
            'amount'     => 'decimal:3',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
    public function invoice(): BelongsTo      { return $this->belongsTo(Invoice::class); }
}
