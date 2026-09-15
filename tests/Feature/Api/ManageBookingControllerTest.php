<?php

use App\Models\Appointment;
use App\Models\BookingEvent;
use App\Models\Refund;
use App\Models\Tenant;
use App\Tenancy\SignedTenantToken;
use App\Tenancy\TenantContext;
use Tests\Support\BookingFixture;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * The signed-token tenant-resolution mechanism (D-0009's manage_booking
 * instance), end to end. No booking-creation endpoint exists this session
 * (see this session's report), so tokens are minted directly via
 * SignedTenantToken::issue() — exactly what a future booking-creation
 * endpoint would call, just without that endpoint existing yet to call it
 * from.
 */
test('a valid manage_booking token resolves the correct tenant\'s appointment', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant);

    $token = SignedTenantToken::issue('manage_booking', $tenant->id, $appointment->id);

    $response = getJson("/api/bookings/manage/{$token}");

    $response->assertOk();
    $response->assertJson([
        'appointment_id' => $appointment->id,
        'status' => $appointment->status,
    ]);
});

test('a token minted for tenant A\'s appointment cannot be made to resolve tenant B\'s data', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $appointmentA = BookingFixture::appointmentFor($tenantA);
    BookingFixture::appointmentFor($tenantB);

    // There is no request field to substitute a different appointment —
    // the token's own signed payload is the only source of truth for what
    // gets read (D-0021's reasoning, generalized to the manage_booking
    // instance of the same token class).
    $token = SignedTenantToken::issue('manage_booking', $tenantA->id, $appointmentA->id);

    $response = getJson("/api/bookings/manage/{$token}");

    $response->assertOk();
    $response->assertJsonPath('appointment_id', $appointmentA->id);
});

test('a token with the wrong purpose is rejected identically to a bad signature', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant);

    $wrongPurposeToken = SignedTenantToken::issue('confirm_payment', $tenant->id, $appointment->id);

    $response = getJson("/api/bookings/manage/{$wrongPurposeToken}");

    $response->assertStatus(404);
    $response->assertJson(['error' => 'INVALID_OR_EXPIRED_TOKEN']);
});

test('a tampered token is rejected with the same generic response, leaking nothing', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant);

    $token = SignedTenantToken::issue('manage_booking', $tenant->id, $appointment->id);

    // Flip a character in the middle of the token, not the last one — the
    // last base64 character before padding can carry unused bits that
    // decode to the same byte either way, which would make this test pass
    // for the wrong reason (a no-op tamper rather than a real one).
    $middle = intdiv(strlen($token), 2);
    $tampered = substr($token, 0, $middle).($token[$middle] === 'a' ? 'b' : 'a').substr($token, $middle + 1);

    $response = getJson("/api/bookings/manage/{$tampered}");

    $response->assertStatus(404);
    $response->assertJson(['error' => 'INVALID_OR_EXPIRED_TOKEN']);
});

test('an expired token is rejected the same way, independent of the appointment\'s live status', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant);

    $expiredToken = SignedTenantToken::issue('manage_booking', $tenant->id, $appointment->id, now()->subMinute());

    $response = getJson("/api/bookings/manage/{$expiredToken}");

    $response->assertStatus(404);
    $response->assertJson(['error' => 'INVALID_OR_EXPIRED_TOKEN']);
});

/**
 * D-0052: the customer-facing counterpart of
 * OwnerAppointmentControllerTest's cancel coverage — same bookkeeping-only
 * discipline, same reused manage_booking token as the show() tests above,
 * no new issuance path.
 */
test('a customer cancels their own confirmed booking using their manage_booking token', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);

    $token = SignedTenantToken::issue('manage_booking', $tenant->id, $appointment->id);

    $response = postJson("/api/bookings/manage/{$token}/cancel", ['reason' => 'Change of plans']);

    $response->assertOk();
    $response->assertJson(['appointment_id' => $appointment->id, 'status' => 'cancelled']);

    TenantContext::run($tenant->id, function () use ($appointment) {
        $fresh = Appointment::find($appointment->id);
        expect($fresh->status)->toBe('cancelled');
        expect($fresh->cancelled_by)->toBe('customer');
        expect($fresh->cancelled_reason)->toBe('Change of plans');
        expect($fresh->cancelled_at)->not->toBeNull();

        // Bookkeeping only, same as the owner-initiated cancel path
        // (D-0051) — no refund is issued automatically.
        expect(Refund::query()->count())->toBe(0);

        $event = BookingEvent::query()->where('appointment_id', $appointment->id)->where('to_status', 'cancelled')->first();
        expect($event)->not->toBeNull();
        expect($event->actor_type)->toBe('customer');
        expect($event->actor_id)->toBeNull();
        expect($event->from_status)->toBe('confirmed');
    });
});

test('a customer cancels a still-pending-payment booking, freeing the held slot', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);

    $token = SignedTenantToken::issue('manage_booking', $tenant->id, $appointment->id);

    $response = postJson("/api/bookings/manage/{$token}/cancel");

    $response->assertOk();
    $response->assertJson(['status' => 'cancelled']);
    BookingFixture::assertStatus($tenant, $appointment->id, 'cancelled');
});

test('cancelling an already-completed booking is rejected — nothing left to cancel', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);

    $token = SignedTenantToken::issue('manage_booking', $tenant->id, $appointment->id);

    $response = postJson("/api/bookings/manage/{$token}/cancel");

    $response->assertStatus(409);
    $response->assertJson(['error' => 'INVALID_STATUS_TRANSITION']);
});

test('cancelling an already-cancelled booking is rejected as idempotent-unsafe, not silently re-cancelled', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, [
        'status' => 'cancelled', 'cancelled_by' => 'system', 'cancelled_reason' => 'hold_window_expired', 'cancelled_at' => now(),
    ]);

    $token = SignedTenantToken::issue('manage_booking', $tenant->id, $appointment->id);

    $response = postJson("/api/bookings/manage/{$token}/cancel");

    $response->assertStatus(409);
});

test('a token with the wrong purpose cannot be used to cancel, rejected identically to a bad signature', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);

    $wrongPurposeToken = SignedTenantToken::issue('confirm_payment', $tenant->id, $appointment->id);

    $response = postJson("/api/bookings/manage/{$wrongPurposeToken}/cancel");

    $response->assertStatus(404);
    $response->assertJson(['error' => 'INVALID_OR_EXPIRED_TOKEN']);
    BookingFixture::assertStatus($tenant, $appointment->id, 'confirmed');
});

test('a tampered cancel token is rejected with the same generic response, leaking nothing', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);

    $token = SignedTenantToken::issue('manage_booking', $tenant->id, $appointment->id);

    $middle = intdiv(strlen($token), 2);
    $tampered = substr($token, 0, $middle).($token[$middle] === 'a' ? 'b' : 'a').substr($token, $middle + 1);

    $response = postJson("/api/bookings/manage/{$tampered}/cancel");

    $response->assertStatus(404);
    $response->assertJson(['error' => 'INVALID_OR_EXPIRED_TOKEN']);
});

test('a token minted for tenant A\'s appointment cannot be used to cancel tenant B\'s data', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $appointmentA = BookingFixture::appointmentFor($tenantA, ['status' => 'confirmed']);
    $appointmentB = BookingFixture::appointmentFor($tenantB, ['status' => 'confirmed']);

    $token = SignedTenantToken::issue('manage_booking', $tenantA->id, $appointmentA->id);

    $response = postJson("/api/bookings/manage/{$token}/cancel");

    $response->assertOk();
    $response->assertJsonPath('appointment_id', $appointmentA->id);

    BookingFixture::assertStatus($tenantA, $appointmentA->id, 'cancelled');
    BookingFixture::assertStatus($tenantB, $appointmentB->id, 'confirmed');
});
