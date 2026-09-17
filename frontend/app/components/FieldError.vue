<script setup lang="ts">
defineProps<{ message?: string }>()
</script>

<template>
  <p v-if="message" class="field-error">{{ message }}</p>
</template>

<style scoped>
.field-error {
  color: #b3261e;
  font-size: 0.8rem;
  margin: 0.2rem 0 0;
}
</style>
