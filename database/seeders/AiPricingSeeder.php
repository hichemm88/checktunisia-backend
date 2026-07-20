<?php

namespace Database\Seeders;

use App\Models\AiPricing;
use Illuminate\Database\Seeder;

/**
 * Seed d'une ligne de tarif pour le modele du scan CIN et du repli passeport
 * (variable d'env CIN_SCAN_MODEL cote fonction Vercel, defaut claude-sonnet-5).
 *
 * Valeurs par defaut : les tarifs standard officiels Anthropic pour
 * claude-sonnet-5 (USD / million de tokens), pour que le suivi des couts soit
 * "configure" des l'installation et que le bandeau "Tarifs non configures"
 * n'apparaisse pas a tort.
 *
 * IMPORTANT : on utilise firstOrCreate (et NON updateOrCreate). Ce seeder est
 * rejoue a chaque deploiement ; firstOrCreate ne cree la ligne que si elle est
 * absente et NE reecrit jamais un tarif deja saisi par l'admin. C'est ce qui
 * evite que les prix soient remis a leur valeur du seed a chaque redeploiement.
 * L'admin peut ajuster les tarifs a tout moment depuis la page Couts IA ; ses
 * saisies persistent.
 */
class AiPricingSeeder extends Seeder
{
    public function run(): void
    {
        AiPricing::firstOrCreate(
            ['model' => env('CIN_SCAN_MODEL', 'claude-sonnet-5')],
            [
                'input_price_per_mtok_usd' => 3.0,
                'output_price_per_mtok_usd' => 15.0,
                'active' => true,
                'updated_at' => now(),
            ],
        );
    }
}
