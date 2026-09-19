<?php

use App\Models\Tenant;
use App\Models\User;
use App\Payments\PaymentIntentGateway;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakePaymentIntentGateway;

use function Pest\Laravel\postJson;

/**
 * D-0063 (09-decision-log.md), Session 37: neither login endpoint nor the
 * public booking-creation endpoint had ever had any throttling — see
 * config/rate_limiting.php for the threshold reasoning. `CACHE_STORE=array`
 * in .env.testing backs Laravel's cache-based RateLimiter here, and each
 * Pest test boots a fresh Application (a fresh, empty array cache) — so
 * these tests never see cross-test bleed, and asserting the Nth request in
 * a tight loop within one test is a reliable way to exercise the real
 * limiter, not a flaky approximation of one.
 */
function rateLimitOwner(Tenant $tenant, string $email, string $password): User
{
    return TenantContext::run($tenant->id, fn () => User::factory()->owner()->create([
        'tenant_id' => $tenant->id,
        'email' => $email,
        'password_hash' => Hash::make($password),
    ]));
}

test('the tenant login route 429s after the per-credential limit is exceeded, even with correct credentials', function () {
    $tenant = Tenant::factory()->create();
    rateLimitOwner($tenant, 'owner@example.test', 'correct-password');

    $limit = config('rate_limiting.login.per_credential_per_minute');

    for ($i = 0; $i < $limit; $i++) {
        postJson("/api/tenants/{$tenant->slug}/login", [
            'email' => 'owner@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    $response = postJson("/api/tenants/{$tenant->slug}/login", [
        'email' => 'owner@example.test',
        'password' => 'correct-password',
    ]);

    $response->assertStatus(429);
    $response->assertJson(['error' => 'TOO_MANY_REQUESTS']);
    expect($response->headers->has('Retry-After'))->toBeTrue();
});

test('the platform-admin login route shares the same login limiter', function () {
    $limit = config('rate_limiting.login.per_credential_per_minute');

    for ($i = 0; $i < $limit; $i++) {
        postJson('/api/admin/login', [
            'email' => 'admin@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    postJson('/api/admin/login', [
        'email' => 'admin@example.test',
        'password' => 'wrong-password',
    ])->assertStatus(429)->assertJson(['error' => 'TOO_MANY_REQUESTS']);
});

test('a different credential from the same IP is not blocked by another credential exhausting its own limit', function () {
    $tenant = Tenant::factory()->create();
    rateLimitOwner($tenant, 'owner-a@example.test', 'correct-password');
    rateLimitOwner($tenant, 'owner-b@example.test', 'correct-password');

    $limit = config('rate_limiting.login.per_credential_per_minute');

    for ($i = 0; $i < $limit; $i++) {
        postJson("/api/tenants/{$tenant->slug}/login", [
            'email' => 'owner-a@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    // owner-a is now exhausted; owner-b, a distinct credential from the
    // same IP, must still be able to log in — proves the key is genuinely
    // per-credential, not accidentally collapsing to per-IP alone. Needs
    // an Origin header so EnsureFrontendRequestsAreStateful attaches a real
    // session store — AuthController::login() calls $request->session()
    // only on the success path, which every prior 401 in this file never
    // reaches.
    postJson("/api/tenants/{$tenant->slug}/login", [
        'email' => 'owner-b@example.test',
        'password' => 'correct-password',
    ], ['Origin' => 'http://localhost'])->assertOk();
});

test('spraying many distinct credentials from one IP eventually 429s under the per-IP limit', function () {
    $tenant = Tenant::factory()->create();
    $ipLimit = config('rate_limiting.login.per_ip_per_minute');

    // A distinct email per attempt (one attempt each, well under the
    // per-credential limit for any single email) means only the per-IP
    // limit can be the thing that eventually trips.
    for ($i = 0; $i < $ipLimit; $i++) {
        postJson("/api/tenants/{$tenant->slug}/login", [
            'email' => "no-such-user-{$i}@example.test",
            'password' => 'whatever',
        ])->assertStatus(401);
    }

    postJson("/api/tenants/{$tenant->slug}/login", [
        'email' => 'no-such-user-final@example.test',
        'password' => 'whatever',
    ])->assertStatus(429)->assertJson(['error' => 'TOO_MANY_REQUESTS']);
});

test('the public booking endpoint 429s after the per-IP-and-tenant limit is exceeded', function () {
    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway);

    $tenant = Tenant::factory()->create();
    $limit = config('rate_limiting.booking.per_minute');

    // An invalid body (missing every required field) still counts against
    // the limiter — throttle:booking runs before validation, exactly as it
    // must to protect against a flood of even-malformed requests.
    for ($i = 0; $i < $limit; $i++) {
        postJson("/api/tenants/{$tenant->slug}/bookings", [])->assertStatus(422);
    }

    $response = postJson("/api/tenants/{$tenant->slug}/bookings", []);

    $response->assertStatus(429);
    $response->assertJson(['error' => 'TOO_MANY_REQUESTS']);
    expect($response->headers->has('Retry-After'))->toBeTrue();
});

test('the booking rate limit is scoped per tenant, not shared across tenants from the same IP', function () {
    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway);

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $limit = config('rate_limiting.booking.per_minute');

    for ($i = 0; $i < $limit; $i++) {
        postJson("/api/tenants/{$tenantA->slug}/bookings", [])->assertStatus(422);
    }
    postJson("/api/tenants/{$tenantA->slug}/bookings", [])->assertStatus(429);

    // tenant B, from the same test process/IP, is a genuinely independent
    // key and must not be affected by tenant A's exhausted limit.
    postJson("/api/tenants/{$tenantB->slug}/bookings", [])->assertStatus(422);
});
