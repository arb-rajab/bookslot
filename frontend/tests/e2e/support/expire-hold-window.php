<?php

/**
 * Test-only harness script, never shipped as application code and never
 * wired to any HTTP route — invoked exclusively by
 * owner-appointment-actions.spec.ts (via `php artisan tinker`, from
 * support/booking.ts's expireHoldWindow()) to simulate FR-05's real
 * hold-window expiry (ReleaseExpiredPendingBookingJob, D-0049) without an
 * E2E test actually waiting out `config('booking.hold_window_minutes')`
 * (15 real minutes by default) or a real RabbitMQ delayed-message wait.
 *
 * This is the exact same technique
 * tests/Feature/Jobs/ReleaseExpiredPendingBookingJobTest.php already uses
 * at the Pest level (backdate `created_at`, then dispatchSync) — replayed
 * here against the real dev database the Playwright-driven `php artisan
 * serve` process is actually reading, so the E2E suite can render and
 * assert the *result* in a real browser without re-implementing or
 * shortcutting the job's own logic. It calls the real, already-tested
 * ReleaseExpiredPendingBookingJob::handle() — it does not duplicate what
 * that job does, and it changes no application code path.
 *
 * J4 boundary, explicit: this script exists only to fast-forward wall
 * time for a job that already exists and already runs unattended in
 * production (dispatched by BookingController at real booking-creation
 * time, D-0049) — it is not a new no-show-detection mechanism, and it
 * never touches the manual `no_show` status this repo's owner dashboard
 * exposes.
 *
 * Reads the target appointment id from E2E_APPOINTMENT_ID (set by the
 * calling Node test) rather than argv, since `php artisan tinker <file>`
 * does not forward extra CLI arguments into the executed script.
 */

use App\Jobs\ReleaseExpiredPendingBookingJob;
use App\Models\Appointment;
use App\Models\Tenant;
use App\Tenancy\TenantContext;

$appointmentId = getenv('E2E_APPOINTMENT_ID');

if (! $appointmentId) {
    throw new RuntimeException('E2E_APPOINTMENT_ID must be set before running this script.');
}

$tenant = Tenant::query()->where('slug', 'demo-studio')->firstOrFail();

TenantContext::run($tenant->id, function () use ($appointmentId) {
    $appointment = Appointment::query()->findOrFail($appointmentId);
    $appointment->created_at = now()->subMinutes((int) config('booking.hold_window_minutes') + 5);
    $appointment->save();
});

ReleaseExpiredPendingBookingJob::dispatchSync($tenant->id, $appointmentId);

echo "expire-hold-window: done\n";
