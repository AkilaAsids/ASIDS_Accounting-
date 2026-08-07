<script setup lang="ts">
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
</script>

<template>
  <!-- `aria-live="polite"` rather than assertive: a save confirmation should not interrupt
       a screen reader mid-sentence. Errors are also announced politely because they persist
       until dismissed, so nothing is missed. -->
  <div
    class="pointer-events-none fixed bottom-4 right-4 z-40 flex w-full max-w-sm flex-col gap-2"
    role="status"
    aria-live="polite"
  >
    <TransitionGroup
      enter-active-class="transition duration-200"
      enter-from-class="translate-y-2 opacity-0"
      leave-active-class="transition duration-150"
      leave-to-class="opacity-0"
    >
      <div
        v-for="notice in ui.notices"
        :key="notice.id"
        class="pointer-events-auto flex items-start gap-3 rounded-md border bg-surface-raised p-3 text-sm shadow-overlay"
        :class="{
          'border-success/40': notice.kind === 'success',
          'border-danger/40': notice.kind === 'error',
          'border-surface-border': notice.kind === 'info',
        }"
      >
        <p class="min-w-0 flex-1 text-content">{{ notice.message }}</p>
        <button
          type="button"
          class="shrink-0 rounded text-content-subtle hover:text-content"
          :aria-label="`Dismiss: ${notice.message}`"
          @click="ui.dismiss(notice.id)"
        >
          <svg
            class="h-4 w-4"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            aria-hidden="true"
          >
            <path stroke-linecap="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>
