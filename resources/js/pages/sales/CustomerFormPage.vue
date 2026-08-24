<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError } from '@/api/client'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import CustomerForm from '@/components/sales/customers/CustomerForm.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import { useUnsavedGuard } from '@/composables/useUnsavedGuard'
import {
  createCustomer,
  getCustomer,
  updateCustomer,
  type CustomerCreatePayload,
  type CustomerUpdatePayload,
} from '@/api/customers'
import type { Customer } from '@/types/domain'

/**
 * Customer create/edit (requirements §4.2/§4.3, design §2.1.2/§2.1.3).
 *
 * Serves both `customer-new` and `customer-edit` — the router distinguishes them by whether
 * `route.params.customerId` is present, matching the pre-step scaffold's own comment. All of
 * the clear-vs-omit payload logic lives in `CustomerForm.vue`; this page's job is only to load
 * the existing customer (edit) and send whatever payload the form emits, exactly as
 * `ChartOfAccountsPage.create()` handles its own request/response cycle.
 */
defineOptions({ name: 'CustomerFormPage' })

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const customerId = computed<string | null>(() =>
  typeof route.params.customerId === 'string' && route.params.customerId !== ''
    ? route.params.customerId
    : null,
)
const isEdit = computed(() => customerId.value !== null)

const companyId = computed<string | null>(() => auth.activeCompany?.id ?? null)

const customer = ref<Customer | null>(null)
const loading = ref(isEdit.value)
const busy = ref(false)
const fieldErrors = ref<Record<string, string>>({})
const dirty = ref(false)

// Company-switch-mid-edit (Gate-1 #6) is handled at the choke point that can still abort the
// switch — `CompanySwitcher.select()` — via this registry, not by a `watch` here.
useUnsavedGuard(() => dirty.value)

onMounted(load)

async function load(): Promise<void> {
  if (!isEdit.value || companyId.value === null || customerId.value === null) {
    loading.value = false
    return
  }

  loading.value = true

  try {
    const { data } = await getCustomer(companyId.value, customerId.value)
    customer.value = data
  } catch (thrown) {
    ui.notify(
      'error',
      thrown instanceof ApiError ? thrown.problem.detail : 'Could not load the customer.',
    )
  } finally {
    loading.value = false
  }
}

function onDirty(value: boolean): void {
  dirty.value = value
}

async function onSubmit(payload: Record<string, unknown>): Promise<void> {
  if (companyId.value === null) {
    return
  }

  busy.value = true
  fieldErrors.value = {}

  try {
    if (isEdit.value && customerId.value !== null) {
      const { data } = await updateCustomer(
        companyId.value,
        customerId.value,
        payload as CustomerUpdatePayload,
      )
      dirty.value = false
      ui.notify('success', `${data.name} updated.`)
      await router.push({ name: 'customer-detail', params: { customerId: data.id } })
    } else {
      const { data } = await createCustomer(companyId.value, payload as CustomerCreatePayload)
      dirty.value = false
      ui.notify('success', `${data.name} created.`)
      await router.push({ name: 'customer-detail', params: { customerId: data.id } })
    }
  } catch (thrown) {
    if (thrown instanceof ApiError) {
      fieldErrors.value = thrown.fieldErrors

      // A conflict or other domain refusal arrives with no field to pin it to — surfaced as a
      // notice rather than invented as a fake field error (§4.2.4/§4.3.4).
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
      <h1 class="text-2xl font-semibold text-content">
        {{ isEdit ? 'Edit customer' : 'Add a customer' }}
      </h1>
      <p class="mt-1 text-sm text-content-muted">
        <template v-if="isEdit">
          Change only what needs to change — everything else is left as it is.
        </template>
        <template v-else> A code can be left blank to auto-generate one. </template>
      </p>
    </header>

    <div v-if="loading" class="py-12 text-center text-sm text-content-muted">Loading…</div>

    <SurfaceCard v-else>
      <CustomerForm
        :customer="customer"
        :busy="busy"
        :field-errors="fieldErrors"
        @submit="onSubmit"
        @update:dirty="onDirty"
      />
    </SurfaceCard>
  </div>
</template>
