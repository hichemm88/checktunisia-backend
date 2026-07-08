<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = [
        'invoice_id',
        'hotel_id',
        'provider',
        'provider_payment_id',
        'provider_tracking_id',
        'declared_reference',
        'declared_at',
        'status',
        'amount',
        'currency',
        'payment_url',
        'expires_at',
        'completed_at',
        'provider_response',
    ];

    protected function casts(): array
    {
        return [
            'amount'            => 'decimal:3',
            'expires_at'        => 'datetime',
            'completed_at'      => 'datetime',
            'declared_at'       => 'datetime',
            'provider_response' => 'array',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired'
            || ($this->isPending() && $this->expires_at?->isPast());
    }
}
