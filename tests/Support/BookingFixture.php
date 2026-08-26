<?php

namespace Tests\Support;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentMandate;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Tenancy\TenantContext;

/**
 * No booking-creation endpoint exists this session (see this session's
 * report/handoff) — Feature tests exercising the manage-booking and
 * confirm-payment endpoints need a real appointment (plus its staff,
 * service, and customer) to mint a token against, seeded directly rather
 * than through an HTTP request.
 */
final class BookingFixture
{
    /** @param array<string, mixed> $attributes */
    public static function appointmentFor(Tenant $tenant, array $attributes = []): Appointment
    {
        return TenantContext::run($tenant->id, function () use ($tenant, $attributes) {
            $staff = Staff::factory()->create(['tenant_id' => $tenant->id]);
            $service = Service::factory()->create(['tenant_id' => $tenant->id]);
            $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

            return Appointment::factory()->create(array_merge([
                'tenant_id' => $tenant->id,
                'staff_id' => $staff->id,
                'service_id' => $service->id,
                'customer_id' => $customer->id,
            ], $attributes));
        });
    }

    /**
     * A Feature test's HTTP request tears down its own tenant context when
     * it finishes (SetTenantContext restores the prior, null, CurrentTenant
     * on exit) — a bare Appointment::find() after the response comes back
     * would be fail-closed by the app-layer scope and always resolve to
     * null regardless of what actually happened. Assertions on
     * post-request database state read through TenantContext::run() for
     * that reason.
     */
    public static function assertStatus(Tenant $tenant, string $appointmentId, string $expected): void
    {
        TenantContext::run($tenant->id, function () use ($appointmentId, $expected) {
            expect(Appointment::find($appointmentId)->status)->toBe($expected);
        });
    }

    /**
     * The `payments`/`payment_mandates` rows BookingController's TX2
     * (D-0030) always creates together for a real `pending_payment`
     * appointment — needed by PaymentConfirmationControllerTest (D-0033),
     * which now re-checks the deposit PaymentIntent against
     * PaymentIntentGateway::retrieve() rather than assuming success.
     *
     * @param  array<string, mixed>  $paymentAttributes
     * @param  array<string, mixed>  $mandateAttributes
     */
    public static function depositPaymentFor(
        Tenant $tenant,
        Appointment $appointment,
        array $paymentAttributes = [],
        array $mandateAttributes = [],
    ): Payment {
        return TenantContext::run($tenant->id, function () use ($tenant, $appointment, $paymentAttributes, $mandateAttributes) {
            $payment = Payment::factory()->create(array_merge([
                'tenant_id' => $tenant->id,
                'appointment_id' => $appointment->id,
                'type' => 'deposit',
                'status' => 'requires_action',
            ], $paymentAttributes));

            PaymentMandate::factory()->create(array_merge([
                'tenant_id' => $tenant->id,
                'appointment_id' => $appointment->id,
                'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
                'stripe_payment_method_id' => null,
            ], $mandateAttributes));

            return $payment;
        });
    }
}
