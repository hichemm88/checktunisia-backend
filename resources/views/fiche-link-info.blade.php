{{--
  Page servie par /f/{token} quand WHATSAPP_FICHE_LINK_MODE=info.

  Elle n'affiche AUCUNE donnée de fiche et ne demande rien : pas de formulaire,
  pas de champ, pas de lien de connexion. C'est tout l'objet du mode — pendant
  la fenêtre où le bouton WhatsApp est cliquable mais le portail pas encore
  ouvert, la seule chose honnête à montrer est une phrase.

  Aucune ressource externe : ni police, ni feuille de style, ni image distante.
  Cette page s'ouvre dans le navigateur intégré de WhatsApp, souvent sur un
  réseau mobile médiocre, et elle doit s'afficher intégralement du premier coup.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Pas d'indexation : l'URL est publique mais elle n'a rien à faire dans
         un moteur de recherche. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>Qayed</title>
    <style>
        :root {
            --papier: #faf7f2;
            --encre: #1c1b19;
            --cachet: #8c2f2f;
            --gris: #6b6660;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--papier);
            color: var(--encre);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.55;
        }
        .carte {
            width: 100%;
            max-width: 420px;
            text-align: center;
        }
        /* Le logo est un carré arrondi : l'enfermer dans le cercle du sceau
           le ferait paraître de travers. La variante retire donc le cadre et
           laisse l'image parler. */
        .sceau--logo {
            width: 72px;
            height: auto;
            display: block;
            margin: 0 auto 20px;
            border: 0;
            border-radius: 0;
        }
        .sceau {
            width: 64px;
            height: 64px;
            margin: 0 auto 20px;
            border: 2px solid var(--cachet);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--cachet);
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.12em;
        }
        h1 {
            margin: 0 0 16px;
            font-size: 20px;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }
        p {
            margin: 0 0 14px;
            font-size: 15px;
            color: var(--gris);
        }
        footer {
            margin-top: 28px;
            font-size: 12px;
            color: var(--gris);
        }
    </style>
</head>
<body>
    <main class="carte">
        {{-- Même source que l'en-tête des PDF (App\Support\BrandLogo), et non
             une copie du fichier dans public/ : deux exemplaires d'un logo
             finissent toujours par diverger. Embarqué en base64 plutôt que
             servi par une seconde requête — la page est vue une fois, souvent
             sur un téléphone en mobilité.

             Absent, la lettre cerclée reprend sa place : cette page est ce que
             voit un policier qui clique depuis WhatsApp, elle ne doit jamais
             s'afficher amputée de son identification. --}}
        @if ($brandLogo)
            <img class="sceau sceau--logo" src="{{ $brandLogo }}" alt="Qayed">
        @else
            <div class="sceau" aria-hidden="true">Q</div>
        @endif
        <h1>Qayed</h1>
        <p>
            Cette fiche de police vous a été transmise par Qayed, plateforme
            d'enregistrement numérique des hôtes. L'accès au portail autorité
            sera ouvert prochainement.
        </p>
        <footer>SOCIETE UW AGENCY</footer>
    </main>
</body>
</html>
