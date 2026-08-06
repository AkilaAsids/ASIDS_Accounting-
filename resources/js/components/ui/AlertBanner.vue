<script setup lang="ts">
withDefaults(
  defineProps<{
    kind?: 'success' | 'error' | 'warning' | 'info'
    title?: string
    /** Displayed in monospace when present — support asks for it by name. */
    requestId?: string
  }>(),
  { kind: 'info' },
)

/**
 * Every kind pairs its colour with a distinct icon. Colour alone is not a signal a
 * colour-blind user can read, and "your VAT return failed to file" is not a message to
 * convey with hue.
 */
const styles: Record<string, { wrapper: string; icon: string }> = {
  success: { wrapper: 'bg-success/10 text-success border-success/30', icon: 'M5 13l4 4L19 7' },
  error: { wrapper: 'bg-danger/10 text-danger border-danger/30', icon: 'M6 18L18 6M6 6l12 12' },
  warning: {
    wrapper: 'bg-warning/10 text-warning border-warning/30',
    icon: 'M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z',
  },
  info: { wrapper: 'bg-info/10 text-info border-info/30', icon: 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z' },
}
</script>

<template>
  <div :class="['flex gap-3 rounded-md border p-3 text-sm', styles[kind].wrapper]" role="alert">
    <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" :d="styles[kind].icon" />
    </svg>
    <div class="min-w-0">
      <p v-if="title" class="font-medium">{{ title }}</p>
      <div class="text-content"><slot /></div>
      <p v-if="requestId" class="mt-1 font-mono text-xs opacity-70">Reference: {{ requestId }}</p>
    </div>
  </div>
</template>
