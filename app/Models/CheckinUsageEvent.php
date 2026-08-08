<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une consommation de check-in facturable — posée à la finalisation d'une
 * fiche (brouillon → active) et jamais retirée.
 *
 * Voir la migration `create_checkin_usage_events_table` pour la règle
 * métier complète. Le modèle n'expose délibérément aucune méthode de
 * suppression : le registre est en ajout seul (l'annulation d'un séjour
 * horodate `cancelled_at`, elle ne rend pas la consommation).
 */
class CheckinUsageEvent extends Model
{
    protected $fillable = [
        'check_in_id', 'organization_id', 'hotel_id', 'subscription_id',
        'period', 'consumed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'period'       => 'date',
            'consumed_at'  => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function checkIn(): BelongsTo      { return $this->belongsTo(CheckIn::class, 'check_in_id'); }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function hotel(): BelongsTo        { return $this->belongsTo(Hotel::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
}
