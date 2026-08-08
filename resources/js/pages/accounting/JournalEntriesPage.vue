<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import BaseButton from '@/components/ui/BaseButton.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import TextField from '@/components/ui/TextField.vue'
import PermissionGate from '@/components/app/PermissionGate.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import type { Account, JournalEntry } from '@/types/domain'

/**
 * Journal entries: the list, and the form that creates one.
 *
 * The form deliberately does not tell the user whether their entry balances until they submit it.
 * A running difference computed in the browser would be a second arithmetic implementation, in the
 * one language whose default number type cannot represent 0.1 — and when it disagreed with the
 * server by a cent, the customer would trust the number in front of them.
 *
 * So the amounts stay strings all the way to the API, and the refusal that comes back names the
 * difference exactly: "debits total X and credits total Y — a difference of Z".
 */
const auth = useAuthStore()
const ui = useUiStore()

const entries = ref<JournalEntry[]>([])
const accounts = ref<Account[]>([])
const loading = ref(true)
const statusFilter = ref('')

const creating = ref(false)
const busy = ref(false)
const errors = ref<Record<string, string>>({})

interface LineDraft {
  account_id: string
  debit: string
  credit: string
  description: string
}

const form = ref({
  entry_date: new Date().toISOString().slice(0, 10),
  description: '',
  reference: '',
  lines: [emptyLine(), emptyLine()] as LineDraft[],
})

function emptyLine(): LineDraft {
  return { account_id: '', debit: '', credit: '', description: '' }
}

const companyId = computed<string | null>(() => auth.activeCompany?.id ?? null)

/** Only accounts an entry may actually post to — leaves, and not archived. */
const postableAccounts = computed<Account[]>(() =>
  accounts.value.filter((account) => account.capabilities.accepts_postings),
)

const canPost = computed<boolean>(() => auth.can('accounting.journals.post'))

onMounted(async () => {
  await Promise.all([load(), loadAccounts()])
})

async function load(): Promise<void> {
  if (companyId.value === null) {
    loading.value = false
    return
  }

  loading.value = true

  try {
    const { data } = await api.get<JournalEntry[]>(`/companies/${companyId.value}/journal-entries`, {
      filter: statusFilter.value === '' ? undefined : { status: statusFilter.value },
    })

    entries.value = data
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not load journal entries.')
  } finally {
    loading.value = false
  }
}

async function loadAccounts(): Promise<void> {
  if (companyId.value === null) {
    return
  }

  try {
    const { data } = await api.get<Account[]>(`/companies/${companyId.value}/accounts`, { postable_only: true })
    accounts.value = data
  } catch {
    accounts.value = []
  }
}

function addLine(): void {
  form.value.lines.push(emptyLine())
}

function removeLine(index: number): void {
  // Never below two. An entry needs something debited and something credited, and a form that lets
  // you delete down to one line only to refuse on submit is wasting the user's time.
  if (form.value.lines.length > 2) {
    form.value.lines.splice(index, 1)
  }
}

/**
 * Submit, optionally posting.
 *
 * `post` is passed to the server rather than deciding anything here: the button is hidden from a
 * bookkeeper by permission, and the server refuses it regardless. The client's copy of the rule is
 * for the interface, never for the outcome.
 */
async function submit(post: boolean): Promise<void> {
  if (companyId.value === null) {
    return
  }

  busy.value = true
  errors.value = {}

  try {
    await api.post(`/companies/${companyId.value}/journal-entries`, {
      entry_date: form.value.entry_date,
      description: form.value.description,
      reference: form.value.reference === '' ? null : form.value.reference,
      lines: form.value.lines
        .filter((line) => line.account_id !== '')
        .map((line) => ({
          account_id: line.account_id,
          // Sent as strings, exactly as typed. Parsing them here would be the first place a cent
          // could go missing.
          debit: line.debit === '' ? null : line.debit,
          credit: line.credit === '' ? null : line.credit,
          description: line.description === '' ? null : line.description,
        })),
      post,
    })

    ui.notify('success', post ? 'Entry posted.' : 'Draft saved.')
    creating.value = false
    form.value = {
      entry_date: new Date().toISOString().slice(0, 10),
      description: '',
      reference: '',
      lines: [emptyLine(), emptyLine()],
    }
    await load()
  } catch (thrown) {
    if (thrown instanceof ApiError) {
      errors.value = thrown.fieldErrors

      // An imbalance, a closed period or an unpostable account arrives as a domain refusal with no
      // field to attach it to. The message names the amount and the side, which is what the person
      // fixing it needs.
      if (Object.keys(thrown.fieldErrors).length === 0) {
        ui.notify('error', thrown.problem.detail)
      }

      return
    }

    throw thrown
  } finally {
    busy.value = false
  }
}

async function postEntry(entry: JournalEntry): Promise<void> {
  if (companyId.value === null) {
    return
  }

  try {
    await api.post(`/companies/${companyId.value}/journal-entries/${entry.id}/post`)
    ui.notify('success', 'Entry posted.')
    await load()
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not post the entry.')
  }
}

async function reverseEntry(entry: JournalEntry): Promise<void> {
  if (companyId.value === null) {
    return
  }

  const reason = window.prompt('Why is this entry being reversed? It is recorded against the original.')

  if (reason === null || reason.trim() === '') {
    return
  }

  try {
    await api.post(`/companies/${companyId.value}/journal-entries/${entry.id}/reverse`, { reason })
    ui.notify('success', 'A reversing entry has been posted. Both entries remain in the ledger.')
    await load()
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not reverse the entry.')
  }
}

const statusStyles: Record<string, string> = {
  draft: 'bg-surface-sunken text-content-subtle',
  posted: 'bg-success/10 text-success',
  reversed: 'bg-warning/10 text-warning',
}
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-content">Journal entries</h1>
        <p class="mt-1 text-sm text-content-muted">
          Every double entry in this company's books.
        </p>
      </div>

      <PermissionGate permission="accounting.journals.draft">
        <BaseButton @click="creating = !creating">
          {{ creating ? 'Cancel' : 'New entry' }}
        </BaseButton>
      </PermissionGate>
    </header>

    <SurfaceCard v-if="creating" title="New journal entry">
      <form class="space-y-4" novalidate @submit.prevent="submit(false)">
        <div class="grid gap-4 sm:grid-cols-3">
          <div>
            <label for="entry-date" class="field-label">Date</label>
            <input
              id="entry-date"
              v-model="form.entry_date"
              type="date"
              class="form-input mt-1 block w-full rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
            />
            <p v-if="errors.entry_date" class="field-error" role="alert">{{ errors.entry_date }}</p>
          </div>
          <TextField v-model="form.description" label="Description" :error="errors.description" required />
          <TextField v-model="form.reference" label="Reference" :error="errors.reference" />
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead>
              <tr class="border-b border-surface-border text-left text-xs uppercase tracking-wide text-content-subtle">
                <th scope="col" class="py-2 pr-3">Account</th>
                <th scope="col" class="py-2 pr-3">Description</th>
                <th scope="col" class="py-2 pr-3 text-right">Debit</th>
                <th scope="col" class="py-2 pr-3 text-right">Credit</th>
                <th scope="col" class="py-2"><span class="sr-only">Remove</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(line, index) in form.lines" :key="index" class="border-b border-surface-border/60">
                <td class="py-2 pr-3">
                  <label :for="`line-account-${index}`" class="sr-only">Account for line {{ index + 1 }}</label>
                  <select
                    :id="`line-account-${index}`"
                    v-model="line.account_id"
                    class="form-select w-full min-w-48 rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
                  >
                    <option value="">Choose an account…</option>
                    <option v-for="account in postableAccounts" :key="account.id" :value="account.id">
                      {{ account.code }} — {{ account.name }}
                    </option>
                  </select>
                </td>
                <td class="py-2 pr-3">
                  <label :for="`line-desc-${index}`" class="sr-only">Description for line {{ index + 1 }}</label>
                  <input
                    :id="`line-desc-${index}`"
                    v-model="line.description"
                    type="text"
                    class="form-input w-full rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
                  />
                </td>
                <td class="py-2 pr-3">
                  <label :for="`line-debit-${index}`" class="sr-only">Debit for line {{ index + 1 }}</label>
                  <!-- `inputmode="decimal"` rather than `type="number"`: a number input hands back a
                       JavaScript float, and the whole point is that the amount reaches the server as
                       the string the user typed. -->
                  <input
                    :id="`line-debit-${index}`"
                    v-model="line.debit"
                    type="text"
                    inputmode="decimal"
                    placeholder="0.00"
                    class="form-input w-28 rounded-md border-surface-border bg-surface-raised text-right font-mono text-sm text-content focus:border-primary-500 focus:ring-primary-500"
                  />
                </td>
                <td class="py-2 pr-3">
                  <label :for="`line-credit-${index}`" class="sr-only">Credit for line {{ index + 1 }}</label>
                  <input
                    :id="`line-credit-${index}`"
                    v-model="line.credit"
                    type="text"
                    inputmode="decimal"
                    placeholder="0.00"
                    class="form-input w-28 rounded-md border-surface-border bg-surface-raised text-right font-mono text-sm text-content focus:border-primary-500 focus:ring-primary-500"
                  />
                </td>
                <td class="py-2 text-right">
                  <button
                    v-if="form.lines.length > 2"
                    type="button"
                    class="text-xs text-primary-700 hover:underline dark:text-primary-400"
                    :aria-label="`Remove line ${index + 1}`"
                    @click="removeLine(index)"
                  >
                    Remove
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="flex flex-wrap items-center gap-3">
          <BaseButton variant="ghost" type="button" @click="addLine">Add a line</BaseButton>

          <div class="ml-auto flex gap-2">
            <BaseButton variant="secondary" type="submit" :loading="busy">Save draft</BaseButton>
            <!-- Hidden from a bookkeeper. The server refuses it regardless — this is so the button
                 is not offered to someone it will always reject. -->
            <BaseButton v-if="canPost" type="button" :loading="busy" @click="submit(true)">
              Post entry
            </BaseButton>
          </div>
        </div>
      </form>
    </SurfaceCard>

    <SurfaceCard>
      <div class="mb-3">
        <label for="status-filter" class="sr-only">Filter by status</label>
        <select
          id="status-filter"
          v-model="statusFilter"
          class="form-select rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
          @change="load"
        >
          <option value="">All entries</option>
          <option value="draft">Drafts</option>
          <option value="posted">Posted</option>
          <option value="reversed">Reversed</option>
        </select>
      </div>

      <div v-if="loading" class="py-12 text-center text-sm text-content-muted">Loading…</div>

      <p v-else-if="entries.length === 0" class="py-12 text-center text-sm text-content-muted">
        No journal entries yet.
      </p>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="border-b border-surface-border text-left text-xs uppercase tracking-wide text-content-subtle">
              <th scope="col" class="py-2 pr-4">Number</th>
              <th scope="col" class="py-2 pr-4">Date</th>
              <th scope="col" class="py-2 pr-4">Description</th>
              <th scope="col" class="py-2 pr-4">Status</th>
              <th scope="col" class="py-2 pr-4"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in entries" :key="entry.id" class="border-b border-surface-border/60">
              <td class="py-2 pr-4 font-mono text-xs text-content">
                {{ entry.number ?? '—' }}
              </td>
              <td class="py-2 pr-4 text-content-muted">{{ entry.entry_date }}</td>
              <td class="py-2 pr-4 text-content">
                {{ entry.description }}
                <span v-if="entry.reversal_reason" class="block text-xs text-content-subtle">
                  Reversed: {{ entry.reversal_reason }}
                </span>
              </td>
              <td class="py-2 pr-4">
                <span :class="['rounded px-2 py-0.5 text-xs', statusStyles[entry.status]]">
                  {{ entry.status_label }}
                </span>
              </td>
              <td class="py-2 pr-4 text-right">
                <button
                  v-if="entry.capabilities.can_post"
                  type="button"
                  class="mr-3 text-xs text-primary-700 hover:underline dark:text-primary-400"
                  @click="postEntry(entry)"
                >
                  Post
                </button>
                <button
                  v-if="entry.capabilities.can_reverse"
                  type="button"
                  class="text-xs text-primary-700 hover:underline dark:text-primary-400"
                  @click="reverseEntry(entry)"
                >
                  Reverse
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </SurfaceCard>
  </div>
</template>
