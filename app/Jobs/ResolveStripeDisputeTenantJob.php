<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * D-0048's own named gap, closed: `charge.dispute.created`/`charge.dispute
 * .closed`'s `data.object` is a Dispute — a Stripe object distinct from the
 * PaymentIntent this codebase writes `metadata.tenant_id` onto at
 * booking-creation time (BookingController). Stripe does not copy a
 * charge's metadata onto disputes raised against it, so
 * StripeWebhookController can never read `metadata.tenant_id` for this
 * event type the way it does for `payment_intent.*` events.
 *
 * A PlatformJob, not a TenantScopedJob — no tenant is known yet, that's
 * exactly the problem this job exists to solve. Resolution reuses the one
 * field a Dispute payload reliably carries that ties back to this
 * codebase's own records: `payment_intent`, the same
 * `payments.stripe_payment_intent_id` column ProcessStripeWebhookJob's own
 * payment_intent.succeeded/payment_intent.payment_failed handlers already
 * key their Payment lookups on (a globally unique column —
 * `payments_stripe_payment_intent_id_unique`) — not a new lookup mechanism
 * invented for this one event type. (Dispute also carries a `charge` id,
 * but nothing in this codebase writes `payments.stripe_charge_id` today —
 * a separate, pre-existing gap, real but out of this job's scope to fix —
 * so `charge` is recorded for audit context only, never queried.)
 *
 * Which tenant owns that PaymentIntent is unknown until the matching
 * Payment row is found, so — the same fail-closed, no-RLS-bypass shape
 * ReconcilePaymentMandatesCommand (D-0037) already established for a
 * different cross-tenant read — this job iterates every Tenant (itself not
 * RLS-scoped, 04-data-model.md's tenancy boundary section) and re-enters
 * TenantContext::run() per tenant to query that tenant's own payments
 * under the ordinary, RLS-subject `bookslot_app` role. Never a BYPASSRLS
 * credential, never a cross-tenant raw query.
 *
 * If no tenant's payments match (a genuinely orphaned/edge-case charge —
 * e.g. a dispute for a charge this codebase never created), the event is
 * left unprocessed with a warning logged, the same "recorded but not
 * dispatched further" outcome StripeWebhookController already gives any
 * other event type it can't resolve a tenant for.
 */
class ResolveStripeDisputeTenantJob extends PlatformJob
{
    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public function __construct(private readonly string $stripeEventId) {}

    public function handle(): void
    {
        $event = StripeWebhookEvent::query()->where('stripe_event_id', $this->stripeEventId)->first();

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $object = $event->payload['data']['object'] ?? [];
        $paymentIntentId = is_string($object['payment_intent'] ?? null) ? $object['payment_intent'] : null;

        if ($paymentIntentId === null) {
            Log::warning('Dispute webhook event carries no payment_intent id — tenant cannot be resolved', [
                'stripe_event_id' => $this->stripeEventId,
                'type' => $event->type,
                'stripe_charge_id' => is_string($object['charge'] ?? null) ? $object['charge'] : null,
            ]);

            return;
        }

        $resolvedTenantId = null;

        foreach (Tenant::query()->cursor() as $tenant) {
            $matches = TenantContext::run(
                $tenant->id,
                fn () => Payment::query()->where('stripe_payment_intent_id', $paymentIntentId)->exists(),
            );

            if ($matches) {
                $resolvedTenantId = $tenant->id;

                break;
            }
        }

        if ($resolvedTenantId === null) {
            Log::warning('Dispute webhook event\'s payment_intent matches no known payment — orphaned/edge-case charge, tenant unresolved', [
                'stripe_event_id' => $this->stripeEventId,
                'type' => $event->type,
                'stripe_payment_intent_id' => $paymentIntentId,
            ]);

            return;
        }

        ProcessStripeWebhookJob::dispatch($resolvedTenantId, $this->stripeEventId);
    }
}
