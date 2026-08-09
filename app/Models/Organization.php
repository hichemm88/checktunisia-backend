<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    /** Property types allowed for any property under this org. */
    public const PROPERTY_TYPES = [
        'hotel', 'guesthouse', 'appartement', 'villa', 'riad',
        'maison_hotes', 'hostel', 'resort', 'bungalow', 'rental',
    ];

    /** Human-readable labels (FR). */
    public const PROPERTY_TYPE_LABELS = [
        'hotel'        => 'Hôtel',
        'guesthouse'   => 'Maison d\'hôtes',
        'appartement'  => 'Appartement',
        'villa'        => 'Villa',
        'riad'         => 'Riad',
        'maison_hotes' => 'Maison d\'hôtes',
        'hostel'       => 'Auberge de jeunesse',
        'resort'       => 'Resort',
        'bungalow'     => 'Bungalow',
        'rental'       => 'Location saisonnière',
    ];

    protected $fillable = [
        'name',
        'entity_type',       // company | individual
        'registration_number',
        'contact_email',
        'contact_phone',
        'address',
        'status',
        'locale',
        'upsell_flagged_at',
        'billing_mode',
        'billing_mode_note',
    ];

    /** Client : facturé, compté dans le chiffre d'affaires. */
    public const BILLING_COMMERCIAL = 'commercial';

    /**
     * Compte nous appartenant : utilise le produit sans rien acheter. Jamais
     * facturé, jamais compté dans une métrique commerciale.
     *
     * N'accorde AUCUN privilège : mêmes permissions, même isolation tenant,
     * même accès au produit qu'un client. C'est une exemption commerciale.
     */
    public const BILLING_INTERNAL = 'internal';

    public const BILLING_MODES = [self::BILLING_COMMERCIAL, self::BILLING_INTERNAL];

    protected function casts(): array
    {
        return [
            'address'           => 'array',
            'upsell_flagged_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────

    /** All properties (hotels / appartements / villas …) owned by this org. */
    public function properties(): HasMany
    {
        return $this->hasMany(Hotel::class, 'organization_id');
    }

    /** Users whose primary org is this one. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'organization_id');
    }

    /** All subscriptions for this org (newest first). */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'organization_id')->orderByDesc('started_at');
    }

    /** The single active (or in-trial) subscription. */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'organization_id')
            ->whereIn('status', ['active', 'trial'])
            ->latest('started_at');
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription()->exists();
    }

    // ─── Périmètre commercial ────────────────────────────────────────────
    //
    // Règle UNIQUE. Toute facturation et toute métrique de revenu doivent
    // passer par ici : c'est ce qui garantit qu'une fonctionnalité ajoutée
    // plus tard ne réintroduira pas un compte interne dans le chiffre
    // d'affaires par simple oubli.

    /** Ce compte est-il un client ? (défaut pour toute organisation) */
    public function isCommercial(): bool
    {
        // Le repli sur `commercial` couvre les lignes antérieures à la
        // colonne : un compte est un client tant qu'on n'a pas dit l'inverse.
        return ($this->billing_mode ?? self::BILLING_COMMERCIAL) !== self::BILLING_INTERNAL;
    }

    /** Compte à nous : exempté de facturation et de toute métrique de revenu. */
    public function isInternal(): bool
    {
        return ! $this->isCommercial();
    }

    /** Organisations clientes — la base de toute statistique commerciale. */
    public function scopeCommercial(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(function ($q) {
            $q->where('billing_mode', self::BILLING_COMMERCIAL)->orWhereNull('billing_mode');
        });
    }

    /** Comptes internes — visibles en pilotage opérationnel, jamais en revenu. */
    public function scopeInternal(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('billing_mode', self::BILLING_INTERNAL);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function totalRooms(): int
    {
        return (int) $this->properties()->sum('room_count');
    }
}
