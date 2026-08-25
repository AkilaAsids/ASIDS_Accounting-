<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import TextField from '@/components/ui/TextField.vue'
import type { CustomerCreatePayload, CustomerUpdatePayload } from '@/api/customers'
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
  submit: [payload: CustomerCreatePayload | CustomerUpdatePayload]
  'update:dirty': [boolean]
}>()

const isEdit = computed(() => props.customer !== null)

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

/** '' means "never filled in" for a plain optional field on create — omit it (`undefined`)
 *  rather than send an empty string the schema was never asked to accept. */
function trimmedOrUndefined(value: string): string | undefined {
  const trimmed = value.trim()
  return trimmed === '' ? undefined : trimmed
}

/** Diffs one plain optional string field against its loaded value for the edit payload:
 *  unchanged (blank-vs-blank counts as unchanged) → `undefined` (omit); changed → the new
 *  value, blank normalised to `null`. Every field routed through this has no dedicated "Clear"
 *  control (§2.1.3) — those three (`branch_id`, `receivable_account_id`, `credit_limit`) are
 *  built explicitly below instead, since only they need the reader's *explicit* clear to be
 *  distinguishable from an emptied-by-accident box. */
function diffedOptionalString(current: string, original: string): string | null | undefined {
  const currentTrimmed = current.trim()
  const currentOrNull = currentTrimmed === '' ? null : currentTrimmed
  const originalTrimmed = original.trim()
  const originalOrNull = originalTrimmed === '' ? null : originalTrimmed

  return currentOrNull === originalOrNull ? undefined : currentOrNull
}

function buildCreatePayload(): CustomerCreatePayload {
  const days = Number(form.payment_terms_days)

  const payload: CustomerCreatePayload = {
    name: form.name.trim(),
    is_vat_registered: form.is_vat_registered,
    payment_terms_days: Number.isFinite(days) ? days : 30,
  }

  const code = trimmedOrUndefined(form.code)
  if (code !== undefined) payload.code = code

  const legalName = trimmedOrUndefined(form.legal_name)
  if (legalName !== undefined) payload.legal_name = legalName

  const tin = trimmedOrUndefined(form.tax_identification_number)
  if (tin !== undefined) payload.tax_identification_number = tin

  const vatNumber = trimmedOrUndefined(form.vat_registration_number)
  if (vatNumber !== undefined) payload.vat_registration_number = vatNumber

  const email = trimmedOrUndefined(form.email)
  if (email !== undefined) payload.email = email

  const phone = trimmedOrUndefined(form.phone)
  if (phone !== undefined) payload.phone = phone

  const website = trimmedOrUndefined(form.website)
  if (website !== undefined) payload.website = website

  const addressLine1 = trimmedOrUndefined(form.address_line_1)
  if (addressLine1 !== undefined) payload.address_line_1 = addressLine1

  const addressLine2 = trimmedOrUndefined(form.address_line_2)
  if (addressLine2 !== undefined) payload.address_line_2 = addressLine2

  const city = trimmedOrUndefined(form.city)
  if (city !== undefined) payload.city = city

  const district = trimmedOrUndefined(form.district)
  if (district !== undefined) payload.district = district

  const postalCode = trimmedOrUndefined(form.postal_code)
  if (postalCode !== undefined) payload.postal_code = postalCode

  const countryCode = trimmedOrUndefined(form.country_code)
  if (countryCode !== undefined) payload.country_code = countryCode

  const notes = trimmedOrUndefined(form.notes)
  if (notes !== undefined) payload.notes = notes

  const creditLimit = trimmedOrUndefined(form.credit_limit)
  if (creditLimit !== undefined) payload.credit_limit = creditLimit

  const receivableAccountId = trimmedOrUndefined(form.receivable_account_id)
  if (receivableAccountId !== undefined) payload.receivable_account_id = receivableAccountId

  const branchId = trimmedOrUndefined(form.branch_id)
  if (branchId !== undefined) payload.branch_id = branchId

  return payload
}

function buildUpdatePayload(): CustomerUpdatePayload {
  const payload: CustomerUpdatePayload = {}

  if (form.name.trim() !== original.value.name) {
    payload.name = form.name.trim()
  }

  if (form.code.trim() !== original.value.code) {
    payload.code = form.code.trim()
  }

  const legalName = diffedOptionalString(form.legal_name, original.value.legal_name)
  if (legalName !== undefined) payload.legal_name = legalName

  const tin = diffedOptionalString(
    form.tax_identification_number,
    original.value.tax_identification_number,
  )
  if (tin !== undefined) payload.tax_identification_number = tin

  const vatNumber = diffedOptionalString(
    form.vat_registration_number,
    original.value.vat_registration_number,
  )
  if (vatNumber !== undefined) payload.vat_registration_number = vatNumber

  const email = diffedOptionalString(form.email, original.value.email)
  if (email !== undefined) payload.email = email

  const phone = diffedOptionalString(form.phone, original.value.phone)
  if (phone !== undefined) payload.phone = phone

  const website = diffedOptionalString(form.website, original.value.website)
  if (website !== undefined) payload.website = website

  const addressLine1 = diffedOptionalString(form.address_line_1, original.value.address_line_1)
  if (addressLine1 !== undefined) payload.address_line_1 = addressLine1

  const addressLine2 = diffedOptionalString(form.address_line_2, original.value.address_line_2)
  if (addressLine2 !== undefined) payload.address_line_2 = addressLine2

  const city = diffedOptionalString(form.city, original.value.city)
  if (city !== undefined) payload.city = city

  const district = diffedOptionalString(form.district, original.value.district)
  if (district !== undefined) payload.district = district

  const postalCode = diffedOptionalString(form.postal_code, original.value.postal_code)
  if (postalCode !== undefined) payload.postal_code = postalCode

  const countryCode = diffedOptionalString(form.country_code, original.value.country_code)
  if (countryCode !== undefined) payload.country_code = countryCode

  const notes = diffedOptionalString(form.notes, original.value.notes)
  if (notes !== undefined) payload.notes = notes

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
