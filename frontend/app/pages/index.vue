<script setup lang="ts">
// This app has exactly one real page: the public booking flow at
// /tenants/{slug}. There is no tenant directory/landing surface in this
// project yet (out of this session's scope) — this root route just points
// a visitor at the one real flow that exists.
</script>

<template>
  <main style="max-width: 640px; margin: 4rem auto; padding: 0 1rem; font-family: system-ui, sans-serif;">
    <h1>bookslot</h1>
    <p>
      This app has one real page: a studio's public booking flow, at
      <code>/tenants/&lt;slug&gt;</code>.
    </p>
  </main>
</template>
