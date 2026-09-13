<?php

use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/**
 * GET/POST/PATCH /api/owner/services (05-api-contracts.md endpoint 8,
 * D-0012/D-0051). `store()` has been real since Session 10 with no
 * dedicated Feature test of its own; `index()`/`update()` are new this
 * session, closing `05`'s own "still unbuilt" note. This file covers all
 * three, not just the two additions, since none had coverage before.
 */
function ownerServiceTenant(): Tenant
{
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () use ($tenant) {
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'email' => 'owner@example.test',
            'password_hash' => Hash::make('correct-password'),
        ]);
    });

    return $tenant;
}

function loginAsServiceOwner(Tenant $tenant): string
{
    disableConsoleCsrfBypass();

    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'owner@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    return $xsrf;
}

test('an owner creates a service with required buffer fields', function () {
    $tenant = ownerServiceTenant();
    $xsrf = loginAsServiceOwner($tenant);

    $response = postJson('/api/owner/services', [
        'name' => 'Small Flash',
        'duration_minutes' => 60,
        'price_amount' => 10000,
        'currency' => 'usd',
        'deposit_type' => 'fixed',
        'deposit_fixed_amount' => 2000,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 15,
    ], ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $response->assertCreated();
    $response->assertJson(['name' => 'Small Flash', 'buffer_after_minutes' => 15]);
});

test('creating a service without buffer fields is rejected', function () {
    $tenant = ownerServiceTenant();
    $xsrf = loginAsServiceOwner($tenant);

    $response = postJson('/api/owner/services', [
        'name' => 'Small Flash',
        'duration_minutes' => 60,
        'price_amount' => 10000,
        'currency' => 'usd',
        'deposit_type' => 'fixed',
        'deposit_fixed_amount' => 2000,
    ], ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $response->assertStatus(422);
});

test('an owner lists every one of their own services, including inactive ones, never another tenant\'s', function () {
    $tenant = ownerServiceTenant();

    $active = TenantContext::run($tenant->id, fn () => Service::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Active Service', 'is_active' => true]));
    $inactive = TenantContext::run($tenant->id, fn () => Service::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Inactive Service', 'is_active' => false]));

    $otherTenant = Tenant::factory()->create();
    TenantContext::run($otherTenant->id, fn () => Service::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'Other Tenant Service']));

    $xsrf = loginAsServiceOwner($tenant);

    $response = getJson('/api/owner/services', ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);
    $response->assertOk();

    $names = collect($response->json('services'))->pluck('name');
    expect($names)->toContain('Active Service');
    expect($names)->toContain('Inactive Service');
    expect($names)->not->toContain('Other Tenant Service');
});

test('an owner updates a service\'s name and buffer without touching deposit fields', function () {
    $tenant = ownerServiceTenant();
    $service = TenantContext::run($tenant->id, fn () => Service::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Original Name',
        'deposit_type' => 'fixed',
        'deposit_fixed_amount' => 2000,
        'deposit_percentage_bps' => null,
    ]));

    $xsrf = loginAsServiceOwner($tenant);

    $response = patchJson("/api/owner/services/{$service->id}", [
        'name' => 'Updated Name',
        'buffer_after_minutes' => 30,
    ], ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $response->assertOk();
    $response->assertJson([
        'name' => 'Updated Name',
        'buffer_after_minutes' => 30,
        'deposit_type' => 'fixed',
        'deposit_fixed_amount' => 2000,
    ]);
});

test('an owner switching deposit_type without the matching amount is rejected, not a 500', function () {
    $tenant = ownerServiceTenant();
    $service = TenantContext::run($tenant->id, fn () => Service::factory()->create([
        'tenant_id' => $tenant->id,
        'deposit_type' => 'fixed',
        'deposit_fixed_amount' => 2000,
        'deposit_percentage_bps' => null,
    ]));

    $xsrf = loginAsServiceOwner($tenant);

    $response = patchJson("/api/owner/services/{$service->id}", [
        'deposit_type' => 'percentage',
    ], ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $response->assertStatus(422);
    $response->assertJson(['error' => 'VALIDATION_FAILED']);
});

test('an owner switching deposit_type together with the matching amount succeeds and clears the other field', function () {
    $tenant = ownerServiceTenant();
    $service = TenantContext::run($tenant->id, fn () => Service::factory()->create([
        'tenant_id' => $tenant->id,
        'deposit_type' => 'fixed',
        'deposit_fixed_amount' => 2000,
        'deposit_percentage_bps' => null,
    ]));

    $xsrf = loginAsServiceOwner($tenant);

    $response = patchJson("/api/owner/services/{$service->id}", [
        'deposit_type' => 'percentage',
        'deposit_percentage_bps' => 1000,
    ], ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $response->assertOk();
    $response->assertJson([
        'deposit_type' => 'percentage',
        'deposit_percentage_bps' => 1000,
        'deposit_fixed_amount' => null,
    ]);
});

test('an owner cannot update another tenant\'s service, gets a plain 404', function () {
    $tenant = ownerServiceTenant();
    $otherTenant = Tenant::factory()->create();
    $foreignService = TenantContext::run($otherTenant->id, fn () => Service::factory()->create(['tenant_id' => $otherTenant->id]));

    $xsrf = loginAsServiceOwner($tenant);

    $response = patchJson("/api/owner/services/{$foreignService->id}", [
        'name' => 'Hijacked',
    ], ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $response->assertStatus(404);
    $response->assertJson(['error' => 'NOT_FOUND']);
});

test('an unauthenticated request to list owner services is rejected', function () {
    getJson('/api/owner/services')->assertStatus(401);
});
