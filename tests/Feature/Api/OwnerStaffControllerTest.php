<?php

use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/**
 * GET/POST/PATCH /api/owner/staff (05-api-contracts.md's endpoint list,
 * D-0051) — the staff-management surface availability/schedule management
 * depends on (working hours and availability exceptions are always scoped
 * to a staff row).
 */
function ownerStaffTenant(): Tenant
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

function loginAsStaffOwner(Tenant $tenant): string
{
    disableConsoleCsrfBypass();

    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'owner@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    return $xsrf;
}

test('an owner creates a staff member', function () {
    $tenant = ownerStaffTenant();
    $xsrf = loginAsStaffOwner($tenant);

    $response = postJson('/api/owner/staff', ['display_name' => 'Jamie Artist'], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertCreated();
    $response->assertJson(['display_name' => 'Jamie Artist', 'is_active' => true]);
});

test('an owner lists only their own tenant\'s staff', function () {
    $tenant = ownerStaffTenant();
    $ownStaff = TenantContext::run($tenant->id, fn () => Staff::factory()->create(['tenant_id' => $tenant->id, 'display_name' => 'Own Staff']));

    $otherTenant = Tenant::factory()->create();
    TenantContext::run($otherTenant->id, fn () => Staff::factory()->create(['tenant_id' => $otherTenant->id, 'display_name' => 'Other Staff']));

    $xsrf = loginAsStaffOwner($tenant);

    $response = getJson('/api/owner/staff', ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);
    $response->assertOk();

    $names = collect($response->json('staff'))->pluck('display_name');
    expect($names)->toContain('Own Staff');
    expect($names)->not->toContain('Other Staff');
});

test('an owner deactivates a staff member', function () {
    $tenant = ownerStaffTenant();
    $staff = TenantContext::run($tenant->id, fn () => Staff::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]));

    $xsrf = loginAsStaffOwner($tenant);

    $response = patchJson("/api/owner/staff/{$staff->id}", ['is_active' => false], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['is_active' => false]);
});

test('an owner cannot update another tenant\'s staff member, gets a plain 404', function () {
    $tenant = ownerStaffTenant();
    $otherTenant = Tenant::factory()->create();
    $foreignStaff = TenantContext::run($otherTenant->id, fn () => Staff::factory()->create(['tenant_id' => $otherTenant->id]));

    $xsrf = loginAsStaffOwner($tenant);

    $response = patchJson("/api/owner/staff/{$foreignStaff->id}", ['display_name' => 'Hijacked'], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(404);
    $response->assertJson(['error' => 'NOT_FOUND']);
});

test('an unauthenticated request to list owner staff is rejected', function () {
    getJson('/api/owner/staff')->assertStatus(401);
});
