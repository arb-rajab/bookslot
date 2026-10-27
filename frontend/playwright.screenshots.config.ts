import { defineConfig } from '@playwright/test'
import base from './playwright.config'

/**
 * Screenshot capture, run by .github/workflows/screenshots.yml (`npm run
 * screenshots`). Same servers, browser and `localhost` rules as the e2e
 * config, pointed at tests/screenshots so the regular E2E job never writes
 * images.
 */
export default defineConfig({
  ...base,
  testDir: './tests/screenshots',
  retries: 0,
})
