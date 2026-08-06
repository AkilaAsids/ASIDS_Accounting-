<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import BaseButton from '@/components/ui/BaseButton.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import type { SettingField, SettingGroup, SettingScope } from '@/types/domain'

/**
 * Settings, rendered entirely from the server's catalogue.
 *
 * Each field arrives with its type, label, help text, options and whether it is currently an
 * override. The control is chosen from `type`, so adding a setting on the server makes it
 * appear here correctly with no front-end change — which is the only way a hundred-plus
 * settings stays maintainable.
 */
const auth = useAuthStore()
const ui = useUiStore()

const scope = ref<SettingScope>('user')
const groups = ref<SettingGroup[]>([])
const draft = ref<Record<string, unknown>>({})
const loading = ref(true)
const saving = ref(false)

const availableScopes = ref<Array<{ value: SettingScope; label: string }>>([])

onMounted(() => {
  availableScopes.value = [
    { value: 'user', label: 'Personal' },
    // Workspace and company settings are administrative, so the tabs only appear for someone
    // who can actually read them — an empty tab that 403s is worse than no tab.
    ...(auth.can('settings.company.view') ? [{ value: 'company' as SettingScope, label: 'Company' }] : []),
    ...(auth.can('settings.workspace.view') ? [{ value: 'tenant' as SettingScope, label: 'Workspace' }] : []),
  ]

  void load()
})

watch(scope, () => void load())

async function load(): Promise<void> {
  loading.value = true

  try {
    const { data } = await api.get<SettingGroup[]>('/settings', { scope: scope.value })
    groups.value = data

    draft.value = Object.fromEntries(
      data.flatMap((group) => group.settings.map((field) => [field.key, field.value])),
    )
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not load settings.')
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  saving.value = true

  try {
    await api.put('/settings', {
      scope: scope.value,
      // Personal scope needs no target — the server uses the authenticated user, which is
      // also what stops a client from writing someone else's preferences.
      scope_id: scope.value === 'company' ? auth.activeCompany?.id : undefined,
      settings: draft.value,
    })

    // Reloaded so `is_overridden` and any server-side coercion are reflected, rather than
    // trusting the local draft to match what was stored.
    await load()

    // Locale and branding settings change how the shell itself renders.
    await auth.fetchSession()

    ui.notify('success', 'Settings saved.')
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not save settings.')
  } finally {
    saving.value = false
  }
}

async function reset(field: SettingField): Promise<void> {
  try {
    await api.delete(`/settings/${field.key}`, { scope: scope.value })
    await load()
    ui.notify('success', `“${field.label}” reset to inherited.`)
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not reset.')
  }
}

function humanise(value: string): string {
  return value.replace(/_/g, ' ').replace(/^./, (char) => char.toUpperCase())
}
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-content">Settings</h1>
      <p class="mt-1 text-sm text-content-muted">
        Personal settings apply only to you. Workspace settings apply to everyone.
      </p>
    </header>

    <div class="flex gap-1 border-b border-surface-border" role="tablist">
      <button
        v-for="option in availableScopes"
        :key="option.value"
        type="button"
        role="tab"
        :aria-selected="scope === option.value"
        class="border-b-2 px-4 py-2 text-sm font-medium transition"
        :class="
          scope === option.value
            ? 'border-primary-600 text-primary-700 dark:text-primary-300'
            : 'border-transparent text-content-muted hover:text-content'
        "
        @click="scope = option.value"
      >
        {{ option.label }}
      </button>
    </div>

    <div v-if="loading" class="py-12 text-center text-sm text-content-muted">Loading…</div>

    <template v-else>
      <SurfaceCard v-for="group in groups" :key="group.group" :title="humanise(group.group)">
        <div class="space-y-5">
          <div v-for="field in group.settings" :key="field.key">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <label :for="field.key" class="field-label">{{ field.label }}</label>
                <p class="field-hint">{{ field.description }}</p>
              </div>

              <!-- "Inherited" is shown, not hidden: without it a user cannot tell a
                   deliberate choice from a value that will change when the workspace's does. -->
              <button
                v-if="field.is_overridden"
                type="button"
                class="shrink-0 text-xs text-primary-700 hover:underline dark:text-primary-400"
                @click="reset(field)"
              >
                Reset to inherited
              </button>
              <span v-else class="shrink-0 rounded bg-surface-sunken px-1.5 py-0.5 text-xs text-content-subtle">
                Inherited
              </span>
            </div>

            <div class="mt-2">
              <label v-if="field.type === 'boolean'" class="flex items-center gap-2 text-sm text-content">
                <input
                  :id="field.key"
                  v-model="draft[field.key]"
                  type="checkbox"
                  class="form-checkbox rounded border-surface-border text-primary-600 focus:ring-primary-500"
                />
                Enabled
              </label>

              <select
                v-else-if="field.options"
                :id="field.key"
                v-model="draft[field.key]"
                class="form-select w-full rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500 sm:w-auto"
              >
                <option v-for="(label, value) in field.options" :key="value" :value="value">
                  {{ label }}
                </option>
              </select>

              <textarea
                v-else-if="field.type === 'text'"
                :id="field.key"
                v-model="draft[field.key]"
                rows="3"
                class="form-textarea w-full rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
              />

              <input
                v-else
                :id="field.key"
                v-model="draft[field.key]"
                :type="field.type === 'integer' || field.type === 'float' ? 'number' : 'text'"
                class="form-input w-full rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500 sm:w-64"
              />
            </div>
          </div>
        </div>
      </SurfaceCard>

      <div class="flex justify-end">
        <BaseButton :loading="saving" @click="save">Save settings</BaseButton>
      </div>
    </template>
  </div>
</template>
