import { expect, test } from '@playwright/test'
import { confirmPayment, createPendingBooking, expireHoldWindow, loginAsOwner } from './support/booking'

/**
 * R-08's remaining owner-side click gaps (docs/project-memory/
 * 10-risk-register.md, 12-session-handoff.md's Session 20/22 amendments):
 * mark-attended, no-show handling as it actually exists today, and the
 * owner's own (studio-authenticated) cancel button — all three distinct
 * from booking-flow.spec.ts's public-booking coverage and from
 * manage-booking-cancel.spec.ts's customer-token cancel coverage.
 *
 * Runs against the seeded `demo-studio` tenant, same precondition as the
 * other two E2E specs: `php artisan migrate:fresh --seed` must already
 * have been run against a real Postgres database.
 */

test('the owner marks a confirmed appointment as attended', async ({ page, request }) => {
  const booking = await createPendingBooking(request, 'Attended')
  await confirmPayment(request, booking.payment_confirmation_token)

  await loginAsOwner(page)
  await expect(page).toHaveURL(/\/owner\/appointments$/)

  const row = page.locator('tr', { has: page.getByRole('cell', { name: `E2E Attended`, exact: false }) }).first()
  await expect(row).toBeVisible({ timeout: 15_000 })
  await expect(row.getByText('confirmed')).toBeVisible()

  await expect(async () => {
    await row.getByRole('button', { name: 'Mark attended' }).click()
    await expect(row.getByText('completed')).toBeVisible({ timeout: 1_000 })
  }).toPass({ timeout: 15_000 })

  // The action buttons only render for status === 'confirmed' — once
  // marked attended, there is nothing left to click for this row.
  await expect(row.getByRole('button', { name: 'Mark attended' })).toHaveCount(0)
  await expect(row.getByRole('button', { name: 'Mark no-show' })).toHaveCount(0)
})

/**
 * J4 (docs/project-memory/09-decision-log.md): "Owner marks it `no_show`
 * from the dashboard (MVP: no automatic no-show detection — an explicit
 * owner action)." This IS that explicit owner action — a real click on a
 * real button, never inferred or scheduled. Kept deliberately distinct
 * from the hold-window-expiry test below, which covers a different status
 * transition (`pending_payment` -> `cancelled`, driven by a background job,
 * not a click) that this work order's own item 2 also names.
 */
test('the owner marks a confirmed appointment as no-show (the explicit, manual owner action J4 requires)', async ({ page, request }) => {
  const booking = await createPendingBooking(request, 'NoShow')
  await confirmPayment(request, booking.payment_confirmation_token)

  await loginAsOwner(page)
  await expect(page).toHaveURL(/\/owner\/appointments$/)

  const row = page.locator('tr', { has: page.getByRole('cell', { name: `E2E NoShow`, exact: false }) }).first()
  await expect(row).toBeVisible({ timeout: 15_000 })

  await expect(async () => {
    await row.getByRole('button', { name: 'Mark no-show' }).click()
    await expect(row.getByText('no show')).toBeVisible({ timeout: 1_000 })
  }).toPass({ timeout: 15_000 })
})

/**
 * FR-05/D-0049: hold-window expiry is a background job
 * (ReleaseExpiredPendingBookingJob), dispatched with a real delay at
 * booking-creation time — there is no owner-facing button for it, and
 * building one (or any other new trigger) would blur J4's boundary this
 * work order explicitly warns against. The real "owner-facing UI surface
 * for this" is passive: the appointment detail page rendering the
 * resulting cancelled/system state, exactly like
 * manage-booking-cancel.spec.ts already does for the customer-cancel
 * counterpart. expireHoldWindow() fast-forwards wall time and runs the
 * real, already-unit-tested job (see support/expire-hold-window.php) —
 * this test does not build or extend any detection logic itself.
 */
test('a pending booking that outlives its hold window shows as system-cancelled on the owner appointment detail page', async ({ page, request }) => {
  const booking = await createPendingBooking(request, 'HoldExpiry')

  expireHoldWindow(booking.appointment_id)

  await loginAsOwner(page)
  await page.goto(`/owner/appointments/${booking.appointment_id}`)

  await expect(page.getByText('Status: cancelled')).toBeVisible({ timeout: 15_000 })
  await expect(page.locator('dt', { hasText: 'Cancelled by' }).locator('+ dd')).toHaveText('system')
  await expect(page.getByText(/hold_window_expired/)).toBeVisible()
})

test('the owner cancels their own booking from the appointment detail page (distinct from the customer-token cancel endpoint)', async ({ page, request }) => {
  const booking = await createPendingBooking(request, 'OwnerCancel')

  await loginAsOwner(page)
  await page.goto(`/owner/appointments/${booking.appointment_id}`)

  await expect(page.getByRole('heading', { name: 'Cancel this appointment' })).toBeVisible({ timeout: 15_000 })
  await page.getByPlaceholder('Reason (optional)').fill('E2E: studio-initiated cancellation')

  await expect(async () => {
    await page.getByRole('button', { name: 'Cancel appointment' }).click()
    await expect(page.getByText('Status: cancelled')).toBeVisible({ timeout: 1_000 })
  }).toPass({ timeout: 15_000 })

  // 'studio', not 'customer' or 'system' — proves this hit the
  // owner-authenticated Owner\AppointmentController::cancel() route, not
  // the manage_booking token endpoint D-0052 already covers.
  await expect(page.locator('dt', { hasText: 'Cancelled by' }).locator('+ dd')).toHaveText('studio')
  await expect(page.getByText('E2E: studio-initiated cancellation')).toBeVisible()

  // Cancelling again must not be offered — the cancel section only renders
  // for a still-live status.
  await expect(page.getByRole('heading', { name: 'Cancel this appointment' })).toHaveCount(0)
})
