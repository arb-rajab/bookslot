<script setup lang="ts">
import type { BalanceChargeResponse, OwnerAppointmentDetail, RefundResponse } from '~/types/owner'

definePageMeta({ layout: 'owner' })

const route = useRoute()
const appointmentId = route.params.id as string

const detail = ref<OwnerAppointmentDetail | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const cancelReason = ref('')
const cancelling = ref(false)
const { formError: cancelError, fieldError: cancelFieldError, clear: clearCancelFormErrors, applyError: applyCancelFormError } = useFormErrors()

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null

  try {
    detail.value = await apiFetch<OwnerAppointmentDetail>(`/owner/appointments/${appointmentId}`)
  } catch (e) {
    loadError.value = describeError(apiErrorBody(e).error)
  } finally {
    loading.value = false
  }
}

const cancellable = computed(() => detail.value?.status === 'pending_payment' || detail.value?.status === 'confirmed')

async function cancelAppointment(): Promise<void> {
  cancelling.value = true
  clearCancelFormErrors()

  try {
    detail.value = await apiFetch<OwnerAppointmentDetail>(`/owner/appointments/${appointmentId}/cancel`, {
      method: 'POST',
      body: { reason: cancelReason.value || null },
    })
  } catch (e) {
    applyCancelFormError(e, describeError)
  } finally {
    cancelling.value = false
  }
}

/**
 * D-0056: refund is gated on the deposit payment's own status, never on
 * `appointments.status` (J8 allows a refund against a still-confirmed/
 * completed appointment for a dispute). `succeeded` is the only refundable
 * status — `partially_refunded`/`refunded` are terminal per 04's state
 * machine, so a second refund attempt is never offered once one has
 * already happened.
 */
const refundableDeposit = computed(() =>
  detail.value?.payments.find((p) => p.type === 'deposit' && p.status === 'succeeded') ?? null,
)

const refundAmount = ref<number | null>(null)
const refundReason = ref('')
const refundConfirming = ref(false)
const refunding = ref(false)
/**
 * Carries its own `currency` rather than reading it back off
 * `refundableDeposit` at render time — a successful refund reloads
 * `detail` (so the payments table reflects the new status immediately),
 * which makes `refundableDeposit` itself go null the moment the deposit
 * is no longer `succeeded`. The section stays visible via `showRefundCard`
 * below specifically so this outcome message isn't hidden the instant it
 * would otherwise have something to say.
 */
const refundOutcome = ref<{ payment_status: string, amount: number, currency: string } | null>(null)
const { formError: refundError, fieldError: refundFieldError, clear: clearRefundFormErrors, applyError: applyRefundFormError } = useFormErrors()

const showRefundCard = computed(() => refundableDeposit.value !== null || refundOutcome.value !== null)

function startRefund(): void {
  refundConfirming.value = true
  refundOutcome.value = null
  clearRefundFormErrors()
}

function cancelRefundConfirm(): void {
  refundConfirming.value = false
}

async function confirmRefund(): Promise<void> {
  refunding.value = true
  clearRefundFormErrors()

  try {
    const response = await apiFetch<RefundResponse>(`/owner/appointments/${appointmentId}/refund`, {
      method: 'POST',
      body: {
        amount: refundAmount.value || null,
        reason: refundReason.value || null,
      },
    })
    refundOutcome.value = { payment_status: response.payment_status, amount: response.refund.amount, currency: refundableDeposit.value?.currency ?? 'usd' }
    refundConfirming.value = false
    refundAmount.value = null
    refundReason.value = ''
    await load()
  } catch (e) {
    applyRefundFormError(e, describeError)
  } finally {
    refunding.value = false
  }
}

/**
 * D-0057/D-0061: a plain button, no amount input and no auto-charge policy
 * UI of any kind — the amount is always the server's own
 * `payment_mandates.balance_amount_disclosed`, and this is the only
 * trigger (owner-initiated, per this endpoint's click). Shown once the
 * appointment is `completed` (J5 point 1); the server is still the source
 * of truth for every other eligibility rule (deposit captured, no
 * already-settled balance, a saved payment method, a positive balance).
 */
const balanceChargeable = computed(() => detail.value?.status === 'completed')

const chargeConfirming = ref(false)
const charging = ref(false)
const chargeError = ref<string | null>(null)
/** A 200 "failed" is a distinct, expected outcome (a decline/SCA block) from a thrown/502 provider failure — kept in a separate ref so the two are never rendered as the same kind of error. */
const chargeOutcome = ref<{ status: 'succeeded' | 'failed', failure_code?: string } | null>(null)

function startCharge(): void {
  chargeConfirming.value = true
  chargeError.value = null
  chargeOutcome.value = null
}

function cancelChargeConfirm(): void {
  chargeConfirming.value = false
}

async function confirmCharge(): Promise<void> {
  charging.value = true
  chargeError.value = null

  try {
    const response = await apiFetch<BalanceChargeResponse>(`/owner/appointments/${appointmentId}/balance/charge`, {
      method: 'POST',
    })
    chargeOutcome.value = response.status === 'succeeded'
      ? { status: 'succeeded' }
      : { status: 'failed', failure_code: response.failure_code }
    chargeConfirming.value = false
    await load()
  } catch (e) {
    chargeError.value = describeError(apiErrorBody(e).error)
  } finally {
    charging.value = false
  }
}

function describeError(code: string): string {
  const messages: Record<string, string> = {
    NOT_FOUND: 'That appointment could not be found.',
    INVALID_STATUS_TRANSITION: 'This appointment is not in a state that allows this action.',
    VALIDATION_FAILED: 'Please check the highlighted fields.',
    PAYMENT_NOT_REFUNDABLE: 'The deposit for this appointment is not in a refundable state (nothing captured yet, or it has already been refunded).',
    DEPOSIT_NOT_CAPTURED: 'The deposit for this appointment was never captured, so there is nothing to charge a balance against.',
    BALANCE_ALREADY_SETTLED: 'The balance for this appointment has already been settled.',
    PAYMENT_METHOD_NOT_AVAILABLE: 'No saved payment method is available for an off-session charge on this booking.',
    NO_BALANCE_DUE: 'There is no remaining balance to charge for this appointment.',
    STRIPE_ACCOUNT_NOT_CONNECTED: 'This studio has no connected Stripe account right now (never connected, or disconnected and not yet reconnected) — go to Settings → Stripe to (re)connect before trying this again.',
    PAYMENT_PROVIDER_UNAVAILABLE: 'Stripe is temporarily unavailable. Please try again shortly.',
    NETWORK_ERROR: 'Could not reach the booking server. Is the API running?',
  }
  return messages[code] ?? `Something went wrong (${code}).`
}

function formatDateTime(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

function formatAmount(amount: number, currency: string): string {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency.toUpperCase() }).format(amount / 100)
}

function statusLabel(status: string): string {
  return status.replace('_', ' ')
}
</script>

<template>
  <div>
    <p><NuxtLink to="/owner/appointments">&larr; Back to appointments</NuxtLink></p>

    <p v-if="loadError" class="error">{{ loadError }}</p>
    <p v-if="loading">Loading…</p>

    <template v-else-if="detail">
      <h1>Appointment — {{ formatDateTime(detail.starts_at) }}</h1>
      <p class="muted">Status: {{ statusLabel(detail.status) }}</p>

      <section class="card">
        <h2>Customer & service</h2>
        <dl>
          <dt>Customer</dt>
          <dd>
            {{ detail.customer_name }} ({{ detail.customer_email }}<template v-if="detail.customer_phone">, {{ detail.customer_phone }}</template>)
            <NuxtLink v-if="detail.customer_id" :to="`/owner/customers/${detail.customer_id}`" class="customer-link">View customer profile &rarr;</NuxtLink>
          </dd>
          <dt>Service</dt><dd>{{ detail.service_name }}</dd>
          <dt>Staff</dt><dd>{{ detail.staff_name }}</dd>
          <dt>When</dt><dd>{{ formatDateTime(detail.starts_at) }} – {{ formatDateTime(detail.ends_at) }}</dd>
          <template v-if="detail.status === 'cancelled'">
            <dt>Cancelled by</dt><dd>{{ detail.cancelled_by }}</dd>
            <dt>Cancelled at</dt><dd>{{ formatDateTime(detail.cancelled_at) }}</dd>
            <dt v-if="detail.cancelled_reason">Reason</dt><dd v-if="detail.cancelled_reason">{{ detail.cancelled_reason }}</dd>
          </template>
        </dl>
      </section>

      <section class="card">
        <h2>Payments</h2>
        <p v-if="detail.payments.length === 0" class="muted">No payments recorded.</p>
        <table v-else class="data-table">
          <thead><tr><th>Type</th><th>Status</th><th>Amount</th><th>Failure</th><th>Created</th></tr></thead>
          <tbody>
            <tr v-for="payment in detail.payments" :key="payment.id">
              <td>{{ payment.type }}</td>
              <td>{{ statusLabel(payment.status) }}</td>
              <td>{{ formatAmount(payment.amount, payment.currency) }}</td>
              <td>{{ payment.failure_code ?? '—' }}</td>
              <td>{{ formatDateTime(payment.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section v-if="showRefundCard" class="card refund-card">
        <h2>Refund deposit</h2>
        <p class="muted">
          Full or partial, independent of this appointment's own status (a refund can be issued for a
          dispute even while still confirmed or completed, D-0056/J8).
        </p>
        <p v-if="refundError" class="error">{{ refundError }}</p>
        <p v-if="refundOutcome" class="success">
          Refund issued — deposit is now {{ statusLabel(refundOutcome.payment_status) }} ({{ formatAmount(refundOutcome.amount, refundOutcome.currency) }} refunded).
        </p>

        <template v-if="refundableDeposit">
          <button v-if="!refundConfirming" type="button" class="danger" @click="startRefund">Refund deposit&hellip;</button>

          <div v-else class="action-form">
            <div class="field-with-error">
              <label>
                Amount to refund (cents, leave blank for the full {{ formatAmount(refundableDeposit.amount, refundableDeposit.currency) }})
                <input v-model.number="refundAmount" type="number" min="1" :max="refundableDeposit.amount" placeholder="Full amount" />
              </label>
              <span v-if="refundFieldError('amount')" class="field-error">{{ refundFieldError('amount') }}</span>
            </div>
            <div class="field-with-error">
              <label>
                Reason (optional)
                <input v-model="refundReason" type="text" placeholder="Reason" />
              </label>
              <span v-if="refundFieldError('reason')" class="field-error">{{ refundFieldError('reason') }}</span>
            </div>
            <p class="confirm-prompt">Are you sure? This issues a real refund on Stripe and cannot be undone.</p>
            <div class="modal-actions">
              <button type="button" class="secondary" :disabled="refunding" @click="cancelRefundConfirm">Cancel</button>
              <button type="button" class="danger" :disabled="refunding" @click="confirmRefund">
                {{ refunding ? 'Refunding…' : 'Confirm refund' }}
              </button>
            </div>
          </div>
        </template>
      </section>

      <section v-if="balanceChargeable" class="card balance-card">
        <h2>Charge remaining balance</h2>
        <p class="muted">
          Charges the card on file for the balance disclosed to the customer at booking time
          (D-0057) — owner-initiated only, there is no automatic/policy-based charging in this app.
        </p>
        <p v-if="chargeError" class="error">{{ chargeError }}</p>
        <p v-if="chargeOutcome?.status === 'succeeded'" class="success">Balance charged successfully.</p>
        <div v-else-if="chargeOutcome?.status === 'failed'" class="warning">
          <p>Charge declined ({{ chargeOutcome.failure_code }}). You can retry once the customer updates their card, or collect the balance in person and record it manually.</p>
        </div>

        <button v-if="!chargeConfirming" type="button" @click="startCharge">
          {{ chargeOutcome?.status === 'failed' ? 'Retry balance charge…' : 'Charge remaining balance…' }}
        </button>

        <div v-else class="action-form">
          <p class="confirm-prompt">Are you sure? This attempts a real off-session charge on Stripe.</p>
          <div class="modal-actions">
            <button type="button" class="secondary" :disabled="charging" @click="cancelChargeConfirm">Cancel</button>
            <button type="button" :disabled="charging" @click="confirmCharge">
              {{ charging ? 'Charging…' : 'Confirm charge' }}
            </button>
          </div>
        </div>
      </section>

      <section class="card">
        <h2>Reminder deliveries</h2>
        <p v-if="detail.reminders.length === 0" class="muted">No reminders scheduled yet.</p>
        <table v-else class="data-table">
          <thead><tr><th>Purpose</th><th>Channel</th><th>Scheduled for</th><th>Sent at</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="reminder in detail.reminders" :key="reminder.id">
              <td>{{ reminder.purpose }}</td>
              <td>{{ reminder.channel }}</td>
              <td>{{ formatDateTime(reminder.scheduled_for) }}</td>
              <td>{{ formatDateTime(reminder.sent_at) }}</td>
              <td>{{ statusLabel(reminder.status) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="card">
        <h2>Audit trail</h2>
        <p v-if="detail.events.length === 0" class="muted">No events recorded.</p>
        <table v-else class="data-table">
          <thead><tr><th>When</th><th>Actor</th><th>Event</th><th>From → to</th></tr></thead>
          <tbody>
            <tr v-for="event in detail.events" :key="event.id">
              <td>{{ formatDateTime(event.created_at) }}</td>
              <td>{{ event.actor_type }}</td>
              <td>{{ event.event_type }}</td>
              <td>{{ event.from_status ?? '—' }} → {{ event.to_status ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section v-if="cancellable" class="card">
        <h2>Cancel this appointment</h2>
        <p class="muted">
          Bookkeeping only — this does not issue a refund on Stripe. A deposit refund is a separate
          action (05-api-contracts.md's refund endpoint, `POST .../refund`, D-0056) — see the "Refund
          deposit" section above.
        </p>
        <p v-if="cancelError" class="error">{{ cancelError }}</p>
        <div class="cancel-form">
          <div class="field-with-error">
            <input v-model="cancelReason" type="text" placeholder="Reason (optional)" />
            <span v-if="cancelFieldError('reason')" class="field-error">{{ cancelFieldError('reason') }}</span>
          </div>
          <button type="button" class="danger" :disabled="cancelling" @click="cancelAppointment">
            {{ cancelling ? 'Cancelling…' : 'Cancel appointment' }}
          </button>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.muted {
  color: #666;
  font-size: 0.9rem;
}

.error {
  background: #fdecea;
  color: #611a15;
  border: 1px solid #f5c6cb;
  border-radius: 6px;
  padding: 0.75rem 1rem;
  margin: 1rem 0;
}

.card {
  border: 1px solid #eee;
  border-radius: 8px;
  padding: 1rem 1.25rem;
  margin-bottom: 1.25rem;
}

dl {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 0.35rem 1rem;
  margin: 0;
}

dt {
  font-weight: 600;
  color: #444;
}

dd {
  margin: 0;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}

.data-table th,
.data-table td {
  text-align: left;
  padding: 0.4rem 0.6rem;
  border-bottom: 1px solid #eee;
}

.cancel-form {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  flex-wrap: wrap;
}

.cancel-form input {
  flex: 1;
  min-width: 200px;
  padding: 0.5rem;
}

.field-with-error {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  flex: 1;
  min-width: 200px;
}

.field-error {
  color: #b3261e;
  font-size: 0.85rem;
}

.success {
  background: #e6f4ea;
  color: #1e4620;
  border: 1px solid #b7dfc0;
  border-radius: 6px;
  padding: 0.75rem 1rem;
  margin: 1rem 0;
}

.warning {
  background: #fff8e1;
  color: #7a5b00;
  border: 1px solid #ffe08a;
  border-radius: 6px;
  padding: 0.75rem 1rem;
  margin: 1rem 0;
}

.customer-link {
  margin-left: 0.75rem;
  font-size: 0.85rem;
}

.action-form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-width: 420px;
}

.action-form label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.9rem;
}

.confirm-prompt {
  font-weight: 600;
  margin: 0;
}

.modal-actions {
  display: flex;
  gap: 0.75rem;
}

button {
  cursor: pointer;
  border: 1px solid #1a1a1a;
  background: #1a1a1a;
  color: #fff;
  border-radius: 6px;
  padding: 0.5rem 0.9rem;
  font-size: 0.9rem;
}

button.danger {
  background: #b3261e;
  border-color: #b3261e;
}

button.secondary {
  background: #fff;
  color: #1a1a1a;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
