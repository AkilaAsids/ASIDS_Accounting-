<script setup lang="ts">
import { useUiStore } from '@/stores/ui'
import type { Theme } from '@/types/domain'

const ui = useUiStore()

const options: Array<{ value: Theme; label: string; icon: string }> = [
  {
    value: 'light',
    label: 'Light',
    icon: 'M12 3v1m0 16v1m9-9h-1M4 12H3m15.36 6.36l-.7-.7M6.34 6.34l-.7-.7m12.72 0l-.7.7M6.34 17.66l-.7.7M16 12a4 4 0 11-8 0 4 4 0 018 0z',
  },
  {
    value: 'system',
    label: 'System',
    icon: 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
  },
  {
    value: 'dark',
    label: 'Dark',
    icon: 'M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z',
  },
]
</script>

<template>
  <!-- A three-way control, not a two-state switch: "match my device" is a distinct choice
       from "always light", and a binary toggle silently overrides the OS preference. -->
  <div
    class="inline-flex rounded-md border border-surface-border bg-surface-raised p-0.5"
    role="radiogroup"
    aria-label="Appearance"
  >
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      role="radio"
      :aria-checked="ui.theme === option.value"
      :title="option.label"
      class="grid h-7 w-7 place-items-center rounded transition"
      :class="
        ui.theme === option.value
          ? 'bg-primary-600 text-white'
          : 'text-content-subtle hover:text-content'
      "
      @click="ui.setTheme(option.value)"
    >
      <svg
        class="h-4 w-4"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        aria-hidden="true"
      >
        <path stroke-linecap="round" stroke-linejoin="round" :d="option.icon" />
      </svg>
      <span class="sr-only">{{ option.label }}</span>
    </button>
  </div>
</template>
