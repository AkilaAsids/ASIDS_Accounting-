<script setup lang="ts">
import { computed } from 'vue'
import TextField from '@/components/ui/TextField.vue'
import TaxCodePicker from '@/components/sales/invoices/TaxCodePicker.vue'
import { useMoney } from '@/composables/useMoney'
import type { Account } from '@/types/domain'
import type { LineDraft } from './lineDraft'

/**
 * One editable invoice line (ADR 0013 §7/§2.3, the single highest-risk component in this
 * wave). Three things this row is responsible for getting right on its own:
 *
 *  1. **Discount mutual-exclusion (§4.7.4/§2.3.2).** The toggle below makes "only one of
 *     `discount_percent`/`discount_amount` may be set" structurally true — switching type
 *     clears whichever field was active — rather than something the server's
 *     `invoice-line-two-discounts` 422 teaches the user after the fact.
 *  2. **No arithmetic.** `line_subtotal`/`tax_amount`/`line_total` are rendered only from the
 *     last successful save (`*Computed` on the draft), `—` before that — never a client
 *     multiply of `quantity * unit_price` (§1.5/§7.4).
 *  3. **Per-line error display (§4.7.9, Gate-1 #8).** Each `errors` entry is keyed on the
 *     server's own field name (`description`, `quantity`, `unit_price`, `revenue_account_id`,
 *     `tax_code`, `discount_percent`, `discount_amount`) and rendered directly under that
 *     field, never force-mapped to the wrong control.
 */
const props = defineProps<{
  index: number
  accounts: Account[]
  companyId: string | null
  errors?: Record<string, string>
  canRemove: boolean
}>()

const emit = defineEmits<{ remove: [] }>()

const line = defineModel<LineDraft>('line', { required: true })

const { formatPlain } = useMoney()

const postableAccounts = computed(() => props.accounts.filter((account) => account.is_postable))

const hasError = computed(() => Object.keys(props.errors ?? {}).length > 0)

const discountValue = computed<string>({
  get: () => (line.value.discountType === 'percent' ? line.value.discountPercent : line.value.discountAmount),
  set: (value: string) => {
    if (line.value.discountType === 'percent') {
      line.value = { ...line.value, discountPercent: value }
    } else if (line.value.discountType === 'amount') {
      line.value = { ...line.value, discountAmount: value }
    }
  },
})

function setDiscountType(type: LineDraft['discountType']): void {
  if (type === line.value.discountType) {
    return
  }

  line.value = {
    ...line.value,
    discountType: type,
    // Switching clears whichever field was active — this is what makes "only one may be
    // set" true by construction rather than a client-side check of both.
    discountPercent: type === 'percent' ? line.value.discountPercent : '',
    discountAmount: type === 'amount' ? line.value.discountAmount : '',
  }
}
</script>

<template>
  <tr :class="hasError && 'border-l-2 border-danger'" class="border-b border-surface-border/60 align-top">
    <td class="py-2 pr-2 text-xs text-content-muted">{{ index + 1 }}</td>

    <td class="min-w-48 py-2 pr-2">
      <TextField
        :model-value="line.description"
        label="Description"
        :error="errors?.description"
        required
        @update:model-value="line = { ...line, description: $event }"
      />
    </td>

    <td class="w-20 py-2 pr-2">
      <TextField
        :model-value="line.quantity"
        label="Qty"
        :error="errors?.quantity"
        required
        @update:model-value="line = { ...line, quantity: $event }"
      />
    </td>

    <td class="w-28 py-2 pr-2">
      <TextField
        :model-value="line.unitPrice"
        label="Unit price"
        :error="errors?.unit_price"
        required
        @update:model-value="line = { ...line, unitPrice: $event }"
      />
    </td>

    <td class="w-40 py-2 pr-2">
      <div class="field-label">Discount</div>
      <div class="mt-1 flex items-center gap-1">
        <div class="inline-flex overflow-hidden rounded-md border border-surface-border text-xs" role="group" aria-label="Discount type">
          <button
            type="button"
            :aria-pressed="line.discountType === 'none'"
            class="px-1.5 py-1"
            :class="line.discountType === 'none' ? 'bg-primary-600 text-white' : 'text-content-muted hover:bg-surface-sunken'"
            @click="setDiscountType('none')"
          >
            None
          </button>
          <button
            type="button"
            :aria-pressed="line.discountType === 'percent'"
            class="border-l border-surface-border px-1.5 py-1"
            :class="line.discountType === 'percent' ? 'bg-primary-600 text-white' : 'text-content-muted hover:bg-surface-sunken'"
            @click="setDiscountType('percent')"
          >
            %
          </button>
          <button
            type="button"
            :aria-pressed="line.discountType === 'amount'"
            class="border-l border-surface-border px-1.5 py-1"
            :class="line.discountType === 'amount' ? 'bg-primary-600 text-white' : 'text-content-muted hover:bg-surface-sunken'"
            @click="setDiscountType('amount')"
          >
            Amt
          </button>
        </div>

        <input
          v-if="line.discountType !== 'none'"
          v-model="discountValue"
          type="text"
          inputmode="decimal"
          :aria-label="line.discountType === 'percent' ? 'Discount percent' : 'Discount amount'"
          class="form-input block w-full rounded-md border-surface-border bg-surface-raised text-sm text-content shadow-sm focus:border-primary-500 focus:ring-primary-500"
        />
      </div>
      <p v-if="errors?.discount_percent" class="field-error" role="alert">{{ errors.discount_percent }}</p>
      <p v-if="errors?.discount_amount" class="field-error" role="alert">{{ errors.discount_amount }}</p>
      <p v-if="line.discountConflictNote" class="field-hint">{{ line.discountConflictNote }}</p>
    </td>

    <td class="w-44 py-2 pr-2">
      <TaxCodePicker
        :company-id="companyId"
        :model-value="line.taxCode"
        :error="errors?.tax_code"
        @update:model-value="line = { ...line, taxCode: $event }"
      />
    </td>

    <td class="min-w-44 py-2 pr-2">
      <div>
        <label class="field-label" :for="`line-${line.key}-revenue-account`">Revenue account</label>
        <select
          :id="`line-${line.key}-revenue-account`"
          :value="line.revenueAccountId"
          class="form-select mt-1 block w-full rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
          :class="errors?.revenue_account_id && 'border-danger focus:border-danger focus:ring-danger'"
          @change="line = { ...line, revenueAccountId: ($event.target as HTMLSelectElement).value }"
        >
          <option value="">Select an account</option>
          <option v-for="account in postableAccounts" :key="account.id" :value="account.id">
            {{ account.code }} — {{ account.name }}
          </option>
        </select>
        <p v-if="errors?.revenue_account_id" class="field-error" role="alert">{{ errors.revenue_account_id }}</p>
      </div>
    </td>

    <td class="w-28 py-2 pr-2 text-right font-mono tabular-nums text-content-muted">
      {{ line.lineTotalComputed === null ? '—' : formatPlain(line.lineTotalComputed) }}
    </td>

    <td class="w-10 py-2 text-right">
      <button
        type="button"
        class="rounded-md p-1.5 text-content-muted hover:bg-surface-sunken hover:text-danger disabled:cursor-not-allowed disabled:opacity-40"
        :disabled="!canRemove"
        :aria-label="`Remove line ${index + 1}`"
        @click="emit('remove')"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M6 18L18 6" />
        </svg>
      </button>
    </td>
  </tr>
</template>
