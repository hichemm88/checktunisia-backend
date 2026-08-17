<?php

namespace App\Services\Delivery;

use Anthropic\Client;
use App\Models\DocumentScan;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;

/**
 * Situe la pièce d'identité dans une photo, à l'aide d'un modèle de vision.
 *
 * ── Le problème ──────────────────────────────────────────────────────────────
 *
 * Un cliché de téléphone montre rarement que la pièce : il y a la table, une
 * main, le bord d'un comptoir. Le cadrage géométrique ne sait pas distinguer le
 * document du décor — il se contente donc de tout contenir, et la pièce finit
 * petite au milieu d'une image pleine de vide.
 *
 * ── Ce que le modèle fait, et ce qu'il ne fait pas ───────────────────────────
 *
 * Il SITUE, il ne recadre pas. Il renvoie un rectangle en fractions de l'image ;
 * le découpage reste fait ici, et rien de ce qu'il répond n'est appliqué sans
 * vérification. Un rectangle hors bornes, inversé, ou couvrant moins de quelques
 * pour cent de l'image est rejeté : on retombe alors sur le cadrage géométrique,
 * qui ne perd rien.
 *
 * Le rectangle retenu est ÉLARGI d'une marge avant découpe. La détection est
 * bonne, pas exacte au pixel, et sur une pièce d'identité transmise à l'autorité
 * un bord rogné ne se voit pas — ni dans le PDF, ni pour qui le reçoit.
 *
 * ── Coût ─────────────────────────────────────────────────────────────────────
 *
 * Un appel par scan, mémorisé en base (document_scans.crop_box). La même pièce
 * est rendue plusieurs fois — WhatsApp, export, récapitulatif — et ne doit être
 * analysée qu'une fois.
 */
class FicheScanCropper
{
    private const PROMPT = <<<'TXT'
        Cette photo montre une pièce d'identité (passeport ouvert à la page de
        données, ou carte d'identité) posée sur un fond quelconque.

        Donne le rectangle englobant de la PIÈCE ELLE-MÊME, bords compris, en
        excluant la table, les mains et tout autre décor.

        Coordonnées en fractions de l'image, entre 0 et 1 : x et y sont le coin
        supérieur gauche, width et height la taille.

        Si la pièce occupe déjà presque toute l'image, renvoie le rectangle
        complet. Si tu ne distingues aucune pièce d'identité, renvoie
        found = false.
        TXT;

    /**
     * Rectangle du document, en fractions de l'image, marge comprise.
     *
     * @return array{x:float,y:float,width:float,height:float}|null null si la
     *                                                              détection est indisponible, refusée ou invraisemblable — l'appelant
     *                                                              doit alors garder le cadrage géométrique.
     */
    public function detect(string $binary): ?array
    {
        $key = (string) config('fiche.ai_crop.api_key');

        if (!config('fiche.ai_crop.enabled') || $key === '') {
            return null;
        }

        try {
            $probe = ImageManager::gd()->read($binary);
            $probe->scaleDown((int) config('fiche.ai_crop.probe_size', 1024));

            $message = (new Client(apiKey: $key))->messages->create(
                model: (string) config('fiche.ai_crop.model', 'claude-opus-5'),
                maxTokens: 1024,
                messages: [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => [
                            'type' => 'base64',
                            'media_type' => 'image/jpeg',
                            'data' => base64_encode((string) $probe->toJpeg(80)),
                        ]],
                        ['type' => 'text', 'text' => self::PROMPT],
                    ],
                ]],
                outputConfig: [
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'found' => ['type' => 'boolean'],
                                'x' => ['type' => 'number'],
                                'y' => ['type' => 'number'],
                                'width' => ['type' => 'number'],
                                'height' => ['type' => 'number'],
                            ],
                            'required' => ['found', 'x', 'y', 'width', 'height'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            );

            $json = null;
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $json = json_decode($block->text, true);
                    break;
                }
            }

            return self::validate($json);
        } catch (\Throwable $e) {
            // Le récapitulatif est une transmission légale : une API muette ne
            // doit jamais faire plus que priver d'un cadrage plus serré.
            Log::warning('[fiche] détection du cadrage indisponible : '.$e->getMessage());

            return null;
        }
    }

    /**
     * Contrôle de vraisemblance, puis élargissement d'une marge.
     *
     * Séparé de l'appel réseau à dessein : c'est la partie qui décide si une
     * pièce va être rognée, et elle doit pouvoir être éprouvée sans appeler
     * quoi que ce soit.
     *
     * @param  mixed  $json
     * @return array{x:float,y:float,width:float,height:float}|null
     */
    public static function validate($json): ?array
    {
        if (!is_array($json) || ($json['found'] ?? false) !== true) {
            return null;
        }

        foreach (['x', 'y', 'width', 'height'] as $k) {
            if (!isset($json[$k]) || !is_numeric($json[$k])) {
                return null;
            }
        }

        $x = (float) $json['x'];
        $y = (float) $json['y'];
        $w = (float) $json['width'];
        $h = (float) $json['height'];

        // Rectangle dégénéré ou débordant : le modèle s'est trompé, on n'ira
        // pas découper une pièce d'identité sur cette base.
        if ($w <= 0 || $h <= 0 || $x < 0 || $y < 0 || $x + $w > 1.0001 || $y + $h > 1.0001) {
            return null;
        }

        // Trop petit pour être la pièce : plus probablement un tampon, ou la
        // photo d'identité imprimée SUR le document.
        if ($w * $h < (float) config('fiche.ai_crop.min_area', 0.05)) {
            return null;
        }

        $margin = (float) config('fiche.ai_crop.margin', 0.06);
        $mx = $w * $margin;
        $my = $h * $margin;

        $x0 = max(0.0, $x - $mx);
        $y0 = max(0.0, $y - $my);

        return [
            'x' => $x0,
            'y' => $y0,
            'width' => min(1.0 - $x0, $w + 2 * $mx),
            'height' => min(1.0 - $y0, $h + 2 * $my),
        ];
    }

    /**
     * Rectangle du scan, détecté au besoin puis mémorisé.
     *
     * `crop_detected_at` distingue « jamais tenté » de « tenté sans succès » :
     * sans lui, un document que le modèle n'arrive pas à situer serait resoumis
     * à chaque rendu, indéfiniment.
     *
     * @return array{x:float,y:float,width:float,height:float}|null
     */
    public function forScan(DocumentScan $scan, string $binary): ?array
    {
        if ($scan->crop_detected_at) {
            return $scan->crop_box;
        }

        $box = $this->detect($binary);

        // Écrit même quand la détection échoue : c'est ce qui empêche de
        // rappeler l'API à chaque rendu pour un document qu'elle ne sait pas
        // cadrer. Best-effort — un scan purgé entre-temps ne doit pas remonter.
        try {
            $scan->forceFill(['crop_box' => $box, 'crop_detected_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('[fiche] cadrage non mémorisé pour le scan '.$scan->id.' : '.$e->getMessage());
        }

        return $box;
    }
}
