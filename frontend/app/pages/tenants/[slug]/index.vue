<script setup lang="ts">
import type { BookingResponse, ConfirmPaymentResponse, Mandate, Service, Slot } from '~/types/booking'

const route = useRoute()
const slug = route.params.slug as string

// SSR'd on first load (05-api-contracts.md's rendering-strategy table: the
// public booking page's initial service list is server-rendered, not a
// blank shell that then fetches) — Nuxt renders this on the server for the
// initial request and reuses the same result on hydration.
const { data: servicesData, error: servicesError } = await useAsyncData(
  `services-${slug}`,
  () => apiFetch<{ services: Service[] }>(`/tenants/${slug}/services`),
)
const services = computed(() => servicesData.value?.services ?? [])

type Step = 'services' | 'slots' | 'form' | 'payment' | 'result'
const step = ref<Step>('services')
const errorMessage = ref<string | null>(null)

const selectedService = ref<Service | null>(null)
const dateFrom = ref(toDateInput(new Date()))
const dateTo = ref(toDateInput(addDays(new Date(), 14)))
const slots = ref<Slot[]>([])
const loadingSlots = ref(false)
const selectedSlot = ref<Slot | null>(null)

const mandate = ref<Mandate | null>(null)
const loadingMandate = ref(false)

const customerName = ref('')
const customerEmail = ref('')
const customerPhone = ref('')
const mandateAccepted = ref(false)
const submitting = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})

const booking = ref<BookingResponse | null>(null)
const confirming = ref(false)
const confirmation = ref<ConfirmPaymentResponse | null>(null)

const slotsByDate = computed(() => {
  const groups = new Map<string, Slot[]>()
  for (const slot of slots.value) {
    const key = new Date(slot.starts_at).toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' })
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key)!.push(slot)
  }
  return [...groups.entries()]
})

function toDateInput(date: Date): string {
  return date.toISOString().slice(0, 10)
}

function addDays(date: Date, days: number): Date {
  const copy = new Date(date)
  copy.setDate(copy.getDate() + days)
  return copy
}

function selectService(service: Service): void {
  selectedService.value = service
  slots.value = []
  selectedSlot.value = null
  errorMessage.value = null
  step.value = 'slots'
  loadSlots()
}

async function loadSlots(): Promise<void> {
  if (!selectedService.value) return

  loadingSlots.value = true
  errorMessage.value = null

  try {
    const response = await apiFetch<{ slots: Slot[] }>(`/tenants/${slug}/availability`, {
      query: { service_id: selectedService.value.id, from: dateFrom.value, to: dateTo.value },
    })
    slots.value = response.slots
  } catch (e) {
    errorMessage.value = describeError(apiErrorBody(e).error)
  } finally {
    loadingSlots.value = false
  }
}

async function selectSlot(slot: Slot): Promise<void> {
  if (!selectedService.value) return

  selectedSlot.value = slot
  errorMessage.value = null
  mandate.value = null
  loadingMandate.value = true
  step.value = 'form'

  try {
    // The mandate consent text is always server-rendered (D-0015b/D-0030) —
    // this app never composes or stores that text itself, only displays
    // what the API returns and echoes back its template_version.
    mandate.value = await apiFetch<Mandate>(`/tenants/${slug}/services/${selectedService.value.id}/mandate`)
  } catch (e) {
    errorMessage.value = describeError(apiErrorBody(e).error)
  } finally {
    loadingMandate.value = false
  }
}

async function submitBooking(): Promise<void> {
  if (!selectedService.value || !selectedSlot.value || !mandate.value) return

  submitting.value = true
  errorMessage.value = null
  fieldErrors.value = {}

  try {
    booking.value = await apiFetch<BookingResponse>(`/tenants/${slug}/bookings`, {
      method: 'POST',
      body: {
        service_id: selectedService.value.id,
        staff_id: selectedSlot.value.staff_id,
        starts_at: selectedSlot.value.starts_at,
        customer: {
          name: customerName.value,
          email: customerEmail.value,
          phone: customerPhone.value || null,
        },
        mandate_accepted: mandateAccepted.value,
        mandate_template_version: mandate.value.template_version,
      },
    })
    step.value = 'payment'
  } catch (e) {
    const body = apiErrorBody(e)
    if (body.error === 'VALIDATION_FAILED' && body.fields) {
      fieldErrors.value = body.fields
    }
    if (body.error === 'SLOT_ALREADY_BOOKED') {
      // 05's own defined client behavior: re-fetch availability, don't
      // retry the identical request against a slot that's already gone.
      step.value = 'slots'
      loadSlots()
    }
    errorMessage.value = describeError(body.error)
  } finally {
    submitting.value = false
  }
}

async function confirmPayment(): Promise<void> {
  if (!booking.value) return

  confirming.value = true
  errorMessage.value = null

  try {
    confirmation.value = await apiFetch<ConfirmPaymentResponse>(
      `/bookings/${booking.value.payment_confirmation_token}/confirm-payment`,
      { method: 'POST' },
    )
    step.value = 'result'
  } catch (e) {
    errorMessage.value = describeError(apiErrorBody(e).error)
  } finally {
    confirming.value = false
  }
}

function describeError(code: string): string {
  const messages: Record<string, string> = {
    SLOT_ALREADY_BOOKED: 'That slot was just booked by someone else — pick another time below.',
    VALIDATION_FAILED: 'Please check the highlighted fields.',
    NOT_FOUND: 'That service or slot could not be found.',
    PAYMENT_PROVIDER_UNAVAILABLE: 'The payment provider is unavailable right now. Please try again.',
    BOOKING_UNAVAILABLE: "This studio isn't able to accept online bookings right now. Please contact them directly to book.",
    BOOKING_EXPIRED: 'This booking hold has expired. Please start again.',
    INVALID_OR_EXPIRED_TOKEN: 'This booking link is no longer valid.',
    NETWORK_ERROR: 'Could not reach the booking server. Is the API running?',
  }
  return messages[code] ?? `Something went wrong (${code}).`
}

function startOver(): void {
  step.value = 'services'
  selectedService.value = null
  slots.value = []
  selectedSlot.value = null
  mandate.value = null
  customerName.value = ''
  customerEmail.value = ''
  customerPhone.value = ''
  mandateAccepted.value = false
  fieldErrors.value = {}
  booking.value = null
  confirmation.value = null
  errorMessage.value = null
}

function formatLocalTime(iso: string): string {
  return new Date(iso).toLocaleString(undefined, {
    hour: 'numeric', minute: '2-digit',
  })
}

function formatMoney(amountMinorUnits: number, currency: string): string {
  return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amountMinorUnits / 100)
}
</script>

<template>
  <main class="page">
    <h1>Book an appointment</h1>
    <p class="tenant-slug">{{ slug }}</p>

    <p v-if="servicesError" class="error">
      Could not load services for this studio. Is the API running and is "{{ slug }}" a real tenant slug?
    </p>

    <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

    <!-- Step 1: services -->
    <section v-if="step === 'services'">
      <p v-if="!servicesError && services.length === 0">This studio has no active services yet.</p>
      <ul class="service-list">
        <li v-for="service in services" :key="service.id" class="service-card">
          <div>
            <strong>{{ service.name }}</strong>
            <div class="muted">{{ service.duration_minutes }} min &middot; {{ formatMoney(service.price_amount, service.currency) }}</div>
          </div>
          <button type="button" @click="selectService(service)">Select</button>
        </li>
      </ul>
    </section>

    <!-- Step 2: slot picker -->
    <section v-else-if="step === 'slots' && selectedService">
      <button type="button" class="link" @click="startOver">&larr; Back to services</button>
      <h2>{{ selectedService.name }}</h2>

      <div class="date-range">
        <label>
          From
          <input v-model="dateFrom" type="date" />
        </label>
        <label>
          To
          <input v-model="dateTo" type="date" />
        </label>
        <button type="button" :disabled="loadingSlots" @click="loadSlots">
          {{ loadingSlots ? 'Loading…' : 'Search' }}
        </button>
      </div>

      <p v-if="!loadingSlots && slots.length === 0">No open slots in this date range. Try a wider range.</p>

      <div v-for="[date, daySlots] in slotsByDate" :key="date" class="slot-day">
        <h3>{{ date }}</h3>
        <div class="slot-grid">
          <button
            v-for="slot in daySlots"
            :key="`${slot.staff_id}-${slot.starts_at}`"
            type="button"
            class="slot-button"
            @click="selectSlot(slot)"
          >
            {{ formatLocalTime(slot.starts_at) }}
          </button>
        </div>
      </div>
    </section>

    <!-- Step 3: mandate + booking form -->
    <section v-else-if="step === 'form' && selectedService && selectedSlot">
      <button type="button" class="link" @click="step = 'slots'">&larr; Back to times</button>
      <h2>{{ selectedService.name }}</h2>
      <p class="muted">
        {{ new Date(selectedSlot.starts_at).toLocaleString(undefined, { dateStyle: 'full', timeStyle: 'short' }) }}
      </p>

      <p v-if="loadingMandate">Loading booking terms…</p>

      <form v-else-if="mandate" class="booking-form" @submit.prevent="submitBooking">
        <label>
          Name
          <input v-model="customerName" type="text" required />
          <span v-if="fieldErrors['customer.name']" class="field-error">{{ fieldErrors['customer.name'][0] }}</span>
        </label>
        <label>
          Email
          <input v-model="customerEmail" type="email" required />
          <span v-if="fieldErrors['customer.email']" class="field-error">{{ fieldErrors['customer.email'][0] }}</span>
        </label>
        <label>
          Phone (optional)
          <input v-model="customerPhone" type="tel" />
        </label>

        <div class="mandate">
          <p class="mandate-text">{{ mandate.text }}</p>
        </div>

        <label class="consent">
          <input v-model="mandateAccepted" type="checkbox" required />
          I agree to the terms above.
        </label>
        <span v-if="fieldErrors['mandate_accepted']" class="field-error">{{ fieldErrors['mandate_accepted'][0] }}</span>

        <button type="submit" :disabled="submitting || !mandateAccepted">
          {{ submitting ? 'Booking…' : 'Book and continue to payment' }}
        </button>
      </form>
    </section>

    <!-- Step 4: payment confirmation -->
    <section v-else-if="step === 'payment' && booking">
      <h2>Confirm your deposit</h2>
      <p>Deposit due: <strong>{{ formatMoney(booking.deposit.amount, booking.deposit.currency) }}</strong></p>
      <p class="muted">
        This project's Stripe integration is intentionally never run against real payment infrastructure
        (see D-0036 in this project's decision log) — confirming below calls the real confirm-payment
        endpoint against the fake payment gateway tier that stands in for it.
      </p>
      <button type="button" :disabled="confirming" @click="confirmPayment">
        {{ confirming ? 'Confirming…' : 'Confirm Payment' }}
      </button>
    </section>

    <!-- Step 5: result -->
    <section v-else-if="step === 'result' && confirmation">
      <h2 v-if="confirmation.status === 'confirmed'">Booking confirmed</h2>
      <h2 v-else>Payment not completed</h2>

      <p v-if="confirmation.status === 'confirmed'">
        Your appointment is booked and your deposit was captured.
      </p>
      <template v-else>
        <p>Your slot is still held, but the deposit payment did not go through.</p>
        <p v-if="confirmation.last_payment_error?.message" class="error">
          {{ confirmation.last_payment_error.message }}
        </p>
        <button type="button" :disabled="confirming" @click="confirmPayment">
          {{ confirming ? 'Retrying…' : 'Retry payment' }}
        </button>
      </template>

      <button type="button" class="link" @click="startOver">Book another appointment</button>
    </section>
  </main>
</template>

<style scoped>
.page {
  max-width: 640px;
  margin: 0 auto;
  padding: 2rem 1rem;
  font-family: system-ui, sans-serif;
  color: #1a1a1a;
}

.tenant-slug {
  color: #666;
  margin-top: -0.5rem;
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

.field-error {
  color: #b3261e;
  font-size: 0.85rem;
}

.service-list {
  list-style: none;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.service-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 1rem;
}

.date-range {
  display: flex;
  gap: 1rem;
  align-items: flex-end;
  flex-wrap: wrap;
  margin: 1rem 0;
}

.date-range label {
  display: flex;
  flex-direction: column;
  font-size: 0.85rem;
  gap: 0.25rem;
}

.slot-day {
  margin: 1.5rem 0;
}

.slot-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.slot-button {
  border: 1px solid #ccc;
  border-radius: 6px;
  padding: 0.5rem 0.75rem;
  background: #fff;
  cursor: pointer;
}

.slot-button:hover {
  border-color: #333;
}

.booking-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  margin-top: 1rem;
}

.booking-form label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.mandate {
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 1rem;
  max-height: 220px;
  overflow-y: auto;
  background: #fafafa;
}

.mandate-text {
  white-space: pre-wrap;
  font-size: 0.9rem;
  margin: 0;
}

.consent {
  flex-direction: row !important;
  align-items: center;
  gap: 0.5rem !important;
}

button {
  cursor: pointer;
  border: 1px solid #1a1a1a;
  background: #1a1a1a;
  color: #fff;
  border-radius: 6px;
  padding: 0.6rem 1rem;
  font-size: 1rem;
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
  display: inline-block;
  margin-bottom: 1rem;
}
</style>
