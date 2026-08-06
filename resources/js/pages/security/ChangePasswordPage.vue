<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import TextField from '@/components/ui/TextField.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const auth = useAuthStore()
const ui = useUiStore()
const route = useRoute()
const router = useRouter()

const current = ref('')
const password = ref('')
const confirmation = ref('')
const submitting = ref(false)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})

/** Reached because the password expired, rather than by choice. */
const expired = computed(() => route.query.expired === '1' || auth.requires.password_change)

async function submit(): Promise<void> {
  submitting.value = true
  error.value = null
  fieldErrors.value = {}

  try {
    await api.put('/auth/password', {
      current_password: current.value,
      password: password.value,
      password_confirmation: confirmation.value,
    })

    // Refetched rather than assumed: the server clears `must_change_password` and resets the
    // expiry clock, and the router guard reads that flag on every navigation.
    await auth.fetchSession()

    ui.notify('success', 'Your password has been changed. Other devices have been signed out.')
    await router.push({ name: 'dashboard' })
  } catch (thrown) {
    if (thrown instanceof ApiError) {
      fieldErrors.value = thrown.fieldErrors
      error.value = thrown.problem.detail
      return
    }

    throw thrown
  } finally {
    submitting.value = false
    current.value = ''
    password.value = ''
    confirmation.value = ''
  }
}
</script>

<template>
  <div class="mx-auto max-w-lg space-y-4">
    <AlertBanner v-if="expired" kind="warning" title="Your password needs changing">
      This workspace requires passwords to be changed periodically. You can carry on once you
      have set a new one.
    </AlertBanner>

    <SurfaceCard
      title="Change your password"
      description="You will stay signed in here. Every other device will be signed out."
    >
      <form class="space-y-4" novalidate @submit.prevent="submit">
        <AlertBanner v-if="error" kind="error">{{ error }}</AlertBanner>

        <!-- Required even for an expired password: without it a hijacked session could lock
             the real owner out of their own account. -->
        <TextField
          v-model="current"
          label="Current password"
          type="password"
          autocomplete="current-password"
          :error="fieldErrors.current_password"
          required
        />

        <TextField
          v-model="password"
          label="New password"
          type="password"
          autocomplete="new-password"
          hint="Long is better than complicated. A phrase you can remember beats a short jumble."
          :error="fieldErrors.password"
          required
        />

        <TextField
          v-model="confirmation"
          label="Confirm new password"
          type="password"
          autocomplete="new-password"
          required
        />

        <BaseButton type="submit" :loading="submitting">Change password</BaseButton>
      </form>
    </SurfaceCard>
  </div>
</template>
