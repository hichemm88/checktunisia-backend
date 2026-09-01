<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  * { box-sizing: border-box; }
  @page { margin: 22px 26px; }
  body { font-family: DejaVu Sans, sans-serif; color: #1f2937; font-size: 11px; margin: 0; }

  .brandbar { background: #10222E; color: #fff; padding: 16px 20px; border-radius: 8px; }
  .brandbar h1 { margin: 0; font-size: 18px; letter-spacing: .5px; }
  .brandbar .tag { margin: 2px 0 0; font-size: 10px; color: #8B7FE0; }
  .meta { padding: 10px 2px 4px; color: #6b7280; font-size: 10px; }
  .meta strong { color: #111827; }

  /* Sommaire : le destinataire retransmet à la main, souvent depuis un
     téléphone. Savoir d'un coup d'œil combien de fiches et pour quels
     établissements lui évite d'ouvrir tout le document pour le vérifier. */
  table.summary { width: 100%; border-collapse: collapse; margin: 10px 0 4px; }
  table.summary th { text-align: left; font-size: 9px; text-transform: uppercase;
                     letter-spacing: .5px; color: #9ca3af; padding: 4px 6px;
                     border-bottom: 1px solid #e5e7eb; }
  table.summary td { padding: 5px 6px; border-bottom: 1px solid #f3f4f6; }
  table.summary td.n { text-align: right; font-weight: bold; color: #10222E; }

  .hotel { margin-top: 16px; page-break-before: always; }
  .hotel:first-of-type { page-break-before: avoid; }
  .hotel h2 { font-size: 13px; color: #10222E; margin: 0 0 1px;
              border-bottom: 2px solid #5346A8; padding-bottom: 4px; }
  .hotel .addr { font-size: 9px; color: #9ca3af; margin: 3px 0 10px; }

  .fiche { border: 1px solid #e5e7eb; border-left: 3px solid #5346A8; border-radius: 6px;
           padding: 12px 14px; margin: 0 0 12px; page-break-inside: avoid; }
  .fiche .head { border-bottom: 1px solid #f0eefb; padding-bottom: 6px; margin-bottom: 8px; }
  .fiche .title { font-size: 9px; text-transform: uppercase; letter-spacing: .6px; color: #5346A8; font-weight: bold; }
  .fiche .name { font-size: 14px; font-weight: bold; color: #10222E; margin-top: 1px; }
  .fiche .sub { font-size: 9px; color: #9ca3af; margin-top: 1px; }

  table.f { width: 100%; border-collapse: collapse; }
  table.f td { padding: 2px 6px; vertical-align: top; }
  table.f td.k { color: #6b7280; width: 30%; font-size: 10px; }
  table.f td.v { font-weight: bold; color: #111827; }
  .half { width: 50%; }

  .scan { margin-top: 8px; padding-top: 7px; border-top: 1px solid #f0eefb; }
  .scan-label { font-size: 8px; text-transform: uppercase; letter-spacing: .5px;
                color: #9ca3af; margin-bottom: 3px; }
  /* Dimensions FIXES, pas des maxima. Les pièces sont déjà ramenées à un
     cadre commun côté serveur (FicheScanImage) ; les borner ici par des
     maxima laissait chaque photo à sa propre taille selon son format
     d'origine, ce qui donnait un document en accordéon. */
  .scan img { width: 300px; height: 200px; border: 1px solid #e5e7eb; border-radius: 4px; }
  /* Une pièce manquante doit se VOIR. Une fiche sans photo qui ne dit rien
     laisse croire que le voyageur n'en avait pas ; l'autorité doit pouvoir
     réclamer la pièce, donc savoir qu'elle manque. */
  .noscan { margin-top: 8px; padding-top: 7px; border-top: 1px solid #f0eefb;
            font-size: 9px; color: #b45309; }

  .foot { margin-top: 7px; font-size: 8px; color: #b6b6b6; }
  .empty { padding: 20px; text-align: center; color: #9ca3af; font-size: 12px; }
</style>
</head>
<body>
  <div class="brandbar">
    <h1>QAYED</h1>
    <p class="tag">Fiches de police — {{ $dateLabel }}</p>
  </div>
  <div class="meta">
    <strong>{{ $total }} fiche(s)</strong> &nbsp;·&nbsp;
    {{ count($groups) }} établissement(s) &nbsp;·&nbsp;
    Édité le {{ $generatedAt }}
  </div>

  @if ($total === 0)
    <div class="empty">Aucune arrivée enregistrée sur la période {{ $dateLabel }}.</div>
  @else
    <table class="summary">
      <tr><th>Établissement</th><th style="text-align:right">Fiches</th><th style="text-align:right">Avec pièce</th></tr>
      @foreach ($groups as $g)
        <tr>
          <td>{{ $g['name'] }}</td>
          <td class="n">{{ count($g['fiches']) }}</td>
          <td class="n">{{ $g['with_photo'] }}</td>
        </tr>
      @endforeach
    </table>

    @foreach ($groups as $g)
      <div class="hotel">
        <h2>{{ $g['name'] }}</h2>
        <div class="addr">{{ $g['address'] }}</div>

        @foreach ($g['fiches'] as $f)
          <div class="fiche">
            <div class="head">
              <div class="title">Fiche de police</div>
              <div class="name">{{ $f['last_name'] }} {{ $f['first_name'] }}</div>
              <div class="sub">{{ $g['name'] }}@if($f['room']) · Chambre {{ $f['room'] }}@endif · Réf. {{ $f['reference'] }}</div>
            </div>
            <table class="f">
              <tr>
                <td class="half"><table class="f"><tr><td class="k">Nationalité</td><td class="v">{{ $f['nationality'] }}</td></tr></table></td>
                <td class="half"><table class="f"><tr><td class="k">Sexe</td><td class="v">{{ $f['sex'] }}</td></tr></table></td>
              </tr>
              <tr>
                <td class="half"><table class="f"><tr><td class="k">Naissance</td><td class="v">{{ $f['dob'] }}</td></tr></table></td>
                <td class="half"><table class="f"><tr><td class="k">Lieu</td><td class="v">{{ $f['birth_place'] }}</td></tr></table></td>
              </tr>
              <tr><td colspan="2"><table class="f"><tr><td class="k" style="width:15%">Document</td><td class="v">{{ $f['document'] }}</td></tr></table></td></tr>
              <tr>
                <td class="half"><table class="f"><tr><td class="k">Arrivée</td><td class="v">{{ $f['arrival'] }}</td></tr></table></td>
                <td class="half"><table class="f"><tr><td class="k">Départ prévu</td><td class="v">{{ $f['departure'] }}</td></tr></table></td>
              </tr>
              @if($f['companions'])
              <tr><td colspan="2"><table class="f"><tr><td class="k" style="width:15%">Accompagnants</td><td class="v">{{ $f['companions'] }}</td></tr></table></td></tr>
              @endif
            </table>

            @if(!empty($f['photo']))
              <div class="scan">
                <div class="scan-label">Pièce d'identité</div>
                <img src="{{ $f['photo'] }}" alt="">
              </div>
            @else
              <div class="noscan">Pièce d'identité non disponible pour ce voyageur.</div>
            @endif
            <div class="foot">Établi via Qayed — {{ $generatedAt }}</div>
          </div>
        @endforeach
      </div>
    @endforeach
  @endif
</body>
</html>
