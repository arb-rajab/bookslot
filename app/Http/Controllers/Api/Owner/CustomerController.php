<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\BookingEvent;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentMandate;
use App\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * POST /api/owner/customers/{id}/erasure, GET /api/owner/customers/{id}/export
 * (05-api-contracts.md endpoint 11, FR-18, D-0059). Both behind plain
 * `auth.tenant` (see routes/api.php's owner group) — unlike refund()/
 * chargeBalance()/StripeConnectController, neither action calls Stripe:
 * D-0022 already ruled `payment_mandates` (the only Stripe-ID-bearing table
 * a customer's data touches) is never modified or deleted by erasure, and
 * export is a read-only dump. Whole-request transaction wrap is safe here.
 *
 * Erasure is anonymize-in-place, never row deletion, per 04-data-model.md's
 * soft/hard delete matrix and D-0022: deleting `customers` would either
 * cascade-orphan `appointments` or require `ON DELETE SET NULL`, both of
 * which destroy the studio's own accounting/audit history. `appointments`,
 * `payments`, `refunds`, `payment_mandates`, and `booking_events` are never
 * touched by erase() — only `customers.name`/`email`/`phone`/`notes` are
 * anonymized and `erasure_requested_at` is set.
 */
class CustomerController extends Controller
{
    /**
     * D-0059: a customer with a still-live booking (`pending_payment` or
     * `confirmed`) is not eligible for erasure — the studio still needs
     * the customer's name/contact details to actually deliver that
     * appointment (reminders, day-of identification). `completed`,
     * `no_show`, and `cancelled` are historical and don't block erasure;
     * anonymizing after those is exactly the anonymize-and-preserve
     * behavior the data model already commits to.
     */
    private const ACTIVE_APPOINTMENT_STATUSES = ['pending_payment', 'confirmed'];

    /**
     * FR-18 export: an owner-triggered, read-only dump of everything this
     * codebase holds that is genuinely about one customer. Scoped
     * deliberately, not exhaustively:
     * - `payments`/`refunds`: included in full except the raw Stripe
     *   identifiers (`stripe_payment_intent_id`, `stripe_charge_id`,
     *   `stripe_refund_id`) — same "internal reconciliation detail, not
     *   something the subject needs to see" reasoning
     *   `Owner\AppointmentController::presentDetail()` already applies to
     *   the owner's own view of a payment.
     * - `payment_mandates`: included minus its Stripe identifiers for the
     *   same reason. There is no raw card data anywhere in this schema to
     *   strip (06-security-threat-model.md's PCI SAQ A design: the server
     *   never receives a PAN) — `mandate_text`, `accepted_at`,
     *   `accepted_ip`, and `accepted_user_agent` are included in full,
     *   since D-0022 classifies them as evidentiary/consent record, not
     *   something erasure or export needs to hide from the subject
     *   themselves.
     * - `booking_events`: scoped to this customer's own appointments,
     *   `event_type`/`from_status`/`to_status`/`created_at` only —
     *   `actor_id` is excluded (it identifies a *staff/owner* user or,
     *   for a `system`/`webhook` actor, nothing personal at all; it is
     *   never this customer's own data) and `metadata` is excluded (it
     *   carries internal cross-references like `refund_id`/`payment_id`
     *   already present elsewhere in this same export, not new
     *   information about the customer).
     */
    public function export(string $id): JsonResponse
    {
        $customer = Customer::query()->find($id);

        if ($customer === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        $appointments = Appointment::query()
            ->with(['service:id,name', 'staff:id,display_name'])
            ->where('customer_id', $customer->id)
            ->orderBy('starts_at')
            ->get();

        $appointmentIds = $appointments->pluck('id');

        $payments = Payment::query()->whereIn('appointment_id', $appointmentIds)->get();
        $refundsByPayment = Refund::query()
            ->whereIn('payment_id', $payments->pluck('id'))
            ->get()
            ->groupBy('payment_id');
        $mandates = PaymentMandate::query()->whereIn('appointment_id', $appointmentIds)->get();
        $events = BookingEvent::query()
            ->whereIn('appointment_id', $appointmentIds)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'notes' => $customer->notes,
                'erasure_requested_at' => $customer->erasure_requested_at,
                'created_at' => $customer->created_at,
            ],
            'appointments' => $appointments->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'service_name' => $appointment->service?->name,
                'staff_name' => $appointment->staff?->display_name,
                'starts_at' => $appointment->starts_at,
                'ends_at' => $appointment->ends_at,
                'status' => $appointment->status,
                'cancelled_by' => $appointment->cancelled_by,
                'cancelled_reason' => $appointment->cancelled_reason,
                'cancelled_at' => $appointment->cancelled_at,
                'notes' => $appointment->notes,
                'created_at' => $appointment->created_at,
            ]),
            'payments' => $payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'appointment_id' => $payment->appointment_id,
                'type' => $payment->type,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'failure_code' => $payment->failure_code,
                'created_at' => $payment->created_at,
                'refunds' => ($refundsByPayment->get($payment->id) ?? collect())->map(fn (Refund $refund) => [
                    'id' => $refund->id,
                    'amount' => $refund->amount,
                    'reason' => $refund->reason,
                    'status' => $refund->status,
                    'created_at' => $refund->created_at,
                ])->values(),
            ]),
            'payment_mandates' => $mandates->map(fn (PaymentMandate $mandate) => [
                'id' => $mandate->id,
                'appointment_id' => $mandate->appointment_id,
                'mandate_text' => $mandate->mandate_text,
                'mandate_template_version' => $mandate->mandate_template_version,
                'balance_amount_disclosed' => $mandate->balance_amount_disclosed,
                'accepted_at' => $mandate->accepted_at,
                'accepted_ip' => $mandate->accepted_ip,
                'accepted_user_agent' => $mandate->accepted_user_agent,
            ]),
            'booking_events' => $events->map(fn (BookingEvent $event) => [
                'appointment_id' => $event->appointment_id,
                'event_type' => $event->event_type,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'created_at' => $event->created_at,
            ]),
        ]);
    }

    /**
     * FR-18 erasure. Idempotent by design: a customer who is already
     * erased (`erasure_requested_at` already set) gets a `200` echoing
     * the current (already-anonymized) state rather than a `409` — the
     * same "repeated calls are a no-op status echo, not an error" pattern
     * `PaymentConfirmationController` already uses (D-0021) for a
     * not-single-use action, chosen here so a retried owner request (lost
     * response, double click) can never surface as a confusing error for
     * an action that already fully succeeded.
     *
     * Blocked (`409 ACTIVE_BOOKING_EXISTS`) only while the customer has a
     * still-live (`pending_payment`/`confirmed`) appointment — see
     * ACTIVE_APPOINTMENT_STATUSES's own docblock and D-0059 for why this
     * is a real conflict, not an invented gate.
     */
    public function erase(Request $request, string $id): JsonResponse
    {
        $customer = Customer::query()->find($id);

        if ($customer === null) {
            return response()->json(['error' => 'NOT_FOUND'], 404);
        }

        if ($customer->erasure_requested_at !== null) {
            return response()->json($this->presentCustomer($customer));
        }

        $hasActiveAppointment = Appointment::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', self::ACTIVE_APPOINTMENT_STATUSES)
            ->exists();

        if ($hasActiveAppointment) {
            return response()->json(['error' => 'ACTIVE_BOOKING_EXISTS'], 409);
        }

        $customer->name = 'Erased Customer';
        $customer->email = 'erased-'.(string) Str::uuid().'@erased.invalid';
        $customer->phone = null;
        $customer->notes = null;
        $customer->erasure_requested_at = now();
        $customer->save();

        // appointment_id is deliberately null: this is a customer-level
        // event, not one appointment's lifecycle transition — exactly the
        // case 04-data-model.md's own column note for booking_events.
        // appointment_id documents ("null for a ... event not tied to one
        // appointment lifecycle transition"). payment_mandates/payments/
        // refunds/appointments themselves are untouched (D-0022) and so
        // get no event of their own here.
        BookingEvent::query()->create([
            'appointment_id' => null,
            'actor_type' => 'owner',
            'actor_id' => $request->user()->id,
            'event_type' => 'customer_erased',
            'metadata' => ['customer_id' => $customer->id],
        ]);

        return response()->json($this->presentCustomer($customer));
    }

    /** @return array<string, mixed> */
    private function presentCustomer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'erasure_requested_at' => $customer->erasure_requested_at,
        ];
    }
}
