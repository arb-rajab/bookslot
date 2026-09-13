<?php

namespace App\Jobs;

use App\Mail\AppointmentReminderMail;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * FR-06 (02-requirements.md), D-0050 (09-decision-log.md): sends the
 * reminder a notification_deliveries row (created by
 * DispatchAppointmentRemindersCommand) describes, then records the outcome
 * back onto that same row.
 *
 * D-0050's stated limitation, the same shape as D-0036's for Stripe: MAIL_MAILER
 * defaults to 'log' (.env.example) — no real email/SMS provider has ever
 * been obtained for this project, so every reminder this job "sends" is, in
 * every environment this project has today, written to a log line, not a
 * real inbox. The channel abstraction (config/services.php's postmark/
 * resend/ses entries already exist) and this job's own logic are real and
 * provider-agnostic; only a real provider credential is missing, by the
 * same accepted-limitation reasoning D-0036 recorded for Stripe. R-05
 * (10-risk-register.md, deliverability) and R-04 (cadence effectiveness)
 * both stay open — this job's existence answers neither.
 *
 * Idempotent under redelivery/retry: only ever acts on a `scheduled` row: it
 * is not enough for the appointment to still exist, since a retried
 * delivery of an already-sent message must not send a second real email
 * once a real provider exists — checking notification_deliveries.status,
 * not just catching an exception, is what makes this safe under Laravel's
 * own at-least-once queue delivery guarantee.
 */
class SendAppointmentReminderJob extends TenantScopedJob
{
    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(string $tenantId, private readonly string $notificationDeliveryId)
    {
        parent::__construct($tenantId);
    }

    public function handle(): void
    {
        $delivery = NotificationDelivery::query()
            ->with(['appointment.customer', 'appointment.service', 'tenant'])
            ->find($this->notificationDeliveryId);

        if ($delivery === null || $delivery->status !== 'scheduled') {
            return;
        }

        $appointment = $delivery->appointment;
        $customer = $appointment?->customer;

        if ($appointment === null || $customer === null || $customer->email === null) {
            $delivery->status = 'failed';
            $delivery->save();

            Log::warning('Appointment reminder skipped: appointment or customer email missing', [
                'notification_delivery_id' => $delivery->id,
                'appointment_id' => $delivery->appointment_id,
            ]);

            return;
        }

        Mail::to($customer->email, $customer->name)->send(new AppointmentReminderMail($delivery));

        $delivery->status = 'sent';
        $delivery->sent_at = now();
        $delivery->save();
    }

    /**
     * Laravel calls this after $tries is exhausted (or a middleware/
     * exception marks the job permanently failed) — the one place this job
     * itself ever writes 'failed' for a delivery attempt that threw, as
     * opposed to handle()'s own early-exit 'failed' for a delivery that
     * was never sendable in the first place.
     */
    public function failed(?Throwable $exception): void
    {
        NotificationDelivery::query()
            ->where('id', $this->notificationDeliveryId)
            ->update(['status' => 'failed']);

        Log::error('Appointment reminder permanently failed', [
            'notification_delivery_id' => $this->notificationDeliveryId,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
