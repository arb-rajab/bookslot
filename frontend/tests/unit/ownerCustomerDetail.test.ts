// @vitest-environment nuxt
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import OwnerCustomerDetailPage from '~/pages/owner/customers/[id].vue'
import { flushUntil } from './support/waitFor'

/**
 * FR-18's export/erasure and FR-23's re-invite (D-0059/D-0060) were
 * backend-only until this session (Session 36) — this page is their one
 * shared UI home (there's no owner-facing customer list page in this
 * codebase, so it's reached from an appointment's own detail page). Covers:
 * the export-endpoint-as-display-source pattern, the "last invited"
 * indicator built purely from already-loaded booking_events (never a
 * cooldown — D-0060 is explicit repeat sends are real), the erasure
 * confirm step, and its own `409 ACTIVE_BOOKING_EXISTS` block surfaced
 * distinctly from a generic error.
 */
let server: Server
let routes: Record<string, (req: import('node:http').IncomingMessage) => { status: number, body: unknown }>

function exportBody(overrides: Partial<{ erasure_requested_at: string | null, appointmentStatus: string, events: unknown[] }> = {}) {
  return {
    customer: {
      id: 'undefined',
      name: 'Jane Doe',
      email: 'jane@example.test',
      phone: '+15551234567',
      notes: null,
      erasure_requested_at: overrides.erasure_requested_at ?? null,
      created_at: '2025-01-01T00:00:00Z',
    },
    appointments: [
      {
        id: 'appt-1',
        service_name: 'Haircut',
        staff_name: 'Alex',
        starts_at: '2026-01-01T10:00:00Z',
        ends_at: '2026-01-01T11:00:00Z',
        status: overrides.appointmentStatus ?? 'completed',
        cancelled_by: null,
        cancelled_reason: null,
        cancelled_at: null,
        notes: null,
        created_at: '2025-12-01T00:00:00Z',
      },
    ],
    payments: [],
    payment_mandates: [],
    booking_events: overrides.events ?? [],
  }
}

beforeEach(async () => {
  routes = {
    '/sanctum/csrf-cookie': () => ({ status: 204, body: null }),
    '/api/owner/customers/undefined/export': () => ({ status: 200, body: exportBody() }),
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

describe('owner/customers/[id].vue', () => {
  it('renders the customer\'s own export data, with no "last invited" line when none was ever sent', async () => {
    const wrapper = await mountSuspended(OwnerCustomerDetailPage)
    await flushUntil(() => wrapper.text().includes('Jane Doe'))

    expect(wrapper.text()).toContain('jane@example.test');
    expect(wrapper.text()).not.toContain('Last invited')
  })

  it('shows the most recent re-invite send as a read-only indicator, and sends a brand-new one on click without any dedup', async () => {
    routes['/api/owner/customers/undefined/export'] = () => ({
      status: 200,
      body: exportBody({
        events: [{ appointment_id: 'appt-1', event_type: 'rebooking_invite_sent', from_status: null, to_status: null, created_at: '2026-01-05T12:00:00Z' }],
      }),
    })
    routes['/api/owner/customers/undefined/re-invite'] = () => ({ status: 202, body: { status: 'queued', channel: 'email' } })

    const wrapper = await mountSuspended(OwnerCustomerDetailPage)
    await flushUntil(() => wrapper.text().includes('Last invited'))

    await wrapper.find('button').trigger('click')
    await flushUntil(() => wrapper.text().includes('Invite sent.'))
  })

  it('blocks erasure with a clear reason while an active booking exists (409 ACTIVE_BOOKING_EXISTS)', async () => {
    routes['/api/owner/customers/undefined/export'] = () => ({ status: 200, body: exportBody({ appointmentStatus: 'confirmed' }) })
    routes['/api/owner/customers/undefined/erasure'] = () => ({ status: 409, body: { error: 'ACTIVE_BOOKING_EXISTS' } })

    const wrapper = await mountSuspended(OwnerCustomerDetailPage)
    await flushUntil(() => wrapper.find('button.danger').exists())

    expect(wrapper.text()).toContain('currently has an active')

    await wrapper.find('button.danger').trigger('click')
    await wrapper.find('.modal-actions button.danger').trigger('click')
    await flushUntil(() => wrapper.find('.error').exists())

    expect(wrapper.text()).toContain('erasure is blocked until it is completed or cancelled')
  })

  it('requires a confirm step before erasing, then shows the anonymized result in place', async () => {
    routes['/api/owner/customers/undefined/erasure'] = () => ({
      status: 200,
      body: { id: 'undefined', name: 'Erased Customer', email: 'erased-abc@erased.invalid', phone: null, erasure_requested_at: '2026-01-10T00:00:00Z' },
    })

    const wrapper = await mountSuspended(OwnerCustomerDetailPage)
    await flushUntil(() => wrapper.find('button.danger').exists())

    expect(wrapper.find('.action-form').exists()).toBe(false)
    await wrapper.find('button.danger').trigger('click')
    expect(wrapper.find('.action-form').exists()).toBe(true)

    await wrapper.find('.modal-actions button.danger').trigger('click')
    await flushUntil(() => wrapper.text().includes('Erased Customer'))

    expect(wrapper.text()).toContain('erased-abc@erased.invalid')
    expect(wrapper.text()).toContain('data was erased on')
  })
})
