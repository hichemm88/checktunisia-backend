<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\TravelDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Le gouvernorat d'un poste de police est une DONNÉE, pas un motif de
 * recherche.
 *
 * ── La faille ───────────────────────────────────────────────────────────
 *
 * Le cloisonnement des comptes « police » repose sur :
 *
 *     ->where('governorate', 'ilike', "%{$profileGouvernorat}%")
 *
 * La valeur vient de `authority_organizations.governorate`, validée seulement
 * comme `string|max:100`. Rien n'interdit d'y écrire « % ».
 *
 * Le motif devient alors `%%%`, qui correspond à TOUT : le poste de police
 * obtient en silence la portée nationale réservée au ministère. Aucun écran ne
 * le signale, et le compte reste de type « police » — la supervision ne voit
 * donc rien d'anormal.
 *
 * Il faut un compte administrateur pour poser la valeur, ce n'est donc pas
 * exploitable de l'extérieur. Mais un caractère collé par mégarde, un import
 * de données, ou un « % » saisi comme joker par quelqu'un qui croit bien faire
 * suffisent : l'élévation est silencieuse et permanente.
 *
 * ── L'incohérence qui la révèle ─────────────────────────────────────────
 *
 * La même notion est comparée de DEUX façons dans le code :
 *
 *   - `ilike "%X%"` en SQL (profil voyageur, export PDF) — jokers actifs ;
 *   - `str_contains()` en PHP (fiche établissement) — littéral.
 *
 * Avec « % », le premier ouvre tout et le second refuse tout. Deux règles pour
 * un seul concept, c'est déjà un défaut ; qu'elles divergent sur la valeur qui
 * ouvre le pays l'est davantage.
 *
 * ── Le correctif ────────────────────────────────────────────────────────
 *
 * On échappe `%`, `_` et `\` avant de composer le motif. La sémantique « sous
 * chaîne » est CONSERVÉE — elle est voulue : elle tolère « Tunis » face à
 * « Grand Tunis ». Passer à une égalité stricte casserait des données
 * existantes ; échapper les jokers ne casse que l'exploit.
 */
class GovernorateWildcardScopingTest extends TestCase
{
    use RefreshDatabase;

    private function officerFor(?string $governorate): User
    {
        $org = AuthorityOrganization::create([
            'name' => 'Poste sonde',
            'type' => 'police',
            'governorate' => $governorate,
            'is_active' => true,
        ]);

        $agent = User::factory()->authorityUser($org)->create();
        AuthorityUserProfile::firstOrCreate(
            ['user_id' => $agent->id],
            ['organization_id' => $org->id, 'authorized_at' => now()],
        );

        $agent->forceFill([
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $agent;
    }

    private function guestStayingIn(string $governorate): Guest
    {
        $hotel = Hotel::factory()->withActiveSubscription()->inGovernorate($governorate)->create();
        $guest = Guest::factory()->create(['last_name' => strtoupper($governorate)]);

        TravelDocument::create([
            'guest_id' => $guest->id,
            'type' => 'passport',
            'document_number' => 'P-'.strtoupper($governorate),
            'issuing_country_code' => 'TUN',
        ]);

        $checkIn = CheckIn::factory()->for($hotel)->create([
            'created_by' => User::factory()->hotelAdmin($hotel)->create()->id,
            'status' => 'active',
        ]);
        $checkIn->guests()->attach($guest->id, ['is_primary' => true, 'added_by' => $checkIn->created_by]);

        return $guest;
    }

    public function test_a_percent_sign_does_not_grant_nationwide_reach(): void
    {
        $sfaxGuest = $this->guestStayingIn('Sfax');
        $officer = $this->officerFor('%');

        // Le poste est de type « police » : il n'a aucun droit hors de son
        // gouvernorat, et « % » n'est le nom d'aucun gouvernorat.
        $this->actingAs($officer)
            ->getJson("/api/v1/authority/guests/{$sfaxGuest->id}")
            ->assertNotFound();

        // L'export PDF est un SECOND chemin vers les mêmes données : il doit
        // refuser pour la même raison, sans quoi le correctif serait à moitié
        // fait.
        $this->actingAs($officer)
            ->get("/api/v1/authority/guests/{$sfaxGuest->id}/export/pdf")
            ->assertNotFound();
    }

    public function test_an_underscore_does_not_match_a_single_character_either(): void
    {
        // `_` est le joker « un caractère » de LIKE. « Sfa_ » correspondrait à
        // « Sfax » sans échappement.
        $sfaxGuest = $this->guestStayingIn('Sfax');
        $officer = $this->officerFor('Sfa_');

        $this->actingAs($officer)
            ->getJson("/api/v1/authority/guests/{$sfaxGuest->id}")
            ->assertNotFound();
    }

    /**
     * Contre-épreuve : le correctif ne doit pas casser le cas normal, ni la
     * tolérance « sous-chaîne » qui existe pour de bonnes raisons.
     */
    public function test_a_normal_governorate_still_works_including_partial_names(): void
    {
        $guest = $this->guestStayingIn('Grand Tunis');
        $officer = $this->officerFor('Tunis');

        $this->actingAs($officer)
            ->getJson("/api/v1/authority/guests/{$guest->id}")
            ->assertOk()
            ->assertJsonPath('data.last_name', 'GRAND TUNIS');
    }
}
