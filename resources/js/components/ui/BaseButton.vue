<script setup lang="ts">
/**
 * The only button in the application.
 *
 * `loading` disables the button *and* keeps its width, so a form does not reflow when
 * submitted — a shifting layout under the cursor is how users double-submit a payment.
 */
withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'danger' | 'ghost'
    size?: 'sm' | 'md'
    type?: 'button' | 'submit'
    loading?: boolean
    disabled?: boolean
    block?: boolean
  }>(),
  { variant: 'primary', size: 'md', type: 'button', loading: false, disabled: false, block: false },
)

const variants: Record<string, string> = {
  primary: 'bg-primary-600 text-white hover:bg-primary-700 focus-visible:ring-primary-500',
  secondary:
    'bg-surface-raised text-content border border-surface-border hover:bg-surface-sunken focus-visible:ring-primary-500',
  danger: 'bg-danger text-white hover:opacity-90 focus-visible:ring-danger',
  ghost: 'text-content-muted hover:bg-surface-sunken hover:text-content focus-visible:ring-primary-500',
}

const sizes: Record<string, string> = {
  sm: 'px-2.5 py-1.5 text-xs',
  md: 'px-4 py-2 text-sm',
}
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :aria-busy="loading"
    :class="[
      'inline-flex items-center justify-center gap-2 rounded-md font-medium transition',
      'disabled:cursor-not-allowed disabled:opacity-50',
      variants[variant],
      sizes[size],
      block && 'w-full',
    ]"
  >
    <svg v-if="loading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" aria-hidden="true">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none" />
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
    </svg>
    <slot />
  </button>
</template>
