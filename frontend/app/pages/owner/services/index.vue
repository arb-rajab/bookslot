<script setup lang="ts">
import type { OwnerService } from '~/types/owner'

definePageMeta({ layout: 'owner' })

const { authState } = useOwnerSession()

const services = ref<OwnerService[]>([])
const loading = ref(false)
const listError = ref<string | null>(null)

const editingId = ref<string | null>(null)
const { generalError: formError, errorFor, setFromError, clear: clearFormErrors } = useFormErrors()
const saving = ref(false)

const blankForm = () => ({
  name: '',
  duration_minutes: 60,
  price_amount: 0,
  currency: 'usd',
  deposit_type: 'fixed' as 'fixed' | 'percentage',
  deposit_fixed_amount: 0 as number | null,
  deposit_percentage_bps: null as number | null,
  buffer_before_minutes: 0,
  buffer_after_minutes: 0,
})

const form = ref(blankForm())
const showForm = ref(false)

watchEffect(() => {
  if (authState.value === 'authenticated' && services.value.length === 0 && !loading.value) {
    loadServices()
  }
})

async function loadServices(): Promise<void> {
  loading.value = true
  listError.value = null

  try {
    const response = await apiFetch<{ services: OwnerService[] }>('/owner/services')
    services.value = response.services
  } catch (e) {
    listError.value = describeError(apiErrorBody(e).error)
  } finally {
    loading.value = false
  }
}

function startCreate(): void {
  editingId.value = null
  form.value = blankForm()
  clearFormErrors()
  showForm.value = true
}

function startEdit(service: OwnerService): void {
  editingId.value = service.id
  form.value = {
    name: service.name,
    duration_minutes: service.duration_minutes,
    price_amount: service.price_amount,
    currency: service.currency,
    deposit_type: service.deposit_type,
    deposit_fixed_amount: service.deposit_fixed_amount,
    deposit_percentage_bps: service.deposit_percentage_bps,
    buffer_before_minutes: service.buffer_before_minutes,
    buffer_after_minutes: service.buffer_after_minutes,
  }
  clearFormErrors()
  showForm.value = true
}

async function submitForm(): Promise<void> {
  saving.value = true
  clearFormErrors()

  const body: Record<string, unknown> = {
    name: form.value.name,
    duration_minutes: form.value.duration_minutes,
    price_amount: form.value.price_amount,
    currency: form.value.currency,
    deposit_type: form.value.deposit_type,
    buffer_before_minutes: form.value.buffer_before_minutes,
    buffer_after_minutes: form.value.buffer_after_minutes,
  }
  if (form.value.deposit_type === 'fixed') {
    body.deposit_fixed_amount = form.value.deposit_fixed_amount
  } else {
    body.deposit_percentage_bps = form.value.deposit_percentage_bps
  }

  try {
    if (editingId.value) {
      const updated = await apiFetch<OwnerService>(`/owner/services/${editingId.value}`, { method: 'PATCH', body })
      const index = services.value.findIndex((s) => s.id === editingId.value)
      if (index !== -1) services.value[index] = updated
    } else {
      const created = await apiFetch<OwnerService>('/owner/services', { method: 'POST', body })
      services.value.push(created)
    }
    showForm.value = false
  } catch (e) {
    setFromError(e, describeError)
  } finally {
    saving.value = false
  }
}

async function toggleActive(service: OwnerService): Promise<void> {
  try {
    const updated = await apiFetch<OwnerService>(`/owner/services/${service.id}`, {
      method: 'PATCH',
      body: { is_active: !service.is_active },
    })
    const index = services.value.findIndex((s) => s.id === service.id)
    if (index !== -1) services.value[index] = updated
  } catch (e) {
    listError.value = describeError(apiErrorBody(e).error)
  }
}

function describeError(code: string): string {
  const messages: Record<string, string> = {
    VALIDATION_FAILED: 'Please check the highlighted fields.',
    NOT_FOUND: 'That service could not be found.',
    NETWORK_ERROR: 'Could not reach the booking server. Is the API running?',
  }
  return messages[code] ?? `Something went wrong (${code}).`
}

function formatAmount(amount: number, currency: string): string {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency.toUpperCase() }).format(amount / 100)
}
</script>

<template>
  <div>
    <div class="header-row">
      <h1>Services</h1>
      <button type="button" @click="startCreate">Add service</button>
    </div>

    <p v-if="listError" class="error">{{ listError }}</p>
    <p v-if="loading">Loading services…</p>

    <table v-else class="data-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Duration</th>
          <th>Price</th>
          <th>Deposit</th>
          <th>Buffer (before/after)</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="service in services" :key="service.id">
          <td>{{ service.name }}</td>
          <td>{{ service.duration_minutes }} min</td>
          <td>{{ formatAmount(service.price_amount, service.currency) }}</td>
          <td>
            <template v-if="service.deposit_type === 'fixed'">{{ formatAmount(service.deposit_fixed_amount ?? 0, service.currency) }}</template>
            <template v-else>{{ (service.deposit_percentage_bps ?? 0) / 100 }}%</template>
          </td>
          <td>{{ service.buffer_before_minutes }} / {{ service.buffer_after_minutes }} min</td>
          <td>{{ service.is_active ? 'Active' : 'Inactive' }}</td>
          <td class="row-actions">
            <button type="button" class="secondary" @click="startEdit(service)">Edit</button>
            <button type="button" class="secondary" @click="toggleActive(service)">
              {{ service.is_active ? 'Deactivate' : 'Activate' }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="showForm" class="modal-backdrop" @click.self="showForm = false">
      <form class="modal card" @submit.prevent="submitForm">
        <h2>{{ editingId ? 'Edit service' : 'New service' }}</h2>
        <p v-if="formError" class="error">{{ formError }}</p>

        <label>Name<input v-model="form.name" type="text" required :class="{ invalid: errorFor('name') }" /><FieldError :message="errorFor('name')" /></label>
        <label>Duration (minutes)<input v-model.number="form.duration_minutes" type="number" min="1" required :class="{ invalid: errorFor('duration_minutes') }" /><FieldError :message="errorFor('duration_minutes')" /></label>
        <label>Price (cents)<input v-model.number="form.price_amount" type="number" min="0" required :class="{ invalid: errorFor('price_amount') }" /><FieldError :message="errorFor('price_amount')" /></label>
        <label>Currency<input v-model="form.currency" type="text" maxlength="3" required :class="{ invalid: errorFor('currency') }" /><FieldError :message="errorFor('currency')" /></label>

        <label>
          Deposit type
          <select v-model="form.deposit_type" :class="{ invalid: errorFor('deposit_type') }">
            <option value="fixed">Fixed</option>
            <option value="percentage">Percentage</option>
          </select>
          <FieldError :message="errorFor('deposit_type')" />
        </label>
        <label v-if="form.deposit_type === 'fixed'">
          Deposit amount (cents)
          <input v-model.number="form.deposit_fixed_amount" type="number" min="0" required :class="{ invalid: errorFor('deposit_fixed_amount') }" />
          <FieldError :message="errorFor('deposit_fixed_amount')" />
        </label>
        <label v-else>
          Deposit percentage (basis points)
          <input v-model.number="form.deposit_percentage_bps" type="number" min="0" required :class="{ invalid: errorFor('deposit_percentage_bps') }" />
          <FieldError :message="errorFor('deposit_percentage_bps')" />
        </label>

        <label>
          Buffer before (minutes)
          <input v-model.number="form.buffer_before_minutes" type="number" min="0" max="1440" required :class="{ invalid: errorFor('buffer_before_minutes') }" />
          <FieldError :message="errorFor('buffer_before_minutes')" />
        </label>
        <label>
          Buffer after (minutes)
          <input v-model.number="form.buffer_after_minutes" type="number" min="0" max="1440" required :class="{ invalid: errorFor('buffer_after_minutes') }" />
          <FieldError :message="errorFor('buffer_after_minutes')" />
        </label>

        <div class="modal-actions">
          <button type="button" class="secondary" @click="showForm = false">Cancel</button>
          <button type="submit" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
        </div>
      </form>
    </div>
  </div>
</template>

<style scoped>
.header-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
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

input.invalid,
select.invalid {
  border-color: #b3261e;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}

.data-table th,
.data-table td {
  text-align: left;
  padding: 0.5rem 0.75rem;
  border-bottom: 1px solid #eee;
}

.row-actions {
  display: flex;
  gap: 0.5rem;
}

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 3rem 1rem;
  overflow-y: auto;
}

.modal {
  background: #fff;
  border-radius: 10px;
  padding: 1.5rem;
  width: 100%;
  max-width: 420px;
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.modal label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.9rem;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  margin-top: 0.5rem;
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
