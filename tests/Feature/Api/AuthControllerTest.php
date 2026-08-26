<?php

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * D-0029 (docs/project-memory/09-decision-log.md): Sanctum SPA (stateful/
 * cookie) auth, one shared `web` guard for owner/staff/platform_admin,
 * role-based authorization at the app layer. disableConsoleCsrfBypass()
 * and loginAndCaptureXsrf() are shared helpers — see
 * tests/Support/AuthTestHelpers.php for why the console bypass override
 * exists at all (Laravel's own CSRF middleware otherwise skips
 * verification unconditionally during any Pest/PHPUnit run).
 */
function createOwner(Tenant $tenant, string $email, string $password): User
{
    return TenantContext::run($tenant->id, fn () => User::factory()->owner()->create([
        'tenant_id' => $tenant->id,
        'email' => $email,
        'password_hash' => Hash::make($password),
    ]));
}

test('a login request from the frontend origin without a CSRF token is rejected with 419', function () {
    disableConsoleCsrfBypass();

    $tenant = Tenant::factory()->create();
    createOwner($tenant, 'owner@example.test', 'correct-password');

    $response = postJson("/api/tenants/{$tenant->slug}/login", [
        'email' => 'owner@example.test',
        'password' => 'correct-password',
    ], ['Origin' => 'http://localhost']);

    $response->assertStatus(419);
});

test('a login request with a valid CSRF token succeeds and the session authenticates a subsequent owner-only request', function () {
    disableConsoleCsrfBypass();

    $tenant = Tenant::factory()->create();
    createOwner($tenant, 'owner@example.test', 'correct-password');

    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'owner@example.test',
        'password' => 'correct-password',
    ]);

    $login->assertOk();
    $login->assertJsonPath('user.role', 'owner');

    $ownerRequest = postJson('/api/owner/services', [
        'name' => 'Small flash tattoo',
        'duration_minutes' => 60,
        'price_amount' => 15000,
        'currency' => 'usd',
        'deposit_type' => 'fixed',
        'deposit_fixed_amount' => 5000,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 15,
    ], ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $ownerRequest->assertCreated();
    $ownerRequest->assertJsonPath('tenant_id', $tenant->id);
});

test('an unauthenticated request to an owner-only route is rejected with 401', function () {
    postJson('/api/owner/services', [], ['Origin' => 'http://localhost'])
        ->assertStatus(401);
});

test('a staff user cannot access an owner-only route', function () {
    disableConsoleCsrfBypass();

    $tenant = Tenant::factory()->create();
    TenantContext::run($tenant->id, fn () => User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'staff@example.test',
        'password_hash' => Hash::make('correct-password'),
    ]));

    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'staff@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    postJson('/api/owner/services', [], ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf])
        ->assertStatus(403);
});

test('an owner cannot log in through the platform-admin login route', function () {
    disableConsoleCsrfBypass();

    $tenant = Tenant::factory()->create();
    createOwner($tenant, 'owner@example.test', 'correct-password');

    [, $login] = loginAndCaptureXsrf('/api/admin/login', [
        'email' => 'owner@example.test',
        'password' => 'correct-password',
    ]);

    $login->assertStatus(401);
});

test('a platform_admin can log in with no tenant context, access the admin impersonation route, and log out', function () {
    disableConsoleCsrfBypass();

    $tenant = Tenant::factory()->create();
    User::factory()->platformAdmin()->create([
        'email' => 'admin@example.test',
        'password_hash' => Hash::make('correct-password'),
    ]);

    [$xsrf, $login] = loginAndCaptureXsrf('/api/admin/login', [
        'email' => 'admin@example.test',
        'password' => 'correct-password',
    ]);

    $login->assertOk();
    $login->assertJsonPath('user.role', 'platform_admin');

    getJson("/api/admin/tenants/{$tenant->id}/appointments", [
        'Origin' => 'http://localhost',
        'X-XSRF-TOKEN' => $xsrf,
    ])->assertOk()->assertJsonPath('appointments', []);

    postJson('/api/logout', [], ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf])
        ->assertOk();

    getJson("/api/admin/tenants/{$tenant->id}/appointments", ['Origin' => 'http://localhost'])
        ->assertStatus(401);
});
