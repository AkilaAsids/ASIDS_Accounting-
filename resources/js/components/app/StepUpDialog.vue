<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import TextField from '@/components/ui/TextField.vue'
import AlertBanner from '@/components/ui/AlertBanner.vue'

/**
 * Prompts for a TOTP code when the server demands step-up authentication.
 *
 * Registers `window.asidsRequestStepUp` so the API client can await a code without
 * importing Vue. The client then replays the original request, which means a sensitive
 * action the user initiated completes after confirmation rather than failing and requiring
 * them to start again — the difference between a security control and an obstacle.
 */
const open = ref(false)
const code = ref('')
const error = ref<string | null>(null)
const input = ref<HTMLElement | null>(null)

let resolveWith: ((value: string | null) => void) | null = null

onMounted(() => {
  window.asidsRequestStepUp = () =>
    new Promise<string | null>((resolve) => {
      resolveWith = resolve
      code.value = ''
      error.value = null
      open.value = true
      void nextTick(() => input.value?.querySelector('input')?.focus())
    })
})

function submit(): void {
  const trimmed = code.value.replace(/\s+/g, '')

  if (trimmed.length < 6) {
    error.value = 'Enter the six-digit code from your authenticator app.'
    return
  }

  open.value = false
  resolveWith?.(trimmed)
  resolveWith = null
}

function cancel(): void {
  open.value = false
  // Resolving null rather than rejecting: cancelling is a decision, not a fault, and the
  // client surfaces the original 428 to the caller.
  resolveWith?.(null)
  resolveWith = null
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="step-up-title"
      @keydown.esc="cancel"
    >
      <div class="w-full max-w-sm rounded-card bg-surface-raised p-6 shadow-overlay animate-slide-up">
        <h2 id="step-up-title" class="text-base font-semibold text-content">Confirm it's you</h2>
        <p class="mt-1 text-sm text-content-muted">
          This action changes security settings, so we need your authenticator code.
        </p>

        <form class="mt-4 space-y-4" @submit.prevent="submit">
          <div ref="input">
            <TextField
              v-model="code"
              label="Authenticator code"
              inputmode="numeric"
              autocomplete="one-time-code"
              placeholder="123456"
              :error="error ?? undefined"
              required
            />
          </div>

          <AlertBanner v-if="error" kind="error">{{ error }}</AlertBanner>

          <div class="flex gap-2">
            <BaseButton type="submit" block>Confirm</BaseButton>
            <BaseButton variant="secondary" @click="cancel">Cancel</BaseButton>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>
