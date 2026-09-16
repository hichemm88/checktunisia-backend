<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerWebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'endpoint_id',
        'event_type',
        'idempotency_key',
        'payload',
        'status',
        'attempts',
        'next_attempt_at',
        'claimed_at',
        'last_response_status',
        'last_error',
        'queued_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(PartnerWebhookEndpoint::class, 'endpoint_id');
    }
}
