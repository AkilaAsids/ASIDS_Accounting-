<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import TextField from '@/components/ui/TextField.vue'
import PermissionGate from '@/components/app/PermissionGate.vue'
import InvoiceLineEditor from '@/components/sales/invoices/InvoiceLineEditor.vue'
import InvoiceTotals from '@/components/sales/invoices/InvoiceTotals.vue'
import {
  blankLine,
  lineFromApi,
  lineToPayload,
  mapLineErrors,
  type LineDraft,
} from '@/components/sales/invoices/lineDraft'
import { createSalesInvoice, getSalesInvoice, updateSalesInvoice } from '@/api/salesInvoices'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { useUnsavedGuard } from '@/composables/useUnsavedGuard'
import type { Account, Customer, SalesInvoice, SalesInvoiceInput } from '@/types/domain'

/**
 * Invoice draft create/edit — the highest-risk screen in the wave (ADR 0013 §7, requirements
 * §4.7/§4.8). Serves both `invoice-new` and `invoice-edit`; the router tells them apart by
 * whether `route.params.invoiceId` is present.
 *
 * THE ABSOLUTE RULE: no money arithmetic anywhere in this file. `savedTotals` is `null`
 * until the first successful save and is only ever assigned from a response body —
 * `InvoiceTotals` renders those strings verbatim (§1.5/§7.4).
 */
defineOptions({ name: 'SalesInvoiceEditorPage' })

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const invoiceId = computed<string | null>(() =>
  typeof route.params.invoiceId === 'string' ? route.params.invoiceId : null,
)
const isEdit = computed<boolean>(() => invoiceId.value !== null)
const companyId = computed<string | null>(() => auth.activeCompany?.id ?? null)

const loading = ref(false)
const busy = ref(false)
const loadFailed = ref(false)

const customers = ref<Customer[]>([])
const accounts = ref<Account[]>([])

// Header form state.
const customerId = ref('')
const invoiceDate = ref(new Date().toISOString().slice(0, 10))
const dueDate = ref('')
const dueDateCleared = ref(false)
const reference = ref('')
const referenceCleared = ref(false)
const branchId = ref('')
const branchIdCleared = ref(false)
const notes = ref('')
const terms = ref('')
const headerDiscountAmount = ref('')
const headerDiscountAmountCleared = ref(false)
const issueImmediately = ref(false)

const lines = ref<LineDraft[]>([blankLine()])

interface OriginalSnapshot {
  customer_id: string
  invoice_date: string
  reference: string | null
  branch_id: string | null
  due_date: string | null
  notes: string | null
  terms: string | null
}

const original = ref<OriginalSnapshot | null>(null)

const fieldErrors = ref<Record<string, string>>({})
const lineErrors = ref<Record<number, Record<string, string>>>({})

interface SavedTotals {
  subtotal: string
  discount_total: string
  tax_total: string
  total: string
  amount_paid: string
  amount_due: string
}

/** `null` until the first successful save — see the module docblock. */
const savedTotals = ref<SavedTotals | null>(null)

const dirty = ref(false)
/** Guards the deep watch below against firing while `loadInvoice()` populates the form. */
const ready = ref(false)

useUnsavedGuard(() => dirty.value)

watch(
  [customerId, invoiceDate, dueDate, reference, branchId, notes, terms, headerDiscountAmount, issueImmediately, lines],
  () => {
    if (ready.value) {
      dirty.value = true
    }
  },
  { deep: true },
)

// A company switch mid-edit is not survivable for an existing draft — an editor for company
// A's invoice is meaningless under company B (§6). `CompanySwitcher.select()` already asks
// "discard unsaved changes?" via `useUnsavedGuard` before the switch commits; once it has,
// this page has nothing sensible left to show, so it leaves for the list.
watch(companyId, (id, previous) => {
  if (previous !== undefined && id !== previous) {
    void router.push({ name: 'invoices' })
  }
})

onMounted(async () => {
  await Promise.all([loadCustomers(), loadAccounts()])

  if (isEdit.value) {
    await loadInvoice()
  }

  await nextTick()
  ready.value = true
})

async function loadCustomers(): Promise<void> {
  if (companyId.value === null) {
    return
  }

  try {
    const { data } = await api.get<Customer[]>(`/companies/${companyId.value}/customers`, { per_page: 100 })
    customers.value = data
  } catch {
    customers.value = []
  }
}

async function loadAccounts(): Promise<void> {
  if (companyId.value === null) {
    return
  }

  try {
    const { data } = await api.get<Account[]>(`/companies/${companyId.value}/accounts`, { active_only: true })
    accounts.value = data
  } catch {
    accounts.value = []
  }
}

async function loadInvoice(): Promise<void> {
  if (companyId.value === null || invoiceId.value === null) {
    return
  }

  loading.value = true

  try {
    const { data } = await getSalesInvoice(companyId.value, invoiceId.value)

    customerId.value = data.customer_id
    invoiceDate.value = data.invoice_date
    dueDate.value = data.due_date ?? ''
    reference.value = data.reference ?? ''
    branchId.value = data.branch_id ?? ''
    notes.value = data.notes ?? ''
    terms.value = data.terms ?? ''
    headerDiscountAmount.value = ''
    lines.value = (data.lines ?? []).map(lineFromApi)

    original.value = {
      customer_id: data.customer_id,
      invoice_date: data.invoice_date,
      reference: data.reference,
      branch_id: data.branch_id,
      due_date: data.due_date,
      notes: data.notes,
      terms: data.terms,
    }

    savedTotals.value = {
      subtotal: data.subtotal,
      discount_total: data.discount_total,
      tax_total: data.tax_total,
      total: data.total,
      amount_paid: data.amount_paid,
      amount_due: data.amount_due,
    }
  } catch (thrown) {
    loadFailed.value = true
    ui.notify(
      'error',
      thrown instanceof ApiError ? thrown.problem.detail : 'Could not load this invoice.',
    )
  } finally {
    loading.value = false
  }
}

function clearReference(): void {
  referenceCleared.value = true
  reference.value = ''
}

function clearBranchId(): void {
  branchIdCleared.value = true
  branchId.value = ''
}

function clearHeaderDiscount(): void {
  headerDiscountAmountCleared.value = true
  headerDiscountAmount.value = ''
}

function clearDueDate(): void {
  dueDateCleared.value = true
  dueDate.value = ''
}

function buildCreatePayload(): SalesInvoiceInput {
  const payload: SalesInvoiceInput = {
    customer_id: customerId.value,
    invoice_date: invoiceDate.value,
    lines: lines.value.map(lineToPayload),
  }

  if (dueDate.value !== '') payload.due_date = dueDate.value
  if (reference.value !== '') payload.reference = reference.value
  if (branchId.value !== '') payload.branch_id = branchId.value
  if (notes.value !== '') payload.notes = notes.value
  if (terms.value !== '') payload.terms = terms.value
  if (headerDiscountAmount.value !== '') payload.discount_amount = headerDiscountAmount.value
  if (issueImmediately.value) payload.issue = true

  return payload
}

/**
 * The clear-vs-omit diff for edit (§4.8.2): an untouched field is omitted, an explicitly
 * cleared one is sent as `null`, and `lines` — present on every submit — always replaces
 * every line (§4.8.4), never a partial patch.
 */
function buildEditPayload(): Partial<SalesInvoiceInput> {
  const baseline = original.value
  const payload: Partial<SalesInvoiceInput> = { lines: lines.value.map(lineToPayload) }

  if (baseline === null) {
    return payload
  }

  if (customerId.value !== baseline.customer_id) payload.customer_id = customerId.value
  if (invoiceDate.value !== baseline.invoice_date) payload.invoice_date = invoiceDate.value

  if (referenceCleared.value) {
    payload.reference = null
  } else if (reference.value !== (baseline.reference ?? '')) {
    payload.reference = reference.value
  }

  if (branchIdCleared.value) {
    payload.branch_id = null
  } else if (branchId.value !== (baseline.branch_id ?? '')) {
    payload.branch_id = branchId.value
  }

  if (dueDateCleared.value) {
    payload.due_date = null
  } else if (dueDate.value !== (baseline.due_date ?? '')) {
    payload.due_date = dueDate.value
  }

  if (headerDiscountAmountCleared.value) {
    payload.discount_amount = null
  } else if (headerDiscountAmount.value !== '') {
    payload.discount_amount = headerDiscountAmount.value
  }

  if (notes.value !== (baseline.notes ?? '')) payload.notes = notes.value
  if (terms.value !== (baseline.terms ?? '')) payload.terms = terms.value

  return payload
}

function applySavedInvoice(invoice: SalesInvoice): void {
  lines.value = (invoice.lines ?? []).map(lineFromApi)

  original.value = {
    customer_id: invoice.customer_id,
    invoice_date: invoice.invoice_date,
    reference: invoice.reference,
    branch_id: invoice.branch_id,
    due_date: invoice.due_date,
    notes: invoice.notes,
    terms: invoice.terms,
  }

  reference.value = invoice.reference ?? ''
  branchId.value = invoice.branch_id ?? ''
  dueDate.value = invoice.due_date ?? ''
  referenceCleared.value = false
  branchIdCleared.value = false
  dueDateCleared.value = false
  headerDiscountAmountCleared.value = false
  headerDiscountAmount.value = ''

  savedTotals.value = {
    subtotal: invoice.subtotal,
    discount_total: invoice.discount_total,
    tax_total: invoice.tax_total,
    total: invoice.total,
    amount_paid: invoice.amount_paid,
    amount_due: invoice.amount_due,
  }
}

async function submit(): Promise<void> {
  if (companyId.value === null || busy.value) {
    return
  }

  busy.value = true
  fieldErrors.value = {}
  lineErrors.value = {}

  try {
    const response = isEdit.value
      ? await updateSalesInvoice(companyId.value, invoiceId.value as string, buildEditPayload())
      : await createSalesInvoice(companyId.value, buildCreatePayload())

    const saved = response.data

    // Suppressed around the reassignment so the deep watch above does not immediately mark
    // the freshly-saved state as dirty again.
    ready.value = false
    applySavedInvoice(saved)
    await nextTick()
    dirty.value = false
    ready.value = true

    ui.notify('success', isEdit.value ? 'Invoice saved.' : 'Invoice saved as a draft.')

    if (!isEdit.value) {
      await router.push(
        saved.status === 'draft'
          ? { name: 'invoice-edit', params: { invoiceId: saved.id } }
          : { name: 'invoice-detail', params: { invoiceId: saved.id } },
      )
    }
  } catch (thrown) {
    if (thrown instanceof ApiError) {
      const { header, lines: mappedLineErrors } = mapLineErrors(thrown.fieldErrors)
      fieldErrors.value = header
      lineErrors.value = mappedLineErrors

      if (Object.keys(thrown.fieldErrors).length === 0) {
        ui.notify('error', thrown.problem.detail)
      }

      return
    }

    throw thrown
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-content">{{ isEdit ? 'Edit invoice' : 'New invoice' }}</h1>
      <p class="mt-1 text-sm text-content-muted">
        Totals finalise on save — the server computes them, this screen never does.
      </p>
    </header>

    <div v-if="loading" class="py-12 text-center text-sm text-content-muted">Loading…</div>

    <AlertBanner v-else-if="loadFailed" kind="error" title="Could not load this invoice">
      It may have been removed, or it may belong to a different company.
    </AlertBanner>

    <form v-else novalidate class="space-y-5" @submit.prevent="submit">
      <SurfaceCard title="Details">
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label class="field-label" for="invoice-customer">Customer<span class="text-danger" aria-hidden="true"> *</span></label>
            <select
              id="invoice-customer"
              v-model="customerId"
              required
              class="form-select mt-1 block w-full rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
              :class="fieldErrors.customer_id && 'border-danger focus:border-danger focus:ring-danger'"
            >
              <option value="">Select a customer</option>
              <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                {{ customer.code }} — {{ customer.name }}
              </option>
            </select>
            <p v-if="fieldErrors.customer_id" class="field-error" role="alert">{{ fieldErrors.customer_id }}</p>
          </div>

          <TextField v-model="invoiceDate" type="date" label="Invoice date" required :error="fieldErrors.invoice_date" />

          <div>
            <div class="flex items-end gap-2">
              <div class="flex-1">
                <TextField v-model="reference" label="Reference" :error="fieldErrors.reference" @update:model-value="referenceCleared = false" />
              </div>
              <button v-if="isEdit" type="button" class="mb-1 text-xs text-content-muted underline hover:text-content" @click="clearReference">
                Clear
              </button>
            </div>
          </div>

          <div>
            <div class="flex items-end gap-2">
              <div class="flex-1">
                <TextField v-model="branchId" label="Branch" :error="fieldErrors.branch_id" @update:model-value="branchIdCleared = false" />
              </div>
              <button v-if="isEdit" type="button" class="mb-1 text-xs text-content-muted underline hover:text-content" @click="clearBranchId">
                Clear
              </button>
            </div>
          </div>

          <div>
            <div class="flex items-end gap-2">
              <div class="flex-1">
                <TextField
                  v-model="headerDiscountAmount"
                  label="Header discount amount"
                  hint="Spread across your lines when you save."
                  :error="fieldErrors.discount_amount"
                  @update:model-value="headerDiscountAmountCleared = false"
                />
              </div>
              <button v-if="isEdit" type="button" class="mb-1 text-xs text-content-muted underline hover:text-content" @click="clearHeaderDiscount">
                Clear
              </button>
            </div>
          </div>

          <div>
            <div class="flex items-end gap-2">
              <div class="flex-1">
                <TextField
                  v-model="dueDate"
                  type="date"
                  label="Due date"
                  :hint="dueDateCleared ? 'Clearing this re-derives the due date from the customer’s payment terms — it will not stay blank.' : 'Leave blank to use the customer’s payment terms.'"
                  :error="fieldErrors.due_date"
                  @update:model-value="dueDateCleared = false"
                />
              </div>
              <button v-if="isEdit" type="button" class="mb-1 text-xs text-content-muted underline hover:text-content" @click="clearDueDate">
                Clear
              </button>
            </div>
          </div>

          <div class="sm:col-span-2">
            <label class="field-label" for="invoice-notes">Notes</label>
            <textarea
              id="invoice-notes"
              v-model="notes"
              rows="2"
              class="form-textarea mt-1 block w-full rounded-md border-surface-border bg-surface-raised text-sm text-content shadow-sm focus:border-primary-500 focus:ring-primary-500"
            />
          </div>

          <div class="sm:col-span-2">
            <label class="field-label" for="invoice-terms">Terms</label>
            <textarea
              id="invoice-terms"
              v-model="terms"
              rows="2"
              class="form-textarea mt-1 block w-full rounded-md border-surface-border bg-surface-raised text-sm text-content shadow-sm focus:border-primary-500 focus:ring-primary-500"
            />
          </div>
        </div>

        <PermissionGate v-if="!isEdit" permission="sales.invoices.issue">
          <div class="mt-4 flex items-center gap-2">
            <input
              id="issue-immediately"
              v-model="issueImmediately"
              type="checkbox"
              class="form-checkbox rounded border-surface-border text-primary-600 focus:ring-primary-500"
            />
            <label for="issue-immediately" class="text-sm text-content">Issue immediately.</label>
          </div>
        </PermissionGate>
      </SurfaceCard>

      <SurfaceCard title="Invoice lines">
        <p v-if="isEdit" class="mb-3 text-xs text-content-muted">Saving replaces every line above.</p>
        <InvoiceLineEditor v-model:lines="lines" :accounts="accounts" :company-id="companyId" :line-errors="lineErrors" />

        <div class="mt-4 flex justify-end">
          <InvoiceTotals
            :subtotal="savedTotals?.subtotal ?? null"
            :discount-total="savedTotals?.discount_total ?? null"
            :tax-total="savedTotals?.tax_total ?? null"
            :total="savedTotals?.total ?? null"
          />
        </div>

        <template #footer>
          <div class="flex justify-end">
            <BaseButton type="submit" :loading="busy">
              {{ isEdit ? 'Save changes' : issueImmediately ? 'Save and issue' : 'Save draft' }}
            </BaseButton>
          </div>
        </template>
      </SurfaceCard>
    </form>
  </div>
</template>
