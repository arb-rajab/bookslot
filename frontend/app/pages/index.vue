<script setup lang="ts">
// This app has two real pages: the public booking flow at /tenants/{slug},
// and the owner dashboard at /owner (D-0042/D-0043). There is no tenant
// directory/landing surface in this project yet (out of scope) — this root
// route just points a visitor at the two real flows that exist.
</script>

<template>
  <main style="max-width: 640px; margin: 4rem auto; padding: 0 1rem; font-family: system-ui, sans-serif;">
    <h1>bookslot</h1>
    <p>
      A studio's public booking flow lives at
      <code>/tenants/&lt;slug&gt;</code>. The owner dashboard lives at
      <NuxtLink to="/owner">/owner</NuxtLink>.
    </p>
  </main>
</template>
