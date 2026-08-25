<script setup lang="ts">
import { computed, nextTick } from 'vue'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import InvoiceLineRow from '@/components/sales/invoices/InvoiceLineRow.vue'
import { blankLine, type LineDraft } from '@/components/sales/invoices/lineDraft'
import type { Account } from '@/types/domain'

/**
 * The dynamic line list (ADR 0013 §7.1). Owns add/remove and hands each row its own slice of
 * `lineErrors` — the per-line 422 mapping (Gate-1 #8) happens once, in the editor page, and
 * arrives here already split by line index so this component does not need to know the
 * `lines.<i>.<field>` key shape itself.
 *
 * Renders no money total of its own — that is `InvoiceTotals.vue`'s job from the page.
 */
const props = defineProps<{
  accounts: Account[]
  companyId: string | null
  lineErrors?: Record<number, Record<string, string>>
}>()

const lines = defineModel<LineDraft[]>('lines', { required: true })

const canRemove = computed(() => lines.value.length > 1)

const errorSummary = computed(() => {
  const entries: Array<{ index: number; field: string; message: string }> = []

  for (const [indexKey, fields] of Object.entries(props.lineErrors ?? {})) {
    for (const [field, message] of Object.entries(fields)) {
      entries.push({ index: Number(indexKey), field, message })
    }
  }

  return entries
})

const fieldLabels: Record<string, string> = {
  description: 'description',
  quantity: 'quantity',
  unit_price: 'unit price',
  tax_code: 'tax code',
  revenue_account_id: 'revenue account',
  discount_percent: 'discount',
  discount_amount: 'discount',
}

function addLine(): void {
  lines.value = [...lines.value, blankLine()]
}

function removeLine(index: number): void {
  if (lines.value.length <= 1) {
    return
  }

  lines.value = lines.value.filter((_, candidateIndex) => candidateIndex !== index)
}

/**
 * Replaces one line by position. Takes the index explicitly rather than binding
 * `v-model:line="lines[index]"` directly in the template — reading `lines[index]` is typed
 * `LineDraft | undefined` under `noUncheckedIndexedAccess`, and `value` here is already
 * known-`LineDraft` (the child only ever emits a full line), so assigning through a copy is
 * both the correctly-typed path and avoids mutating the model in place.
 */
function updateLine(index: number, value: LineDraft): void {
  const next = [...lines.value]
  next[index] = value
  lines.value = next
}

async function focusLine(index: number): Promise<void> {
  await nextTick()
  const row = document.querySelector<HTMLElement>(`[data-line-index="${index}"]`)
  row?.scrollIntoView({ block: 'center' })
  row?.querySelector<HTMLElement>('input, select')?.focus()
}
</script>

<template>
  <div class="space-y-3">
    <AlertBanner v-if="errorSummary.length > 0" kind="error" title="Some lines need attention">
      <ul class="space-y-1">
        <li v-for="entry in errorSummary" :key="`${entry.index}-${entry.field}`">
          <button
            type="button"
            class="text-left underline hover:no-underline"
            @click="focusLine(entry.index)"
          >
            Line {{ entry.index + 1 }}: {{ fieldLabels[entry.field] ?? entry.field }} — {{ entry.message }}
          </button>
        </li>
      </ul>
    </AlertBanner>

    <div class="overflow-x-auto" role="region" aria-label="Invoice lines" tabindex="0">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="border-b border-surface-border text-left text-xs uppercase tracking-wide text-content-subtle">
            <th scope="col" class="py-2 pr-2">#</th>
            <th scope="col" class="py-2 pr-2">Description</th>
            <th scope="col" class="py-2 pr-2">Qty</th>
            <th scope="col" class="py-2 pr-2">Unit price</th>
            <th scope="col" class="py-2 pr-2">Discount</th>
            <th scope="col" class="py-2 pr-2">Tax code</th>
            <th scope="col" class="py-2 pr-2">Revenue account</th>
            <th scope="col" class="py-2 pr-2 text-right">Line total</th>
            <th scope="col" class="py-2"><span class="sr-only">Remove</span></th>
          </tr>
        </thead>
        <tbody>
          <InvoiceLineRow
            v-for="(line, index) in lines"
            :key="line.key"
            :line="line"
            :data-line-index="index"
            :index="index"
            :accounts="accounts"
            :company-id="companyId"
            :errors="lineErrors?.[index]"
            :can-remove="canRemove"
            @update:line="updateLine(index, $event)"
            @remove="removeLine(index)"
          />
        </tbody>
      </table>
    </div>

    <BaseButton variant="secondary" type="button" @click="addLine">Add a line</BaseButton>
  </div>
</template>
