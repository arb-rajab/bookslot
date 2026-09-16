import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { expect, type APIRequestContext, type Locator, type Page } from '@playwright/test'

/**
 * Shared helpers for the owner-admin E2E specs (R-08's remaining gaps —
 * mark-attended/no-show, owner-initiated cancel, hold-window expiry
 * rendering, services/availability forms). Factored out of
 * manage-booking-cancel.spec.ts's own inline helpers (D-0052) rather than
 * duplicated a third/fourth time, since this file's specs need the same
 * "create a real pending booking against demo-studio over real HTTP" step
 * repeatedly, plus one further step (confirm-payment) those specs didn't
 * need.
 */

export const API_ORIGIN = 'http://localhost:8000'

export interface BookingCreatedResponse {
  appointment_id: string
  status: string
  payment_confirmation_token: string
  manage_token: string
}

/**
 * Mirrors manage-booking-cancel.spec.ts's createPendingBooking exactly
 * (same seeded demo-studio tenant/service/availability), returning the
 * confirm-payment token too since several specs here need to move the
 * booking on to `confirmed` (D-0033/D-0036's fake-Stripe-gateway tier —
 * this project never speaks to real Stripe, see D-0036) before an
 * owner action that only accepts a `confirmed` appointment (mark
 * attended/no-show) is reachable at all.
 */
export async function createPendingBooking(request: APIRequestContext, label: string): Promise<BookingCreatedResponse> {
  const servicesResponse = await request.get(`${API_ORIGIN}/api/tenants/demo-studio/services`)
  expect(servicesResponse.ok()).toBeTruthy()
  const { services } = (await servicesResponse.json()) as { services: Array<{ id: string; duration_minutes: number }> }
  const service = services[0]

  const availabilityResponse = await request.get(`${API_ORIGIN}/api/tenants/demo-studio/availability`, {
    params: {
      service_id: service.id,
      from: new Date().toISOString().slice(0, 10),
      to: new Date(Date.now() + 14 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10),
    },
  })
  expect(availabilityResponse.ok()).toBeTruthy()
  const { slots } = (await availabilityResponse.json()) as { slots: Array<{ staff_id: string; starts_at: string }> }
  expect(slots.length).toBeGreaterThan(0)
  const slot = slots[Math.floor(Math.random() * slots.length)]

  const mandateResponse = await request.get(`${API_ORIGIN}/api/tenants/demo-studio/services/${service.id}/mandate`)
  expect(mandateResponse.ok()).toBeTruthy()
  const mandate = (await mandateResponse.json()) as { template_version: string }

  const bookingResponse = await request.post(`${API_ORIGIN}/api/tenants/demo-studio/bookings`, {
    data: {
      service_id: service.id,
      staff_id: slot.staff_id,
      starts_at: slot.starts_at,
      customer: {
        name: `E2E ${label} ${Date.now()}`,
        email: `e2e-${label.toLowerCase().replace(/\s+/g, '-')}-${Date.now()}@example.test`,
      },
      mandate_accepted: true,
      mandate_template_version: mandate.template_version,
    },
  })
  expect(bookingResponse.ok()).toBeTruthy()

  return (await bookingResponse.json()) as BookingCreatedResponse
}

/**
 * Drives the real confirm-payment endpoint against D-0036's fake-Stripe
 * tier — the same PaymentIntent the booking above just created is always
 * `succeeded` under that gateway, so this always lands on `confirmed`
 * (PaymentConfirmationController::store). No body needed: the endpoint
 * re-checks the PaymentIntent server-side, it does not take client input.
 */
export async function confirmPayment(request: APIRequestContext, paymentConfirmationToken: string): Promise<void> {
  const response = await request.post(`${API_ORIGIN}/api/bookings/${paymentConfirmationToken}/confirm-payment`)
  expect(response.ok()).toBeTruthy()
  const body = (await response.json()) as { status: string }
  expect(body.status).toBe('confirmed')
}

async function clickUntilVisible(target: Locator, expectVisible: Locator): Promise<void> {
  await expect(async () => {
    await target.click()
    await expect(expectVisible).toBeVisible({ timeout: 1_000 })
  }).toPass({ timeout: 15_000 })
}

const CURRENT_DIR = path.dirname(fileURLToPath(import.meta.url))
const REPO_ROOT = path.resolve(CURRENT_DIR, '..', '..', '..', '..')

/**
 * FR-05/D-0049's real hold-window expiry (see expire-hold-window.php's own
 * docblock for the full rationale) — fast-forwards a still-`pending_payment`
 * booking past `config('booking.hold_window_minutes')` and runs the real
 * ReleaseExpiredPendingBookingJob against it synchronously, by shelling out
 * to `php artisan tinker` against the same database the E2E-driven `php
 * artisan serve` process reads. This is a test-harness call into existing,
 * already-unit-tested production logic — it adds no new application code
 * and no new HTTP surface.
 */
export function expireHoldWindow(appointmentId: string): void {
  execFileSync('php', ['artisan', 'tinker', 'frontend/tests/e2e/support/expire-hold-window.php'], {
    cwd: REPO_ROOT,
    env: { ...process.env, E2E_APPOINTMENT_ID: appointmentId },
    stdio: 'pipe',
  })
}

/** The same owner login click-path booking-flow.spec.ts and manage-booking-cancel.spec.ts already establish. */
export async function loginAsOwner(page: Page): Promise<void> {
  await page.goto('/owner')
  await page.getByLabel('Studio slug').fill('demo-studio')
  await page.getByLabel('Email').fill('owner@demo-studio.test')
  await page.getByLabel('Password').fill('password')
  await clickUntilVisible(page.getByRole('button', { name: 'Log in' }), page.getByRole('heading', { name: 'Appointments' }))
}
