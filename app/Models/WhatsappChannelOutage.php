<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une période pendant laquelle le canal des fiches ne pouvait rien émettre.
 *
 * Sert une seule question, et il faut qu'elle ait une réponse exacte :
 * « depuis que cette fiche est en file, combien de temps le canal a-t-il
 * réellement été capable de l'envoyer ? » C'est ce temps-là — et lui seul —
 * qui a le droit d'être compté dans les 24 h au bout desquelles un envoi est
 * abandonné.
 *
 * Une seule période ouverte à la fois : le canal est capable, ou il ne l'est
 * pas. Des périodes qui se chevauchent feraient compter deux fois le même
 * temps d'arrêt, ce qui rendrait des fiches immortelles.
 */
class WhatsappChannelOutage extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_channel_outages';

    protected $fillable = ['started_at', 'ended_at', 'reason'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** La période en cours, s'il y en a une. */
    public static function open(): ?self
    {
        return static::whereNull('ended_at')->orderByDesc('started_at')->first();
    }

    /**
     * Le canal ne peut pas émettre : ouvre une période, ou prolonge celle en
     * cours.
     *
     * Le motif de la période ouverte n'est PAS réécrit à chaque passe : c'est
     * la cause d'origine du blocage qui intéresse, pas la dernière formulation
     * — un blocage qui glisse de « modèle PENDING » à « arriéré retenu » a
     * commencé sur le modèle, et c'est cela qu'il faut pouvoir lire.
     */
    public static function begin(string $reason): self
    {
        return static::open() ?? static::create([
            'started_at' => now(),
            'ended_at' => null,
            'reason' => $reason,
        ]);
    }

    /** Le canal peut de nouveau émettre : referme la période en cours. */
    public static function end(): void
    {
        static::whereNull('ended_at')->update(['ended_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Minutes d'incapacité comprises dans l'intervalle [$from, $to].
     *
     * Une période encore ouverte est comptée jusqu'à $to : le canal est
     * toujours en panne à cet instant, il serait faux de ne rien compter.
     */
    public static function minutesOverlapping(Carbon $from, ?Carbon $to = null): int
    {
        $to = $to ?? now();

        if ($to->lte($from)) {
            return 0;
        }

        $seconds = 0;

        static::query()
            ->where('started_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->get()
            ->each(function (self $outage) use ($from, $to, &$seconds) {
                $start = $outage->started_at->max($from);
                $end = ($outage->ended_at ?? $to)->min($to);

                if ($end->gt($start)) {
                    // Horodatages bruts plutôt que diffInSeconds() : ce dernier
                    // est SIGNÉ en Carbon 3 et ne l'était pas en Carbon 2. Une
                    // durée d'arrêt comptée à l'envers ferait expirer les
                    // fiches plus vite, pas plus lentement.
                    $seconds += $end->getTimestamp() - $start->getTimestamp();
                }
            });

        return intdiv($seconds, 60);
    }

    /**
     * Élague le registre : au-delà de cette ancienneté, plus aucune fiche
     * vivante ne peut le chevaucher.
     */
    public static function purgeBefore(Carbon $before): int
    {
        return static::whereNotNull('ended_at')->where('ended_at', '<', $before)->delete();
    }
}
