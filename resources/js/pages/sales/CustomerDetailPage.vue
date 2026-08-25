<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ApiError } from '@/api/client'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import CustomerLifecycleMenu from '@/components/sales/customers/CustomerLifecycleMenu.vue'
import CustomerStatusBadge from '@/components/sales/customers/CustomerStatusBadge.vue'
import { useCompanyReload } from '@/composables/useCompanyReload'
import { useMoney } from '@/composables/useMoney'
import { useUiStore } from '@/stores/ui'
import {
  archiveCustomer,
  deactivateCustomer,
  deleteCustomer,
  getCustomer,
  reactivateCustomer,
  restoreCustomer,
} from '@/api/customers'
import type { Customer } from '@/types/domain'

/**
 * The customer view screen (requirements §4.4/§4.5, design §2.1.4/§2.1.5).
 *
 * Every field of the `Customer` resource is rendered, grouped exactly as the create/edit form
 * groups them, so a reader's mental model of "where is X" carries across both screens. A `404`
 * (not found, or not accessible — the API deliberately does not distinguish the two) renders a
 * single generic not-found panel; the UI never tries to guess or state which of the two applies
 * (§4.4.4).
 */
defineOptions({ name: 'CustomerDetailPage' })

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const { formatPlain } = useMoney()

const customer = ref<Customer | null>(null)
const loading = ref(true)
const notFound = ref(false)

const customerId = computed<string>(() => route.params.customerId as string)

const { companyId } = useCompanyReload(load)

async function load(): Promise<void> {
  if (companyId.value === null) {
    loading.value = false
    return
  }

  loading.value = true
  notFound.value = false

  try {
    const { data } = await getCustomer(companyId.value, customerId.value)
    customer.value = data
  } catch (thrown) {
    customer.value = null

    if (thrown instanceof ApiError && thrown.status === 404) {
      notFound.value = true
    } else {
      ui.notify(
        'error',
        thrown instanceof ApiError ? thrown.problem.detail : 'Could not load the customer.',
      )
    }
  } finally {
    loading.value = false
  }
}

async function runLifecycleAction(action: () => Promise<unknown>, successMessage: string): Promise<void> {
  try {
    await action()
    ui.notify('success', successMessage)
    await load()
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not complete that.')
  }
}

function onArchive(): Promise<void> {
  return runLifecycleAction(
    () => archiveCustomer(companyId.value as string, customerId.value),
    'Customer archived.',
  )
}

function onRestore(): Promise<void> {
  return runLifecycleAction(
    () => restoreCustomer(companyId.value as string, customerId.value),
    'Customer restored.',
  )
}

function onDeactivate(): Promise<void> {
  return runLifecycleAction(
    () => deactivateCustomer(companyId.value as string, customerId.value),
    'Customer deactivated.',
  )
}

function onReactivate(): Promise<void> {
  return runLifecycleAction(
    () => reactivateCustomer(companyId.value as string, customerId.value),
    'Customer reactivated.',
  )
}

async function onDelete(): Promise<void> {
  if (companyId.value === null || customer.value === null) {
    return
  }

  const name = customer.value.name

  try {
    await deleteCustomer(companyId.value, customer.value.id)
    ui.notify('success', `${name} deleted.`)
    await router.push({ name: 'customers' })
  } catch (thrown) {
    if (thrown instanceof ApiError) {
      const detail = thrown.problem.detail
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
  <div class="mx-auto max-w-5xl space-y-5">
    <div v-if="loading" class="py-12 text-center text-sm text-content-muted">Loading…</div>

    <SurfaceCard v-else-if="notFound">
      <p class="py-12 text-center text-sm text-content-muted">
        We could not find that customer. It may not exist, or you may not have access to it.
      </p>
    </SurfaceCard>

    <template v-else-if="customer">
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-semibold text-content">{{ customer.name }}</h1>
          <p class="mt-1 flex items-center gap-2 text-sm text-content-muted">
            <span class="font-mono">{{ customer.code }}</span>
            <CustomerStatusBadge :status="customer.status" :status-label="customer.status_label" />
          </p>
          <p v-if="customer.status === 'archived' && customer.archived_at" class="mt-1 text-xs text-content-subtle">
            Archived on {{ customer.archived_at }}
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <RouterLink
            v-if="customer.capabilities.can_update"
            :to="{ name: 'customer-edit', params: { customerId: customer.id } }"
            class="text-sm text-primary-700 hover:underline dark:text-primary-400"
          >
            Edit
          </RouterLink>

          <CustomerLifecycleMenu
            :customer="customer"
            @archive="onArchive"
            @restore="onRestore"
            @deactivate="onDeactivate"
            @reactivate="onReactivate"
            @delete="onDelete"
          />
        </div>
      </header>

      <SurfaceCard title="Identity">
        <dl class="grid gap-4 sm:grid-cols-2">
          <div>
            <dt class="field-label">Code</dt>
            <dd class="font-mono text-sm text-content">{{ customer.code }}</dd>
          </div>
          <div>
            <dt class="field-label">Legal name</dt>
            <dd class="text-content">{{ customer.legal_name ?? '—' }}</dd>
          </div>
        </dl>
      </SurfaceCard>

      <SurfaceCard title="Contact">
        <dl class="grid gap-4 sm:grid-cols-2">
          <div>
            <dt class="field-label">Email</dt>
            <dd class="text-content">{{ customer.email ?? '—' }}</dd>
          </div>
          <div>
            <dt class="field-label">Phone</dt>
            <dd class="text-content">{{ customer.phone ?? '—' }}</dd>
          </div>
          <div>
            <dt class="field-label">Website</dt>
            <dd class="text-content">{{ customer.website ?? '—' }}</dd>
          </div>
        </dl>
      </SurfaceCard>

      <SurfaceCard title="Address">
        <dl class="grid gap-4 sm:grid-cols-2">
          <div class="sm:col-span-2">
            <dt class="field-label">Address</dt>
            <dd class="text-content">
              {{ [customer.address_line_1, customer.address_line_2].filter(Boolean).join(', ') || '—' }}
            </dd>
          </div>
          <div>
            <dt class="field-label">City</dt>
            <dd class="text-content">{{ customer.city ?? '—' }}</dd>
          </div>
          <div>
            <dt class="field-label">District</dt>
            <dd class="text-content">{{ customer.district ?? '—' }}</dd>
          </div>
          <div>
            <dt class="field-label">Postal code</dt>
            <dd class="text-content">{{ customer.postal_code ?? '—' }}</dd>
          </div>
          <div>
            <dt class="field-label">Country</dt>
            <dd class="text-content">{{ customer.country_code ?? '—' }}</dd>
          </div>
        </dl>
      </SurfaceCard>

      <SurfaceCard title="Tax">
        <dl class="grid gap-4 sm:grid-cols-2">
          <div>
            <dt class="field-label">Tax identification number</dt>
            <dd class="text-content">{{ customer.tax_identification_number ?? '—' }}</dd>
          </div>
          <div>
            <dt class="field-label">VAT registration number</dt>
            <dd class="text-content">{{ customer.vat_registration_number ?? '—' }}</dd>
          </div>
          <div>
            <dt class="field-label">VAT registered</dt>
            <dd class="text-content">{{ customer.is_vat_registered ? 'Yes' : 'No' }}</dd>
          </div>
        </dl>
      </SurfaceCard>

      <SurfaceCard title="Commercial terms">
        <dl class="grid gap-4 sm:grid-cols-2">
          <div>
            <dt class="field-label">Payment terms</dt>
            <dd class="text-content">{{ customer.payment_terms_days }} days</dd>
          </div>
          <div>
            <dt class="field-label">Credit limit</dt>
            <dd class="font-mono tabular-nums text-content">
              {{ customer.credit_limit ? formatPlain(customer.credit_limit) : 'No limit' }}
            </dd>
          </div>
          <div>
            <dt class="field-label">Receivable account</dt>
            <dd class="text-content">{{ customer.receivable_account_id ?? 'Company default' }}</dd>
          </div>
          <div>
            <dt class="field-label">Branch</dt>
            <dd class="text-content">{{ customer.branch_id ?? '—' }}</dd>
          </div>
        </dl>
      </SurfaceCard>

      <SurfaceCard v-if="customer.notes" title="Notes">
        <p class="whitespace-pre-line text-sm text-content">{{ customer.notes }}</p>
      </SurfaceCard>
    </template>
  </div>
</template>
