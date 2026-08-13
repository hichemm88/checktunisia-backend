<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected string $guard_name = 'api';

    protected $fillable = [
        'organization_id',
        'role_org',
        'email',
        'password',
        'first_name',
        'last_name',
        'phone',
        'status',
        'locale',
        'email_verified_at',
        'last_login_at',
        'metadata',
        'two_factor_secret',
        'two_factor_confirmed_at',
        'webauthn_user_handle',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',  // never expose the encrypted secret
        // Identifiant opaque présenté aux authentificateurs : il n'a aucune
        // raison de circuler dans les réponses d'API.
        'webauthn_user_handle',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'       => 'datetime',
            'last_login_at'           => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'metadata'                => 'array',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function hotels(): BelongsToMany
    {
        return $this->belongsToMany(Hotel::class, 'user_hotels')
            ->withPivot(['granted_at', 'expires_at']);
    }

    public function authorityProfile(): HasOne
    {
        return $this->hasOne(AuthorityUserProfile::class);
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class, 'created_by');
    }

    /** Passkeys enregistrées — la sécurité est portée par le COMPTE, pas par le rôle. */
    public function webauthnCredentials(): HasMany
    {
        return $this->hasMany(WebauthnCredential::class);
    }

    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(UserRecoveryCode::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    public function hotel(): ?Hotel
    {
        return $this->hotels()->first();
    }

    public function isPlatformAdmin(): bool
    {
        return $this->hasRole('platform_admin');
    }

    public function isHotelAdmin(): bool
    {
        return $this->hasRole('hotel_admin');
    }

    public function isReceptionist(): bool
    {
        return $this->hasRole('receptionist');
    }

    public function isAuthorityUser(): bool
    {
        return $this->hasRole('authority_user');
    }

    public function isHotelStaff(): bool
    {
        return $this->hasAnyRole(['hotel_admin', 'receptionist']);
    }

    /**
     * Propriétaire de son organisation (dimension intra-org, distincte du
     * rôle plateforme hotel_admin — voir users.role_org).
     */
    public function isOrgOwner(): bool
    {
        return $this->role_org === 'owner';
    }

    public function hasPasskey(): bool
    {
        return $this->webauthnCredentials()->exists();
    }

    /**
     * Identifiant opaque présenté à l'authentificateur (user.id WebAuthn).
     *
     * Aléatoire et distinct de l'UUID interne comme de l'e-mail : c'est la
     * valeur que l'appareil renvoie lors d'une connexion sans identifiant, et
     * elle est stockée en clair dans le trousseau de l'appareil. Créé à la
     * demande, à la première passkey.
     */
    public function webauthnUserHandle(): string
    {
        if (! $this->webauthn_user_handle) {
            $this->forceFill([
                'webauthn_user_handle' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            ])->save();
        }

        return $this->webauthn_user_handle;
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getPrimaryRoleAttribute(): string
    {
        return $this->roles->first()?->name ?? 'unknown';
    }
}
