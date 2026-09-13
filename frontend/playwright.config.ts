import { defineConfig, devices } from '@playwright/test'

/**
 * R-08 (docs/project-memory/10-risk-register.md): three prior sessions
 * added a frontend surface, checked for browser-automation tooling, found
 * none, and left the whole frontend proven only at the HTTP/SSR level —
 * never by an actual click. This session's environment DOES have a
 * pre-installed Chromium (unlike the privacy-forge gap this is the direct
 * do-over of), so this config drives it for real against the real Laravel
 * API and a real Postgres database — no mocked network layer anywhere in
 * tests/e2e.
 *
 * Both dev servers are started here (not assumed already running) so
 * `npx playwright test` is a complete, reproducible command in CI and
 * locally alike. `reuseExistingServer` lets a developer who already has
 * both running locally skip the relaunch.
 */
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'line',
  // Sanctum's SPA cookie pattern (D-0029) relies on the frontend and API
  // sharing a cookie-visible hostname — cookies are scoped by hostname,
  // not by port, but "localhost" and "127.0.0.1" are different hostnames
  // entirely as far as the cookie jar and CORS are concerned, even though
  // they resolve to the same machine. The frontend's own default
  // apiOrigin (nuxt.config.ts) is `http://localhost:8000`, so every origin
  // here must use `localhost`, never `127.0.0.1` — a real, if easy to
  // make, local-dev footgun this session's own first browser run hit
  // (a same-request 419 CSRF mismatch, not a CORS block).
  use: {
    baseURL: 'http://localhost:3000',
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // This environment ships a pinned Chromium build that doesn't
        // always match whatever revision @playwright/test's own version
        // expects (it looks for a "headless shell" build that may not be
        // the one actually installed) — pointing directly at the real
        // installed binary avoids a spurious "please run playwright
        // install" failure over a network fetch that isn't needed.
        launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_PATH },
      },
    },
  ],
  webServer: [
    {
      command: 'php artisan serve --host=localhost --port=8000',
      cwd: '..',
      url: 'http://localhost:8000/up',
      reuseExistingServer: !process.env.CI,
      timeout: 30_000,
    },
    {
      command: 'npm run dev -- --host=localhost --port=3000',
      cwd: '.',
      url: 'http://localhost:3000',
      reuseExistingServer: !process.env.CI,
      timeout: 30_000,
    },
  ],
})
