<?php

namespace App\Services\PartnerApi;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Models\FicheSession;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * JWT du widget (§3) : 15 min, usage unique, aucune donnée voyageur dans les
 * claims. Le "usage unique" réel est appliqué par bootstrap() qui marque
 * fiche_sessions.jwt_consumed_at au PREMIER chargement réussi — le JWT ne
 * sert ensuite plus à rien, tous les appels suivants passent par le token de
 * session widget opaque émis à ce moment-là.
 */
class WidgetTokenService
{
    public function issue(FicheSession $session): string
    {
        $secret = $this->secret();
        $now = time();

        return JWT::encode([
            'session_id' => $session->id,
            'establishment_id' => $session->hotel_id,
            'partner_id' => $session->partner_id,
            'iat' => $now,
            'exp' => $now + (int) config('partner_api.jwt.ttl_minutes', 15) * 60,
            'jti' => $session->jwt_jti,
        ], $secret, 'HS256');
    }

    /** @return object{session_id:string,establishment_id:string,partner_id:string,jti:string} */
    public function decode(string $jwt): object
    {
        try {
            $claims = JWT::decode($jwt, new Key($this->secret(), 'HS256'));
        } catch (ExpiredException) {
            throw new PartnerApiException(ErrorCodes::SESSION_EXPIRED, 'Le lien du widget a expiré.');
        } catch (SignatureInvalidException|UnexpectedValueException) {
            throw new PartnerApiException(ErrorCodes::INVALID_SIGNATURE, 'Jeton de widget invalide.');
        }

        return $claims;
    }

    private function secret(): string
    {
        $secret = (string) config('partner_api.jwt.secret');

        if ($secret === '') {
            throw new \RuntimeException('WIDGET_JWT_SECRET absent — impossible de signer/vérifier les jetons de widget.');
        }

        return $secret;
    }

    public static function newJti(): string
    {
        return (string) Str::ulid();
    }
}
