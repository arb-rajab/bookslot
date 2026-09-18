<?php

use App\Models\Appointment;
use App\Models\BookingEvent;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Payments\PaymentIntentGateway;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Tests\Support\BookingFixture;
use Tests\Support\FakePaymentIntentGateway;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

// D-0056: refund() is the one action on this controller that calls Stripe
// — bound here, file-wide, same as BookingControllerTest/
// PaymentConfirmationControllerTest, so it never depends on
// AppServiceProvider's own runtime fallback logic (which would otherwise
// try the real StripePaymentIntentGateway against .env.testing's
// look-real-enough dummy key and fail every refund test with a spurious
// 502).
beforeEach(function () {
    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway);
});

/**
 * GET/PATCH /api/owner/appointments... (05-api-contracts.md endpoint 4,
 * D-0042, built this session). Two things this test suite exists to prove
 * that Session 10's owner-role wiring never actually exercised: the owner
 * sees appointments across every staff member in their tenant (unlike
 * D-0013's staff-only-own-bookings narrowing), and the completed/no_show
 * status transition is only reachable from `confirmed` per 04's booking
 * state machine.
 */
function ownerAndTenant(): array
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

    return [$tenant];
}

function loginAsOwner(Tenant $tenant): string
{
    disableConsoleCsrfBypass();

    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'owner@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    return $xsrf;
}

test('an owner sees appointments across every staff member in their tenant, with customer/service/deposit detail', function () {
    [$tenant] = ownerAndTenant();

    $staffA = TenantContext::run($tenant->id, fn () => Staff::factory()->create(['tenant_id' => $tenant->id]));
    $staffB = TenantContext::run($tenant->id, fn () => Staff::factory()->create(['tenant_id' => $tenant->id]));

    $appointmentA = BookingFixture::appointmentFor($tenant, ['staff_id' => $staffA->id]);
    $appointmentB = BookingFixture::appointmentFor($tenant, ['staff_id' => $staffB->id]);
    BookingFixture::depositPaymentFor($tenant, $appointmentA, ['status' => 'succeeded']);

    $otherTenant = Tenant::factory()->create();
    $foreignAppointment = BookingFixture::appointmentFor($otherTenant);

    $xsrf = loginAsOwner($tenant);

    $response = getJson('/api/owner/appointments', ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);
    $response->assertOk();

    $appointments = collect($response->json('appointments'));
    $ids = $appointments->pluck('id');
    expect($ids)->toContain($appointmentA->id);
    expect($ids)->toContain($appointmentB->id);
    expect($ids)->not->toContain($foreignAppointment->id);

    $rowA = $appointments->firstWhere('id', $appointmentA->id);
    expect($rowA['customer_id'])->toBe($appointmentA->customer_id);
    expect($rowA['customer_name'])->not->toBeNull();
    expect($rowA['service_name'])->not->toBeNull();
    expect($rowA['staff_name'])->not->toBeNull();
    expect($rowA['deposit_status'])->toBe('succeeded');
});

test('the appointments list reports a tenant-scoped no-show count that ignores other tenants and non-no_show statuses', function () {
    [$tenant] = ownerAndTenant();

    BookingFixture::appointmentFor($tenant, ['status' => 'no_show']);
    BookingFixture::appointmentFor($tenant, ['status' => 'no_show']);
    BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    BookingFixture::appointmentFor($tenant, ['status' => 'completed']);

    // A no_show appointment in a different tenant must never leak into
    // this tenant's count — FR-15 names the tenant as the count's scope.
    $otherTenant = Tenant::factory()->create();
    BookingFixture::appointmentFor($otherTenant, ['status' => 'no_show']);

    $xsrf = loginAsOwner($tenant);

    $response = getJson('/api/owner/appointments', ['Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf]);

    $response->assertOk();
    $response->assertJson(['no_show_count' => 2]);
});

test('the no-show count respects the from/to window but not the status filter, so filtering to another status does not zero it', function () {
    [$tenant] = ownerAndTenant();

    $past = now()->subDays(10);
    $future = now()->addDays(10);

    BookingFixture::appointmentFor($tenant, [
        'status' => 'no_show',
        'appointment_range' => sprintf('[%s,%s)', $past->toIso8601String(), $past->copy()->addHour()->toIso8601String()),
    ]);
    BookingFixture::appointmentFor($tenant, [
        'status' => 'no_show',
        'appointment_range' => sprintf('[%s,%s)', $future->toIso8601String(), $future->copy()->addHour()->toIso8601String()),
    ]);
    BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);

    $xsrf = loginAsOwner($tenant);

    // Filtering the list to a status other than no_show must not make the
    // summary count report 0 — it describes the window, not the list filter.
    $filtered = getJson('/api/owner/appointments?status=confirmed', [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);
    $filtered->assertOk();
    $filtered->assertJson(['no_show_count' => 2]);

    // A date window narrows the count to only the no-show(s) inside it.
    $windowed = getJson('/api/owner/appointments?from='.now()->subDays(1)->toISOString().'&to='.now()->addDays(30)->toISOString(), [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);
    $windowed->assertOk();
    $windowed->assertJson(['no_show_count' => 1]);
});

test('an owner marking a confirmed appointment attended records completed and a booking_events row', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);

    $xsrf = loginAsOwner($tenant);

    $response = patchJson("/api/owner/appointments/{$appointment->id}/status", ['status' => 'completed'], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'completed']);

    TenantContext::run($tenant->id, function () use ($appointment) {
        expect(Appointment::find($appointment->id)->status)->toBe('completed');

        $event = BookingEvent::query()->where('appointment_id', $appointment->id)->first();
        expect($event)->not->toBeNull();
        expect($event->event_type)->toBe('status_changed');
        expect($event->actor_type)->toBe('owner');
        expect($event->from_status)->toBe('confirmed');
        expect($event->to_status)->toBe('completed');
    });
});

test('an owner marking a confirmed appointment no-show records no_show, no Stripe/refund row created', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    BookingFixture::depositPaymentFor($tenant, $appointment, ['status' => 'succeeded']);

    $xsrf = loginAsOwner($tenant);

    $response = patchJson("/api/owner/appointments/{$appointment->id}/status", ['status' => 'no_show'], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'no_show']);

    TenantContext::run($tenant->id, function () use ($appointment) {
        expect(Appointment::find($appointment->id)->status)->toBe('no_show');
        // D-0006: forfeiture is bookkeeping on the already-captured deposit
        // — no new payments/refunds row, the deposit payment itself is
        // untouched.
        expect(Payment::where('appointment_id', $appointment->id)->count())->toBe(1);
        expect(Refund::query()->count())->toBe(0);
    });
});

test('marking a pending_payment appointment as completed is rejected as an invalid status transition', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);

    $xsrf = loginAsOwner($tenant);

    $response = patchJson("/api/owner/appointments/{$appointment->id}/status", ['status' => 'completed'], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(409);
    $response->assertJson(['error' => 'INVALID_STATUS_TRANSITION']);

    TenantContext::run($tenant->id, function () use ($appointment) {
        expect(Appointment::find($appointment->id)->status)->toBe('pending_payment');
    });
});

test('marking an already-completed appointment no-show is rejected — completed is terminal', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);

    $xsrf = loginAsOwner($tenant);

    $response = patchJson("/api/owner/appointments/{$appointment->id}/status", ['status' => 'no_show'], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(409);
    $response->assertJson(['error' => 'INVALID_STATUS_TRANSITION']);
});

test('an owner cannot mark another tenant\'s appointment, gets a plain 404', function () {
    [$tenant] = ownerAndTenant();
    $otherTenant = Tenant::factory()->create();
    $foreignAppointment = BookingFixture::appointmentFor($otherTenant, ['status' => 'confirmed']);

    $xsrf = loginAsOwner($tenant);

    $response = patchJson("/api/owner/appointments/{$foreignAppointment->id}/status", ['status' => 'completed'], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(404);
    $response->assertJson(['error' => 'NOT_FOUND']);
});

test('a staff member cannot call the owner mark-attendance endpoint', function () {
    $tenant = Tenant::factory()->create();

    $staffUser = TenantContext::run($tenant->id, function () use ($tenant) {
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'staff',
            'email' => 'staff@example.test',
            'password_hash' => Hash::make('correct-password'),
        ]);
    });

    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);

    disableConsoleCsrfBypass();
    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'staff@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    $response = patchJson("/api/owner/appointments/{$appointment->id}/status", ['status' => 'completed'], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(403);
});

test('an unauthenticated request to list owner appointments is rejected', function () {
    getJson('/api/owner/appointments')->assertStatus(401);
});

test('an owner sees full detail on one appointment: payments, reminders, and the audit trail', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    BookingFixture::depositPaymentFor($tenant, $appointment, ['status' => 'succeeded']);
    TenantContext::run($tenant->id, fn () => NotificationDelivery::factory()->create([
        'tenant_id' => $tenant->id,
        'appointment_id' => $appointment->id,
        'purpose' => 'reminder_24h',
        'status' => 'sent',
    ]));

    $xsrf = loginAsOwner($tenant);

    $response = getJson("/api/owner/appointments/{$appointment->id}", [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['id' => $appointment->id, 'customer_id' => $appointment->customer_id, 'deposit_status' => 'succeeded']);
    expect($response->json('customer_email'))->not->toBeNull();
    expect(collect($response->json('payments')))->toHaveCount(1);
    expect(collect($response->json('reminders')))->toHaveCount(1);
    expect($response->json('reminders.0.status'))->toBe('sent');
});

test('an owner cannot view another tenant\'s appointment detail, gets a plain 404', function () {
    [$tenant] = ownerAndTenant();
    $otherTenant = Tenant::factory()->create();
    $foreignAppointment = BookingFixture::appointmentFor($otherTenant);

    $xsrf = loginAsOwner($tenant);

    $response = getJson("/api/owner/appointments/{$foreignAppointment->id}", [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(404);
});

test('an owner cancels a confirmed appointment, freeing the slot with no Stripe/refund row created', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    BookingFixture::depositPaymentFor($tenant, $appointment, ['status' => 'succeeded']);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/cancel", ['reason' => 'Customer called to cancel'], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'cancelled']);

    // Regression guard, found by real-browser E2E coverage (R-08), not by
    // this file: the frontend's appointment-detail page
    // (frontend/app/pages/owner/appointments/[id].vue) assigns whatever
    // this endpoint returns straight onto its `detail` state and its
    // template unconditionally reads `detail.payments.length` (etc.) — a
    // response missing `payments`/`reminders`/`events` doesn't just render
    // a thinner page, it throws and crashes the page entirely, silently,
    // right after a real owner click. cancel() must return the same full
    // detail shape show() does, not the slim list-row shape updateStatus()
    // correctly still uses (that response only ever feeds the appointments
    // *list* page, which never reads those keys).
    $response->assertJsonStructure(['payments', 'reminders', 'events', 'cancelled_by', 'cancelled_reason', 'cancelled_at']);

    TenantContext::run($tenant->id, function () use ($appointment) {
        $fresh = Appointment::find($appointment->id);
        expect($fresh->status)->toBe('cancelled');
        expect($fresh->cancelled_by)->toBe('studio');
        expect($fresh->cancelled_reason)->toBe('Customer called to cancel');
        expect($fresh->cancelled_at)->not->toBeNull();

        // D-0051: bookkeeping only — cancellation never touches Stripe or
        // creates a refunds row, same discipline as no_show forfeiture.
        expect(Refund::query()->count())->toBe(0);

        $event = BookingEvent::query()->where('appointment_id', $appointment->id)->where('to_status', 'cancelled')->first();
        expect($event)->not->toBeNull();
        expect($event->actor_type)->toBe('owner');
        expect($event->from_status)->toBe('confirmed');
    });
});

test('cancelling an already-completed appointment is rejected — nothing left to cancel', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/cancel", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(409);
    $response->assertJson(['error' => 'INVALID_STATUS_TRANSITION']);
});

test('cancelling an already-cancelled appointment is rejected as idempotent-unsafe, not silently re-cancelled', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, [
        'status' => 'cancelled', 'cancelled_by' => 'system', 'cancelled_reason' => 'hold_window_expired', 'cancelled_at' => now(),
    ]);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/cancel", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(409);
});

test('an owner cannot cancel another tenant\'s appointment, gets a plain 404', function () {
    [$tenant] = ownerAndTenant();
    $otherTenant = Tenant::factory()->create();
    $foreignAppointment = BookingFixture::appointmentFor($otherTenant, ['status' => 'confirmed']);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$foreignAppointment->id}/cancel", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(404);
});

test('an owner issues a full refund on a captured deposit, creating a refunds row, updating payment status, and a booking_events row', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    $payment = BookingFixture::depositPaymentFor($tenant, $appointment, ['status' => 'succeeded', 'amount' => 5000]);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/refund", ['reason' => 'Studio closed for the day'], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['payment_status' => 'refunded']);
    expect($response->json('refund.amount'))->toBe(5000);
    expect($response->json('refund.status'))->toBe('succeeded');

    TenantContext::run($tenant->id, function () use ($appointment, $payment) {
        expect(Payment::find($payment->id)->status)->toBe('refunded');

        $refund = Refund::query()->where('payment_id', $payment->id)->first();
        expect($refund)->not->toBeNull();
        expect($refund->amount)->toBe(5000);
        expect($refund->reason)->toBe('Studio closed for the day');
        expect($refund->stripe_refund_id)->not->toBeNull();

        $event = BookingEvent::query()->where('appointment_id', $appointment->id)->where('event_type', 'refund_issued')->first();
        expect($event)->not->toBeNull();
        expect($event->actor_type)->toBe('owner');
        expect($event->metadata['refund_id'])->toBe($refund->id);
    });
});

test('an owner issues a partial refund, leaving the payment partially_refunded', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    $payment = BookingFixture::depositPaymentFor($tenant, $appointment, ['status' => 'succeeded', 'amount' => 5000]);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/refund", ['amount' => 2000], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['payment_status' => 'partially_refunded']);
    expect($response->json('refund.amount'))->toBe(2000);

    TenantContext::run($tenant->id, function () use ($payment) {
        expect(Payment::find($payment->id)->status)->toBe('partially_refunded');
    });
});

test('refunding an already partially-refunded deposit a second time is rejected as 409 — partially_refunded is terminal per 04\'s payment state machine, not incrementally toppable-up', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    BookingFixture::depositPaymentFor($tenant, $appointment, ['status' => 'partially_refunded', 'amount' => 5000]);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/refund", ['amount' => 1000], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(409);
    $response->assertJson(['error' => 'PAYMENT_NOT_REFUNDABLE']);
});

test('a refund request exceeding the remaining refundable balance is rejected as 422', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    BookingFixture::depositPaymentFor($tenant, $appointment, ['status' => 'succeeded', 'amount' => 5000]);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/refund", ['amount' => 5001], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(422);
    $response->assertJson(['error' => 'VALIDATION_FAILED']);
});

test('refunding a deposit that was never captured is rejected as 409, not reachable via a NOT_FOUND-shaped bug', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);
    BookingFixture::depositPaymentFor($tenant, $appointment, ['status' => 'requires_action']);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/refund", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(409);
    $response->assertJson(['error' => 'PAYMENT_NOT_REFUNDABLE']);
});

test('refunding an already fully-refunded deposit a second time is rejected as 409', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    BookingFixture::depositPaymentFor($tenant, $appointment, ['status' => 'refunded', 'amount' => 5000]);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/refund", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(409);
    $response->assertJson(['error' => 'PAYMENT_NOT_REFUNDABLE']);
});

test('an owner cannot refund another tenant\'s appointment, gets a plain 404 — cross-tenant financial-action isolation', function () {
    [$tenant] = ownerAndTenant();
    $otherTenant = Tenant::factory()->create();
    $foreignAppointment = BookingFixture::appointmentFor($otherTenant, ['status' => 'confirmed']);
    BookingFixture::depositPaymentFor($otherTenant, $foreignAppointment, ['status' => 'succeeded', 'amount' => 5000]);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$foreignAppointment->id}/refund", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(404);
    $response->assertJson(['error' => 'NOT_FOUND']);

    // The other tenant's own payment/refund state must be completely
    // unaffected — not just that this request failed, but that RLS never
    // let it touch the foreign row at all.
    TenantContext::run($otherTenant->id, function () use ($foreignAppointment) {
        $payment = Payment::query()->where('appointment_id', $foreignAppointment->id)->first();
        expect($payment->status)->toBe('succeeded');
        expect(Refund::query()->where('payment_id', $payment->id)->count())->toBe(0);
    });
});

test('a staff member cannot call the owner refund endpoint', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () use ($tenant) {
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'staff',
            'email' => 'staff@example.test',
            'password_hash' => Hash::make('correct-password'),
        ]);
    });

    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    BookingFixture::depositPaymentFor($tenant, $appointment, ['status' => 'succeeded']);

    disableConsoleCsrfBypass();
    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'staff@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    $response = postJson("/api/owner/appointments/{$appointment->id}/refund", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(403);
});

test('an unauthenticated request to the refund endpoint is rejected', function () {
    postJson('/api/owner/appointments/some-id/refund')->assertStatus(401);
});

test('a refund is still allowed on a confirmed (not cancelled) appointment — J8 dispute path, not gated on appointment status', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    BookingFixture::depositPaymentFor($tenant, $appointment, ['status' => 'succeeded', 'amount' => 5000]);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/refund", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();

    TenantContext::run($tenant->id, function () use ($appointment) {
        // The appointment's own status is untouched by a refund — refund
        // and cancellation are independent workflows (D-0056/D-0051).
        expect(Appointment::find($appointment->id)->status)->toBe('confirmed');
    });
});

// D-0057: POST /api/owner/appointments/{id}/balance/charge (05-api-
// contracts.md endpoint 6, J5). Same discipline as the refund suite above
// — FakePaymentIntentGateway is bound file-wide (see this file's top
// beforeEach), and every test here constructs its own configured instance
// via app()->bind() when it needs a specific chargeOffSession() outcome.
test('an owner charges the balance on a completed appointment, creating a succeeded balance payment and a booking_events row', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);
    BookingFixture::depositPaymentFor(
        $tenant,
        $appointment,
        ['status' => 'succeeded', 'amount' => 2000],
        ['balance_amount_disclosed' => 8000, 'stripe_payment_method_id' => 'pm_fake_saved_card'],
    );

    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway(
        chargeOffSessionStatus: 'succeeded',
        chargeOffSessionPaymentIntentId: 'pi_fake_balance_charge_1',
    ));

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/balance/charge", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'succeeded']);
    expect($response->json('payment_id'))->not->toBeNull();

    TenantContext::run($tenant->id, function () use ($appointment, $response) {
        $payment = Payment::find($response->json('payment_id'));
        expect($payment)->not->toBeNull();
        expect($payment->appointment_id)->toBe($appointment->id);
        expect($payment->type)->toBe('balance');
        expect($payment->status)->toBe('succeeded');
        expect($payment->amount)->toBe(8000);
        expect($payment->stripe_payment_intent_id)->toBe('pi_fake_balance_charge_1');

        $event = BookingEvent::query()->where('appointment_id', $appointment->id)->where('event_type', 'balance_charge_succeeded')->first();
        expect($event)->not->toBeNull();
        expect($event->actor_type)->toBe('owner');
        expect($event->metadata['payment_id'])->toBe($payment->id);
        expect($event->metadata['amount'])->toBe(8000);
    });
});

test('a card-declined off-session charge returns 200 with a failed status and fallback_action, and records a failed payment + booking_events row', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);
    BookingFixture::depositPaymentFor(
        $tenant,
        $appointment,
        ['status' => 'succeeded', 'amount' => 2000],
        ['balance_amount_disclosed' => 8000, 'stripe_payment_method_id' => 'pm_fake_saved_card'],
    );

    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway(
        chargeOffSessionStatus: 'failed',
        chargeOffSessionFailureCode: 'card_declined',
        chargeOffSessionPaymentIntentId: 'pi_fake_balance_declined',
    ));

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/balance/charge", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson([
        'status' => 'failed',
        'failure_code' => 'card_declined',
        'fallback_action' => 'mark_paid_manually',
    ]);

    TenantContext::run($tenant->id, function () use ($appointment) {
        $payment = Payment::query()->where('appointment_id', $appointment->id)->where('type', 'balance')->first();
        expect($payment)->not->toBeNull();
        expect($payment->status)->toBe('failed');
        expect($payment->failure_code)->toBe('card_declined');

        $event = BookingEvent::query()->where('appointment_id', $appointment->id)->where('event_type', 'balance_charge_failed')->first();
        expect($event)->not->toBeNull();
        expect($event->metadata['failure_code'])->toBe('card_declined');
    });
});

test('an authentication-required off-session charge (SCA, no cardholder present) returns 200 with a failed status and fallback_action', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);
    BookingFixture::depositPaymentFor(
        $tenant,
        $appointment,
        ['status' => 'succeeded', 'amount' => 2000],
        ['balance_amount_disclosed' => 8000, 'stripe_payment_method_id' => 'pm_fake_saved_card'],
    );

    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway(
        chargeOffSessionStatus: 'failed',
        chargeOffSessionFailureCode: 'authentication_required',
        chargeOffSessionPaymentIntentId: 'pi_fake_balance_auth_required',
    ));

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/balance/charge", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson([
        'status' => 'failed',
        'failure_code' => 'authentication_required',
        'fallback_action' => 'mark_paid_manually',
    ]);

    TenantContext::run($tenant->id, function () use ($appointment) {
        $payment = Payment::query()->where('appointment_id', $appointment->id)->where('type', 'balance')->first();
        expect($payment->status)->toBe('failed');
        expect($payment->failure_code)->toBe('authentication_required');
    });
});

test('a previously card-declined balance charge can be retried and succeed — failed is not a terminal state for a balance payment', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);
    BookingFixture::depositPaymentFor(
        $tenant,
        $appointment,
        ['status' => 'succeeded', 'amount' => 2000],
        ['balance_amount_disclosed' => 8000, 'stripe_payment_method_id' => 'pm_fake_saved_card'],
    );
    TenantContext::run($tenant->id, fn () => Payment::factory()->create([
        'tenant_id' => $tenant->id,
        'appointment_id' => $appointment->id,
        'type' => 'balance',
        'status' => 'failed',
        'failure_code' => 'card_declined',
        'amount' => 8000,
    ]));

    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway(chargeOffSessionStatus: 'succeeded'));

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/balance/charge", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'succeeded']);
});

test('a genuine Stripe-provider failure (not a decline) during the balance charge is mapped to 502, not silently treated as a decline', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);
    BookingFixture::depositPaymentFor(
        $tenant,
        $appointment,
        ['status' => 'succeeded', 'amount' => 2000],
        ['balance_amount_disclosed' => 8000, 'stripe_payment_method_id' => 'pm_fake_saved_card'],
    );

    app()->bind(PaymentIntentGateway::class, fn () => new FakePaymentIntentGateway(shouldThrowOnChargeOffSession: true));

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/balance/charge", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(502);
    $response->assertJson(['error' => 'PAYMENT_PROVIDER_UNAVAILABLE']);

    TenantContext::run($tenant->id, function () use ($appointment) {
        expect(Payment::query()->where('appointment_id', $appointment->id)->where('type', 'balance')->count())->toBe(0);
    });
});

test('charging the balance on a still-confirmed (not yet completed) appointment is rejected as 409 — J4 forbids a balance charge on anything but attended', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    BookingFixture::depositPaymentFor(
        $tenant,
        $appointment,
        ['status' => 'succeeded', 'amount' => 2000],
        ['balance_amount_disclosed' => 8000, 'stripe_payment_method_id' => 'pm_fake_saved_card'],
    );

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/balance/charge", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(409);
    $response->assertJson(['error' => 'INVALID_STATUS_TRANSITION']);
});

test('charging the balance on a no_show appointment is rejected — J4: no balance charge is ever attempted for a no-show', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'no_show']);
    BookingFixture::depositPaymentFor(
        $tenant,
        $appointment,
        ['status' => 'succeeded', 'amount' => 2000],
        ['balance_amount_disclosed' => 8000, 'stripe_payment_method_id' => 'pm_fake_saved_card'],
    );

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/balance/charge", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(409);
    $response->assertJson(['error' => 'INVALID_STATUS_TRANSITION']);
});

test('charging the balance when the deposit was never captured is rejected as 409', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);
    BookingFixture::depositPaymentFor($tenant, $appointment, ['status' => 'failed']);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/balance/charge", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(409);
    $response->assertJson(['error' => 'DEPOSIT_NOT_CAPTURED']);
});

test('charging an already-settled balance a second time is rejected as 409, no second Stripe call attempted', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);
    BookingFixture::depositPaymentFor(
        $tenant,
        $appointment,
        ['status' => 'succeeded', 'amount' => 2000],
        ['balance_amount_disclosed' => 8000, 'stripe_payment_method_id' => 'pm_fake_saved_card'],
    );
    TenantContext::run($tenant->id, fn () => Payment::factory()->create([
        'tenant_id' => $tenant->id,
        'appointment_id' => $appointment->id,
        'type' => 'balance',
        'status' => 'succeeded',
        'amount' => 8000,
    ]));

    $gateway = new FakePaymentIntentGateway;
    app()->bind(PaymentIntentGateway::class, fn () => $gateway);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/balance/charge", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(409);
    $response->assertJson(['error' => 'BALANCE_ALREADY_SETTLED']);
    expect($gateway->chargeOffSessionCallCount())->toBe(0);
});

test('charging the balance when no payment method was ever saved (R-07\'s backfill gap) is rejected as 409, not attempted against Stripe', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);
    BookingFixture::depositPaymentFor(
        $tenant,
        $appointment,
        ['status' => 'succeeded', 'amount' => 2000],
        ['balance_amount_disclosed' => 8000, 'stripe_payment_method_id' => null],
    );

    $gateway = new FakePaymentIntentGateway;
    app()->bind(PaymentIntentGateway::class, fn () => $gateway);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$appointment->id}/balance/charge", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(409);
    $response->assertJson(['error' => 'PAYMENT_METHOD_NOT_AVAILABLE']);
    expect($gateway->chargeOffSessionCallCount())->toBe(0);
});

test('an owner cannot charge the balance on another tenant\'s appointment, gets a plain 404 — cross-tenant financial-action isolation, foreign state untouched', function () {
    [$tenant] = ownerAndTenant();
    $otherTenant = Tenant::factory()->create();
    $foreignAppointment = BookingFixture::appointmentFor($otherTenant, ['status' => 'completed']);
    BookingFixture::depositPaymentFor(
        $otherTenant,
        $foreignAppointment,
        ['status' => 'succeeded', 'amount' => 2000],
        ['balance_amount_disclosed' => 8000, 'stripe_payment_method_id' => 'pm_fake_saved_card'],
    );

    $gateway = new FakePaymentIntentGateway;
    app()->bind(PaymentIntentGateway::class, fn () => $gateway);

    $xsrf = loginAsOwner($tenant);

    $response = postJson("/api/owner/appointments/{$foreignAppointment->id}/balance/charge", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(404);
    $response->assertJson(['error' => 'NOT_FOUND']);
    expect($gateway->chargeOffSessionCallCount())->toBe(0);

    // Not just that this request failed — the other tenant's own payment
    // state must be completely unaffected, proving RLS never let this
    // request touch the foreign row at all.
    TenantContext::run($otherTenant->id, function () use ($foreignAppointment) {
        expect(Payment::query()->where('appointment_id', $foreignAppointment->id)->where('type', 'balance')->count())->toBe(0);
        expect(BookingEvent::query()->where('appointment_id', $foreignAppointment->id)->whereIn('event_type', ['balance_charge_succeeded', 'balance_charge_failed'])->count())->toBe(0);
    });
});

test('a staff member cannot call the owner balance-charge endpoint', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () use ($tenant) {
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'staff',
            'email' => 'staff-balance@example.test',
            'password_hash' => Hash::make('correct-password'),
        ]);
    });

    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);
    BookingFixture::depositPaymentFor(
        $tenant,
        $appointment,
        ['status' => 'succeeded', 'amount' => 2000],
        ['balance_amount_disclosed' => 8000, 'stripe_payment_method_id' => 'pm_fake_saved_card'],
    );

    disableConsoleCsrfBypass();
    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'staff-balance@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    $response = postJson("/api/owner/appointments/{$appointment->id}/balance/charge", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(403);
});

test('an unauthenticated request to the balance-charge endpoint is rejected', function () {
    postJson('/api/owner/appointments/some-id/balance/charge')->assertStatus(401);
});

test('a cancelled appointment\'s slot can be rebooked — the exclusion constraint\'s partial WHERE excludes cancelled', function () {
    [$tenant] = ownerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);

    $xsrf = loginAsOwner($tenant);
    postJson("/api/owner/appointments/{$appointment->id}/cancel", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ])->assertOk();

    TenantContext::run($tenant->id, function () use ($appointment) {
        $rebooked = Appointment::factory()->create([
            'tenant_id' => $appointment->tenant_id,
            'staff_id' => $appointment->staff_id,
            'service_id' => $appointment->service_id,
            'customer_id' => $appointment->customer_id,
            'appointment_range' => $appointment->appointment_range,
            'status' => 'confirmed',
        ]);

        expect($rebooked->id)->not->toBe($appointment->id);
    });
});
