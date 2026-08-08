<?php

namespace Database\Factories;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Secret TOTP de test, en base32.
     *
     * Il était stocké EN CLAIR par cette factory, alors que TwoFactorController
     * fait Crypt::decryptString() : toute tentative de vérification TOTP dans
     * un test levait DecryptException (HTTP 500). Conséquence — le vrai chemin
     * de vérification à deux facteurs n'était couvert par AUCUN test, alors que
     * les tests semblaient valider la 2FA (ils ne testaient que le middleware,
     * qui ne regarde que two_factor_confirmed_at).
     *
     * Chiffré ici comme en production, pour que les tests puissent exercer le
     * code réel. Exposé pour permettre de générer un code valide.
     */
    public const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    public function definition(): array
    {
        return [
            'email'             => fake()->unique()->safeEmail(),
            'password'          => Hash::make('Password1!Test'),
            'first_name'        => fake()->firstName(),
            'last_name'         => fake()->lastName(),
            'phone'             => fake()->optional()->phoneNumber(),
            'status'            => 'active',
            'email_verified_at' => now(),
            'metadata'          => [],
        ];
    }

    // ── Role states ──────────────────────────────────────────────────────────

    /**
     * Create a hotel_admin user linked to the given hotel.
     */
    public function hotelAdmin(Hotel $hotel): static
    {
        return $this->afterCreating(function (User $user) use ($hotel) {
            $user->assignRole('hotel_admin');
            $user->hotels()->attach($hotel->id, ['granted_at' => now()]);
        });
    }

    /**
     * Create a receptionist user linked to the given hotel.
     */
    public function receptionist(Hotel $hotel): static
    {
        return $this->afterCreating(function (User $user) use ($hotel) {
            $user->assignRole('receptionist');
            $user->hotels()->attach($hotel->id, ['granted_at' => now()]);
        });
    }

    /**
     * Create an authority_user linked to the given organization, with a valid profile.
     */
    public function authorityUser(AuthorityOrganization $organization): static
    {
        return $this->afterCreating(function (User $user) use ($organization) {
            $user->assignRole('authority_user');

            // Authority routes enforce mandatory 2FA (EnsureAuthorityCredentialValid).
            // A usable authority account is therefore one that has already confirmed
            // TOTP — otherwise every authority endpoint returns 403 2FA_SETUP_REQUIRED.
            $user->forceFill([
                'two_factor_secret'       => Crypt::encryptString(self::TOTP_SECRET),
                'two_factor_confirmed_at' => now(),
            ])->save();

            AuthorityUserProfile::create([
                'user_id'         => $user->id,
                'organization_id' => $organization->id,
                'badge_number'    => fake()->numerify('TN-#####'),
                'rank'            => 'Agent',
                'authorized_at'   => now()->subMonth(),
                'expires_at'      => now()->addYear(),
            ]);
        });
    }

    /**
     * Create a platform_admin user.
     */
    public function platformAdmin(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->assignRole('platform_admin');

            // Comme les comptes autorité, les routes /admin/* exigent une 2FA
            // confirmée (EnsurePlatformAdmin2FA) : le compte platform_admin n'a
            // aucun scoping tenant. Un admin utilisable est donc un admin ayant
            // déjà activé sa TOTP — sinon tout endpoint admin renvoie
            // 403 2FA_SETUP_REQUIRED.
            $user->forceFill([
                'two_factor_secret'       => Crypt::encryptString(self::TOTP_SECRET),
                'two_factor_confirmed_at' => now(),
            ])->save();
        });
    }

    /**
     * platform_admin n'ayant PAS configuré sa 2FA — pour tester le blocage.
     */
    public function platformAdminWithout2FA(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->assignRole('platform_admin');
        });
    }
}
