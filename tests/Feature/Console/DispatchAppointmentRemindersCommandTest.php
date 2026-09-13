<?php

use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BookingFixture;

use function Pest\Laravel\artisan;

/**
 * FR-06 (02-requirements.md), D-0050 (09-decision-log.md). QUEUE_CONNECTION
 * is 'sync' under APP_ENV=testing, so SendAppointmentReminderJob dispatched
 * by this command runs inline within the same artisan() call — these tests
 * assert on final notification_deliveries state directly.
 */
test('a confirmed appointment inside the 24h reminder window gets exactly one reminder scheduled and sent', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, [
        'status' => 'confirmed',
        'appointment_range' => sprintf(
            '[%s,%s)',
            now()->addHours(24)->addSeconds(30)->toIso8601String(),
            now()->addHours(25)->toIso8601String(),
        ),
    ]);

    artisan('reminders:dispatch')->assertExitCode(0);

    TenantContext::run($tenant->id, function () use ($appointment) {
        $delivery = NotificationDelivery::query()
            ->where('appointment_id', $appointment->id)
            ->where('purpose', 'reminder_24h')
            ->firstOrFail();

        expect($delivery->status)->toBe('sent');
        expect(NotificationDelivery::query()->where('appointment_id', $appointment->id)->count())->toBe(1);
    });
});

test('an appointment outside every reminder window gets nothing scheduled', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, [
        'status' => 'confirmed',
        'appointment_range' => sprintf('[%s,%s)', now()->addHours(10)->toIso8601String(), now()->addHours(11)->toIso8601String()),
    ]);

    artisan('reminders:dispatch')->assertExitCode(0);

    TenantContext::run($tenant->id, function () use ($appointment) {
        expect(NotificationDelivery::query()->where('appointment_id', $appointment->id)->count())->toBe(0);
    });

    Mail::assertNothingSent();
});

test('running the command twice never schedules the same reminder twice — real idempotency, not a race that happens not to occur', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, [
        'status' => 'confirmed',
        'appointment_range' => sprintf(
            '[%s,%s)',
            now()->addHours(2)->addSeconds(30)->toIso8601String(),
            now()->addHours(3)->toIso8601String(),
        ),
    ]);

    artisan('reminders:dispatch')->assertExitCode(0);
    artisan('reminders:dispatch')->assertExitCode(0);

    TenantContext::run($tenant->id, function () use ($appointment) {
        expect(NotificationDelivery::query()
            ->where('appointment_id', $appointment->id)
            ->where('purpose', 'reminder_2h')
            ->count())->toBe(1);
    });
});

test('a pending_payment (not yet confirmed) appointment never gets a reminder', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, [
        'status' => 'pending_payment',
        'appointment_range' => sprintf(
            '[%s,%s)',
            now()->addHours(24)->addSeconds(30)->toIso8601String(),
            now()->addHours(25)->toIso8601String(),
        ),
    ]);

    artisan('reminders:dispatch')->assertExitCode(0);

    TenantContext::run($tenant->id, function () use ($appointment) {
        expect(NotificationDelivery::query()->where('appointment_id', $appointment->id)->count())->toBe(0);
    });
});

test('reminders are scheduled per tenant, never leaking into another tenant\'s appointment window', function () {
    Mail::fake();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $range = sprintf(
        '[%s,%s)',
        now()->addHours(24)->addSeconds(30)->toIso8601String(),
        now()->addHours(25)->toIso8601String(),
    );

    $appointmentA = BookingFixture::appointmentFor($tenantA, ['status' => 'confirmed', 'appointment_range' => $range]);
    $appointmentB = BookingFixture::appointmentFor($tenantB, ['status' => 'confirmed', 'appointment_range' => $range]);

    artisan('reminders:dispatch')->assertExitCode(0);

    TenantContext::run($tenantA->id, function () use ($appointmentA) {
        expect(NotificationDelivery::query()->where('appointment_id', $appointmentA->id)->count())->toBe(1);
    });

    TenantContext::run($tenantB->id, function () use ($appointmentB) {
        expect(NotificationDelivery::query()->where('appointment_id', $appointmentB->id)->count())->toBe(1);
    });
});
