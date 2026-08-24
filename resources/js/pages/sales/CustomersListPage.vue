<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { ApiError } from '@/api/client'
import Pagination from '@/components/ui/Pagination.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import TextField from '@/components/ui/TextField.vue'
import PermissionGate from '@/components/app/PermissionGate.vue'
import CustomerLifecycleMenu from '@/components/sales/customers/CustomerLifecycleMenu.vue'
import CustomerStatusBadge from '@/components/sales/customers/CustomerStatusBadge.vue'
import { useCompanyReload } from '@/composables/useCompanyReload'
import { useMoney } from '@/composables/useMoney'
import { useUiStore } from '@/stores/ui'
import {
  archiveCustomer,
  deactivateCustomer,
  deleteCustomer,
  listCustomers,
  reactivateCustomer,
  restoreCustomer,
} from '@/api/customers'
import type { Customer } from '@/types/domain'
import type { ApiMeta } from '@/types/api'

/**
 * The customer list (requirements §4.1, design §2.1.1).
 *
 * Search and status are server parameters, never a client-side filter over an already-fetched
 * array — the list renders exactly the `data` the server returned. Search is debounced 300ms,
 * matching `UsersPage.vue`'s own pattern; a status change re-queries immediately, with no
 * debounce, per §4.1.3. The empty/loaded state is keyed on a successful response having
 * landed (`meta !== null`), never on `rows.length === 0` alone — the exact bug ADR 0011 D4
 * fixed on the reporting pages, re-imposed here so a failed request never falls through to
 * "no customers" copy.
 */
defineOptions({ name: 'CustomersListPage' })

const ui = useUiStore()
const { formatPlain } = useMoney()

const rows = ref<Customer[]>([])
const meta = ref<ApiMeta | null>(null)
const loading = ref(true)
const search = ref('')
const statusFilter = ref('')
const page = ref(1)

let searchTimer: number | undefined

const { companyId } = useCompanyReload(load)

const isFiltered = computed(() => search.value !== '' || statusFilter.value !== '')
const isEmpty = computed(() => meta.value !== null && rows.value.length === 0)

// Debounced, matching `UsersPage.vue`'s `watch([search, statusFilter], …)` precedent — split in
// two here because §4.1.2/§4.1.3 want different timing per control: search waits for a pause,
// a status change re-queries immediately.
watch(search, () => {
  window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => void load(1), 300)
})

watch(statusFilter, () => {
  void load(1)
})

async function load(requestedPage = 1): Promise<void> {
  if (companyId.value === null) {
    loading.value = false
    return
  }

  loading.value = true

  try {
    const response = await listCustomers(companyId.value, {
      page: requestedPage,
      q: search.value || undefined,
      filter: statusFilter.value ? { status: statusFilter.value as Customer['status'] } : undefined,
    })

    rows.value = response.data
    meta.value = response.meta
    page.value = requestedPage
  } catch (thrown) {
    // Cleared, not left showing the previous successful response — a stale row under a
    // refusal notice is indistinguishable from a current one (ADR 0011 D4).
    rows.value = []
    meta.value = null
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not load customers.')
  } finally {
    loading.value = false
  }
}

async function runLifecycleAction(action: () => Promise<unknown>, successMessage: string): Promise<void> {
  try {
    await action()
    ui.notify('success', successMessage)
    await load(page.value)
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not complete that.')
  }
}

function onArchive(customer: Customer): Promise<void> {
  return runLifecycleAction(
    () => archiveCustomer(companyId.value as string, customer.id),
    `${customer.name} archived.`,
  )
}

function onRestore(customer: Customer): Promise<void> {
  return runLifecycleAction(
    () => restoreCustomer(companyId.value as string, customer.id),
    `${customer.name} restored.`,
  )
}

function onDeactivate(customer: Customer): Promise<void> {
  return runLifecycleAction(
    () => deactivateCustomer(companyId.value as string, customer.id),
    `${customer.name} deactivated.`,
  )
}

function onReactivate(customer: Customer): Promise<void> {
  return runLifecycleAction(
    () => reactivateCustomer(companyId.value as string, customer.id),
    `${customer.name} reactivated.`,
  )
}

async function onDelete(customer: Customer): Promise<void> {
  if (companyId.value === null) {
    return
  }

  try {
    await deleteCustomer(companyId.value, customer.id)
    ui.notify('success', `${customer.name} deleted.`)
    await load(page.value)
  } catch (thrown) {
    if (thrown instanceof ApiError) {
      const detail = thrown.problem.detail
      // §4.5.3: a customer already named on an invoice cannot be hard-deleted — the notice
      // names archiving as the ordinary alternative rather than leaving a dead end.
      const suggestion = detail.toLowerCase().includes('invoice')
        ? ' Archiving keeps the record while removing it from active use.'
        : ''

      ui.notify('error', `${detail}${suggestion}`)
      return
    }

    throw thrown
  }
}
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-content">Customers</h1>
        <p class="mt-1 text-sm text-content-muted">Who you invoice, and what they owe you.</p>
      </div>

      <PermissionGate permission="sales.customers.manage">
        <RouterLink
          :to="{ name: 'customer-new' }"
          class="inline-flex items-center justify-center gap-2 rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-primary-700 focus-visible:ring-2 focus-visible:ring-primary-500"
        >
          Add a customer
        </RouterLink>
      </PermissionGate>
    </header>

    <div class="flex flex-wrap gap-3">
      <div class="min-w-56 flex-1">
        <TextField v-model="search" label="Search" placeholder="Name or code" />
      </div>
      <div>
        <label class="field-label" for="customer-status-filter">Status</label>
        <select
          id="customer-status-filter"
          v-model="statusFilter"
          class="form-select mt-1 rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
        >
          <option value="">All</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="archived">Archived</option>
        </select>
      </div>
    </div>

    <SurfaceCard>
      <div v-if="loading" class="py-12 text-center text-sm text-content-muted">Loading…</div>

      <template v-else-if="isEmpty">
        <p v-if="isFiltered" class="py-12 text-center text-sm text-content-muted">
          No customers match that search.
        </p>
        <div v-else class="py-12 text-center">
          <p class="text-sm text-content-muted">This company has no customers yet.</p>
          <PermissionGate permission="sales.customers.manage">
            <RouterLink
              :to="{ name: 'customer-new' }"
              class="mt-3 inline-block text-sm text-primary-700 hover:underline dark:text-primary-400"
            >
              Add a customer
            </RouterLink>
          </PermissionGate>
        </div>
      </template>

      <template v-else>
        <div
          class="hidden overflow-x-auto md:block"
          role="region"
          aria-label="Customers"
          tabindex="0"
        >
          <table class="min-w-full text-sm">
            <thead>
              <tr class="border-b border-surface-border text-left text-xs uppercase tracking-wide text-content-subtle">
                <th scope="col" class="py-2 pr-4">Code</th>
                <th scope="col" class="py-2 pr-4">Name</th>
                <th scope="col" class="py-2 pr-4">Status</th>
                <th scope="col" class="py-2 pr-4">Branch</th>
                <th scope="col" class="py-2 pr-4 text-right">Credit limit</th>
                <th scope="col" class="py-2 pr-4"><span class="sr-only">Actions</span></th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="row in rows"
                :key="row.id"
                class="border-b border-surface-border/60"
                :class="row.status === 'archived' && 'opacity-60'"
              >
                <td class="py-2 pr-4 font-mono text-xs text-content">{{ row.code }}</td>
                <td class="py-2 pr-4 text-content">
                  <RouterLink
                    :to="{ name: 'customer-detail', params: { customerId: row.id } }"
                    class="hover:underline"
                  >
                    {{ row.name }}
                  </RouterLink>
                </td>
                <td class="py-2 pr-4">
                  <CustomerStatusBadge :status="row.status" :status-label="row.status_label" />
                </td>
                <td class="py-2 pr-4 text-content-muted">{{ row.branch_id ?? '—' }}</td>
                <td class="py-2 pr-4 text-right font-mono tabular-nums text-content">
                  {{ row.credit_limit ? formatPlain(row.credit_limit) : '—' }}
                </td>
                <td class="py-2 pr-4">
                  <div class="flex items-center justify-end gap-2">
                    <RouterLink
                      v-if="row.capabilities.can_update"
                      :to="{ name: 'customer-edit', params: { customerId: row.id } }"
                      class="text-xs text-primary-700 hover:underline dark:text-primary-400"
                    >
                      Edit
                    </RouterLink>

                    <CustomerLifecycleMenu
                      :customer="row"
                      @archive="onArchive(row)"
                      @restore="onRestore(row)"
                      @deactivate="onDeactivate(row)"
                      @reactivate="onReactivate(row)"
                      @delete="onDelete(row)"
                    />
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Card fallback below `md` (design §1.8) — a customer list is a task surface a
             bookkeeper may act on from a phone, not a report glanced at, so horizontal scroll
             alone is not sufficient here. -->
        <ul class="space-y-2 md:hidden">
          <li
            v-for="row in rows"
            :key="row.id"
            class="rounded-md border border-surface-border p-3"
            :class="row.status === 'archived' && 'opacity-60'"
          >
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <RouterLink
                  :to="{ name: 'customer-detail', params: { customerId: row.id } }"
                  class="text-sm font-medium text-content hover:underline"
                >
                  {{ row.name }}
                </RouterLink>
                <p class="text-xs text-content-muted">{{ row.code }}</p>
              </div>

              <div class="flex shrink-0 items-center gap-2">
                <CustomerStatusBadge :status="row.status" :status-label="row.status_label" />
                <RouterLink
                  v-if="row.capabilities.can_update"
                  :to="{ name: 'customer-edit', params: { customerId: row.id } }"
                  class="text-xs text-primary-700 hover:underline dark:text-primary-400"
                >
                  Edit
                </RouterLink>
                <CustomerLifecycleMenu
                  :customer="row"
                  @archive="onArchive(row)"
                  @restore="onRestore(row)"
                  @deactivate="onDeactivate(row)"
                  @reactivate="onReactivate(row)"
                  @delete="onDelete(row)"
                />
              </div>
            </div>

            <p class="mt-2 text-right font-mono text-sm tabular-nums text-content">
              {{ row.credit_limit ? formatPlain(row.credit_limit) : '—' }}
            </p>
          </li>
        </ul>
      </template>

      <template v-if="meta?.pagination" #footer>
        <Pagination :pagination="meta.pagination" :disabled="loading" @update:page="load" />
      </template>
    </SurfaceCard>
  </div>
</template>
