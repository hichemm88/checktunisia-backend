<?php

namespace App\Models\Prospection;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Compte interne du CRM de prospection. Voir la migration
 * create_prospection_users_table pour le pourquoi de l'isolation vis-à-vis de
 * App\Models\User (comptes clients de production).
 */
class ProspectionUser extends Authenticatable
{
    use HasUuids;

    protected $connection = 'prospection';
    protected $table = 'users';

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'notif_digest_enabled',
        'notif_digest_hour',
        'notif_demo_reminder_enabled',
        'notif_activity_enabled',
        'active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'notif_digest_enabled' => 'boolean',
            'notif_demo_reminder_enabled' => 'boolean',
            'notif_activity_enabled' => 'boolean',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(ProspectionAccessToken::class, 'user_id');
    }

    public function establishmentsCreated(): HasMany
    {
        return $this->hasMany(Establishment::class, 'created_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ProspectionAction::class, 'created_by');
    }
}
