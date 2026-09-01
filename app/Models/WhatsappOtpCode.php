<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un code à usage unique envoyé par WhatsApp à un agent autorité.
 *
 * Le code lui-même n'existe en clair qu'entre sa génération et son départ chez
 * Meta : ici il n'y a que son empreinte. Toute la logique (éligibilité, envoi,
 * vérification, verrouillage) vit dans WhatsappOtpService — ce modèle n'est
 * que la table.
 */
class WhatsappOtpCode extends Model
{
    protected $fillable = [
        'phone',
        'code_hash',
        'user_id',
        'expires_at',
        'attempts',
        'consumed_at',
        'locked_until',
    ];

    /**
     * `code_hash` est caché de la sérialisation : ce modèle n'a aucune raison
     * d'être renvoyé par l'API, mais un `toArray()` d'appoint dans un log ou
     * une réponse de débogage ne doit pas pouvoir exposer l'empreinte.
     */
    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'locked_until' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Encore utilisable : ni consommé, ni périmé. */
    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
