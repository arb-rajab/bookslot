<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStripeWebhookJob;
use App\Jobs\ResolveStripeDisputeTenantJob;
use App\Models\StripeWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * POST /api/webhooks/stripe. D-0048 (docs/project-memory/09-decision-log.md)
 * finally builds J9 (02-requirements.md) — until this session, the
 * `stripe_webhook_events` table and `STRIPE_WEBHOOK_SECRET` config existed
 * with no route, no controller, and no signature verification anywhere in
 * `app/` (a real, named gap, not an oversight left undocumented).
 *
 * Deliberately outside every tenant-resolution middleware
 * (resolve.tenant.slug/token, tenant.context, auth.tenant) — a webhook
 * carries none of those; tenant context is recovered from the verified
 * event payload itself, inside ProcessStripeWebhookJob (see that job's own
 * docblock for the trust argument). This controller's only two jobs are:
 * verify the signature (J9: "before any of the above"), and record +
 * dedup the event fast enough to ack Stripe immediately — never do the
 * actual state-changing work synchronously, so a slow or failing handler
 * can never turn into a webhook timeout/retry storm.
 *
 * D-0036 still holds: this endpoint has never received a real Stripe
 * webhook delivery and, per that permanent scope decision, never will
 * within this project's lifecycle — every test exercising it signs its own
 * fixture payload locally with the configured STRIPE_WEBHOOK_SECRET
 * (tests/Support/StripeWebhookSignature), the same "mock signature, not a
 * live call" tier D-0036/07-testing-strategy.md already established for
 * PaymentIntentGateway.
 */
class StripeWebhookController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signatureHeader = $request->header('Stripe-Signature', '');

        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, config('services.stripe.webhook_secret'));
        } catch (UnexpectedValueException|SignatureVerificationException) {
            return response()->json(['error' => 'INVALID_SIGNATURE'], 400);
        }

        $eventArray = $event->toArray();
        $tenantId = $eventArray['data']['object']['metadata']['tenant_id'] ?? null;

        $webhookEvent = StripeWebhookEvent::query()->firstOrCreate(
            ['stripe_event_id' => $event->id],
            ['type' => $event->type, 'payload' => $eventArray, 'received_at' => now()],
        );

        // J9: duplicate delivery of an event this codebase already fully
        // applied — acknowledge without dispatching anything.
        if ($webhookEvent->processed_at !== null) {
            return response()->json(['status' => 'already_processed']);
        }

        if (is_string($tenantId) && $tenantId !== '') {
            ProcessStripeWebhookJob::dispatch($tenantId, $event->id);

            return response()->json(['status' => 'accepted']);
        }

        // D-0048: charge.dispute.created/closed's Dispute object never
        // carries metadata.tenant_id (Stripe does not copy a charge's
        // metadata onto disputes raised against it) — resolve the tenant
        // asynchronously instead of giving up, per
        // ResolveStripeDisputeTenantJob's own docblock. Recorded here, not
        // resolved synchronously: fan-out DB lookups across tenants have no
        // place in a fast-ack webhook request (J9).
        if (in_array($event->type, ['charge.dispute.created', 'charge.dispute.closed'], true)) {
            ResolveStripeDisputeTenantJob::dispatch($event->id);

            return response()->json(['status' => 'accepted']);
        }

        // Recorded for audit/ops visibility (this table is deliberately
        // tenant-less — see StripeWebhookEvent's docblock), but no
        // tenant-scoped job can be dispatched without a tenant context to
        // run it in, and no resolution path exists for this event type.
        Log::warning('Stripe webhook event recorded with no resolvable tenant_id — not dispatched for processing', [
            'stripe_event_id' => $event->id,
            'type' => $event->type,
        ]);

        return response()->json(['status' => 'recorded_unresolved_tenant']);
    }
}
