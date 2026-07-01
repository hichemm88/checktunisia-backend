<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TravelDocument extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'guest_id', 'type', 'document_number', 'issuing_country_code',
        'issue_date', 'expiry_date', 'mrz_line1', 'mrz_line2', 'mrz_line3',
        'is_verified', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'issue_date'  => 'date',
            'expiry_date' => 'date',
            'is_verified' => 'boolean',
            'metadata'    => 'array',
        ];
    }

    public function guest(): BelongsTo   { return $this->belongsTo(Guest::class); }
    public function scans(): HasMany     { return $this->hasMany(DocumentScan::class); }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->expiry_date && $this->expiry_date->isBefore(now()->addDays($days));
    }
}
