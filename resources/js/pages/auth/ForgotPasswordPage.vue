<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '@/api/client'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import TextField from '@/components/ui/TextField.vue'
import AuthLayout from '@/layouts/AuthLayout.vue'

const email = ref('')
const submitting = ref(false)
const sent = ref(false)
const error = ref<string | null>(null)

/**
 * The server answers identically whether or not the address exists, so this page must not
 * imply otherwise. Showing "if an account exists" is deliberate: a confident "sent!" would
 * leak which addresses hold accounts just as surely as a different status code would.
 */
async function submit(): Promise<void> {
  submitting.value = true
  error.value = null

  try {
    await api.post('/auth/forgot-password', { email: email.value })
    sent.value = true
  } catch (thrown) {
    if (thrown instanceof ApiError) {
      error.value = thrown.problem.detail
      return
    }

    throw thrown
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <AuthLayout
    title="Reset your password"
    :subtitle="sent ? undefined : 'We will e-mail you a link to choose a new one.'"
  >
    <div v-if="sent" class="space-y-4">
      <AlertBanner kind="success" title="Check your inbox">
        If an account exists for that address, a reset link is on its way. It expires in an hour and
        can only be used once.
      </AlertBanner>

      <p class="text-sm text-content-muted">
        Nothing arrived? Check your spam folder, or ask an administrator in your workspace to send
        you one.
      </p>

      <RouterLink :to="{ name: 'sign-in' }" class="block">
        <BaseButton variant="secondary" block>Back to sign in</BaseButton>
      </RouterLink>
    </div>

    <form v-else class="space-y-4" novalidate @submit.prevent="submit">
      <AlertBanner v-if="error" kind="error">{{ error }}</AlertBanner>

      <TextField
        v-model="email"
        label="E-mail address"
        type="email"
        autocomplete="username"
        inputmode="email"
        required
      />

      <BaseButton type="submit" block :loading="submitting">Send reset link</BaseButton>

      <RouterLink
        :to="{ name: 'sign-in' }"
        class="block text-center text-sm text-primary-700 hover:underline dark:text-primary-400"
      >
        Back to sign in
      </RouterLink>
    </form>
  </AuthLayout>
</template>
