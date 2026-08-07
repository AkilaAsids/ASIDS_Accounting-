<script setup lang="ts">
import ThemeToggle from '@/components/app/ThemeToggle.vue'

defineProps<{ title: string; subtitle?: string }>()
</script>

<template>
  <div class="flex min-h-screen flex-col bg-surface-sunken">
    <header class="flex items-center justify-between px-6 py-4">
      <div class="flex items-center gap-2">
        <span
          class="grid h-8 w-8 place-items-center rounded-md bg-primary-600 text-sm font-bold text-white"
          >A</span
        >
        <span class="text-sm font-semibold text-content">ASIDS ERP Cloud</span>
      </div>
      <ThemeToggle />
    </header>

    <main
      id="main"
      class="flex flex-1 items-start justify-center px-4 pb-16 pt-4 sm:items-center sm:pt-0"
    >
      <div class="w-full max-w-md">
        <div
          class="rounded-card border border-surface-border bg-surface-raised p-6 shadow-card sm:p-8"
        >
          <h1 class="text-xl font-semibold text-content">{{ title }}</h1>
          <p v-if="subtitle" class="mt-1 text-sm text-content-muted">{{ subtitle }}</p>
          <div class="mt-6"><slot /></div>
        </div>
        <div class="mt-4 text-center text-xs text-content-subtle"><slot name="below" /></div>
      </div>
    </main>
  </div>
</template>
