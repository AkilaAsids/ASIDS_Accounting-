<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { ApiError } from '@/api/client'
import BaseButton from '@/components/ui/BaseButton.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import OverflowMenu from '@/components/ui/OverflowMenu.vue'
import Pagination from '@/components/ui/Pagination.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import TextField from '@/components/ui/TextField.vue'
import PermissionGate from '@/components/app/PermissionGate.vue'
import CancelInvoiceDialog from '@/components/sales/invoices/CancelInvoiceDialog.vue'
import InvoiceStatusBadge from '@/components/sales/invoices/InvoiceStatusBadge.vue'
import { cancelSalesInvoice, deleteSalesInvoice, listSalesInvoices } from '@/api/salesInvoices'
import { useCompanyReload } from '@/composables/useCompanyReload'
import { useMoney } from '@/composables/useMoney'
import { useUiStore } from '@/stores/ui'
import type { ApiMeta, Pagination as PaginationMeta } from '@/types/api'
import type { SalesInvoice } from '@/types/domain'

/**
 * The invoice list (requirements §4.6, ADR 0013 §1). Filtering/search/pagination are server
 * parameters, never client-side array work (§4.6.1) — the request itself, not the rendered
 * rows, is what a spec asserts to prove that. Line detail never appears here (§4.6.5); the
 * list never sends `include=lines`.
 *
 * Issuing has no place here at all — a draft is issued from its own detail page. This
 * screen's row actions are Edit/View plus an overflow that offers Cancel or Delete, each
 * gated on the row's own `capabilities`, never shown when nothing in it would be enabled.
 */
defineOptions({ name: 'SalesInvoicesListPage' })

const ui = useUiStore()
const { formatPlain } = useMoney()

const rows = ref<SalesInvoice[]>([])
const meta = ref<ApiMeta | null>(null)
const loading = ref(true)

const search = ref('')
const statusFilter = ref('')
const customerFilter = ref('')
const currentPage = ref(1)

const actionInvoice = ref<SalesInvoice | null>(null)
const cancelDialogOpen = ref(false)
const deleteDialogOpen = ref(false)
const rowBusy = ref<string | null>(null)

const { companyId } = useCompanyReload(() => load(1))

const pagination = computed<PaginationMeta | null>(() => meta.value?.pagination ?? null)
const isEmpty = computed<boolean>(() => meta.value !== null && rows.value.length === 0)
const isFiltered = computed<boolean>(() => search.value !== '' || statusFilter.value !== '' || customerFilter.value !== '')

async function load(page = 1): Promise<void> {
  if (companyId.value === null) {
    loading.value = false
    return
  }

  loading.value = true

  try {
    const response = await listSalesInvoices(companyId.value, {
      page,
      q: search.value || undefined,
      filter: {
        status: statusFilter.value || undefined,
        customer_id: customerFilter.value || undefined,
      },
    })

    rows.value = response.data
    meta.value = response.meta
    currentPage.value = page
  } catch (thrown) {
    // Cleared rather than left in place — a stale list under a failure notice reads as
    // though it is current (ADR 0011 D4's corrected rule, requirements §4.6.6).
    rows.value = []
    meta.value = null

    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not load invoices.')
  } finally {
    loading.value = false
  }
}

// The status/customer filters re-query immediately — there is no rapid-fire input to debounce
// on a `<select>`. Only free-text search gets the 300 ms debounce (matching `UsersPage.vue`).
watch(statusFilter, () => void load(1))

let searchTimer: number | undefined
watch([search, customerFilter], () => {
  window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => void load(1), 300)
})

function openCancel(invoice: SalesInvoice): void {
  actionInvoice.value = invoice
  cancelDialogOpen.value = true
}

function openDelete(invoice: SalesInvoice): void {
  actionInvoice.value = invoice
  deleteDialogOpen.value = true
}

async function confirmCancel(reason: string): Promise<void> {
  const invoice = actionInvoice.value
  cancelDialogOpen.value = false

  if (invoice === null || companyId.value === null) {
    return
  }

  rowBusy.value = invoice.id

  try {
    await cancelSalesInvoice(companyId.value, invoice.id, reason)
    ui.notify('success', `Invoice ${invoice.number ?? ''} cancelled.`)
    await load(currentPage.value)
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not cancel the invoice.')
  } finally {
    rowBusy.value = null
  }
}

async function confirmDelete(): Promise<void> {
  const invoice = actionInvoice.value
  deleteDialogOpen.value = false

  if (invoice === null || companyId.value === null) {
    return
  }

  rowBusy.value = invoice.id

  try {
    await deleteSalesInvoice(companyId.value, invoice.id)
    ui.notify('success', 'Draft invoice deleted.')
    await load(currentPage.value)
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not delete the draft.')
  } finally {
    rowBusy.value = null
  }
}
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-content">Invoices</h1>
        <p class="mt-1 text-sm text-content-muted">Every sales invoice raised by this company.</p>
      </div>

      <PermissionGate permission="sales.invoices.draft">
        <RouterLink :to="{ name: 'invoice-new' }">
          <BaseButton>New invoice</BaseButton>
        </RouterLink>
      </PermissionGate>
    </header>

    <div class="flex flex-wrap gap-3">
      <div class="min-w-56 flex-1">
        <TextField v-model="search" label="Search" placeholder="Invoice number or reference" />
      </div>
      <div>
        <label class="field-label" for="invoice-status-filter">Status</label>
        <select
          id="invoice-status-filter"
          v-model="statusFilter"
          class="form-select mt-1 rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
        >
          <option value="">All</option>
          <option value="draft">Draft</option>
          <option value="issued">Issued</option>
          <option value="partially_paid">Partially paid</option>
          <option value="paid">Paid</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
      <div class="min-w-40">
        <TextField v-model="customerFilter" label="Customer" placeholder="Customer id" />
      </div>
    </div>

    <SurfaceCard>
      <div v-if="loading" class="py-12 text-center text-sm text-content-muted">Loading…</div>

      <p v-else-if="isEmpty && isFiltered" class="py-12 text-center text-sm text-content-muted">
        No invoices match that.
      </p>

      <div v-else-if="isEmpty" class="py-12 text-center text-sm text-content-muted">
        <p>This company has no invoices yet.</p>
        <PermissionGate permission="sales.invoices.draft">
          <RouterLink :to="{ name: 'invoice-new' }" class="mt-3 inline-block">
            <BaseButton size="sm">New invoice</BaseButton>
          </RouterLink>
        </PermissionGate>
      </div>

      <template v-else>
        <div class="hidden overflow-x-auto md:block" role="region" aria-label="Invoices" tabindex="0">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="border-b border-surface-border text-left text-xs uppercase tracking-wide text-content-subtle">
                <th scope="col" class="py-2 pr-4">Number</th>
                <th scope="col" class="py-2 pr-4">Customer</th>
                <th scope="col" class="py-2 pr-4">Invoice date</th>
                <th scope="col" class="py-2 pr-4">Due date</th>
                <th scope="col" class="py-2 pr-4">Status</th>
                <th scope="col" class="py-2 pr-4 text-right">Total</th>
                <th scope="col" class="py-2"><span class="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="invoice in rows"
                :key="invoice.id"
                class="border-b border-surface-border/60"
                :class="invoice.status === 'cancelled' && 'opacity-60'"
              >
                <td class="py-2 pr-4">
                  <span v-if="invoice.number" class="font-mono text-xs text-content">{{ invoice.number }}</span>
                  <span v-else class="rounded bg-surface-sunken px-2 py-0.5 text-xs text-content-subtle">Draft</span>
                </td>
                <td class="py-2 pr-4 text-content">{{ invoice.customer?.name ?? '—' }}</td>
                <td class="py-2 pr-4 text-content-muted">{{ invoice.invoice_date }}</td>
                <td class="py-2 pr-4 text-content-muted">{{ invoice.due_date }}</td>
                <td class="py-2 pr-4">
                  <InvoiceStatusBadge :status="invoice.status" :status-label="invoice.status_label" />
                </td>
                <td class="py-2 pr-4 text-right font-mono tabular-nums text-content">{{ formatPlain(invoice.total) }}</td>
                <td class="py-2 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <RouterLink
                      v-if="invoice.capabilities.can_update"
                      :to="{ name: 'invoice-edit', params: { invoiceId: invoice.id } }"
                      class="text-xs text-primary-700 hover:underline dark:text-primary-400"
                    >
                      Edit
                    </RouterLink>
                    <RouterLink
                      v-else
                      :to="{ name: 'invoice-detail', params: { invoiceId: invoice.id } }"
                      class="text-xs text-primary-700 hover:underline dark:text-primary-400"
                    >
                      View
                    </RouterLink>

                    <OverflowMenu
                      v-if="invoice.capabilities.can_cancel || invoice.capabilities.can_delete"
                      :label="`More actions for invoice ${invoice.number ?? 'draft'}`"
                    >
                      <template #default="{ close }">
                        <button
                          v-if="invoice.capabilities.can_cancel"
                          type="button"
                          role="menuitem"
                          class="block w-full px-3 py-1.5 text-left text-sm text-content hover:bg-surface-sunken"
                          :disabled="rowBusy === invoice.id"
                          @click="openCancel(invoice); close()"
                        >
                          Cancel
                        </button>
                        <button
                          v-if="invoice.capabilities.can_delete"
                          type="button"
                          role="menuitem"
                          class="block w-full px-3 py-1.5 text-left text-sm text-danger hover:bg-surface-sunken"
                          :disabled="rowBusy === invoice.id"
                          @click="openDelete(invoice); close()"
                        >
                          Delete
                        </button>
                      </template>
                    </OverflowMenu>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Card fallback below `md` (design §1.8) — a bookkeeper triaging which invoices are
             drafts is exactly the audience this fallback targets, matching CustomersListPage's
             precedent. Same row actions, just stacked instead of columned. -->
        <ul class="space-y-2 md:hidden">
          <li
            v-for="invoice in rows"
            :key="invoice.id"
            class="rounded-md border border-surface-border p-3"
            :class="invoice.status === 'cancelled' && 'opacity-60'"
          >
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <RouterLink
                  :to="{ name: 'invoice-detail', params: { invoiceId: invoice.id } }"
                  class="text-sm font-medium text-content hover:underline"
                >
                  <span v-if="invoice.number" class="font-mono">{{ invoice.number }}</span>
                  <span v-else>Draft</span>
                </RouterLink>
                <p class="text-xs text-content-muted">
                  {{ invoice.customer?.name ?? '—' }} · {{ invoice.invoice_date }}
                </p>
              </div>

              <div class="flex shrink-0 items-center gap-2">
                <InvoiceStatusBadge :status="invoice.status" :status-label="invoice.status_label" />
                <RouterLink
                  v-if="invoice.capabilities.can_update"
                  :to="{ name: 'invoice-edit', params: { invoiceId: invoice.id } }"
                  class="text-xs text-primary-700 hover:underline dark:text-primary-400"
                >
                  Edit
                </RouterLink>

                <OverflowMenu
                  v-if="invoice.capabilities.can_cancel || invoice.capabilities.can_delete"
                  :label="`More actions for invoice ${invoice.number ?? 'draft'}`"
                >
                  <template #default="{ close }">
                    <button
                      v-if="invoice.capabilities.can_cancel"
                      type="button"
                      role="menuitem"
                      class="block w-full px-3 py-1.5 text-left text-sm text-content hover:bg-surface-sunken"
                      :disabled="rowBusy === invoice.id"
                      @click="openCancel(invoice); close()"
                    >
                      Cancel
                    </button>
                    <button
                      v-if="invoice.capabilities.can_delete"
                      type="button"
                      role="menuitem"
                      class="block w-full px-3 py-1.5 text-left text-sm text-danger hover:bg-surface-sunken"
                      :disabled="rowBusy === invoice.id"
                      @click="openDelete(invoice); close()"
                    >
                      Delete
                    </button>
                  </template>
                </OverflowMenu>
              </div>
            </div>

            <p class="mt-2 text-right font-mono text-sm tabular-nums text-content">
              {{ formatPlain(invoice.total) }}
            </p>
          </li>
        </ul>
      </template>

      <template v-if="pagination && pagination.last_page > 1" #footer>
        <Pagination :pagination="pagination" :disabled="loading" @update:page="load" />
      </template>
    </SurfaceCard>

    <CancelInvoiceDialog :open="cancelDialogOpen" @confirm="confirmCancel" @cancel="cancelDialogOpen = false" />

    <ConfirmDialog
      :open="deleteDialogOpen"
      mode="typed"
      danger
      title="Delete this draft invoice?"
      message="This is permanent — there is no restore."
      :confirm-token="null"
      confirm-label="Delete draft"
      @confirm="confirmDelete"
      @cancel="deleteDialogOpen = false"
    />
  </div>
</template>
