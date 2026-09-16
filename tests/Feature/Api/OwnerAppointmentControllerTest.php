<?php

use App\Models\Appointment;
use App\Models\BookingEvent;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Tests\Support\BookingFixture;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

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
    expect($rowA['customer_name'])->not->toBeNull();
    expect($rowA['service_name'])->not->toBeNull();
    expect($rowA['staff_name'])->not->toBeNull();
    expect($rowA['deposit_status'])->toBe('succeeded');
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
    $response->assertJson(['id' => $appointment->id, 'deposit_status' => 'succeeded']);
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
