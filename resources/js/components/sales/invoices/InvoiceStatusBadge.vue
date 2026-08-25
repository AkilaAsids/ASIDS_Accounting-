<script setup lang="ts">
import type { SalesInvoiceStatus } from '@/types/domain'

/**
 * `status_label` as text-plus-colour, never colour alone (§4.9.2) — the word is what a
 * colour-blind or screen-reader user reads; the colour only reinforces it for everyone else.
 */
defineProps<{ status: SalesInvoiceStatus; statusLabel: string }>()

const styles: Record<SalesInvoiceStatus, string> = {
  draft: 'bg-surface-sunken text-content-subtle',
  issued: 'bg-info/10 text-info',
  partially_paid: 'bg-warning/10 text-warning',
  paid: 'bg-success/10 text-success',
  cancelled: 'bg-danger/10 text-danger',
}
</script>

<template>
  <span :class="['rounded px-2 py-0.5 text-xs font-medium', styles[status]]">{{ statusLabel }}</span>
</template>
