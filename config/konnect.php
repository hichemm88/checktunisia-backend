<?php

/**
 * Konnect Payment Gateway — Tunisie
 *
 * Tableau de bord :
 *   simulation → https://dashboard.sandbox.konnect.network
 *   production → https://dashboard.konnect.network
 *
 * La clé d'API n'est affichée QU'UNE FOIS à sa création. Elle a la forme
 * « <idOrganisation>:<secret> » et voyage dans l'en-tête `x-api-key`.
 *
 * Les identifiants effectifs ne se lisent PAS ici : ils passent par
 * `PlatformSetting::konnectCredentials()` (saisie du back-office d'abord,
 * environnement en repli). Ce fichier ne porte que le repli et les réglages
 * non secrets.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Environnement
    |--------------------------------------------------------------------------
    | « sandbox » (simulation) ou « production ». C'est LUI qui choisit l'URL
    | de l'API : aucune base n'est saisie à la main, pour qu'on ne puisse pas
    | encaisser en simulation en croyant être en production.
    */
    'environment' => env('KONNECT_ENV', 'sandbox'),

    'base_urls' => [
        'sandbox'    => 'https://api.preprod.konnect.network/api/v2',
        'production' => 'https://api.konnect.network/api/v2',
    ],

    /*
    |--------------------------------------------------------------------------
    | Identifiants (repli d'environnement)
    |--------------------------------------------------------------------------
    */
    'api_key'   => env('KONNECT_API_KEY', ''),
    'wallet_id' => env('KONNECT_WALLET_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Page de paiement
    |--------------------------------------------------------------------------
    */
    'accepted_payment_methods' => ['wallet', 'bank_card', 'e-DINAR'],

    // Durée de validité du lien, en MINUTES (Konnect compte en minutes, là où
    // Flouci comptait en secondes). 15 min = l'ancien FLOUCI_SESSION_TIMEOUT.
    'lifespan_minutes' => (int) env('KONNECT_LIFESPAN_MINUTES', 15),

    // Formulaire de coordonnées sur la page Konnect. À false : on connaît déjà
    // le client, on préremplit — une saisie de moins avant de payer.
    'checkout_form' => (bool) env('KONNECT_CHECKOUT_FORM', false),

    // À true, les frais Konnect sont AJOUTÉS au montant : le client paie plus
    // que le TTC de sa facture et l'encaissement ne correspond plus à ce qui a
    // été facturé. Le défaut sûr est false — les frais restent à notre charge.
    'add_fees_to_amount' => (bool) env('KONNECT_ADD_FEES_TO_AMOUNT', false),

    /*
    |--------------------------------------------------------------------------
    | Retours navigateur
    |--------------------------------------------------------------------------
    | Konnect renvoie le client avec ?payment_ref=<référence>. Le front
    | transmet cette référence à GET /hotel/payments/{id}/verify, qui la
    | reconnaît au même titre que notre propre UUID.
    */
    'success_url' => env(
        'KONNECT_SUCCESS_URL',
        env('FRONTEND_URL', 'http://localhost:5173') . '/hotel/payment/success'
    ),
    'fail_url' => env(
        'KONNECT_FAIL_URL',
        env('FRONTEND_URL', 'http://localhost:5173') . '/hotel/payment/failed'
    ),

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    | Konnect prévient le serveur même si le client ferme son navigateur avant
    | de revenir — c'est ce que Flouci ne savait pas faire. Il n'y a AUCUNE
    | signature : le webhook est un réveil, pas une preuve. Le secret ci-dessous
    | est un segment de chemin qui rend l'URL non devinable ; la vérité, elle,
    | vient toujours d'un appel sortant vers Konnect.
    |
    | Vide = webhook fermé (404 sur toute requête). Jamais ouvert par défaut.
    */
    'webhook_token' => env('KONNECT_WEBHOOK_TOKEN', ''),

    // URL publique annoncée à Konnect. Par défaut construite depuis APP_URL —
    // en local, elle n'est pas joignable : passer par un tunnel et renseigner
    // KONNECT_WEBHOOK_URL.
    'webhook_url' => env('KONNECT_WEBHOOK_URL'),

];
