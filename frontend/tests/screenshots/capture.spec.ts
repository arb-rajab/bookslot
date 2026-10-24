import { test } from '@playwright/test'
import { loginAsOwner } from '../e2e/support/booking'

/**
 * Writes PNGs to ../docs/screenshots. Needs the seeded `demo-studio` tenant
 * and owner login (database/seeders/DatabaseSeeder.php), like the e2e specs.
 */
const OUT = '../docs/screenshots'

test.use({ viewport: { width: 1180, height: 640 }, deviceScaleFactor: 1.5 })

test('capture the public booking page', async ({ page }) => {
  await page.goto('/tenants/demo-studio', { waitUntil: 'networkidle' })
  await page.screenshot({ path: `${OUT}/public-booking.png` })
})

test('capture the owner screens', async ({ page }) => {
  await loginAsOwner(page)
  await page.waitForLoadState('networkidle')
  await page.screenshot({ path: `${OUT}/owner-appointments.png` })

  for (const [path, file] of [
    ['/owner/services', 'owner-services'],
    ['/owner/availability', 'owner-availability'],
  ] as const) {
    await page.goto(path, { waitUntil: 'networkidle' })
    await page.screenshot({ path: `${OUT}/${file}.png` })
  }
})
