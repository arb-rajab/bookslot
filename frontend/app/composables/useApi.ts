import { FetchError } from 'ofetch'

/**
 * The real Laravel API (05-api-contracts.md) this app calls — no mocking,
 * per this session's own verification standard. Every error path the
 * backend defines is a structured JSON body ({"error": "CODE", ...}), not
 * a generic HTTP status, so callers can branch on `error.data.error`
 * rather than parsing prose.
 */
export interface ApiErrorBody {
  error: string
  fields?: Record<string, string[]>
}

export function apiUrl(path: string): string {
  const config = useRuntimeConfig()
  return `${config.public.apiBase}${path}`
}

/**
 * D-0029: Sanctum SPA (stateful/cookie) mode applies its session + CSRF
 * middleware group to every /api/* request from a configured stateful
 * origin (config/sanctum.php) — not only authenticated ones. Found by
 * actually issuing a cross-origin POST against the real API: an
 * unauthenticated booking-creation request from this app's own origin
 * still comes back 419 "CSRF token mismatch" without this, since Laravel's
 * VerifyCsrfToken checks every state-changing method regardless of auth
 * state. The fix is Sanctum's own documented SPA pattern — GET
 * /sanctum/csrf-cookie once to receive the XSRF-TOKEN cookie, then echo it
 * back as X-XSRF-TOKEN on state-changing requests — which ofetch does not
 * do automatically the way axios does, so it's done explicitly here.
 */
let csrfCookiePromise: Promise<void> | null = null

async function ensureCsrfCookie(): Promise<void> {
  if (!import.meta.client) return

  csrfCookiePromise ??= $fetch(`${useRuntimeConfig().public.apiOrigin}/sanctum/csrf-cookie`, {
    credentials: 'include',
  }) as Promise<void>

  await csrfCookiePromise
}

function readCookie(name: string): string | null {
  if (!import.meta.client) return null

  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
  return match ? decodeURIComponent(match[1] ?? '') : null
}

export async function apiFetch<T>(path: string, options: Parameters<typeof $fetch>[1] = {}): Promise<T> {
  const method = (options.method ?? 'GET').toString().toUpperCase()
  const isMutating = method !== 'GET' && method !== 'HEAD'

  if (isMutating) {
    await ensureCsrfCookie()
  }

  const headers: Record<string, string> = { ...(options.headers as Record<string, string> | undefined ?? {}) }
  const xsrfToken = isMutating ? readCookie('XSRF-TOKEN') : null
  if (xsrfToken) {
    headers['X-XSRF-TOKEN'] = xsrfToken
  }

  return await $fetch<T>(apiUrl(path), {
    credentials: 'include',
    ...options,
    headers,
  })
}

/** Narrows a caught error to the backend's structured JSON body, or a generic message if the call never reached the API at all. */
export function apiErrorBody(error: unknown): ApiErrorBody {
  if (error instanceof FetchError && error.data && typeof error.data === 'object' && 'error' in error.data) {
    return error.data as ApiErrorBody
  }

  return { error: 'NETWORK_ERROR' }
}
