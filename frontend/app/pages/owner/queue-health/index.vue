<script setup lang="ts">
import type { QueueHealth } from '~/types/owner'

definePageMeta({ layout: 'owner' })

const { authState } = useOwnerSession()

const health = ref<QueueHealth | null>(null)
const loading = ref(false)
const loadError = ref<string | null>(null)

watchEffect(() => {
  if (authState.value === 'authenticated' && !loading.value && !health.value) {
    load()
  }
})

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null

  try {
    health.value = await apiFetch<QueueHealth>('/owner/queue-health')
  } catch (e) {
    loadError.value = describeError(apiErrorBody(e).error)
  } finally {
    loading.value = false
  }
}

function describeError(code: string): string {
  const messages: Record<string, string> = {
    NETWORK_ERROR: 'Could not reach the booking server. Is the API running?',
  }
  return messages[code] ?? `Something went wrong (${code}).`
}
</script>

<template>
  <div>
    <div class="header-row">
      <h1>Queue health</h1>
      <button type="button" class="secondary" @click="load">Refresh</button>
    </div>

    <p v-if="loadError" class="error">{{ loadError }}</p>
    <p v-if="loading">Loading…</p>

    <template v-else-if="health">
      <section class="card">
        <h2>RabbitMQ broker</h2>
        <p v-if="!health.broker.available" class="muted">
          Broker management API unavailable — depth/consumer figures below cannot be shown right now.
        </p>
        <dl v-else>
          <dt>Messages ready</dt><dd>{{ health.broker.messages_ready }}</dd>
          <dt>Messages unacknowledged</dt><dd>{{ health.broker.messages_unacknowledged }}</dd>
          <dt>Consumers</dt><dd>{{ health.broker.consumers }}</dd>
        </dl>
      </section>

      <section class="card">
        <h2>Reminder job outcomes</h2>
        <div class="tiles">
          <div class="tile"><span class="tile-value">{{ health.reminders.sent }}</span><span class="tile-label">Sent</span></div>
          <div class="tile"><span class="tile-value">{{ health.reminders.failed }}</span><span class="tile-label">Failed</span></div>
          <div class="tile"><span class="tile-value">{{ health.reminders.scheduled }}</span><span class="tile-label">Scheduled</span></div>
          <div class="tile"><span class="tile-value">{{ health.reminders.cancelled }}</span><span class="tile-label">Cancelled</span></div>
        </div>
      </section>

      <section class="card">
        <h2>Hold-window expiry backlog</h2>
        <p>
          <span class="tile-value">{{ health.pending_payment_appointments }}</span>
          appointment(s) currently awaiting payment or hold-window expiry.
        </p>
      </section>
    </template>
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

.tiles {
  display: flex;
  gap: 1.5rem;
  flex-wrap: wrap;
}

.tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 90px;
}

.tile-value {
  font-size: 1.75rem;
  font-weight: 700;
}

.tile-label {
  color: #666;
  font-size: 0.85rem;
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
</style>
