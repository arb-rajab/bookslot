<?php

use App\Models\Tenant;
use App\Tenancy\SignedTenantToken;
use Tests\Support\BookingFixture;

use function Pest\Laravel\postJson;

/**
 * D-0021's payment_confirmation_token instance of the signed-token
 * mechanism, end to end. No Stripe call — this session's controller stubs
 * the confirmation itself (see PaymentConfirmationController's docblock);
 * these tests exercise the token verification, idempotency, and
 * fail-closed shape D-0021/07 describe, independent of what the stub does.
 */
test('a valid token confirms a pending_payment appointment', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);

    $token = SignedTenantToken::issue('confirm_payment', $tenant->id, $appointment->id);

    $response = postJson("/api/bookings/{$token}/confirm-payment");

    $response->assertOk();
    $response->assertJson(['status' => 'confirmed']);
    BookingFixture::assertStatus($tenant, $appointment->id, 'confirmed');
});

test('the same token presented again after confirmation is an idempotent echo, not a new mutation', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);

    $token = SignedTenantToken::issue('confirm_payment', $tenant->id, $appointment->id);

    $first = postJson("/api/bookings/{$token}/confirm-payment");
    $second = postJson("/api/bookings/{$token}/confirm-payment");

    $first->assertOk()->assertJson(['status' => 'confirmed']);
    $second->assertOk()->assertJson(['status' => 'confirmed']);
});

test('a token for an already-cancelled (hold-expired) appointment returns 409 BOOKING_EXPIRED, never a mutation', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, [
        'status' => 'cancelled',
        'cancelled_by' => 'system',
        'cancelled_reason' => 'Hold window elapsed',
        'cancelled_at' => now(),
    ]);

    $token = SignedTenantToken::issue('confirm_payment', $tenant->id, $appointment->id);

    $response = postJson("/api/bookings/{$token}/confirm-payment");

    $response->assertStatus(409);
    $response->assertJson(['error' => 'BOOKING_EXPIRED']);
    BookingFixture::assertStatus($tenant, $appointment->id, 'cancelled');
});

test('a token minted for tenant A\'s appointment cannot confirm tenant B\'s', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $appointmentA = BookingFixture::appointmentFor($tenantA, ['status' => 'pending_payment']);
    $appointmentB = BookingFixture::appointmentFor($tenantB, ['status' => 'pending_payment']);

    $token = SignedTenantToken::issue('confirm_payment', $tenantA->id, $appointmentA->id);

    $response = postJson("/api/bookings/{$token}/confirm-payment");

    $response->assertOk();
    $response->assertJsonPath('status', 'confirmed');
    BookingFixture::assertStatus($tenantA, $appointmentA->id, 'confirmed');
    BookingFixture::assertStatus($tenantB, $appointmentB->id, 'pending_payment');
});

test('a wrong-purpose token (a manage_booking token presented here) is rejected identically to a bad signature', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);

    $wrongPurposeToken = SignedTenantToken::issue('manage_booking', $tenant->id, $appointment->id);

    $response = postJson("/api/bookings/{$wrongPurposeToken}/confirm-payment");

    $response->assertStatus(404);
    $response->assertJson(['error' => 'INVALID_OR_EXPIRED_TOKEN']);
    BookingFixture::assertStatus($tenant, $appointment->id, 'pending_payment');
});

test('an expired token is rejected the same way, independent of the appointment\'s live status', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);

    $expiredToken = SignedTenantToken::issue('confirm_payment', $tenant->id, $appointment->id, now()->subMinute());

    $response = postJson("/api/bookings/{$expiredToken}/confirm-payment");

    $response->assertStatus(404);
    $response->assertJson(['error' => 'INVALID_OR_EXPIRED_TOKEN']);
    BookingFixture::assertStatus($tenant, $appointment->id, 'pending_payment');
});
