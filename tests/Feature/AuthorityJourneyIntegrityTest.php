<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\TravelDocument;
use App\Models\User;
use App\Models\WhatsappOtpCode;
use App\Models\WatchlistEntry;
use App\Models\WatchlistHit;
use App\Services\Auth\WhatsappOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Le parcours autorité, éprouvé sur ses états intermédiaires.
 *
 * Les tests existants (34 cas dans WhatsappOtpLoginTest, 12 dans
 * AuthorityScopingTest) couvrent bien le nominal et les refus SÉQUENTIELS. Ce
 * fichier vise ce qu'ils ne peuvent pas voir :
 *
 *  - la CONCURRENCE sur une clef à usage unique ;
 *  - les états intermédiaires d'une alerte (déjà résolue, résolue deux fois) ;
 *  - l'injection HTML dans un document servi à un agent des forces de l'ordre.
 *
 * Données strictement synthétiques (spécimen « EL FOULANI / FOULEN »).
 */
class AuthorityJourneyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER = '21620123456';

    /** @var array<string,mixed> */
    private array $gov;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.enabled' => true,
            'whatsapp.channel' => 'cloud',
            'whatsapp.cloud.token' => 'test-token',
            'whatsapp.cloud.phone_number_id' => '123456',
        ]);

        $this->gov = $this->makeGovernorate('Tunis', self::NUMBER);
    }

    // ── OTP : concurrence sur une clef à usage unique ────────────────────────

    public function test_two_simultaneous_verifications_consume_the_code_once(): void
    {
        /*
         * La fenêtre n'est pas théorique : entre le SELECT du code et son
         * marquage, il y a un `Hash::check` — bcrypt, délibérément lent, une
         * centaine de millisecondes. Deux vérifications arrivées dans cet
         * intervalle voyaient toutes les deux un code non consommé.
         *
         * On reproduit l'entrelacement de façon déterministe : le concurrent
         * consomme la ligne pendant que l'appelant « calcule son hash ».
         */
        $entry = $this->issueCode('123456');

        /*
         * L'entrelacement se joue PENDANT le `Hash::check`. Consommer la ligne
         * avant l'appel ne prouverait rien : le SELECT initial la filtrerait
         * deja, et le test passerait meme sans revendication atomique — il
         * mesurerait une garde qui existait deja.
         *
         * On se glisse donc exactement dans la fenetre : un hacheur qui, au
         * moment de verifier le code, laisse le concurrent consommer la ligne.
         * C'est la seule facon de reproduire la course de maniere deterministe.
         */
        $real = app('hash');
        $id = $entry->id;

        $racing = new class($real, $id) implements \Illuminate\Contracts\Hashing\Hasher
        {
            private bool $raced = false;

            public function __construct(private $inner, private string $id) {}

            public function check($value, $hashedValue, array $options = []): bool
            {
                if (! $this->raced) {
                    $this->raced = true;
                    // Le concurrent gagne, pendant que nous « calculons ».
                    WhatsappOtpCode::whereKey($this->id)->update(['consumed_at' => now()]);
                }

                return $this->inner->check($value, $hashedValue, $options);
            }

            public function make($value, array $options = []): string { return $this->inner->make($value, $options); }
            public function info($hashedValue): array { return $this->inner->info($hashedValue); }
            public function needsRehash($hashedValue, array $options = []): bool { return $this->inner->needsRehash($hashedValue, $options); }
        };

        $this->app->instance('hash', $racing);
        // La facade `Hash` garde en cache la racine deja resolue : sans cet
        // oubli explicite, le remplacement n'aurait aucun effet et le test
        // passerait en croyant avoir reproduit la course.
        \Illuminate\Support\Facades\Hash::clearResolvedInstance('hash');

        $service = app(WhatsappOtpService::class);

        $this->assertNull(
            $service->verify(self::NUMBER, '123456', '127.0.0.1'),
            'deux verifications simultanees ont consomme le meme code',
        );

        $this->app->instance('hash', $real);
        \Illuminate\Support\Facades\Hash::clearResolvedInstance('hash');
    }

    public function test_the_winner_of_the_race_still_gets_their_session(): void
    {
        // Contre-épreuve : le durcissement ne doit pas fermer la porte au
        // premier arrivé, qui est l'agent légitime.
        $service = app(WhatsappOtpService::class);
        $this->issueCode('123456');

        $user = $service->verify(self::NUMBER, '123456', '127.0.0.1');

        $this->assertNotNull($user);
        $this->assertSame($this->gov['agent']->id, $user->id);
    }

    public function test_a_replaced_code_never_stays_usable(): void
    {
        // Deux demandes successives ne doivent pas laisser deux clefs en
        // circulation : l'ancienne meurt avec l'usage de la nouvelle.
        $service = app(WhatsappOtpService::class);
        $old = $this->issueCode('111111');
        $new = $this->issueCode('222222');

        $this->assertNotNull($service->verify(self::NUMBER, '222222', '127.0.0.1'));

        $this->assertNotNull($old->fresh()->consumed_at, 'l\'ancien code est resté vivant');
        $this->assertNotNull($new->fresh()->consumed_at);
        $this->assertNull($service->verify(self::NUMBER, '111111', '127.0.0.1'));
    }

    public function test_a_code_issued_for_one_agent_never_logs_in_another(): void
    {
        $other = $this->makeGovernorate('Sfax', '21655000222');

        $service = app(WhatsappOtpService::class);
        $this->issueCode('333333');

        // Le bon code, le mauvais numéro : le couple (numéro, code) est ce qui
        // identifie, jamais le code seul.
        $this->assertNull($service->verify('21655000222', '333333', '127.0.0.1'));
        $this->assertSame(0, $other['agent']->tokens()->count());
    }

    // ── Alertes de sécurité : idempotence et périmètre ───────────────────────

    public function test_acknowledging_an_alert_twice_keeps_the_first_acknowledgement(): void
    {
        $hit = $this->gov['hit'];
        $agent = $this->actingAsAgent($this->gov['agent']);

        $agent->postJson("/api/v1/authority/security-alerts/{$hit->id}/acknowledge")->assertOk();
        $first = $hit->fresh()->authority_acknowledged_at;
        $this->assertNotNull($first);

        $this->travel(2)->minutes();

        // Double clic / rejeu : l'accusé doit rester celui du premier geste.
        $this->actingAsAgent($this->gov['agent'])
            ->postJson("/api/v1/authority/security-alerts/{$hit->id}/acknowledge");

        $this->travelBack();

        $this->assertSame(
            $first->toDateTimeString(),
            $hit->fresh()->authority_acknowledged_at->toDateTimeString(),
            'un second accusé a écrasé l\'horodatage du premier',
        );
    }

    public function test_an_alert_of_another_governorate_cannot_be_acknowledged(): void
    {
        $sfax = $this->makeGovernorate('Sfax', '21655000333');

        $this->actingAsAgent($this->gov['agent'])
            ->postJson("/api/v1/authority/security-alerts/{$sfax['hit']->id}/acknowledge")
            ->assertNotFound();

        $this->assertNull($sfax['hit']->fresh()->authority_acknowledged_at);
    }

    // ── Export : injection dans un document servi à un agent ─────────────────

    public function test_a_traveller_name_cannot_inject_html_into_the_exported_profile(): void
    {
        /*
         * Le nom vient de la saisie d'un hôtel — et l'inscription est ouverte,
         * avec sept jours d'essai. Le document est servi en `text/html` et
         * ouvert par un agent des forces de l'ordre : sans échappement, c'est
         * un XSS stocké déclenchable par n'importe quel client.
         */
        $guest = $this->gov['guest'];
        $guest->forceFill(['last_name' => '<img src=x onerror=alert(1)>'])->save();

        $response = $this->actingAsAgent($this->gov['agent'])
            ->get("/api/v1/authority/guests/{$guest->id}/export/pdf")
            ->assertOk();

        $html = $response->getContent();

        // La comparaison est insensible a la casse : le nom passe par
        // `strtoupper()` AVANT l'echappement, donc « &lt;IMG » et non « &lt;img ».
        // Une assertion sensible a la casse aurait declare une faille inexistante.
        $this->assertStringNotContainsStringIgnoringCase('<img src=x', $html, 'balise injectée servie telle quelle');
        $this->assertStringContainsStringIgnoringCase('&lt;img', $html, 'le nom doit apparaître, échappé');
    }

    public function test_the_export_of_a_foreign_profile_is_refused(): void
    {
        $sfax = $this->makeGovernorate('Sfax', '21655000444');

        $this->actingAsAgent($this->gov['agent'])
            ->get("/api/v1/authority/guests/{$sfax['guest']->id}/export/pdf")
            ->assertNotFound();
    }

    // ── Recherche : caractères spéciaux et jokers ────────────────────────────

    public function test_a_wildcard_typed_in_the_search_does_not_widen_the_result_set(): void
    {
        /*
         * `%` seul est refusé par `min:2` — la validation s'en charge. C'est
         * « %% » qui passait : deux caractères, donc accepté, et interprété
         * comme « tout » par LIKE. La recherche ciblée devenait un vidage
         * complet du périmètre, pendant que le journal d'audit enregistrait une
         * requête d'apparence banale.
         */
        $this->actingAsAgent($this->gov['agent'])
            ->getJson('/api/v1/authority/search?last_name=%25')
            ->assertStatus(422);

        $data = $this->actingAsAgent($this->gov['agent'])
            ->getJson('/api/v1/authority/search?last_name=%25%25')
            ->assertOk()
            ->json('data');

        $this->assertSame([], $data ?? [], 'le joker a ramené des voyageurs');
    }

    public function test_an_underscore_in_a_name_is_not_a_single_character_joker(): void
    {
        // « EL_FOULANI » ne doit PAS correspondre a « EL FOULANI » : sinon la
        // recherche rend des resultats faux sans que rien ne l'indique.
        $data = $this->actingAsAgent($this->gov['agent'])
            ->getJson('/api/v1/authority/search?last_name=EL_FOULANI')
            ->assertOk()
            ->json('data');

        $this->assertSame([], $data ?? [], 'le joker « _ » a fait correspondre un nom different');
    }

    public function test_a_normal_search_still_finds_the_traveller(): void
    {
        // Contre-epreuve : l'echappement ne doit pas casser la recherche, qui
        // est la fonction principale du portail.
        $data = $this->actingAsAgent($this->gov['agent'])
            ->getJson('/api/v1/authority/search?last_name=FOULANI')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data, 'la recherche normale ne trouve plus rien');
    }

    public function test_a_search_stays_inside_the_governorate(): void
    {
        $sfax = $this->makeGovernorate('Sfax', '21655000555');

        $names = collect(
            $this->actingAsAgent($this->gov['agent'])
                ->getJson('/api/v1/authority/search?last_name=FOULANI')
                ->assertOk()
                ->json('data') ?? [],
        )->pluck('last_name')->implode(' ');

        $this->assertStringNotContainsString('SFAX', strtoupper($names));
    }

    // ── Utilitaires ──────────────────────────────────────────────────────────

    private function issueCode(string $code): WhatsappOtpCode
    {
        return WhatsappOtpCode::create([
            'phone' => self::NUMBER,
            'code_hash' => Hash::make($code),
            'user_id' => $this->gov['agent']->id,
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    private function actingAsAgent(User $agent): static
    {
        $agent->forceFill([
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $this->actingAs($agent);
    }

    /** @return array<string,mixed> */
    private function makeGovernorate(string $governorate, string $phone): array
    {
        $org = AuthorityOrganization::create([
            'name' => "Poste de $governorate",
            'type' => 'police',
            'governorate' => $governorate,
            'is_active' => true,
        ]);

        $agent = User::factory()->authorityUser($org)->create();
        AuthorityUserProfile::firstOrCreate(
            ['user_id' => $agent->id],
            [
                'organization_id' => $org->id,
                'whatsapp_number' => $phone,
                'receives_whatsapp_fiches' => true,
                'authorized_at' => now(),
            ],
        );

        $hotel = Hotel::factory()->withActiveSubscription()->inGovernorate($governorate)->create();
        $agent->hotels()->syncWithoutDetaching([$hotel->id]);

        $guest = Guest::factory()->create(['last_name' => strtoupper($governorate) === 'TUNIS' ? 'EL FOULANI' : 'SFAX']);
        TravelDocument::create([
            'guest_id' => $guest->id,
            'type' => 'passport',
            'document_number' => 'X'.substr(md5($governorate.$phone), 0, 7),
            'issuing_country_code' => 'TUN',
        ]);

        $checkIn = CheckIn::factory()->for($hotel)->create([
            'created_by' => User::factory()->hotelAdmin($hotel)->create()->id,
            'status' => 'active',
        ]);
        $checkIn->guests()->attach($guest->id, ['is_primary' => true, 'added_by' => $checkIn->created_by]);

        $entry = WatchlistEntry::factory()->create([
            'organization_id' => $org->id,
            'added_by' => $agent->id,
        ]);

        $hit = WatchlistHit::factory()->create([
            'watchlist_entry_id' => $entry->id,
            'guest_id' => $guest->id,
            'check_in_id' => $checkIn->id,
            'hotel_id' => $hotel->id,
        ]);

        return compact('org', 'agent', 'hotel', 'guest', 'checkIn', 'entry', 'hit');
    }
}
