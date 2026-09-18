// @vitest-environment nuxt
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import OwnerAppointmentDetailPage from '~/pages/owner/appointments/[id].vue'
import { flushUntil } from './support/waitFor'

/**
 * D-0057's off-session balance charge was backend-only until this session
 * (Session 36). Per D-0061, this button is deliberately plain — no amount
 * input, no auto-charge policy UI — and the response's own two real `200`
 * shapes (succeeded vs. an expected decline/auth-required failure) must be
 * told apart from a genuine `502` provider failure, since the endpoint's
 * own contract treats a decline as a retriable business outcome, not an
 * error.
 */
let server: Server
let routes: Record<string, (req: import('node:http').IncomingMessage) => { status: number, body: unknown }>

const detail = {
  id: 'undefined',
  status: 'completed',
  starts_at: '2026-01-01T10:00:00Z',
  ends_at: '2026-01-01T11:00:00Z',
  customer_id: 'cust-1',
  customer_name: 'Jane Doe',
  customer_email: 'jane@example.test',
  customer_phone: null,
  service_name: 'Haircut',
  staff_name: 'Alex',
  deposit_status: 'succeeded',
  notes: null,
  cancelled_by: null,
  cancelled_reason: null,
  cancelled_at: null,
  payments: [
    { id: 'pay-1', type: 'deposit', status: 'succeeded', amount: 5000, currency: 'usd', failure_code: null, created_at: '2026-01-01T09:00:00Z' },
  ],
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

describe('owner/appointments/[id].vue — balance charge', () => {
  it('shows the balance-charge action only once completed, requires a confirm step, and reports success', async () => {
    routes['/api/owner/appointments/undefined/balance/charge'] = () => ({
      status: 200,
      body: { status: 'succeeded', payment_id: 'pay-2' },
    })

    const wrapper = await mountSuspended(OwnerAppointmentDetailPage)
    await flushUntil(() => wrapper.find('.balance-card').exists())

    expect(wrapper.find('.balance-card .action-form').exists()).toBe(false)
    await wrapper.find('.balance-card button').trigger('click')
    expect(wrapper.find('.balance-card .action-form').exists()).toBe(true)

    await wrapper.find('.balance-card .modal-actions button:not(.secondary)').trigger('click')
    await flushUntil(() => wrapper.text().includes('Balance charged successfully.'))
  })

  it('surfaces a declined charge as a retriable outcome, distinct from a provider-unavailable error', async () => {
    routes['/api/owner/appointments/undefined/balance/charge'] = () => ({
      status: 200,
      body: { status: 'failed', failure_code: 'card_declined', fallback_action: 'mark_paid_manually' },
    })

    const wrapper = await mountSuspended(OwnerAppointmentDetailPage)
    await flushUntil(() => wrapper.find('.balance-card').exists())

    await wrapper.find('.balance-card button').trigger('click')
    await wrapper.find('.balance-card .modal-actions button:not(.secondary)').trigger('click')
    await flushUntil(() => wrapper.text().includes('Charge declined'))

    expect(wrapper.text()).toContain('card_declined')
    expect(wrapper.text()).toContain('collect the balance in person')
    expect(wrapper.find('.balance-card .warning').exists()).toBe(true)
    expect(wrapper.find('.balance-card .error').exists()).toBe(false)
  })

  it('surfaces a genuine provider failure (502) as an error, never conflated with a decline', async () => {
    routes['/api/owner/appointments/undefined/balance/charge'] = () => ({
      status: 502,
      body: { error: 'PAYMENT_PROVIDER_UNAVAILABLE' },
    })

    const wrapper = await mountSuspended(OwnerAppointmentDetailPage)
    await flushUntil(() => wrapper.find('.balance-card').exists())

    await wrapper.find('.balance-card button').trigger('click')
    await wrapper.find('.balance-card .modal-actions button:not(.secondary)').trigger('click')
    await flushUntil(() => wrapper.find('.balance-card .error').exists())

    expect(wrapper.text()).toContain('Stripe is temporarily unavailable')
    expect(wrapper.find('.balance-card .warning').exists()).toBe(false)
  })
})
