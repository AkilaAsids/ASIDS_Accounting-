<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError } from '@/api/client'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import InvoiceActionsMenu from '@/components/sales/invoices/InvoiceActionsMenu.vue'
import InvoiceStatusBadge from '@/components/sales/invoices/InvoiceStatusBadge.vue'
import InvoiceTotals from '@/components/sales/invoices/InvoiceTotals.vue'
import { cancelSalesInvoice, deleteSalesInvoice, getSalesInvoice, issueSalesInvoice } from '@/api/salesInvoices'
import { useCompanyReload } from '@/composables/useCompanyReload'
import { useMoney } from '@/composables/useMoney'
import { useUiStore } from '@/stores/ui'
import type { SalesInvoice } from '@/types/domain'

/**
 * Invoice view + issue/cancel/delete (requirements §4.9–§4.12).
 *
 * Journal-entry cross-link (Decision C, §4.9.4): `router/index.ts` has no journal-entry
 * *detail* route — only the `journal-entries` list — so `journal_entry_id` is rendered as
 * plain text below, never as a link that would then 404. Revisit this if a detail route is
 * added later.
 */
defineOptions({ name: 'SalesInvoiceDetailPage' })

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const { formatPlain } = useMoney()

const invoiceId = computed<string | null>(() =>
  typeof route.params.invoiceId === 'string' ? route.params.invoiceId : null,
)

const invoice = ref<SalesInvoice | null>(null)
const loading = ref(true)
const notFound = ref(false)
const busy = ref(false)

const { companyId } = useCompanyReload(load)

async function load(): Promise<void> {
  if (companyId.value === null || invoiceId.value === null) {
    loading.value = false
    return
  }

  loading.value = true
  notFound.value = false

  try {
    const response = await getSalesInvoice(companyId.value, invoiceId.value)
    invoice.value = response.data
  } catch (thrown) {
    invoice.value = null

    // A 404 and an `invoice-company-mismatch` 422 (a stale link after a company switch) are
    // treated identically — a generic not-found panel, never distinguishing "wrong company"
    // from "does not exist" (§4.9.5).
    if (thrown instanceof ApiError && (thrown.status === 404 || thrown.is('invoice-company-mismatch'))) {
      notFound.value = true
    } else {
      ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not load this invoice.')
    }
  } finally {
    loading.value = false
  }
}

const issueRefusals: Record<string, string> = {
  'invoice-not-a-draft':
    'This invoice is no longer a draft — someone else may have issued or cancelled it. Refresh to see its current state.',
  'invoice-has-no-lines-to-issue': 'Add at least one line before issuing.',
  'invoice-total-not-positive': 'The invoice total must be greater than zero before it can be issued.',
  'invoice-period-not-open':
    "The accounting period for this invoice's date is closed. Ask Accounting to reopen it, or change the invoice date.",
  'receivable-account-missing':
    'This customer has no receivable account configured. Set one on the customer record before issuing.',
  'tax-output-account-missing':
    'A tax code on this invoice has no output account configured. Ask an administrator to fix the tax code.',
}

const cancelRefusals: Record<string, string> = {
  'invoice-not-issued': 'This invoice is not issued, so it cannot be cancelled.',
  'invoice-already-cancelled': 'This invoice has already been cancelled.',
  'invoice-partially-paid': 'This invoice has payments recorded against it and cannot be cancelled.',
  'invoice-reversal-period-not-open':
    "The current accounting period is closed, so a reversal cannot be posted today. This refers to today's period, not the invoice's original one — ask Accounting to reopen the current period, or try again once it reopens.",
}

async function onIssue(): Promise<void> {
  if (companyId.value === null || invoice.value === null) {
    return
  }

  busy.value = true

  try {
    const response = await issueSalesInvoice(companyId.value, invoice.value.id)
    invoice.value = response.data
    ui.notify('success', `Invoice ${response.data.number ?? ''} issued.`)
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? issueRefusals[thrown.code] ?? thrown.problem.detail : 'Could not issue this invoice.')
  } finally {
    busy.value = false
  }
}

async function onCancel(reason: string): Promise<void> {
  if (companyId.value === null || invoice.value === null) {
    return
  }

  busy.value = true

  try {
    const response = await cancelSalesInvoice(companyId.value, invoice.value.id, reason)
    invoice.value = response.data
    ui.notify('success', 'Invoice cancelled — the original entry is untouched; a mirror entry has been posted.')
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? cancelRefusals[thrown.code] ?? thrown.problem.detail : 'Could not cancel this invoice.')
  } finally {
    busy.value = false
  }
}

async function onDelete(): Promise<void> {
  if (companyId.value === null || invoice.value === null) {
    return
  }

  busy.value = true

  try {
    await deleteSalesInvoice(companyId.value, invoice.value.id)
    ui.notify('success', 'Draft invoice deleted.')
    await router.push({ name: 'invoices' })
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not delete the draft.')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-5">
    <div v-if="loading" class="py-12 text-center text-sm text-content-muted">Loading…</div>

    <div v-else-if="notFound" class="mx-auto max-w-md py-16 text-center">
      <h1 class="text-xl font-semibold text-content">We could not find that invoice</h1>
      <p class="mt-2 text-sm text-content-muted">It may have been removed, or the link may be out of date.</p>
    </div>

    <template v-else-if="invoice">
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2 text-2xl font-semibold text-content">
            {{ invoice.number ?? 'Draft invoice' }}
            <InvoiceStatusBadge :status="invoice.status" :status-label="invoice.status_label" />
          </h1>
          <p class="mt-1 text-sm text-content-muted">{{ invoice.customer?.name ?? '—' }}</p>
        </div>

        <InvoiceActionsMenu :invoice="invoice" :busy="busy" @issue="onIssue" @cancel="onCancel" @delete="onDelete" />
      </header>

      <AlertBanner v-if="invoice.status === 'cancelled'" kind="warning" title="This invoice was cancelled">
        Cancelled on {{ invoice.cancelled_at }}. This does not delete or undo the original entry — a mirror
        entry reversing it was posted.
        <span v-if="invoice.cancellation_reason" class="mt-1 block font-medium text-content">
          Reason: {{ invoice.cancellation_reason }}
        </span>
      </AlertBanner>

      <SurfaceCard title="Details">
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <p class="field-label">Invoice date</p>
            <p class="text-content">{{ invoice.invoice_date }}</p>
          </div>
          <div>
            <p class="field-label">Due date</p>
            <p class="text-content">
              {{ invoice.due_date }}
              <span v-if="invoice.is_overdue" class="ml-1 font-medium text-danger">Overdue</span>
            </p>
          </div>
          <div v-if="invoice.reference">
            <p class="field-label">Reference</p>
            <p class="text-content">{{ invoice.reference }}</p>
          </div>
          <div v-if="invoice.journal_entry_id">
            <p class="field-label">Journal entry</p>
            <!-- Decision C: no journal-entry detail route exists in the router, so this is
                 stated as plain text rather than a link that would then 404. -->
            <p class="font-mono text-xs text-content-muted">{{ invoice.journal_entry_id }}</p>
          </div>
        </div>
      </SurfaceCard>

      <SurfaceCard title="Lines">
        <div class="overflow-x-auto" role="region" aria-label="Invoice lines" tabindex="0">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="border-b border-surface-border text-left text-xs uppercase tracking-wide text-content-subtle">
                <th scope="col" class="py-2 pr-4">Description</th>
                <th scope="col" class="py-2 pr-4 text-right">Qty</th>
                <th scope="col" class="py-2 pr-4 text-right">Unit price</th>
                <th scope="col" class="py-2 pr-4">Tax code</th>
                <th scope="col" class="py-2 text-right">Line total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="line in invoice.lines ?? []" :key="line.id" class="border-b border-surface-border/60">
                <td class="py-2 pr-4 text-content">{{ line.description }}</td>
                <td class="py-2 pr-4 text-right font-mono tabular-nums text-content-muted">{{ line.quantity }}</td>
                <td class="py-2 pr-4 text-right font-mono tabular-nums text-content-muted">{{ formatPlain(line.unit_price) }}</td>
                <td class="py-2 pr-4 text-content-muted">
                  {{ line.tax_code ? `${line.tax_code} (${Number(line.tax_rate)}%)` : '—' }}
                </td>
                <td class="py-2 text-right font-mono tabular-nums text-content">{{ formatPlain(line.line_total) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-4 flex justify-end">
          <InvoiceTotals
            mode="view"
            :subtotal="invoice.subtotal"
            :discount-total="invoice.discount_total"
            :tax-total="invoice.tax_total"
            :total="invoice.total"
            :amount-paid="invoice.amount_paid"
            :amount-due="invoice.amount_due"
          />
        </div>
      </SurfaceCard>
    </template>
  </div>
</template>
