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

];
