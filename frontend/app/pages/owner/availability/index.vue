<script setup lang="ts">
import type { AvailabilityException, OwnerStaff, WorkingHour } from '~/types/owner'

definePageMeta({ layout: 'owner' })

const { authState } = useOwnerSession()

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

const staff = ref<OwnerStaff[]>([])
const loadingStaff = ref(false)
const staffError = ref<string | null>(null)

const newStaffName = ref('')
const creatingStaff = ref(false)

const selectedStaffId = ref<string | null>(null)

type DayRow = { enabled: boolean, start_time: string, end_time: string }
const week = ref<DayRow[]>(DAY_NAMES.map(() => ({ enabled: false, start_time: '09:00', end_time: '17:00' })))
const loadingHours = ref(false)
const savingHours = ref(false)
const hoursError = ref<string | null>(null)

const exceptions = ref<AvailabilityException[]>([])
const loadingExceptions = ref(false)
const exceptionError = ref<string | null>(null)
const newException = ref({ date: '', is_available: false, reason: '' })

watchEffect(() => {
  if (authState.value === 'authenticated' && staff.value.length === 0 && !loadingStaff.value) {
    loadStaff()
  }
})

async function loadStaff(): Promise<void> {
  loadingStaff.value = true
  staffError.value = null

  try {
    const response = await apiFetch<{ staff: OwnerStaff[] }>('/owner/staff')
    staff.value = response.staff
    if (!selectedStaffId.value && staff.value.length > 0) {
      selectStaff(staff.value[0]!.id)
    }
  } catch (e) {
    staffError.value = describeError(apiErrorBody(e).error)
  } finally {
    loadingStaff.value = false
  }
}

async function createStaff(): Promise<void> {
  if (!newStaffName.value.trim()) return
  creatingStaff.value = true
  staffError.value = null

  try {
    const created = await apiFetch<OwnerStaff>('/owner/staff', { method: 'POST', body: { display_name: newStaffName.value } })
    staff.value.push(created)
    newStaffName.value = ''
    selectStaff(created.id)
  } catch (e) {
    staffError.value = describeError(apiErrorBody(e).error)
  } finally {
    creatingStaff.value = false
  }
}

async function selectStaff(id: string): Promise<void> {
  selectedStaffId.value = id
  await Promise.all([loadWorkingHours(id), loadExceptions(id)])
}

async function loadWorkingHours(staffId: string): Promise<void> {
  loadingHours.value = true
  hoursError.value = null
  week.value = DAY_NAMES.map(() => ({ enabled: false, start_time: '09:00', end_time: '17:00' }))

  try {
    const response = await apiFetch<{ working_hours: WorkingHour[] }>(`/owner/staff/${staffId}/working-hours`)
    for (const hour of response.working_hours) {
      week.value[hour.day_of_week] = { enabled: true, start_time: hour.start_time.slice(0, 5), end_time: hour.end_time.slice(0, 5) }
    }
  } catch (e) {
    hoursError.value = describeError(apiErrorBody(e).error)
  } finally {
    loadingHours.value = false
  }
}

async function saveWorkingHours(): Promise<void> {
  if (!selectedStaffId.value) return
  savingHours.value = true
  hoursError.value = null

  const workingHours = week.value
    .map((row, day_of_week) => ({ ...row, day_of_week }))
    .filter((row) => row.enabled)
    .map(({ day_of_week, start_time, end_time }) => ({ day_of_week, start_time, end_time }))

  try {
    await apiFetch(`/owner/staff/${selectedStaffId.value}/working-hours`, {
      method: 'PUT',
      body: { working_hours: workingHours },
    })
  } catch (e) {
    hoursError.value = describeError(apiErrorBody(e).error)
  } finally {
    savingHours.value = false
  }
}

async function loadExceptions(staffId: string): Promise<void> {
  loadingExceptions.value = true
  exceptionError.value = null

  try {
    const response = await apiFetch<{ availability_exceptions: AvailabilityException[] }>(`/owner/staff/${staffId}/availability-exceptions`)
    exceptions.value = response.availability_exceptions
  } catch (e) {
    exceptionError.value = describeError(apiErrorBody(e).error)
  } finally {
    loadingExceptions.value = false
  }
}

async function addException(): Promise<void> {
  if (!selectedStaffId.value || !newException.value.date) return
  exceptionError.value = null

  try {
    const created = await apiFetch<AvailabilityException>(`/owner/staff/${selectedStaffId.value}/availability-exceptions`, {
      method: 'POST',
      body: { date: newException.value.date, is_available: newException.value.is_available, reason: newException.value.reason || null },
    })
    exceptions.value.push(created)
    newException.value = { date: '', is_available: false, reason: '' }
  } catch (e) {
    exceptionError.value = describeError(apiErrorBody(e).error)
  }
}

async function deleteException(exception: AvailabilityException): Promise<void> {
  if (!selectedStaffId.value) return

  try {
    await apiFetch(`/owner/staff/${selectedStaffId.value}/availability-exceptions/${exception.id}`, { method: 'DELETE' })
    exceptions.value = exceptions.value.filter((e) => e.id !== exception.id)
  } catch (e) {
    exceptionError.value = describeError(apiErrorBody(e).error)
  }
}

function describeError(code: string): string {
  const messages: Record<string, string> = {
    VALIDATION_FAILED: 'Please check the highlighted fields.',
    NOT_FOUND: 'That staff member could not be found.',
    NETWORK_ERROR: 'Could not reach the booking server. Is the API running?',
  }
  return messages[code] ?? `Something went wrong (${code}).`
}
</script>

<template>
  <div>
    <h1>Availability</h1>

    <p v-if="staffError" class="error">{{ staffError }}</p>

    <div class="layout">
      <aside class="staff-list">
        <h2>Staff</h2>
        <p v-if="loadingStaff">Loading…</p>
        <p v-else-if="staff.length === 0" class="muted">No staff yet — add one below.</p>
        <ul>
          <li v-for="member in staff" :key="member.id">
            <button
              type="button"
              class="staff-button"
              :class="{ active: member.id === selectedStaffId }"
              @click="selectStaff(member.id)"
            >
              {{ member.display_name }}<span v-if="!member.is_active" class="muted"> (inactive)</span>
            </button>
          </li>
        </ul>
        <form class="add-staff" @submit.prevent="createStaff">
          <input v-model="newStaffName" type="text" placeholder="New staff name" />
          <button type="submit" :disabled="creatingStaff">Add</button>
        </form>
      </aside>

      <section v-if="selectedStaffId" class="detail">
        <div class="card">
          <h2>Weekly working hours</h2>
          <p v-if="hoursError" class="error">{{ hoursError }}</p>
          <p v-if="loadingHours">Loading…</p>
          <template v-else>
            <div v-for="(day, index) in week" :key="index" class="day-row">
              <label class="day-toggle">
                <input v-model="day.enabled" type="checkbox" />
                {{ DAY_NAMES[index] }}
              </label>
              <template v-if="day.enabled">
                <input v-model="day.start_time" type="time" />
                <span>to</span>
                <input v-model="day.end_time" type="time" />
              </template>
            </div>
            <button type="button" :disabled="savingHours" @click="saveWorkingHours">
              {{ savingHours ? 'Saving…' : 'Save working hours' }}
            </button>
          </template>
        </div>

        <div class="card">
          <h2>One-off exceptions (holidays, closures)</h2>
          <p v-if="exceptionError" class="error">{{ exceptionError }}</p>
          <p v-if="loadingExceptions">Loading…</p>
          <table v-else-if="exceptions.length > 0" class="data-table">
            <thead><tr><th>Date</th><th>Available?</th><th>Reason</th><th></th></tr></thead>
            <tbody>
              <tr v-for="exception in exceptions" :key="exception.id">
                <td>{{ exception.date }}</td>
                <td>{{ exception.is_available ? 'Available' : 'Closed' }}</td>
                <td>{{ exception.reason ?? '—' }}</td>
                <td><button type="button" class="secondary" @click="deleteException(exception)">Remove</button></td>
              </tr>
            </tbody>
          </table>
          <p v-else class="muted">No exceptions on record.</p>

          <form class="add-exception" @submit.prevent="addException">
            <input v-model="newException.date" type="date" required />
            <label><input v-model="newException.is_available" type="checkbox" /> Available (unusual open day)</label>
            <input v-model="newException.reason" type="text" placeholder="Reason (optional)" />
            <button type="submit">Add exception</button>
          </form>
        </div>
      </section>
    </div>
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

.layout {
  display: flex;
  gap: 2rem;
  align-items: flex-start;
  flex-wrap: wrap;
}

.staff-list {
  min-width: 220px;
}

.staff-list ul {
  list-style: none;
  padding: 0;
  margin: 0 0 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.staff-button {
  width: 100%;
  text-align: left;
  background: none;
  border: none;
  color: #1a1a1a;
  padding: 0.4rem 0.5rem;
  border-radius: 6px;
  cursor: pointer;
}

.staff-button.active {
  background: #f0f0f0;
  font-weight: 600;
}

.add-staff {
  display: flex;
  gap: 0.5rem;
}

.detail {
  flex: 1;
  min-width: 320px;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.card {
  border: 1px solid #eee;
  border-radius: 8px;
  padding: 1rem 1.25rem;
}

.day-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.35rem 0;
}

.day-toggle {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 130px;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
  margin-bottom: 1rem;
}

.data-table th,
.data-table td {
  text-align: left;
  padding: 0.4rem 0.6rem;
  border-bottom: 1px solid #eee;
}

.add-exception {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
}

input[type='text'],
input[type='date'] {
  padding: 0.4rem;
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
