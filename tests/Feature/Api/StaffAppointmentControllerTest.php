<?php

use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Tests\Support\BookingFixture;

use function Pest\Laravel\getJson;

/**
 * GET /api/staff/appointments (05-api-contracts.md, FR-16/D-0013): own
 * bookings only at MVP. This is the real behavioral test D-0029's
 * resolve.tenant.from-user middleware exists to make possible — a staff
 * member's session resolves tenant context from their own tenant_id, and
 * the controller further narrows to their own staff_id, so another
 * staff member's appointments in the SAME tenant never leak through
 * either.
 */
test('a staff member sees only their own appointments, never another staff member\'s', function () {
    disableConsoleCsrfBypass();

    $tenant = Tenant::factory()->create();

    $myStaff = TenantContext::run($tenant->id, function () use ($tenant) {
        $staffUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'staff@example.test',
            'password_hash' => Hash::make('correct-password'),
        ]);

        return Staff::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $staffUser->id]);
    });
    $otherStaff = TenantContext::run($tenant->id, fn () => Staff::factory()->create(['tenant_id' => $tenant->id, 'user_id' => null]));

    $mine = BookingFixture::appointmentFor($tenant, ['staff_id' => $myStaff->id]);
    $theirs = BookingFixture::appointmentFor($tenant, ['staff_id' => $otherStaff->id]);

    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'staff@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    $response = getJson('/api/staff/appointments', ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $response->assertOk();
    $ids = collect($response->json('appointments'))->pluck('id');
    expect($ids)->toContain($mine->id);
    expect($ids)->not->toContain($theirs->id);
});
