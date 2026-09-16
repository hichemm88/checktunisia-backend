<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartnerWebhookEndpoint extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'partner_id',
        'url',
        'secret',
        'events',
        'active',
        'consecutive_failures',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(ApiPartner::class, 'partner_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PartnerWebhookDelivery::class, 'endpoint_id');
    }

    public function subscribesTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }
}
