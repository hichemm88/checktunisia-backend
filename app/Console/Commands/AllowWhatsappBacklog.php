<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\WhatsappSendingGuard;
use Illuminate\Console\Command;

/**
 * Débloque, pour une durée bornée, l'envoi d'un arriéré retenu.
 *
 * Le garde-fou d'arriéré suspend l'envoi automatique dès qu'un nombre anormal
 * de fiches s'accumule : un arriéré n'est pas du travail en retard, c'est le
 * symptôme d'une panne, et le vider automatiquement est ce qui a coûté le
 * numéro émetteur précédent.
 *
 * Le déblocage est volontairement TEMPORAIRE. Une autorisation permanente
 * redeviendrait, au premier incident suivant, le comportement qu'on vient de
 * retirer — la retenue doit se réarmer toute seule.
 */
class AllowWhatsappBacklog extends Command
{
    protected $signature = 'whatsapp:allow-backlog {--minutes=60 : Durée du déblocage}';

    protected $description = 'Autorise temporairement l\'envoi de l\'arriéré WhatsApp retenu par le garde-fou.';

    public function handle(WhatsappSendingGuard $guard): int
    {
        $pending = $guard->pendingCount();
        $minutes = max(1, (int) $this->option('minutes'));

        $this->warn("{$pending} fiche(s) en attente vont pouvoir partir.");
        $this->line('Vérifier AVANT de confirmer : ces fiches concernent-elles des séjours en cours ?');
        $this->line('Pour un arriéré de séjours terminés, la bonne commande est whatsapp:cancel-backlog.');

        if (! $this->confirm('Débloquer l\'envoi pendant '.$minutes.' minute(s) ?', false)) {
            $this->info('Rien n\'a changé.');

            return self::SUCCESS;
        }

        $until = $guard->acknowledgeBacklog($minutes);

        $this->info('Envoi autorisé jusqu\'à '.$until->toDateTimeString().'.');
        $this->line('Le débit reste plafonné ('.config('whatsapp.guard.max_sends_per_minute').'/min, '
            .config('whatsapp.guard.max_sends_per_day').'/jour).');

        return self::SUCCESS;
    }
}
