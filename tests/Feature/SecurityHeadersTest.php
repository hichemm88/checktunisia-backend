<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SecurityHeaders middleware must add defence-in-depth headers to every
 * API response (checked here on a public, unauthenticated endpoint).
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_carry_security_headers(): void
    {
        $res = $this->getJson('/api/v1/referential/countries');

        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'DENY');
        $res->assertHeader('Referrer-Policy', 'no-referrer');
        $res->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
    }
}
