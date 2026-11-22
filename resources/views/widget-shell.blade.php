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
    <style>html,body{margin:0;padding:0;height:100%;width:100%}#qayed-widget-root{height:100%;width:100%}</style>
    <link rel="stylesheet" href="/widget-assets/widget-main.css?v={{ $assetVersion ?? 0 }}">
</head>
<body>
    <div id="qayed-widget-root" data-widget-token="{{ $token }}"></div>
    <script type="module" src="/widget-assets/widget-main.js?v={{ $assetVersion ?? 0 }}"></script>
</body>
</html>
