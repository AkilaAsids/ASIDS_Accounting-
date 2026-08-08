<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import TextField from '@/components/ui/TextField.vue'
import PermissionGate from '@/components/app/PermissionGate.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import type { Account, AccountType, ChartTemplateOffer } from '@/types/domain'

/**
 * The chart of accounts.
 *
 * Rendered as a tree rather than a flat list, because that is what the chart *is*: a controller
 * reading it wants to see that three bank accounts roll up into one line on the balance sheet.
 *
 * Two things the server decides and this page only displays. The normal balance is derived from the
 * account type — reimplementing that mapping here would be a second place for it to be wrong, and the
 * symptom is a report with every figure inverted. And the starter template's disclaimer comes from
 * the API rather than being written into this file, so a correction to the wording reaches every
 * client at once.
 */
const auth = useAuthStore()
const ui = useUiStore()

const accounts = ref<Account[]>([])
const template = ref<ChartTemplateOffer | null>(null)
const loading = ref(true)
const showArchived = ref(false)

const creating = ref(false)
const createBusy = ref(false)
const createErrors = ref<Record<string, string>>({})
const form = ref({ code: '', name: '', type: 'asset' as AccountType, parent_id: '' })

const companyId = computed<string | null>(() => auth.activeCompany?.id ?? null)

const accountTypes: Array<{ value: AccountType; label: string }> = [
  { value: 'asset', label: 'Asset' },
  { value: 'liability', label: 'Liability' },
  { value: 'equity', label: 'Equity' },
  { value: 'income', label: 'Income' },
  { value: 'expense', label: 'Expense' },
]

/**
 * The chart as a tree, in the order an accountant reads it.
 *
 * Built here rather than requested from the server as nested JSON: the flat list is what the account
 * pickers elsewhere need, and one representation that both screens share beats two endpoints that can
 * disagree about which accounts exist.
 */
interface TreeNode {
  account: Account
  depth: number
}

const tree = computed<TreeNode[]>(() => {
  const byParent = new Map<string | null, Account[]>()

  for (const account of accounts.value) {
    const key = account.parent_id
    byParent.set(key, [...(byParent.get(key) ?? []), account])
  }

  const flatten = (parentId: string | null, depth: number): TreeNode[] =>
    (byParent.get(parentId) ?? [])
      .sort((left, right) => left.sort_order - right.sort_order || left.code.localeCompare(right.code))
      .flatMap((account) => [{ account, depth }, ...flatten(account.id, depth + 1)])

  return flatten(null, 0)
})

const isEmpty = computed<boolean>(() => !loading.value && accounts.value.length === 0)

onMounted(async () => {
  await Promise.all([load(), loadTemplate()])
})

async function load(): Promise<void> {
  if (companyId.value === null) {
    loading.value = false
    return
  }

  loading.value = true

  try {
    const { data } = await api.get<Account[]>(`/companies/${companyId.value}/accounts`, {
      active_only: !showArchived.value,
    })

    accounts.value = data
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not load the chart of accounts.')
  } finally {
    loading.value = false
  }
}

async function loadTemplate(): Promise<void> {
  if (companyId.value === null) {
    return
  }

  try {
    const { data } = await api.get<ChartTemplateOffer>(`/companies/${companyId.value}/accounts/template`)
    template.value = data
  } catch {
    // A missing template offer is not worth a notice: the page is still usable without it, and the
    // only consequence is that the "start from a template" card does not appear.
    template.value = null
  }
}

async function applyTemplate(): Promise<void> {
  if (companyId.value === null || template.value === null) {
    return
  }

  if (!window.confirm(`Create ${template.value.account_count} accounts from “${template.value.name}”?`)) {
    return
  }

  try {
    await api.post(`/companies/${companyId.value}/accounts/template`)
    ui.notify('success', 'The starter chart has been created. Review it with your accountant before filing.')
    await Promise.all([load(), loadTemplate()])
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not apply the template.')
  }
}

async function create(): Promise<void> {
  if (companyId.value === null) {
    return
  }

  createBusy.value = true
  createErrors.value = {}

  try {
    await api.post(`/companies/${companyId.value}/accounts`, {
      code: form.value.code,
      name: form.value.name,
      type: form.value.type,
      parent_id: form.value.parent_id === '' ? null : form.value.parent_id,
    })

    ui.notify('success', `Account ${form.value.code} created.`)
    creating.value = false
    form.value = { code: '', name: '', type: 'asset', parent_id: '' }
    await load()
  } catch (thrown) {
    if (thrown instanceof ApiError) {
      createErrors.value = thrown.fieldErrors

      // A hierarchy or duplicate-code refusal is a domain rule, not a field-level validation error,
      // so it arrives without a field to pin it to. Surfacing it as a notice is the difference
      // between "the form did nothing" and knowing why.
      if (Object.keys(thrown.fieldErrors).length === 0) {
        ui.notify('error', thrown.problem.detail)
      }

      return
    }

    throw thrown
  } finally {
    createBusy.value = false
  }
}

async function archive(account: Account): Promise<void> {
  if (companyId.value === null) {
    return
  }

  if (!window.confirm(`Archive ${account.code} ${account.name}? Its history stays readable.`)) {
    return
  }

  try {
    await api.post(`/companies/${companyId.value}/accounts/${account.id}/archive`)
    ui.notify('success', 'Account archived.')
    await load()
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not archive the account.')
  }
}

async function restore(account: Account): Promise<void> {
  if (companyId.value === null) {
    return
  }

  try {
    await api.post(`/companies/${companyId.value}/accounts/${account.id}/restore`)
    await load()
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not restore the account.')
  }
}

const typeStyles: Record<AccountType, string> = {
  asset: 'bg-info/10 text-info',
  liability: 'bg-warning/10 text-warning',
  equity: 'bg-primary-500/10 text-primary-700 dark:text-primary-300',
  income: 'bg-success/10 text-success',
  expense: 'bg-danger/10 text-danger',
}
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-content">Chart of accounts</h1>
        <p class="mt-1 text-sm text-content-muted">
          The accounts this company keeps its books in.
        </p>
      </div>

      <PermissionGate permission="accounting.accounts.manage">
        <BaseButton @click="creating = !creating">
          {{ creating ? 'Cancel' : 'Add an account' }}
        </BaseButton>
      </PermissionGate>
    </header>

    <!-- Offered only to a company with no chart yet. Applying it over an existing one is refused by
         the server, and a button that always fails is worse than no button. -->
    <SurfaceCard v-if="template && template.can_apply" :title="`Start from ${template.name}`">
      <p class="text-sm text-content-muted">{{ template.description }}</p>

      <AlertBanner kind="warning" title="Have this reviewed before you rely on it" class="mt-4">
        {{ template.disclaimer }}
      </AlertBanner>

      <template #footer>
        <PermissionGate permission="accounting.accounts.manage">
          <BaseButton variant="secondary" @click="applyTemplate">
            Create {{ template.account_count }} accounts
          </BaseButton>
        </PermissionGate>
      </template>
    </SurfaceCard>

    <SurfaceCard v-if="creating" title="Add an account">
      <form class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="create">
        <TextField v-model="form.code" label="Code" :error="createErrors.code" required />
        <TextField v-model="form.name" label="Name" :error="createErrors.name" required />

        <div>
          <label for="account-type" class="field-label">Type</label>
          <select
            id="account-type"
            v-model="form.type"
            class="form-select mt-1 block w-full rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
          >
            <option v-for="option in accountTypes" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
          <!-- Stated rather than left to be discovered on save. The parent must share the child's
               type, and finding that out by failing is a poor way to learn it. -->
          <p class="field-hint">A parent account must be of the same type.</p>
        </div>

        <div>
          <label for="account-parent" class="field-label">Rolls up into</label>
          <select
            id="account-parent"
            v-model="form.parent_id"
            class="form-select mt-1 block w-full rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
          >
            <option value="">Nothing — a top-level account</option>
            <option
              v-for="candidate in accounts.filter((a) => a.type === form.type && !a.is_postable)"
              :key="candidate.id"
              :value="candidate.id"
            >
              {{ candidate.code }} — {{ candidate.name }}
            </option>
          </select>
        </div>

        <div class="sm:col-span-2">
          <BaseButton type="submit" :loading="createBusy">Create account</BaseButton>
        </div>
      </form>
    </SurfaceCard>

    <SurfaceCard>
      <div class="mb-3 flex items-center justify-between">
        <label class="flex items-center gap-2 text-sm text-content-muted">
          <input
            v-model="showArchived"
            type="checkbox"
            class="form-checkbox rounded border-surface-border text-primary-600 focus:ring-primary-500"
            @change="load"
          />
          Show archived accounts
        </label>
      </div>

      <div v-if="loading" class="py-12 text-center text-sm text-content-muted">Loading…</div>

      <p v-else-if="isEmpty" class="py-12 text-center text-sm text-content-muted">
        This company has no accounts yet.
      </p>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="border-b border-surface-border text-left text-xs uppercase tracking-wide text-content-subtle">
              <th scope="col" class="py-2 pr-4">Code</th>
              <th scope="col" class="py-2 pr-4">Name</th>
              <th scope="col" class="py-2 pr-4">Type</th>
              <th scope="col" class="py-2 pr-4">Normal balance</th>
              <th scope="col" class="py-2 pr-4"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="node in tree"
              :key="node.account.id"
              class="border-b border-surface-border/60"
              :class="!node.account.is_active && 'opacity-60'"
            >
              <td class="py-2 pr-4 font-mono text-xs text-content">
                <!-- Indented by depth so the roll-up is visible at a glance. A flat list of codes
                     makes the reader reconstruct the hierarchy in their head. -->
                <span :style="{ paddingLeft: `${node.depth * 1.25}rem` }">{{ node.account.code }}</span>
              </td>
              <td class="py-2 pr-4 text-content" :class="!node.account.is_postable && 'font-semibold'">
                {{ node.account.name }}
                <span v-if="!node.account.is_postable" class="ml-2 text-xs text-content-subtle">heading</span>
                <span v-if="node.account.is_system" class="ml-2 text-xs text-content-subtle">system</span>
              </td>
              <td class="py-2 pr-4">
                <span :class="['rounded px-2 py-0.5 text-xs', typeStyles[node.account.type]]">
                  {{ node.account.type_label }}
                </span>
              </td>
              <td class="py-2 pr-4 text-content-muted">{{ node.account.normal_balance }}</td>
              <td class="py-2 pr-4 text-right">
                <button
                  v-if="node.account.capabilities.can_update && node.account.is_active && !node.account.is_system"
                  type="button"
                  class="text-xs text-primary-700 hover:underline dark:text-primary-400"
                  @click="archive(node.account)"
                >
                  Archive
                </button>
                <button
                  v-else-if="node.account.capabilities.can_update && !node.account.is_active"
                  type="button"
                  class="text-xs text-primary-700 hover:underline dark:text-primary-400"
                  @click="restore(node.account)"
                >
                  Restore
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </SurfaceCard>
  </div>
</template>
