<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Qayed — Fiche police</title>
    {{--
        Bundle Vite dédié, séparé de l'app principale (pas de router/store
        d'auth global embarqué — voir API-V1-DECISIONS.md). Construit par
        `frontend` (entrée widget-main.tsx) et publié sous ce chemin par le
        pipeline de déploiement.
    --}}
    <link rel="stylesheet" href="/widget-assets/widget-main.css">
</head>
<body>
    <div id="qayed-widget-root" data-widget-token="{{ $token }}"></div>
    <script type="module" src="/widget-assets/widget-main.js"></script>
</body>
</html>
