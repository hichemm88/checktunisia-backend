<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOtpDeviceMatches;
use App\Models\AuthorityOrganization;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsappOtpCode;
use App\Models\WhatsappSendLog;
use App\Services\Auth\SessionIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Connexion du portail autorité par code reçu sur WhatsApp.
 *
 * Ce qui est vérifié ici n'est pas « la connexion marche » — cela se voit au
 * premier essai. C'est l'ensemble des façons dont ce chemin pourrait s'ouvrir
 * plus grand qu'il ne devrait, sans que rien ne le montre :
 *
 *  - un code part vers un numéro que l'admin n'a jamais enregistré ;
 *  - la réponse ou le délai laissent deviner si un numéro est celui d'un agent ;
 *  - un code se rejoue, se force par essais successifs, ou survit à son
 *    expiration ;
 *  - la session ouverte contourne les contrôles que la 2FA imposait ;
 *  - le code apparaît quelque part — journal, réponse, base.
 */
class WhatsappOtpLoginTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER = '21620123456';

    private AuthorityOrganization $org;

    private Hotel $hotel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = AuthorityOrganization::create([
            'name' => 'Poste Tunis Medina', 'type' => 'police', 'governorate' => 'Tunis', 'is_active' => true,
        ]);
        $this->hotel = Hotel::factory()->withActiveSubscription()->create(['name' => 'Dar Test']);

        config([
            'whatsapp.enabled' => true,
            'whatsapp.channel' => 'cloud',
            'whatsapp.cloud.token' => 'test-token',
            'whatsapp.cloud.phone_number_id' => '123456',
            // Explicites : un test qui hérite d'un défaut de configuration est
            // un test qui passe chez soi et tombe en CI.
            'whatsapp.cloud.base_url' => 'https://graph.facebook.com',
            'whatsapp.cloud.api_version' => 'v21.0',
            'whatsapp.cloud.timeout' => 30,
            'whatsapp.cloud.template.otp_name' => 'qayed_otp',
            'whatsapp.cloud.template.otp_language' => 'fr',
            'whatsapp.guard.sending_enabled' => true,
        ]);

        // Les limiteurs vivent dans le cache : sans ce nettoyage, un test qui
        // épuise le quota d'une IP fait tomber le suivant, et l'ordre
        // d'exécution devient significatif.
        RateLimiter::clear('otp-request:ip:127.0.0.1');
    }

    // ── Éligibilité : qui peut recevoir un code ─────────────────────────────

    public function test_an_unregistered_number_gets_the_same_answer_and_no_call_to_meta(): void
    {
        Http::fake();

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '21699999999'])
            ->assertStatus(202)
            ->assertExactJson(['ok' => true]);

        // Le point qui compte : pas d'appel HTTP. Un envoi vers un numéro non
        // enregistré n'est pas seulement inutile, c'est un message non sollicité
        // sur le canal légal — exactement ce qui a fait bannir le numéro
        // précédent.
        Http::assertNothingSent();
        $this->assertDatabaseCount('whatsapp_otp_codes', 0);
    }

    public function test_a_registered_number_receives_the_authentication_template(): void
    {
        $this->fakeAccepted();
        $this->agent();

        $this->postJson('/api/v1/auth/otp/request', ['phone' => '+216 20 123 456'])
            ->assertStatus(202)
            ->assertExactJson(['ok' => true]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['type'] === 'template'
                && $body['template']['name'] === 'qayed_otp'
                && $body['template']['language']['code'] === 'fr'
                && $body['to'] === self::NUMBER;
        });

        $entry = WhatsappOtpCode::where('phone', self::NUMBER)->firstOrFail();

        // Le code n'est stocké que haché : rien dans cette colonne ne permet de
        // se connecter, même en lisant la base.
        $this->assertNotEmpty($entry->code_hash);
        $this->assertMatchesRegularExpression('/^\$(2y|argon)/', $entry->code_hash);
        $this->assertSame(0, $entry->attempts);
    }

    public function test_the_code_travels_in_the_body_and_on_the_copy_button(): void
    {
        $this->fakeAccepted();
        $this->agent();

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::NUMBER]);

        Http::assertSent(function ($request) {
            $components = collect($request->data()['template']['components']);
            $body = $components->firstWhere('type', 'body');
            $button = $components->firstWhere('type', 'button');

            // Meta refuse le message si le code manque à l'un des deux
            // emplacements : ce ne sont pas deux paramètres, mais deux places du
            // même.
            return $body !== null
                && $button !== null
                && $button['sub_type'] === 'url'
                && preg_match('/^\d{6}$/', $body['parameters'][0]['text']) === 1
                && $button['parameters'][0]['text'] === $body['parameters'][0]['text'];
        });
    }

    public function test_a_number_without_the_receives_flag_is_not_eligible(): void
    {
        Http::fake();
        $this->agent(receives: false);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::NUMBER])->assertStatus(202);

        Http::assertNothingSent();
    }

    public function test_a_number_attached_to_no_hotel_is_not_eligible(): void
    {
        Http::fake();
        $this->agent(attachHotel: false);

        // Un profil créé avec un numéro mais que l'admin n'a rattaché à aucun
        // établissement ne reçoit AUCUNE fiche : il ne doit pas non plus
        // recevoir de code. On n'ouvre pas un canal vers un numéro sur lequel
        // on n'envoie rien.
        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::NUMBER])->assertStatus(202);

        Http::assertNothingSent();
    }

    public function test_a_suspended_account_is_not_eligible(): void
    {
        Http::fake();
        $user = $this->agent();
        $user->update(['status' => 'suspended']);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::NUMBER])->assertStatus(202);

        Http::assertNothingSent();
    }

    public function test_an_expired_credential_is_not_eligible(): void
    {
        Http::fake();
        $user = $this->agent();
        $user->authorityProfile()->update(['expires_at' => now()->subDay()]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::NUMBER])->assertStatus(202);

        Http::assertNothingSent();
    }

    // ── Anti-énumération ───────────────────────────────────────────────────

    public function test_eligible_and_ineligible_numbers_answer_identically(): void
    {
        $this->fakeAccepted();
        $this->agent();

        $known = $this->postJson('/api/v1/auth/otp/request', ['phone' => self::NUMBER]);
        RateLimiter::clear('otp-request:ip:127.0.0.1');
        $unknown = $this->postJson('/api/v1/auth/otp/request', ['phone' => '21699999999']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_an_ineligible_number_is_not_answered_faster_than_an_eligible_one(): void
    {
        $this->fakeAccepted();
        $this->agent();

        $unknownMs = $this->timeRequest('21699999999');
        RateLimiter::clear('otp-request:ip:127.0.0.1');
        $knownMs = $this->timeRequest(self::NUMBER);

        // La réponse identique ne suffit pas : sans plancher de durée, le
        // numéro inconnu répondrait en quelques millisecondes là où le numéro
        // réel attend un appel à Meta et un hachage. La différence se mesure
        // depuis n'importe quel navigateur.
        $this->assertGreaterThan(500, $unknownMs, 'La réponse à un numéro inconnu doit être retenue.');
        $this->assertGreaterThan(500, $knownMs);
    }

    // ── Vérification du code ───────────────────────────────────────────────

    public function test_a_valid_code_opens_a_thirty_day_session_marked_whatsapp_otp(): void
    {
        $user = $this->agent();
        $code = $this->issueCode($user);

        $response = $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::NUMBER, 'code' => $code])
            ->assertOk();

        $this->assertSame(SessionIssuer::METHOD_WHATSAPP_OTP, $response->json('data.user.security.auth_method'));
        $this->assertSame($user->id, $response->json('data.user.id'));

        $token = $user->tokens()->latest('id')->firstOrFail();
        $this->assertContains(SessionIssuer::WHATSAPP_OTP_ABILITY, $token->abilities);
        // 30 jours : la tolérance couvre le temps d'exécution du test, pas un
        // écart de configuration.
        $this->assertEqualsWithDelta(30 * 24 * 60, now()->diffInMinutes($token->expires_at), 5);

        // Le code est consommé : le rejouer ne peut plus rien ouvrir.
        $this->assertNotNull(WhatsappOtpCode::where('phone', self::NUMBER)->firstOrFail()->consumed_at);
    }

    public function test_the_intended_destination_is_reachable_right_after_an_otp_login(): void
    {
        $user = $this->agent();
        $code = $this->issueCode($user);

        $token = $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::NUMBER, 'code' => $code])
            ->assertOk()
            ->json('data.token');

        // Le point réel de l'exigence « la destination visée est respectée » :
        // le frontend ne peut y emmener l'agent que si la session ouverte par
        // code franchit les contrôles du portail autorité. Sans l'OTP reconnu
        // comme facteur fort, cette requête répondrait 403 2FA_SETUP_REQUIRED —
        // et l'agent resterait bloqué devant une TOTP qu'il ne peut pas
        // configurer, faute d'adresse e-mail réelle.
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/authority/dashboard')
            ->assertOk();
    }

    public function test_an_otp_session_does_not_require_the_existing_two_factor(): void
    {
        // Agent SANS TOTP configurée — le cas nominal : son adresse e-mail est
        // fictive, il n'a jamais pu en activer une.
        $user = $this->agent(withTotp: false);
        $code = $this->issueCode($user);

        $token = $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::NUMBER, 'code' => $code])
            ->assertOk()
            ->json('data.token');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/authority/dashboard')
            ->assertOk();
    }

    public function test_three_wrong_codes_lock_the_number_for_fifteen_minutes(): void
    {
        $user = $this->agent();
        $code = $this->issueCode($user);

        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::NUMBER, 'code' => '000000'])
                ->assertStatus(422)
                ->assertJsonPath('errors.0.code', 'OTP_INVALID');
        }

        // Le BON code ne passe plus : c'est ce qui ramène 10⁶ possibilités à
        // trois tentatives.
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::NUMBER, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'OTP_INVALID');

        $lock = WhatsappOtpCode::where('phone', self::NUMBER)->firstOrFail()->locked_until;
        $this->assertNotNull($lock);
        $this->assertEqualsWithDelta(15, now()->diffInMinutes($lock), 1);
    }

    public function test_a_locked_number_cannot_request_a_fresh_code(): void
    {
        $this->fakeAccepted();
        $user = $this->agent();
        $this->issueCode($user);

        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::NUMBER, 'code' => '000000']);
        }

        Http::fake();
        RateLimiter::clear('otp-request:ip:127.0.0.1');
        RateLimiter::clear('otp-request:phone:'.hash('sha256', self::NUMBER));

        // Sans cette règle, le verrou de quinze minutes se contourne d'un clic
        // sur « renvoyer le code ».
        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::NUMBER])->assertStatus(202);

        Http::assertNothingSent();
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = $this->agent();
        $code = $this->issueCode($user);

        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::NUMBER, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'OTP_INVALID');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $user = $this->agent();
        $code = $this->issueCode($user);

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::NUMBER, 'code' => $code])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::NUMBER, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'OTP_INVALID');

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_a_code_does_not_open_a_session_on_another_number(): void
    {
        $user = $this->agent();
        $code = $this->issueCode($user);

        $other = $this->agent(number: '21630000000', email: 'autre@wa-recipient.qayed.local');

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '21630000000', 'code' => $code])
            ->assertStatus(422);

        $this->assertSame(0, $other->tokens()->count());
    }

    // ── Limites de débit ───────────────────────────────────────────────────

    public function test_a_number_is_limited_to_three_requests_per_window(): void
    {
        $this->fakeAccepted();
        $this->agent();

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/v1/auth/otp/request', ['phone' => self::NUMBER])->assertStatus(202);
        }

        Http::fake();
        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::NUMBER])
            // Même réponse au-delà de la limite : un 429 réservé aux numéros
            // réels serait, à lui seul, l'oracle qu'on cherche à fermer.
            ->assertStatus(202)
            ->assertExactJson(['ok' => true]);

        Http::assertNothingSent();
    }

    public function test_an_ip_is_limited_across_different_numbers(): void
    {
        $this->fakeAccepted();
        $this->agent();
        $this->agent(number: '21630000000', email: 'b@wa-recipient.qayed.local');
        $this->agent(number: '21640000000', email: 'c@wa-recipient.qayed.local');
        $this->agent(number: '21650000000', email: 'd@wa-recipient.qayed.local');

        // Trois numéros DIFFÉRENTS : seul le limiteur par IP peut arrêter le
        // quatrième. Un limiteur unique indexé sur le couple (numéro, IP)
        // laisserait passer.
        foreach (['21620123456', '21630000000', '21640000000'] as $number) {
            $this->postJson('/api/v1/auth/otp/request', ['phone' => $number])->assertStatus(202);
        }

        Http::fake();
        $this->postJson('/api/v1/auth/otp/request', ['phone' => '21650000000'])->assertStatus(202);

        Http::assertNothingSent();
    }

    // ── Garde-fous d'envoi ─────────────────────────────────────────────────

    public function test_the_global_kill_switch_stops_codes_too(): void
    {
        $this->fakeAccepted();
        $this->agent();
        config(['whatsapp.guard.sending_enabled' => false]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::NUMBER])->assertStatus(202);

        Http::assertNothingSent();
    }

    public function test_codes_ignore_the_fiche_guards(): void
    {
        $this->fakeAccepted();
        $this->agent();

        // Bascule non armée, arriéré au-dessus du seuil, plafond quotidien à
        // zéro : chacune de ces conditions bloque TOUTES les fiches. Aucune ne
        // doit fermer la porte du portail — un agent qui cherche à consulter
        // une fiche qu'il n'a pas reçue se connecte précisément quand l'envoi
        // est en panne.
        config([
            'whatsapp.guard.cutover_at' => null,
            'whatsapp.guard.backlog_alert_threshold' => 1,
            'whatsapp.guard.max_sends_per_day' => 0,
        ]);

        foreach (range(1, 3) as $i) {
            $this->sendLog();
        }

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::NUMBER])->assertStatus(202);

        Http::assertSentCount(1);
    }

    public function test_codes_have_their_own_hourly_ceiling(): void
    {
        $this->fakeAccepted();
        config(['whatsapp.otp.max_per_hour' => 2]);

        $numbers = ['21620123456', '21630000000', '21640000000'];
        $this->agent();
        $this->agent(number: '21630000000', email: 'b@wa-recipient.qayed.local');
        $this->agent(number: '21640000000', email: 'c@wa-recipient.qayed.local');

        foreach ($numbers as $number) {
            $this->postJson('/api/v1/auth/otp/request', ['phone' => $number])->assertStatus(202);
        }

        // Le troisième est retenu par le plafond OTP : sans lui, cette file
        // serait le seul chemin non borné vers Meta.
        Http::assertSentCount(2);
    }

    public function test_a_refused_send_neutralises_the_code_and_stays_neutral_to_the_caller(): void
    {
        $this->agent();
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['code' => 131026, 'message' => 'Message undeliverable'],
            ], 400),
        ]);

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::NUMBER])
            ->assertStatus(202)
            ->assertExactJson(['ok' => true]);

        // Un code que Meta n'a pas transmis ne doit pas occuper la place du
        // suivant.
        $this->assertNotNull(WhatsappOtpCode::where('phone', self::NUMBER)->firstOrFail()->consumed_at);
    }

    // ── Le code ne fuit nulle part ─────────────────────────────────────────

    public function test_the_code_never_appears_in_the_logs_or_the_response(): void
    {
        $this->fakeAccepted();
        $this->agent();

        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            $lines[] = $message->message.' '.json_encode($message->context);
        });

        $response = $this->postJson('/api/v1/auth/otp/request', ['phone' => self::NUMBER]);

        // Le code en clair n'existe qu'entre sa génération et son départ chez
        // Meta : on le récupère depuis la requête sortante, seul endroit
        // légitime où il figure.
        $code = null;
        Http::assertSent(function ($request) use (&$code) {
            $code = $request->data()['template']['components'][0]['parameters'][0]['text'];

            return true;
        });

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);
        $this->assertStringNotContainsString((string) $code, $response->getContent());

        foreach ($lines as $line) {
            $this->assertStringNotContainsString((string) $code, $line, 'Le code a fuité dans les journaux.');
        }

        // Ni en base, ni dans le journal d'activité.
        $this->assertDatabaseMissing('whatsapp_otp_codes', ['code_hash' => $code]);
        $this->assertStringNotContainsString(
            (string) $code,
            (string) json_encode(\App\Models\AuditLog::pluck('new_values')->all()),
        );
    }

    public function test_the_activity_log_records_a_masked_number_never_a_full_one(): void
    {
        $this->fakeAccepted();
        $this->agent();

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::NUMBER]);

        $entry = \App\Models\AuditLog::where('action', 'authority.otp_requested')->firstOrFail();

        $this->assertTrue($entry->new_values['eligible']);
        $this->assertTrue($entry->new_values['sent']);
        $this->assertNotSame(self::NUMBER, $entry->new_values['phone']);
        $this->assertStringContainsString('*', $entry->new_values['phone']);
    }

    public function test_opening_a_session_is_recorded_in_the_activity_log(): void
    {
        $user = $this->agent();
        $code = $this->issueCode($user);

        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::NUMBER, 'code' => $code])->assertOk();

        $entry = \App\Models\AuditLog::where('action', 'authority.otp_session_opened')->firstOrFail();

        $this->assertSame($user->id, $entry->actor_id);
        $this->assertSame(SessionIssuer::METHOD_WHATSAPP_OTP, $entry->new_values['via']);
    }

    // ── Révocation et appareil ─────────────────────────────────────────────

    public function test_an_admin_revokes_every_session_of_an_agent(): void
    {
        $user = $this->agent();
        $code = $this->issueCode($user);
        $token = $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::NUMBER, 'code' => $code])
            ->json('data.token');

        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/authority-users/'.$user->id.'/revoke-sessions')
            ->assertOk()
            ->assertJsonPath('data.revoked', 1);

        // `actingAs` a fixé l'utilisateur de la garde pour tout le test : sans
        // cet oubli, la requête suivante resterait celle de l'admin et
        // répondrait 403 au lieu de 401 — on croirait avoir vérifié la
        // révocation alors qu'on aurait vérifié les rôles.
        $this->app['auth']->forgetGuards();

        // Contrepartie des trente jours : sans ce bouton, un téléphone perdu
        // resterait valable un mois.
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/authority/dashboard')
            ->assertStatus(401);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_session_replayed_from_another_device_is_destroyed(): void
    {
        $user = $this->agent();
        $code = $this->issueCode($user);

        $token = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/605.1.15'])
            ->postJson('/api/v1/auth/otp/verify', ['phone' => self::NUMBER, 'code' => $code])
            ->json('data.token');

        // Même appareil, navigateur mis à jour : les chiffres de version ne
        // comptent pas, sinon chaque mise à jour mensuelle déconnecterait tout
        // le monde.
        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_2) Safari/610.4.20',
        ])->getJson('/api/v1/authority/dashboard')->assertOk();

        // Autre appareil : le jeton est détruit, pas seulement refusé.
        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0.0.0',
        ])->getJson('/api/v1/authority/dashboard')
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'OTP_DEVICE_MISMATCH');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_device_guard_leaves_other_sessions_alone(): void
    {
        $user = User::factory()->authorityUser($this->org)->create();
        $token = $user->createToken('api-token', ['*'])->plainTextToken;

        // Un jeton sans empreinte traverse le middleware sans être regardé :
        // la garde est propre à l'OTP, elle ne doit rien changer pour les
        // sessions ouvertes autrement.
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'User-Agent' => 'un-agent'])
            ->getJson('/api/v1/authority/dashboard')->assertOk();

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'User-Agent' => 'un-autre-agent'])
            ->getJson('/api/v1/authority/dashboard')->assertOk();
    }

    public function test_the_device_fingerprint_ignores_version_numbers(): void
    {
        $iphone17 = EnsureOtpDeviceMatches::fingerprint('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/605.1.15');
        $iphone18 = EnsureOtpDeviceMatches::fingerprint('Mozilla/5.0 (iPhone; CPU iPhone OS 18_2) Safari/610.4.20');
        $desktop = EnsureOtpDeviceMatches::fingerprint('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0.0.0');

        $this->assertSame($iphone17, $iphone18);
        $this->assertNotSame($iphone17, $desktop);
    }

    // ── /f/{token} ─────────────────────────────────────────────────────────

    public function test_the_fiche_link_redirects_to_the_portal_by_default(): void
    {
        config(['whatsapp.fiche_link_mode' => 'portal']);
        $job = $this->sendLog();

        $this->get('/f/'.$job->public_token)
            ->assertStatus(302)
            ->assertRedirectContains('/authority/guests/'.$job->guest_id);
    }

    public function test_the_info_mode_shows_a_page_without_any_fiche_data(): void
    {
        config(['whatsapp.fiche_link_mode' => 'info']);
        $job = $this->sendLog();

                $response = $this->get('/f/'.$job->public_token)->assertOk();
        $html = $response->getContent();

        // Espaces normalisés : la page est indentée, la phrase y court sur
        // trois lignes. Ce qui compte est le texte, pas sa mise en forme.
        $flat = (string) preg_replace('/\s+/u', ' ', $html);

        $this->assertStringContainsString('Cette fiche de police vous a été transmise par Qayed', $flat);
        $this->assertStringContainsString("plateforme d'enregistrement numérique des hôtes", $flat);
        $this->assertStringContainsString('SOCIETE UW AGENCY', $html);

        // Aucune donnée de fiche, aucun formulaire : c'est tout l'objet du mode.
        $this->assertStringNotContainsString((string) $job->guest_id, $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<input', $html);
    }

    public function test_an_unknown_token_is_a_bare_404_in_both_modes(): void
    {
        foreach (['portal', 'info'] as $mode) {
            config(['whatsapp.fiche_link_mode' => $mode]);

            // Sans cette vérification en mode « info », /f/n-importe-quoi
            // servirait la même page officielle : le lien deviendrait un
            // support d'hameçonnage au nom de Qayed.
            $this->get('/f/'.Str::ulid())->assertStatus(404)->assertSee('', false);
        }
    }

    // ── Fabriques ──────────────────────────────────────────────────────────

    /**
     * Un agent tel que l'admin le crée réellement : adresse e-mail fictive,
     * numéro WhatsApp, coché comme destinataire d'un établissement.
     */
    private function agent(
        string $number = self::NUMBER,
        string $email = 'agent@wa-recipient.qayed.local',
        bool $receives = true,
        bool $attachHotel = true,
        bool $withTotp = true,
    ): User {
        $user = User::factory()->authorityUser($this->org)->create(['email' => $email]);

        if (! $withTotp) {
            $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        }

        $profile = $user->authorityProfile()->firstOrFail();
        $profile->update([
            'whatsapp_number' => $number,
            'receives_whatsapp_fiches' => $receives,
        ]);

        if ($attachHotel) {
            $this->hotel->whatsappRecipientProfiles()->syncWithoutDetaching([$profile->id]);
        }

        return $user->refresh();
    }

    /** Pose un code connu en base, sans passer par l'envoi. */
    private function issueCode(User $user, string $code = '123456'): string
    {
        WhatsappOtpCode::create([
            'phone' => self::NUMBER,
            'code_hash' => Hash::make($code),
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(5),
        ]);

        return $code;
    }

    /**
     * Une ligne d'envoi minimale — le jeton public est posé par le modèle.
     */
    private function sendLog(): WhatsappSendLog
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->active()->withGuest('Martin', 'Ostermeier')->create();

        return WhatsappSendLog::create([
            'hotel_id' => $checkIn->hotel_id,
            'check_in_id' => $checkIn->id,
            'guest_id' => $checkIn->guests()->first()->id,
            'recipient' => self::NUMBER,
            'caption' => 'Fiche',
            'status' => WhatsappSendLog::STATUS_PENDING,
            'next_attempt_at' => now(),
            'queued_at' => now(),
        ]);
    }

    private function fakeAccepted(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TEST'.Str::random(8)]],
            ], 200),
        ]);
    }

    private function timeRequest(string $phone): float
    {
        $startedAt = microtime(true);
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone]);

        return (microtime(true) - $startedAt) * 1000;
    }
}
