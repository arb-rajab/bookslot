import type { OwnerUser } from '~/types/booking'

/**
 * Shared auth/session state across every /owner/* page. Previously this
 * lived duplicated inline in owner/index.vue, which crammed every owner
 * capability into one file — splitting the admin surface into dedicated
 * pages (services, availability, appointments, queue-health) means
 * login/logout/session-check needs to be one composable, not copy-pasted
 * five times.
 *
 * `useState` (not a plain module-level `ref`) so every page component gets
 * the SAME reactive instance within one client session — this is a
 * client-side-only, authenticated surface (05-api-contracts.md's own
 * rendering-strategy table), so there's no SSR-hydration concern here, just
 * the ordinary Nuxt composable-sharing mechanism.
 */
export type AuthState = 'checking' | 'unauthenticated' | 'authenticated'

export function useOwnerSession() {
  const authState = useState<AuthState>('owner-auth-state', () => 'checking')
  const currentUser = useState<OwnerUser | null>('owner-current-user', () => null)

  /**
   * No dedicated `GET /api/me` endpoint exists in this codebase — probing
   * with a real, cheap, already-tenant-scoped owner endpoint is what the
   * original owner/index.vue did (via loadAppointments()) and is preserved
   * here as the one shared mechanism every page's onMounted() calls,
   * rather than each page inventing its own probe request.
   */
  async function checkSession(): Promise<void> {
    try {
      await apiFetch('/owner/services')
      authState.value = 'authenticated'
    } catch {
      authState.value = 'unauthenticated'
      currentUser.value = null
    }
  }

  async function login(slug: string, email: string, password: string): Promise<void> {
    const response = await apiFetch<{ user: OwnerUser }>(`/tenants/${slug}/login`, {
      method: 'POST',
      body: { email, password },
    })
    currentUser.value = response.user
    authState.value = 'authenticated'
  }

  /**
   * Deliberately never throws: local session state is cleared regardless
   * of whether the server-side call succeeds — a failed `/logout` request
   * (already-expired session, a transient 500) must not strand the caller
   * mid-navigation with an unhandled rejection instead of returning to the
   * login screen, which is the whole point of calling logout in the first
   * place.
   */
  async function logout(): Promise<void> {
    try {
      await apiFetch('/logout', { method: 'POST' })
    } catch {
      // Local state is cleared below regardless of the reason.
    } finally {
      currentUser.value = null
      authState.value = 'unauthenticated'
    }
  }

  return { authState, currentUser, checkSession, login, logout }
}
