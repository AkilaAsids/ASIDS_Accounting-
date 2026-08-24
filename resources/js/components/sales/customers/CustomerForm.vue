<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import TextField from '@/components/ui/TextField.vue'
import type { Customer } from '@/types/domain'

/**
 * The create/edit form body (ADR 0013 §1: "owns the clear-vs-omit payload construction").
 *
 * Create and edit share this one implementation, distinguished only by whether `customer` is
 * supplied. In edit mode, only fields the reader actually changes are included in the emitted
 * payload — an untouched field is omitted entirely, never re-sent as its current value — and
 * `branch_id` / `receivable_account_id` / `credit_limit` each carry a dedicated "Clear" control
 * (design §2.1.3) so an explicit clear is distinguishable from a field the reader never
 * touched (requirements §4.3.1/§4.3.2, the single most acceptance-critical behaviour on this
 * screen). `status` has no field here at all — it cannot be sent, structurally (§4.2.7).
 *
 * This component never calls `api` — it only emits a normalised payload for the page to send,
 * and reports its own dirtiness so the page can register it with `useUnsavedGuard`.
 */
const props = withDefaults(
  defineProps<{
    customer?: Customer | null
    busy?: boolean
    fieldErrors?: Record<string, string>
  }>(),
  { customer: null, busy: false, fieldErrors: () => ({}) },
)

const emit = defineEmits<{
  submit: [payload: Record<string, unknown>]
  'update:dirty': [boolean]
}>()

const isEdit = computed(() => props.customer != null)

interface FormState {
  code: string
  name: string
  legal_name: string
  tax_identification_number: string
  vat_registration_number: string
  is_vat_registered: boolean
  email: string
  phone: string
  website: string
  address_line_1: string
  address_line_2: string
  city: string
  district: string
  postal_code: string
  country_code: string
  payment_terms_days: string
  credit_limit: string
  receivable_account_id: string
  branch_id: string
  notes: string
}

function toFormState(customer: Customer | null): FormState {
  return {
    code: customer?.code ?? '',
    name: customer?.name ?? '',
    legal_name: customer?.legal_name ?? '',
    tax_identification_number: customer?.tax_identification_number ?? '',
    vat_registration_number: customer?.vat_registration_number ?? '',
    is_vat_registered: customer?.is_vat_registered ?? false,
    email: customer?.email ?? '',
    phone: customer?.phone ?? '',
    website: customer?.website ?? '',
    address_line_1: customer?.address_line_1 ?? '',
    address_line_2: customer?.address_line_2 ?? '',
    city: customer?.city ?? '',
    district: customer?.district ?? '',
    postal_code: customer?.postal_code ?? '',
    country_code: customer?.country_code ?? '',
    payment_terms_days: String(customer?.payment_terms_days ?? 30),
    credit_limit: customer?.credit_limit ?? '',
    receivable_account_id: customer?.receivable_account_id ?? '',
    branch_id: customer?.branch_id ?? '',
    notes: customer?.notes ?? '',
  }
}

const original = ref<FormState>(toFormState(props.customer))
const form = reactive<FormState>(toFormState(props.customer))

const clearedBranchId = ref(false)
const clearedReceivableAccountId = ref(false)
const clearedCreditLimit = ref(false)

// Re-seeds when the page swaps in a freshly fetched customer — the edit route mounts this
// component before its own `load()` resolves, so `customer` arrives a tick after mount.
watch(
  () => props.customer,
  (customer) => {
    original.value = toFormState(customer)
    Object.assign(form, toFormState(customer))
    clearedBranchId.value = false
    clearedReceivableAccountId.value = false
    clearedCreditLimit.value = false
  },
)

const isDirty = computed(() => {
  if (clearedBranchId.value || clearedReceivableAccountId.value || clearedCreditLimit.value) {
    return true
  }

  return (Object.keys(form) as Array<keyof FormState>).some(
    (key) => form[key] !== original.value[key],
  )
})

watch(isDirty, (value) => emit('update:dirty', value))

function clearBranchId(): void {
  form.branch_id = ''
  clearedBranchId.value = true
}

function clearReceivableAccountId(): void {
  form.receivable_account_id = ''
  clearedReceivableAccountId.value = true
}

function clearCreditLimit(): void {
  form.credit_limit = ''
  clearedCreditLimit.value = true
}

/** Every plain optional string field that follows the ordinary omit-if-unchanged rule — the
 *  three fields with their own "Clear" control (`branch_id`, `receivable_account_id`,
 *  `credit_limit`) are handled separately below since they need the explicit-null path. */
const STRING_FIELDS = [
  'legal_name',
  'tax_identification_number',
  'vat_registration_number',
  'email',
  'phone',
  'website',
  'address_line_1',
  'address_line_2',
  'city',
  'district',
  'postal_code',
  'country_code',
  'notes',
] as const satisfies ReadonlyArray<keyof FormState>

function buildCreatePayload(): Record<string, unknown> {
  const payload: Record<string, unknown> = { name: form.name.trim() }

  if (form.code.trim() !== '') {
    payload.code = form.code.trim()
  }

  for (const field of STRING_FIELDS) {
    const value = form[field].trim()

    if (value !== '') {
      payload[field] = value
    }
  }

  payload.is_vat_registered = form.is_vat_registered

  const days = Number(form.payment_terms_days)
  payload.payment_terms_days = Number.isFinite(days) ? days : 30

  if (form.credit_limit.trim() !== '') {
    payload.credit_limit = form.credit_limit.trim()
  }

  if (form.receivable_account_id.trim() !== '') {
    payload.receivable_account_id = form.receivable_account_id.trim()
  }

  if (form.branch_id.trim() !== '') {
    payload.branch_id = form.branch_id.trim()
  }

  return payload
}

function buildUpdatePayload(): Record<string, unknown> {
  const payload: Record<string, unknown> = {}

  if (form.name.trim() !== original.value.name) {
    payload.name = form.name.trim()
  }

  if (form.code.trim() !== original.value.code) {
    payload.code = form.code.trim()
  }

  for (const field of STRING_FIELDS) {
    const current = form[field].trim()
    const currentOrNull = current === '' ? null : current
    const originalTrimmed = original.value[field].trim()
    const originalOrNull = originalTrimmed === '' ? null : originalTrimmed

    if (currentOrNull !== originalOrNull) {
      payload[field] = currentOrNull
    }
  }

  if (form.is_vat_registered !== original.value.is_vat_registered) {
    payload.is_vat_registered = form.is_vat_registered
  }

  const days = Number(form.payment_terms_days)
  if (Number.isFinite(days) && days !== Number(original.value.payment_terms_days)) {
    payload.payment_terms_days = days
  }

  if (clearedBranchId.value) {
    payload.branch_id = null
  } else if (form.branch_id.trim() !== original.value.branch_id) {
    payload.branch_id = form.branch_id.trim()
  }

  if (clearedReceivableAccountId.value) {
    payload.receivable_account_id = null
  } else if (form.receivable_account_id.trim() !== original.value.receivable_account_id) {
    payload.receivable_account_id = form.receivable_account_id.trim()
  }

  if (clearedCreditLimit.value) {
    payload.credit_limit = null
  } else if (form.credit_limit.trim() !== original.value.credit_limit) {
    payload.credit_limit = form.credit_limit.trim()
  }

  return payload
}

function onSubmit(): void {
  emit('submit', isEdit.value ? buildUpdatePayload() : buildCreatePayload())
}
</script>

<template>
  <form novalidate class="space-y-6" @submit.prevent="onSubmit">
    <fieldset class="grid gap-4 sm:grid-cols-2">
      <legend class="field-label mb-2">Identity</legend>
      <TextField
        v-model="form.name"
        label="Name"
        required
        class="sm:col-span-2"
        :error="fieldErrors.name"
      />
      <TextField
        v-model="form.code"
        label="Code"
        hint="Leave blank to auto-generate."
        :error="fieldErrors.code"
      />
      <TextField v-model="form.legal_name" label="Legal name" :error="fieldErrors.legal_name" />
    </fieldset>

    <fieldset class="grid gap-4 sm:grid-cols-2">
      <legend class="field-label mb-2">Contact</legend>
      <TextField v-model="form.email" label="Email" type="email" :error="fieldErrors.email" />
      <TextField v-model="form.phone" label="Phone" :error="fieldErrors.phone" />
      <TextField v-model="form.website" label="Website" :error="fieldErrors.website" />
    </fieldset>

    <fieldset class="grid gap-4 sm:grid-cols-2">
      <legend class="field-label mb-2">Address</legend>
      <TextField
        v-model="form.address_line_1"
        label="Address line 1"
        class="sm:col-span-2"
        :error="fieldErrors.address_line_1"
      />
      <TextField
        v-model="form.address_line_2"
        label="Address line 2"
        class="sm:col-span-2"
        :error="fieldErrors.address_line_2"
      />
      <TextField v-model="form.city" label="City" :error="fieldErrors.city" />
      <TextField v-model="form.district" label="District" :error="fieldErrors.district" />
      <TextField v-model="form.postal_code" label="Postal code" :error="fieldErrors.postal_code" />
      <TextField v-model="form.country_code" label="Country code" :error="fieldErrors.country_code" />
    </fieldset>

    <fieldset class="grid gap-4 sm:grid-cols-2">
      <legend class="field-label mb-2">Tax</legend>
      <TextField
        v-model="form.vat_registration_number"
        label="VAT registration number"
        :error="fieldErrors.vat_registration_number"
      />
      <TextField
        v-model="form.tax_identification_number"
        label="Tax identification number"
        :error="fieldErrors.tax_identification_number"
      />
      <label class="flex items-center gap-2 text-sm text-content sm:col-span-2">
        <input
          v-model="form.is_vat_registered"
          type="checkbox"
          class="form-checkbox rounded border-surface-border text-primary-600 focus:ring-primary-500"
        />
        Is VAT registered
      </label>
    </fieldset>

    <fieldset class="grid gap-4 sm:grid-cols-2">
      <legend class="field-label mb-2">Commercial terms</legend>
      <TextField
        v-model="form.payment_terms_days"
        label="Payment terms (days)"
        inputmode="numeric"
        :error="fieldErrors.payment_terms_days"
      />

      <div>
        <TextField
          v-model="form.credit_limit"
          label="Credit limit"
          hint="A leading minus is accepted; left blank means no limit."
          :error="fieldErrors.credit_limit"
        />
        <button
          v-if="isEdit && original.credit_limit !== ''"
          type="button"
          class="mt-1 text-xs text-content-muted underline hover:text-content"
          @click="clearCreditLimit"
        >
          Clear
        </button>
      </div>

      <div>
        <TextField
          v-model="form.receivable_account_id"
          label="Receivable account"
          hint="Leave blank to use the company default."
          :error="fieldErrors.receivable_account_id"
        />
        <button
          v-if="isEdit && original.receivable_account_id !== ''"
          type="button"
          class="mt-1 text-xs text-content-muted underline hover:text-content"
          @click="clearReceivableAccountId"
        >
          Clear
        </button>
      </div>

      <div>
        <TextField v-model="form.branch_id" label="Branch" :error="fieldErrors.branch_id" />
        <button
          v-if="isEdit && original.branch_id !== ''"
          type="button"
          class="mt-1 text-xs text-content-muted underline hover:text-content"
          @click="clearBranchId"
        >
          Clear
        </button>
      </div>
    </fieldset>

    <fieldset>
      <legend class="field-label mb-2">Notes</legend>
      <label for="customer-notes" class="field-label">Notes</label>
      <textarea
        id="customer-notes"
        v-model="form.notes"
        rows="3"
        class="form-textarea mt-1 block w-full rounded-md border-surface-border bg-surface-raised text-content shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
      />
    </fieldset>

    <div>
      <BaseButton type="submit" :loading="busy">
        {{ isEdit ? 'Save changes' : 'Add customer' }}
      </BaseButton>
    </div>
  </form>
</template>
