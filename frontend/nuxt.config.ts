// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  // 03-architecture.md's decoupled Laravel API + Nuxt frontend split
  // (D-0002): this app never talks to a database directly, only the real
  // Laravel API below. NUXT_PUBLIC_API_BASE overrides this for any
  // non-local deployment; the default matches this repository's own
  // `php artisan serve` port.
  runtimeConfig: {
    public: {
      apiOrigin: process.env.NUXT_PUBLIC_API_ORIGIN || 'http://localhost:8000',
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000/api',
    },
  },
})
