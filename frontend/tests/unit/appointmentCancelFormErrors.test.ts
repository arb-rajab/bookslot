// @vitest-environment nuxt
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import AppointmentDetailPage from '~/pages/owner/appointments/[id].vue'
import type { OwnerAppointmentDetail } from '~/types/owner'

/**
 * Confirms the "Cancel this appointment" form renders a 422's field-level
 * message inline, same pattern as the services/availability forms — this
 * page previously collapsed every cancel-endpoint error (including a
 * validation failure on `reason`) into one generic banner.
 */
let server: Server
let routes: Record<string, (req: import('node:http').IncomingMessage) => { status: number, body: unknown }>

const detail: OwnerAppointmentDetail = {
  id: 'appt-1',
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
    '/api/owner/appointments/appt-1': () => ({ status: 200, body: detail }),
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

  mockNuxtImport('useRoute', () => () => ({ params: { id: 'appt-1' } }))
})

afterEach(() => new Promise<void>((resolve) => server.close(() => resolve())))

describe('pages/owner/appointments/[id].vue', () => {
  it('shows the cancel endpoint\'s 422 field message inline on the reason input', async () => {
    routes['/api/owner/appointments/appt-1/cancel'] = () => ({
      status: 422,
      body: { error: 'VALIDATION_FAILED', fields: { reason: ['The reason field must be a string.'] } },
    })

    const wrapper = await mountSuspended(AppointmentDetailPage)
    await new Promise((resolve) => setTimeout(resolve, 20))

    await wrapper.find('button.danger').trigger('click')
    await new Promise((resolve) => setTimeout(resolve, 50))

    expect(wrapper.text()).toContain('The reason field must be a string.')
  })
})
