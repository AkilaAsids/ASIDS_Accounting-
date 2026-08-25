<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'

/**
 * The shared confirm dialog for the two friction tiers above a plain `window.confirm`
 * (PHASE-3-FRONTEND-DESIGN.md §1.2).
 *
 * `window.confirm` itself is **not** reimplemented here — it stays the tier-1 control for
 * reversible actions (archive, deactivate, restore), unchanged from `ChartOfAccountsPage.vue`
 * / `UsersPage.vue`. This component covers the two tiers that need more than a browser
 * confirm can express:
 *
 *   - `mode="reason"`  — tier 2: a modal with an optional or required reason (invoice issue,
 *     invoice cancel).
 *   - `mode="typed"`   — tier 3: hard delete, gated on a typed token (the record's `code` /
 *     `number`) when one exists, or an explicit checkbox when it does not (a draft invoice has
 *     no `number` yet).
 *   - `mode="simple"`  — a tier-2 dialog with no extra input, for a consequential action that
 *     still needs more ceremony than `window.confirm` (e.g. "discard unsaved changes").
 *
 * The dialog is *controlled*: `open` is a prop, not local state, so a caller can react to
 * `cancel`/`confirm` however its own flow needs (including firing a follow-up request and only
 * then setting `open` back to `false`).
 */
const props = withDefaults(
  defineProps<{
    open: boolean
    mode?: 'simple' | 'reason' | 'typed'
    title: string
    message?: string
    danger?: boolean
    confirmLabel?: string
    cancelLabel?: string
    /** `mode="reason"` only. */
    reasonLabel?: string
    reasonMinLength?: number
    reasonMaxLength?: number
    /**
     * `mode="typed"` only. The token the reader must type verbatim (a customer `code`, an
     * issued invoice `number`). Omit or pass `null` to fall back to the checkbox variant —
     * the only option when the record has no stable token yet, e.g. a draft invoice.
     */
    confirmToken?: string | null
    typedLabel?: string
    checkboxLabel?: string
  }>(),
  {
    mode: 'simple',
    message: undefined,
    danger: false,
    confirmLabel: 'Confirm',
    cancelLabel: 'Cancel',
    reasonLabel: 'Reason',
    reasonMinLength: 3,
    reasonMaxLength: 255,
    confirmToken: null,
    typedLabel: undefined,
    checkboxLabel: 'I understand this cannot be undone.',
  },
)

const emit = defineEmits<{
  /** Fires only once the tier's own validity condition is met. */
  confirm: [reason: string | undefined]
  cancel: []
}>()

const titleId = useId()
const dialog = ref<HTMLElement | null>(null)
const reason = ref('')
const typed = ref('')
const checked = ref(false)
let returnFocusTo: HTMLElement | null = null

const reasonLength = computed(() => reason.value.trim().length)

const typedLabelText = computed(
  () => props.typedLabel ?? `Type ${props.confirmToken ?? ''} to confirm`,
)

const isValid = computed(() => {
  if (props.mode === 'reason') {
    return reasonLength.value >= props.reasonMinLength && reasonLength.value <= props.reasonMaxLength
  }

  if (props.mode === 'typed') {
    return props.confirmToken ? typed.value.trim() === props.confirmToken : checked.value
  }

  return true
})

// Reset every time the dialog opens, so a reason typed before a cancelled attempt does not
// reappear pre-filled next time — and move focus in, then back out again on close, exactly as
// `StepUpDialog.vue` already does.
watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      reason.value = ''
      typed.value = ''
      checked.value = false
      returnFocusTo = document.activeElement as HTMLElement | null
      void nextTick(() => firstFocusable()?.focus())
    } else {
      returnFocusTo?.focus()
      returnFocusTo = null
    }
  },
)

function firstFocusable(): HTMLElement | null {
  return dialog.value?.querySelector<HTMLElement>('textarea, input, button') ?? null
}

function focusable(): HTMLElement[] {
  return Array.from(
    dialog.value?.querySelectorAll<HTMLElement>(
      'button, input, textarea, [href], [tabindex]:not([tabindex="-1"])',
    ) ?? [],
  )
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.preventDefault()
    onCancel()
    return
  }

  if (event.key !== 'Tab') {
    return
  }

  // Focus trap: Tab/Shift+Tab cycles within the dialog rather than escaping to the page
  // behind the backdrop.
  const items = focusable()
  if (items.length === 0) {
    return
  }

  const first = items[0]
  const last = items[items.length - 1]

  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last?.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first?.focus()
  }
}

function onConfirm(): void {
  if (!isValid.value) {
    return
  }

  emit('confirm', props.mode === 'reason' ? reason.value.trim() : undefined)
}

function onCancel(): void {
  emit('cancel')
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      @click.self="onCancel"
      @keydown="onKeydown"
    >
      <div
        ref="dialog"
        class="w-full max-w-md rounded-card bg-surface-raised p-6 shadow-overlay animate-slide-up"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
      >
        <h2 :id="titleId" class="text-base font-semibold text-content">{{ title }}</h2>

        <p v-if="$slots.default" class="mt-2 text-sm text-content-muted"><slot /></p>
        <p v-else-if="message" class="mt-2 text-sm text-content-muted">{{ message }}</p>

        <!-- Tier 3, typed-token variant: the record's own code/number must be typed verbatim. -->
        <div v-if="mode === 'typed' && confirmToken" class="mt-4">
          <label class="field-label" :for="`${titleId}-typed`">{{ typedLabelText }}</label>
          <input
            :id="`${titleId}-typed`"
            v-model="typed"
            type="text"
            class="form-input mt-1 block w-full rounded-md border-surface-border bg-surface-raised text-content shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
            autocomplete="off"
          />
        </div>

        <!-- Tier 3, checkbox variant: no stable token exists yet (e.g. a draft invoice). -->
        <label
          v-else-if="mode === 'typed'"
          class="mt-4 flex items-center gap-2 text-sm text-content"
        >
          <input
            v-model="checked"
            type="checkbox"
            class="form-checkbox rounded border-surface-border text-danger focus:ring-danger"
          />
          {{ checkboxLabel }}
        </label>

        <!-- Tier 2, reason variant: required, 3–255 chars, live character count. -->
        <div v-else-if="mode === 'reason'" class="mt-4">
          <label class="field-label" :for="`${titleId}-reason`">{{ reasonLabel }}</label>
          <textarea
            :id="`${titleId}-reason`"
            v-model="reason"
            rows="3"
            :maxlength="reasonMaxLength"
            class="form-textarea mt-1 block w-full rounded-md border-surface-border bg-surface-raised text-content shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
          />
          <p class="field-hint">{{ reasonLength }} / {{ reasonMaxLength }}</p>
        </div>

        <div class="mt-6 flex justify-end gap-2">
          <BaseButton variant="secondary" @click="onCancel">{{ cancelLabel }}</BaseButton>
          <BaseButton :variant="danger ? 'danger' : 'primary'" :disabled="!isValid" @click="onConfirm">
            {{ confirmLabel }}
          </BaseButton>
        </div>
      </div>
    </div>
  </Teleport>
</template>
