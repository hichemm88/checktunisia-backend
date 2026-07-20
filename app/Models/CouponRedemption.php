<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Application d'un coupon a une facture (trace d'usage). Un coupon ne peut etre
 * applique qu'une fois par facture (contrainte unique en base).
 */
class CouponRedemption extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $primaryKey = 'id';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = [
        'coupon_id', 'invoice_id', 'organization_id',
        'amount_discounted', 'redeemed_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_discounted' => 'decimal:3',
            'created_at'        => 'datetime',
        ];
    }

    public function coupon(): BelongsTo   { return $this->belongsTo(Coupon::class); }
    public function invoice(): BelongsTo  { return $this->belongsTo(Invoice::class); }
}
