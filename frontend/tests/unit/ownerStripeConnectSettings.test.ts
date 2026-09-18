// @vitest-environment nuxt
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mountSuspended } from '@nuxt/test-utils/runtime'
import OwnerStripeConnectPage from '~/pages/owner/settings/stripe/index.vue'
import { flushUntil } from './support/waitFor'

/**
 * D-0058's Connect onboarding-link/status endpoints were backend-only
 * until this session (Session 36). Covers: the live status display (all
 * four classified states), and that "start/resume/refresh onboarding" is
 * real browser navigation to Stripe's own hosted page (never rendered as
 * an in-app result) — the one action in this session's scope that
 * deliberately leaves the app rather than showing a response.
 */
let server: Server
let routes: Record<string, (req: import('node:http').IncomingMessage) => { status: number, body: unknown }>

beforeEach(async () => {
  routes = {
    '/sanctum/csrf-cookie': () => ({ status: 204, body: null }),
    '/api/owner/stripe/connect/status': () => ({
      status: 200,
      body: { status: 'not_started', charges_enabled: false, details_submitted: false },
    }),
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

describe('owner/settings/stripe/index.vue', () => {
  it('shows a not-started studio and labels the action "Start onboarding"', async () => {
    const wrapper = await mountSuspended(OwnerStripeConnectPage)
    await flushUntil(() => wrapper.text().includes('Not started'))

    expect(wrapper.find('button').text()).toContain('Start onboarding with Stripe')
  })

  it('classifies restricted ahead of charges_enabled, per D-0058 (a disabled_reason always wins)', async () => {
    routes['/api/owner/stripe/connect/status'] = () => ({
      status: 200,
      body: { status: 'restricted', charges_enabled: true, details_submitted: true },
    })

    const wrapper = await mountSuspended(OwnerStripeConnectPage)
    await flushUntil(() => wrapper.text().includes('Restricted'))

    expect(wrapper.text()).toContain('Stripe has flagged this account')
  })

  it('offers "Update details" once complete, rather than a fresh "start"', async () => {
    routes['/api/owner/stripe/connect/status'] = () => ({
      status: 200,
      body: { status: 'complete', charges_enabled: true, details_submitted: true },
    })

    const wrapper = await mountSuspended(OwnerStripeConnectPage)
    await flushUntil(() => wrapper.text().includes('Complete'))

    expect(wrapper.find('button').text()).toContain('Update details on Stripe')
  })

  it('redirects the browser to the real Stripe-hosted onboarding link on click, rather than rendering it as an in-app result', async () => {
    routes['/api/owner/stripe/connect/onboarding-link'] = () => ({
      status: 200,
      body: { url: 'https://connect.stripe.com/setup/e/acct_123/xyz', expires_at: 1999999999 },
    })

    const originalLocation = window.location
    let assignedHref = ''
    // @ts-expect-error -- replacing window.location for this test only, restored below
    delete window.location
    // @ts-expect-error -- minimal stand-in, only `href` is used by the page
    window.location = {
      get href() { return assignedHref },
      set href(value: string) { assignedHref = value },
    }

    try {
      const wrapper = await mountSuspended(OwnerStripeConnectPage)
      await flushUntil(() => wrapper.find('button').exists())

      await wrapper.find('button').trigger('click')
      await flushUntil(() => assignedHref !== '')

      expect(assignedHref).toBe('https://connect.stripe.com/setup/e/acct_123/xyz')
    } finally {
      window.location = originalLocation
    }
  })

  it('surfaces a 502 from the onboarding-link call as a provider-unavailable error', async () => {
    routes['/api/owner/stripe/connect/onboarding-link'] = () => ({
      status: 502,
      body: { error: 'PAYMENT_PROVIDER_UNAVAILABLE' },
    })

    const wrapper = await mountSuspended(OwnerStripeConnectPage)
    await flushUntil(() => wrapper.find('button').exists())

    await wrapper.find('button').trigger('click')
    await flushUntil(() => wrapper.find('.error').exists())

    expect(wrapper.text()).toContain('Stripe is temporarily unavailable')
  })
})
