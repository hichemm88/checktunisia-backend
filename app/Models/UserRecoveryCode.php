<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Code de récupération à usage unique.
 *
 * Sert quand l'appareil portant la passkey — ou l'application TOTP — n'est plus
 * disponible. Stocké haché (bcrypt via Hash::make) : une fuite de la table ne
 * donne aucun code utilisable.
 */
class UserRecoveryCode extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'code_hash',
        'used_at',
        'used_ip',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
