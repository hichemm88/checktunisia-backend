<?php

use App\Services\CheckIn\DocumentIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Met les documents déjà enregistrés sous la forme canonique désormais
 * utilisée pour rapprocher un voyageur : numéro en majuscules sans
 * séparateur, pays de délivrance en alpha-3.
 *
 * Pourquoi c'est nécessaire : le rapprochement se fait sur le triplet
 * (type, numéro, pays). Après normalisation du code applicatif, un client
 * fidèle dont le passeport avait été saisi « TN »/« ab 123 » ne serait plus
 * reconnu au retour — le système lui créerait un second dossier, et son
 * historique de séjours serait coupé en deux.
 *
 * Garanties :
 *  - AUCUNE suppression, AUCUNE fusion : on ne fait que réécrire deux colonnes.
 *  - Les collisions (deux lignes qui deviendraient identiques après
 *    normalisation, ex. « TN »+« TUN » pour le même numéro) sont laissées
 *    INTACTES et journalisées. Les fusionner supposerait de choisir quel
 *    voyageur survit — décision humaine, pas une décision de migration.
 *  - Idempotente : relancée, elle ne trouve plus rien à changer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $updated = 0;
        $collisions = [];

        DB::table('travel_documents')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$updated, &$collisions) {
                foreach ($rows as $row) {
                    $number = DocumentIdentity::normalizeNumber($row->document_number);
                    $country = DocumentIdentity::normalizeCountry($row->issuing_country_code);

                    if ($number === trim((string) $row->document_number)
                        && $country === trim((string) $row->issuing_country_code)) {
                        continue;
                    }

                    // Un numéro qui se vide entièrement à la normalisation
                    // (que des séparateurs) est une donnée douteuse : on n'y
                    // touche pas plutôt que d'écrire une chaîne vide.
                    if ($number === '') {
                        $collisions[] = ['id' => $row->id, 'reason' => 'numero_vide_apres_normalisation'];

                        continue;
                    }

                    $taken = DB::table('travel_documents')
                        ->where('type', $row->type)
                        ->where('document_number', $number)
                        ->where('issuing_country_code', $country)
                        ->where('id', '!=', $row->id)
                        ->exists();

                    if ($taken) {
                        $collisions[] = ['id' => $row->id, 'reason' => 'doublon_apres_normalisation'];

                        continue;
                    }

                    DB::table('travel_documents')->where('id', $row->id)->update([
                        'document_number' => $number,
                        'issuing_country_code' => $country,
                    ]);
                    $updated++;
                }
            });

        Log::info('[migration] normalisation des documents de voyage', [
            'documents_normalises' => $updated,
            'laisses_en_etat' => count($collisions),
            'details' => array_slice($collisions, 0, 50),
        ]);
    }

    /**
     * Sans retour arrière : la normalisation perd l'information de mise en
     * forme d'origine (espaces, casse), qui n'a aucune valeur métier. Aucune
     * ligne n'ayant été supprimée, il n'y a rien à restaurer.
     */
    public function down(): void
    {
        // no-op
    }
};
