<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    public const MODE_LIVE = 'live';
    public const MODE_TEST = 'test';

    protected $fillable = [
        'partner_id',
        'mode',
        'prefix',
        'key_hash',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(ApiPartner::class, 'partner_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isTestMode(): bool
    {
        return $this->mode === self::MODE_TEST;
    }
}
