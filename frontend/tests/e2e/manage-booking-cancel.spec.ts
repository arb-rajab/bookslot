import { expect, test, type APIRequestContext, type Page } from '@playwright/test'

/**
 * D-0052 (docs/project-memory/09-decision-log.md): real E2E coverage of the
 * new customer-facing `POST /api/bookings/manage/{token}/cancel` endpoint —
 * the counterpart to `booking-flow.spec.ts`'s public-booking coverage.
 *
 * A deliberate scope note, so this test isn't misread as covering more than
 * it does: `frontend/` has no customer-facing "manage my booking" page —
 * the only real consumer of this endpoint is `bookslot-mobile` (a separate
 * repository, out of scope here). Building a customer-facing UI page in
 * this project's own Nuxt app purely to have something to click would be
 * scope creep this endpoint's own work order never asked for. This test
 * therefore drives the new endpoint directly over real HTTP (Playwright's
 * `request` fixture — the same real, locally-run Postgres/Redis/RabbitMQ-
 * backed Laravel API every other E2E spec in this suite runs against, no
 * mocked network layer anywhere), then verifies the effect **in a real
 * browser** by loading the owner appointment-detail page and asserting the
 * cancelled state renders — closing R-08's gap for that page's
 * cancelled-state rendering specifically (see 10-risk-register.md).
 *
 * Runs against the seeded `demo-studio` tenant (database/seeders/
 * DatabaseSeeder.php), same as booking-flow.spec.ts — `php artisan
 * migrate:fresh --seed` must already have been run against a real
 * Postgres database.
 */

const API_ORIGIN = 'http://localhost:8000'

interface BookingCreatedResponse {
  appointment_id: string
  manage_token: string
}

async function createPendingBooking(request: APIRequestContext): Promise<BookingCreatedResponse> {
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
  const slot = slots[0]

  const mandateResponse = await request.get(`${API_ORIGIN}/api/tenants/demo-studio/services/${service.id}/mandate`)
  expect(mandateResponse.ok()).toBeTruthy()
  const mandate = (await mandateResponse.json()) as { template_version: string }

  const bookingResponse = await request.post(`${API_ORIGIN}/api/tenants/demo-studio/bookings`, {
    data: {
      service_id: service.id,
      staff_id: slot.staff_id,
      starts_at: slot.starts_at,
      customer: {
        name: `E2E Cancel Customer ${Date.now()}`,
        email: `e2e-cancel-${Date.now()}@example.test`,
      },
      mandate_accepted: true,
      mandate_template_version: mandate.template_version,
    },
  })
  expect(bookingResponse.ok()).toBeTruthy()

  return (await bookingResponse.json()) as BookingCreatedResponse
}

async function clickUntilVisible(page: Page, targetName: string, expectVisibleName: string): Promise<void> {
  await expect(async () => {
    await page.getByRole('button', { name: targetName }).click()
    await expect(page.getByRole('heading', { name: expectVisibleName })).toBeVisible({ timeout: 1_000 })
  }).toPass({ timeout: 15_000 })
}

test('a customer cancels their own pending booking via the manage_booking token, and the owner sees it cancelled', async ({ page, request }) => {
  const { appointment_id: appointmentId, manage_token: manageToken } = await createPendingBooking(request)

  const cancelResponse = await request.post(`${API_ORIGIN}/api/bookings/manage/${manageToken}/cancel`, {
    data: { reason: 'E2E: customer changed their mind' },
  })
  expect(cancelResponse.ok()).toBeTruthy()
  const cancelled = await cancelResponse.json()
  expect(cancelled).toMatchObject({ appointment_id: appointmentId, status: 'cancelled' })

  // A second call must be rejected, not silently re-accepted — the
  // endpoint's own idempotent-unsafe stance (D-0052), proven here against
  // the real server, not just the Feature-test suite.
  const secondCancelResponse = await request.post(`${API_ORIGIN}/api/bookings/manage/${manageToken}/cancel`)
  expect(secondCancelResponse.status()).toBe(409)

  await page.goto('/owner')
  await page.getByLabel('Studio slug').fill('demo-studio')
  await page.getByLabel('Email').fill('owner@demo-studio.test')
  await page.getByLabel('Password').fill('password')
  await clickUntilVisible(page, 'Log in', 'Appointments')

  await page.goto(`/owner/appointments/${appointmentId}`)
  await expect(page.getByText('Status: cancelled')).toBeVisible({ timeout: 15_000 })
  // "Cancelled by"'s own value dd, specifically — not just any element on
  // the page containing the word "customer" (the customer's own name field
  // also renders nearby and would otherwise make this assertion pass for
  // the wrong reason).
  await expect(page.locator('dt', { hasText: 'Cancelled by' }).locator('+ dd')).toHaveText('customer')
})

test('a tampered manage_booking token cannot be used to cancel someone else\'s booking', async ({ request }) => {
  const { manage_token: manageToken } = await createPendingBooking(request)

  const middle = Math.floor(manageToken.length / 2)
  const flippedChar = manageToken[middle] === 'a' ? 'b' : 'a'
  const tampered = manageToken.slice(0, middle) + flippedChar + manageToken.slice(middle + 1)

  const response = await request.post(`${API_ORIGIN}/api/bookings/manage/${tampered}/cancel`)
  expect(response.status()).toBe(404)
  const body = await response.json()
  expect(body).toMatchObject({ error: 'INVALID_OR_EXPIRED_TOKEN' })
})
