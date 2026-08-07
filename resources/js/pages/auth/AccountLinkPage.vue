<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import TextField from '@/components/ui/TextField.vue'
import AuthLayout from '@/layouts/AuthLayout.vue'

/**
 * Landing page for a signed invitation or password reset link.
 *
 * The signature, expiry and purpose all travel in the query string and are verified
 * server-side; this page forwards them verbatim and never interprets them. It inspects the
 * link first so the user sees whose account it is and which password rules apply *before*
 * typing — an invitation that rejects a password after the fact is where new users give up.
 */
const route = useRoute()
const router = useRouter()

const loading = ref(true)
const submitting = ref(false)
const invalid = ref<string | null>(null)
const done = ref<string | null>(null)
const error = ref<string | null>(null)
const fieldErrors = ref<Record<string, string>>({})

const password = ref('')
const passwordConfirmation = ref('')

const details = ref<{
  purpose: string
  email: string
  first_name: string
  workspace: string | null
  password_policy: {
    min_length: number
    requires_mixed_case: boolean
    requires_numbers: boolean
    requires_symbols: boolean
  }
} | null>(null)

const isInvitation = computed(() => details.value?.purpose === 'invitation')

/** The query string minus the router's own params, forwarded to the API unchanged. */
const signedQuery = computed(() => {
  const params = new URLSearchParams()

  for (const [key, value] of Object.entries(route.query)) {
    if (typeof value === 'string') {
      params.set(key, value)
    }
  }

  return params.toString()
})

const endpoint = computed(() => `/account-link/${String(route.params.userId)}?${signedQuery.value}`)

const policyText = computed(() => {
  const policy = details.value?.password_policy
  if (!policy) return ''

  const requirements = [`at least ${policy.min_length} characters`]
  if (policy.requires_mixed_case) requirements.push('upper and lower case')
  if (policy.requires_numbers) requirements.push('a number')
  if (policy.requires_symbols) requirements.push('a symbol')

  return `Use ${requirements.join(', ')}.`
})

onMounted(async () => {
  try {
    const { data } = await api.get<typeof details.value>(endpoint.value)
    details.value = data
  } catch (thrown) {
    // One message for expired, already-used and tampered links, mirroring the server:
    // distinguishing them would confirm the link was once genuine.
    invalid.value =
      thrown instanceof ApiError
        ? thrown.problem.detail
        : 'This link could not be checked. Please request a new one.'
  } finally {
    loading.value = false
  }
})

async function submit(): Promise<void> {
  submitting.value = true
  error.value = null
  fieldErrors.value = {}

  try {
    const { data } = await api.post<{ completed: boolean; email: string; message: string }>(
      endpoint.value,
      {
        purpose: details.value?.purpose,
        fp: route.query.fp,
        password: password.value,
        password_confirmation: passwordConfirmation.value,
      },
    )

    done.value = data.message

    // Deliberately not signed in automatically. Signing in with the new password proves it
    // works and routes through the ordinary path — including the 2FA challenge, which this
    // flow must not bypass.
    setTimeout(() => void router.push({ name: 'sign-in' }), 2500)
  } catch (thrown) {
    if (thrown instanceof ApiError) {
      fieldErrors.value = thrown.fieldErrors
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
    :title="isInvitation ? 'Welcome to ASIDS' : 'Choose a new password'"
    :subtitle="details ? `for ${details.email}` : undefined"
  >
    <div v-if="loading" class="py-6 text-center text-sm text-content-muted">
      Checking your link…
    </div>

    <div v-else-if="invalid" class="space-y-4">
      <AlertBanner kind="error" title="This link is no longer valid">{{ invalid }}</AlertBanner>
      <BaseButton variant="secondary" block @click="router.push({ name: 'forgot-password' })">
        Request a new link
      </BaseButton>
    </div>

    <div v-else-if="done" class="space-y-4">
      <AlertBanner kind="success" title="All set">{{ done }}</AlertBanner>
      <p class="text-sm text-content-muted">Taking you to sign in…</p>
    </div>

    <form v-else class="space-y-4" novalidate @submit.prevent="submit">
      <p v-if="isInvitation && details?.workspace" class="text-sm text-content-muted">
        You have been invited to <strong class="text-content">{{ details.workspace }}</strong
        >. Choose a password to finish setting up your account.
      </p>

      <AlertBanner v-if="error" kind="error">{{ error }}</AlertBanner>

      <TextField
        v-model="password"
        label="New password"
        type="password"
        autocomplete="new-password"
        :hint="policyText"
        :error="fieldErrors.password"
        required
      />

      <TextField
        v-model="passwordConfirmation"
        label="Confirm password"
        type="password"
        autocomplete="new-password"
        required
      />

      <BaseButton type="submit" block :loading="submitting">
        {{ isInvitation ? 'Create my account' : 'Change my password' }}
      </BaseButton>
    </form>
  </AuthLayout>
</template>
