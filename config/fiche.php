<?php

/*
|--------------------------------------------------------------------------
| Rendu des fiches de police
|--------------------------------------------------------------------------
|
| Réglages du cadrage des pièces d'identité, partagés par les trois chemins
| qui produisent une fiche : le relais WhatsApp (canal Cloud), l'export par
| établissement et le récapitulatif quotidien. Un seul endroit, faute de quoi
| une même pièce n'aurait pas la même tête selon la voie empruntée.
|
*/

return [

    /*
    | Cadre imposé aux pièces d'identité, en pixels.
    |
    | Les photos arrivent dans tous les formats : scan à plat en paysage, cliché
    | de téléphone en portrait, capture recadrée à la main. Sans cadre commun,
    | le PDF alterne vignettes minuscules et pavés pleine largeur, et se lit
    | mal. Toutes les pièces sont donc ramenées à ce format exact.
    |
    | 3:2 : proche du rapport d'une page de passeport (~1,42) comme d'une CIN
    | tunisienne, donc peu de vide autour dans le cas courant.
    */
    'photo_width' => (int) env('FICHE_PHOTO_WIDTH', 1200),
    'photo_height' => (int) env('FICHE_PHOTO_HEIGHT', 800),

    /*
    | Comment la pièce entre dans ce cadre.
    |
    | 'pad'   — la pièce tient ENTIÈRE dans le cadre, complétée par du blanc.
    |           Défaut, et le seul choix défendable par défaut : une fiche de
    |           police est une pièce d'identité transmise à l'autorité. Rogner
    |           peut couper un bord, un numéro, une bande MRZ — et l'autorité
    |           n'a aucun moyen de savoir qu'il manque quelque chose.
    |
    | 'cover' — la pièce remplit le cadre, le débord est rogné. Plus net à
    |           l'œil, tout le PDF au même format sans marge blanche. À réserver
    |           aux parcs où les photos sont déjà cadrées serré.
    */
    'photo_fit' => env('FICHE_PHOTO_FIT', 'pad'),

    // Qualité JPEG. Le PDF embarque en base64 (+33 %) : au-delà de 75, on
    // alourdit la pièce jointe sans gagner en lisibilité sur un document.
    'photo_quality' => (int) env('FICHE_PHOTO_QUALITY', 70),

    /*
    |--------------------------------------------------------------------------
    | Détection du document par modèle de vision
    |--------------------------------------------------------------------------
    |
    | Un cliché de téléphone montre rarement que la pièce : il y a la table, une
    | main, le bord d'un comptoir. Le cadrage géométrique ne sait pas distinguer
    | le document du décor et se contente donc de tout contenir — la pièce finit
    | petite au milieu de l'image.
    |
    | Le modèle, lui, sait où est le document. Il ne fait que le SITUER : il
    | renvoie un rectangle, le recadrage reste fait par nous, et rien de ce qu'il
    | répond n'est appliqué sans vérification (voir FicheScanCropper).
    |
    | Désactivé par défaut. C'est délibéré : le récapitulatif quotidien est la
    | voie de transmission légale des fiches, et on n'ajoute pas une dépendance
    | réseau sur ce chemin sans l'avoir vue fonctionner. À activer une fois un
    | rendu vérifié à l'œil.
    */
    'ai_crop' => [
        'enabled' => (bool) env('FICHE_AI_CROP', false),

        'api_key' => env('ANTHROPIC_API_KEY'),

        'model' => env('FICHE_AI_CROP_MODEL', 'claude-opus-5'),

        /*
        | Marge conservée autour du rectangle détecté, en fraction de sa taille.
        |
        | La détection est bonne, pas exacte au pixel. Un recadrage au ras du
        | rectangle rognerait tôt ou tard un bord de passeport ou une ligne de
        | MRZ — et sur une pièce transmise à l'autorité, rien ne signalerait le
        | manque. 6 % coûtent un peu de décor et évitent ça.
        */
        'margin' => (float) env('FICHE_AI_CROP_MARGIN', 0.06),

        /*
        | Aire minimale du rectangle détecté, en fraction de l'image. En dessous,
        | on considère que le modèle s'est trompé de sujet — un tampon, une photo
        | d'identité dans la photo — et on garde le cadrage géométrique.
        */
        'min_area' => (float) env('FICHE_AI_CROP_MIN_AREA', 0.05),

        // Côté long de l'image envoyée au modèle. Inutile de payer pour une
        // pleine résolution : situer un document n'en demande pas tant.
        'probe_size' => (int) env('FICHE_AI_CROP_PROBE_SIZE', 1024),
    ],

];
