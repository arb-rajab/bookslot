<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\BookingFixture;

use function Pest\Laravel\getJson;

/**
 * GET /api/admin/tenants/{tenant}/appointments (05-api-contracts.md,
 * D-0009's platform-admin impersonation path, wired for real this session
 * via resolve.tenant.impersonate). Proves the impersonated tenant is
 * exactly and only the one named by the route — a real cross-tenant
 * scoping test, not just a role-gating one.
 */
test('a platform_admin impersonating one tenant sees only that tenant\'s appointments', function () {
    disableConsoleCsrfBypass();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $appointmentA = BookingFixture::appointmentFor($tenantA);
    $appointmentB = BookingFixture::appointmentFor($tenantB);

    User::factory()->platformAdmin()->create([
        'email' => 'admin@example.test',
        'password_hash' => Hash::make('correct-password'),
    ]);

    [$xsrf, $login] = loginAndCaptureXsrf('/api/admin/login', [
        'email' => 'admin@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    $responseA = getJson("/api/admin/tenants/{$tenantA->id}/appointments", ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);
    $responseA->assertOk();
    $idsA = collect($responseA->json('appointments'))->pluck('id');
    expect($idsA)->toContain($appointmentA->id);
    expect($idsA)->not->toContain($appointmentB->id);

    $responseB = getJson("/api/admin/tenants/{$tenantB->id}/appointments", ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);
    $responseB->assertOk();
    $idsB = collect($responseB->json('appointments'))->pluck('id');
    expect($idsB)->toContain($appointmentB->id);
    expect($idsB)->not->toContain($appointmentA->id);
});

test('a nonexistent tenant id in the admin impersonation route returns 404', function () {
    disableConsoleCsrfBypass();

    User::factory()->platformAdmin()->create([
        'email' => 'admin@example.test',
        'password_hash' => Hash::make('correct-password'),
    ]);

    [$xsrf, $login] = loginAndCaptureXsrf('/api/admin/login', [
        'email' => 'admin@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    getJson('/api/admin/tenants/'.Str::uuid()->toString().'/appointments', [
        'Origin' => 'http://localhost',
        'X-XSRF-TOKEN' => $xsrf,
    ])->assertStatus(404);
});
