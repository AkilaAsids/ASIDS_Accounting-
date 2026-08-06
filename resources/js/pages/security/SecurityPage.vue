<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { RouterLink } from 'vue-router'
import { api, ApiError } from '@/api/client'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import TextField from '@/components/ui/TextField.vue'
import { useFormat } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import type { LoginHistoryEntry, TwoFactorEnrolment, UserDevice } from '@/types/domain'

/**
 * Self-service security: two factor, devices, recent sign-ins.
 *
 * Enrolment is two-phase to match the server — the secret is issued, then a working code
 * confirms it. Showing "you're protected" before a code has verified would lock out anyone
 * whose authenticator was misconfigured, which is the most avoidable support ticket there is.
 */
const auth = useAuthStore()
const ui = useUiStore()
const route = useRoute()
const { relative, dateTime } = useFormat()

const status = ref<{
  enabled: boolean
  unused_recovery_codes: number
  required_by_workspace: boolean
} | null>(null)

const devices = ref<UserDevice[]>([])
const history = ref<LoginHistoryEntry[]>([])

const enrolment = ref<TwoFactorEnrolment | null>(null)
const recoveryCodes = ref<string[] | null>(null)
const confirmCode = ref('')
const busy = ref(false)
const error = ref<string | null>(null)

onMounted(async () => {
  await Promise.all([loadStatus(), loadDevices(), loadHistory()])

  // Routed here by the guard because the workspace mandates 2FA. Start enrolment
  // immediately — making a confined user hunt for the button is needless friction.
  if (route.query.enrol === '1' && status.value?.enabled === false) {
    await beginEnrolment()
  }
})

async function loadStatus(): Promise<void> {
  const { data } = await api.get<typeof status.value>('/auth/two-factor')
  status.value = data
}

async function loadDevices(): Promise<void> {
  const { data } = await api.get<UserDevice[]>('/me/devices')
  devices.value = data
}

async function loadHistory(): Promise<void> {
  const { data } = await api.get<LoginHistoryEntry[]>('/me/login-history', { per_page: 10 })
  history.value = data
}

async function beginEnrolment(): Promise<void> {
  busy.value = true
  error.value = null

  try {
    const { data } = await api.post<TwoFactorEnrolment>('/auth/two-factor/enrol')
    enrolment.value = data
  } catch (thrown) {
    error.value = thrown instanceof ApiError ? thrown.problem.detail : 'Could not start setup.'
  } finally {
    busy.value = false
  }
}

async function confirmEnrolment(): Promise<void> {
  busy.value = true
  error.value = null

  try {
    const { data } = await api.post<{ recovery_codes: string[] }>('/auth/two-factor/confirm', {
      code: confirmCode.value,
    })

    // Shown once and never retrievable, so they are held in view until the user dismisses
    // them rather than disappearing on the next render.
    recoveryCodes.value = data.recovery_codes
    enrolment.value = null
    confirmCode.value = ''

    await Promise.all([loadStatus(), auth.fetchSession()])
    ui.notify('success', 'Two factor authentication is on.')
  } catch (thrown) {
    error.value = thrown instanceof ApiError ? thrown.problem.detail : 'Could not confirm the code.'
  } finally {
    busy.value = false
  }
}

async function revokeDevice(device: UserDevice): Promise<void> {
  if (!window.confirm(`Revoke ${device.name}? It will have to sign in again.`)) {
    return
  }

  try {
    await api.delete(`/devices/${device.id}`)
    await loadDevices()
    ui.notify('success', 'Device revoked.')
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not revoke.')
  }
}

async function signOutEverywhere(): Promise<void> {
  if (!window.confirm('Sign out of every device, including this one?')) {
    return
  }

  await auth.signOut(true)
  window.location.assign('/sign-in')
}
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-content">Security</h1>
      <p class="mt-1 text-sm text-content-muted">
        How you sign in, and where you are signed in.
      </p>
    </header>

    <AlertBanner v-if="error" kind="error">{{ error }}</AlertBanner>

    <!-- Recovery codes: shown exactly once, so this panel is deliberately hard to dismiss
         by accident and says plainly that they will not be shown again. -->
    <SurfaceCard v-if="recoveryCodes" title="Save your recovery codes">
      <AlertBanner kind="warning" title="These are shown only once">
        Each code works a single time. Keep them somewhere safe and offline — they are the only
        way back in if you lose your authenticator.
      </AlertBanner>

      <ul class="mt-4 grid grid-cols-2 gap-2 font-mono text-sm">
        <li v-for="code in recoveryCodes" :key="code" class="rounded bg-surface-sunken px-3 py-2 text-content">
          {{ code }}
        </li>
      </ul>

      <template #footer>
        <BaseButton variant="secondary" size="sm" @click="recoveryCodes = null">
          I have saved them
        </BaseButton>
      </template>
    </SurfaceCard>

    <!-- ── Two factor ──────────────────────────────────────────────────── -->
    <SurfaceCard title="Two factor authentication">
      <div v-if="status?.enabled" class="space-y-3">
        <AlertBanner kind="success">
          Two factor authentication is on.
          <span v-if="status.unused_recovery_codes <= 2" class="mt-1 block font-medium">
            Only {{ status.unused_recovery_codes }} recovery code(s) left — generate a new set.
          </span>
          <span v-else class="mt-1 block">
            {{ status.unused_recovery_codes }} recovery codes remaining.
          </span>
        </AlertBanner>
      </div>

      <div v-else-if="enrolment" class="space-y-4">
        <p class="text-sm text-content-muted">
          Scan this with Google Authenticator, Authy or 1Password, then enter the code it shows.
        </p>

        <div class="flex flex-col items-start gap-4 sm:flex-row">
          <!-- Rendered server-side as SVG, so no QR library ships to the browser. -->
          <div class="rounded-md bg-white p-3" v-html="enrolment.qr_code_svg" />

          <div class="min-w-0 flex-1 space-y-3">
            <div>
              <p class="text-xs uppercase tracking-wide text-content-subtle">
                Or enter this key by hand
              </p>
              <code class="mt-1 block break-all rounded bg-surface-sunken px-2 py-1 font-mono text-xs text-content">
                {{ enrolment.secret }}
              </code>
            </div>

            <TextField
              v-model="confirmCode"
              label="Code from your app"
              inputmode="numeric"
              autocomplete="one-time-code"
              placeholder="123456"
              required
            />

            <div class="flex gap-2">
              <BaseButton :loading="busy" @click="confirmEnrolment">Turn it on</BaseButton>
              <BaseButton
                v-if="!status?.required_by_workspace"
                variant="ghost"
                @click="enrolment = null"
              >
                Cancel
              </BaseButton>
            </div>
          </div>
        </div>
      </div>

      <div v-else class="space-y-3">
        <AlertBanner :kind="status?.required_by_workspace ? 'warning' : 'info'">
          <template v-if="status?.required_by_workspace">
            This workspace requires two factor authentication. Set it up to carry on.
          </template>
          <template v-else>
            Add a second step to your sign-in. Strongly recommended — a password alone is one
            phishing e-mail away from someone else having your books.
          </template>
        </AlertBanner>

        <BaseButton :loading="busy" @click="beginEnrolment">Set up two factor</BaseButton>
      </div>
    </SurfaceCard>

    <!-- ── Devices ─────────────────────────────────────────────────────── -->
    <SurfaceCard title="Signed-in devices" description="Revoke anything you do not recognise.">
      <ul class="divide-y divide-surface-border">
        <li
          v-for="device in devices"
          :key="device.id"
          class="flex items-center justify-between gap-3 py-3 first:pt-0"
        >
          <div class="min-w-0">
            <p class="truncate text-sm text-content">
              {{ device.name }}
              <span v-if="device.is_current_device" class="ml-1 rounded bg-primary-600/10 px-1.5 py-0.5 text-xs text-primary-700 dark:text-primary-300">
                This device
              </span>
              <span v-if="device.is_trusted" class="ml-1 rounded bg-success/10 px-1.5 py-0.5 text-xs text-success">
                Trusted
              </span>
            </p>
            <p class="text-xs text-content-muted">
              {{ device.last_ip_address }} · last used {{ relative(device.last_seen_at) }}
            </p>
          </div>

          <BaseButton variant="ghost" size="sm" @click="revokeDevice(device)">Revoke</BaseButton>
        </li>
        <li v-if="devices.length === 0" class="py-3 text-sm text-content-muted">No devices recorded.</li>
      </ul>

      <template #footer>
        <BaseButton variant="danger" size="sm" @click="signOutEverywhere">
          Sign out everywhere
        </BaseButton>
      </template>
    </SurfaceCard>

    <!-- ── Sign-in history ─────────────────────────────────────────────── -->
    <SurfaceCard title="Recent sign-ins" description="Failed attempts are shown too.">
      <ul class="divide-y divide-surface-border">
        <li v-for="entry in history" :key="entry.id" class="flex items-center justify-between gap-3 py-2.5 first:pt-0">
          <div class="min-w-0">
            <p class="flex items-center gap-2 text-sm text-content">
              <span
                class="h-1.5 w-1.5 shrink-0 rounded-full"
                :class="entry.succeeded ? 'bg-success' : 'bg-danger'"
                aria-hidden="true"
              />
              {{ entry.succeeded ? 'Signed in' : 'Failed attempt' }}
              <span v-if="entry.two_factor_used" class="text-xs text-content-subtle">· 2FA</span>
            </p>
            <p class="text-xs text-content-muted">
              {{ entry.browser ?? 'Unknown' }} on {{ entry.platform ?? 'unknown' }} · {{ entry.ip_address }}
            </p>
          </div>
          <time class="shrink-0 text-xs text-content-subtle" :title="dateTime(entry.created_at)">
            {{ relative(entry.created_at) }}
          </time>
        </li>
      </ul>
    </SurfaceCard>

    <div class="text-center">
      <RouterLink :to="{ name: 'change-password' }" class="text-sm text-primary-700 hover:underline dark:text-primary-400">
        Change your password
      </RouterLink>
    </div>
  </div>
</template>
