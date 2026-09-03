<?php

namespace App\Services\Auth;

use App\Models\AuthorityUserProfile;
use App\Models\User;
use App\Models\WhatsappOtpCode;
use App\Services\Audit\AuditLogger;
use App\Services\Whatsapp\WhatsappOtpSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Connexion d'un agent autorité par code reçu sur WhatsApp.
 *
 * ── Le problème que ce chemin résout ────────────────────────────────────
 *
 * Un agent (policier, officiel) est enregistré dans le backoffice avec un nom
 * et un numéro WhatsApp. Son adresse e-mail est FICTIVE — elle n'existe que
 * pour satisfaire la colonne `users.email`. Il ne peut donc ni activer un mot
 * de passe, ni recevoir un lien de connexion, ni suivre une réinitialisation.
 * Le seul facteur qu'il possède réellement, c'est le téléphone sur lequel la
 * fiche vient d'arriver.
 *
 * ── Ce qui rend ce chemin sûr ───────────────────────────────────────────
 *
 * Un code à 6 chiffres envoyé sur demande est, en soi, un mécanisme faible :
 * 10⁶ possibilités, et un canal (WhatsApp) sur lequel on n'a aucune prise.
 * Cinq règles le rendent acceptable, et elles se tiennent toutes :
 *
 *  1. AUCUN envoi vers un numéro saisi librement. Le numéro doit déjà être
 *     enregistré par l'admin comme destinataire de fiches. Le formulaire ne
 *     choisit pas la destination : il désigne un enregistrement existant.
 *  2. Réponse et DÉLAI identiques que le numéro soit éligible ou non. Sans
 *     cela, l'endpoint devient un annuaire : « ce numéro est-il celui d'un
 *     policier ? » — exactement la question à laquelle il ne doit jamais
 *     répondre.
 *  3. Trois essais par code, puis verrouillage du numéro. C'est ce qui ramène
 *     10⁶ possibilités à 3 tentatives.
 *  4. Trois demandes par fenêtre, par numéro ET par IP. Sans cela, on
 *     contourne (3) en redemandant un code à chaque essai.
 *  5. Le code n'est stocké que haché, et n'est utilisable qu'une fois.
 *
 * Retirer une seule de ces règles rend les quatre autres décoratives.
 */
class WhatsappOtpService
{
    /**
     * Délai plancher d'une demande, en millisecondes.
     *
     * Une réponse identique ne suffit pas à fermer l'oracle : le TEMPS parle.
     * Un numéro éligible déclenche un appel HTTP à Meta (quelques centaines de
     * millisecondes) et un hachage bcrypt ; un numéro inconnu ne déclenche
     * rien et répondrait en quelques millisecondes. La différence se mesure
     * depuis n'importe quel navigateur.
     *
     * Toute demande dure donc au moins ce temps-là, quel qu'en soit le sort.
     */
    private const MIN_RESPONSE_MS = 700;

    public function __construct(private WhatsappOtpSender $sender) {}

    /**
     * Normalise un numéro : chiffres seuls, international, sans « + ».
     *
     * Même règle que `WhatsAppCloudChannel::formatRecipient()` — la forme
     * stockée par l'admin, celle des envois, et celle des demandes de code
     * doivent être la MÊME, sinon un agent parfaitement enregistré se voit
     * refuser un code sans que rien ne l'explique.
     */
    public function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen((string) $digits) >= 8 ? $digits : null;
    }

    /**
     * Traite une demande de code.
     *
     * Ne renvoie RIEN d'exploitable par l'appelant, volontairement : le
     * contrôleur ne doit pas pouvoir laisser filtrer, même par accident, si le
     * numéro était connu. Tout ce qui s'est passé est dans le journal.
     */
    public function request(?string $rawPhone, ?string $ip): void
    {
        $startedAt = microtime(true);

        try {
            $this->handleRequest($rawPhone, $ip);
        } catch (\Throwable $e) {
            // Une panne ne doit pas devenir un oracle non plus : un numéro
            // inconnu ne peut pas faire échouer l'envoi, donc une 500 sur
            // demande dirait « ce numéro-là existe ».
            Log::error('[whatsapp-otp] demande interrompue : '.$e->getMessage());
        }

        $this->padDuration($startedAt);
    }

    /**
     * Vérifie un code et renvoie le compte à connecter, ou null.
     *
     * Un seul retour d'échec, quelle qu'en soit la cause (code faux, périmé,
     * déjà utilisé, numéro verrouillé, numéro inconnu) : distinguer « code
     * expiré » de « code faux » apprend à un attaquant qu'il vise un numéro
     * réel et qu'il lui suffit d'en redemander un.
     */
    public function verify(?string $rawPhone, ?string $code, ?string $ip): ?User
    {
        $phone = $this->normalize($rawPhone);

        if ($phone === null || ! is_string($code) || ! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        if ($this->lockedUntil($phone) !== null) {
            $this->journal('authority.otp_verify_locked', $phone, $ip);

            return null;
        }

        $entry = WhatsappOtpCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($entry === null) {
            $this->journal('authority.otp_verify_failed', $phone, $ip, ['reason' => 'no_active_code']);

            return null;
        }

        /*
         * `Hash::check` est à temps constant sur le hachage lui-même — c'est
         * la propriété de bcrypt/argon, pas quelque chose à réimplémenter ici.
         * Le comparateur naïf (`===` sur des chaînes) fuiterait la longueur du
         * préfixe commun ; il n'apparaît nulle part sur ce chemin.
         */
        if (! Hash::check($code, $entry->code_hash)) {
            $entry->increment('attempts');

            if ($entry->attempts >= (int) config('whatsapp.otp.max_attempts', 3)) {
                // Verrou porté par la ligne du code fautif, et code neutralisé
                // au passage : redemander un code ne contourne pas le verrou,
                // puisque celui-ci est cherché par NUMÉRO.
                $entry->forceFill([
                    'locked_until' => now()->addMinutes((int) config('whatsapp.otp.lockout_minutes', 15)),
                    'consumed_at' => now(),
                ])->save();

                $this->journal('authority.otp_locked', $phone, $ip, [
                    'lockout_minutes' => (int) config('whatsapp.otp.lockout_minutes', 15),
                ]);

                return null;
            }

            $this->journal('authority.otp_verify_failed', $phone, $ip, [
                'attempts' => $entry->attempts,
            ]);

            return null;
        }

        $user = $entry->user()->first();

        // Le compte a pu être suspendu, supprimé ou son habilitation expirer
        // entre l'envoi du code et sa saisie. Le code est valide ; le compte,
        // lui, ne l'est plus.
        if ($user === null || ! $this->accountUsable($user)) {
            $entry->forceFill(['consumed_at' => now()])->save();
            $this->journal('authority.otp_verify_failed', $phone, $ip, ['reason' => 'account_unusable']);

            return null;
        }

        /*
         * CONSOMMATION ATOMIQUE — c'est la base qui arbitre, pas une lecture
         * suivie d'une ecriture.
         *
         * Entre le SELECT du code et son marquage, il y a un `Hash::check` :
         * bcrypt, deliberement lent, une centaine de millisecondes. La fenetre
         * n'est donc pas theorique — deux verifications arrivees dans cet
         * intervalle voyaient toutes les deux un code non consomme, le
         * marquaient toutes les deux, et ouvraient DEUX sessions avec une clef
         * a usage unique.
         *
         * Ce que cette garantie protege : un code intercepte (epaule, appareil
         * partage, compte WhatsApp compromis) ne doit servir qu'une fois. Si
         * l'agent legitime l'utilise, l'attaquant qui court en parallele doit
         * repartir les mains vides — c'est tout ce qui limite les degats apres
         * une fuite de code.
         *
         * Meme motif que `WhatsappCostRecorder::recordDelivered()` : un UPDATE
         * conditionnel, et on regarde le nombre de lignes affectees.
         */
        $claimed = WhatsappOtpCode::whereKey($entry->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($claimed === 0) {
            // Une autre requete a gagne la course : ce code est deja depense.
            $this->journal('authority.otp_verify_failed', $phone, $ip, ['reason' => 'already_consumed']);

            return null;
        }

        // Tout autre code encore vivant sur ce numéro tombe : deux demandes
        // successives ne doivent pas laisser deux clés en circulation.
        WhatsappOtpCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('id', '!=', $entry->id)
            ->update(['consumed_at' => now()]);

        return $user;
    }

    /**
     * Instant de fin de verrouillage du numéro, ou null s'il est ouvert.
     */
    public function lockedUntil(string $phone): ?Carbon
    {
        $locked = WhatsappOtpCode::where('phone', $phone)
            ->whereNotNull('locked_until')
            ->where('locked_until', '>', now())
            ->max('locked_until');

        return $locked === null ? null : Carbon::parse($locked);
    }

    /**
     * Le profil autorité correspondant à ce numéro, s'il est éligible.
     *
     * Deux conditions cumulatives, et c'est le cœur de la règle :
     *
     *  - un compte autorité ACTIF (non supprimé, non suspendu, habilitation
     *    non expirée) ;
     *  - un numéro déjà enregistré par l'admin comme destinataire de fiches —
     *    l'interrupteur `receives_whatsapp_fiches` ET un rattachement à au
     *    moins un établissement. C'est exactement l'ensemble des numéros vers
     *    lesquels une fiche part déjà (voir `WhatsAppCloudChannel::recipientsFor`).
     *
     * Le rattachement est exigé à dessein : sans lui, un profil créé avec un
     * numéro mais jamais coché par aucun établissement — donc un agent que le
     * produit ne reconnaît pas encore comme destinataire — deviendrait
     * néanmoins joignable. On n'envoie jamais un code sur un numéro sur lequel
     * on n'envoie pas déjà des fiches.
     */
    public function eligibleProfile(string $phone): ?AuthorityUserProfile
    {
        $profile = AuthorityUserProfile::query()
            ->where('whatsapp_number', $phone)
            ->where('receives_whatsapp_fiches', true)
            ->whereHas('user', fn ($q) => $q->where('status', 'active')->whereNull('deleted_at'))
            ->whereHas('hotels')
            ->with('user')
            ->first();

        if ($profile === null || $profile->isExpired()) {
            return null;
        }

        return $profile;
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    private function handleRequest(?string $rawPhone, ?string $ip): void
    {
        $phone = $this->normalize($rawPhone);

        if ($phone === null) {
            $this->journal('authority.otp_requested', null, $ip, ['eligible' => false, 'sent' => false, 'reason' => 'malformed']);

            return;
        }

        if (! $this->consumeRequestBudget($phone, $ip)) {
            $this->journal('authority.otp_requested', $phone, $ip, [
                'eligible' => null, 'sent' => false, 'reason' => 'rate_limited',
            ]);

            return;
        }

        // Un numéro verrouillé ne redemande pas de code : sans cela, le verrou
        // de 15 minutes se contourne en cliquant « renvoyer ».
        if ($this->lockedUntil($phone) !== null) {
            $this->journal('authority.otp_requested', $phone, $ip, [
                'eligible' => null, 'sent' => false, 'reason' => 'locked',
            ]);

            return;
        }

        $profile = $this->eligibleProfile($phone);

        if ($profile === null) {
            $this->journal('authority.otp_requested', $phone, $ip, ['eligible' => false, 'sent' => false]);

            return;
        }

        // `random_int` et non `rand`/`mt_rand` : ces derniers sont prédictibles
        // à partir de quelques tirages, ce qui suffirait à deviner le code
        // suivant sans jamais l'essayer.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // La ligne est écrite AVANT l'envoi : un envoi accepté par Meta dont on
        // n'aurait pas gardé l'empreinte donnerait un code que personne ne peut
        // plus valider.
        $entry = WhatsappOtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'user_id' => $profile->user_id,
            'expires_at' => now()->addMinutes((int) config('whatsapp.otp.ttl_minutes', 5)),
        ]);

        $result = $this->sender->send($phone, $code);

        // `$code` a fini son voyage. Rien en dessous de cette ligne ne doit le
        // toucher — ni journal, ni réponse, ni exception.
        unset($code);

        if (! $result->success) {
            // Code inutilisable : Meta ne l'a pas transmis. Le neutraliser
            // évite qu'il occupe la place du prochain.
            $entry->forceFill(['consumed_at' => now()])->save();

            Log::warning('[whatsapp-otp] envoi refusé', [
                'to' => $this->mask($phone),
                'code' => $result->errorCode,
                'error' => $result->error,
            ]);

            $this->journal('authority.otp_requested', $phone, $ip, [
                'eligible' => true, 'sent' => false, 'meta_error' => $result->errorCode,
            ]);

            return;
        }

        $this->journal('authority.otp_requested', $phone, $ip, ['eligible' => true, 'sent' => true]);
    }

    /**
     * Consomme un jeton sur les DEUX limiteurs — par numéro et par IP.
     *
     * Deux limiteurs distincts, et non un seul indexé sur le couple : indexer
     * sur (numéro, IP) laisserait marteler un même numéro depuis autant d'IP
     * qu'on veut, ce qui est précisément le mode opératoire à empêcher.
     *
     * Les deux sont consommés même quand le premier refuse : sinon un numéro
     * bloqué rendrait les demandes gratuites du point de vue de l'IP.
     */
    private function consumeRequestBudget(string $phone, ?string $ip): bool
    {
        $max = (int) config('whatsapp.otp.max_requests', 3);
        $window = (int) config('whatsapp.otp.request_window_minutes', 10) * 60;

        $byPhone = RateLimiter::attempt('otp-request:phone:'.hash('sha256', $phone), $max, fn () => true, $window);
        $byIp = RateLimiter::attempt('otp-request:ip:'.($ip ?? 'unknown'), $max, fn () => true, $window);

        return $byPhone !== false && $byIp !== false;
    }

    private function accountUsable(User $user): bool
    {
        if ($user->status !== 'active' || $user->deleted_at !== null) {
            return false;
        }

        $profile = $user->authorityProfile()->first();

        return $profile !== null && ! $profile->isExpired();
    }

    /**
     * Journal d'activité. Le numéro y figure MASQUÉ et le code jamais : ces
     * lignes servent à recouper un incident, pas à reconstituer l'annuaire des
     * agents à partir des journaux.
     *
     * @param  array<string,mixed>  $context
     */
    private function journal(string $action, ?string $phone, ?string $ip, array $context = []): void
    {
        AuditLogger::log($action, newValues: array_merge($context, [
            'phone' => $this->mask($phone),
            'ip' => $ip,
        ]));
    }

    /** « 216 9* *** **0 » — assez pour recouper, pas pour composer. */
    private function mask(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        $length = strlen($phone);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($phone, 0, 3).str_repeat('*', $length - 4).substr($phone, -1);
    }

    /**
     * Complète la durée de la requête jusqu'au plancher.
     *
     * `usleep` bloque le worker : c'est le prix, assumé, de la fermeture de
     * l'oracle temporel. La route est limitée à quelques appels par minute,
     * l'impact sur la capacité est nul.
     */
    private function padDuration(float $startedAt): void
    {
        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $remaining = self::MIN_RESPONSE_MS - $elapsedMs;

        if ($remaining > 0) {
            usleep((int) ($remaining * 1000));
        }
    }
}
