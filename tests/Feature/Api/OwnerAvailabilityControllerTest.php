<?php

use App\Models\AvailabilityException;
use App\Models\Staff;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/**
 * GET/PUT /api/owner/staff/{staff}/working-hours,
 * GET/POST/DELETE /api/owner/staff/{staff}/availability-exceptions
 * (05-api-contracts.md endpoint 7, D-0051) — the availability/schedule
 * management surface the admin frontend needs. `GET /api/tenants/{slug}/
 * availability` (D-0039) already reads both tables; this is the
 * previously-missing owner-facing write path.
 */
function ownerAvailabilityTenant(): Tenant
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

function loginAsAvailabilityOwner(Tenant $tenant): string
{
    disableConsoleCsrfBypass();

    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'owner@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    return $xsrf;
}

test('an owner replaces a staff member\'s whole week of working hours in one call', function () {
    $tenant = ownerAvailabilityTenant();
    $staff = TenantContext::run($tenant->id, fn () => Staff::factory()->create(['tenant_id' => $tenant->id]));

    // A pre-existing Monday row that the PUT below must be replaced, not
    // merged with — this is a whole-week replace, not per-row upsert.
    TenantContext::run($tenant->id, fn () => StaffWorkingHour::factory()->create([
        'tenant_id' => $tenant->id, 'staff_id' => $staff->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '12:00',
    ]));

    $xsrf = loginAsAvailabilityOwner($tenant);

    $response = putJson("/api/owner/staff/{$staff->id}/working-hours", [
        'working_hours' => [
            ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
            ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '17:00'],
        ],
    ], ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $response->assertOk();
    $hours = collect($response->json('working_hours'));
    expect($hours)->toHaveCount(2);
    expect($hours->firstWhere('day_of_week', 1)['start_time'])->toBe('09:00:00');
});

test('an empty working-hours array clears a staff member\'s whole schedule', function () {
    $tenant = ownerAvailabilityTenant();
    $staff = TenantContext::run($tenant->id, fn () => Staff::factory()->create(['tenant_id' => $tenant->id]));
    TenantContext::run($tenant->id, fn () => StaffWorkingHour::factory()->create(['tenant_id' => $tenant->id, 'staff_id' => $staff->id]));

    $xsrf = loginAsAvailabilityOwner($tenant);

    $response = putJson("/api/owner/staff/{$staff->id}/working-hours", ['working_hours' => []], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    expect($response->json('working_hours'))->toBe([]);
});

test('working hours with end before start are rejected', function () {
    $tenant = ownerAvailabilityTenant();
    $staff = TenantContext::run($tenant->id, fn () => Staff::factory()->create(['tenant_id' => $tenant->id]));

    $xsrf = loginAsAvailabilityOwner($tenant);

    $response = putJson("/api/owner/staff/{$staff->id}/working-hours", [
        'working_hours' => [['day_of_week' => 1, 'start_time' => '17:00', 'end_time' => '09:00']],
    ], ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $response->assertStatus(422);
});

test('an owner cannot set working hours on another tenant\'s staff member, gets a plain 404', function () {
    $tenant = ownerAvailabilityTenant();
    $otherTenant = Tenant::factory()->create();
    $foreignStaff = TenantContext::run($otherTenant->id, fn () => Staff::factory()->create(['tenant_id' => $otherTenant->id]));

    $xsrf = loginAsAvailabilityOwner($tenant);

    $response = putJson("/api/owner/staff/{$foreignStaff->id}/working-hours", [
        'working_hours' => [['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00']],
    ], ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $response->assertStatus(404);
});

test('an owner creates a one-off availability exception (a holiday closure)', function () {
    $tenant = ownerAvailabilityTenant();
    $staff = TenantContext::run($tenant->id, fn () => Staff::factory()->create(['tenant_id' => $tenant->id]));

    $xsrf = loginAsAvailabilityOwner($tenant);

    $response = postJson("/api/owner/staff/{$staff->id}/availability-exceptions", [
        'date' => now()->addWeek()->toDateString(),
        'is_available' => false,
        'reason' => 'Studio closed',
    ], ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $response->assertCreated();
    $response->assertJson(['is_available' => false, 'reason' => 'Studio closed']);
});

test('an availability exception dated in the past is rejected', function () {
    $tenant = ownerAvailabilityTenant();
    $staff = TenantContext::run($tenant->id, fn () => Staff::factory()->create(['tenant_id' => $tenant->id]));

    $xsrf = loginAsAvailabilityOwner($tenant);

    $response = postJson("/api/owner/staff/{$staff->id}/availability-exceptions", [
        'date' => now()->subWeek()->toDateString(),
        'is_available' => false,
    ], ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $response->assertStatus(422);
});

test('an owner deletes their own availability exception', function () {
    $tenant = ownerAvailabilityTenant();
    $staff = TenantContext::run($tenant->id, fn () => Staff::factory()->create(['tenant_id' => $tenant->id]));
    $exception = TenantContext::run($tenant->id, fn () => AvailabilityException::factory()->create([
        'tenant_id' => $tenant->id, 'staff_id' => $staff->id,
    ]));

    $xsrf = loginAsAvailabilityOwner($tenant);

    $response = deleteJson("/api/owner/staff/{$staff->id}/availability-exceptions/{$exception->id}", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    TenantContext::run($tenant->id, function () use ($exception) {
        expect(AvailabilityException::find($exception->id))->toBeNull();
    });
});

test('an owner cannot delete another tenant\'s availability exception, gets a plain 404', function () {
    $tenant = ownerAvailabilityTenant();
    $staff = TenantContext::run($tenant->id, fn () => Staff::factory()->create(['tenant_id' => $tenant->id]));

    $otherTenant = Tenant::factory()->create();
    $otherStaff = TenantContext::run($otherTenant->id, fn () => Staff::factory()->create(['tenant_id' => $otherTenant->id]));
    $foreignException = TenantContext::run($otherTenant->id, fn () => AvailabilityException::factory()->create([
        'tenant_id' => $otherTenant->id, 'staff_id' => $otherStaff->id,
    ]));

    $xsrf = loginAsAvailabilityOwner($tenant);

    $response = deleteJson("/api/owner/staff/{$staff->id}/availability-exceptions/{$foreignException->id}", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(404);
});

test('an unauthenticated request to read working hours is rejected', function () {
    $tenant = Tenant::factory()->create();
    $staff = TenantContext::run($tenant->id, fn () => Staff::factory()->create(['tenant_id' => $tenant->id]));

    getJson("/api/owner/staff/{$staff->id}/working-hours")->assertStatus(401);
});
