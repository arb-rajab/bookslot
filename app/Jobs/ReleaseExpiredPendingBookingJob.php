<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\BookingEvent;

/**
 * FR-05 (02-requirements.md), D-0011's hold-window mechanism, finally
 * implemented (D-0049, 09-decision-log.md) — until this job existed, a
 * `pending_payment` appointment that never completed payment held its slot
 * forever (01-scope-and-non-goals.md's "Not built" list). BookingController
 * dispatches exactly one of these, delayed by
 * config('booking.hold_window_minutes'), immediately after every booking
 * it creates.
 *
 * Deliberately idempotent and safe to run late or more than once: it only
 * ever transitions an appointment that is STILL `pending_payment` at the
 * moment it runs. If the customer completed payment in the meantime
 * (PaymentConfirmationController or the Stripe webhook already moved the
 * appointment to `confirmed`), or another process already cancelled it,
 * this job is a no-op — never a double-cancel, never undoing a real
 * confirmed booking.
 */
class ReleaseExpiredPendingBookingJob extends TenantScopedJob
{
    public int $tries = 5;

    public function __construct(string $tenantId, private readonly string $appointmentId)
    {
        parent::__construct($tenantId);
    }

    public function handle(): void
    {
        $appointment = Appointment::query()->find($this->appointmentId);

        if ($appointment === null || $appointment->status !== 'pending_payment') {
            return;
        }

        $appointment->status = 'cancelled';
        $appointment->cancelled_by = 'system';
        $appointment->cancelled_reason = 'hold_window_expired';
        $appointment->cancelled_at = now();
        $appointment->save();

        BookingEvent::query()->create([
            'appointment_id' => $appointment->id,
            'actor_type' => 'system',
            'actor_id' => null,
            'event_type' => 'status_changed',
            'from_status' => 'pending_payment',
            'to_status' => 'cancelled',
            'metadata' => ['reason' => 'hold_window_expired'],
        ]);
    }
}
