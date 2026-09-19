// @vitest-environment nuxt
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import OwnerAppointmentDetailPage from '~/pages/owner/appointments/[id].vue'
import { flushUntil } from './support/waitFor'

/**
 * D-0056's refund endpoint was backend-only until this session (Session
 * 36) — this confirms the new "Refund deposit" section on the appointment
 * detail page: it only appears once a deposit payment is actually
 * `succeeded` (refundable), requires an explicit confirm step before
 * firing (a real, if small, financial action), and surfaces both the
 * happy path and the endpoint's own `409 PAYMENT_NOT_REFUNDABLE` distinctly
 * from a generic error.
 */
let server: Server
let routes: Record<string, (req: import('node:http').IncomingMessage) => { status: number, body: unknown }>

const detail = {
  id: 'undefined',
  status: 'confirmed',
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

describe('owner/appointments/[id].vue — refund', () => {
  /**
   * A real backend re-fetch after a successful refund returns the deposit
   * as `refunded`, not `succeeded` — this mock does the same (rather than
   * always echoing the original fixture) specifically because an earlier
   * version of this page hid its own success message the instant that
   * happened: the whole "Refund deposit" section was gated on there still
   * being something refundable, so the confirmation banner disappeared as
   * soon as the post-refund reload landed. A static mock that always
   * returns the pre-refund payment status would never have caught this —
   * it was only found against the real API in a real browser.
   */
  it('shows the refund action only once a deposit has actually succeeded, requires a confirm step, and keeps reporting the resulting payment status after the page reloads its own data', async () => {
    routes['/api/owner/appointments/undefined/refund'] = () => ({
      status: 200,
      body: { refund: { id: 'ref-1', payment_id: 'pay-1', amount: 5000, reason: null, status: 'succeeded' }, payment_status: 'refunded' },
    })

    const wrapper = await mountSuspended(OwnerAppointmentDetailPage)
    await flushUntil(() => wrapper.find('.refund-card').exists())

    // Confirm step: the form isn't shown, and no request fires, until the owner clicks through it.
    expect(wrapper.find('.refund-card .action-form').exists()).toBe(false)
    await wrapper.find('.refund-card button.danger').trigger('click')
    expect(wrapper.find('.refund-card .action-form').exists()).toBe(true)

    // From here on, the appointment-detail GET reflects the post-refund state, like the real API would.
    routes['/api/owner/appointments/undefined'] = () => ({
      status: 200,
      body: { ...detail, payments: [{ ...detail.payments[0], status: 'refunded' }] },
    })

    await wrapper.find('.refund-card .modal-actions button.danger').trigger('click')
    await flushUntil(() => wrapper.text().includes('Refund issued'))

    expect(wrapper.text()).toContain('now refunded')
    // Still visible after the reload settles — not hidden by refundableDeposit going null.
    await flushUntil(() => wrapper.text().includes('refunded'))
    expect(wrapper.text()).toContain('Refund issued')
  })

  it('surfaces PAYMENT_NOT_REFUNDABLE distinctly, not as a generic error', async () => {
    routes['/api/owner/appointments/undefined/refund'] = () => ({
      status: 409,
      body: { error: 'PAYMENT_NOT_REFUNDABLE' },
    })

    const wrapper = await mountSuspended(OwnerAppointmentDetailPage)
    await flushUntil(() => wrapper.find('.refund-card').exists())

    await wrapper.find('.refund-card button.danger').trigger('click')
    await wrapper.find('.refund-card .modal-actions button.danger').trigger('click')
    await flushUntil(() => wrapper.find('.refund-card .error').exists())

    expect(wrapper.text()).toContain('not in a refundable state')
  })

  it('surfaces STRIPE_ACCOUNT_NOT_CONNECTED (D-0066) with a clear, actionable message pointing at Settings → Stripe, not a generic error', async () => {
    routes['/api/owner/appointments/undefined/refund'] = () => ({
      status: 409,
      body: { error: 'STRIPE_ACCOUNT_NOT_CONNECTED' },
    })

    const wrapper = await mountSuspended(OwnerAppointmentDetailPage)
    await flushUntil(() => wrapper.find('.refund-card').exists())

    await wrapper.find('.refund-card button.danger').trigger('click')
    await wrapper.find('.refund-card .modal-actions button.danger').trigger('click')
    await flushUntil(() => wrapper.find('.refund-card .error').exists())

    expect(wrapper.text()).not.toContain('Something went wrong')
    expect(wrapper.text()).toContain('Settings')
    expect(wrapper.text()).toContain('Stripe')
  })

  it('renders the amount field\'s own 422 message inline, next to the field', async () => {
    routes['/api/owner/appointments/undefined/refund'] = () => ({
      status: 422,
      body: { error: 'VALIDATION_FAILED', fields: { amount: ['amount exceeds the remaining refundable balance (5000)'] } },
    })

    const wrapper = await mountSuspended(OwnerAppointmentDetailPage)
    await flushUntil(() => wrapper.find('.refund-card').exists())

    await wrapper.find('.refund-card button.danger').trigger('click')
    await wrapper.find('.refund-card input[type="number"]').setValue(9000)
    await wrapper.find('.refund-card .modal-actions button.danger').trigger('click')
    await flushUntil(() => wrapper.text().includes('exceeds the remaining refundable balance'))

    expect(wrapper.text()).toContain('Please check the highlighted fields.')
  })
})
