<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ReleaseExpiredPendingBookingJob;
use App\Mandates\MandateRenderer;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentMandate;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Payments\PaymentIntentGateway;
use App\Tenancy\SignedTenantToken;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * POST /api/tenants/{slug}/bookings (05-api-contracts.md endpoint 2,
 * D-0030). Creates a pending_payment appointment (respecting D-0007/
 * D-0008's exclusion constraint and D-0011's hold window), renders the
 * mandate via the shared MandateRenderer (never client-supplied text —
 * the client sends only mandate_accepted/mandate_template_version,
 * D-0015(b)), then creates the deposit PaymentIntent (D-0006).
 *
 * D-0027's transaction boundary, built for real here — this is the first
 * controller in the repository that touches Stripe:
 *   1. TX1 (TenantContext::run): insert the pending_payment appointment,
 *      commit.
 *   2. The Stripe call, OUTSIDE any open transaction.
 *   3. On success — TX2 (TenantContext::run): insert the payments row and
 *      the payment_mandates row (D-0031: stripe_payment_method_id stays
 *      NULL here, genuinely not known until a payment method is actually
 *      attached later), commit.
 *   4. On Stripe failure — a short cleanup TenantContext::run() releases
 *      the hold immediately (appointment -> cancelled) rather than
 *      leaving a zombie hold for the expiry job to eventually clear, and
 *      the request fails with a distinct, machine-readable error.
 *   5. On success — D-0049 (09-decision-log.md), FR-05: dispatches exactly
 *      one ReleaseExpiredPendingBookingJob, delayed by
 *      config('booking.hold_window_minutes'), as the real backstop for the
 *      hold window this endpoint's own response already advertises via
 *      payment_confirmation_token's expiry. A customer who never completes
 *      payment has their slot released by this job, not left held forever.
 *
 * Deliberately NOT behind the `tenant.context` middleware (see
 * routes/api.php) — that middleware wraps the whole request in one
 * transaction, which would hold a database connection and the
 * appointments exclusion index's locks across the Stripe call for its
 * full duration, exactly what D-0027 exists to avoid.
 */
class BookingController extends Controller
{
    public function __construct(
        private readonly MandateRenderer $mandateRenderer,
        private readonly PaymentIntentGateway $paymentIntentGateway,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $validated = $request->validate([
            'service_id' => ['required', 'uuid'],
            'staff_id' => ['required', 'uuid'],
            'starts_at' => ['required', 'date'],
            'customer.name' => ['required', 'string'],
            'customer.email' => ['required', 'email'],
            'customer.phone' => ['nullable', 'string'],
            'mandate_accepted' => ['required', 'accepted'],
            'mandate_template_version' => ['required', 'string'],
        ]);

        try {
            $created = TenantContext::run($tenantId, function () use ($validated) {
                $service = Service::query()->findOrFail($validated['service_id']);
                $staff = Staff::query()->findOrFail($validated['staff_id']);

                $customer = Customer::query()->firstOrCreate(
                    ['email' => $validated['customer']['email']],
                    ['name' => $validated['customer']['name'], 'phone' => $validated['customer']['phone'] ?? null],
                );

                $startsAt = CarbonImmutable::parse($validated['starts_at']);
                $endsAt = $startsAt->addMinutes($service->duration_minutes);

                $appointment = Appointment::query()->create([
                    'staff_id' => $staff->id,
                    'service_id' => $service->id,
                    'customer_id' => $customer->id,
                    'appointment_range' => sprintf('[%s,%s)', $startsAt->toIso8601String(), $endsAt->toIso8601String()),
                    'buffer_before_minutes' => $service->buffer_before_minutes,
                    'buffer_after_minutes' => $service->buffer_after_minutes,
                ]);

                return ['appointment' => $appointment, 'service' => $service];
            });
        } catch (ModelNotFoundException) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        } catch (QueryException $e) {
            // 23P01: the exclusion constraint (D-0007/D-0008) rejected the
            // insert outright — the ordinary "lost the race" case.
            // 40P01: found by executing the real two-process concurrency
            // test (BookingConcurrencyTest.php), not anticipated in
            // advance — under a sufficiently tight race, two concurrent
            // inserts checking the SAME exclusion constraint can deadlock
            // against each other rather than one cleanly blocking and then
            // failing with 23P01 (a documented Postgres behavior for
            // exclusion constraints, not a bug in this query). The
            // deadlock victim gets the identical customer-facing outcome —
            // it lost a race for this slot — so it's mapped the same way,
            // never surfaced as a 500.
            if (in_array($e->errorInfo[0] ?? null, ['23P01', '40P01'], true)) {
                return response()->json(['error' => 'SLOT_ALREADY_BOOKED'], 409);
            }

            throw $e;
        }

        $appointment = $created['appointment'];
        $service = $created['service'];
        $tenant = Tenant::query()->findOrFail($tenantId);

        $mandate = $this->mandateRenderer->render($tenant, $service);
        $depositAmount = $service->price_amount - $mandate['balance_amount_disclosed'];
        $applicationFeeAmount = (int) round($depositAmount * config('services.stripe.application_fee_bps') / 10000);

        try {
            $paymentIntent = $this->paymentIntentGateway->create(
                $depositAmount,
                $service->currency,
                $tenant->stripe_connect_account_id,
                $applicationFeeAmount,
                ['tenant_id' => $tenantId, 'appointment_id' => $appointment->id],
            );
        } catch (Throwable) {
            TenantContext::run($tenantId, function () use ($appointment) {
                $appointment->status = 'cancelled';
                $appointment->cancelled_by = 'system';
                $appointment->cancelled_reason = 'payment_provider_error';
                $appointment->cancelled_at = now();
                $appointment->save();
            });

            return response()->json(['error' => 'PAYMENT_PROVIDER_UNAVAILABLE'], 502);
        }

        TenantContext::run($tenantId, function () use ($appointment, $paymentIntent, $depositAmount, $applicationFeeAmount, $service, $mandate, $request, $validated) {
            Payment::query()->create([
                'appointment_id' => $appointment->id,
                'type' => 'deposit',
                'stripe_payment_intent_id' => $paymentIntent->id,
                'amount' => $depositAmount,
                'currency' => $service->currency,
                'application_fee_amount' => $applicationFeeAmount,
                'status' => 'requires_action',
            ]);

            PaymentMandate::query()->create([
                'appointment_id' => $appointment->id,
                'mandate_text' => $mandate['text'],
                'mandate_template_version' => $validated['mandate_template_version'],
                'balance_amount_disclosed' => $mandate['balance_amount_disclosed'],
                'accepted_at' => now(),
                'accepted_ip' => $request->ip(),
                'accepted_user_agent' => $request->userAgent(),
                'stripe_payment_intent_id' => $paymentIntent->id,
                'stripe_payment_method_id' => null,
            ]);
        });

        ReleaseExpiredPendingBookingJob::dispatch($tenantId, $appointment->id)
            ->delay(now()->addMinutes(config('booking.hold_window_minutes')));

        $holdExpiresAt = $appointment->created_at
            ->addMinutes(config('booking.hold_window_minutes'))
            ->addMinutes(config('booking.confirm_payment_token_grace_minutes'));

        return response()->json([
            'appointment_id' => $appointment->id,
            'status' => 'pending_payment',
            'deposit' => [
                'amount' => $depositAmount,
                'currency' => $service->currency,
                'client_secret' => $paymentIntent->clientSecret,
            ],
            'manage_token' => SignedTenantToken::issue('manage_booking', $tenantId, $appointment->id),
            'payment_confirmation_token' => SignedTenantToken::issue('confirm_payment', $tenantId, $appointment->id, $holdExpiresAt),
        ], 201);
    }
}
