<?php

use App\Models\Service;
use App\Models\Tenant;
use App\Tenancy\SignedTenantToken;
use App\Tenancy\TenantContext;
use Tests\Support\BookingFixture;

use function Pest\Laravel\getJson;

/**
 * The D-0025 condition, exercised through real HTTP requests rather than
 * TenantContext::run() directly: Pest's feature-test client runs entirely
 * in-process, so sequential requests within one test genuinely reuse the
 * same physical database connection — exactly the PgBouncer
 * transaction-mode condition D-0025 found (a connection that has ever set
 * the tenant GUC gets an empty string, not NULL, once cleared). If
 * SetTenantContext's transaction-per-request boundary (or the RLS policy's
 * own NULLIF hardening) ever regressed, this is the level at which it would
 * actually surface as a real cross-request leak, not just a unit-level one.
 */
test('sequential requests for two different tenants on the same connection never see each other\'s data', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::run($tenantA->id, fn () => Service::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Tenant A Service']));
    TenantContext::run($tenantB->id, fn () => Service::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Tenant B Service']));

    $responseA = getJson("/api/tenants/{$tenantA->slug}/services");
    $responseB = getJson("/api/tenants/{$tenantB->slug}/services");
    $responseA2 = getJson("/api/tenants/{$tenantA->slug}/services");

    $responseA->assertOk();
    expect(collect($responseA->json('services'))->pluck('name'))->toEqual(collect(['Tenant A Service']));

    $responseB->assertOk();
    expect(collect($responseB->json('services'))->pluck('name'))->toEqual(collect(['Tenant B Service']));

    // A third request, back to tenant A, on the same reused connection —
    // proves tenant B's context from the middle request didn't linger.
    $responseA2->assertOk();
    expect(collect($responseA2->json('services'))->pluck('name'))->toEqual(collect(['Tenant A Service']));
});

test('a failed-resolution request never leaves residual context for the next request on the same connection', function () {
    $tenant = Tenant::factory()->create();
    TenantContext::run($tenant->id, fn () => Service::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Real Service']));

    $badSlug = getJson('/api/tenants/no-such-studio/services');
    $badSlug->assertStatus(404);

    // SetTenantContext never even ran for the request above (the resolver
    // middleware short-circuited before calling $next()) — the immediately
    // following request must still resolve its own tenant correctly, not
    // inherit whatever (nothing) the failed request left behind.
    $good = getJson("/api/tenants/{$tenant->slug}/services");
    $good->assertOk();
    expect(collect($good->json('services'))->pluck('name'))->toEqual(collect(['Real Service']));
});

test('a slug-resolved request and a token-resolved request for different tenants interleave correctly on the same connection', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::run($tenantA->id, fn () => Service::factory()->create(['tenant_id' => $tenantA->id]));
    $appointmentB = BookingFixture::appointmentFor($tenantB);
    $tokenB = SignedTenantToken::issue('manage_booking', $tenantB->id, $appointmentB->id);

    $slugResponse = getJson("/api/tenants/{$tenantA->slug}/services");
    $tokenResponse = getJson("/api/bookings/manage/{$tokenB}");
    $slugResponseAgain = getJson("/api/tenants/{$tenantA->slug}/services");

    $slugResponse->assertOk();
    $tokenResponse->assertOk()->assertJsonPath('appointment_id', $appointmentB->id);
    $slugResponseAgain->assertOk();
    expect($slugResponseAgain->json('services'))->toHaveCount(1);
});
