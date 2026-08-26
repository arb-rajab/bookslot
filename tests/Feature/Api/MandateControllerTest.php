<?php

use App\Mandates\MandateRenderer;
use App\Models\Service;
use App\Models\Tenant;
use App\Tenancy\TenantContext;

use function Pest\Laravel\getJson;

/**
 * GET /api/tenants/{slug}/services/{service}/mandate — the pre-submission
 * mandate display endpoint this session added (D-0030), closing the gap
 * 05-api-contracts.md's Session 9 amendment flagged: nothing served the
 * mandate text a customer must see before the consent checkbox. Proves
 * this endpoint and the booking-creation storage path (BookingControllerTest)
 * share exactly one renderer, not two independently-maintained ones.
 */
test('the pre-submission mandate endpoint renders identically to what booking creation stores', function () {
    $tenant = Tenant::factory()->create();
    $service = TenantContext::run($tenant->id, fn () => Service::factory()->create([
        'tenant_id' => $tenant->id,
        'price_amount' => 20000,
        'currency' => 'usd',
        'deposit_type' => 'fixed',
        'deposit_fixed_amount' => 5000,
        'deposit_percentage_bps' => null,
    ]));

    $response = getJson("/api/tenants/{$tenant->slug}/services/{$service->id}/mandate");

    $response->assertOk();

    $expected = (new MandateRenderer)->render($tenant, $service);
    $response->assertJson([
        'template_version' => $expected['template_version'],
        'text' => $expected['text'],
        'balance_amount_disclosed' => $expected['balance_amount_disclosed'],
    ]);
    $response->assertJsonPath('balance_amount_disclosed', 15000);
});

test('the mandate endpoint 404s for a service belonging to a different tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $serviceB = TenantContext::run($tenantB->id, fn () => Service::factory()->create(['tenant_id' => $tenantB->id]));

    getJson("/api/tenants/{$tenantA->slug}/services/{$serviceB->id}/mandate")
        ->assertStatus(404)
        ->assertJson(['error' => 'NOT_FOUND']);
});
