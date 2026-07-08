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
    ];

    protected function casts(): array
    {
        return [
            'address' => 'array',
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

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function totalRooms(): int
    {
        return (int) $this->properties()->sum('room_count');
    }
}
