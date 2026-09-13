<script setup lang="ts">
// The owner dashboard used to live entirely in this one file (login form +
// appointment list + mark-attended/no-show). Splitting the admin surface
// into dedicated pages (services, availability, appointments, queue-
// health, D-0051) means this route is now just the login gate (handled by
// layouts/owner.vue) plus a redirect once authenticated — appointments
// stays the default landing view since it was this dashboard's original
// sole purpose.
definePageMeta({ layout: 'owner' })

const { authState } = useOwnerSession()

watchEffect(() => {
  if (authState.value === 'authenticated') {
    navigateTo('/owner/appointments')
  }
})
</script>

<template>
  <div />
</template>
