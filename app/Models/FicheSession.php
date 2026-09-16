<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FicheSession extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    public const MODE_CREATE = 'create';
    public const MODE_AMEND = 'amend';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'hotel_id',
        'partner_id',
        'api_key_id',
        'booking_reference',
        'mode',
        'status',
        'check_in_id',
        'prefill_guests',
        'arrival_date',
        'departure_date',
        'room_label',
        'metadata',
        'is_test',
        'jwt_jti',
        'jwt_consumed_at',
        'widget_session_token_hash',
        'widget_session_expires_at',
        'expires_at',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'prefill_guests' => 'array',
            'metadata' => 'array',
            'is_test' => 'boolean',
            'arrival_date' => 'date',
            'departure_date' => 'date',
            'jwt_consumed_at' => 'datetime',
            'widget_session_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
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

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'api_key_id');
    }

    public function checkIn(): BelongsTo
    {
        return $this->belongsTo(CheckIn::class);
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isPast();
    }

    public function isPendingAndUsable(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->expires_at->isPast();
    }

    /** Statut public tel qu'exposé dans GET /v1/fiche-sessions/{id} (reflète l'expiration réelle). */
    public function publicStatus(): string
    {
        if ($this->status === self::STATUS_PENDING && $this->expires_at->isPast()) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }
}
