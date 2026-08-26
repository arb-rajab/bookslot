<?php

use App\Models\Appointment;
use App\Models\BookingEvent;
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
