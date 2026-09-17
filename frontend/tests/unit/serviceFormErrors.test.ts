// @vitest-environment nuxt
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import ServicesPage from '~/pages/owner/services/index.vue'

/**
 * Confirms the "Add service" form renders a real 422's field-level
 * messages inline (D-0012/D-0051's ServiceController.store() validation
 * shape) rather than only a generic banner — the gap this session's audit
 * found across every owner-admin form (see useFormErrors.test.ts for the
 * composable's own unit coverage).
 */
let server: Server
let routes: Record<string, (req: import('node:http').IncomingMessage) => { status: number, body: unknown }>

beforeEach(async () => {
  routes = {
    '/sanctum/csrf-cookie': () => ({ status: 204, body: null }),
    '/api/owner/services': () => ({ status: 200, body: { services: [] } }),
  }
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

  useOwnerSession().authState.value = 'authenticated'
})

afterEach(() => new Promise<void>((resolve) => server.close(() => resolve())))

describe('pages/owner/services/index.vue', () => {
  it('shows field-level messages from a 422 response inline on the matching inputs', async () => {
    routes['/api/owner/services'] = (req) => {
      if (req.method === 'POST') {
        return {
          status: 422,
          body: {
            error: 'VALIDATION_FAILED',
            fields: {
              name: ['The name field is required.'],
              price_amount: ['The price amount must be at least 0.'],
            },
          },
        }
      }
      return { status: 200, body: { services: [] } }
    }

    const wrapper = await mountSuspended(ServicesPage)

    await wrapper.find('button').trigger('click') // "Add service"
    await new Promise((resolve) => setTimeout(resolve, 10))

    await wrapper.find('form.modal').trigger('submit.prevent')
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(wrapper.text()).toContain('The name field is required.')
    expect(wrapper.text()).toContain('The price amount must be at least 0.')
    // No redundant generic banner alongside the field-level messages.
    expect(wrapper.text()).not.toContain('Please check the highlighted fields.')
  })
})
