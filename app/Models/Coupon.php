<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Code promo applique aux factures. Remise en pourcentage ou montant fixe (TND),
 * portant sur le montant HT avant taxe. Le code est toujours stocke en MAJUSCULES.
 */
class Coupon extends Model
{
    use HasUuids;

    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED   = 'fixed';
    public const TYPES = [self::TYPE_PERCENT, self::TYPE_FIXED];

    protected $primaryKey = 'id';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = [
        'code', 'type', 'value', 'description', 'min_amount',
        'max_uses', 'used_count', 'expires_at', 'active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'value'      => 'decimal:3',
            'min_amount' => 'decimal:3',
            'max_uses'   => 'integer',
            'used_count' => 'integer',
            'expires_at' => 'datetime',
            'active'     => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Le code est normalise en MAJUSCULES a l'ecriture pour une comparaison
        // insensible a la casse cote saisie (l'unicite est garantie en base).
        static::saving(function (Coupon $coupon) {
            if ($coupon->code !== null) {
                $coupon->code = Str::upper(trim($coupon->code));
            }
        });
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->max_uses !== null && $this->used_count >= $this->max_uses;
    }

    /** Utilisable en principe (actif, non expire, quota non atteint). */
    public function isRedeemable(): bool
    {
        return $this->active && ! $this->isExpired() && ! $this->isExhausted();
    }
}
