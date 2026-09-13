import { defineVitestConfig } from '@nuxt/test-utils/config'

/**
 * `@nuxt/test-utils`'s vitest environment gives tests the real Nuxt
 * auto-import context (`useState`, `useRuntimeConfig`, `$fetch`, etc.) —
 * composables like useOwnerSession.ts and useApi.ts genuinely depend on
 * those, so a plain Vitest setup (with those calls stubbed by hand) would
 * be testing a fiction, not this app's actual composables.
 */
export default defineVitestConfig({
  test: {
    environment: 'nuxt',
    include: ['tests/unit/**/*.test.ts'],
  },
})
