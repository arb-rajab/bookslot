<script setup lang="ts">
import type { OwnerAppointment, OwnerUser } from '~/types/booking'

// Owner dashboard (05-api-contracts.md endpoint 4, D-0042/D-0043) — the
// first real owner-facing surface. Client-side only, per 05's own
// rendering-strategy table ("Owner/staff dashboard (all of it) |
// Client-side fetch behind auth | No SEO surface") — no useAsyncData/SSR
// fetch here, unlike the public booking page.
type AuthState = 'checking' | 'unauthenticated' | 'authenticated'

const authState = ref<AuthState>('checking')
const currentUser = ref<OwnerUser | null>(null)

const loginSlug = ref('')
const loginEmail = ref('')
const loginPassword = ref('')
const loginError = ref<string | null>(null)
const loggingIn = ref(false)

const appointments = ref<OwnerAppointment[]>([])
const loadingAppointments = ref(false)
const listError = ref<string | null>(null)
const actionError = ref<string | null>(null)
const actioningId = ref<string | null>(null)

const now = Date.now()
const upcoming = computed(() => appointments.value.filter((a) => new Date(a.starts_at).getTime() >= now))
const past = computed(() => appointments.value.filter((a) => new Date(a.starts_at).getTime() < now))

onMounted(() => {
  checkSession()
})

async function checkSession(): Promise<void> {
  try {
    await loadAppointments()
    authState.value = 'authenticated'
  } catch {
    authState.value = 'unauthenticated'
    listError.value = null
  }
}

async function loadAppointments(): Promise<void> {
  loadingAppointments.value = true
  listError.value = null

  try {
    const response = await apiFetch<{ appointments: OwnerAppointment[] }>('/owner/appointments')
    appointments.value = response.appointments
  } finally {
    loadingAppointments.value = false
  }
}

async function login(): Promise<void> {
  loggingIn.value = true
  loginError.value = null

  try {
    const response = await apiFetch<{ user: OwnerUser }>(`/tenants/${loginSlug.value}/login`, {
      method: 'POST',
      body: { email: loginEmail.value, password: loginPassword.value },
    })
    currentUser.value = response.user
    authState.value = 'authenticated'
    await loadAppointments()
  } catch (e) {
    loginError.value = describeError(apiErrorBody(e).error)
  } finally {
    loggingIn.value = false
  }
}

async function logout(): Promise<void> {
  try {
    await apiFetch('/logout', { method: 'POST' })
  } finally {
    currentUser.value = null
    appointments.value = []
    authState.value = 'unauthenticated'
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
    INVALID_CREDENTIALS: 'Incorrect email or password for that studio.',
    UNAUTHENTICATED: 'Please log in again.',
    NOT_FOUND: 'That studio, or that appointment, could not be found.',
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
  <main class="page">
    <h1>Owner dashboard</h1>

    <!-- Not logged in -->
    <section v-if="authState === 'unauthenticated'" class="login-section">
      <p class="muted">Log in with your studio's slug and your owner account.</p>
      <p v-if="loginError" class="error">{{ loginError }}</p>

      <form class="login-form" @submit.prevent="login">
        <label>
          Studio slug
          <input v-model="loginSlug" type="text" required placeholder="demo-studio" />
        </label>
        <label>
          Email
          <input v-model="loginEmail" type="email" required />
        </label>
        <label>
          Password
          <input v-model="loginPassword" type="password" required />
        </label>
        <button type="submit" :disabled="loggingIn">{{ loggingIn ? 'Logging in…' : 'Log in' }}</button>
      </form>
    </section>

    <!-- Checking existing session -->
    <p v-else-if="authState === 'checking'">Checking session…</p>

    <!-- Logged in -->
    <section v-else>
      <div class="dashboard-header">
        <p v-if="currentUser" class="muted">Logged in as {{ currentUser.name }} ({{ currentUser.email }})</p>
        <button type="button" class="link" @click="logout">Log out</button>
      </div>

      <p v-if="listError" class="error">{{ listError }}</p>
      <p v-if="actionError" class="error">{{ actionError }}</p>
      <p v-if="loadingAppointments">Loading appointments…</p>

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
              <td>{{ formatDateTime(appointment.starts_at) }}</td>
              <td>{{ appointment.customer_name }}</td>
              <td>{{ appointment.service_name }}</td>
              <td>{{ appointment.staff_name }}</td>
              <td>{{ statusLabel(appointment.status) }}</td>
              <td>{{ appointment.deposit_status ? statusLabel(appointment.deposit_status) : '—' }}</td>
              <td>
                <div v-if="appointment.status === 'confirmed'" class="row-actions">
                  <button
                    type="button"
                    :disabled="actioningId === appointment.id"
                    @click="markStatus(appointment, 'completed')"
                  >
                    Mark attended
                  </button>
                  <button
                    type="button"
                    class="secondary"
                    :disabled="actioningId === appointment.id"
                    @click="markStatus(appointment, 'no_show')"
                  >
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
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="appointment in past" :key="appointment.id">
              <td>{{ formatDateTime(appointment.starts_at) }}</td>
              <td>{{ appointment.customer_name }}</td>
              <td>{{ appointment.service_name }}</td>
              <td>{{ appointment.staff_name }}</td>
              <td>{{ statusLabel(appointment.status) }}</td>
              <td>{{ appointment.deposit_status ? statusLabel(appointment.deposit_status) : '—' }}</td>
              <td>
                <div v-if="appointment.status === 'confirmed'" class="row-actions">
                  <button
                    type="button"
                    :disabled="actioningId === appointment.id"
                    @click="markStatus(appointment, 'completed')"
                  >
                    Mark attended
                  </button>
                  <button
                    type="button"
                    class="secondary"
                    :disabled="actioningId === appointment.id"
                    @click="markStatus(appointment, 'no_show')"
                  >
                    Mark no-show
                  </button>
                </div>
                <span v-else class="muted">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </template>
    </section>
  </main>
</template>

<style scoped>
.page {
  max-width: 960px;
  margin: 0 auto;
  padding: 2rem 1rem;
  font-family: system-ui, sans-serif;
  color: #1a1a1a;
}

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

.login-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 360px;
}

.login-form label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.dashboard-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
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

button.link {
  border: none;
  background: none;
  color: #333;
  text-decoration: underline;
  padding: 0;
  font-size: 0.9rem;
}
</style>
