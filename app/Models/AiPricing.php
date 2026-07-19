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
    /**
     * L'API Anthropic renvoie souvent un snapshot date (ex.
     * "claude-sonnet-5-20260101") la ou le tarif est saisi sous l'alias
     * ("claude-sonnet-5"). On tente donc : 1) correspondance exacte ; 2) a
     * defaut, le tarif actif dont le `model` est le plus long prefixe du modele
     * reel (l'alias couvre tous ses snapshots dates).
     */
    public static function activeFor(string $model): ?self
    {
        $exact = static::query()->where('model', $model)->where('active', true)->first();
        if ($exact) {
            return $exact;
        }

        return static::query()
            ->where('active', true)
            ->get()
            ->filter(fn (self $p) => $p->model !== '' && str_starts_with($model, $p->model))
            ->sortByDesc(fn (self $p) => strlen($p->model))
            ->first();
    }
}
