<?php

namespace App\Console\Commands;

use App\Jobs\SendAppointmentReminderJob;
use App\Models\Appointment;
use App\Models\NotificationDelivery;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * FR-06 (02-requirements.md), D-0050 (09-decision-log.md): the scheduled
 * scan that turns config('booking.reminder_offsets_minutes') into real,
 * once-only reminder sends. Iterates tenants explicitly and re-enters
 * TenantContext::run() per tenant, the same pattern
 * ReconcilePaymentMandatesCommand (D-0037) already established for
 * cross-tenant scheduled work under the ordinary, RLS-subject
 * `bookslot_app` role.
 *
 * Idempotent by construction, not by convention: firstOrCreate on
 * (tenant_id, appointment_id, purpose) — the same three columns
 * notification_deliveries_tenant_appt_purpose_idx already indexes — means a
 * run that overlaps a previous one (this command taking longer than its own
 * schedule interval) can never schedule the same reminder twice, and a
 * SendAppointmentReminderJob dispatch only happens on the row this call
 * itself just created (an already-existing row, scheduled or otherwise, is
 * left alone here — it either already has a job in flight or was already
 * resolved).
 */
class DispatchAppointmentRemindersCommand extends Command
{
    protected $signature = 'reminders:dispatch';

    protected $description = 'Schedule and dispatch due appointment reminders (FR-06, D-0050) for every tenant';

    public function handle(): int
    {
        $windowMinutes = (int) config('booking.reminder_dispatch_window_minutes');
        $now = Carbon::now();
        $scheduled = 0;

        foreach (Tenant::query()->cursor() as $tenant) {
            $scheduled += TenantContext::run($tenant->id, function () use ($tenant, $now, $windowMinutes): int {
                $count = 0;

                foreach (config('booking.reminder_offsets_minutes') as $purpose => $offsetMinutes) {
                    $windowStart = $now->clone()->addMinutes($offsetMinutes);
                    $windowEnd = $windowStart->clone()->addMinutes($windowMinutes);

                    $appointments = Appointment::query()
                        ->where('status', 'confirmed')
                        ->whereBetween('starts_at', [$windowStart, $windowEnd])
                        ->get();

                    foreach ($appointments as $appointment) {
                        [$delivery, $created] = $this->firstOrCreateDelivery($tenant->id, $appointment, $purpose, $appointment->starts_at->clone()->subMinutes($offsetMinutes));

                        if ($created) {
                            SendAppointmentReminderJob::dispatch($tenant->id, $delivery->id);
                            $count++;
                        }
                    }
                }

                return $count;
            });
        }

        $this->info("Reminder dispatch complete: {$scheduled} reminder(s) newly scheduled.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: NotificationDelivery, 1: bool}
     *
     * Real idempotency, not just a check-then-create race: the actual
     * guarantee is the unique index this table gained specifically for
     * this command (see the migration adding
     * notification_deliveries_tenant_appointment_purpose_unique). Two
     * overlapping runs can both pass the initial `where()->first()` null
     * check; only one of their inserts wins, and the loser catches Postgres
     * 23505 and re-fetches the winner's row instead of ever dispatching a
     * second SendAppointmentReminderJob for the same (appointment, purpose).
     */
    private function firstOrCreateDelivery(string $tenantId, Appointment $appointment, string $purpose, Carbon $scheduledFor): array
    {
        $existing = NotificationDelivery::query()
            ->where('appointment_id', $appointment->id)
            ->where('purpose', $purpose)
            ->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $delivery = NotificationDelivery::query()->create([
                'tenant_id' => $tenantId,
                'appointment_id' => $appointment->id,
                'purpose' => $purpose,
                'channel' => 'email',
                'scheduled_for' => $scheduledFor,
                'status' => 'scheduled',
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23505') {
                throw $e;
            }

            $delivery = NotificationDelivery::query()
                ->where('appointment_id', $appointment->id)
                ->where('purpose', $purpose)
                ->firstOrFail();

            return [$delivery, false];
        }

        return [$delivery, true];
    }
}
