<?php

/*
|--------------------------------------------------------------------------
| WebAuthn / Passkeys
|--------------------------------------------------------------------------
|
| Deux valeurs commandent toute la sécurité de la cérémonie :
|
|   rp_id   Le domaine de la « partie de confiance ». Il doit être le domaine
|           du FRONTEND (celui qui appelle navigator.credentials.*), ou un
|           suffixe enregistrable de celui-ci. Une passkey créée pour
|           `qayed.tn` fonctionne sur `www.qayed.tn` ; l'inverse est faux.
|           Changer cette valeur INVALIDE toutes les passkeys existantes.
|
|   origins Les origines exactes autorisées à présenter une réponse. C'est ce
|           qui bloque le hameçonnage : une page servie depuis un autre
|           domaine produit un clientDataJSON dont l'origin ne figure pas ici,
|           et la vérification échoue — même si l'utilisateur s'est fait
|           piéger. À renseigner explicitement en production ; ne JAMAIS y
|           mettre de joker.
|
| Le backend (api.qayed.tn) et le frontend (qayed.tn) sont sur des hôtes
| différents : c'est sans effet, seul l'hôte du frontend compte.
|
*/

return [

    // Domaine de la partie de confiance. Repli : l'hôte de FRONTEND_URL.
    'rp_id' => env('WEBAUTHN_RP_ID') ?: parse_url((string) env('FRONTEND_URL', 'http://localhost:5173'), PHP_URL_HOST),

    // Nom affiché par le système au moment de Face ID / Touch ID / Windows Hello.
    'rp_name' => env('WEBAUTHN_RP_NAME', 'Qayed'),

    // Origines autorisées, séparées par des virgules. Repli : FRONTEND_URL.
    'origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) (env('WEBAUTHN_ORIGINS') ?: env('FRONTEND_URL', 'http://localhost:5173')))
    ))),

    /*
    | Vérification de l'utilisateur (biométrie, code de l'appareil, PIN de clé).
    |
    | `required` par défaut, et c'est délibéré : la passkey remplace ici le
    | couple mot de passe + TOTP. Sans UV, elle ne prouverait que la POSSESSION
    | de l'appareil ; avec UV, elle prouve aussi que c'est bien son porteur.
    | Face ID, Touch ID, Windows Hello et le verrouillage Android le font
    | toujours ; une clé de sécurité sans code sera refusée — le frontend
    | propose alors la connexion par mot de passe.
    */
    'user_verification' => env('WEBAUTHN_USER_VERIFICATION', 'required'),

    // Passkeys découvrables : indispensable pour « Continuer avec une passkey »
    // sans saisir d'e-mail, et pour le remplissage conditionnel du navigateur.
    'resident_key' => env('WEBAUTHN_RESIDENT_KEY', 'required'),

    // Durée de vie d'un challenge, en secondes. Assez long pour laisser le
    // temps d'approcher un téléphone en relais (QR code), assez court pour
    // que la fenêtre de rejeu reste étroite.
    'challenge_ttl' => (int) env('WEBAUTHN_CHALLENGE_TTL', 300),

    // Délai proposé au navigateur, en millisecondes.
    'timeout' => (int) env('WEBAUTHN_TIMEOUT', 120_000),

    // Nombre maximum de passkeys par compte : borne la table et l'écran de
    // gestion. iPhone + iPad + Mac + PC + clé de secours = 5 ; 10 laisse de
    // la marge sans permettre l'accumulation silencieuse d'accès oubliés.
    'max_credentials_per_user' => (int) env('WEBAUTHN_MAX_CREDENTIALS', 10),

    // Codes de récupération remis à l'activation de la première passkey.
    'recovery_codes_count' => (int) env('WEBAUTHN_RECOVERY_CODES', 10),

];
