<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiPricing extends Model
{
    use HasUuids;

    protected $table = 'ai_pricing';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false; // seule updated_at existe, geree a la main

    protected $fillable = [
        'model',
        'input_price_per_mtok_usd',
        'output_price_per_mtok_usd',
        'active',
        'updated_at',
    ];

    protected $casts = [
        'input_price_per_mtok_usd' => 'decimal:4',
        'output_price_per_mtok_usd' => 'decimal:4',
        'active' => 'boolean',
        'updated_at' => 'datetime',
    ];

    /** Tarif actif pour un modele donne, ou null. */
    public static function activeFor(string $model): ?self
    {
        return static::where('model', $model)->where('active', true)->first();
    }
}
