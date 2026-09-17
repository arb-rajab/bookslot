// @vitest-environment nuxt
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import OwnerServicesPage from '~/pages/owner/services/index.vue'

/**
 * Session 32's audit found every owner-admin form discarding the backend's
 * `fields` object from a 422 VALIDATION_FAILED response (see useApi.ts's
 * ApiErrorBody) — only a generic "Please check the highlighted fields"
 * banner was shown, never the actual per-field messages. This confirms the
 * services form (the first one fixed) now renders those messages inline,
 * next to the field they belong to, using the shared useFormErrors()
 * composable.
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
    res.end(body === null ? '' : JSON.stringify(body))
  })
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve))
  const { port } = server.address() as AddressInfo

  const config = useRuntimeConfig()
  config.public.apiOrigin = `http://127.0.0.1:${port}`
  config.public.apiBase = `http://127.0.0.1:${port}/api`

  useOwnerSession().authState.value = 'authenticated'
})

afterEach(() => new Promise<void>((resolve) => server.close(() => resolve())))

describe('owner/services/index.vue', () => {
  it('renders field-level messages from a 422 VALIDATION_FAILED response inline, next to their fields', async () => {
    routes['/api/owner/services'] = (req) => {
      if (req.method === 'POST') {
        return {
          status: 422,
          body: {
            error: 'VALIDATION_FAILED',
            fields: {
              name: ['The name field is required.'],
              buffer_after_minutes: ['The buffer after minutes must be at most 1440.'],
            },
          },
        }
      }
      return { status: 200, body: { services: [] } }
    }

    const wrapper = await mountSuspended(OwnerServicesPage)
    await new Promise((resolve) => setTimeout(resolve, 20))

    await wrapper.find('button[type="button"]').trigger('click') // "Add service"
    await wrapper.find('form.modal').trigger('submit.prevent')
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(wrapper.text()).toContain('Please check the highlighted fields.')
    expect(wrapper.text()).toContain('The name field is required.')
    expect(wrapper.text()).toContain('The buffer after minutes must be at most 1440.')
  })

  it('replaces stale field errors with the latest response on resubmission', async () => {
    let attempt = 0
    routes['/api/owner/services'] = (req) => {
      if (req.method === 'POST') {
        attempt += 1
        if (attempt === 1) {
          return { status: 422, body: { error: 'VALIDATION_FAILED', fields: { name: ['required'], currency: ['invalid'] } } }
        }
        return { status: 422, body: { error: 'VALIDATION_FAILED', fields: { currency: ['invalid'] } } }
      }
      return { status: 200, body: { services: [] } }
    }

    const wrapper = await mountSuspended(OwnerServicesPage)
    await new Promise((resolve) => setTimeout(resolve, 20))

    await wrapper.find('button[type="button"]').trigger('click')
    await wrapper.find('form.modal').trigger('submit.prevent')
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(wrapper.text()).toContain('required')

    await wrapper.find('form.modal').trigger('submit.prevent')
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(wrapper.text()).not.toContain('required')
    expect(wrapper.text()).toContain('invalid')
  })
})
