<?php

namespace App\Services\PartnerApi;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Models\CheckIn;
use App\Models\FicheSession;
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

    /** @return array{session: FicheSession, widget_token: string} */
    public function bootstrap(string $jwt): array
    {
        $claims = $this->tokens->decode($jwt);

        return DB::transaction(function () use ($claims) {
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

            $token = Str::random(48);
            $ttl = (int) config('partner_api.widget_session.ttl_minutes', 20);

            $session->update([
                'jwt_consumed_at' => now(),
                'widget_session_token_hash' => hash('sha256', $token),
                'widget_session_expires_at' => now()->addMinutes($ttl),
            ]);

            return ['session' => $session, 'widget_token' => $token];
        });
    }

    /** Résout et fait glisser la session widget (TTL prolongé à chaque appel authentifié). */
    public function resolve(string $widgetToken): FicheSession
    {
        $session = FicheSession::where('widget_session_token_hash', hash('sha256', $widgetToken))->first();

        if (! $session || $session->widget_session_expires_at === null || $session->widget_session_expires_at->isPast()) {
            throw new PartnerApiException(ErrorCodes::SESSION_EXPIRED);
        }

        if ($session->status !== FicheSession::STATUS_PENDING) {
            throw new PartnerApiException(ErrorCodes::SESSION_EXPIRED, 'Cette fiche a déjà été soumise.');
        }

        $ttl = (int) config('partner_api.widget_session.ttl_minutes', 20);
        $session->forceFill(['widget_session_expires_at' => now()->addMinutes($ttl)])->saveQuietly();

        return $session;
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
