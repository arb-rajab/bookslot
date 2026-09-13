import { expect, test, type Locator, type Page } from '@playwright/test'

/**
 * R-08's real-browser gap, closed for real (see playwright.config.ts's
 * docblock). Runs against the seeded `demo-studio` tenant
 * (database/seeders/DatabaseSeeder.php) — `php artisan migrate:fresh
 * --seed` must have been run against a real Postgres database before this
 * suite executes; it is not run automatically here since seeding is a
 * one-time, destructive step this test file should never trigger itself.
 *
 * Covers J1 end to end: pick a service, pick a real derived-availability
 * slot, accept the real server-rendered mandate, submit the booking, and
 * confirm the deposit against D-0036's fake-Stripe-gateway tier (this
 * project permanently never speaks to real Stripe infrastructure — see
 * that decision). Then proves the admin frontend this session built
 * actually reflects that same booking, tenant-scoped, closing the loop
 * between the public flow and the admin surface rather than testing them
 * in isolation.
 */

/**
 * Nuxt SSR's own hydration is genuinely racy against a script-driven click
 * that arrives the instant an element is painted: the server-rendered
 * button is visible before Vue finishes attaching its `@click` handler, so
 * a single click can silently no-op. Retrying the click until the expected
 * next-step content actually appears is the standard, documented way to
 * make an E2E test robust to that race without an arbitrary sleep.
 */
async function clickUntilVisible(target: Locator, expectVisible: Locator): Promise<void> {
  await expect(async () => {
    await target.click()
    await expect(expectVisible).toBeVisible({ timeout: 1_000 })
  }).toPass({ timeout: 15_000 })
}

test('a customer books an appointment end to end, and it appears on the owner dashboard', async ({ page }: { page: Page }) => {
  await page.goto('/tenants/demo-studio')

  await expect(page.getByText('Small Tattoo Session')).toBeVisible()
  await clickUntilVisible(
    page.getByRole('button', { name: 'Select' }).first(),
    page.getByRole('heading', { name: 'Small Tattoo Session' }),
  )

  await page.getByRole('button', { name: 'Search' }).click()

  const slotButton = page.locator('.slot-button').first()
  await expect(slotButton).toBeVisible({ timeout: 15_000 })
  await clickUntilVisible(slotButton, page.getByText('I agree to the terms above.'))

  const customerName = `E2E Customer ${Date.now()}`
  await page.getByLabel('Name').fill(customerName)
  await page.getByLabel('Email').fill(`e2e-${Date.now()}@example.test`)
  await page.getByLabel('I agree to the terms above.').check()
  await clickUntilVisible(
    page.getByRole('button', { name: 'Book and continue to payment' }),
    page.getByRole('heading', { name: 'Confirm your deposit' }),
  )

  await clickUntilVisible(
    page.getByRole('button', { name: 'Confirm Payment' }),
    page.getByRole('heading', { name: 'Booking confirmed' }),
  )

  // Close the loop: the owner dashboard (this session's admin frontend)
  // must show this exact booking, tenant-scoped, not a mock.
  await page.goto('/owner')
  await page.getByLabel('Studio slug').fill('demo-studio')
  await page.getByLabel('Email').fill('owner@demo-studio.test')
  await page.getByLabel('Password').fill('password')
  await clickUntilVisible(page.getByRole('button', { name: 'Log in' }), page.getByRole('heading', { name: 'Appointments' }))

  await expect(page).toHaveURL(/\/owner\/appointments$/)
  await expect(page.getByRole('cell', { name: customerName })).toBeVisible({ timeout: 15_000 })
})

/**
 * Every admin page this session added, hit by a real browser at least
 * once. Component/unit tests (see the Vitest suite) prove each page's
 * logic in isolation; this proves the whole shell — layout, routing,
 * auth-gated navigation — actually wires together, the exact class of bug
 * (a missing `<NuxtLayout>` in app.vue silently blanked every owner page)
 * a plain typecheck or component test cannot catch.
 */
test('every owner nav page renders after login, not just appointments', async ({ page }: { page: Page }) => {
  await page.goto('/owner')
  await page.getByLabel('Studio slug').fill('demo-studio')
  await page.getByLabel('Email').fill('owner@demo-studio.test')
  await page.getByLabel('Password').fill('password')
  await clickUntilVisible(page.getByRole('button', { name: 'Log in' }), page.getByRole('heading', { name: 'Appointments' }))

  const pages: Array<[string, string]> = [
    ['Services', 'Services'],
    ['Availability', 'Availability'],
    ['Reminders', 'Reminder deliveries'],
    ['Queue health', 'Queue health'],
  ]

  for (const [navLabel, heading] of pages) {
    await clickUntilVisible(page.getByRole('link', { name: navLabel }), page.getByRole('heading', { name: heading }))
  }
})
