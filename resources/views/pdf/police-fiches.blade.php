<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
  * { box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; color: #1a1a1a; font-size: 12px; margin: 0; }
  .fiche { border: 1px solid #333; padding: 14px 16px; margin: 0 0 14px; page-break-inside: avoid; }
  .fiche h2 { margin: 0 0 2px; font-size: 13px; text-transform: uppercase; letter-spacing: .3px; }
  .fiche .sub { color: #555; font-size: 10px; margin: 0 0 10px; }
  table.f { width: 100%; border-collapse: collapse; }
  table.f td { padding: 3px 6px; vertical-align: top; }
  table.f td.k { color: #555; width: 32%; }
  table.f td.v { font-weight: bold; }
  .row2 td { width: 50%; }
  .foot { margin-top: 8px; font-size: 9px; color: #888; border-top: 1px solid #ddd; padding-top: 5px; }
  .cover { margin: 0 0 16px; }
  .cover h1 { font-size: 16px; margin: 0 0 2px; }
  .cover p { margin: 0; color: #555; font-size: 11px; }
</style>
</head>
<body>
  <div class="cover">
    <h1>Fiches de police — {{ $hotelName }}</h1>
    <p>{{ $hotelAddress }}</p>
    <p>Période : {{ $rangeLabel }} · {{ $count }} fiche(s) · Édité le {{ $generatedAt }}</p>
  </div>

  @foreach ($fiches as $f)
    <div class="fiche">
      <h2>Fiche de police</h2>
      <p class="sub">{{ $hotelName }} @if($f['room']) · Chambre {{ $f['room'] }} @endif · Réf. {{ $f['reference'] }}</p>
      <table class="f">
        <tr><td class="k">Nom / Prénom</td><td class="v">{{ $f['last_name'] }} {{ $f['first_name'] }}</td></tr>
        <tr class="row2">
          <td><table class="f"><tr><td class="k">Nationalité</td><td class="v">{{ $f['nationality'] }}</td></tr></table></td>
          <td><table class="f"><tr><td class="k">Sexe</td><td class="v">{{ $f['sex'] }}</td></tr></table></td>
        </tr>
        <tr class="row2">
          <td><table class="f"><tr><td class="k">Naissance</td><td class="v">{{ $f['dob'] }}</td></tr></table></td>
          <td><table class="f"><tr><td class="k">Lieu</td><td class="v">{{ $f['birth_place'] }}</td></tr></table></td>
        </tr>
        <tr><td class="k">Document</td><td class="v">{{ $f['document'] }}</td></tr>
        <tr class="row2">
          <td><table class="f"><tr><td class="k">Arrivée</td><td class="v">{{ $f['arrival'] }}</td></tr></table></td>
          <td><table class="f"><tr><td class="k">Départ prévu</td><td class="v">{{ $f['departure'] }}</td></tr></table></td>
        </tr>
        @if($f['companions'])
        <tr><td class="k">Accompagnants</td><td class="v">{{ $f['companions'] }}</td></tr>
        @endif
      </table>
      <div class="foot">Établi via Qayed — {{ $generatedAt }}</div>
    </div>
  @endforeach
</body>
</html>
