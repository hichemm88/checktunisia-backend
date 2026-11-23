<?php

namespace App\Services\PartnerApi;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Models\CheckIn;
use App\Models\FicheSession;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cycle de vie du widget côté serveur (§3) : le JWT d'origine n'est vérifié
 * qu'UNE fois, au bootstrap — dès ce moment il est remplacé par un token de
 * session opaque (TTL glissant) qui porte toute la saisie du réceptionniste.
 * C'est ce qui rend le JWT réellement "usage unique" sans empêcher une
 * saisie de plusieurs minutes.
 */
class WidgetSessionService
{
    public function __construct(private WidgetTokenService $tokens) {}

    /**
     * @return array{session: FicheSession, widget_token: ?string, locked_by?: ?string}
     *         widget_token null = fiche tenue par une autre personne : rien
     *         n'est consommé ; le même lien sert à réessayer ou à reprendre
     *         la saisie ($takeover).
     */
    public function bootstrap(string $jwt, bool $takeover = false): array
    {
        $claims = $this->tokens->decode($jwt);

        return DB::transaction(function () use ($claims, $takeover) {
            $session = FicheSession::where('id', $claims->session_id)
                ->where('jwt_jti', $claims->jti)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new PartnerApiException(ErrorCodes::SESSION_NOT_FOUND);
            }

            // Usage UNIQUE réel (§8) : un jti déjà consommé ne redonne JAMAIS
            // accès, même dans la fenêtre de validité du JWT. Le widget garde
            // le token de session opaque côté client (sessionStorage) pour
            // survivre à un rechargement d'onglet SANS repasser par ici — voir
            // frontend/src/widget-main.tsx.
            if ($session->jwt_consumed_at !== null) {
                throw new PartnerApiException(ErrorCodes::SESSION_EXPIRED, 'Ce lien de widget a déjà été utilisé.');
            }

            if (! $session->isPendingAndUsable()) {
                throw new PartnerApiException(ErrorCodes::SESSION_EXPIRED);
            }

            // Une autre personne saisit cette fiche en ce moment (action réelle
            // il y a moins de 2 min). Rien n'est consommé : le widget affiche
            // « {nom} est en train de saisir cette fiche » et propose
            // « Reprendre la saisie » (même lien, $takeover = true).
            $heldByAnother = $session->isHeldByAnother($session->requested_operator_user_id, $session->requested_operator_name);
            if ($heldByAnother && ! $takeover) {
                return ['session' => $session, 'widget_token' => null, 'locked_by' => $session->holderName()];
            }

            $previousHolder = $heldByAnother ? $session->holderName() : null;
            $token = Str::random(48);
            $ttl = (int) config('partner_api.widget_session.ttl_minutes', 20);

            $session->update([
                'jwt_consumed_at' => now(),
                // L'ancien token (autre appareil, collègue) cesse de valoir :
                // son widget affichera « Saisie reprise par … ».
                'superseded_token_hash' => $session->widget_session_token_hash ?? $session->superseded_token_hash,
                'widget_session_token_hash' => hash('sha256', $token),
                'widget_session_expires_at' => now()->addMinutes($ttl),
                'lock_renewed_at' => now(),
                // Celui qui ouvre réellement le widget devient l'opérateur.
                'operator_user_id' => $session->requested_operator_user_id,
                'operator_name' => $session->requested_operator_name,
            ]);

            if ($heldByAnother) {
                AuditLogger::log('fiche_session.lock_taken_over', $session,
                    ['operator' => $previousHolder], ['operator' => $session->holderName()],
                    actor: $session->operator()->first(), hotelId: $session->hotel_id);
            }

            return ['session' => $session, 'widget_token' => $token];
        });
    }

    /**
     * Résout la session widget. $activity = action réelle du réceptionniste
     * (scan, saisie, navigation) : prolonge le token et renouvelle le verrou.
     * Une simple vérification d'état ou une libération ne prolonge rien.
     */
    public function resolve(string $widgetToken, bool $activity = true): FicheSession
    {
        $hash = hash('sha256', $widgetToken);
        $session = FicheSession::where('widget_session_token_hash', $hash)->first();

        if (! $session) {
            $this->throwIfTakenOver($hash);
        }

        if (! $session || $session->widget_session_expires_at === null || $session->widget_session_expires_at->isPast()) {
            throw new PartnerApiException(ErrorCodes::SESSION_EXPIRED);
        }

        if ($session->status !== FicheSession::STATUS_PENDING) {
            throw new PartnerApiException(ErrorCodes::SESSION_EXPIRED, 'Cette fiche a déjà été soumise.');
        }

        if ($activity) {
            $ttl = (int) config('partner_api.widget_session.ttl_minutes', 20);
            $session->forceFill([
                'widget_session_expires_at' => now()->addMinutes($ttl),
                'lock_renewed_at' => now(),
            ])->saveQuietly();
        }

        return $session;
    }

    private function throwIfTakenOver(string $hash): void
    {
        $session = FicheSession::where('superseded_token_hash', $hash)
            ->where('status', FicheSession::STATUS_PENDING)
            ->first();

        if ($session) {
            $name = $session->holderName();
            throw new PartnerApiException(
                ErrorCodes::SESSION_TAKEN_OVER,
                $name ? "Saisie reprise par {$name}." : null,
                extra: ['taken_over_by' => $name],
            );
        }
    }

    public function checkInFor(FicheSession $session): CheckIn
    {
        $checkIn = CheckIn::find($session->check_in_id);

        if (! $checkIn) {
            throw new PartnerApiException(ErrorCodes::SESSION_NOT_FOUND, 'Fiche associée introuvable.');
        }

        return $checkIn;
    }
}
