<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserRecoveryCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Request;

/**
 * Codes de récupération à usage unique.
 *
 * Le filet de sécurité du scénario « j'ai perdu mon téléphone » : il rend la
 * passkey adoptable sans risquer d'enfermer l'utilisateur dehors. Un code
 * remplace le TOTP à l'étape de vérification, jamais le mot de passe — perdre
 * la liste ne suffit donc pas à prendre un compte.
 *
 * Les codes sont hachés (bcrypt) : la base ne permet pas de les retrouver, ils
 * ne sont affichés qu'une seule fois, à la génération.
 */
class RecoveryCodeService
{
    /** Alphabet sans caractères ambigus (0/O, 1/I/l) — les codes sont recopiés à la main. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Remplace tous les codes du compte et renvoie les nouveaux, EN CLAIR.
     * C'est le seul moment où ils existent en clair côté serveur.
     *
     * @return string[]
     */
    public static function regenerate(User $user): array
    {
        $count = max(1, (int) config('webauthn.recovery_codes_count', 10));

        $user->recoveryCodes()->delete();

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = self::generateCode();
            $codes[] = $code;

            UserRecoveryCode::create([
                'user_id'   => $user->id,
                'code_hash' => Hash::make(self::normalize($code)),
            ]);
        }

        return $codes;
    }

    /**
     * Consomme un code. Renvoie false si aucun code non utilisé ne correspond.
     *
     * La comparaison parcourt tous les codes restants : les hachages bcrypt
     * empêchent toute recherche par index, et le coût reste négligeable
     * (10 codes au plus).
     */
    public static function consume(User $user, string $submitted): bool
    {
        $normalized = self::normalize($submitted);

        foreach ($user->recoveryCodes()->whereNull('used_at')->get() as $code) {
            if (Hash::check($normalized, $code->code_hash)) {
                $code->update([
                    'used_at' => now(),
                    'used_ip' => Request::ip(),
                ]);

                return true;
            }
        }

        return false;
    }

    public static function remaining(User $user): int
    {
        return $user->recoveryCodes()->whereNull('used_at')->count();
    }

    /** Format : XXXX-XXXX (40 bits d'entropie). */
    private static function generateCode(): string
    {
        $chars = '';
        for ($i = 0; $i < 8; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($chars, 0, 4) . '-' . substr($chars, 4, 4);
    }

    /** Tolère la casse, les espaces et le tiret manquant à la saisie. */
    private static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }
}
