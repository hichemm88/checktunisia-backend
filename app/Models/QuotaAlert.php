<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace d'une alerte quota envoyée — l'unicité (organisation, mois, seuil)
 * garantit qu'un seuil ne part qu'une fois par cycle mensuel (anti-spam).
 */
class QuotaAlert extends Model
{
    public const THRESHOLD_WARN_80   = 'warn_80';
    public const THRESHOLD_REACHED   = 'reached_100';
    public const THRESHOLD_UPSELL    = 'upsell_suggested';

    protected $fillable = [
        'organization_id', 'subscription_id', 'period', 'threshold',
        'checkins_count', 'quota', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'period'  => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
}
