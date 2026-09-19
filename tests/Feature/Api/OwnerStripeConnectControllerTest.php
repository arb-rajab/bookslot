<?php

use App\Models\Tenant;
use App\Models\User;
use App\Payments\ConnectOnboardingGateway;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeConnectOnboardingGateway;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * POST /api/owner/stripe/connect/onboarding-link, GET /api/owner/stripe
 * /connect/status (05-api-contracts.md, D-0058
 * docs/project-memory/09-decision-log.md). Both routes call Stripe — bound
 * to the fake tier file-wide, same reasoning as
 * OwnerAppointmentControllerTest's own refund()/chargeBalance() coverage:
 * never depend on AppServiceProvider's real-vs-fake fallback logic
 * resolving against .env.testing's look-real-enough dummy key.
 */
beforeEach(function () {
    app()->bind(ConnectOnboardingGateway::class, fn () => new FakeConnectOnboardingGateway);
});

function ownerAndTenantForConnect(): Tenant
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

function loginAsOwnerForConnect(Tenant $tenant): string
{
    disableConsoleCsrfBypass();

    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'owner@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    return $xsrf;
}

test('a tenant with no Connect account yet gets one created and a fresh onboarding link', function () {
    $tenant = ownerAndTenantForConnect();
    $gateway = new FakeConnectOnboardingGateway(accountId: 'acct_fake_new_123');
    app()->bind(ConnectOnboardingGateway::class, fn () => $gateway);

    $xsrf = loginAsOwnerForConnect($tenant);

    $response = postJson('/api/owner/stripe/connect/onboarding-link', [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    expect($response->json('url'))->toBe('https://connect.stripe.com/setup/fake/acct_fake_test');
    expect($response->json('expires_at'))->toBe(1893456000);

    expect($gateway->createAccountCallCount())->toBe(1);
    expect($gateway->createAccountLinkCallCount())->toBe(1);

    $reloaded = Tenant::query()->find($tenant->id);
    expect($reloaded->stripe_connect_account_id)->toBe('acct_fake_new_123');
    expect($reloaded->stripe_onboarding_status)->toBe('pending');
});

test('an already-onboarded tenant reuses its existing account and never calls createAccount again', function () {
    $tenant = ownerAndTenantForConnect();
    $tenant->stripe_connect_account_id = 'acct_already_onboarded';
    $tenant->stripe_onboarding_status = 'complete';
    $tenant->save();

    $gateway = new FakeConnectOnboardingGateway;
    app()->bind(ConnectOnboardingGateway::class, fn () => $gateway);

    $xsrf = loginAsOwnerForConnect($tenant);

    $response = postJson('/api/owner/stripe/connect/onboarding-link', [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    expect($gateway->createAccountCallCount())->toBe(0);
    expect($gateway->createAccountLinkCallCount())->toBe(1);

    // Re-issuing a link for an already-complete account must not silently
    // regress its recorded status — only a live status check or a webhook
    // may ever change it.
    $reloaded = Tenant::query()->find($tenant->id);
    expect($reloaded->stripe_connect_account_id)->toBe('acct_already_onboarded');
    expect($reloaded->stripe_onboarding_status)->toBe('complete');
});

test('a Stripe account-creation failure maps to 502, leaving the tenant untouched', function () {
    $tenant = ownerAndTenantForConnect();
    app()->bind(ConnectOnboardingGateway::class, fn () => new FakeConnectOnboardingGateway(shouldThrowOnCreateAccount: true));

    $xsrf = loginAsOwnerForConnect($tenant);

    $response = postJson('/api/owner/stripe/connect/onboarding-link', [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(502);
    $response->assertJson(['error' => 'PAYMENT_PROVIDER_UNAVAILABLE']);

    $reloaded = Tenant::query()->find($tenant->id);
    expect($reloaded->stripe_connect_account_id)->toBeNull();
    expect($reloaded->stripe_onboarding_status)->toBe('not_started');
});

test('a Stripe account-link-creation failure maps to 502, even once the account itself already exists', function () {
    $tenant = ownerAndTenantForConnect();
    $tenant->stripe_connect_account_id = 'acct_existing';
    $tenant->save();

    app()->bind(ConnectOnboardingGateway::class, fn () => new FakeConnectOnboardingGateway(shouldThrowOnCreateAccountLink: true));

    $xsrf = loginAsOwnerForConnect($tenant);

    $response = postJson('/api/owner/stripe/connect/onboarding-link', [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(502);
    $response->assertJson(['error' => 'PAYMENT_PROVIDER_UNAVAILABLE']);
});

test('onboarding-link only ever touches the calling owner\'s own tenant, never another tenant\'s row', function () {
    $tenant = ownerAndTenantForConnect();
    $otherTenant = Tenant::factory()->create(['stripe_connect_account_id' => null]);

    $gateway = new FakeConnectOnboardingGateway(accountId: 'acct_isolated');
    app()->bind(ConnectOnboardingGateway::class, fn () => $gateway);

    $xsrf = loginAsOwnerForConnect($tenant);

    postJson('/api/owner/stripe/connect/onboarding-link', [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ])->assertOk();

    expect(Tenant::query()->find($tenant->id)->stripe_connect_account_id)->toBe('acct_isolated');
    expect(Tenant::query()->find($otherTenant->id)->stripe_connect_account_id)->toBeNull();
});

test('the status endpoint reports not_started without ever calling Stripe when no account exists yet', function () {
    $tenant = ownerAndTenantForConnect();
    $gateway = new FakeConnectOnboardingGateway;
    app()->bind(ConnectOnboardingGateway::class, fn () => $gateway);

    $xsrf = loginAsOwnerForConnect($tenant);

    $response = getJson('/api/owner/stripe/connect/status', [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'not_started', 'charges_enabled' => false, 'details_submitted' => false]);
    expect($gateway->retrieveAccountStatusCallCount())->toBe(0);
});

test('the status endpoint live-checks Stripe and self-heals a stale complete status', function () {
    $tenant = ownerAndTenantForConnect();
    $tenant->stripe_connect_account_id = 'acct_live_check';
    $tenant->stripe_onboarding_status = 'pending';
    $tenant->save();

    app()->bind(ConnectOnboardingGateway::class, fn () => new FakeConnectOnboardingGateway(
        chargesEnabled: true,
        detailsSubmitted: true,
    ));

    $xsrf = loginAsOwnerForConnect($tenant);

    $response = getJson('/api/owner/stripe/connect/status', [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'complete', 'charges_enabled' => true, 'details_submitted' => true]);

    expect(Tenant::query()->find($tenant->id)->stripe_onboarding_status)->toBe('complete');
});

test('the status endpoint reports restricted when Stripe reports a disabled_reason, regardless of charges_enabled', function () {
    $tenant = ownerAndTenantForConnect();
    $tenant->stripe_connect_account_id = 'acct_restricted';
    $tenant->stripe_onboarding_status = 'complete';
    $tenant->save();

    app()->bind(ConnectOnboardingGateway::class, fn () => new FakeConnectOnboardingGateway(
        chargesEnabled: true,
        detailsSubmitted: true,
        disabledReason: 'requirements.past_due',
    ));

    $xsrf = loginAsOwnerForConnect($tenant);

    $response = getJson('/api/owner/stripe/connect/status', [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'restricted']);
    expect(Tenant::query()->find($tenant->id)->stripe_onboarding_status)->toBe('restricted');
});

test('a Stripe outage during the status check maps to 502 without corrupting the stored status', function () {
    $tenant = ownerAndTenantForConnect();
    $tenant->stripe_connect_account_id = 'acct_outage';
    $tenant->stripe_onboarding_status = 'pending';
    $tenant->save();

    app()->bind(ConnectOnboardingGateway::class, fn () => new FakeConnectOnboardingGateway(shouldThrowOnRetrieveAccountStatus: true));

    $xsrf = loginAsOwnerForConnect($tenant);

    $response = getJson('/api/owner/stripe/connect/status', [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(502);
    $response->assertJson(['error' => 'PAYMENT_PROVIDER_UNAVAILABLE']);
    expect(Tenant::query()->find($tenant->id)->stripe_onboarding_status)->toBe('pending');
});

test('the status endpoint only ever reflects the calling owner\'s own tenant', function () {
    $tenant = ownerAndTenantForConnect();
    $tenant->stripe_connect_account_id = 'acct_own';
    $tenant->stripe_onboarding_status = 'pending';
    $tenant->save();

    $otherTenant = Tenant::factory()->create([
        'stripe_connect_account_id' => 'acct_other',
        'stripe_onboarding_status' => 'restricted',
    ]);

    app()->bind(ConnectOnboardingGateway::class, fn () => new FakeConnectOnboardingGateway(
        chargesEnabled: true,
        detailsSubmitted: true,
    ));

    $xsrf = loginAsOwnerForConnect($tenant);

    $response = getJson('/api/owner/stripe/connect/status', [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'complete']);

    // The other tenant's own (differently-classified) status is untouched
    // by this request.
    expect(Tenant::query()->find($otherTenant->id)->stripe_onboarding_status)->toBe('restricted');
});

test('D-0065: the status endpoint reports deauthorized (not not_started) for a tenant whose account was disconnected, distinguishing it from a tenant that never onboarded', function () {
    $tenant = ownerAndTenantForConnect();
    $tenant->stripe_connect_account_id = null;
    $tenant->stripe_onboarding_status = 'deauthorized';
    $tenant->save();

    $gateway = new FakeConnectOnboardingGateway;
    app()->bind(ConnectOnboardingGateway::class, fn () => $gateway);

    $xsrf = loginAsOwnerForConnect($tenant);

    $response = getJson('/api/owner/stripe/connect/status', [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'deauthorized', 'charges_enabled' => false, 'details_submitted' => false]);
    expect($gateway->retrieveAccountStatusCallCount())->toBe(0);
});

test('D-0065: a deauthorized tenant reconnects a brand new Connect account through the same onboarding-link endpoint — full deauthorization-to-reconnection regression', function () {
    $tenant = ownerAndTenantForConnect();

    // Simulate the state ProcessStripeWebhookJob::handleAccountDeauthorized()
    // leaves behind after a real account.application.deauthorized webhook —
    // that mutation itself is covered directly by
    // StripeWebhookControllerTest's own D-0065 test; this test's job is to
    // prove the *onboarding-link/status controller* correctly turns that
    // state into a real, working reconnection with no manual intervention.
    $tenant->stripe_connect_account_id = null;
    $tenant->stripe_onboarding_status = 'deauthorized';
    $tenant->save();

    $gateway = new FakeConnectOnboardingGateway(accountId: 'acct_reconnected_fresh');
    app()->bind(ConnectOnboardingGateway::class, fn () => $gateway);

    $xsrf = loginAsOwnerForConnect($tenant);

    $statusBefore = getJson('/api/owner/stripe/connect/status', [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);
    $statusBefore->assertOk();
    $statusBefore->assertJson(['status' => 'deauthorized']);

    $linkResponse = postJson('/api/owner/stripe/connect/onboarding-link', [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $linkResponse->assertOk();
    $linkResponse->assertJsonStructure(['url', 'expires_at']);
    expect($gateway->createAccountCallCount())->toBe(1);

    $reconnected = Tenant::query()->find($tenant->id);
    expect($reconnected->stripe_connect_account_id)->toBe('acct_reconnected_fresh');
    expect($reconnected->stripe_onboarding_status)->toBe('pending');

    $statusAfter = getJson('/api/owner/stripe/connect/status', [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);
    $statusAfter->assertOk();
    $statusAfter->assertJson(['status' => 'pending']);
});
