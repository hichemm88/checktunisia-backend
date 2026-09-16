<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ApiPartner extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'auth_mode',
        'allowed_widget_origins',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'allowed_widget_origins' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ApiPartner $partner) {
            if (empty($partner->slug)) {
                $partner->slug = Str::slug($partner->name).'-'.Str::random(6);
            }
        });
    }

    public function keys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'partner_id');
    }

    public function establishmentLinks(): HasMany
    {
        return $this->hasMany(EstablishmentPartnerLink::class, 'partner_id');
    }

    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(PartnerWebhookEndpoint::class, 'partner_id');
    }

    public function ficheSessions(): HasMany
    {
        return $this->hasMany(FicheSession::class, 'partner_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Origine autorisée à charger le widget dans une iframe. */
    public function allowsOrigin(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return false;
        }

        return in_array(rtrim($origin, '/'), array_map(
            fn ($o) => rtrim((string) $o, '/'),
            $this->allowed_widget_origins ?? [],
        ), true);
    }
}
