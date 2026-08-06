<script setup lang="ts">
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/ui/BaseButton.vue'
</script>

<template>
  <div class="mx-auto max-w-md py-16 text-center">
    <p class="text-sm font-medium text-primary-700 dark:text-primary-400">403</p>
    <h1 class="mt-2 text-xl font-semibold text-content">You do not have access to this</h1>
    <!-- Deliberately does not name the missing permission: enumerating the capability
         catalogue to someone probing for access is exactly what the server refuses to do. -->
    <p class="mt-2 text-sm text-content-muted">
      Ask an administrator in your workspace if you think you should.
    </p>
    <RouterLink :to="{ name: 'dashboard' }" class="mt-6 inline-block">
      <BaseButton variant="secondary">Back to dashboard</BaseButton>
    </RouterLink>
  </div>
</template>
