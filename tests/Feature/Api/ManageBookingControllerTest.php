<?php

use App\Models\Tenant;
use App\Tenancy\SignedTenantToken;
use Tests\Support\BookingFixture;

use function Pest\Laravel\getJson;

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
