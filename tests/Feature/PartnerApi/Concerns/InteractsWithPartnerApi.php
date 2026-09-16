<?php

namespace Tests\Feature\PartnerApi\Concerns;

use App\Models\ApiKey;
use App\Models\ApiPartner;
use App\Models\EstablishmentPartnerLink;
use App\Models\Hotel;
use App\Models\Organization;
use App\Models\PartnerWebhookEndpoint;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\PartnerApi\ApiKeyService;
use App\Services\PartnerApi\EstablishmentLinkService;

/**
 * Setup helpers shared by the Partner API + widget test suite. Mirrors the
 * conventions already used elsewhere in this repo (see PlanEntitlementsTest,
 * CheckInFlowTest): plain Eloquent creates for models with no dedicated
 * factory (Organization, Subscription, ApiPartner, ApiKey, ...), and real
 * service calls only for the pieces that would otherwise require standing up
 * a full authenticated admin/owner HTTP flow just to seed fixtures.
 */
trait InteractsWithPartnerApi
{
    /**
     * A dedicated, non-empty WIDGET_JWT_SECRET for the test process — required
     * or WidgetTokenService throws. Mirrors how other service secrets (e.g.
     * AI_TRACKING_SECRET) are provided in this suite: config() set directly in
     * the test, not through phpunit.xml/.env.testing (see AiCostTrackingTest).
     */
    protected function seedWidgetJwtSecret(): void
    {
        config(['partner_api.jwt.secret' => 'test-widget-jwt-secret-not-for-prod']);
    }

    /**
     * @return array{org: Organization, hotel: Hotel, plan: SubscriptionPlan, subscription: Subscription}
     */
    protected function makeOrgWithHotel(bool $apiAccess = true, array $planFeatures = []): array
    {
        $org = Organization::create([
            'name' => 'Partner Org '.uniqid(),
            'entity_type' => 'company',
            'contact_email' => 'org+'.uniqid().'@test.tn',
            'status' => 'active',
        ]);

        $hotel = Hotel::factory()->withActiveSubscription()->create(['organization_id' => $org->id]);

        $plan = SubscriptionPlan::create([
            'name' => 'Partner Plan '.uniqid(),
            'slug' => 'partner-plan-'.uniqid(),
            'min_rooms' => 1,
            'max_rooms' => null,
            'price_monthly' => 99,
            'currency' => 'TND',
            'is_active' => true,
            'sort_order' => 9,
            'features' => array_merge(['api_access' => $apiAccess], $planFeatures),
        ]);

        $subscription = Subscription::create([
            'organization_id' => $org->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'started_at' => now()->subDay(),
            'expires_at' => now()->addMonth(),
        ]);

        return ['org' => $org, 'hotel' => $hotel, 'plan' => $plan, 'subscription' => $subscription];
    }

    protected function makePartner(array $attrs = []): ApiPartner
    {
        return ApiPartner::create(array_merge([
            'name' => 'Diar Test Partner',
            'status' => 'active',
            'allowed_widget_origins' => [],
        ], $attrs));
    }

    /** @return array{key: ApiKey, plaintext: string} */
    protected function issuePartnerKey(ApiPartner $partner, string $mode = ApiKey::MODE_LIVE): array
    {
        return app(ApiKeyService::class)->issue($partner, $mode);
    }

    protected function linkEstablishment(ApiPartner $partner, Hotel $hotel): EstablishmentPartnerLink
    {
        return EstablishmentPartnerLink::create([
            'hotel_id' => $hotel->id,
            'partner_id' => $partner->id,
            'linked_at' => now(),
        ]);
    }

    protected function makeWebhookEndpoint(ApiPartner $partner, array $attrs = []): PartnerWebhookEndpoint
    {
        return PartnerWebhookEndpoint::create(array_merge([
            'partner_id' => $partner->id,
            'url' => 'https://partner.example.test/webhooks/qayed',
            'secret' => 'whsec_test_'.uniqid(),
            'events' => ['fiche.submitted', 'fiche.failed', 'session.expired'],
            'active' => true,
        ], $attrs));
    }

    /** Full partner fixture: org+hotel (api_access on), partner, live key, active link. */
    protected function setUpLinkedPartner(bool $apiAccess = true): array
    {
        $this->seedWidgetJwtSecret();

        ['org' => $org, 'hotel' => $hotel, 'plan' => $plan, 'subscription' => $subscription] = $this->makeOrgWithHotel($apiAccess);
        $partner = $this->makePartner();
        ['key' => $key, 'plaintext' => $plaintext] = $this->issuePartnerKey($partner, ApiKey::MODE_LIVE);
        $link = $this->linkEstablishment($partner, $hotel);

        return compact('org', 'hotel', 'plan', 'subscription', 'partner', 'key', 'plaintext', 'link');
    }

    protected function bearer(string $plaintext): array
    {
        return ['Authorization' => 'Bearer '.$plaintext];
    }

    protected function hotelOwner(Hotel $hotel): User
    {
        return User::factory()->hotelAdmin($hotel)->create();
    }

    /** Standard fiche-session creation payload. */
    protected function fichePayload(Hotel $hotel, string $bookingRef, array $guests = [], array $overrides = []): array
    {
        return array_merge([
            'establishment_id' => $hotel->id,
            'booking_ref' => $bookingRef,
            'arrival_date' => now()->addDay()->toDateString(),
            'departure_date' => now()->addDays(3)->toDateString(),
            'room' => '101',
            'guests' => $guests,
        ], $overrides);
    }

    /** Minimal valid widget guest payload for POST /widget/v1/guests. */
    protected function widgetGuestPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ahmed',
            'last_name' => 'Ben Salah',
            'date_of_birth' => '1985-03-15',
            'sex' => 'M',
            'nationality_code' => 'TUN',
            'is_primary' => true,
            'document' => [
                'type' => 'passport',
                'document_number' => 'TN'.random_int(10000000, 99999999),
                'issuing_country_code' => 'TUN',
            ],
        ], $overrides);
    }
}
