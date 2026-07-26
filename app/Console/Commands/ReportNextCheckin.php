<?php

namespace App\Console\Commands;

use App\Mail\SystemMail;
use App\Models\AuthorityUserProfile;
use App\Models\CheckIn;
use App\Models\WhatsappSendLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Rapport « prochain check-in » à la demande de l'utilisateur : quand un
 * rapport est ARMÉ (Cache 'checkin_report.watch'), surveille le premier
 * check-in finalisé après l'armement, attend que ses envois WhatsApp se
 * stabilisent (max 20 min), envoie un rapport par email, puis se désarme.
 *
 * Armement (tinker) :
 *   Cache::forever('checkin_report.watch', ['email'=>'x@y.z','since'=>now()->toIso8601String()]);
 */
class ReportNextCheckin extends Command
{
    protected $signature = 'checkins:report-next';

    protected $description = 'Envoie un rapport email sur le prochain check-in (relais WhatsApp) si armé';

    private const KEY = 'checkin_report.watch';

    public function handle(): int
    {
        $state = Cache::get(self::KEY);
        if (! is_array($state) || empty($state['email']) || empty($state['since'])) {
            return self::SUCCESS; // non armé
        }

        $since = Carbon::parse($state['since']);

        // Le premier check-in finalisé APRÈS l'armement.
        $ci = CheckIn::with(['hotel', 'room', 'guests'])
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $since)
            ->whereIn('status', ['active', 'completed'])
            ->orderBy('completed_at')
            ->first();

        if (! $ci) {
            return self::SUCCESS; // rien encore, on repassera
        }

        $jobs = WhatsappSendLog::where('check_in_id', $ci->id)->where('is_test', false)->get();

        // Laisser les envois se terminer : si des jobs sont encore en attente et
        // que le check-in a moins de 20 min, on patiente (prochain tick).
        $pending = $jobs->where('status', 'pending')->count();
        if ($pending > 0 && now()->lt(Carbon::parse($ci->completed_at)->addMinutes(20))) {
            return self::SUCCESS;
        }

        Mail::to($state['email'])->send(new SystemMail(
            'Qayed — Rapport check-in : '.($ci->hotel?->name ?? 'Établissement'),
            $this->buildHtml($ci, $jobs),
        ));

        Cache::forget(self::KEY); // one-shot : désarmé
        $this->info("Rapport envoyé à {$state['email']} pour le check-in {$ci->id}.");

        return self::SUCCESS;
    }

    private function buildHtml(CheckIn $ci, $jobs): string
    {
        // Résolution numéro → nom d'agent (comme le journal admin).
        $byNumber = AuthorityUserProfile::whereNotNull('whatsapp_number')
            ->with('user:id,first_name,last_name')
            ->get()
            ->keyBy(fn ($p) => preg_replace('/\D+/', '', (string) $p->whatsapp_number));
        $recipientName = function (?string $jid) use ($byNumber): string {
            $d = preg_replace('/\D+/', '', (string) str_replace('@c.us', '', (string) $jid));
            $p = $byNumber[$d] ?? null;
            return $p ? trim(((string) $p->user?->first_name).' '.((string) $p->user?->last_name)) : ($d ?: '—');
        };

        $sent = $jobs->where('status', 'sent')->count();
        $failed = $jobs->where('status', 'failed')->count();
        $pending = $jobs->where('status', 'pending')->count();
        $cancelled = $jobs->where('status', 'cancelled')->count();
        $ok = $jobs->count() > 0 && $failed === 0 && $pending === 0 && $cancelled === 0;

        $banner = $ok
            ? '<div style="background:#e7f6ec;border:1px solid #1a7f4b;color:#1a7f4b;padding:12px 16px;border-radius:10px;font-weight:600">✅ Tout s\'est bien passé — toutes les fiches ont été envoyées.</div>'
            : '<div style="background:#fdf0f0;border:1px solid #c0392b;color:#c0392b;padding:12px 16px;border-radius:10px;font-weight:600">⚠️ À vérifier — certaines fiches ne sont pas parties correctement (détail ci-dessous).</div>';

        $rows = '';
        foreach ($jobs->groupBy('guest_id') as $guestId => $guestJobs) {
            $guest = $ci->guests->firstWhere('id', $guestId);
            $name = $guest ? strtoupper((string) $guest->last_name).' '.$guest->first_name : '—';
            foreach ($guestJobs as $j) {
                $statusLabel = [
                    'sent' => '<span style="color:#1a7f4b">Envoyée</span>',
                    'failed' => '<span style="color:#c0392b">Échec</span>',
                    'pending' => '<span style="color:#b7791f">En cours</span>',
                    'cancelled' => '<span style="color:#888">Annulée</span>',
                ][$j->status] ?? $j->status;
                $photo = $j->scan_id ? 'Oui' : 'Non';
                $rows .= '<tr>'
                    .'<td style="padding:6px 10px;border-bottom:1px solid #eee">'.e($name).'</td>'
                    .'<td style="padding:6px 10px;border-bottom:1px solid #eee">'.e($recipientName($j->recipient)).'</td>'
                    .'<td style="padding:6px 10px;border-bottom:1px solid #eee">'.$statusLabel.'</td>'
                    .'<td style="padding:6px 10px;border-bottom:1px solid #eee;text-align:center">'.$photo.'</td>'
                    .($j->last_error ? '<td style="padding:6px 10px;border-bottom:1px solid #eee;color:#c0392b;font-size:12px">'.e($j->last_error).'</td>' : '<td style="border-bottom:1px solid #eee"></td>')
                    .'</tr>';
            }
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" style="padding:10px;color:#888">Aucun envoi WhatsApp enregistré pour ce check-in.</td></tr>';
        }

        $room = $ci->room?->number ?? $ci->room?->name ?? '—';

        return '<div style="font-family:system-ui,Arial,sans-serif;max-width:640px;margin:0 auto;color:#222">'
            .'<h2 style="margin:0 0 4px">Rapport de check-in</h2>'
            .'<p style="color:#666;margin:0 0 16px">'.e($ci->hotel?->name ?? '—').' · Chambre '.e($room)
            .' · '.e((string) $ci->check_in_date).' → '.e((string) ($ci->expected_check_out_date ?? '?')).'</p>'
            .$banner
            .'<p style="margin:16px 0 6px"><strong>'.$jobs->count().'</strong> fiche(s) — '
            .$sent.' envoyée(s), '.$failed.' échec(s), '.$pending.' en cours, '.$cancelled.' annulée(s).</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px">'
            .'<thead><tr style="text-align:left;color:#666">'
            .'<th style="padding:6px 10px">Voyageur</th><th style="padding:6px 10px">Destinataire</th>'
            .'<th style="padding:6px 10px">Statut</th><th style="padding:6px 10px">Photo</th><th style="padding:6px 10px">Détail</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<p style="color:#999;font-size:12px;margin-top:20px">Rapport automatique Qayed — envoyé une seule fois pour le prochain check-in suivant votre demande.</p>'
            .'</div>';
    }
}
