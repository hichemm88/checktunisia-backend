<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renseigne les tarifs officiels Anthropic pour le modele du scan CIN/passeport
 * quand ils sont encore au placeholder 0.
 *
 * Contexte : le seed initial posait des prix a 0 et etait rejoue en
 * `updateOrCreate` a chaque deploiement, ce qui remettait les tarifs a 0 et
 * faisait reapparaitre le bandeau "Tarifs non configures". On corrige les lignes
 * encore a 0 avec les tarifs standard de claude-sonnet-5 (USD / million de tokens),
 * sans jamais ecraser un tarif deja saisi par l'admin (on ne touche que les <= 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        $model = env('CIN_SCAN_MODEL', 'claude-sonnet-5');

        DB::table('ai_pricing')
            ->where('model', $model)
            ->where(function ($q) {
                $q->where('input_price_per_mtok_usd', '<=', 0)
                  ->orWhere('output_price_per_mtok_usd', '<=', 0);
            })
            ->update([
                'input_price_per_mtok_usd' => 3.0,   // tarif standard Anthropic
                'output_price_per_mtok_usd' => 15.0,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // No-op : on ne restaure pas des placeholders a 0.
    }
};
