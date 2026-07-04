<?php

namespace App\Http\Controllers\Authority;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\Guest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExportController extends Controller
{
    /**
     * Export a single guest profile as a simple HTML-based printable page.
     * Uses no external PDF library — browser print / wkhtmltopdf friendly.
     */
    public function guestPdf(Request $request, string $id): Response
    {
        $guest = Guest::with(['documents', 'checkIns.hotel.address'])->findOrFail($id);

        $docsHtml = '';
        foreach ($guest->documents as $doc) {
            $expiry = $doc->expiry_date ? date('d/m/Y', strtotime($doc->expiry_date)) : '—';
            $docsHtml .= "<tr>
                <td>{$doc->type}</td>
                <td>{$doc->document_number}</td>
                <td>{$doc->issuing_country_code}</td>
                <td>{$expiry}</td>
            </tr>";
        }

        $staysHtml = '';
        foreach ($guest->checkIns->sortByDesc('check_in_date')->take(20) as $ci) {
            $hotel     = $ci->hotel?->name ?? '—';
            $city      = $ci->hotel?->address?->city ?? '';
            $checkIn   = $ci->check_in_date  ? date('d/m/Y', strtotime($ci->check_in_date))           : '—';
            $checkOut  = $ci->actual_check_out_date ? date('d/m/Y', strtotime($ci->actual_check_out_date)) : ($ci->expected_check_out_date ? date('d/m/Y', strtotime($ci->expected_check_out_date)) . '*' : '—');
            $staysHtml .= "<tr>
                <td>{$hotel}</td>
                <td>{$city}</td>
                <td>{$checkIn}</td>
                <td>{$checkOut}</td>
            </tr>";
        }

        $dob  = $guest->date_of_birth  ? date('d/m/Y', strtotime($guest->date_of_birth))  : '—';
        $sex  = match($guest->sex) { 'M' => 'Masculin', 'F' => 'Féminin', default => '—' };
        $name = strtoupper("{$guest->last_name} {$guest->first_name}");
        $date = now()->format('d/m/Y H:i');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Fiche Voyageur — {$name}</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 20px; }
  h1 { font-size: 18px; color: #1B3A5F; margin-bottom: 4px; }
  .subtitle { color: #666; font-size: 11px; margin-bottom: 20px; }
  .section { margin-bottom: 20px; }
  .section-title { font-size: 13px; font-weight: bold; color: #1B3A5F; border-bottom: 2px solid #1B3A5F; margin-bottom: 8px; padding-bottom: 4px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .field label { color: #666; font-size: 10px; text-transform: uppercase; }
  .field span { display: block; font-weight: bold; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #1B3A5F; color: white; padding: 6px 8px; text-align: left; font-size: 11px; }
  td { padding: 5px 8px; border-bottom: 1px solid #eee; }
  tr:nth-child(even) td { background: #f9f9f9; }
  .footer { margin-top: 30px; font-size: 10px; color: #999; border-top: 1px solid #eee; padding-top: 10px; }
  @media print { body { margin: 0; } }
</style>
</head>
<body>
<h1>Qayed — Fiche Voyageur</h1>
<p class="subtitle">Document confidentiel — Usage officiel uniquement — Généré le {$date}</p>

<div class="section">
  <div class="section-title">Identité</div>
  <div class="grid">
    <div class="field"><label>Nom complet</label><span>{$name}</span></div>
    <div class="field"><label>Date de naissance</label><span>{$dob}</span></div>
    <div class="field"><label>Sexe</label><span>{$sex}</span></div>
    <div class="field"><label>Nationalité</label><span>{$guest->nationality_code}</span></div>
  </div>
</div>

<div class="section">
  <div class="section-title">Documents de voyage</div>
  <table>
    <thead><tr><th>Type</th><th>Numéro</th><th>Pays émetteur</th><th>Expiration</th></tr></thead>
    <tbody>{$docsHtml}</tbody>
  </table>
</div>

<div class="section">
  <div class="section-title">Historique des séjours (20 derniers)</div>
  <table>
    <thead><tr><th>Hôtel</th><th>Ville</th><th>Arrivée</th><th>Départ</th></tr></thead>
    <tbody>{$staysHtml}</tbody>
  </table>
  <p style="font-size:10px;color:#999;margin-top:4px;">* date de départ prévue</p>
</div>

<div class="footer">
  Généré par Qayed — Plateforme de contrôle hôtelier — Confidentiel
</div>
</body>
</html>
HTML;

        return response($html, 200, [
            'Content-Type'        => 'text/html; charset=UTF-8',
            'Content-Disposition' => "inline; filename=\"fiche-voyageur-{$guest->id}.html\"",
        ]);
    }

    /**
     * Export stays as CSV (scoped to authority's zone for police, all for ministry).
     */
    public function staysCsv(Request $request): Response
    {
        $profile  = $request->user()->authorityProfile;
        $isPolice = $profile?->organization?->type === 'police';

        $query = CheckIn::with(['hotel.address', 'guests.documents'])
            ->whereIn('status', ['active', 'completed'])
            ->orderByDesc('check_in_date');

        if ($isPolice && $profile->organization?->governorate) {
            $gov = $profile->organization->governorate;
            $query->whereHas('hotel.address', fn($q) => $q->where('governorate', $gov));
        }

        if ($request->filled('from')) $query->whereDate('check_in_date', '>=', $request->from);
        if ($request->filled('to'))   $query->whereDate('check_in_date', '<=', $request->to);

        $rows = $query->limit(5000)->get();

        $csv  = "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
        $csv .= "ID Check-in,Hôtel,Ville,Gouvernorat,Arrivée,Départ prévu,Départ réel,Statut,";
        $csv .= "Prénom voyageur,Nom voyageur,Naissance,Sexe,Nationalité,N° Document,Type doc\n";

        foreach ($rows as $ci) {
            $hotel = $ci->hotel;
            $addr  = $hotel?->address;
            $guest = $ci->guests->firstWhere('pivot.is_primary', true) ?? $ci->guests->first();
            $doc   = $guest?->documents->first();

            $csv .= implode(',', array_map(
                fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"',
                [
                    $ci->id,
                    $hotel?->name,
                    $addr?->city,
                    $addr?->governorate,
                    $ci->check_in_date,
                    $ci->expected_check_out_date,
                    $ci->actual_check_out_date,
                    $ci->status,
                    $guest?->first_name,
                    $guest?->last_name,
                    $guest?->date_of_birth,
                    $guest?->sex,
                    $guest?->nationality_code,
                    $doc?->document_number,
                    $doc?->type,
                ],
            )) . "\n";
        }

        $filename = 'sejours-' . now()->format('Y-m-d') . '.csv';

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
