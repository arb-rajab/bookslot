<script setup lang="ts">
import type { OwnerAppointmentDetail } from '~/types/owner'

definePageMeta({ layout: 'owner' })

const route = useRoute()
const appointmentId = route.params.id as string

const detail = ref<OwnerAppointmentDetail | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const cancelReason = ref('')
const cancelling = ref(false)
const cancelError = ref<string | null>(null)

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
  cancelError.value = null

  try {
    detail.value = await apiFetch<OwnerAppointmentDetail>(`/owner/appointments/${appointmentId}/cancel`, {
      method: 'POST',
      body: { reason: cancelReason.value || null },
    })
  } catch (e) {
    cancelError.value = describeError(apiErrorBody(e).error)
  } finally {
    cancelling.value = false
  }
}

function describeError(code: string): string {
  const messages: Record<string, string> = {
    NOT_FOUND: 'That appointment could not be found.',
    INVALID_STATUS_TRANSITION: 'This appointment can no longer be cancelled.',
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
          <dt>Customer</dt><dd>{{ detail.customer_name }} ({{ detail.customer_email }}<template v-if="detail.customer_phone">, {{ detail.customer_phone }}</template>)</dd>
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
          Bookkeeping only — this does not issue a refund on Stripe. A deposit refund is a separate,
          not-yet-built capability (05-api-contracts.md's refund endpoint).
        </p>
        <p v-if="cancelError" class="error">{{ cancelError }}</p>
        <div class="cancel-form">
          <input v-model="cancelReason" type="text" placeholder="Reason (optional)" />
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

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
