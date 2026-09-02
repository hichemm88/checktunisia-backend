<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Statut d'approbation, chez Meta, du modèle de message des fiches.
 *
 * Un modèle n'est pas une donnée de configuration comme une autre : c'est la
 * seule pièce de la chaîne dont l'état ne dépend pas de nous. Meta l'examine,
 * l'approbation prend de quelques minutes à quelques jours, et TANT QU'ELLE
 * N'EST PAS ACQUISE, chaque envoi est refusé en 132001 — « modèle inexistant
 * dans cette langue », le même code que pour un modèle réellement absent.
 *
 * C'est très exactement ce qui vient de se produire : WHATSAPP_SENDING_ENABLED
 * est passé à true alors que le modèle était encore PENDING. Le dispatcher a
 * essayé, échoué, consommé les tentatives de chaque fiche, puis abandonné au
 * bout de 24 h en marquant « échec définitif » des fiches qui n'avaient jamais
 * eu la moindre chance de partir — et en alertant sur chacune.
 *
 * D'où cette classe : le dispatcher DEMANDE d'abord si le modèle est approuvé,
 * et se tait si la réponse n'est pas oui.
 *
 * Trois règles, dans cet ordre d'importance :
 *
 *  1. À DÉFAUT D'INFO, ON NE TENTE PAS. Jeton refusé, WABA absent, Graph
 *     injoignable : le statut est « inconnu », et inconnu vaut « pas
 *     approuvé ». L'inverse — tenter quand on ne sait pas — est précisément le
 *     comportement qui a produit l'incident.
 *  2. LE STATUT EST EN CACHE. Une lecture Graph par fiche transformerait un
 *     garde-fou en source de latence et de limitation de débit. Rafraîchi
 *     toutes les 15 min ; un statut inconnu est réessayé bien plus vite, pour
 *     qu'une coupure réseau de deux minutes ne coûte pas un quart d'heure de
 *     file immobile.
 *  3. LE MODÈLE D'AUTHENTIFICATION (qayed_otp) N'EST PAS CONCERNÉ. Il est
 *     approuvé, il emprunte un autre chemin (WhatsappOtpSender), et fermer la
 *     porte du portail parce qu'un modèle de fiche attend une validation
 *     serait punir les agents d'un problème qui n'est pas le leur.
 */
class WhatsappTemplateStatus
{
    /** Durée de vie d'un statut connu. */
    public const REFRESH_MINUTES = 15;

    /**
     * Durée de vie d'un statut INCONNU.
     *
     * Volontairement courte : « je ne sais pas » bloque la file, et une panne
     * réseau de trente secondes ne doit pas coûter quinze minutes d'arrêt.
     */
    private const UNKNOWN_TTL_MINUTES = 2;

    private const KEY = 'whatsapp:template_status';

    public function __construct(private WhatsappCloudApi $api) {}

    /** Nom du modèle de fiche effectivement configuré. */
    public function ficheName(): string
    {
        return (string) config('whatsapp.cloud.template.name');
    }

    public function ficheLanguage(): string
    {
        return (string) config('whatsapp.cloud.template.language');
    }

    /**
     * Statut Meta du modèle de fiche : APPROVED, PENDING, REJECTED…
     * ou null quand nous n'avons pas pu le savoir.
     */
    public function ficheStatus(): ?string
    {
        return $this->entry()['status'];
    }

    public function ficheApproved(): bool
    {
        return $this->ficheStatus() === 'APPROVED';
    }

    /**
     * Pourquoi le modèle interdit-il d'envoyer ? Null s'il est approuvé.
     *
     * La phrase est destinée au journal et à l'écran d'administration : elle
     * doit dire QUOI FAIRE, pas seulement que c'est bloqué.
     */
    public function blockingReason(): ?string
    {
        $entry = $this->entry();
        $model = $this->ficheName().' ('.$this->ficheLanguage().')';

        return match ($entry['status']) {
            'APPROVED' => null,
            null => 'Statut du modèle '.$model.' inconnu : '.($entry['error'] ?? 'lecture impossible')
                .'. Aucune fiche n\'est tentée tant que l\'approbation n\'est pas confirmée — '
                .'les fiches restent en file, rien n\'est perdu.',
            'PENDING' => 'En attente d\'approbation du modèle '.$model.' chez Meta. '
                .'Les fiches restent en file et partiront dès l\'approbation.',
            default => 'Modèle '.$model.' en statut '.$entry['status'].' chez Meta — '
                .'aucune fiche ne peut partir. Vérifier avec « php artisan whatsapp:templates ».',
        };
    }

    /** Instant de la dernière lecture réussie ou tentée. */
    public function checkedAt(): ?Carbon
    {
        $at = $this->entry()['checked_at'] ?? null;

        return filled($at) ? Carbon::parse($at) : null;
    }

    /**
     * Ce que l'écran d'administration a besoin d'afficher.
     *
     * @return array{name:string, language:string, status:?string, approved:bool, checked_at:?string, error:?string}
     */
    public function snapshot(): array
    {
        $entry = $this->entry();

        return [
            'name' => $this->ficheName(),
            'language' => $this->ficheLanguage(),
            'status' => $entry['status'],
            'approved' => $entry['status'] === 'APPROVED',
            'checked_at' => $entry['checked_at'] ?? null,
            'error' => $entry['error'] ?? null,
        ];
    }

    /**
     * Oublie le statut mémorisé : la prochaine question ira le redemander.
     *
     * Appelé quand Meta vient de nous contredire (132001 sur un modèle que
     * nous croyions approuvé) : le cache est alors la dernière chose à
     * laquelle il faut faire confiance.
     */
    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * Mémorise un statut lu ailleurs.
     *
     * `php artisan whatsapp:templates` interroge déjà Meta ; sans cette
     * méthode, l'exploitant qui vient de LIRE « APPROVED » à l'écran devrait
     * quand même attendre l'expiration du cache pour que les fiches repartent.
     * Un quart d'heure d'attente inexpliquée après une bonne nouvelle est
     * exactement le genre de détail qui fait relancer les choses à la main.
     */
    public function remember(?string $status, ?string $error = null): void
    {
        Cache::put(
            $this->cacheKey(),
            ['status' => $status, 'error' => $error, 'checked_at' => now()->toIso8601String()],
            now()->addMinutes($status === null ? self::UNKNOWN_TTL_MINUTES : self::REFRESH_MINUTES),
        );
    }

    /**
     * Statut mémorisé, ou lecture chez Meta si le cache est vide/expiré.
     *
     * @return array{status:?string, error:?string, checked_at:?string}
     */
    private function entry(): array
    {
        $cached = Cache::get($this->cacheKey());

        if (is_array($cached)) {
            return $cached + ['status' => null, 'error' => null, 'checked_at' => null];
        }

        $fresh = $this->read();

        Cache::put(
            $this->cacheKey(),
            $fresh,
            now()->addMinutes($fresh['status'] === null ? self::UNKNOWN_TTL_MINUTES : self::REFRESH_MINUTES),
        );

        return $fresh;
    }

    /**
     * Interroge Meta. Ne lève jamais : un garde-fou qui plante bloquerait la
     * file par accident au lieu de la bloquer par décision.
     *
     * @return array{status:?string, error:?string, checked_at:?string}
     */
    private function read(): array
    {
        $now = now()->toIso8601String();

        if (! $this->api->canManageTemplates()) {
            // Sans WABA ni jeton, on ne peut pas lire les modèles — et donc
            // pas garantir qu'un envoi ne partira pas dans le vide. Le nom de
            // la variable est dans le message : sans lui, l'exploitant voit
            // « bloqué » sans savoir quoi poser.
            return [
                'status' => null,
                'error' => 'WHATSAPP_WABA_ID ou WHATSAPP_API_TOKEN absent — statut des modèles illisible',
                'checked_at' => $now,
            ];
        }

        try {
            $templates = $this->api->listTemplates($this->ficheName());
        } catch (\Throwable $e) {
            Log::warning('[whatsapp-template] statut illisible : '.$e->getMessage());

            return ['status' => null, 'error' => $e->getMessage(), 'checked_at' => $now];
        }

        $found = $templates[$this->ficheName().':'.$this->ficheLanguage()] ?? null;

        if ($found === null) {
            /*
             * Absent DANS CETTE LANGUE. Meta rend le même 132001 pour un
             * modèle jamais soumis et pour un modèle approuvé dans une autre
             * langue ; distinguer les deux ici épargne une seconde soumission
             * et l'attente d'approbation qui va avec.
             */
            $others = collect($templates)
                ->filter(fn ($t) => ($t['name'] ?? null) === $this->ficheName())
                ->pluck('language')
                ->all();

            return [
                'status' => 'ABSENT',
                'error' => $others === []
                    ? 'Modèle non déclaré chez Meta.'
                    : 'Modèle déclaré en ['.implode(', ', $others).'] mais pas en « '.$this->ficheLanguage().' ».',
                'checked_at' => $now,
            ];
        }

        return [
            'status' => (string) ($found['status'] ?? 'INCONNU'),
            'error' => $found['rejected_reason'] ?? null,
            'checked_at' => $now,
        ];
    }

    /**
     * La clé porte le nom ET la langue : changer WHATSAPP_TEMPLATE_NAME doit
     * invalider le statut, sans quoi le nouveau modèle hériterait de
     * l'approbation de l'ancien pendant un quart d'heure.
     */
    private function cacheKey(): string
    {
        return self::KEY.':'.$this->ficheName().':'.$this->ficheLanguage();
    }
}
