<script setup lang="ts">
import { onMounted, ref, useId, watch } from 'vue'
import { listTaxCodes } from '@/api/taxCodes'
import type { TaxCode } from '@/types/domain'

/**
 * The read-only tax-code picker (§4.7.3, Gate-1 #7, Gate-2 decision A: a plain `<select>`).
 *
 * Emits the tax **code** string, never an id — binding to `code.id` is the natural
 * Vue-select instinct and is exactly the wrong one here, since the wire value the API
 * accepts back is `tax_code` (a string), not `tax_code_id` (§7.3 of the ADR names this as
 * the field most likely to be implemented wrong by reflex).
 *
 * The picker itself offers no create/edit affordance for tax codes — it only lists what
 * already exists (`GET .../tax-codes?active_only=true`). Building tax-code CRUD is
 * explicitly out of scope for this wave.
 */
const props = defineProps<{
  companyId: string | null
  modelValue: string
  label?: string
  error?: string
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const id = useId()
const codes = ref<TaxCode[]>([])

onMounted(load)
watch(() => props.companyId, load)

async function load(): Promise<void> {
  if (!props.companyId) {
    codes.value = []
    return
  }

  try {
    const { data } = await listTaxCodes(props.companyId, true)
    codes.value = data
  } catch {
    // A picker that cannot load still leaves "No tax" selectable — a failed lookup here is
    // not worth a notice on top of whatever else is happening on the page.
    codes.value = []
  }
}
</script>

<template>
  <div>
    <label :for="id" class="field-label">{{ label ?? 'Tax code' }}</label>
    <select
      :id="id"
      :value="modelValue"
      :aria-invalid="error ? 'true' : undefined"
      class="form-select mt-1 block w-full rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
      :class="error && 'border-danger focus:border-danger focus:ring-danger'"
      @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
    >
      <option value="">No tax</option>
      <option v-for="code in codes" :key="code.id" :value="code.code">
        {{ code.code }} — {{ code.name }} ({{ Number(code.rate) }}%)
      </option>
    </select>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
  </div>
</template>
