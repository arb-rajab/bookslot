<script setup lang="ts">
import type { ConnectStatus, OnboardingLinkResponse } from '~/types/owner'

definePageMeta({ layout: 'owner' })

const route = useRoute()

const status = ref<ConnectStatus | null>(null)
const loading = ref(true)
const loadError = ref<string | null>(null)
const startingOnboarding = ref(false)
const onboardingError = ref<string | null>(null)

/**
 * D-0058: `?onboarding=return`/`?onboarding=refresh` is the query Stripe's
 * hosted flow appends when it sends the owner back here — the real,
 * trustworthy signal is always the live GET .../status read below (Stripe's
 * own guidance: arriving at return_url doesn't itself prove onboarding
 * finished), so this is used only to show a short "welcome back" note, not
 * to skip or short-circuit the status check.
 */
const returnedFromStripe = computed(() => route.query.onboarding === 'return' || route.query.onboarding === 'refresh')

onMounted(loadStatus)

async function loadStatus(): Promise<void> {
  loading.value = true
  loadError.value = null

  try {
    status.value = await apiFetch<ConnectStatus>('/owner/stripe/connect/status')
  } catch (e) {
    loadError.value = describeError(apiErrorBody(e).error)
  } finally {
    loading.value = false
  }
}

const actionLabel = computed(() => {
  if (!status.value) return 'Start onboarding'
  if (status.value.status === 'not_started') return 'Start onboarding with Stripe'
  if (status.value.status === 'complete') return 'Update details on Stripe'
  return 'Continue onboarding with Stripe'
})

/**
 * Real navigation away from this app (Stripe's own hosted onboarding page)
 * — not a fetch this page renders a result from. The same action serves
 * start/resume/refresh per D-0058: it always issues a fresh Account Link
 * for whatever account already exists, creating one first only if none
 * does.
 */
async function startOnboarding(): Promise<void> {
  startingOnboarding.value = true
  onboardingError.value = null

  try {
    const response = await apiFetch<OnboardingLinkResponse>('/owner/stripe/connect/onboarding-link', { method: 'POST' })
    window.location.href = response.url
  } catch (e) {
    onboardingError.value = describeError(apiErrorBody(e).error)
    startingOnboarding.value = false
  }
}

function describeError(code: string): string {
  const messages: Record<string, string> = {
    PAYMENT_PROVIDER_UNAVAILABLE: 'Stripe is temporarily unavailable. Please try again shortly.',
    NETWORK_ERROR: 'Could not reach the booking server. Is the API running?',
  }
  return messages[code] ?? `Something went wrong (${code}).`
}

const statusLabels: Record<ConnectStatus['status'], string> = {
  not_started: 'Not started',
  pending: 'Pending — Stripe still needs more information',
  complete: 'Complete — this studio can receive payouts',
  restricted: 'Restricted — Stripe has flagged this account',
}
</script>

<template>
  <div>
    <h1>Stripe payouts</h1>
    <p class="muted">
      Connect a Stripe account so this studio can receive deposit and balance payouts (Stripe Connect
      Express, D-0058).
    </p>

    <p v-if="returnedFromStripe" class="muted">Welcome back from Stripe — checking the current status…</p>
    <p v-if="loadError" class="error">{{ loadError }}</p>
    <p v-if="loading">Loading…</p>

    <template v-else-if="status">
      <section class="card">
        <h2>Status</h2>
        <p class="status-badge" :class="`status-${status.status}`">{{ statusLabels[status.status] }}</p>
        <dl>
          <dt>Charges enabled</dt><dd>{{ status.charges_enabled ? 'Yes' : 'No' }}</dd>
          <dt>Details submitted</dt><dd>{{ status.details_submitted ? 'Yes' : 'No' }}</dd>
        </dl>

        <p v-if="status.status === 'restricted'" class="warning">
          Stripe has flagged this account and disabled charges. Follow the link below to see what Stripe
          needs before this studio can take payments again.
        </p>

        <p v-if="onboardingError" class="error">{{ onboardingError }}</p>
        <button type="button" :disabled="startingOnboarding" @click="startOnboarding">
          {{ startingOnboarding ? 'Redirecting to Stripe…' : actionLabel }}
        </button>
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

.status-badge {
  display: inline-block;
  font-weight: 600;
  padding: 0.35rem 0.75rem;
  border-radius: 999px;
  margin: 0 0 1rem;
  font-size: 0.9rem;
}

.status-not_started {
  background: #eee;
  color: #444;
}

.status-pending {
  background: #fff8e1;
  color: #7a5b00;
}

.status-complete {
  background: #e6f4ea;
  color: #1e4620;
}

.status-restricted {
  background: #fdecea;
  color: #611a15;
}

dl {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 0.35rem 1rem;
  margin: 0 0 1rem;
}

dt {
  font-weight: 600;
  color: #444;
}

dd {
  margin: 0;
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

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
