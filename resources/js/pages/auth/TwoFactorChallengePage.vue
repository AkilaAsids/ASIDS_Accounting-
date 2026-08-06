<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError } from '@/api/client'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import TextField from '@/components/ui/TextField.vue'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const code = ref('')
const trustDevice = ref(false)
const usingRecoveryCode = ref(false)
const submitting = ref(false)
const error = ref<string | null>(null)

onMounted(async () => {
  // Reached directly, or after a refresh that cleared the store. There is nothing to
  // complete, so send the user back rather than showing a form that cannot succeed.
  if (auth.twoFactorChallenge === null) {
    await router.replace({ name: 'sign-in' })
  }
})

async function submit(): Promise<void> {
  submitting.value = true
  error.value = null

  try {
    // Trust is never extended off a recovery code: that is the credential someone uses when
    // they have lost the device, so trusting the browser would defeat the point.
    await auth.completeTwoFactor(code.value, usingRecoveryCode.value ? false : trustDevice.value)
    await router.push({ name: 'dashboard' })
  } catch (thrown) {
    if (thrown instanceof ApiError) {
      error.value = thrown.problem.detail

      // The challenge is single-use and short-lived; once it is gone the only way forward is
      // to start again, so say so and route them there.
      if (thrown.is('two-factor-challenge-expired')) {
        setTimeout(() => void router.push({ name: 'sign-in' }), 2500)
      }

      return
    }

    throw thrown
  } finally {
    submitting.value = false
    code.value = ''
  }
}
</script>

<template>
  <AuthLayout
    title="Verify it's you"
    :subtitle="
      usingRecoveryCode
        ? 'Enter one of the recovery codes you saved when you set up two factor authentication.'
        : 'Enter the six-digit code from your authenticator app.'
    "
  >
    <form class="space-y-4" novalidate @submit.prevent="submit">
      <AlertBanner v-if="error" kind="error">{{ error }}</AlertBanner>

      <TextField
        v-model="code"
        :label="usingRecoveryCode ? 'Recovery code' : 'Authenticator code'"
        :inputmode="usingRecoveryCode ? 'text' : 'numeric'"
        autocomplete="one-time-code"
        :placeholder="usingRecoveryCode ? 'abcde-fghij' : '123456'"
        required
      />

      <label v-if="!usingRecoveryCode" class="flex items-start gap-2 text-sm text-content-muted">
        <input v-model="trustDevice" type="checkbox" class="form-checkbox mt-0.5 rounded border-surface-border text-primary-600 focus:ring-primary-500" />
        <span>
          Trust this device for 30 days
          <span class="block text-xs text-content-subtle">Only on a device you control.</span>
        </span>
      </label>

      <BaseButton type="submit" block :loading="submitting">Verify</BaseButton>

      <button
        type="button"
        class="w-full text-center text-sm text-primary-700 hover:underline dark:text-primary-400"
        @click="usingRecoveryCode = !usingRecoveryCode; code = ''"
      >
        {{ usingRecoveryCode ? 'Use my authenticator app instead' : "I can't use my authenticator app" }}
      </button>
    </form>

    <template #below>
      Each recovery code works once. If you have run out, ask an administrator to reset your
      two factor authentication.
    </template>
  </AuthLayout>
</template>
