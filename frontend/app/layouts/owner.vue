<script setup lang="ts">
const { authState, currentUser, checkSession, login, logout } = useOwnerSession()

const loginSlug = ref('')
const loginEmail = ref('')
const loginPassword = ref('')
const loginError = ref<string | null>(null)
const loggingIn = ref(false)

onMounted(() => {
  if (authState.value === 'checking') checkSession()
})

async function submitLogin(): Promise<void> {
  loggingIn.value = true
  loginError.value = null

  try {
    await login(loginSlug.value, loginEmail.value, loginPassword.value)
  } catch (e) {
    loginError.value = describeError(apiErrorBody(e).error)
  } finally {
    loggingIn.value = false
  }
}

async function submitLogout(): Promise<void> {
  await logout()
  await navigateTo('/owner')
}

function describeError(code: string): string {
  const messages: Record<string, string> = {
    INVALID_CREDENTIALS: 'Incorrect email or password for that studio.',
    NETWORK_ERROR: 'Could not reach the booking server. Is the API running?',
  }
  return messages[code] ?? `Something went wrong (${code}).`
}

const navLinks = [
  { to: '/owner/appointments', label: 'Appointments' },
  { to: '/owner/services', label: 'Services' },
  { to: '/owner/availability', label: 'Availability' },
  { to: '/owner/notifications', label: 'Reminders' },
  { to: '/owner/queue-health', label: 'Queue health' },
]
</script>

<template>
  <main class="page">
    <section v-if="authState === 'unauthenticated'" class="login-section">
      <h1>Owner dashboard</h1>
      <p class="muted">Log in with your studio's slug and your owner account.</p>
      <p v-if="loginError" class="error">{{ loginError }}</p>

      <form class="login-form" @submit.prevent="submitLogin">
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

    <p v-else-if="authState === 'checking'">Checking session…</p>

    <div v-else class="owner-shell">
      <header class="owner-header">
        <nav class="owner-nav">
          <NuxtLink v-for="link in navLinks" :key="link.to" :to="link.to">{{ link.label }}</NuxtLink>
        </nav>
        <div class="owner-header-right">
          <span v-if="currentUser" class="muted">{{ currentUser.name }} ({{ currentUser.email }})</span>
          <button type="button" class="link" @click="submitLogout">Log out</button>
        </div>
      </header>

      <slot />
    </div>
  </main>
</template>

<style scoped>
.page {
  max-width: 1080px;
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

.owner-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 1.5rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid #eee;
}

.owner-nav {
  display: flex;
  gap: 1.25rem;
  flex-wrap: wrap;
}

.owner-nav a {
  color: #1a1a1a;
  text-decoration: none;
  font-size: 0.9rem;
  font-weight: 600;
}

.owner-nav a:hover,
.owner-nav a.router-link-active {
  text-decoration: underline;
}

.owner-header-right {
  display: flex;
  align-items: center;
  gap: 1rem;
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

button.link {
  border: none;
  background: none;
  color: #333;
  text-decoration: underline;
  padding: 0;
  font-size: 0.9rem;
}
</style>
