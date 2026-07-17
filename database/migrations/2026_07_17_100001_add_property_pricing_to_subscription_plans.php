<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grille tarifaire par établissement : fin du « Multi-sites illimité » qui
 * inversait la grille (2 établissements Pro > Multi-sites, et 20 biens payés
 * comme 3). Nouveau modèle : chaque pack inclut N établissements
 * (included_properties) ; au-delà, +X TND/mois par établissement
 * (extra_property_price). extra_property_price null = pas d'extension
 * possible (le pack est plafonné à included_properties).
 *
 * Valeurs initiales : Essentiel 1 inclus, Pro 1 inclus, Multi-sites 3 inclus
 * puis +39 TND/mois. La migration met aussi à jour la carte marketing
 * Multi-sites stockée en base (les cartes sont data-driven sur la landing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unsignedInteger('included_properties')->default(1);
            $table->decimal('extra_property_price', 8, 3)->nullable();
        });

        // Valeurs initiales par pack (modifiable ensuite dans Admin > Abonnements).
        DB::table('subscription_plans')->where('slug', 'multi-sites')->update([
            'included_properties'  => 3,
            'extra_property_price' => 39.000,
        ]);

        // Carte marketing Multi-sites : retirer « illimité » (établissements et
        // utilisateurs restent illimités en FONCTIONNALITÉ pour les comptes,
        // mais l'affichage change) — le sous-texte « 3 établissements inclus »
        // et la ligne « +39 TND/mois par établissement supplémentaire » sont
        // rendus DYNAMIQUEMENT depuis les colonnes, pas depuis ce JSON, pour
        // suivre les changements de tarifs sans retoucher le contenu.
        $plan = DB::table('subscription_plans')->where('slug', 'multi-sites')->first();
        if ($plan && $plan->marketing) {
            $marketing = json_decode($plan->marketing, true);

            $marketing['price_note'] = [
                'fr' => 'par société / mois',
                'en' => 'per company / month',
                'ar' => 'لكل شركة / شهريًا',
            ];
            $marketing['price_note_yearly'] = [
                'fr' => 'par société / an · 12 mois au prix de 11',
                'en' => 'per company / year · 12 months for the price of 11',
                'ar' => 'لكل شركة / سنويًا · 12 شهرًا بسعر 11',
            ];
            $marketing['tagline'] = [
                'fr' => 'Pour les gestionnaires de portefeuille (3 établissements et plus).',
                'en' => 'For portfolio managers (3 properties and more).',
                'ar' => 'لمديري المحافظ العقارية (3 مؤسسات فأكثر).',
            ];
            // Remplace la puce « Établissements illimités » par le registre
            // consolidé ; les autres puces restent.
            $marketing['bullets'] = array_values(array_map(function ($bullet) {
                if (($bullet['text']['fr'] ?? '') === 'Établissements illimités') {
                    $bullet['text'] = [
                        'fr' => 'Registre consolidé multi-établissements',
                        'en' => 'Consolidated multi-property register',
                        'ar' => 'سجل موحد متعدد المؤسسات',
                    ];
                }
                if (($bullet['text']['fr'] ?? '') === 'Comptes utilisateurs illimités') {
                    $bullet['text'] = [
                        'fr' => 'Équipe illimitée, accès par établissement',
                        'en' => 'Unlimited team, per-property access',
                        'ar' => 'فريق غير محدود، وصول لكل مؤسسة',
                    ];
                }

                return $bullet;
            }, $marketing['bullets'] ?? []));

            DB::table('subscription_plans')->where('slug', 'multi-sites')->update([
                'marketing'  => json_encode($marketing, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['included_properties', 'extra_property_price']);
        });
    }
};
