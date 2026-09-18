<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BookingEvent;
use App\Models\NotificationDelivery;
use App\Models\Payment;
use App\Models\PaymentMandate;
use App\Models\Refund;
use App\Models\Tenant;
use App\Payments\PaymentIntentGateway;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * GET/PATCH /api/owner/appointments... (05-api-contracts.md endpoint 4,
 * built this session). Owner sees every one of their tenant's
 * appointments — D-0013's "own bookings only" narrowing applies to staff,
 * not the owner (see this session's D-0042). Gated by `auth`,
 * `auth.tenant`, `role:owner` (D-0029; consolidated into one middleware by
 * D-0043) — the whole request already runs inside one DB transaction
 * scoped to the owner's own tenant (BelongsToTenant + RLS), so no manual
 * TenantContext::run() is needed here for most actions, unlike
 * BookingController/PaymentConfirmationController.
 *
 * refund() (D-0056) and chargeBalance() (D-0057) are the two exceptions:
 * both call Stripe, so both run behind `auth.tenant.external` instead (see
 * routes/api.php and AuthenticateTenantUserWithoutTransactionWrap), not the
 * `auth.tenant` group below, and both open their own short
 * TenantContext::run() calls exactly like
 * BookingController/PaymentConfirmationController already do.
 */
class AppointmentController extends Controller
{
    public function __construct(private readonly PaymentIntentGateway $paymentIntentGateway) {}

    /**
     * D-0042: only `confirmed` is a valid prior state for either target
     * status, per 04-data-model.md's booking state machine
     * (`confirmed --> completed`, `confirmed --> no_show` are the only two
     * incoming transitions either status has). `cancelled` is a documented
     * third target for this same endpoint in 05-api-contracts.md but is
     * deliberately not accepted here — this session's scope is attended/
     * no-show disposition only (D-0006: bookkeeping on already-captured
     * funds, no Stripe call); owner-initiated cancellation is a distinct
     * workflow (J7) with its own refund considerations, left for a future
     * session.
     */
    private const VALID_TARGET_STATUSES = ['completed', 'no_show'];

    /**
     * D-0051: only a still-live booking can be cancelled — a terminal
     * status (`completed`, `no_show`, already-`cancelled`) has nothing left
     * to cancel.
     */
    private const CANCELLABLE_STATUSES = ['pending_payment', 'confirmed'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['pending_payment', 'confirmed', 'completed', 'no_show', 'cancelled'])],
        ]);

        $query = Appointment::query()->with(['customer:id,name', 'service:id,name', 'staff:id,display_name']);

        if (isset($validated['from'])) {
            $query->where('starts_at', '>=', $validated['from']);
        }

        if (isset($validated['to'])) {
            $query->where('starts_at', '<=', $validated['to']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $appointments = $query->orderBy('starts_at')->get();

        return response()->json([
            'appointments' => $appointments->map(fn (Appointment $appointment) => $this->present($appointment)),
            'no_show_count' => $this->noShowCount($validated),
        ]);
    }

    /**
     * FR-15 (02-requirements.md): "a basic no-show count, scoped strictly
     * to the owner's own tenant" — a raw count (not a rate/percentage),
     * tenant-wide (not per customer/per service; the requirement names
     * only the tenant as a scope). RLS + BelongsToTenant already confine
     * this to the caller's own tenant, the same as every other query in
     * this controller.
     *
     * Deliberately re-reads `appointments.status = 'no_show'` directly,
     * not any hold-window/expiry mechanism: FR-05's
     * ReleaseExpiredPendingBookingJob (D-0049) only ever transitions a
     * `pending_payment` booking to `cancelled` (an unpaid slot released,
     * `cancelled_reason = 'hold_window_expired'`) — it has nothing to do
     * with no-shows. A no-show is `confirmed -> no_show`, set only by an
     * explicit owner action via `updateStatus()` above (FR-07, D-0042).
     * That explicit-action-only path is exactly what J4 (no automatic
     * no-show detection) requires, so counting it introduces no new
     * detection logic — it only counts owner-made decisions that already
     * exist.
     *
     * Uses the same `from`/`to` window as the list above (the count on the
     * dashboard should describe the same period being viewed), but
     * deliberately ignores the `status` filter — otherwise filtering the
     * list to `status=confirmed` would make this always report 0,
     * defeating the point of a standing summary count.
     *
     * @param  array{from?: string, to?: string}  $validated
     */
    private function noShowCount(array $validated): int
    {
        $query = Appointment::query()->where('status', 'no_show');

        if (isset($validated['from'])) {
            $query->where('starts_at', '>=', $validated['from']);
        }

        if (isset($validated['to'])) {
            $query->where('starts_at', '<=', $validated['to']);
        }

        return $query->count();
    }

    /**
     * D-0051: the detail view the frontend's booking-detail page needs —
     * every payment (deposit and, if it exists, balance) with its real
     * Stripe-webhook-derived status, the accepted mandate, the reminder
     * (`notification_deliveries`) log, and the full `booking_events` audit
     * trail for this one appointment. Never exposes raw Stripe IDs — those
     * are an internal reconciliation detail, not something a studio owner
     * needs to see or could act on from this screen.
     */
    public function show(string $id): JsonResponse
    {
        $appointment = Appointment::query()->with(['customer', 'service', 'staff'])->find($id);

        if ($appointment === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        return response()->json($this->presentDetail($appointment));
    }

    /**
     * The full detail shape (frontend/app/types/owner.ts's
     * OwnerAppointmentDetail) — shared by show() and cancel() so both ever
     * only build it in one place. Found necessary this session (R-08): the
     * frontend's appointment-detail page (frontend/app/pages/owner/
     * appointments/[id].vue) assigns whatever `cancel()` returns straight
     * onto `detail.value`, so a response missing `payments`/`reminders`/
     * `events` doesn't just render a slightly incomplete page — the
     * template's `detail.payments.length` (etc.) throws on `undefined` and
     * crashes the whole page, silently, past the click that triggered it.
     * No non-browser test could have caught this: every existing
     * `cancel()` Feature test only asserted the JSON body's own fields, not
     * how a real Vue template that had already rendered the fuller show()
     * shape would react to a thinner one replacing it in place.
     */
    private function presentDetail(Appointment $appointment): array
    {
        $payments = Payment::query()->where('appointment_id', $appointment->id)->get();

        return [
            ...$this->present($appointment),
            'customer_email' => $appointment->customer?->email,
            'customer_phone' => $appointment->customer?->phone,
            'notes' => $appointment->notes,
            'cancelled_by' => $appointment->cancelled_by,
            'cancelled_reason' => $appointment->cancelled_reason,
            'cancelled_at' => $appointment->cancelled_at,
            'payments' => $payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'type' => $payment->type,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'failure_code' => $payment->failure_code,
                'created_at' => $payment->created_at,
            ]),
            'reminders' => NotificationDelivery::query()
                ->where('appointment_id', $appointment->id)
                ->orderBy('scheduled_for')
                ->get(['id', 'purpose', 'channel', 'scheduled_for', 'sent_at', 'status']),
            'events' => BookingEvent::query()
                ->where('appointment_id', $appointment->id)
                ->orderBy('created_at')
                ->get(['id', 'actor_type', 'event_type', 'from_status', 'to_status', 'created_at']),
        ];
    }

    /**
     * D-0051: a new, dedicated action rather than a third value accepted by
     * `updateStatus()` — D-0042 (Session 17) deliberately kept `cancelled`
     * out of that endpoint's accepted target list, reasoning it as "a
     * distinct workflow with its own refund considerations, not yet
     * reasoned through." That reasoning still holds: this endpoint
     * deliberately does NOT touch Stripe or create a `refunds` row — it is
     * bookkeeping only (status + audit trail), identical in spirit to how
     * `updateStatus()` already treats `no_show` forfeiture as bookkeeping
     * on an already-captured deposit. A studio owner who needs to actually
     * refund money still has no endpoint for that (05-api-contracts.md's
     * endpoint 5, `POST .../refund`, remains unbuilt) — this is named here,
     * not silently implied as "cancel = refunded."
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        $appointment = Appointment::query()->find($id);

        if ($appointment === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        if (! in_array($appointment->status, self::CANCELLABLE_STATUSES, true)) {
            return response()->json(['error' => 'INVALID_STATUS_TRANSITION'], 409);
        }

        $reason = $validated['reason'] ?? null;

        $fromStatus = $appointment->status;
        $appointment->status = 'cancelled';
        $appointment->cancelled_by = 'studio';
        $appointment->cancelled_reason = $reason;
        $appointment->cancelled_at = now();
        $appointment->save();

        BookingEvent::query()->create([
            'appointment_id' => $appointment->id,
            'actor_type' => 'owner',
            'actor_id' => $request->user()->id,
            'event_type' => 'status_changed',
            'from_status' => $fromStatus,
            'to_status' => 'cancelled',
            'metadata' => $reason !== null ? ['reason' => $reason] : null,
        ]);

        return response()->json($this->presentDetail($appointment->fresh(['customer', 'service', 'staff'])));
    }

    /**
     * POST /api/owner/appointments/{id}/refund (05-api-contracts.md
     * endpoint 5, D-0056). FR-12/J7/J8: owner-initiated only — a refund is
     * never system-triggered by cancellation or anything else, matching
     * this codebase's standing "explicit owner action, not automatic"
     * pattern for anything touching money (D-0006, D-0042, D-0051). Full or
     * partial, against an already-captured (`succeeded`) deposit payment,
     * independent of the appointment's own status: J8 explicitly allows a
     * refund on an otherwise still-`confirmed`/`completed` appointment when
     * a dispute requires it, not only after a cancellation (J7) — so this
     * method deliberately does not gate on `appointments.status` at all,
     * only on the deposit `payments` row's own state.
     *
     * Eligibility (04-data-model.md's payment state machine, taken
     * literally): `succeeded` is the only refundable status, AND it is a
     * one-shot transition — the diagram draws both `refunded` and
     * `partially_refunded` as terminal (no outgoing edge at all), so a
     * payment is refundable exactly once in this codebase's data model,
     * never incrementally topped up across multiple calls. A payment that
     * was never captured (no deposit row, or one still `requires_action`/
     * `failed`) and a payment that has already been refunded (fully or
     * partially) both fail the same `status !== 'succeeded'` check,
     * matching 05's documented `409` ("deposit payment isn't in a
     * refundable state"). Because of that one-shot property, "the
     * remaining refundable balance" 05's contract refers to is simply the
     * payment's own `amount` — there is never a prior successful refund to
     * subtract while status is still `succeeded`. `amount` (minor units)
     * defaults to the full deposit amount when omitted; exceeding it is
     * `422`, per 05's contract.
     */
    public function refund(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string'],
        ]);

        $tenantId = (string) $request->attributes->get('tenant_id');

        // Short, explicit TenantContext::run() — this route runs behind
        // `auth.tenant.external`, not `auth.tenant`, so (unlike every other
        // method on this controller) nothing wraps this read in a
        // transaction automatically. Tenant-scoped by RLS exactly like
        // updateStatus()'s own find(): a foreign appointment id resolves to
        // null here, indistinguishable from a nonexistent one.
        $lookup = TenantContext::run($tenantId, function () use ($id) {
            $appointment = Appointment::query()->find($id);

            if ($appointment === null) {
                return null;
            }

            $payment = Payment::query()
                ->where('appointment_id', $appointment->id)
                ->where('type', 'deposit')
                ->first();

            return ['appointment' => $appointment, 'payment' => $payment];
        });

        if ($lookup === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        $payment = $lookup['payment'];

        if ($payment === null || $payment->status !== 'succeeded') {
            return response()->json(['error' => 'PAYMENT_NOT_REFUNDABLE'], 409);
        }

        $refundableBalance = $payment->amount;
        $amount = $validated['amount'] ?? $refundableBalance;

        if ($amount > $refundableBalance) {
            return response()->json([
                'error' => 'VALIDATION_FAILED',
                'fields' => ['amount' => ["amount exceeds the remaining refundable balance ({$refundableBalance})"]],
            ], 422);
        }

        $reason = $validated['reason'] ?? null;

        // D-0027's boundary, applied here for the first time on an owner
        // route: the Stripe call sits strictly outside any open
        // transaction, same as BookingController/PaymentConfirmationController.
        try {
            $refundResult = $this->paymentIntentGateway->refund($payment->stripe_payment_intent_id, $amount, $reason);
        } catch (Throwable) {
            return response()->json(['error' => 'PAYMENT_PROVIDER_UNAVAILABLE'], 502);
        }

        $result = TenantContext::run($tenantId, function () use ($id, $request, $payment, $amount, $reason, $refundResult, $refundableBalance) {
            $refund = Refund::query()->create([
                'payment_id' => $payment->id,
                'stripe_refund_id' => $refundResult->id,
                'amount' => $amount,
                'reason' => $reason,
                'status' => $refundResult->status,
            ]);

            if ($refundResult->status === 'succeeded') {
                $payment->status = $amount === $refundableBalance ? 'refunded' : 'partially_refunded';
                $payment->save();
            }

            // The `booking_events` audit-trail pattern D-0053 already
            // extended to dispute tracking — `refund_issued` is
            // 04-data-model.md's own named example event_type for exactly
            // this action, never before written until now.
            BookingEvent::query()->create([
                'appointment_id' => $id,
                'actor_type' => 'owner',
                'actor_id' => $request->user()->id,
                'event_type' => 'refund_issued',
                'metadata' => ['refund_id' => $refund->id, 'amount' => $amount, 'reason' => $reason],
            ]);

            return ['refund' => $refund, 'payment_status' => $payment->status];
        });

        return response()->json([
            'refund' => $result['refund'],
            'payment_status' => $result['payment_status'],
        ]);
    }

    /**
     * POST /api/owner/appointments/{id}/balance/charge (05-api-contracts.md
     * endpoint 6, D-0057). J5: owner-initiated only, same "explicit owner
     * action, never system-triggered" pattern as refund() above — 04's
     * schema has no studio-configured "auto-charge policy" field anywhere
     * (checked directly: neither `tenants` nor `services` carries one), so
     * J5's "if the studio's policy is auto-charge" branch describes a
     * product-config knob that was never built; this endpoint is the
     * explicit action a still-unbuilt "charge balance" button would call,
     * matching endpoint 6's own contract shape (a dedicated POST action
     * route, not an automatic side effect of updateStatus()'s
     * confirmed -> completed transition).
     *
     * Eligibility, each checked against 04's real state machine/fields
     * rather than assumed:
     * - `appointments.status === 'completed'` — J5 point 1 ("owner marks
     *   the appointment completed"); J4 point 4 is explicit that a no-show
     *   never gets a balance charge attempt, and `pending_payment`/
     *   `confirmed`/`cancelled` have no balance to collect yet or ever.
     * - The deposit `payments` row must be `succeeded` — mirrors refund()'s
     *   own reasoning; an appointment can reach `completed` with no
     *   captured deposit only via bad data, but this is still checked
     *   rather than assumed.
     * - No existing `balance` payment already `succeeded`/`paid_manually`/
     *   `processing` — prevents double-charging a balance that's already
     *   settled or mid-flight. A prior `failed` balance payment does NOT
     *   block a retry (declined once, owner tries again after the
     *   customer updates their card) — failure is J5's own documented
     *   expected, retriable outcome, not a terminal one.
     *
     * Balance amount: `payment_mandates.balance_amount_disclosed`, NOT
     * `service.price_amount - deposit.amount` recomputed live. This is a
     * deliberate choice, not an oversight — `balance_amount_disclosed` is
     * 04's own field for "the exact remaining-balance figure disclosed at
     * booking time" as part of the mandate the customer actually agreed
     * to, and D-0006's context note is explicit that an off-session charge
     * needs to match what the cardholder consented to at save time for the
     * SCA/dispute-defense reasoning that table exists for. Recomputing
     * from the service's CURRENT price would silently charge a different
     * amount than was disclosed if the owner edited the service's price
     * after this booking was made — exactly the drift the mandate snapshot
     * exists to prevent.
     *
     * Payment-method mechanics: reuses `payment_mandates.
     * stripe_payment_method_id` (D-0031's nullable-with-backfill column) —
     * no new payment-method-saving plumbing needed. D-0006's `create()`
     * already sets `setup_future_usage: off_session` on the deposit
     * PaymentIntent and never creates a Stripe Customer object; Stripe's
     * documented off-session-reuse-without-a-Customer pattern lets that
     * same `payment_method` ID be confirmed again directly (see
     * StripePaymentIntentGateway::chargeOffSession()'s own docblock). If
     * that column is still null (D-0031's backfill genuinely never ran —
     * R-07), there is nothing to charge against; this is a 409, not a
     * 502, since no Stripe call is even attempted.
     */
    public function chargeBalance(Request $request, string $id): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        $lookup = TenantContext::run($tenantId, function () use ($id) {
            $appointment = Appointment::query()->with('service')->find($id);

            if ($appointment === null) {
                return null;
            }

            $deposit = Payment::query()
                ->where('appointment_id', $appointment->id)
                ->where('type', 'deposit')
                ->first();

            $existingBalance = Payment::query()
                ->where('appointment_id', $appointment->id)
                ->where('type', 'balance')
                ->first();

            $mandate = PaymentMandate::query()->where('appointment_id', $appointment->id)->first();

            $tenant = Tenant::query()->find($appointment->tenant_id);

            return compact('appointment', 'deposit', 'existingBalance', 'mandate', 'tenant');
        });

        if ($lookup === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        ['appointment' => $appointment, 'deposit' => $deposit, 'existingBalance' => $existingBalance, 'mandate' => $mandate, 'tenant' => $tenant] = $lookup;

        if ($appointment->status !== 'completed') {
            return response()->json(['error' => 'INVALID_STATUS_TRANSITION'], 409);
        }

        if ($deposit === null || $deposit->status !== 'succeeded') {
            return response()->json(['error' => 'DEPOSIT_NOT_CAPTURED'], 409);
        }

        if ($existingBalance !== null && in_array($existingBalance->status, ['succeeded', 'paid_manually', 'processing'], true)) {
            return response()->json(['error' => 'BALANCE_ALREADY_SETTLED'], 409);
        }

        if ($mandate === null || $mandate->stripe_payment_method_id === null) {
            return response()->json(['error' => 'PAYMENT_METHOD_NOT_AVAILABLE'], 409);
        }

        $balanceAmount = $mandate->balance_amount_disclosed;

        if ($balanceAmount <= 0) {
            return response()->json(['error' => 'NO_BALANCE_DUE'], 409);
        }

        $applicationFeeAmount = (int) round($balanceAmount * config('services.stripe.application_fee_bps') / 10000);

        // D-0027's boundary, same as refund() — the Stripe call sits
        // strictly outside any open transaction.
        try {
            $chargeResult = $this->paymentIntentGateway->chargeOffSession(
                $mandate->stripe_payment_method_id,
                $balanceAmount,
                $deposit->currency,
                $tenant->stripe_connect_account_id,
                $applicationFeeAmount,
                ['tenant_id' => $tenantId, 'appointment_id' => $appointment->id],
            );
        } catch (Throwable) {
            return response()->json(['error' => 'PAYMENT_PROVIDER_UNAVAILABLE'], 502);
        }

        $payment = TenantContext::run($tenantId, function () use ($id, $request, $appointment, $deposit, $balanceAmount, $applicationFeeAmount, $chargeResult) {
            $payment = Payment::query()->create([
                'appointment_id' => $appointment->id,
                'type' => 'balance',
                'stripe_payment_intent_id' => $chargeResult->paymentIntentId,
                'amount' => $balanceAmount,
                'currency' => $deposit->currency,
                'application_fee_amount' => $applicationFeeAmount,
                'status' => $chargeResult->succeeded() ? 'succeeded' : 'failed',
                'failure_code' => $chargeResult->failureCode,
            ]);

            // Same booking_events audit-trail pattern D-0053/D-0056 already
            // use — a failed off-session charge is recorded too (not just
            // successes), since J6's "collect in person" fallback and any
            // later dispute both need to see that an attempt was made and
            // why it didn't succeed.
            BookingEvent::query()->create([
                'appointment_id' => $id,
                'actor_type' => 'owner',
                'actor_id' => $request->user()->id,
                'event_type' => $chargeResult->succeeded() ? 'balance_charge_succeeded' : 'balance_charge_failed',
                'metadata' => [
                    'payment_id' => $payment->id,
                    'amount' => $balanceAmount,
                    'failure_code' => $chargeResult->failureCode,
                ],
            ]);

            return $payment;
        });

        if ($chargeResult->succeeded()) {
            return response()->json(['status' => 'succeeded', 'payment_id' => $payment->id]);
        }

        // Deliberately 200, not 4xx/5xx — 05-api-contracts.md endpoint 6:
        // a declined/authentication-required off-session charge is an
        // expected, handled business outcome (J5), not a malformed request
        // or a server error. fallback_action tells the frontend exactly
        // which UI to show (J6) without inferring it from an HTTP status.
        return response()->json([
            'status' => 'failed',
            'failure_code' => $chargeResult->failureCode,
            'fallback_action' => 'mark_paid_manually',
        ]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::VALID_TARGET_STATUSES)],
        ]);

        // Tenant-scoped by BelongsToTenant + RLS, same as every other
        // controller in this codebase — an id belonging to another tenant
        // is indistinguishable from a nonexistent one at this query, so it
        // resolves to the same 404 rather than a 403 that would require an
        // out-of-scope cross-tenant existence check to produce.
        $appointment = Appointment::query()->find($id);

        if ($appointment === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        if ($appointment->status !== 'confirmed') {
            return response()->json(['error' => 'INVALID_STATUS_TRANSITION'], 409);
        }

        $fromStatus = $appointment->status;
        $appointment->status = $validated['status'];
        $appointment->save();

        // D-0006's disposition, recorded into the audit trail 04 names for
        // exactly this purpose: completed -> deposit applied to balance;
        // no_show -> deposit forfeited per studio policy. Both are pure
        // bookkeeping — no payments/refunds row is created, no Stripe call
        // is made; the disposition IS this status transition plus this
        // event row.
        BookingEvent::query()->create([
            'appointment_id' => $appointment->id,
            'actor_type' => 'owner',
            'actor_id' => $request->user()->id,
            'event_type' => 'status_changed',
            'from_status' => $fromStatus,
            'to_status' => $appointment->status,
        ]);

        return response()->json($this->present($appointment->fresh(['customer', 'service', 'staff'])));
    }

    /**
     * `customer_id` (Session 36) is the one additive field this shared
     * projection gained to let the new owner-admin UI link an appointment
     * row to `/owner/customers/{id}` (erasure/export/re-invite) without a
     * new endpoint — every existing consumer already tolerates unknown
     * response keys (no `assertExactJson` anywhere in this controller's
     * tests), so this is safe on both `index()` and `show()`/`presentDetail()`.
     *
     * @return array<string, mixed>
     */
    private function present(Appointment $appointment): array
    {
        $depositStatus = Payment::query()
            ->where('appointment_id', $appointment->id)
            ->where('type', 'deposit')
            ->value('status');

        return [
            'id' => $appointment->id,
            'status' => $appointment->status,
            'starts_at' => $appointment->starts_at,
            'ends_at' => $appointment->ends_at,
            'customer_id' => $appointment->customer_id,
            'customer_name' => $appointment->customer?->name,
            'service_name' => $appointment->service?->name,
            'staff_name' => $appointment->staff?->display_name,
            'deposit_status' => $depositStatus,
        ];
    }
}
