<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une passkey enregistrée par un utilisateur (WebAuthn credential record).
 *
 * Ne contient QUE des données publiques : identifiant de credential, clé
 * publique COSE, compteur de signature, métadonnées d'appareil. La clé privée
 * — et la biométrie qui la déverrouille — ne quittent jamais l'appareil.
 *
 * @property string $credential_id base64url
 * @property string $public_key    base64url (COSE)
 */
class WebauthnCredential extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'credential_id',
        'public_key',
        'attestation_type',
        'trust_path',
        'aaguid',
        'user_handle',
        'transports',
        'sign_count',
        'backup_eligible',
        'backed_up',
        'uv_initialized',
        'device_name',
        'last_used_at',
        'last_used_ip',
    ];

    /**
     * La clé publique et l'identifiant de credential ne sont jamais renvoyés au
     * client : ils ne servent qu'au serveur, et les exposer élargirait
     * inutilement la surface (corrélation d'un même appareil entre comptes).
     */
    protected $hidden = [
        'credential_id',
        'public_key',
        'trust_path',
        'user_handle',
    ];

    protected function casts(): array
    {
        return [
            'trust_path'      => 'array',
            'transports'      => 'array',
            'sign_count'      => 'integer',
            'backup_eligible' => 'boolean',
            'backed_up'       => 'boolean',
            'uv_initialized'  => 'boolean',
            'last_used_at'    => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Payload d'affichage : ce que le profil montre dans « Sécurité → Passkeys ».
     */
    public function toDisplayArray(): array
    {
        return [
            'id'           => $this->id,
            'device_name'  => $this->device_name,
            'transports'   => $this->transports ?? [],
            'backed_up'    => $this->backed_up,
            'created_at'   => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
