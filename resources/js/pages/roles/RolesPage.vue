<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import { useUiStore } from '@/stores/ui'
import type { PermissionGroup, Role } from '@/types/domain'

/**
 * Roles and the permission matrix.
 *
 * The matrix is rendered from the server's grouped catalogue, so a capability added in a
 * later phase appears here with its label and help text and no front-end change. Hard-coding
 * the groups would mean every new module needing a UI edit, and one of them being forgotten.
 *
 * Controls are disabled from the server's own `capabilities` flags rather than re-derived
 * here. Two implementations of "may this role be edited?" would eventually disagree, and the
 * one the user sees would be the wrong one.
 */
const ui = useUiStore()

const roles = ref<Role[]>([])
const permissionGroups = ref<PermissionGroup[]>([])
const selected = ref<Role | null>(null)
const draft = ref<Set<string>>(new Set())
const loading = ref(true)
const saving = ref(false)
const actorLevel = ref(0)

onMounted(async () => {
  await Promise.all([loadRoles(), loadPermissions()])
  loading.value = false
})

async function loadRoles(): Promise<void> {
  const response = await api.get<Role[]>('/roles')
  roles.value = response.data
  actorLevel.value = Number(response.meta.actor_role_level ?? 0)
}

async function loadPermissions(): Promise<void> {
  const { data } = await api.get<PermissionGroup[]>('/permissions')
  permissionGroups.value = data
}

async function select(role: Role): Promise<void> {
  const { data } = await api.get<Role>(`/roles/${role.id}`)
  selected.value = data
  draft.value = new Set(data.permissions ?? [])
}

function toggle(permission: string): void {
  if (draft.value.has(permission)) {
    draft.value.delete(permission)
  } else {
    draft.value.add(permission)
  }

  // Reassigned so Vue's reactivity sees the change — a Set mutated in place is not tracked.
  draft.value = new Set(draft.value)
}

async function save(): Promise<void> {
  if (!selected.value) return

  saving.value = true

  try {
    await api.put(`/roles/${selected.value.id}`, {
      label: selected.value.label,
      description: selected.value.description,
      permissions: [...draft.value],
    })

    await loadRoles()
    ui.notify('success', `“${selected.value.label}” updated.`)
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not save.')
  } finally {
    saving.value = false
  }
}

function humanise(value: string): string {
  return value.replace(/_/g, ' ').replace(/^./, (char) => char.toUpperCase())
}
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-content">Roles</h1>
      <p class="mt-1 text-sm text-content-muted">
        What each role in your workspace is allowed to do.
      </p>
    </header>

    <div v-if="loading" class="py-12 text-center text-sm text-content-muted">Loading…</div>

    <div v-else class="grid gap-5 lg:grid-cols-[18rem_1fr]">
      <!-- ── Role list ─────────────────────────────────────────────────── -->
      <SurfaceCard>
        <ul class="-my-1 divide-y divide-surface-border">
          <li v-for="role in roles" :key="role.id">
            <button
              type="button"
              class="w-full py-3 text-left transition"
              :class="selected?.id === role.id ? 'text-primary-700 dark:text-primary-300' : 'text-content hover:text-primary-700'"
              @click="select(role)"
            >
              <span class="flex items-center justify-between gap-2">
                <span class="truncate text-sm font-medium">{{ role.label }}</span>
                <span v-if="role.is_system" class="shrink-0 rounded bg-surface-sunken px-1.5 py-0.5 text-xs text-content-subtle">
                  Built in
                </span>
              </span>
              <span class="mt-0.5 block text-xs text-content-muted">
                Level {{ role.level }}
                <span v-if="role.assigned_user_count !== undefined">
                  · {{ role.assigned_user_count }} user(s)
                </span>
              </span>
            </button>
          </li>
        </ul>
      </SurfaceCard>

      <!-- ── Permission matrix ─────────────────────────────────────────── -->
      <SurfaceCard
        v-if="selected"
        :title="selected.label"
        :description="selected.description ?? undefined"
      >
        <AlertBanner v-if="selected.is_owner" kind="info" title="The owner holds every capability">
          Ownership is not a list of permissions — it always includes everything, including
          capabilities added in future releases. That is why it cannot be edited.
        </AlertBanner>

        <AlertBanner
          v-else-if="!selected.capabilities.permissions_editable || selected.level >= actorLevel"
          kind="warning"
        >
          You cannot change this role, because it grants at least as much authority as your own.
        </AlertBanner>

        <div v-else class="space-y-6">
          <fieldset v-for="group in permissionGroups" :key="group.module">
            <legend class="text-sm font-semibold text-content">{{ humanise(group.module) }}</legend>

            <div v-for="resource in group.resources" :key="resource.resource" class="mt-3">
              <p class="text-xs uppercase tracking-wide text-content-subtle">
                {{ humanise(resource.resource) }}
              </p>

              <div class="mt-1.5 grid gap-1.5 sm:grid-cols-2">
                <label
                  v-for="permission in resource.permissions"
                  :key="permission.name"
                  class="flex items-start gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-surface-sunken"
                >
                  <input
                    type="checkbox"
                    :checked="draft.has(permission.name)"
                    class="form-checkbox mt-0.5 rounded border-surface-border text-primary-600 focus:ring-primary-500"
                    @change="toggle(permission.name)"
                  />
                  <span class="min-w-0">
                    <span class="block text-content">
                      {{ permission.label }}
                      <!-- Sensitive capabilities are marked, not hidden: an administrator
                           choosing what to delegate needs to see which grants can move money
                           or weaken someone's security. -->
                      <span
                        v-if="permission.is_sensitive"
                        class="ml-1 rounded bg-warning/15 px-1.5 py-0.5 text-xs text-warning"
                        title="Can move money or change security settings"
                      >
                        Sensitive
                      </span>
                    </span>
                    <span v-if="permission.description" class="block text-xs text-content-muted">
                      {{ permission.description }}
                    </span>
                  </span>
                </label>
              </div>
            </div>
          </fieldset>
        </div>

        <template v-if="selected.capabilities.permissions_editable && selected.level < actorLevel" #footer>
          <div class="flex items-center justify-between">
            <p class="text-sm text-content-muted">{{ draft.size }} permission(s) selected</p>
            <BaseButton :loading="saving" @click="save">Save changes</BaseButton>
          </div>
        </template>
      </SurfaceCard>

      <SurfaceCard v-else>
        <p class="py-8 text-center text-sm text-content-muted">
          Choose a role to see what it allows.
        </p>
      </SurfaceCard>
    </div>
  </div>
</template>
