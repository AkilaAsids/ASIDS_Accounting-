<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { RouterLink } from 'vue-router'
import { ApiError, NetworkError } from '@/api/client'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import TextField from '@/components/ui/TextField.vue'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const remember = ref(false)
const submitting = ref(false)
const error = ref<{ message: string; requestId?: string; retryAfter?: number } | null>(null)
const fieldErrors = ref<Record<string, string>>({})

async function submit(): Promise<void> {
  submitting.value = true
  error.value = null
  fieldErrors.value = {}

  try {
    const { twoFactorRequired } = await auth.signIn(email.value, password.value, remember.value)

    if (twoFactorRequired) {
      // The challenge lives in the store, not the URL: putting a bearer credential in a
      // query string leaks it into history and any proxy log in between.
      await router.push({ name: 'two-factor-challenge' })
      return
    }

    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    await router.push(redirect)
  } catch (thrown) {
    if (thrown instanceof NetworkError) {
      error.value = { message: thrown.message }
      return
    }

    if (thrown instanceof ApiError) {
      fieldErrors.value = thrown.fieldErrors
      error.value = {
        message: thrown.problem.detail,
        requestId: thrown.requestId,
        // Surfaced so a locked-out user is told how long to wait instead of retrying into
        // the same wall and escalating to support.
        retryAfter:
          typeof thrown.problem.retry_after_seconds === 'number'
            ? thrown.problem.retry_after_seconds
            : undefined,
      }
      return
    }

    throw thrown
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AuthLayout title="Sign in" subtitle="Enter your details to reach your workspace.">
    <form class="space-y-4" novalidate @submit.prevent="submit">
      <AlertBanner v-if="error" kind="error" :request-id="error.requestId">
        {{ error.message }}
        <span v-if="error.retryAfter" class="mt-1 block">
          Try again in about {{ Math.ceil(error.retryAfter / 60) }} minute(s).
        </span>
      </AlertBanner>

      <TextField
        v-model="email"
        label="E-mail address"
        type="email"
        autocomplete="username"
        inputmode="email"
        :error="fieldErrors.email"
        required
      />

      <TextField
        v-model="password"
        label="Password"
        type="password"
        autocomplete="current-password"
        :error="fieldErrors.password"
        required
      />

      <div class="flex items-center justify-between">
        <label class="flex items-center gap-2 text-sm text-content-muted">
          <input
            v-model="remember"
            type="checkbox"
            class="form-checkbox rounded border-surface-border text-primary-600 focus:ring-primary-500"
          />
          Keep me signed in
        </label>

        <RouterLink
          :to="{ name: 'forgot-password' }"
          class="text-sm text-primary-700 hover:underline dark:text-primary-400"
        >
          Forgot password?
        </RouterLink>
      </div>

      <BaseButton type="submit" block :loading="submitting">Sign in</BaseButton>
    </form>

    <template #below> Protected by two factor authentication where enabled. </template>
  </AuthLayout>
</template>
