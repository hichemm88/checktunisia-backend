<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Challenge WebAuthn émis par le serveur, à usage unique et expirant.
 *
 * On stocke les options COMPLÈTES et non le seul challenge : la vérification
 * rejoue exactement la demande envoyée au navigateur (RP ID, exigence de
 * vérification utilisateur, liste de credentials autorisés). Un attaquant ne
 * peut donc pas faire valider une réponse contre des règles plus faibles que
 * celles réellement demandées.
 */
class WebauthnChallenge extends Model
{
    use HasUuids;

    public const CEREMONY_REGISTRATION   = 'registration';
    public const CEREMONY_AUTHENTICATION = 'authentication';

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    /** Un challenge n'est jamais modifié après création, sauf sa consommation. */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'ceremony',
        'options',
        'expires_at',
        'consumed_at',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'options'     => 'array',
            'expires_at'  => 'datetime',
            'consumed_at' => 'datetime',
            'created_at'  => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(string $ceremony): bool
    {
        return $this->ceremony === $ceremony
            && $this->consumed_at === null
            && $this->expires_at->isFuture();
    }
}
