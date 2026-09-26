<?php

use App\Mandates\MandateRenderer;
use App\Models\Appointment;
use App\Models\PaymentMandate;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Payments\PaymentIntentGateway;
use App\Tenancy\SignedTenantToken;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\FakePaymentIntentGateway;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway);
});

/**
 * POST /api/tenants/{slug}/bookings (05-api-contracts.md endpoint 2,
 * D-0030). Uses FakePaymentIntentGateway (07-testing-strategy.md's
 * "faked Stripe client by default" tier) — see BookingConcurrencyTest.php
 * for the true multi-connection race case D-0007 requires, which this
 * in-process suite cannot express.
 */
function bookingTenant(): array
{
    // D-0067: matches D-0066's own fix to ownerAndTenant() — Tenant::
    // factory()'s own default leaves stripe_connect_account_id null, which
    // is exactly the misrouting condition this file's own D-0067 tests
    // exist to catch. Every test that isn't specifically about that gap
    // gets a properly-connected tenant by default; the gap-specific tests
    // below construct their own disconnected tenant directly, same pattern
    // OwnerAppointmentControllerTest.php already uses.
    $tenant = Tenant::factory()->create([
        'stripe_connect_account_id' => 'acct_fake_connected',
        'stripe_onboarding_status' => 'complete',
    ]);

    return TenantContext::run($tenant->id, function () use ($tenant) {
        $service = Service::factory()->create([
            'tenant_id' => $tenant->id,
            'duration_minutes' => 60,
            'price_amount' => 20000,
            'currency' => 'usd',
            'deposit_type' => 'fixed',
            'deposit_fixed_amount' => 5000,
            'deposit_percentage_bps' => null,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 15,
        ]);
        $staff = Staff::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $service, $staff];
    });
}

function bookingRequestBody(Service $service, Staff $staff, string $email = 'customer@example.test'): array
{
    return [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => now()->addDays(3)->setTime(17, 0)->toIso8601String(),
        'customer' => ['name' => 'Jamie Customer', 'email' => $email, 'phone' => '555-0100'],
        'mandate_accepted' => true,
        'mandate_template_version' => 'v1',
    ];
}

test('booking creation succeeds end to end: pending_payment appointment, deposit PaymentIntent, server-rendered mandate', function () {
    [$tenant, $service, $staff] = bookingTenant();

    $response = postJson("/api/tenants/{$tenant->slug}/bookings", bookingRequestBody($service, $staff));

    $response->assertCreated();
    $response->assertJsonPath('status', 'pending_payment');
    $response->assertJsonPath('deposit.amount', 5000);
    $response->assertJsonPath('deposit.currency', 'usd');
    expect($response->json('deposit.client_secret'))->toBeString();
    expect($response->json('manage_token'))->toBeString();
    expect($response->json('payment_confirmation_token'))->toBeString();

    $appointmentId = $response->json('appointment_id');

    TenantContext::run($tenant->id, function () use ($appointmentId, $tenant, $service) {
        $appointment = Appointment::findOrFail($appointmentId);
        expect($appointment->status)->toBe('pending_payment');
        expect($appointment->buffer_before_minutes)->toBe(0);
        expect($appointment->buffer_after_minutes)->toBe(15);

        $mandate = PaymentMandate::query()->where('appointment_id', $appointmentId)->firstOrFail();
        $rendered = (new MandateRenderer)->render($tenant, $service);
        expect($mandate->mandate_text)->toBe($rendered['text']);
        expect($mandate->mandate_template_version)->toBe('v1');
        expect($mandate->balance_amount_disclosed)->toBe(15000);
        expect($mandate->stripe_payment_method_id)->toBeNull();
        expect($mandate->stripe_payment_intent_id)->not->toBeNull();
    });

    // The manage/confirm-payment tokens returned really work against the
    // rest of the token-based public surface D-0021/D-0026 built.
    $payload = SignedTenantToken::verify($response->json('payment_confirmation_token'), 'confirm_payment');
    expect($payload['appointment_id'])->toBe($appointmentId);
    expect($payload['tenant_id'])->toBe($tenant->id);
});

test('D-0064: the manage_token issued at booking creation expires N days after the appointment ends, not never', function () {
    [$tenant, $service, $staff] = bookingTenant();

    $response = postJson("/api/tenants/{$tenant->slug}/bookings", bookingRequestBody($service, $staff));
    $response->assertCreated();

    $manageToken = $response->json('manage_token');
    $appointmentId = $response->json('appointment_id');

    $graceDays = config('booking.manage_booking_token_expiry_grace_days');
    $endsAt = TenantContext::run($tenant->id, fn () => Appointment::findOrFail($appointmentId)->ends_at);
    $expectedExpiresAt = $endsAt->copy()->addDays($graceDays);

    // Still valid the instant before its computed expiry. Carbon::setTestNow()
    // rather than $this->travelTo() — Larastan's Pest stubs type $this
    // inside a test() closure as Pest\PendingCalls\TestCall, which has no
    // travelTo() method, even though it resolves fine at runtime (Pest
    // binds the closure to the real TestCase). Both reset automatically
    // after each test via InteractsWithTestCaseLifecycle's tearDown.
    Carbon::setTestNow($expectedExpiresAt->copy()->subSecond());
    getJson("/api/bookings/manage/{$manageToken}")->assertOk();

    // Expired the instant it passes — same generic response as every other
    // invalid-token case (D-0021's fail-closed shape), not a distinct code.
    Carbon::setTestNow($expectedExpiresAt->copy()->addSecond());
    getJson("/api/bookings/manage/{$manageToken}")
        ->assertStatus(404)
        ->assertJson(['error' => 'INVALID_OR_EXPIRED_TOKEN']);
});

test('booking creation with an unknown service_id returns 404', function () {
    [$tenant, $service, $staff] = bookingTenant();

    $body = bookingRequestBody($service, $staff);
    $body['service_id'] = (string) Str::uuid();

    postJson("/api/tenants/{$tenant->slug}/bookings", $body)->assertStatus(404)->assertJson(['error' => 'NOT_FOUND']);
});

test('booking creation without mandate acceptance returns 422 VALIDATION_FAILED', function () {
    [$tenant, $service, $staff] = bookingTenant();

    $body = bookingRequestBody($service, $staff);
    $body['mandate_accepted'] = false;

    $response = postJson("/api/tenants/{$tenant->slug}/bookings", $body);
    $response->assertStatus(422);
    $response->assertJsonPath('error', 'VALIDATION_FAILED');
    expect($response->json('fields'))->toHaveKey('mandate_accepted');
});

test('D-0027: a failing Stripe call does not roll back the already-committed pending_payment appointment', function () {
    [$tenant, $service, $staff] = bookingTenant();

    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway(shouldFail: true));

    $response = postJson("/api/tenants/{$tenant->slug}/bookings", bookingRequestBody($service, $staff));

    $response->assertStatus(502);
    $response->assertJson(['error' => 'PAYMENT_PROVIDER_UNAVAILABLE']);

    // The proof: TX1 (appointment insert) already committed to the real
    // database before the Stripe call ever ran — it is not undone by the
    // later failure. The row is still there, just moved to `cancelled` by
    // BookingController's own deliberate cleanup step (releasing the
    // hold), never a database-level rollback of TX1 itself.
    TenantContext::run($tenant->id, function () use ($tenant, $staff) {
        $appointment = Appointment::query()->where('tenant_id', $tenant->id)->where('staff_id', $staff->id)->first();
        expect($appointment)->not->toBeNull();
        expect($appointment->status)->toBe('cancelled');
        expect($appointment->cancelled_reason)->toBe('payment_provider_error');

        // No payment or mandate row exists — TX2 never ran, since it
        // depends on the Stripe call that failed.
        expect(PaymentMandate::query()->where('appointment_id', $appointment->id)->exists())->toBeFalse();
    });
});

test('D-0027: a slow Stripe call still commits TX1 before the external call resolves', function () {
    [$tenant, $service, $staff] = bookingTenant();

    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway(delaySeconds: 1));

    $start = microtime(true);
    $response = postJson("/api/tenants/{$tenant->slug}/bookings", bookingRequestBody($service, $staff));
    $elapsed = microtime(true) - $start;

    $response->assertCreated();
    expect($elapsed)->toBeGreaterThanOrEqual(1.0);
});

// D-0067: the same misrouting gap D-0066 closed for refund()/chargeBalance()
// — a tenant with no connected Stripe account (never onboarded, or
// deauthorized per D-0065 and not yet reconnected) must not have a
// customer's deposit silently routed to the PLATFORM's own Stripe account.
// Reproduced first, per D-0066's own standard: before this file's
// bookingTenant() helper and BookingController::store() were changed, this
// exact test body (a null-connect tenant, asserting a 201) passed against
// the unmodified controller — direct, first-hand confirmation the gap was
// real, not just a theoretical reading of the code, the same way D-0066's
// own entry found ownerAndTenant() had been unknowingly doing the whole
// time. FakePaymentIntentGateway::create() (like the real
// StripePaymentIntentGateway) has no connected-account validation of its
// own — it happily "succeeds" regardless, which is exactly how the real
// gateway's null-branch behaves too (omits transfer_data/
// application_fee_amount rather than failing).
test('D-0067: booking creation for a tenant with no connected Stripe account is rejected, never reaching Stripe — not silently routed to the platform account', function () {
    $tenant = Tenant::factory()->create([
        'stripe_connect_account_id' => null,
        'stripe_onboarding_status' => 'not_started',
    ]);
    [$service, $staff] = TenantContext::run($tenant->id, function () use ($tenant) {
        $service = Service::factory()->create([
            'tenant_id' => $tenant->id,
            'duration_minutes' => 60,
            'price_amount' => 20000,
            'currency' => 'usd',
            'deposit_type' => 'fixed',
            'deposit_fixed_amount' => 5000,
            'deposit_percentage_bps' => null,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 15,
        ]);
        $staff = Staff::factory()->create(['tenant_id' => $tenant->id]);

        return [$service, $staff];
    });

    $gateway = new FakePaymentIntentGateway;
    app()->bind(PaymentIntentGateway::class, fn () => $gateway);

    $response = postJson("/api/tenants/{$tenant->slug}/bookings", bookingRequestBody($service, $staff));

    $response->assertStatus(409);
    $response->assertJson(['error' => 'BOOKING_UNAVAILABLE']);
    expect($gateway->callCount())->toBe(0);

    // The hold taken by TX1 is released immediately, same cleanup path a
    // Stripe-call failure already uses (D-0027) — not left for the expiry
    // job, and no payment/mandate row exists since the request never got
    // that far.
    TenantContext::run($tenant->id, function () use ($tenant, $staff) {
        $appointment = Appointment::query()->where('tenant_id', $tenant->id)->where('staff_id', $staff->id)->first();
        expect($appointment)->not->toBeNull();
        expect($appointment->status)->toBe('cancelled');
        expect($appointment->cancelled_by)->toBe('system');
        expect($appointment->cancelled_reason)->toBe('stripe_account_not_connected');
        expect(PaymentMandate::query()->where('appointment_id', $appointment->id)->exists())->toBeFalse();
    });
});

test('D-0067: the same rejection applies to a deauthorized tenant, not just one that never onboarded', function () {
    $tenant = Tenant::factory()->create([
        'stripe_connect_account_id' => null,
        'stripe_onboarding_status' => 'deauthorized',
    ]);
    [$service, $staff] = TenantContext::run($tenant->id, function () use ($tenant) {
        $service = Service::factory()->create([
            'tenant_id' => $tenant->id,
            'duration_minutes' => 60,
            'price_amount' => 20000,
            'currency' => 'usd',
            'deposit_type' => 'fixed',
            'deposit_fixed_amount' => 5000,
            'deposit_percentage_bps' => null,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 15,
        ]);
        $staff = Staff::factory()->create(['tenant_id' => $tenant->id]);

        return [$service, $staff];
    });

    $gateway = new FakePaymentIntentGateway;
    app()->bind(PaymentIntentGateway::class, fn () => $gateway);

    $response = postJson("/api/tenants/{$tenant->slug}/bookings", bookingRequestBody($service, $staff));

    $response->assertStatus(409);
    $response->assertJson(['error' => 'BOOKING_UNAVAILABLE']);
    expect($gateway->callCount())->toBe(0);
});

test('D-0067: a merely restricted (still connected) tenant is NOT blocked by the account-routing check', function () {
    $tenant = Tenant::factory()->create([
        'stripe_connect_account_id' => 'acct_fake_restricted',
        'stripe_onboarding_status' => 'restricted',
    ]);
    [$service, $staff] = TenantContext::run($tenant->id, function () use ($tenant) {
        $service = Service::factory()->create([
            'tenant_id' => $tenant->id,
            'duration_minutes' => 60,
            'price_amount' => 20000,
            'currency' => 'usd',
            'deposit_type' => 'fixed',
            'deposit_fixed_amount' => 5000,
            'deposit_percentage_bps' => null,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 15,
        ]);
        $staff = Staff::factory()->create(['tenant_id' => $tenant->id]);

        return [$service, $staff];
    });

    $response = postJson("/api/tenants/{$tenant->slug}/bookings", bookingRequestBody($service, $staff));

    $response->assertCreated();
    $response->assertJsonPath('status', 'pending_payment');
});
