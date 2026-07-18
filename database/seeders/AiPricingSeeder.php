<?php

namespace Database\Seeders;

use App\Models\AiPricing;
use Illuminate\Database\Seeder;

/**
 * Seed initial : une ligne pour le modele utilise par le scan CIN et le repli
 * passeport (variable d'env CIN_SCAN_MODEL cote fonction Vercel, defaut
 * claude-sonnet-5).
 *
 * ATTENTION : les prix sont a 0 (placeholder). NE PAS deviner les tarifs. Ils
 * doivent etre saisis depuis l'admin (page Couts IA) a partir de la page pricing
 * officielle Anthropic. Tant qu'ils sont a 0, le widget affiche l'avertissement
 * "Tarifs non configures" et les couts sont faux.
 */
class AiPricingSeeder extends Seeder
{
    public function run(): void
    {
        AiPricing::updateOrCreate(
            ['model' => env('CIN_SCAN_MODEL', 'claude-sonnet-5')],
            [
                'input_price_per_mtok_usd' => 0,
                'output_price_per_mtok_usd' => 0,
                'active' => true,
                'updated_at' => now(),
            ],
        );
    }
}
