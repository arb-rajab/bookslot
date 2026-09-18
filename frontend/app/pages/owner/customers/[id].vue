<script setup lang="ts">
import type { CustomerExport, CustomerErasureResponse, ReinviteResponse } from '~/types/owner'

definePageMeta({ layout: 'owner' })

const route = useRoute()
const customerId = route.params.id as string

const data = ref<CustomerExport | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)

onMounted(load)

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null

  try {
    data.value = await apiFetch<CustomerExport>(`/owner/customers/${customerId}/export`)
  } catch (e) {
    loadError.value = describeError(apiErrorBody(e).error)
  } finally {
    loading.value = false
  }
}

/**
 * D-0059: erasure is blocked (409 ACTIVE_BOOKING_EXISTS) only while a
 * still-live booking exists — computed here purely as an owner-facing
 * hint so the button's own confirm step can explain *why* it might be
 * rejected before the owner clicks it; the server remains the sole source
 * of truth for this rule.
 */
const hasActiveBooking = computed(() =>
  data.value?.appointments.some((a) => a.status === 'pending_payment' || a.status === 'confirmed') ?? false,
)

const alreadyErased = computed(() => data.value?.customer.erasure_requested_at !== null && data.value?.customer.erasure_requested_at !== undefined)

/**
 * D-0060: re-invite is deliberately NOT deduplicated — every click is a
 * real resend. This "last sent" line is read-only information (derived
 * from the same booking_events this page already loaded) so an owner
 * isn't clicking blind about whether they already sent one recently; it
 * never blocks or throttles another click.
 */
const lastInvite = computed(() => {
  const sends = (data.value?.booking_events ?? [])
    .filter((e) => e.event_type === 'rebooking_invite_sent')
    .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime())
  return sends[0] ?? null
})

const reinviting = ref(false)
const reinviteError = ref<string | null>(null)
const reinviteSent = ref(false)

async function sendReinvite(): Promise<void> {
  reinviting.value = true
  reinviteError.value = null
  reinviteSent.value = false

  try {
    await apiFetch<ReinviteResponse>(`/owner/customers/${customerId}/re-invite`, { method: 'POST' })
    reinviteSent.value = true
    await load()
  } catch (e) {
    reinviteError.value = describeError(apiErrorBody(e).error)
  } finally {
    reinviting.value = false
  }
}

const exporting = ref(false)
const exportError = ref<string | null>(null)

/**
 * Re-fetches rather than reusing already-loaded `data` so a downloaded
 * export always reflects the customer's current state, not a possibly
 * stale copy from whenever the page happened to load.
 */
async function downloadExport(): Promise<void> {
  exporting.value = true
  exportError.value = null

  try {
    const fresh = await apiFetch<CustomerExport>(`/owner/customers/${customerId}/export`)
    const blob = new Blob([JSON.stringify(fresh, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `customer-${customerId}-export.json`
    link.click()
    URL.revokeObjectURL(url)
  } catch (e) {
    exportError.value = describeError(apiErrorBody(e).error)
  } finally {
    exporting.value = false
  }
}

const erasureConfirming = ref(false)
const erasing = ref(false)
const erasureError = ref<string | null>(null)

function startErasure(): void {
  erasureConfirming.value = true
  erasureError.value = null
}

function cancelErasureConfirm(): void {
  erasureConfirming.value = false
}

async function confirmErasure(): Promise<void> {
  erasing.value = true
  erasureError.value = null

  try {
    const response = await apiFetch<CustomerErasureResponse>(`/owner/customers/${customerId}/erasure`, { method: 'POST' })
    if (data.value) {
      data.value.customer.name = response.name
      data.value.customer.email = response.email
      data.value.customer.phone = response.phone
      data.value.customer.erasure_requested_at = response.erasure_requested_at
    }
    erasureConfirming.value = false
  } catch (e) {
    erasureError.value = describeError(apiErrorBody(e).error)
  } finally {
    erasing.value = false
  }
}

function describeError(code: string): string {
  const messages: Record<string, string> = {
    NOT_FOUND: 'That customer could not be found.',
    ACTIVE_BOOKING_EXISTS: 'This customer has an active (pending or confirmed) booking — erasure is blocked until it is completed or cancelled.',
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
    <p v-if="loadError" class="error">{{ loadError }}</p>
    <p v-if="loading">Loading…</p>

    <template v-else-if="data">
      <h1>{{ data.customer.name }}</h1>
      <p class="muted">Customer since {{ formatDateTime(data.customer.created_at) }}</p>

      <section class="card">
        <h2>Contact details</h2>
        <p v-if="alreadyErased" class="warning">
          This customer's data was erased on {{ formatDateTime(data.customer.erasure_requested_at) }}. The fields
          below show the anonymized record.
        </p>
        <dl>
          <dt>Name</dt><dd>{{ data.customer.name }}</dd>
          <dt>Email</dt><dd>{{ data.customer.email }}</dd>
          <dt>Phone</dt><dd>{{ data.customer.phone ?? '—' }}</dd>
          <dt v-if="data.customer.notes">Notes</dt><dd v-if="data.customer.notes">{{ data.customer.notes }}</dd>
        </dl>
      </section>

      <section class="card">
        <h2>Appointments</h2>
        <p v-if="data.appointments.length === 0" class="muted">No appointments on record.</p>
        <table v-else class="data-table">
          <thead><tr><th>When</th><th>Service</th><th>Staff</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="appointment in data.appointments" :key="appointment.id">
              <td><NuxtLink :to="`/owner/appointments/${appointment.id}`">{{ formatDateTime(appointment.starts_at) }}</NuxtLink></td>
              <td>{{ appointment.service_name }}</td>
              <td>{{ appointment.staff_name }}</td>
              <td>{{ statusLabel(appointment.status) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="card">
        <h2>Payments</h2>
        <p v-if="data.payments.length === 0" class="muted">No payments on record.</p>
        <table v-else class="data-table">
          <thead><tr><th>Type</th><th>Status</th><th>Amount</th><th>Refunds</th><th>Created</th></tr></thead>
          <tbody>
            <tr v-for="payment in data.payments" :key="payment.id">
              <td>{{ payment.type }}</td>
              <td>{{ statusLabel(payment.status) }}</td>
              <td>{{ formatAmount(payment.amount, payment.currency) }}</td>
              <td>
                <span v-if="payment.refunds.length === 0">—</span>
                <span v-else>{{ payment.refunds.map((r) => formatAmount(r.amount, payment.currency)).join(', ') }}</span>
              </td>
              <td>{{ formatDateTime(payment.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="card">
        <h2>Re-invite to book again</h2>
        <p class="muted">
          Sends this customer a link back to the public booking page (FR-23) — independent of whether
          their last appointment was a no-show. Repeat sends are real resends by design (D-0060), not
          deduplicated; the note below reflects the most recent one already on record, purely so you
          aren't clicking blind.
        </p>
        <p v-if="lastInvite" class="muted">Last invited: {{ formatDateTime(lastInvite.created_at) }}</p>
        <p v-if="reinviteError" class="error">{{ reinviteError }}</p>
        <p v-if="reinviteSent" class="success">Invite sent.</p>
        <button type="button" :disabled="reinviting" @click="sendReinvite">
          {{ reinviting ? 'Sending…' : 'Send re-invite' }}
        </button>
      </section>

      <section class="card">
        <h2>Export customer data</h2>
        <p class="muted">Downloads everything this app holds about this customer (FR-18) as a JSON file.</p>
        <p v-if="exportError" class="error">{{ exportError }}</p>
        <button type="button" :disabled="exporting" @click="downloadExport">
          {{ exporting ? 'Preparing export…' : 'Download export' }}
        </button>
      </section>

      <section v-if="!alreadyErased" class="card">
        <h2>Erase customer data</h2>
        <p class="muted">
          Anonymizes this customer's name, email, phone, and notes in place (FR-18). Appointments,
          payments, refunds, and consent records are kept for the studio's own accounting/audit history
          and are never affected.
        </p>
        <p v-if="hasActiveBooking" class="warning">
          This customer currently has an active (pending or confirmed) booking. Erasure will be blocked
          until it is completed or cancelled.
        </p>
        <p v-if="erasureError" class="error">{{ erasureError }}</p>

        <button v-if="!erasureConfirming" type="button" class="danger" @click="startErasure">Erase customer data&hellip;</button>

        <div v-else class="action-form">
          <p class="confirm-prompt">Are you sure? This overwrites the customer's name, email, and phone and cannot be undone.</p>
          <div class="modal-actions">
            <button type="button" class="secondary" :disabled="erasing" @click="cancelErasureConfirm">Cancel</button>
            <button type="button" class="danger" :disabled="erasing" @click="confirmErasure">
              {{ erasing ? 'Erasing…' : 'Confirm erasure' }}
            </button>
          </div>
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

.data-table a {
  color: #1a1a1a;
}

.action-form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-width: 420px;
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

button.secondary {
  background: #fff;
  color: #1a1a1a;
}

button.danger {
  background: #b3261e;
  border-color: #b3261e;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
