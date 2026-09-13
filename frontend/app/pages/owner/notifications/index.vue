<script setup lang="ts">
import type { NotificationLogEntry } from '~/types/owner'

definePageMeta({ layout: 'owner' })

const { authState } = useOwnerSession()

const notifications = ref<NotificationLogEntry[]>([])
const loading = ref(false)
const listError = ref<string | null>(null)
const statusFilter = ref('')

watchEffect(() => {
  if (authState.value === 'authenticated' && !loading.value && notifications.value.length === 0) {
    loadNotifications()
  }
})

async function loadNotifications(): Promise<void> {
  loading.value = true
  listError.value = null

  try {
    const query = statusFilter.value ? `?status=${statusFilter.value}` : ''
    const response = await apiFetch<{ notifications: NotificationLogEntry[] }>(`/owner/notifications${query}`)
    notifications.value = response.notifications
  } catch (e) {
    listError.value = describeError(apiErrorBody(e).error)
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

function formatDateTime(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <div>
    <div class="header-row">
      <h1>Reminder deliveries</h1>
      <label class="filter">
        Status
        <select v-model="statusFilter" @change="loadNotifications">
          <option value="">All</option>
          <option value="scheduled">Scheduled</option>
          <option value="sent">Sent</option>
          <option value="failed">Failed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </label>
    </div>

    <p v-if="listError" class="error">{{ listError }}</p>
    <p v-if="loading">Loading…</p>
    <p v-else-if="notifications.length === 0" class="muted">No reminder deliveries on record.</p>

    <table v-else class="data-table">
      <thead>
        <tr><th>Customer</th><th>Purpose</th><th>Channel</th><th>Scheduled for</th><th>Sent at</th><th>Status</th></tr>
      </thead>
      <tbody>
        <tr v-for="notification in notifications" :key="notification.id">
          <td>
            <NuxtLink :to="`/owner/appointments/${notification.appointment_id}`">{{ notification.customer_name ?? '—' }}</NuxtLink>
          </td>
          <td>{{ notification.purpose }}</td>
          <td>{{ notification.channel }}</td>
          <td>{{ formatDateTime(notification.scheduled_for) }}</td>
          <td>{{ formatDateTime(notification.sent_at) }}</td>
          <td>{{ notification.status }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.header-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}

.filter {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
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

.data-table a {
  color: #1a1a1a;
}
</style>
