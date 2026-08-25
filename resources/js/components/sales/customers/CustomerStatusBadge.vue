<script setup lang="ts">
import { computed } from 'vue'
import type { CustomerStatus } from '@/types/domain'

/**
 * `status_label` as text-plus-colour, never colour alone (requirements §4.4.2, WCAG 1.4.1) —
 * the same rule `AlertBanner` and `ArControlPage.vue` already apply.
 */
const props = defineProps<{ status: CustomerStatus; statusLabel: string }>()

const styles: Record<CustomerStatus, string> = {
  active: 'bg-success/10 text-success',
  inactive: 'bg-warning/10 text-warning',
  archived: 'bg-surface-sunken text-content-subtle',
}

const style = computed(() => styles[props.status])
</script>

<template>
  <span :class="['rounded px-2 py-0.5 text-xs font-medium', style]">{{ statusLabel }}</span>
</template>
