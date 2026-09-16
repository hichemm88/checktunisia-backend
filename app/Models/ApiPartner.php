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

    /**
     * Origine autorisée soit à EMBARQUER le widget dans une iframe (chargement
     * du shell, bootstrap), soit à APPELER l'API widget DEPUIS l'intérieur de
     * l'iframe déjà chargée (ajout/retrait de voyageur, scan, soumission).
     *
     * Ce second cas a un "origin" bien à lui : un navigateur ajoute l'en-tête
     * `Origin` sur toute requête POST/DELETE (même de même origine, jamais
     * sur un GET de même origine) — la valeur envoyée est alors qayed.tn
     * lui-même (l'origine du DOCUMENT qui fait l'appel), jamais celle du
     * partenaire qui l'a embarqué. Vérifier cette origine contre la seule
     * liste `allowed_widget_origins` (qui ne contient que des domaines
     * partenaires) rejetait donc TOUJOURS ces appels dans un vrai navigateur
     * — bug reproduit en production le 2026-09-16 : le bootstrap (GET)
     * passait, l'ajout de voyageur (POST) rendait 403 sur toute intégration.
     * `'self'` est déjà ajouté à la directive CSP `frame-ancestors` posée par
     * PartnerWidgetFrameAncestors pour la même raison ; l'accepter ici aussi
     * ne l'étend à rien : l'origine reste non falsifiable côté navigateur, un
     * jeton volé utilisé depuis un VRAI site tiers enverrait toujours son
     * origine réelle et resterait rejeté.
     */
    public function allowsOrigin(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return false;
        }

        $origin = rtrim($origin, '/');

        if ($origin === rtrim((string) config('app.url'), '/')) {
            return true;
        }

        return in_array($origin, array_map(
            fn ($o) => rtrim((string) $o, '/'),
            $this->allowed_widget_origins ?? [],
        ), true);
    }
}
