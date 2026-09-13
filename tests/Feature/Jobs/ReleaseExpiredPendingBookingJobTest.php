<?php

use App\Jobs\ReleaseExpiredPendingBookingJob;
use App\Models\BookingEvent;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Tests\Support\BookingFixture;

/**
 * FR-05 (02-requirements.md), D-0049 (09-decision-log.md, amending D-0011).
 * BookingController itself dispatches this job with a real delay — these
 * tests call it directly (dispatchSync, the same pattern
 * QueueJobTenantContextTest already established) to prove handle()'s own
 * status-transition logic in isolation from RabbitMqQueue's actual delay
 * mechanism, which RabbitMqQueueIntegrationTest (queue-broker group)
 * proves separately, against a real broker.
 */
test('a still-pending appointment past its hold window is released back to availability', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'pending_payment']);

    ReleaseExpiredPendingBookingJob::dispatchSync($tenant->id, $appointment->id);

    BookingFixture::assertStatus($tenant, $appointment->id, 'cancelled');

    TenantContext::run($tenant->id, function () use ($appointment) {
        $reloaded = $appointment->fresh();
        expect($reloaded->cancelled_by)->toBe('system');
        expect($reloaded->cancelled_reason)->toBe('hold_window_expired');
        expect($reloaded->cancelled_at)->not->toBeNull();

        $event = BookingEvent::query()->where('appointment_id', $appointment->id)->firstOrFail();
        expect($event->actor_type)->toBe('system');
        expect($event->from_status)->toBe('pending_payment');
        expect($event->to_status)->toBe('cancelled');
    });
});

test('an appointment already confirmed before the job runs is left untouched — never undoes a real booking', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, ['status' => 'confirmed']);

    ReleaseExpiredPendingBookingJob::dispatchSync($tenant->id, $appointment->id);

    BookingFixture::assertStatus($tenant, $appointment->id, 'confirmed');

    TenantContext::run($tenant->id, function () use ($appointment) {
        expect(BookingEvent::query()->where('appointment_id', $appointment->id)->count())->toBe(0);
    });
});

test('an appointment already cancelled before the job runs is left untouched — idempotent under a late or duplicate run', function () {
    $tenant = Tenant::factory()->create();
    $appointment = BookingFixture::appointmentFor($tenant, [
        'status' => 'cancelled',
        'cancelled_by' => 'customer',
        'cancelled_reason' => 'customer_requested',
        'cancelled_at' => now(),
    ]);

    ReleaseExpiredPendingBookingJob::dispatchSync($tenant->id, $appointment->id);

    TenantContext::run($tenant->id, function () use ($appointment) {
        $reloaded = $appointment->fresh();
        expect($reloaded->cancelled_by)->toBe('customer');
        expect($reloaded->cancelled_reason)->toBe('customer_requested');
        expect(BookingEvent::query()->where('appointment_id', $appointment->id)->count())->toBe(0);
    });
});

test('a job dispatched for one tenant cannot release another tenant\'s appointment', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $appointmentB = BookingFixture::appointmentFor($tenantB, ['status' => 'pending_payment']);

    // Constructed with tenant A's id but tenant B's appointment id — RLS
    // (not application logic) is what makes this a safe no-op: tenant A's
    // context can never see tenant B's row to begin with.
    ReleaseExpiredPendingBookingJob::dispatchSync($tenantA->id, $appointmentB->id);

    BookingFixture::assertStatus($tenantB, $appointmentB->id, 'pending_payment');
});
