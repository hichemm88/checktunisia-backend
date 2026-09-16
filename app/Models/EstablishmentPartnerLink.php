<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstablishmentPartnerLink extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'hotel_id',
        'partner_id',
        'linked_at',
        'revoked_at',
        'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(ApiPartner::class, 'partner_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
