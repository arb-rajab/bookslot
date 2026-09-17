// @vitest-environment nuxt
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import AvailabilityPage from '~/pages/owner/availability/index.vue'

/**
 * Confirms the "New staff name" form (StaffController::store()'s
 * `display_name` rule) renders a real 422's field-level message inline —
 * this page had three separate forms with no field-error handling at all
 * before this session (staff creation, working-hours save, exceptions).
 */
let server: Server
let routes: Record<string, (req: import('node:http').IncomingMessage) => { status: number, body: unknown }>

beforeEach(async () => {
  routes = {
    '/sanctum/csrf-cookie': () => ({ status: 204, body: null }),
    '/api/owner/staff': () => ({ status: 200, body: { staff: [] } }),
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

describe('pages/owner/availability/index.vue', () => {
  it('shows the staff-creation 422\'s field message inline, next to the name input', async () => {
    routes['/api/owner/staff'] = (req) => {
      if (req.method === 'POST') {
        return {
          status: 422,
          body: { error: 'VALIDATION_FAILED', fields: { display_name: ['The display name field is required.'] } },
        }
      }
      return { status: 200, body: { staff: [] } }
    }

    const wrapper = await mountSuspended(AvailabilityPage)
    await new Promise((resolve) => setTimeout(resolve, 20))

    // A non-blank value so createStaff()'s own trim() guard doesn't
    // short-circuit before the request is ever made — the 422 here
    // simulates a server-side rule the client can't check locally
    // (e.g. a uniqueness constraint), not an empty-string submission.
    await wrapper.find('.add-staff input[type="text"]').setValue('Duplicate Name')
    await wrapper.find('form.add-staff').trigger('submit.prevent')
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(wrapper.text()).toContain('The display name field is required.')
  })
})
