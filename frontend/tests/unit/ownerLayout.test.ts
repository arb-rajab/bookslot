// @vitest-environment nuxt
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import OwnerLayout from '~/layouts/owner.vue'

/**
 * layouts/owner.vue is the one component every /owner/* page shares
 * (D-0051's admin-shell refactor) — a real, if easy to introduce, class of
 * bug lives here specifically: this session's own first Playwright run
 * found the entire admin surface rendering blank because app.vue never
 * wrapped `<NuxtPage>` in `<NuxtLayout>` (fixed in app.vue). A component
 * test can't catch that particular wiring gap (mounting the layout
 * directly bypasses the app.vue/NuxtLayout question entirely) — that's
 * exactly why the Playwright suite exists alongside this one, not instead
 * of it. What this test covers instead: the login form's own behavior,
 * which a browser test would only ever exercise indirectly.
 */
let server: Server
let routes: Record<string, (req: import('node:http').IncomingMessage) => { status: number, body: unknown }>

beforeEach(async () => {
  routes = { '/sanctum/csrf-cookie': () => ({ status: 204, body: null }) }
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

  useOwnerSession().authState.value = 'unauthenticated'
})

afterEach(() => new Promise<void>((resolve) => server.close(() => resolve())))

describe('layouts/owner.vue', () => {
  it('shows a login form when unauthenticated, and an incorrect-credentials error on a failed attempt', async () => {
    routes['/api/tenants/wrong-studio/login'] = () => ({ status: 401, body: { error: 'INVALID_CREDENTIALS' } })

    const wrapper = await mountSuspended(OwnerLayout)

    expect(wrapper.find('form.login-form').exists()).toBe(true)

    await wrapper.find('input[type="text"]').setValue('wrong-studio')
    await wrapper.find('input[type="email"]').setValue('owner@example.test')
    await wrapper.find('input[type="password"]').setValue('bad-password')
    await wrapper.find('form.login-form').trigger('submit.prevent')

    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(wrapper.text()).toContain('Incorrect email or password')
  })

  it('renders the nav and hides the login form once authenticated', async () => {
    routes['/api/tenants/demo-studio/login'] = () => ({
      status: 200,
      body: { user: { id: '1', role: 'owner', tenant_id: 't1', name: 'Demo Owner', email: 'owner@demo-studio.test' } },
    })

    const wrapper = await mountSuspended(OwnerLayout)

    await wrapper.find('input[type="text"]').setValue('demo-studio')
    await wrapper.find('input[type="email"]').setValue('owner@demo-studio.test')
    await wrapper.find('input[type="password"]').setValue('password')
    await wrapper.find('form.login-form').trigger('submit.prevent')

    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(wrapper.find('form.login-form').exists()).toBe(false)
    expect(wrapper.text()).toContain('Demo Owner')
    expect(wrapper.text()).toContain('Appointments')
    expect(wrapper.text()).toContain('Queue health')
  })
})
