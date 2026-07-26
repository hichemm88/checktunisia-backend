<?php

namespace App\Services\Email;

/**
 * Habillage de marque Qayed pour les emails « bruts » (non gérés par les
 * templates admin) : export de fiches, alertes admin, rapport check-in.
 * En-tête encre + accent cachet, cohérent avec la facture PDF et l'app.
 */
class EmailBrand
{
    private const ENCRE = '#10222E';
    private const CACHET_CLAIR = '#8B7FE0';
    private const PAPIER = '#F6F5F1';

    public static function wrap(string $title, string $bodyHtml): string
    {
        $enc = self::ENCRE;
        $clair = self::CACHET_CLAIR;
        $papier = self::PAPIER;
        $year = date('Y');
        $head = $title !== ''
            ? '<h2 style="margin:0 0 14px;font-size:16px;color:'.$enc.'">'.e($title).'</h2>'
            : '';

        return '<div style="background:'.$papier.';padding:24px 12px;font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif">'
            .'<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e5e7eb">'
            .'<div style="background:'.$enc.';padding:20px 28px">'
            .'<div style="font-size:20px;font-weight:bold;color:#fff;letter-spacing:.5px">QAYED</div>'
            .'<div style="font-size:11px;color:'.$clair.';margin-top:2px">Enregistrement numérique des hôtes</div>'
            .'</div>'
            .'<div style="padding:24px 28px;color:#1f2937;font-size:14px;line-height:1.5">'.$head.$bodyHtml.'</div>'
            .'<div style="padding:14px 28px;border-top:1px solid #eee;font-size:11px;color:#9ca3af">Qayed · '.$year.'</div>'
            .'</div></div>';
    }
}
