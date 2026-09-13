// @vitest-environment nuxt
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { useOwnerSession } from '~/composables/useOwnerSession'

/**
 * `@nuxt/test-utils`'s `registerEndpoint` mocks the CURRENT Nuxt app's own
 * server routes — it has no hook into a `$fetch` call to a genuinely
 * different origin, which is exactly what every call in this app makes
 * (the whole point of D-0002's decoupled API). Rather than fight that
 * mismatch with brittle internals-stubbing, this suite spins up a real,
 * tiny HTTP server as a stand-in for the Laravel API and points
 * `useRuntimeConfig().public` at it — the composable under test makes a
 * REAL network call end to end, same "no mocking" discipline the backend's
 * own Feature test suite already holds itself to, just applied here to a
 * fake server instead of a fake function.
 */
type RouteHandler = (req: import('node:http').IncomingMessage) => { status: number, body: unknown }

let server: Server
let routes: Record<string, RouteHandler>

function route(path: string, handler: RouteHandler): void {
  routes[path] = handler
}

beforeEach(async () => {
  routes = {}
  server = createServer((req, res) => {
    const handler = req.url ? routes[req.url] : undefined
    if (!handler) {
      res.writeHead(404).end()
      return
    }
    const { status, body } = handler(req)
    res.writeHead(status, { 'Content-Type': 'application/json' })
    res.end(JSON.stringify(body))
  })

  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve))
  const { port } = server.address() as AddressInfo

  const config = useRuntimeConfig()
  config.public.apiOrigin = `http://127.0.0.1:${port}`
  config.public.apiBase = `http://127.0.0.1:${port}/api`

  route('/sanctum/csrf-cookie', () => ({ status: 204, body: null }))
})

afterEach(() => new Promise<void>((resolve) => server.close(() => resolve())))

describe('useOwnerSession', () => {
  it('checkSession() lands on authenticated when the probe endpoint succeeds', async () => {
    route('/api/owner/services', () => ({ status: 200, body: { services: [] } }))

    const { authState, checkSession } = useOwnerSession()
    await checkSession()

    expect(authState.value).toBe('authenticated')
  })

  it('checkSession() lands on unauthenticated and clears the current user when the probe 401s', async () => {
    route('/api/owner/services', () => ({ status: 401, body: { error: 'UNAUTHENTICATED' } }))

    const { authState, currentUser, checkSession } = useOwnerSession()
    currentUser.value = { id: '1', role: 'owner', tenant_id: 't1', name: 'Old', email: 'old@example.test' }

    await checkSession()

    expect(authState.value).toBe('unauthenticated')
    expect(currentUser.value).toBeNull()
  })

  it('login() stores the returned user and flips authState to authenticated', async () => {
    route('/api/tenants/demo-studio/login', () => ({
      status: 200,
      body: { user: { id: '42', role: 'owner', tenant_id: 't-42', name: 'Demo Owner', email: 'owner@demo-studio.test' } },
    }))

    const { authState, currentUser, login } = useOwnerSession()
    await login('demo-studio', 'owner@demo-studio.test', 'password')

    expect(authState.value).toBe('authenticated')
    expect(currentUser.value?.email).toBe('owner@demo-studio.test')
  })

  it('login() surfaces INVALID_CREDENTIALS on a 401 without setting authState to authenticated', async () => {
    route('/api/tenants/demo-studio/login', () => ({ status: 401, body: { error: 'INVALID_CREDENTIALS' } }))

    const { authState, login } = useOwnerSession()
    // useState is a shared singleton across this whole test file (a real
    // Nuxt app instance is not re-created per `it()`), so a prior test's
    // successful login can otherwise leak state into this one.
    authState.value = 'unauthenticated'

    await expect(login('demo-studio', 'owner@demo-studio.test', 'wrong-password')).rejects.toThrow()
    expect(authState.value).not.toBe('authenticated')
  })

  it('logout() clears the current user and flips authState to unauthenticated even if the request fails', async () => {
    route('/api/logout', () => ({ status: 500, body: { error: 'SERVER_ERROR' } }))

    const { authState, currentUser, logout } = useOwnerSession()
    currentUser.value = { id: '1', role: 'owner', tenant_id: 't1', name: 'Someone', email: 'someone@example.test' }
    authState.value = 'authenticated'

    await logout()

    expect(authState.value).toBe('unauthenticated')
    expect(currentUser.value).toBeNull()
  })
})
