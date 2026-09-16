import { expect, test } from '@playwright/test'
import { loginAsOwner } from './support/booking'

/**
 * R-08's remaining "services/availability form submission" click-path gap
 * (docs/project-memory/12-session-handoff.md's Session 20/22 amendments).
 *
 * Scope check performed before writing this file, per this work order's
 * own instruction not to trust the manifest: read
 * frontend/app/pages/owner/services/index.vue and
 * frontend/app/pages/owner/availability/index.vue directly.
 *   - Services: create + edit are both real, wired forms (POST/PATCH
 *     /owner/services) — no destroy endpoint exists (routes/api.php has no
 *     DELETE route for services), so "at minimum create/edit" is also the
 *     ceiling here, not just the floor. Deactivate/activate is a PATCH
 *     toggle on the existing row, covered as an extra assertion since it's
 *     the same form-adjacent surface and cheap to add once a service
 *     exists.
 *   - Availability: no dedicated "availability" CRUD resource exists as
 *     such. What's actually built and wired to a form is staff creation,
 *     weekly working-hours replacement (PUT), and one-off availability
 *     exceptions (POST/DELETE) — all on this one page. Covered directly
 *     rather than invented against a resource shape that isn't real.
 *
 * Runs against the seeded `demo-studio` tenant, same precondition as the
 * other E2E specs in this suite.
 */

test('the owner creates a new service via the admin form, then edits it', async ({ page }) => {
  await loginAsOwner(page)
  await page.getByRole('link', { name: 'Services' }).click()
  await expect(page.getByRole('heading', { name: 'Services' })).toBeVisible({ timeout: 15_000 })

  const serviceName = `E2E Service ${Date.now()}`

  await page.getByRole('button', { name: 'Add service' }).click()
  await expect(page.getByRole('heading', { name: 'New service' })).toBeVisible()

  await page.getByLabel('Name').fill(serviceName)
  await page.getByLabel('Duration (minutes)').fill('45')
  await page.getByLabel('Price (cents)').fill('5000')
  await page.getByLabel('Currency').fill('usd')
  await page.getByLabel('Deposit type').selectOption('fixed')
  await page.getByLabel('Deposit amount (cents)').fill('1000')
  await page.getByLabel('Buffer before (minutes)').fill('5')
  await page.getByLabel('Buffer after (minutes)').fill('5')

  await expect(async () => {
    await page.getByRole('button', { name: 'Save' }).click()
    await expect(page.getByRole('cell', { name: serviceName })).toBeVisible({ timeout: 1_000 })
  }).toPass({ timeout: 15_000 })

  const row = page.locator('tr', { has: page.getByRole('cell', { name: serviceName, exact: true }) })
  await expect(row.getByText('45 min')).toBeVisible()
  await expect(row.getByText('Active')).toBeVisible()

  // Edit: change the duration and confirm the row reflects the new value —
  // proves the PATCH path (editingId set from startEdit()), not just POST.
  await row.getByRole('button', { name: 'Edit' }).click()
  await expect(page.getByRole('heading', { name: 'Edit service' })).toBeVisible()
  await expect(page.getByLabel('Name')).toHaveValue(serviceName)

  await page.getByLabel('Duration (minutes)').fill('60')

  await expect(async () => {
    await page.getByRole('button', { name: 'Save' }).click()
    await expect(row.getByText('60 min')).toBeVisible({ timeout: 1_000 })
  }).toPass({ timeout: 15_000 })

  // Deactivate toggle — same PATCH endpoint, exercised via its other real
  // button on this page.
  await expect(async () => {
    await row.getByRole('button', { name: 'Deactivate' }).click()
    await expect(row.getByText('Inactive')).toBeVisible({ timeout: 1_000 })
  }).toPass({ timeout: 15_000 })
})

test('the owner adds a staff member, sets their weekly working hours, and adds a one-off availability exception', async ({ page }) => {
  await loginAsOwner(page)
  await page.getByRole('link', { name: 'Availability' }).click()
  await expect(page.getByRole('heading', { name: 'Availability' })).toBeVisible({ timeout: 15_000 })

  const staffName = `E2E Staff ${Date.now()}`

  await page.getByPlaceholder('New staff name').fill(staffName)
  await expect(async () => {
    await page.getByRole('button', { name: 'Add', exact: true }).click()
    await expect(page.getByRole('button', { name: staffName, exact: false })).toBeVisible({ timeout: 1_000 })
  }).toPass({ timeout: 15_000 })

  // Newly added staff is auto-selected (selectStaff() in the page's own
  // createStaff()) — the weekly-hours card is for them already.
  await expect(page.getByRole('heading', { name: 'Weekly working hours' })).toBeVisible()

  const mondayRow = page.locator('.day-row', { has: page.getByText('Monday', { exact: true }) })
  await mondayRow.locator('input[type="checkbox"]').check()
  await mondayRow.locator('input[type="time"]').first().fill('09:00')
  await mondayRow.locator('input[type="time"]').nth(1).fill('17:00')

  await page.getByRole('button', { name: 'Save working hours' }).click()
  await expect(page.getByRole('button', { name: 'Save working hours' })).toBeEnabled({ timeout: 15_000 })
  await expect(page.getByText('VALIDATION_FAILED', { exact: false })).toHaveCount(0)

  // Reload the page and re-select the same staff member to prove the PUT
  // actually persisted server-side, not just held in local component state.
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Availability' })).toBeVisible({ timeout: 15_000 })
  await page.getByRole('button', { name: staffName, exact: false }).click()
  await expect(page.getByRole('heading', { name: 'Weekly working hours' })).toBeVisible({ timeout: 15_000 })
  const reloadedMondayRow = page.locator('.day-row', { has: page.getByText('Monday', { exact: true }) })
  await expect(reloadedMondayRow.locator('input[type="time"]').first()).toHaveValue('09:00')
  await expect(reloadedMondayRow.locator('input[type="time"]').nth(1)).toHaveValue('17:00')

  // One-off exception form (POST), on the same page.
  const futureDate = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10)
  await page.locator('.add-exception input[type="date"]').fill(futureDate)
  await page.locator('.add-exception input[type="text"]').fill('E2E studio closure')

  await expect(async () => {
    await page.getByRole('button', { name: 'Add exception' }).click()
    await expect(page.getByRole('cell', { name: futureDate })).toBeVisible({ timeout: 1_000 })
  }).toPass({ timeout: 15_000 })

  const exceptionRow = page.locator('tr', { has: page.getByRole('cell', { name: futureDate }) })
  await expect(exceptionRow.getByText('Closed')).toBeVisible()
  await expect(exceptionRow.getByText('E2E studio closure')).toBeVisible()

  // DELETE path.
  await expect(async () => {
    await exceptionRow.getByRole('button', { name: 'Remove' }).click()
    await expect(page.getByRole('cell', { name: futureDate })).toHaveCount(0, { timeout: 1_000 })
  }).toPass({ timeout: 15_000 })
})
