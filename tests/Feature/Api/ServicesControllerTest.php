<?php

use App\Models\Service;
use App\Models\Tenant;
use App\Tenancy\TenantContext;

use function Pest\Laravel\getJson;

/**
 * The slug-based tenant-resolution mechanism (D-0009), end to end: a real
 * HTTP request through ResolveTenantFromSlug + SetTenantContext + the
 * controller, not the TenantContext::run() call the tenant-isolation suite
 * drives directly. Per 07-testing-strategy.md's factory convention, both
 * tenants are constructed inline in every test that cares about isolation.
 *
 * Fixture rows are still created inside TenantContext::run(): a factory
 * INSERT is itself subject to the target table's RLS policy (WITH CHECK
 * defaults to the policy's USING expression), so seeding with no context
 * set is rejected exactly like a read would be — this is the same
 * discipline Tests\Support\TenantFixture already documents.
 */
test('lists only the resolved tenant\'s active services', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $activeA = TenantContext::run($tenantA->id, fn () => Service::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Small Flash', 'is_active' => true]));
    TenantContext::run($tenantA->id, fn () => Service::factory()->create(['tenant_id' => $tenantA->id, 'name' => 'Discontinued', 'is_active' => false]));
    TenantContext::run($tenantB->id, fn () => Service::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Tenant B Service', 'is_active' => true]));

    $response = getJson("/api/tenants/{$tenantA->slug}/services");

    $response->assertOk();
    $names = collect($response->json('services'))->pluck('name');

    expect($names)->toContain('Small Flash');
    expect($names)->not->toContain('Discontinued');
    expect($names)->not->toContain('Tenant B Service');
    expect($response->json('services.0.id'))->toBe($activeA->id);
});

test('an unknown slug fails closed with a generic 404, never a 500 or another tenant\'s data', function () {
    $response = getJson('/api/tenants/no-such-studio/services');

    $response->assertStatus(404);
    $response->assertJson(['error' => 'NOT_FOUND']);
});

test('a soft-deleted tenant\'s slug fails closed identically to an unknown slug', function () {
    $tenant = Tenant::factory()->create();
    $tenant->delete();

    $response = getJson("/api/tenants/{$tenant->slug}/services");

    $response->assertStatus(404);
    $response->assertJson(['error' => 'NOT_FOUND']);
});
