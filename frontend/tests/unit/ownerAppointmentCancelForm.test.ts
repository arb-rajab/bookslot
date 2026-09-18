// @vitest-environment nuxt
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import OwnerAppointmentDetailPage from '~/pages/owner/appointments/[id].vue'
import { flushUntil } from './support/waitFor'

/**
 * The appointment-cancellation form was the third owner-admin form this
 * session's audit found showing only a generic banner on a 422, never the
 * `reason` field's own message. `route.params.id` isn't set by
 * mountSuspended in this test setup (no router navigation happens), so the
 * page's `appointmentId` is the literal string "undefined" — harmless
 * here since the test only cares about how a 422 from the cancel POST is
 * rendered, not which specific appointment id was requested.
 */
let server: Server
let routes: Record<string, (req: import('node:http').IncomingMessage) => { status: number, body: unknown }>

const detail = {
  id: 'undefined',
  status: 'confirmed',
  starts_at: '2026-01-01T10:00:00Z',
  ends_at: '2026-01-01T11:00:00Z',
  customer_name: 'Jane Doe',
  customer_email: 'jane@example.test',
  customer_phone: null,
  service_name: 'Haircut',
  staff_name: 'Alex',
  deposit_status: null,
  notes: null,
  cancelled_by: null,
  cancelled_reason: null,
  cancelled_at: null,
  payments: [],
  reminders: [],
  events: [],
}

beforeEach(async () => {
  routes = {
    '/sanctum/csrf-cookie': () => ({ status: 204, body: null }),
    '/api/owner/appointments/undefined': () => ({ status: 200, body: detail }),
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
})

afterEach(() => new Promise<void>((resolve) => server.close(() => resolve())))

describe('owner/appointments/[id].vue', () => {
  it('shows the reason field\'s own message inline on a 422 from the cancel endpoint', async () => {
    routes['/api/owner/appointments/undefined/cancel'] = () => ({
      status: 422,
      body: { error: 'VALIDATION_FAILED', fields: { reason: ['The reason may not be greater than 255 characters.'] } },
    })

    const wrapper = await mountSuspended(OwnerAppointmentDetailPage)
    await flushUntil(() => wrapper.find('.cancel-form input').exists())

    await wrapper.find('.cancel-form input').setValue('x'.repeat(300))
    await wrapper.find('button.danger').trigger('click')
    await flushUntil(() => wrapper.text().includes('Please check the highlighted fields.'))

    expect(wrapper.text()).toContain('Please check the highlighted fields.')
    expect(wrapper.text()).toContain('The reason may not be greater than 255 characters.')
  })
})
