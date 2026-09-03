<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsappBillableMessage;
use App\Models\WhatsappConversation;
use App\Models\WhatsappConversationMessage;
use App\Models\WhatsappSendLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Boîte de réception des autorités.
 *
 * Ce qui est vérifié ici, ce n'est pas « la liste s'affiche », mais les façons
 * dont ce fil pourrait mentir sans que personne ne le voie :
 *
 *  - le même agent réparti sur plusieurs fils parce que son numéro est écrit
 *    différemment d'un endroit à l'autre ;
 *  - un compteur de non-lus gonflé par les rejeux de webhook de Meta ;
 *  - une chronologie qui n'est pas dans l'ordre parce que les fiches et les
 *    messages viennent de deux tables ;
 *  - un champ de réponse ouvert sur une fenêtre de service fermée, donc un
 *    message que Meta refusera après avoir laissé croire qu'il partait ;
 *  - un fil lisible par quelqu'un qui n'est pas administrateur.
 */
class AuthorityWhatsappInboxTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->create(['name' => 'Dar Test']);

        config([
            'whatsapp.enabled' => true,
            'whatsapp.channel' => 'cloud',
            'whatsapp.cloud.token' => 'test-token',
            'whatsapp.cloud.phone_number_id' => '123456',
            'whatsapp.cloud.app_secret' => 'app-secret',
            'whatsapp.cloud.template.name' => 'fiche_police_v2',
            'whatsapp.cloud.template.language' => 'fr',
            'whatsapp.pricing.rates.service' => 0.0,
        ]);
    }

    // ── Entrants ─────────────────────────────────────────────────────────────

    public function test_an_incoming_reply_opens_a_thread_and_is_kept(): void
    {
        $this->postSigned($this->inboundPayload('wamid.IN1', '21620123456', 'Reçu, merci'))->assertOk();

        $conversation = WhatsappConversation::sole();
        $this->assertSame('21620123456', $conversation->phone);
        $this->assertSame(1, $conversation->unread_count);
        $this->assertSame('inbound', $conversation->last_message_direction);
        $this->assertSame('Reçu, merci', $conversation->last_message_preview);

        $message = WhatsappConversationMessage::sole();
        $this->assertSame('inbound', $message->direction);
        $this->assertSame('Reçu, merci', $message->body);
        $this->assertSame('text', $message->type);
    }

    public function test_the_message_body_is_encrypted_at_rest(): void
    {
        $this->postSigned($this->inboundPayload('wamid.SECRET', '21620123456', 'Passeport X1234567'))->assertOk();

        // Le contenu d'un fil avec un poste de police peut porter un nom, un
        // numéro de document, une consigne. Une copie de base volée doit rester
        // illisible sans APP_KEY.
        $raw = \DB::table('whatsapp_conversation_messages')->value('body');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('X1234567', (string) $raw);
        $this->assertSame('Passeport X1234567', WhatsappConversationMessage::sole()->body);
    }

    public function test_replayed_inbound_webhooks_do_not_inflate_the_unread_counter(): void
    {
        // Meta rejoue toute livraison non acquittée. Un compteur qui monte à
        // chaque rejeu annoncerait « 4 nouveaux » pour un seul message.
        foreach (range(1, 4) as $ignored) {
            $this->postSigned($this->inboundPayload('wamid.REPLAY', '21620123456', 'Bonjour'))->assertOk();
        }

        $this->assertSame(1, WhatsappConversationMessage::count());
        $this->assertSame(1, WhatsappConversation::sole()->unread_count);
    }

    public function test_a_crash_between_the_two_writes_leaves_no_orphan_message(): void
    {
        /*
         * `recordInbound` fait deux écritures pour un seul événement :
         * enregistrer le message, puis avancer `last_inbound_at` sur la
         * conversation — c'est cette seconde valeur que la fenêtre de service
         * de 24 h utilise pour savoir si une réponse est possible.
         *
         * Reproduit ici en interrompant le save() de la conversation via un
         * évènement Eloquent, ce qui simule fidèlement un redémarrage du
         * conteneur entre les deux écritures (déploiement, OOM) — le point de
         * défaillance, pas sa cause, est ce qui compte pour ce test.
         *
         * Sans transaction : le message reste enregistré (webhook déjà
         * répondu 200 à Meta, qui ne le renverra plus), mais
         * `last_inbound_at` ne bouge jamais. La fenêtre de réponse se calcule
         * alors sur une valeur périmée — ou reste fermée avec « jamais
         * répondu » si aucun inbound n'avait encore été enregistré — et
         * l'agent voit « cette autorité n'a jamais écrit » alors qu'elle
         * vient de le faire.
         */
        // `forPhone()` ouvre d'abord le fil (une création, donc un premier
        // save() légitime) : ne faire échouer que la mise à jour qui suit,
        // celle qui porte `last_inbound_at`, sous peine de ne rien prouver.
        WhatsappConversation::saving(function (WhatsappConversation $model) {
            if ($model->exists && $model->isDirty('last_inbound_at')) {
                throw new \RuntimeException('panne simulée entre les deux écritures');
            }
        });

        try {
            $this->postSigned($this->inboundPayload('wamid.CRASH', '21620123456', 'Bonjour'));
        } finally {
            \Illuminate\Support\Facades\Event::forget('eloquent.saving: '.WhatsappConversation::class);
        }

        // Le fil existe (ouvert par forPhone(), avant la panne simulée), mais
        // n'a jamais reçu son inbound : la panne a bien empêché la SECONDE
        // écriture, pas seulement laissé une trace en erreur.
        $conversation = WhatsappConversation::sole();
        $this->assertNull($conversation->last_inbound_at);
        $this->assertSame(
            0,
            WhatsappConversationMessage::count(),
            "Le message entrant n'aurait pas dû survivre à l'échec de la mise à jour de la conversation.",
        );
    }

    public function test_the_same_number_written_differently_shares_one_thread(): void
    {
        // La fiche est enfilée vers « +216 20 123 456 », l'agent répond depuis
        // « 21620123456 ». Deux fils voudraient dire deux boîtes de réception à
        // moitié pleines, et un compteur de non-lus faux dans les deux.
        $this->fiche('+216 20 123 456');

        $this->postSigned($this->inboundPayload('wamid.IN2', '21620123456', 'Ok'))->assertOk();

        $this->assertSame(1, WhatsappConversation::count());
        $this->assertSame(1, WhatsappSendLog::sole()->conversation_id !== null ? 1 : 0);
    }

    public function test_an_image_without_caption_is_kept_with_its_type(): void
    {
        $this->postSigned(['entry' => [['changes' => [['value' => [
            'messages' => [[
                'id' => 'wamid.IMG',
                'from' => '21620123456',
                'type' => 'image',
                'timestamp' => (string) now()->timestamp,
                'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg'],
            ]],
        ]]]]]])->assertOk();

        $message = WhatsappConversationMessage::sole();
        // Pas de corps inventé : un libellé écrit ici passerait pour un texte
        // écrit par l'agent.
        $this->assertNull($message->body);
        $this->assertSame('image', $message->type);
        $this->assertSame('media-1', $message->media_id);
        $this->assertSame('[image]', WhatsappConversation::sole()->last_message_preview);
    }

    public function test_a_reply_to_a_fiche_keeps_the_link_to_it(): void
    {
        $job = $this->fiche('21620123456', 'wamid.FICHE');

        $this->postSigned(['entry' => [['changes' => [['value' => [
            'messages' => [[
                'id' => 'wamid.CTX',
                'from' => '21620123456',
                'type' => 'text',
                'timestamp' => (string) now()->timestamp,
                'context' => ['id' => 'wamid.FICHE'],
                'text' => ['body' => 'Il manque le passeport'],
            ]],
        ]]]]]])->assertOk();

        $this->assertSame('wamid.FICHE', WhatsappConversationMessage::sole()->context_wamid);
        $this->assertSame($job->message_id_whatsapp, WhatsappConversationMessage::sole()->context_wamid);
    }

    public function test_the_whatsapp_profile_name_is_stored_when_meta_provides_it(): void
    {
        $this->postSigned(['entry' => [['changes' => [['value' => [
            'contacts' => [['wa_id' => '21620123456', 'profile' => ['name' => 'Poste Lac 2']]],
            'messages' => [[
                'id' => 'wamid.NAME',
                'from' => '21620123456',
                'type' => 'text',
                'timestamp' => (string) now()->timestamp,
                'text' => ['body' => 'Bien reçu'],
            ]],
        ]]]]]])->assertOk();

        $this->assertSame('Poste Lac 2', WhatsappConversation::sole()->contact_name);
    }

    public function test_a_thread_resolves_the_authority_agent_behind_the_number(): void
    {
        $profile = $this->agent('21620123456', 'Karim', 'Ben Salah');

        $this->postSigned($this->inboundPayload('wamid.WHO', '21620123456', 'Reçu'))->assertOk();

        $this->assertSame($profile->id, WhatsappConversation::sole()->authority_user_profile_id);
    }

    // ── Sortants ─────────────────────────────────────────────────────────────

    public function test_sending_a_fiche_opens_the_thread_before_any_reply(): void
    {
        // Un poste de police à qui l'on écrit pour la première fois doit
        // apparaître dans la boîte de réception, même s'il ne répond jamais.
        $job = $this->fiche('21620123456');

        $conversation = WhatsappConversation::sole();
        $this->assertSame($conversation->id, $job->fresh()->conversation_id);
        $this->assertSame(0, $conversation->unread_count);
    }

    public function test_the_thread_list_never_carries_a_traveller_name(): void
    {
        $this->fiche('21620123456');

        // La liste des fils est un écran de supervision du canal, pas un
        // registre des personnes contrôlées.
        $this->assertSame('Fiche de police transmise', WhatsappConversation::sole()->last_message_preview);
    }

    // ── Fenêtre de service ───────────────────────────────────────────────────

    public function test_a_free_text_reply_is_refused_outside_the_24h_window(): void
    {
        Http::fake();

        $conversation = WhatsappConversation::create([
            'phone' => '21620123456',
            'last_inbound_at' => now()->subHours(25),
        ]);

        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/whatsapp/inbox/{$conversation->id}/reply", ['message' => 'Bonjour'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'SERVICE_WINDOW_CLOSED');

        // Refusé AVANT Meta : un appel qu'on sait refusé n'a pas à être fait.
        Http::assertNothingSent();
        $this->assertSame(0, WhatsappConversationMessage::count());
    }

    public function test_a_thread_that_never_replied_says_so_rather_than_expired(): void
    {
        $this->fiche('21620123456');
        $conversation = WhatsappConversation::sole();
        $admin = User::factory()->platformAdmin()->create();

        $reply = $this->actingAs($admin)
            ->getJson("/api/v1/admin/whatsapp/inbox/{$conversation->id}")
            ->assertOk()
            ->json('data.reply');

        $this->assertFalse($reply['allowed']);
        $this->assertSame('NEVER_REPLIED', $reply['reason']);
        $this->assertNull($reply['window_closes_at']);
    }

    public function test_a_reply_inside_the_window_is_sent_stored_and_billed_as_service(): void
    {
        Http::fake([
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.REPLY']]], 200),
        ]);

        $this->postSigned($this->inboundPayload('wamid.IN3', '21620123456', 'Question ?'))->assertOk();

        $conversation = WhatsappConversation::sole();
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/whatsapp/inbox/{$conversation->id}/reply", ['message' => 'Voici le numéro.'])
            ->assertCreated()
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.status', 'accepted');

        $reply = WhatsappConversationMessage::where('direction', 'outbound')->sole();
        $this->assertSame('Voici le numéro.', $reply->body);
        $this->assertSame($admin->id, $reply->sent_by_user_id);

        // La réponse est un message de SERVICE : gratuit jusqu'au 30/09/2026,
        // puis au tarif utility. Le compter dès maintenant fait que la bascule
        // sera un changement de variable, pas la découverte d'un poste.
        $billable = WhatsappBillableMessage::find('wamid.REPLY');
        $this->assertNotNull($billable);
        $this->assertSame(WhatsappBillableMessage::CATEGORY_SERVICE, $billable->category);
        $this->assertNull($billable->hotel_id);
    }

    public function test_the_global_kill_switch_stops_replies_too(): void
    {
        Http::fake();

        $this->postSigned($this->inboundPayload('wamid.KILL', '21620123456', 'Question ?'))->assertOk();
        $conversation = WhatsappConversation::sole();
        $admin = User::factory()->platformAdmin()->create();

        // Le coupe-circuit est le geste qui arrête TOUT quand Meta signale la
        // qualité du numéro — il a déjà coûté un numéro à ce produit. La boîte
        // de réception ouvre un chemin d'émission de plus : il devait être
        // couvert, sinon on continue d'écrire à des postes de police pendant
        // la période où l'on cherche à ne plus rien envoyer.
        config(['whatsapp.guard.sending_enabled' => false]);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/whatsapp/inbox/{$conversation->id}/reply", ['message' => 'Réponse'])
            ->assertStatus(503)
            ->assertJsonPath('errors.0.code', 'WHATSAPP_SENDING_DISABLED');

        Http::assertNothingSent();
        $this->assertSame(0, WhatsappConversationMessage::where('direction', 'outbound')->count());
    }

    public function test_a_reply_refused_by_meta_is_still_recorded(): void
    {
        Http::fake([
            '*/messages' => Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400),
        ]);

        $this->postSigned($this->inboundPayload('wamid.IN4', '21620123456', 'Question ?'))->assertOk();
        $conversation = WhatsappConversation::sole();
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/whatsapp/inbox/{$conversation->id}/reply", ['message' => 'Réponse'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'REPLY_REFUSED');

        // La tentative reste visible : un message disparu passerait pour un
        // message reçu.
        $failed = WhatsappConversationMessage::where('direction', 'outbound')->sole();
        $this->assertSame('failed', $failed->status);
        $this->assertNull($failed->wamid);
    }

    public function test_delivery_receipts_of_an_admin_reply_reach_the_thread(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.RCPT']]], 200)]);

        $this->postSigned($this->inboundPayload('wamid.IN5', '21620123456', 'Question ?'))->assertOk();
        $conversation = WhatsappConversation::sole();
        $admin = User::factory()->platformAdmin()->create();
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/whatsapp/inbox/{$conversation->id}/reply", ['message' => 'Réponse'])
            ->assertCreated();

        $this->postSigned($this->statusPayload('wamid.RCPT', 'delivered'))->assertOk();
        $this->postSigned($this->statusPayload('wamid.RCPT', 'read'))->assertOk();

        $reply = WhatsappConversationMessage::where('wamid', 'wamid.RCPT')->sole();
        $this->assertSame('read', $reply->status);
        $this->assertNotNull($reply->delivered_at);
        $this->assertNotNull($reply->read_at);
    }

    public function test_a_late_sent_receipt_never_makes_a_read_message_go_backwards(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.ORDER']]], 200)]);

        $this->postSigned($this->inboundPayload('wamid.IN6', '21620123456', 'Question ?'))->assertOk();
        $conversation = WhatsappConversation::sole();
        $admin = User::factory()->platformAdmin()->create();
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/whatsapp/inbox/{$conversation->id}/reply", ['message' => 'Réponse'])
            ->assertCreated();

        // Meta ne garantit pas l'ordre des accusés.
        $this->postSigned($this->statusPayload('wamid.ORDER', 'read'))->assertOk();
        $this->postSigned($this->statusPayload('wamid.ORDER', 'sent'))->assertOk();

        $this->assertSame('read', WhatsappConversationMessage::where('wamid', 'wamid.ORDER')->sole()->status);
    }

    // ── Écran ────────────────────────────────────────────────────────────────

    public function test_the_timeline_merges_fiches_and_messages_in_order(): void
    {
        $this->travelTo(now()->subHours(3));
        $this->fiche('21620123456', 'wamid.T1');

        $this->travelTo(now()->addHours(2));
        $this->postSigned($this->inboundPayload('wamid.T2', '21620123456', 'Bien reçu'))->assertOk();

        $this->travelBack();

        $conversation = WhatsappConversation::sole();
        $admin = User::factory()->platformAdmin()->create();

        $timeline = $this->actingAs($admin)
            ->getJson("/api/v1/admin/whatsapp/inbox/{$conversation->id}")
            ->assertOk()
            ->json('data.timeline');

        // Deux tables, une seule chronologie : le tri se fait à la lecture.
        $this->assertCount(2, $timeline);
        $this->assertSame('fiche', $timeline[0]['kind']);
        $this->assertSame('message', $timeline[1]['kind']);
        $this->assertSame('Bien reçu', $timeline[1]['body']);
    }

    public function test_opening_a_thread_clears_its_unread_counter(): void
    {
        $this->postSigned($this->inboundPayload('wamid.U1', '21620123456', 'A'))->assertOk();
        $this->postSigned($this->inboundPayload('wamid.U2', '21620123456', 'B'))->assertOk();

        $conversation = WhatsappConversation::sole();
        $this->assertSame(2, $conversation->unread_count);

        $admin = User::factory()->platformAdmin()->create();
        $this->actingAs($admin)->getJson("/api/v1/admin/whatsapp/inbox/{$conversation->id}")->assertOk();

        $this->assertSame(0, $conversation->fresh()->unread_count);
    }

    public function test_the_list_filters_on_unread_and_awaiting_reply(): void
    {
        $this->fiche('21620111111');
        $this->postSigned($this->inboundPayload('wamid.F1', '21620222222', 'Question ?'))->assertOk();

        $admin = User::factory()->platformAdmin()->create();

        $all = $this->actingAs($admin)->getJson('/api/v1/admin/whatsapp/inbox')->assertOk()->json();
        $this->assertCount(2, $all['data']);
        $this->assertSame(1, $all['meta']['unread_total']);

        $unread = $this->actingAs($admin)->getJson('/api/v1/admin/whatsapp/inbox?filter=unread')->json('data');
        $this->assertCount(1, $unread);
        $this->assertSame('21620222222', $unread[0]['phone']);

        // « awaiting » : l'agent a écrit et rien n'est reparti depuis — la file
        // de travail réelle d'un administrateur.
        $awaiting = $this->actingAs($admin)->getJson('/api/v1/admin/whatsapp/inbox?filter=awaiting')->json('data');
        $this->assertCount(1, $awaiting);
        $this->assertSame('21620222222', $awaiting[0]['phone']);
    }

    public function test_the_search_matches_a_number_written_with_spaces(): void
    {
        $this->agent('21620123456', 'Karim', 'Ben Salah');
        $this->postSigned($this->inboundPayload('wamid.S1', '21620123456', 'Ok'))->assertOk();
        $this->postSigned($this->inboundPayload('wamid.S2', '21620999999', 'Ok'))->assertOk();

        $admin = User::factory()->platformAdmin()->create();

        $byName = $this->actingAs($admin)->getJson('/api/v1/admin/whatsapp/inbox?search=Ben')->json('data');
        $this->assertCount(1, $byName);
        $this->assertSame('21620123456', $byName[0]['phone']);

        $byNumber = $this->actingAs($admin)->getJson('/api/v1/admin/whatsapp/inbox?search=20 12 34 56')->json('data');
        $this->assertCount(1, $byNumber);
        $this->assertSame('21620123456', $byNumber[0]['phone']);
    }

    // ── Accès ────────────────────────────────────────────────────────────────

    public function test_the_inbox_is_closed_to_anyone_but_a_platform_admin(): void
    {
        $this->postSigned($this->inboundPayload('wamid.P1', '21620123456', 'Ok'))->assertOk();
        $conversation = WhatsappConversation::sole();

        $receptionist = User::factory()->create();
        $receptionist->assignRole('receptionist');

        $this->actingAs($receptionist)->getJson('/api/v1/admin/whatsapp/inbox')->assertForbidden();
        $this->actingAs($receptionist)->getJson("/api/v1/admin/whatsapp/inbox/{$conversation->id}")->assertForbidden();
        $this->actingAs($receptionist)
            ->postJson("/api/v1/admin/whatsapp/inbox/{$conversation->id}/reply", ['message' => 'x'])
            ->assertForbidden();
    }

    public function test_a_thread_survives_the_deletion_of_the_agent_profile(): void
    {
        $profile = $this->agent('21620123456', 'Karim', 'Ben Salah');
        $this->postSigned($this->inboundPayload('wamid.D1', '21620123456', 'Ok'))->assertOk();

        $profile->delete();

        // L'échange a eu lieu : l'effacer réécrirait l'histoire.
        $conversation = WhatsappConversation::sole();
        $this->assertNull($conversation->fresh()->authority_user_profile_id);
        $this->assertSame(1, WhatsappConversationMessage::count());
    }

    // ── Utilitaires ──────────────────────────────────────────────────────────

    private function fiche(string $recipient, ?string $wamid = null): WhatsappSendLog
    {
        $job = WhatsappSendLog::create([
            'hotel_id' => $this->hotel->id,
            'recipient' => $recipient,
            'caption' => 'Fiche',
            'status' => WhatsappSendLog::STATUS_SENT,
            'message_id_whatsapp' => $wamid,
            'template_name' => 'fiche_police_v2',
            'queued_at' => now(),
            'sent_at' => now(),
        ]);

        $service = app(\App\Services\Whatsapp\WhatsappConversationService::class);
        $service->attachSendLog($job);
        $service->touchOutbound($job);

        return $job;
    }

    private function agent(string $number, string $firstName, string $lastName): AuthorityUserProfile
    {
        $organization = AuthorityOrganization::create([
            'name' => 'Poste de police du Lac',
            'type' => 'police',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
        $user->assignRole('authority_user');

        return AuthorityUserProfile::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'whatsapp_number' => $number,
            'receives_whatsapp_fiches' => true,
            'authorized_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function inboundPayload(string $wamid, string $from, string $body): array
    {
        return ['entry' => [['changes' => [['value' => [
            'messages' => [[
                'id' => $wamid,
                'from' => $from,
                'type' => 'text',
                'timestamp' => (string) now()->timestamp,
                'text' => ['body' => $body],
            ]],
        ]]]]]];
    }

    /** @return array<string,mixed> */
    private function statusPayload(string $wamid, string $status): array
    {
        return ['entry' => [['changes' => [['value' => ['statuses' => [[
            'id' => $wamid,
            'status' => $status,
            'timestamp' => (string) now()->timestamp,
        ]]]]]]]];
    }

    /** @param  array<string,mixed>  $payload */
    private function postSigned(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body);
    }
}
