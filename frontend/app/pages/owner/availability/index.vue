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
const {
  formError: staffFormError,
  fieldError: staffFieldError,
  clear: clearStaffFormErrors,
  applyError: applyStaffFormError,
} = useFormErrors()

const selectedStaffId = ref<string | null>(null)

type DayRow = { enabled: boolean, start_time: string, end_time: string }
const week = ref<DayRow[]>(DAY_NAMES.map(() => ({ enabled: false, start_time: '09:00', end_time: '17:00' })))
const loadingHours = ref(false)
const savingHours = ref(false)
const hoursError = ref<string | null>(null)
const {
  formError: hoursFormError,
  fieldError: hoursFieldError,
  otherFieldErrors: otherHoursFieldErrors,
  clear: clearHoursFormErrors,
  applyError: applyHoursFormError,
} = useFormErrors()

const exceptions = ref<AvailabilityException[]>([])
const loadingExceptions = ref(false)
const exceptionError = ref<string | null>(null)
const newException = ref({ date: '', is_available: false, reason: '' })
const {
  formError: exceptionFormError,
  fieldError: exceptionFieldError,
  otherFieldErrors: otherExceptionFieldErrors,
  clear: clearExceptionFormErrors,
  applyError: applyExceptionFormError,
} = useFormErrors()
const KNOWN_EXCEPTION_FIELDS = ['date', 'is_available', 'start_time', 'end_time', 'reason']

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
  clearStaffFormErrors()

  try {
    const created = await apiFetch<OwnerStaff>('/owner/staff', { method: 'POST', body: { display_name: newStaffName.value } })
    staff.value.push(created)
    newStaffName.value = ''
    selectStaff(created.id)
  } catch (e) {
    applyStaffFormError(e, describeError)
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

/**
 * The PUT body only carries enabled days, in week order — its own index
 * (0, 1, 2, …) is what the backend's `working_hours.{index}.{field}`
 * validation-error keys refer to, which is NOT the same as `day_of_week`
 * once any day is disabled. This maps a visible day-of-week index back to
 * its position in that submitted array so an error for, say, the second
 * enabled day lands on the right row rather than the wrong one.
 */
const enabledDayIndexes = computed(() => week.value.map((row, day) => (row.enabled ? day : null)).filter((day): day is number => day !== null))

function workingHourFieldError(dayOfWeek: number, field: 'start_time' | 'end_time'): string | null {
  const payloadIndex = enabledDayIndexes.value.indexOf(dayOfWeek)
  return payloadIndex === -1 ? null : hoursFieldError(`working_hours.${payloadIndex}.${field}`)
}

function knownHoursFields(): string[] {
  return ['working_hours', ...enabledDayIndexes.value.flatMap((_, index) => [
    `working_hours.${index}.day_of_week`,
    `working_hours.${index}.start_time`,
    `working_hours.${index}.end_time`,
  ])]
}

async function saveWorkingHours(): Promise<void> {
  if (!selectedStaffId.value) return
  savingHours.value = true
  clearHoursFormErrors()

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
    applyHoursFormError(e, describeError)
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
  clearExceptionFormErrors()

  try {
    const created = await apiFetch<AvailabilityException>(`/owner/staff/${selectedStaffId.value}/availability-exceptions`, {
      method: 'POST',
      body: { date: newException.value.date, is_available: newException.value.is_available, reason: newException.value.reason || null },
    })
    exceptions.value.push(created)
    newException.value = { date: '', is_available: false, reason: '' }
  } catch (e) {
    applyExceptionFormError(e, describeError)
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
        <p v-if="staffFormError" class="error">{{ staffFormError }}</p>
        <span v-if="staffFieldError('display_name')" class="field-error">{{ staffFieldError('display_name') }}</span>
      </aside>

      <section v-if="selectedStaffId" class="detail">
        <div class="card">
          <h2>Weekly working hours</h2>
          <p v-if="hoursError" class="error">{{ hoursError }}</p>
          <p v-if="loadingHours">Loading…</p>
          <template v-else>
            <p v-if="hoursFormError" class="error">{{ hoursFormError }}</p>
            <ul v-if="otherHoursFieldErrors(knownHoursFields()).length > 0" class="error field-error-list">
              <li v-for="message in otherHoursFieldErrors(knownHoursFields())" :key="message">{{ message }}</li>
            </ul>
            <div v-for="(day, index) in week" :key="index" class="day-row">
              <label class="day-toggle">
                <input v-model="day.enabled" type="checkbox" />
                {{ DAY_NAMES[index] }}
              </label>
              <template v-if="day.enabled">
                <div class="day-times">
                  <div class="day-time-field">
                    <input v-model="day.start_time" type="time" />
                    <span v-if="workingHourFieldError(index, 'start_time')" class="field-error">{{ workingHourFieldError(index, 'start_time') }}</span>
                  </div>
                  <span>to</span>
                  <div class="day-time-field">
                    <input v-model="day.end_time" type="time" />
                    <span v-if="workingHourFieldError(index, 'end_time')" class="field-error">{{ workingHourFieldError(index, 'end_time') }}</span>
                  </div>
                </div>
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

          <p v-if="exceptionFormError" class="error">{{ exceptionFormError }}</p>
          <ul v-if="otherExceptionFieldErrors(KNOWN_EXCEPTION_FIELDS).length > 0" class="error field-error-list">
            <li v-for="message in otherExceptionFieldErrors(KNOWN_EXCEPTION_FIELDS)" :key="message">{{ message }}</li>
          </ul>
          <form class="add-exception" @submit.prevent="addException">
            <div class="field-with-error">
              <input v-model="newException.date" type="date" required />
              <span v-if="exceptionFieldError('date')" class="field-error">{{ exceptionFieldError('date') }}</span>
            </div>
            <label><input v-model="newException.is_available" type="checkbox" /> Available (unusual open day)</label>
            <div class="field-with-error">
              <input v-model="newException.reason" type="text" placeholder="Reason (optional)" />
              <span v-if="exceptionFieldError('reason')" class="field-error">{{ exceptionFieldError('reason') }}</span>
            </div>
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

.field-error-list {
  margin: 0.5rem 0;
  padding-left: 1.25rem;
}

.field-error {
  color: #b3261e;
  font-size: 0.85rem;
}

.field-with-error {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.day-times {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.day-time-field {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
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
