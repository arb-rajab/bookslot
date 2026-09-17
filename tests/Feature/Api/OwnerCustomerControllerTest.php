<?php

use App\Mail\AppointmentReminderMail;
use App\Models\Appointment;
use App\Models\BookingEvent;
use App\Models\Customer;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\PaymentMandate;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\BookingFixture;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * GET /api/owner/customers/{id}/export, POST /api/owner/customers/{id}/erasure
 * (05-api-contracts.md endpoint 11, FR-18, D-0059). No PaymentIntentGateway
 * binding needed file-wide — unlike refund()/chargeBalance(), neither
 * action here calls Stripe (D-0022).
 *
 * POST /api/owner/customers/{id}/re-invite (05-api-contracts.md endpoint 9,
 * FR-23/D-0014/D-0023, built Session 30/D-0060).
 */
function customerOwnerAndTenant(): array
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

function loginAsCustomerOwner(Tenant $tenant): string
{
    disableConsoleCsrfBypass();

    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'owner@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    return $xsrf;
}

test('an owner exports a customer\'s data: their record, appointments, payments (with refunds), mandates minus Stripe IDs, and scoped booking_events', function () {
    [$tenant] = customerOwnerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);
    $payment = BookingFixture::depositPaymentFor(
        $tenant,
        $appointment,
        ['status' => 'succeeded', 'amount' => 5000],
        ['mandate_text' => 'I agree to the deposit terms.', 'accepted_ip' => '203.0.113.9'],
    );

    TenantContext::run($tenant->id, function () use ($appointment, $payment) {
        Refund::factory()->create([
            'tenant_id' => $appointment->tenant_id,
            'payment_id' => $payment->id,
            'amount' => 1000,
            'status' => 'succeeded',
        ]);

        BookingEvent::query()->create([
            'tenant_id' => $appointment->tenant_id,
            'appointment_id' => $appointment->id,
            'actor_type' => 'owner',
            'event_type' => 'status_changed',
            'from_status' => 'confirmed',
            'to_status' => 'completed',
        ]);
    });

    $customerId = TenantContext::run($tenant->id, fn () => Appointment::find($appointment->id)->customer_id);

    // A second, unrelated customer's own appointment must never leak into
    // this export.
    $otherAppointment = BookingFixture::appointmentFor($tenant);

    $xsrf = loginAsCustomerOwner($tenant);

    $response = getJson("/api/owner/customers/{$customerId}/export", [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    $response->assertJson(['customer' => ['id' => $customerId]]);

    $appointments = collect($response->json('appointments'));
    expect($appointments)->toHaveCount(1);
    expect($appointments->first()['id'])->toBe($appointment->id);
    expect($appointments->pluck('id'))->not->toContain($otherAppointment->id);

    $payments = collect($response->json('payments'));
    expect($payments)->toHaveCount(1);
    expect($payments->first()['amount'])->toBe(5000);
    expect($payments->first())->not->toHaveKey('stripe_payment_intent_id');
    expect(collect($payments->first()['refunds']))->toHaveCount(1);
    expect($payments->first()['refunds'][0]['amount'])->toBe(1000);

    $mandates = collect($response->json('payment_mandates'));
    expect($mandates)->toHaveCount(1);
    expect($mandates->first()['mandate_text'])->toBe('I agree to the deposit terms.');
    expect($mandates->first()['accepted_ip'])->toBe('203.0.113.9');
    expect($mandates->first())->not->toHaveKey('stripe_payment_intent_id');
    expect($mandates->first())->not->toHaveKey('stripe_payment_method_id');

    $events = collect($response->json('booking_events'));
    expect($events->pluck('event_type'))->toContain('status_changed');
});

test('an owner cannot export another tenant\'s customer, gets a plain 404', function () {
    [$tenant] = customerOwnerAndTenant();
    $otherTenant = Tenant::factory()->create();
    $foreignAppointment = BookingFixture::appointmentFor($otherTenant);
    $foreignCustomerId = TenantContext::run($otherTenant->id, fn () => Appointment::find($foreignAppointment->id)->customer_id);

    $xsrf = loginAsCustomerOwner($tenant);

    $response = getJson("/api/owner/customers/{$foreignCustomerId}/export", [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(404);
    $response->assertJson(['error' => 'NOT_FOUND']);
});

test('a staff member cannot call the owner customer-export endpoint', function () {
    $tenant = Tenant::factory()->create();
    TenantContext::run($tenant->id, fn () => User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => 'staff',
        'email' => 'staff-export@example.test',
        'password_hash' => Hash::make('correct-password'),
    ]));
    $appointment = BookingFixture::appointmentFor($tenant);
    $customerId = TenantContext::run($tenant->id, fn () => Appointment::find($appointment->id)->customer_id);

    disableConsoleCsrfBypass();
    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'staff-export@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    $response = getJson("/api/owner/customers/{$customerId}/export", [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(403);
});

test('an unauthenticated request to the customer-export endpoint is rejected', function () {
    getJson('/api/owner/customers/some-id/export')->assertStatus(401);
});

test('an owner erases a customer with no live bookings: PII anonymized, erasure_requested_at set, appointments/payments/mandates/booking_events untouched (D-0022)', function () {
    [$tenant] = customerOwnerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);
    $payment = BookingFixture::depositPaymentFor(
        $tenant,
        $appointment,
        ['status' => 'succeeded'],
        ['mandate_text' => 'Deposit terms', 'accepted_ip' => '203.0.113.9', 'accepted_user_agent' => 'TestAgent/1.0'],
    );

    $customerId = TenantContext::run($tenant->id, fn () => Appointment::find($appointment->id)->customer_id);
    $originalMandate = TenantContext::run($tenant->id, fn () => PaymentMandate::query()->where('appointment_id', $appointment->id)->first()->getAttributes());

    $xsrf = loginAsCustomerOwner($tenant);

    $response = postJson("/api/owner/customers/{$customerId}/erasure", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    expect($response->json('erasure_requested_at'))->not->toBeNull();
    expect($response->json('name'))->not->toBe('');

    TenantContext::run($tenant->id, function () use ($customerId, $appointment, $payment, $originalMandate) {
        $customer = Customer::find($customerId);
        expect($customer->name)->toBe('Erased Customer');
        expect($customer->email)->toEndWith('@erased.invalid');
        expect($customer->phone)->toBeNull();
        expect($customer->erasure_requested_at)->not->toBeNull();

        // D-0022: payment_mandates is never modified or deleted by erasure
        // — every column, including accepted_ip/accepted_user_agent,
        // survives byte-for-byte.
        $mandate = PaymentMandate::query()->where('appointment_id', $appointment->id)->first();
        expect($mandate->mandate_text)->toBe($originalMandate['mandate_text']);
        expect($mandate->accepted_ip)->toBe($originalMandate['accepted_ip']);
        expect($mandate->accepted_user_agent)->toBe($originalMandate['accepted_user_agent']);

        // appointments/payments are historical business records, untouched.
        expect(Appointment::find($appointment->id)->status)->toBe('completed');
        expect(Payment::find($payment->id)->status)->toBe('succeeded');

        $event = BookingEvent::query()->where('event_type', 'customer_erased')->first();
        expect($event)->not->toBeNull();
        expect($event->appointment_id)->toBeNull();
        expect($event->actor_type)->toBe('owner');
        expect($event->metadata['customer_id'])->toBe($customerId);
    });
});

test('erasing a customer with a still-confirmed (live) appointment is blocked as 409, PII left untouched', function () {
    [$tenant] = customerOwnerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    $customerId = TenantContext::run($tenant->id, fn () => Appointment::find($appointment->id)->customer_id);

    $xsrf = loginAsCustomerOwner($tenant);

    $response = postJson("/api/owner/customers/{$customerId}/erasure", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(409);
    $response->assertJson(['error' => 'ACTIVE_BOOKING_EXISTS']);

    TenantContext::run($tenant->id, function () use ($customerId) {
        $customer = Customer::find($customerId);
        expect($customer->erasure_requested_at)->toBeNull();
        expect($customer->name)->not->toBe('Erased Customer');
    });
});

test('erasing a customer with a still-pending_payment appointment is blocked as 409', function () {
    [$tenant] = customerOwnerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);
    $customerId = TenantContext::run($tenant->id, fn () => Appointment::find($appointment->id)->customer_id);

    $xsrf = loginAsCustomerOwner($tenant);

    $response = postJson("/api/owner/customers/{$customerId}/erasure", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(409);
    $response->assertJson(['error' => 'ACTIVE_BOOKING_EXISTS']);
});

test('erasure is not blocked by a cancelled or no_show appointment — only pending_payment/confirmed are live', function () {
    [$tenant] = customerOwnerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, [
        'status' => 'cancelled', 'cancelled_by' => 'studio', 'cancelled_reason' => 'test', 'cancelled_at' => now(),
    ]);
    $customerId = TenantContext::run($tenant->id, fn () => Appointment::find($appointment->id)->customer_id);

    $xsrf = loginAsCustomerOwner($tenant);

    $response = postJson("/api/owner/customers/{$customerId}/erasure", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertOk();
    expect($response->json('erasure_requested_at'))->not->toBeNull();
});

test('erasing an already-erased customer a second time is idempotent — a 200 echo, not an error, and does not re-randomize the anonymized email', function () {
    [$tenant] = customerOwnerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);
    $customerId = TenantContext::run($tenant->id, fn () => Appointment::find($appointment->id)->customer_id);

    $xsrf = loginAsCustomerOwner($tenant);

    $first = postJson("/api/owner/customers/{$customerId}/erasure", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);
    $first->assertOk();
    $firstEmail = $first->json('email');

    $second = postJson("/api/owner/customers/{$customerId}/erasure", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);
    $second->assertOk();
    expect($second->json('email'))->toBe($firstEmail);

    TenantContext::run($tenant->id, function () {
        expect(BookingEvent::query()->where('event_type', 'customer_erased')->count())->toBe(1);
    });
});

test('an owner cannot erase another tenant\'s customer, gets a plain 404 — the foreign customer is left untouched', function () {
    [$tenant] = customerOwnerAndTenant();
    $otherTenant = Tenant::factory()->create();
    $foreignAppointment = BookingFixture::appointmentFor($otherTenant, ['status' => 'completed']);
    $foreignCustomerId = TenantContext::run($otherTenant->id, fn () => Appointment::find($foreignAppointment->id)->customer_id);

    $xsrf = loginAsCustomerOwner($tenant);

    $response = postJson("/api/owner/customers/{$foreignCustomerId}/erasure", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(404);
    $response->assertJson(['error' => 'NOT_FOUND']);

    TenantContext::run($otherTenant->id, function () use ($foreignCustomerId) {
        $customer = Customer::find($foreignCustomerId);
        expect($customer->erasure_requested_at)->toBeNull();
        expect($customer->name)->not->toBe('Erased Customer');
    });
});

test('a staff member cannot call the owner customer-erasure endpoint', function () {
    $tenant = Tenant::factory()->create();
    TenantContext::run($tenant->id, fn () => User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => 'staff',
        'email' => 'staff-erase@example.test',
        'password_hash' => Hash::make('correct-password'),
    ]));
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);
    $customerId = TenantContext::run($tenant->id, fn () => Appointment::find($appointment->id)->customer_id);

    disableConsoleCsrfBypass();
    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'staff-erase@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    $response = postJson("/api/owner/customers/{$customerId}/erasure", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(403);
});

test('an unauthenticated request to the customer-erasure endpoint is rejected', function () {
    postJson('/api/owner/customers/some-id/erasure')->assertStatus(401);
});

test('an owner re-invites a customer: queues an email and records a distinct audit/notification purpose', function () {
    Mail::fake();

    [$tenant] = customerOwnerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'no_show']);
    $customerEmail = TenantContext::run($tenant->id, fn () => $appointment->customer->email);

    $xsrf = loginAsCustomerOwner($tenant);

    $response = postJson("/api/owner/customers/{$appointment->customer_id}/re-invite", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(202);
    $response->assertJson(['status' => 'queued', 'channel' => 'email']);

    Mail::assertSent(AppointmentReminderMail::class, fn ($mail) => $mail->hasTo($customerEmail) && $mail->delivery->purpose === 'rebooking_invite'
    );

    TenantContext::run($tenant->id, function () use ($appointment) {
        $delivery = NotificationDelivery::query()->where('appointment_id', $appointment->id)->where('purpose', 'rebooking_invite')->firstOrFail();
        expect($delivery->channel)->toBe('email');
        expect($delivery->status)->toBe('sent');

        $event = BookingEvent::query()->where('appointment_id', $appointment->id)->where('event_type', 'rebooking_invite_sent')->firstOrFail();
        expect($event->actor_type)->toBe('owner');
        expect($event->metadata['notification_delivery_id'])->toBe($delivery->id);
    });
});

test('re-invite is independent of no-show status — a customer whose last appointment was completed can also be re-invited', function () {
    Mail::fake();

    [$tenant] = customerOwnerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'completed']);

    $xsrf = loginAsCustomerOwner($tenant);

    $response = postJson("/api/owner/customers/{$appointment->customer_id}/re-invite", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(202);
    Mail::assertSent(AppointmentReminderMail::class);
});

test('a second re-invite call for the same customer is not deduplicated — D-0060 treats it as a real, repeatable resend', function () {
    Mail::fake();

    [$tenant] = customerOwnerAndTenant();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'no_show']);

    $xsrf = loginAsCustomerOwner($tenant);

    $first = postJson("/api/owner/customers/{$appointment->customer_id}/re-invite", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);
    $first->assertStatus(202);

    $second = postJson("/api/owner/customers/{$appointment->customer_id}/re-invite", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);
    $second->assertStatus(202);

    Mail::assertSent(AppointmentReminderMail::class, 2);

    TenantContext::run($tenant->id, function () use ($appointment) {
        $count = NotificationDelivery::query()
            ->where('appointment_id', $appointment->id)
            ->where('purpose', 'rebooking_invite')
            ->count();
        expect($count)->toBe(2);

        $eventCount = BookingEvent::query()
            ->where('appointment_id', $appointment->id)
            ->where('event_type', 'rebooking_invite_sent')
            ->count();
        expect($eventCount)->toBe(2);
    });
});

test('re-invite attaches to the customer\'s most recent appointment when they have more than one', function () {
    Mail::fake();

    [$tenant] = customerOwnerAndTenant();

    $customerId = TenantContext::run($tenant->id, fn () => Customer::factory()->create(['tenant_id' => $tenant->id])->id);

    // starts_at is a database-generated STORED column derived from
    // appointment_range (Appointment model's own docblock) — never
    // settable directly, so distinct ordering is established via
    // appointment_range at creation time, not by mutating starts_at after
    // the fact. Distinct staff per appointment sidesteps the
    // tenant+staff exclusion constraint regardless of range overlap.
    $olderStart = now()->addDays(10);
    $olderAppointment = BookingFixture::appointmentFor($tenant, [
        'customer_id' => $customerId,
        'status' => 'completed',
        'appointment_range' => sprintf('[%s,%s)', $olderStart->toIso8601String(), $olderStart->clone()->addHour()->toIso8601String()),
    ]);

    $newerStart = now()->addDays(40);
    $newerAppointment = BookingFixture::appointmentFor($tenant, [
        'customer_id' => $customerId,
        'status' => 'no_show',
        'appointment_range' => sprintf('[%s,%s)', $newerStart->toIso8601String(), $newerStart->clone()->addHour()->toIso8601String()),
    ]);

    $xsrf = loginAsCustomerOwner($tenant);

    $response = postJson("/api/owner/customers/{$customerId}/re-invite", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);
    $response->assertStatus(202);

    TenantContext::run($tenant->id, function () use ($newerAppointment, $olderAppointment) {
        expect(NotificationDelivery::query()->where('appointment_id', $newerAppointment->id)->where('purpose', 'rebooking_invite')->exists())->toBeTrue();
        expect(NotificationDelivery::query()->where('appointment_id', $olderAppointment->id)->where('purpose', 'rebooking_invite')->exists())->toBeFalse();
    });
});

test('re-inviting an unknown customer id returns a plain 404', function () {
    [$tenant] = customerOwnerAndTenant();
    $xsrf = loginAsCustomerOwner($tenant);

    $response = postJson('/api/owner/customers/'.Str::uuid()->toString().'/re-invite', [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(404);
    $response->assertJson(['error' => 'NOT_FOUND']);
});

test('a customer with no appointment history at all (a defensive guard, not a reachable path in practice) also gets a plain 404, not a 500', function () {
    [$tenant] = customerOwnerAndTenant();
    $customerId = TenantContext::run($tenant->id, fn () => Customer::factory()->create(['tenant_id' => $tenant->id])->id);

    $xsrf = loginAsCustomerOwner($tenant);

    $response = postJson("/api/owner/customers/{$customerId}/re-invite", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(404);
    $response->assertJson(['error' => 'NOT_FOUND']);
});

test('an owner cannot re-invite another tenant\'s customer — cross-tenant access resolves to the same 404, and the other tenant is left untouched', function () {
    Mail::fake();

    [$tenant] = customerOwnerAndTenant();
    $otherTenant = Tenant::factory()->create();
    $foreignAppointment = BookingFixture::appointmentFor($otherTenant, ['status' => 'no_show']);

    $xsrf = loginAsCustomerOwner($tenant);

    $response = postJson("/api/owner/customers/{$foreignAppointment->customer_id}/re-invite", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(404);
    $response->assertJson(['error' => 'NOT_FOUND']);

    Mail::assertNothingSent();

    TenantContext::run($otherTenant->id, function () use ($foreignAppointment) {
        expect(NotificationDelivery::query()->where('appointment_id', $foreignAppointment->id)->where('purpose', 'rebooking_invite')->exists())->toBeFalse();
        expect(BookingEvent::query()->where('appointment_id', $foreignAppointment->id)->where('event_type', 'rebooking_invite_sent')->exists())->toBeFalse();
    });
});

test('a staff member cannot call the owner re-invite endpoint', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () use ($tenant) {
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'staff',
            'email' => 'staff-reinvite@example.test',
            'password_hash' => Hash::make('correct-password'),
        ]);
    });

    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'no_show']);

    disableConsoleCsrfBypass();
    [$xsrf, $login] = loginAndCaptureXsrf("/api/tenants/{$tenant->slug}/login", [
        'email' => 'staff-reinvite@example.test',
        'password' => 'correct-password',
    ]);
    $login->assertOk();

    $response = postJson("/api/owner/customers/{$appointment->customer_id}/re-invite", [], [
        'Origin' => 'http://localhost', 'X-XSRF-TOKEN' => $xsrf,
    ]);

    $response->assertStatus(403);
});

test('an unauthenticated request to re-invite is rejected', function () {
    postJson('/api/owner/customers/'.Str::uuid()->toString().'/re-invite')
        ->assertStatus(401);
});
