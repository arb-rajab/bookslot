<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Payment;
use App\Models\PaymentMandate;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * R-07's mitigation (10-risk-register.md), built and tested D-0037
 * (09-decision-log.md) — the reconciliation check named as "recommended,
 * not built" since D-0032 first raised the risk: a `payment_mandates` row
 * (D-0031's nullable-with-backfill design) whose `stripe_payment_method_id`
 * write-back never happened is otherwise silent. This is not a Stripe call
 * (D-0036 permanently descopes real Stripe access for this project) — it
 * only checks how long our own row has sat incomplete, which is exactly the
 * signal D-0032's original mitigation text described.
 *
 * Iterates tenants explicitly and re-enters TenantContext::run() per
 * tenant (D-0009) rather than querying across tenants directly — this
 * command runs under the ordinary, RLS-subject `bookslot_app` role (D-0020
 * keeps the BYPASSRLS-capable migrator credential out of general runtime
 * use), so a cross-tenant report is built the same fail-closed way every
 * other cross-tenant read in this codebase is: one tenant context at a
 * time, never a bypass.
 *
 * `Tenant` itself carries no RLS/TenantScope (04-data-model.md's tenancy
 * boundary section — it IS the boundary), so listing every tenant to
 * iterate over is safe without any context set.
 */
class ReconcilePaymentMandatesCommand extends Command
{
    protected $signature = 'mandates:reconcile-backfill';

    protected $description = 'Flag payment_mandates rows whose stripe_payment_method_id backfill (D-0031) never completed past the reconciliation grace period (R-07, D-0037)';

    public function handle(): int
    {
        $graceMinutes = (int) config('booking.reconciliation_grace_minutes');
        $cutoff = Carbon::now()->subMinutes($graceMinutes);

        $flagged = 0;

        foreach (Tenant::query()->cursor() as $tenant) {
            $flagged += TenantContext::run($tenant->id, function () use ($tenant, $cutoff): int {
                $orphaned = PaymentMandate::query()
                    ->whereNull('stripe_payment_method_id')
                    ->where('created_at', '<=', $cutoff)
                    ->get();

                foreach ($orphaned as $mandate) {
                    $appointment = Appointment::query()->find($mandate->appointment_id);

                    $payment = Payment::query()
                        ->where('appointment_id', $mandate->appointment_id)
                        ->where('type', 'deposit')
                        ->first();

                    Log::warning('payment_mandates backfill incomplete past reconciliation grace period', [
                        'tenant_id' => $tenant->id,
                        'appointment_id' => $mandate->appointment_id,
                        'payment_mandate_id' => $mandate->id,
                        'stripe_payment_intent_id' => $mandate->stripe_payment_intent_id,
                        'mandate_created_at' => $mandate->created_at->toIso8601String(),
                        'orphaned_for_minutes' => $mandate->created_at->diffInMinutes(now()),
                        'appointment_status' => $appointment?->status,
                        'deposit_payment_status' => $payment?->status,
                    ]);
                }

                return $orphaned->count();
            });
        }

        $this->info("Reconciliation complete: {$flagged} payment_mandates row(s) flagged past the {$graceMinutes}-minute grace period.");

        return $flagged > 0 ? self::FAILURE : self::SUCCESS;
    }
}
