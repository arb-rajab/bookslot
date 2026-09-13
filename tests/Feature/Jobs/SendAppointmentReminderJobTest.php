<?php

use App\Jobs\SendAppointmentReminderJob;
use App\Mail\AppointmentReminderMail;
use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BookingFixture;

/**
 * FR-06 (02-requirements.md), D-0050 (09-decision-log.md). Mail::fake()
 * intercepts before MAIL_MAILER ever matters (.env.testing's is 'array') —
 * this suite proves this job's own send/record/idempotency/failure logic,
 * not mail transport, which this project has never obtained a real
 * provider for (same accepted-limitation shape as D-0036 — see this job's
 * own docblock).
 */
test('a scheduled reminder is sent and marked sent, addressed to the customer on file', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);

    $delivery = TenantContext::run($tenant->id, fn () => NotificationDelivery::factory()->create([
        'tenant_id' => $tenant->id,
        'appointment_id' => $appointment->id,
        'purpose' => 'reminder_24h',
    ]));

    $customerEmail = TenantContext::run($tenant->id, fn () => $appointment->customer()->firstOrFail()->email);

    SendAppointmentReminderJob::dispatchSync($tenant->id, $delivery->id);

    Mail::assertSent(AppointmentReminderMail::class, fn ($mail) => $mail->hasTo($customerEmail) && $mail->delivery->is($delivery));

    TenantContext::run($tenant->id, function () use ($delivery) {
        $reloaded = NotificationDelivery::query()->findOrFail($delivery->id);
        expect($reloaded->status)->toBe('sent');
        expect($reloaded->sent_at)->not->toBeNull();
    });
});

test('a delivery that is not (or no longer) scheduled is left untouched — safe under redelivery/retry', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);

    $delivery = TenantContext::run($tenant->id, fn () => NotificationDelivery::factory()->create([
        'tenant_id' => $tenant->id,
        'appointment_id' => $appointment->id,
        'status' => 'sent',
        'sent_at' => now()->subHour(),
    ]));

    SendAppointmentReminderJob::dispatchSync($tenant->id, $delivery->id);

    Mail::assertNothingSent();

    TenantContext::run($tenant->id, function () use ($delivery) {
        $reloaded = NotificationDelivery::query()->findOrFail($delivery->id);
        expect($reloaded->status)->toBe('sent');
        expect($reloaded->sent_at)->toEqual($delivery->sent_at);
    });
});

test('a customer with no email on file is marked failed rather than throwing', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);

    TenantContext::run($tenant->id, function () use ($appointment) {
        $appointment->customer()->first()->update(['email' => null]);
    });

    $delivery = TenantContext::run($tenant->id, fn () => NotificationDelivery::factory()->create([
        'tenant_id' => $tenant->id,
        'appointment_id' => $appointment->id,
    ]));

    SendAppointmentReminderJob::dispatchSync($tenant->id, $delivery->id);

    Mail::assertNothingSent();

    TenantContext::run($tenant->id, function () use ($delivery) {
        expect(NotificationDelivery::query()->findOrFail($delivery->id)->status)->toBe('failed');
    });
});

test('failed() marks the delivery failed after retries are exhausted — the queue-driven failure path', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);

    $delivery = TenantContext::run($tenant->id, fn () => NotificationDelivery::factory()->create([
        'tenant_id' => $tenant->id,
        'appointment_id' => $appointment->id,
    ]));

    $job = new SendAppointmentReminderJob($tenant->id, $delivery->id);
    $job->failed(new RuntimeException('simulated mail transport failure'));

    TenantContext::run($tenant->id, function () use ($delivery) {
        expect(NotificationDelivery::query()->findOrFail($delivery->id)->status)->toBe('failed');
    });
});

test('a job for one tenant cannot send or mutate another tenant\'s notification_deliveries row', function () {
    Mail::fake();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $appointmentB = BookingFixture::appointmentFor($tenantB, ['status' => 'confirmed']);

    $deliveryB = TenantContext::run($tenantB->id, fn () => NotificationDelivery::factory()->create([
        'tenant_id' => $tenantB->id,
        'appointment_id' => $appointmentB->id,
    ]));

    SendAppointmentReminderJob::dispatchSync($tenantA->id, $deliveryB->id);

    Mail::assertNothingSent();

    TenantContext::run($tenantB->id, function () use ($deliveryB) {
        expect(NotificationDelivery::query()->findOrFail($deliveryB->id)->status)->toBe('scheduled');
    });
});
