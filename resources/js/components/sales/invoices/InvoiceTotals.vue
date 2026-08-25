<script setup lang="ts">
import { computed } from 'vue'
import { useMoney } from '@/composables/useMoney'

/**
 * Renders totals exactly as the API returned them — nothing here is computed (Gate-1 #5,
 * ADR 0011 D5, requirements §4.7.6/§4.8.6/§4.9.1). Every prop is the literal string from the
 * last successful response; a `null` prop means "not known yet," never "zero."
 *
 * `mode="editor"` shows the "totals finalise on save" hint (§1.5): an em dash before the
 * first successful save, then the saved figures with a caption warning that a further edit
 * needs a further save to update them. `mode="view"` (the read-only detail screen) shows the
 * figures with no hint — there is nothing left to finalise — and adds the amount paid/due
 * rows the editor never has reason to show.
 */
const props = withDefaults(
  defineProps<{
    subtotal: string | null
    discountTotal: string | null
    taxTotal: string | null
    total: string | null
    amountPaid?: string | null
    amountDue?: string | null
    mode?: 'editor' | 'view'
  }>(),
  { amountPaid: null, amountDue: null, mode: 'editor' },
)

const { formatPlain } = useMoney()

const hasFigures = computed(() => props.total !== null)

const hint = computed(() =>
  hasFigures.value
    ? 'Totals shown are as saved. Editing a line will need saving again to update them.'
    : 'Totals finalise when you save.',
)

function display(amount: string | null): string {
  return amount === null ? '—' : formatPlain(amount)
}
</script>

<template>
  <div class="space-y-1 text-right">
    <p class="flex justify-between gap-6 text-sm text-content-muted">
      <span>Subtotal</span>
      <span class="font-mono tabular-nums">{{ display(subtotal) }}</span>
    </p>
    <p class="flex justify-between gap-6 text-sm text-content-muted">
      <span>Discount</span>
      <span class="font-mono tabular-nums">{{ display(discountTotal) }}</span>
    </p>
    <p class="flex justify-between gap-6 text-sm text-content-muted">
      <span>Tax</span>
      <span class="font-mono tabular-nums">{{ display(taxTotal) }}</span>
    </p>
    <p class="flex justify-between gap-6 border-t border-surface-border pt-1 text-base font-semibold text-content">
      <span>Total</span>
      <span class="font-mono tabular-nums">{{ display(total) }}</span>
    </p>

    <template v-if="mode === 'view'">
      <p class="flex justify-between gap-6 text-sm text-content-muted">
        <span>Amount paid</span>
        <span class="font-mono tabular-nums">{{ display(amountPaid) }}</span>
      </p>
      <p class="flex justify-between gap-6 text-sm font-medium text-content">
        <span>Amount due</span>
        <span class="font-mono tabular-nums">{{ display(amountDue) }}</span>
      </p>
    </template>

    <p v-if="mode === 'editor'" class="pt-1 text-xs text-content-muted">{{ hint }}</p>
  </div>
</template>
