<script setup lang="ts">
import type { OwnerAppointment } from '~/types/booking'

definePageMeta({ layout: 'owner' })

const { authState } = useOwnerSession()

const appointments = ref<OwnerAppointment[]>([])
const loading = ref(false)
const listError = ref<string | null>(null)
const actionError = ref<string | null>(null)
const actioningId = ref<string | null>(null)

const now = Date.now()
const upcoming = computed(() => appointments.value.filter((a) => new Date(a.starts_at).getTime() >= now))
const past = computed(() => appointments.value.filter((a) => new Date(a.starts_at).getTime() < now))

watchEffect(() => {
  if (authState.value === 'authenticated' && appointments.value.length === 0 && !loading.value) {
    loadAppointments()
  }
})

async function loadAppointments(): Promise<void> {
  loading.value = true
  listError.value = null

  try {
    const response = await apiFetch<{ appointments: OwnerAppointment[] }>('/owner/appointments')
    appointments.value = response.appointments
  } catch (e) {
    listError.value = describeError(apiErrorBody(e).error)
  } finally {
    loading.value = false
  }
}

async function markStatus(appointment: OwnerAppointment, status: 'completed' | 'no_show'): Promise<void> {
  actioningId.value = appointment.id
  actionError.value = null

  try {
    const updated = await apiFetch<OwnerAppointment>(`/owner/appointments/${appointment.id}/status`, {
      method: 'PATCH',
      body: { status },
    })
    const index = appointments.value.findIndex((a) => a.id === appointment.id)
    if (index !== -1) appointments.value[index] = updated
  } catch (e) {
    actionError.value = describeError(apiErrorBody(e).error)
  } finally {
    actioningId.value = null
  }
}

function describeError(code: string): string {
  const messages: Record<string, string> = {
    NOT_FOUND: 'That appointment could not be found.',
    VALIDATION_FAILED: 'Please check the highlighted fields.',
    INVALID_STATUS_TRANSITION: 'This appointment is not in a state that can be marked attended/no-show.',
    NETWORK_ERROR: 'Could not reach the booking server. Is the API running?',
  }
  return messages[code] ?? `Something went wrong (${code}).`
}

function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}

function statusLabel(status: string): string {
  return status.replace('_', ' ')
}
</script>

<template>
  <div>
    <h1>Appointments</h1>

    <p v-if="listError" class="error">{{ listError }}</p>
    <p v-if="actionError" class="error">{{ actionError }}</p>
    <p v-if="loading">Loading appointments…</p>

    <template v-else>
      <h2>Upcoming ({{ upcoming.length }})</h2>
      <p v-if="upcoming.length === 0" class="muted">No upcoming appointments.</p>
      <table v-else class="appointments-table">
        <thead>
          <tr>
            <th>When</th>
            <th>Customer</th>
            <th>Service</th>
            <th>Staff</th>
            <th>Status</th>
            <th>Deposit</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="appointment in upcoming" :key="appointment.id">
            <td><NuxtLink :to="`/owner/appointments/${appointment.id}`">{{ formatDateTime(appointment.starts_at) }}</NuxtLink></td>
            <td>{{ appointment.customer_name }}</td>
            <td>{{ appointment.service_name }}</td>
            <td>{{ appointment.staff_name }}</td>
            <td>{{ statusLabel(appointment.status) }}</td>
            <td>{{ appointment.deposit_status ? statusLabel(appointment.deposit_status) : '—' }}</td>
            <td>
              <div v-if="appointment.status === 'confirmed'" class="row-actions">
                <button type="button" :disabled="actioningId === appointment.id" @click="markStatus(appointment, 'completed')">
                  Mark attended
                </button>
                <button type="button" class="secondary" :disabled="actioningId === appointment.id" @click="markStatus(appointment, 'no_show')">
                  Mark no-show
                </button>
              </div>
              <span v-else class="muted">—</span>
            </td>
          </tr>
        </tbody>
      </table>

      <h2>Past ({{ past.length }})</h2>
      <p v-if="past.length === 0" class="muted">No past appointments.</p>
      <table v-else class="appointments-table">
        <thead>
          <tr>
            <th>When</th>
            <th>Customer</th>
            <th>Service</th>
            <th>Staff</th>
            <th>Status</th>
            <th>Deposit</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="appointment in past" :key="appointment.id">
            <td><NuxtLink :to="`/owner/appointments/${appointment.id}`">{{ formatDateTime(appointment.starts_at) }}</NuxtLink></td>
            <td>{{ appointment.customer_name }}</td>
            <td>{{ appointment.service_name }}</td>
            <td>{{ appointment.staff_name }}</td>
            <td>{{ statusLabel(appointment.status) }}</td>
            <td>{{ appointment.deposit_status ? statusLabel(appointment.deposit_status) : '—' }}</td>
          </tr>
        </tbody>
      </table>
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

.appointments-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 2rem;
  font-size: 0.9rem;
}

.appointments-table th,
.appointments-table td {
  text-align: left;
  padding: 0.5rem 0.75rem;
  border-bottom: 1px solid #eee;
}

.appointments-table a {
  color: #1a1a1a;
}

.row-actions {
  display: flex;
  gap: 0.5rem;
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

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
