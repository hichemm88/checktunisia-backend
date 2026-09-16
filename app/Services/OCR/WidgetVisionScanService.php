<?php

namespace App\Services\OCR;

use Anthropic\Client;
use App\Models\DocumentScan;
use App\Services\AiUsageRecorder;
use Illuminate\Support\Facades\Log;

/**
 * Lecture (Claude vision) d'un scan CIN/passeport pris dans le widget embarqué.
 *
 * OcrService (flux natif) reste un no-op en production par choix explicite —
 * la vraie lecture pour l'app native se fait côté CLIENT via /api/scan/cin,
 * hors de ce repo (voir OcrService). Le widget n'a ni cet accès (jeton de
 * session opaque, pas de Bearer Sanctum) ni de dépendance client lourde
 * (bundle Vite volontairement séparé et léger, voir widget-main.tsx) : la
 * lecture se fait donc ici, côté serveur, pour ce seul chemin.
 *
 * La zone MRZ, quand le modèle la retranscrit, est repassée dans MrzParser —
 * un décodage déterministe déjà éprouvé vaut mieux qu'une date devinée par le
 * modèle à partir des mêmes deux lignes.
 */
class WidgetVisionScanService
{
    private const PROMPT = <<<'TXT'
        Cette photo montre une pièce d'identité (carte d'identité nationale,
        passeport, ou titre de séjour) destinée à préremplir un formulaire
        d'enregistrement hôtelier.

        Extrait UNIQUEMENT ce qui est lisible avec certitude sur cette image :
        - first_name, last_name : en alphabet latin tels qu'imprimés
          (translittération officielle si le document est en arabe).
        - date_of_birth, expiry_date : format YYYY-MM-DD.
        - sex : "M", "F" ou "X".
        - nationality_code : code ISO 3166-1 alpha-3 (ex. TUN, FRA).
        - document_type : "national_id", "passport" ou "residence_permit".
        - document_number : tel qu'imprimé.
        - issuing_country_code : code ISO 3166-1 alpha-3 du pays émetteur.
        - mrz_line1 / mrz_line2 : si une zone MRZ à deux lignes de 44
          caractères est visible (bas de la page passeport), retranscris-la
          EXACTEMENT caractère par caractère, chevrons "<" compris.
        - confidence : ta confiance globale dans cette lecture, entre 0 et 1.
        - found : false si aucune pièce d'identité lisible n'apparaît sur
          cette photo (les autres champs peuvent alors être null).

        Ne complète JAMAIS un champ que tu ne peux pas lire avec certitude —
        renvoie null plutôt que de deviner.
        TXT;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'found' => ['type' => 'boolean'],
            'confidence' => ['type' => 'number'],
            'first_name' => ['type' => ['string', 'null']],
            'last_name' => ['type' => ['string', 'null']],
            'date_of_birth' => ['type' => ['string', 'null']],
            'sex' => ['type' => ['string', 'null']],
            'nationality_code' => ['type' => ['string', 'null']],
            'document_type' => ['type' => ['string', 'null']],
            'document_number' => ['type' => ['string', 'null']],
            'issuing_country_code' => ['type' => ['string', 'null']],
            'expiry_date' => ['type' => ['string', 'null']],
            'mrz_line1' => ['type' => ['string', 'null']],
            'mrz_line2' => ['type' => ['string', 'null']],
        ],
        'required' => [
            'found', 'confidence', 'first_name', 'last_name', 'date_of_birth', 'sex',
            'nationality_code', 'document_type', 'document_number', 'issuing_country_code',
            'expiry_date', 'mrz_line1', 'mrz_line2',
        ],
        'additionalProperties' => false,
    ];

    public function __construct(private AiUsageRecorder $usage) {}

    /**
     * @return array{status:string,confidence?:?float,extracted?:?array,error?:string}
     */
    public function extract(DocumentScan $scan, string $hotelId, string $documentTypeHint): array
    {
        $key = (string) config('ocr.widget_vision.api_key');

        if (!config('ocr.widget_vision.enabled') || $key === '') {
            return ['status' => 'failed', 'error' => 'Lecture automatique non configurée — utilisez la saisie manuelle.'];
        }

        $binary = $scan->imageBytes();
        if ($binary === null) {
            return ['status' => 'failed', 'error' => 'Image illisible — utilisez la saisie manuelle.'];
        }

        $feature = $documentTypeHint === 'passport' ? 'passport_scan' : 'cin_scan';
        $model = (string) config('ocr.widget_vision.model', 'claude-opus-5');
        $started = microtime(true);

        try {
            $message = (new Client(apiKey: $key))->messages->create(
                model: $model,
                maxTokens: 1024,
                messages: [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => [
                            'type' => 'base64',
                            'media_type' => 'image/jpeg',
                            'data' => base64_encode($binary),
                        ]],
                        ['type' => 'text', 'text' => self::PROMPT],
                    ],
                ]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::SCHEMA]],
            );
        } catch (\Throwable $e) {
            Log::warning('[widget] lecture du scan indisponible : '.$e->getMessage());
            $this->record($hotelId, $feature, $model, null, null, 'api_error', $started);

            return ['status' => 'failed', 'error' => 'Lecture automatique indisponible — utilisez la saisie manuelle.'];
        }

        $json = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = json_decode($block->text, true);
                break;
            }
        }

        if (!is_array($json)) {
            $this->record($hotelId, $feature, $model, $message->usage, $message->model, 'parse_error', $started);

            return ['status' => 'failed', 'error' => 'Lecture automatique indisponible — utilisez la saisie manuelle.'];
        }

        $this->record($hotelId, $feature, $model, $message->usage, $message->model, 'success', $started);

        if (($json['found'] ?? false) !== true) {
            return ['status' => 'failed', 'error' => 'Aucune pièce d\'identité reconnue sur cette photo.'];
        }

        return [
            'status' => 'completed',
            'confidence' => isset($json['confidence']) && is_numeric($json['confidence'])
                ? max(0.0, min(1.0, (float) $json['confidence']))
                : null,
            'extracted' => self::normalize($json),
        ];
    }

    /**
     * Fusionne la lecture MRZ déterministe (si les deux lignes sont valides)
     * par-dessus la lecture libre du modèle.
     *
     * Public static à dessein (voir FicheScanCropper::validate) : c'est la
     * partie qui décide ce qui atterrit dans le formulaire, elle doit pouvoir
     * être éprouvée sans appeler quoi que ce soit.
     */
    public static function normalize(array $json): array
    {
        $extracted = [
            'first_name' => $json['first_name'] ?? null,
            'last_name' => $json['last_name'] ?? null,
            'date_of_birth' => $json['date_of_birth'] ?? null,
            'sex' => in_array($json['sex'] ?? null, ['M', 'F', 'X'], true) ? $json['sex'] : null,
            'nationality_code' => $json['nationality_code'] ?? null,
            'document_type' => in_array($json['document_type'] ?? null, ['national_id', 'passport', 'residence_permit'], true)
                ? $json['document_type']
                : null,
            'document_number' => $json['document_number'] ?? null,
            'issuing_country_code' => $json['issuing_country_code'] ?? null,
            'expiry_date' => $json['expiry_date'] ?? null,
            'mrz_line1' => $json['mrz_line1'] ?? null,
            'mrz_line2' => $json['mrz_line2'] ?? null,
        ];

        $line1 = (string) ($json['mrz_line1'] ?? '');
        $line2 = (string) ($json['mrz_line2'] ?? '');

        if (strlen($line1) === 44 && strlen($line2) === 44) {
            $mrz = MrzParser::parse($line1, $line2);
            if ($mrz) {
                $extracted = array_merge($extracted, [
                    'last_name' => $mrz['last_name'],
                    'first_name' => $mrz['first_name'],
                    'document_number' => $mrz['document_number'],
                    'nationality_code' => $mrz['nationality_code'],
                    'date_of_birth' => $mrz['date_of_birth'],
                    'sex' => $mrz['sex'],
                    'expiry_date' => $mrz['expiry_date'],
                    'issuing_country_code' => $mrz['issuing_country_code'],
                    'document_type' => 'passport',
                    'mrz_line1' => $mrz['mrz_line1'],
                    'mrz_line2' => $mrz['mrz_line2'],
                ]);
            }
        }

        return $extracted;
    }

    private function record(string $hotelId, string $feature, string $model, mixed $usage, ?string $actualModel, string $status, float $started): void
    {
        $this->usage->record([
            'hotel_id' => $hotelId,
            'user_id' => null,
            'feature' => $feature,
            'model' => $actualModel ?? $model,
            'input_tokens' => $usage->inputTokens ?? 0,
            'output_tokens' => $usage->outputTokens ?? 0,
            'status' => $status,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }
}
