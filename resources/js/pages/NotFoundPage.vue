<script setup lang="ts">
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/ui/BaseButton.vue'
</script>

<template>
  <div class="mx-auto max-w-md py-16 text-center">
    <p class="text-sm font-medium text-primary-700 dark:text-primary-400">404</p>
    <h1 class="mt-2 text-xl font-semibold text-content">We could not find that page</h1>
    <p class="mt-2 text-sm text-content-muted">
      It may have been moved, or the link may be out of date.
    </p>
    <RouterLink :to="{ name: 'dashboard' }" class="mt-6 inline-block">
      <BaseButton variant="secondary">Back to dashboard</BaseButton>
    </RouterLink>
  </div>
</template>
