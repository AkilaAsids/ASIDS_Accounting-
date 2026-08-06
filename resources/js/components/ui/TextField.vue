<script setup lang="ts">
import { computed, useId } from 'vue'

/**
 * A labelled input with hint and error slots.
 *
 * The error is wired through `aria-describedby` and `aria-invalid` rather than only being
 * shown in red, so a screen-reader user learns which field failed and why. Colour alone
 * would fail WCAG and would be invisible to a colour-blind user.
 */
const props = withDefaults(
  defineProps<{
    label: string
    modelValue: string | number | null
    type?: string
    hint?: string
    error?: string
    required?: boolean
    disabled?: boolean
    autocomplete?: string
    placeholder?: string
    inputmode?: 'text' | 'numeric' | 'email' | 'tel'
  }>(),
  { type: 'text', required: false, disabled: false },
)

defineEmits<{ 'update:modelValue': [value: string] }>()

const id = useId()
const describedBy = computed(() => {
  const ids: string[] = []
  if (props.hint) ids.push(`${id}-hint`)
  if (props.error) ids.push(`${id}-error`)
  return ids.length > 0 ? ids.join(' ') : undefined
})
</script>

<template>
  <div>
    <label :for="id" class="field-label">
      {{ label }}
      <span v-if="required" class="text-danger" aria-hidden="true">*</span>
      <span v-if="required" class="sr-only">(required)</span>
    </label>

    <input
      :id="id"
      :type="type"
      :value="modelValue"
      :required="required"
      :disabled="disabled"
      :autocomplete="autocomplete"
      :placeholder="placeholder"
      :inputmode="inputmode"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="describedBy"
      class="form-input mt-1 block w-full rounded-md border-surface-border bg-surface-raised text-content
             shadow-sm placeholder:text-content-subtle focus:border-primary-500 focus:ring-primary-500
             disabled:cursor-not-allowed disabled:opacity-60 sm:text-sm"
      :class="error && 'border-danger focus:border-danger focus:ring-danger'"
      @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />

    <p v-if="hint && !error" :id="`${id}-hint`" class="field-hint">{{ hint }}</p>
    <p v-if="error" :id="`${id}-error`" class="field-error" role="alert">{{ error }}</p>
  </div>
</template>
