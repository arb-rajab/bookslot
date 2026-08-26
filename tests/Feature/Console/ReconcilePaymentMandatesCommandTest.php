<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Tests\Support\BookingFixture;

use function Pest\Laravel\artisan;

/**
 * D-0037 (09-decision-log.md), R-07's mitigation (10-risk-register.md):
 * proves `mandates:reconcile-backfill` actually distinguishes a genuinely
 * orphaned `payment_mandates.stripe_payment_method_id` (D-0031) from one
 * still legitimately mid-flight through a normal booking, using the
 * `booking.reconciliation_grace_minutes` config value (30 by default) as
 * the cutoff.
 *
 * Uses `Pest\Laravel\artisan()` rather than `$this->artisan()`, and
 * `Log::shouldReceive()` (a real, inherited static method on the base
 * `Facade` class) rather than `Log::spy()`/`shouldHaveReceived()` — the
 * same PHPStan/Larastan-vs-Pest-closure-`$this`-typing workaround this
 * project's testing conventions already established (see
 * PaymentConfirmationControllerTest and its own Session-9 finding).
 */
test('an orphaned mandate past the grace period is flagged and logged', function () {
    $graceMinutes = config('booking.reconciliation_grace_minutes');

    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);
    $payment = BookingFixture::depositPaymentFor($tenant, $appointment, [], [
        'created_at' => now()->subMinutes($graceMinutes + 1),
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context) use ($tenant, $appointment, $payment, $graceMinutes) {
            return $message === 'payment_mandates backfill incomplete past reconciliation grace period'
                && $context['tenant_id'] === $tenant->id
                && $context['appointment_id'] === $appointment->id
                && $context['stripe_payment_intent_id'] === $payment->stripe_payment_intent_id
                && $context['appointment_status'] === 'pending_payment'
                && $context['deposit_payment_status'] === $payment->status
                && $context['orphaned_for_minutes'] >= $graceMinutes + 1;
        });

    artisan('mandates:reconcile-backfill')->assertExitCode(1);
});

test('a mandate still within the grace period is not flagged', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);
    BookingFixture::depositPaymentFor($tenant, $appointment, [], [
        'created_at' => now()->subMinutes(5),
    ]);

    Log::shouldReceive('warning')->never();

    artisan('mandates:reconcile-backfill')->assertExitCode(0);
});

test('a mandate that was already backfilled is never flagged regardless of age', function () {
    $graceMinutes = config('booking.reconciliation_grace_minutes');

    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);
    BookingFixture::depositPaymentFor($tenant, $appointment, ['status' => 'succeeded'], [
        'created_at' => now()->subMinutes($graceMinutes * 5),
        'stripe_payment_method_id' => 'pm_already_backfilled',
    ]);

    Log::shouldReceive('warning')->never();

    artisan('mandates:reconcile-backfill')->assertExitCode(0);
});

test('orphaned mandates are found across every tenant, not just the first', function () {
    $graceMinutes = config('booking.reconciliation_grace_minutes');

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $appointmentA = BookingFixture::appointmentFor($tenantA, ['status' => 'pending_payment']);
    $appointmentB = BookingFixture::appointmentFor($tenantB, ['status' => 'pending_payment']);

    BookingFixture::depositPaymentFor($tenantA, $appointmentA, [], [
        'created_at' => now()->subMinutes($graceMinutes + 10),
    ]);
    BookingFixture::depositPaymentFor($tenantB, $appointmentB, [], [
        'created_at' => now()->subMinutes($graceMinutes + 10),
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $context['appointment_id'] === $appointmentA->id);
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $context['appointment_id'] === $appointmentB->id);

    artisan('mandates:reconcile-backfill')->assertExitCode(1);
});
