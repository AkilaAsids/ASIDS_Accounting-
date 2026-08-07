<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import BaseButton from '@/components/ui/BaseButton.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import TextField from '@/components/ui/TextField.vue'
import PermissionGate from '@/components/app/PermissionGate.vue'
import { useFormat } from '@/composables/useFormat'
import { useUiStore } from '@/stores/ui'
import type { ApiMeta, SeatUsage } from '@/types/api'
import type { Role, User } from '@/types/domain'

/**
 * User administration.
 *
 * Every action here is a separate endpoint on the server — suspend, deactivate, reset
 * password, reset 2FA — and the interface mirrors that rather than offering a single "edit
 * user" form with a status dropdown. Folding privileged transitions into a general edit is
 * how "manage users" quietly becomes "escalate privilege".
 */
const ui = useUiStore()
const { relative } = useFormat()

const users = ref<User[]>([])
const meta = ref<ApiMeta | null>(null)
const roles = ref<Role[]>([])
const loading = ref(true)
const search = ref('')
const statusFilter = ref('')

const inviting = ref(false)
const inviteForm = ref({
  first_name: '',
  last_name: '',
  email: '',
  job_title: '',
  role_ids: [] as string[],
})
const inviteErrors = ref<Record<string, string>>({})
const inviteBusy = ref(false)

// Derived here rather than cast inline in the template: `meta` is an open record, so reading
// `seats` from it there needs an assertion on every interpolation, and an assertion is exactly
// what hid the fact that `limit` is nullable for a caller with no workspace.
const seats = computed<SeatUsage | null>(() => meta.value?.seats ?? null)

const seatSummary = computed<string>(() => {
  const current = seats.value

  if (current === null) {
    return ''
  }

  return current.limit === null
    ? `${current.consumed} seats in use`
    : `${current.consumed} of ${current.limit} seats used`
})

let searchTimer: number | undefined

onMounted(async () => {
  await Promise.all([load(), loadRoles()])
})

// Debounced: the trigram index makes the query cheap, but a request per keystroke is still a
// request per keystroke on a shared office connection.
watch([search, statusFilter], () => {
  window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => void load(), 300)
})

async function load(page = 1): Promise<void> {
  loading.value = true

  try {
    const response = await api.get<User[]>('/users', {
      page,
      q: search.value || undefined,
      filter: statusFilter.value ? { status: statusFilter.value } : undefined,
    })

    users.value = response.data
    meta.value = response.meta
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not load users.')
  } finally {
    loading.value = false
  }
}

async function loadRoles(): Promise<void> {
  try {
    const { data } = await api.get<Role[]>('/roles')
    // Only roles this user may actually grant: offering one that will be refused is a form
    // that fails after submission for a reason the user cannot see.
    roles.value = data.filter((role) => role.capabilities.grantable_by_current_user)
  } catch {
    roles.value = []
  }
}

async function invite(): Promise<void> {
  inviteBusy.value = true
  inviteErrors.value = {}

  try {
    await api.post('/users', { ...inviteForm.value, company_ids: [] })

    ui.notify('success', `Invitation sent to ${inviteForm.value.email}.`)
    inviting.value = false
    inviteForm.value = { first_name: '', last_name: '', email: '', job_title: '', role_ids: [] }
    await load()
  } catch (thrown) {
    if (thrown instanceof ApiError) {
      inviteErrors.value = thrown.fieldErrors
      // A seat limit is commercial, not a form error, so it is surfaced as a notice rather
      // than pinned to a field the user cannot fix by editing.
      if (thrown.status === 402) {
        ui.notify('error', thrown.problem.detail)
      }
      return
    }

    throw thrown
  } finally {
    inviteBusy.value = false
  }
}

async function act(user: User, action: 'suspend' | 'reinstate' | 'deactivate'): Promise<void> {
  const prompts: Record<typeof action, string> = {
    suspend: `Suspend ${user.full_name}? They will be signed out immediately.`,
    reinstate: `Restore access for ${user.full_name}?`,
    deactivate: `Deactivate ${user.full_name}? This cannot be undone — their history is kept for audit.`,
  }

  if (!window.confirm(prompts[action])) {
    return
  }

  const reason =
    action === 'suspend' || action === 'deactivate'
      ? (window.prompt('Reason (recorded in the audit trail):') ?? '')
      : ''

  try {
    await api.post(`/users/${user.id}/${action}`, { reason })
    await load(meta.value?.pagination?.current_page ?? 1)
    ui.notify('success', 'Done.')
  } catch (thrown) {
    ui.notify(
      'error',
      thrown instanceof ApiError ? thrown.problem.detail : 'Could not complete that.',
    )
  }
}

async function sendReset(user: User): Promise<void> {
  try {
    await api.post(`/users/${user.id}/send-password-reset`)
    ui.notify('success', `Reset link sent to ${user.email}.`)
  } catch (thrown) {
    ui.notify(
      'error',
      thrown instanceof ApiError ? thrown.problem.detail : 'Could not send the link.',
    )
  }
}

const statusStyles: Record<string, string> = {
  active: 'bg-success/10 text-success',
  pending_invitation: 'bg-info/10 text-info',
  suspended: 'bg-warning/10 text-warning',
  deactivated: 'bg-surface-sunken text-content-subtle',
}
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-content">Users</h1>
        <p v-if="seats" class="mt-1 text-sm text-content-muted">{{ seatSummary }}</p>
      </div>

      <PermissionGate permission="identity.users.invite">
        <BaseButton @click="inviting = !inviting">
          {{ inviting ? 'Cancel' : 'Invite a user' }}
        </BaseButton>
      </PermissionGate>
    </header>

    <SurfaceCard
      v-if="inviting"
      title="Invite a user"
      description="They will choose their own password from a link we e-mail them."
    >
      <form class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="invite">
        <TextField
          v-model="inviteForm.first_name"
          label="First name"
          :error="inviteErrors.first_name"
          required
        />
        <TextField
          v-model="inviteForm.last_name"
          label="Last name"
          :error="inviteErrors.last_name"
        />
        <TextField
          v-model="inviteForm.email"
          label="E-mail address"
          type="email"
          inputmode="email"
          :error="inviteErrors.email"
          required
        />
        <TextField
          v-model="inviteForm.job_title"
          label="Job title"
          :error="inviteErrors.job_title"
        />

        <div class="sm:col-span-2">
          <span class="field-label">Roles</span>
          <div class="mt-2 flex flex-wrap gap-3">
            <label
              v-for="role in roles"
              :key="role.id"
              class="flex items-center gap-2 rounded-md border border-surface-border px-3 py-1.5 text-sm"
            >
              <input
                v-model="inviteForm.role_ids"
                type="checkbox"
                :value="role.id"
                class="form-checkbox rounded border-surface-border text-primary-600 focus:ring-primary-500"
              />
              {{ role.label }}
            </label>
            <p v-if="roles.length === 0" class="text-sm text-content-muted">
              No roles you can assign. Ask an owner to grant one.
            </p>
          </div>
          <p class="field-hint">You can only assign roles below your own level.</p>
        </div>

        <div class="sm:col-span-2">
          <BaseButton type="submit" :loading="inviteBusy">Send invitation</BaseButton>
        </div>
      </form>
    </SurfaceCard>

    <div class="flex flex-wrap gap-3">
      <div class="min-w-56 flex-1">
        <TextField v-model="search" label="Search" placeholder="Name or e-mail" />
      </div>
      <div>
        <label class="field-label" for="status-filter">Status</label>
        <select
          id="status-filter"
          v-model="statusFilter"
          class="form-select mt-1 rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
        >
          <option value="">All</option>
          <option value="active">Active</option>
          <option value="pending_invitation">Invitation pending</option>
          <option value="suspended">Suspended</option>
          <option value="deactivated">Deactivated</option>
        </select>
      </div>
    </div>

    <SurfaceCard>
      <div class="-mx-5 -my-4 overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead
            class="border-b border-surface-border text-xs uppercase tracking-wide text-content-subtle"
          >
            <tr>
              <th scope="col" class="px-5 py-3">Name</th>
              <th scope="col" class="px-5 py-3">Roles</th>
              <th scope="col" class="px-5 py-3">Status</th>
              <th scope="col" class="px-5 py-3">Last seen</th>
              <th scope="col" class="px-5 py-3"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>

          <tbody class="divide-y divide-surface-border">
            <tr v-if="loading">
              <td colspan="5" class="px-5 py-8 text-center text-content-muted">Loading…</td>
            </tr>

            <tr v-else-if="users.length === 0">
              <td colspan="5" class="px-5 py-8 text-center text-content-muted">
                No users match that.
              </td>
            </tr>

            <tr v-for="user in users" v-else :key="user.id" class="hover:bg-surface-sunken">
              <td class="px-5 py-3">
                <div class="flex items-center gap-3">
                  <span
                    class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary-600/10 text-xs font-semibold text-primary-700 dark:text-primary-300"
                  >
                    {{ user.initials }}
                  </span>
                  <div class="min-w-0">
                    <p class="truncate text-content">
                      {{ user.full_name }}
                      <span
                        v-if="user.is_owner"
                        class="ml-1 rounded bg-primary-600/10 px-1.5 py-0.5 text-xs text-primary-700 dark:text-primary-300"
                      >
                        Owner
                      </span>
                    </p>
                    <p class="truncate text-xs text-content-muted">{{ user.email }}</p>
                  </div>
                </div>
              </td>

              <td class="px-5 py-3 text-content-muted">
                {{ user.roles?.map((role) => role.label).join(', ') || '—' }}
              </td>

              <td class="px-5 py-3">
                <span
                  :class="['rounded px-2 py-0.5 text-xs font-medium', statusStyles[user.status]]"
                >
                  {{ user.status_label }}
                </span>
                <!-- Lockout is transient and self-clearing, so it is shown as a note rather
                     than a status: the account is still active. -->
                <span
                  v-if="user.security?.is_locked"
                  class="ml-1 rounded bg-danger/10 px-2 py-0.5 text-xs text-danger"
                >
                  Locked
                </span>
              </td>

              <td class="px-5 py-3 text-content-muted">
                {{ user.security ? relative(user.security.last_activity_at) : '—' }}
              </td>

              <td class="px-5 py-3">
                <div class="flex justify-end gap-1">
                  <PermissionGate permission="identity.credentials.reset_password">
                    <BaseButton variant="ghost" size="sm" @click="sendReset(user)"
                      >Reset password</BaseButton
                    >
                  </PermissionGate>

                  <PermissionGate permission="identity.users.suspend">
                    <BaseButton
                      v-if="user.status === 'active'"
                      variant="ghost"
                      size="sm"
                      @click="act(user, 'suspend')"
                    >
                      Suspend
                    </BaseButton>
                    <BaseButton
                      v-else-if="user.status === 'suspended'"
                      variant="ghost"
                      size="sm"
                      @click="act(user, 'reinstate')"
                    >
                      Restore
                    </BaseButton>
                  </PermissionGate>

                  <PermissionGate permission="identity.users.deactivate">
                    <BaseButton
                      v-if="user.status !== 'deactivated'"
                      variant="ghost"
                      size="sm"
                      @click="act(user, 'deactivate')"
                    >
                      Deactivate
                    </BaseButton>
                  </PermissionGate>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <template v-if="meta?.pagination && meta.pagination.last_page > 1" #footer>
        <div class="flex items-center justify-between text-sm">
          <p class="text-content-muted">
            {{ meta.pagination.from }}–{{ meta.pagination.to }} of {{ meta.pagination.total }}
          </p>
          <div class="flex gap-2">
            <BaseButton
              variant="secondary"
              size="sm"
              :disabled="meta.pagination.current_page <= 1"
              @click="load(meta.pagination.current_page - 1)"
            >
              Previous
            </BaseButton>
            <BaseButton
              variant="secondary"
              size="sm"
              :disabled="meta.pagination.current_page >= meta.pagination.last_page"
              @click="load(meta.pagination.current_page + 1)"
            >
              Next
            </BaseButton>
          </div>
        </div>
      </template>
    </SurfaceCard>
  </div>
</template>
