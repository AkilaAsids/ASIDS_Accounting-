<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import { useAuthStore } from '@/stores/auth'
import { useMoney } from '@/composables/useMoney'
import { useUiStore } from '@/stores/ui'
import type { TrialBalanceMeta, TrialBalanceRow } from '@/types/domain'

/**
 * The trial balance.
 *
 * The report that says whether the books are sound, so the one thing this page must never do is
 * compute its own totals. Every figure here — including the debit and credit sums and the answer to
 * "does it tie?" — comes from the server. A client that added up doubles would produce a total that
 * disagrees with the ledger by a few cents, and the customer would have two numbers and no way to
 * know which to believe.
 */
const auth = useAuthStore()
const ui = useUiStore()
const { formatPlain, isZero } = useMoney()

const rows = ref<TrialBalanceRow[]>([])
const meta = ref<TrialBalanceMeta | null>(null)
const loading = ref(true)

const from = ref('')
const to = ref('')

const companyId = computed<string | null>(() => auth.activeCompany?.id ?? null)

/** Grouped for reading, in the order a set of books is laid out. */
const groups = computed(() => {
  const order: Array<{ type: string; label: string }> = [
    { type: 'asset', label: 'Assets' },
    { type: 'liability', label: 'Liabilities' },
    { type: 'equity', label: 'Equity' },
    { type: 'income', label: 'Income' },
    { type: 'expense', label: 'Expenses' },
  ]

  return order
    .map((group) => ({ ...group, rows: rows.value.filter((row) => row.type === group.type) }))
    .filter((group) => group.rows.length > 0)
})

onMounted(load)

async function load(): Promise<void> {
  if (companyId.value === null) {
    loading.value = false
    return
  }

  loading.value = true

  try {
    const response = await api.get<TrialBalanceRow[]>(`/companies/${companyId.value}/reports/trial-balance`, {
      // Omitted when blank, so the server applies its own default — the company's fiscal year, which
      // for an April-start business is not the calendar one.
      from: from.value === '' ? undefined : from.value,
      to: to.value === '' ? undefined : to.value,
    })

    rows.value = response.data
    meta.value = response.meta as unknown as TrialBalanceMeta

    // Populated from the response so the inputs show the range actually reported, including when the
    // server chose it.
    from.value = meta.value.from
    to.value = meta.value.to
  } catch (thrown) {
    ui.notify('error', thrown instanceof ApiError ? thrown.problem.detail : 'Could not load the trial balance.')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-content">Trial balance</h1>
      <p class="mt-1 text-sm text-content-muted">
        Every account with movement in the period, and whether the books tie.
      </p>
    </header>

    <SurfaceCard>
      <form class="flex flex-wrap items-end gap-3" @submit.prevent="load">
        <div>
          <label for="tb-from" class="field-label">From</label>
          <input
            id="tb-from"
            v-model="from"
            type="date"
            class="form-input mt-1 rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
          />
        </div>
        <div>
          <label for="tb-to" class="field-label">To</label>
          <input
            id="tb-to"
            v-model="to"
            type="date"
            class="form-input mt-1 rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
          />
        </div>
        <BaseButton type="submit" :loading="loading">Run</BaseButton>
      </form>
    </SurfaceCard>

    <!-- If this ever appears, something has bypassed both the posting service and a deferred
         constraint trigger. It is stated loudly rather than left for the reader to notice that two
         totals differ, because a trial balance that does not tie is not a reporting problem. -->
    <AlertBanner v-if="meta && !meta.ties" kind="error" title="This trial balance does not tie">
      The debits and credits for this period are not equal. Contact support before relying on any
      report from this company — the ledger itself needs investigating.
    </AlertBanner>

    <SurfaceCard>
      <div v-if="loading" class="py-12 text-center text-sm text-content-muted">Loading…</div>

      <p v-else-if="rows.length === 0" class="py-12 text-center text-sm text-content-muted">
        No account had any movement in this period.
      </p>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <caption class="sr-only">
            Trial balance from {{ meta?.from }} to {{ meta?.to }}
          </caption>
          <thead>
            <tr class="border-b border-surface-border text-left text-xs uppercase tracking-wide text-content-subtle">
              <th scope="col" class="py-2 pr-4">Code</th>
              <th scope="col" class="py-2 pr-4">Account</th>
              <th scope="col" class="py-2 pr-4 text-right">Debit</th>
              <th scope="col" class="py-2 pr-4 text-right">Credit</th>
              <th scope="col" class="py-2 text-right">Balance</th>
            </tr>
          </thead>

          <tbody v-for="group in groups" :key="group.type">
            <tr class="bg-surface-sunken">
              <th scope="rowgroup" colspan="5" class="py-1.5 pl-0 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-content-muted">
                {{ group.label }}
              </th>
            </tr>
            <tr v-for="row in group.rows" :key="row.account_id" class="border-b border-surface-border/60">
              <td class="py-2 pr-4 font-mono text-xs text-content">{{ row.code }}</td>
              <td class="py-2 pr-4 text-content">{{ row.name }}</td>
              <!-- Blanked when zero. An accountant reads a trial balance by scanning for the side
                   that carries a figure, and "0.00" in every other cell defeats that. -->
              <td class="py-2 pr-4 text-right font-mono tabular-nums text-content">
                {{ isZero(row.debit) ? '' : formatPlain(row.debit) }}
              </td>
              <td class="py-2 pr-4 text-right font-mono tabular-nums text-content">
                {{ isZero(row.credit) ? '' : formatPlain(row.credit) }}
              </td>
              <td class="py-2 text-right font-mono tabular-nums text-content">
                {{ formatPlain(row.balance) }}
              </td>
            </tr>
          </tbody>

          <tfoot v-if="meta">
            <tr class="border-t-2 border-surface-border font-semibold">
              <td class="py-2 pr-4" colspan="2">Total</td>
              <!-- Server-computed. See the module docblock: these are not sums of the column above. -->
              <td class="py-2 pr-4 text-right font-mono tabular-nums text-content">
                {{ formatPlain(meta.totals.debit) }}
              </td>
              <td class="py-2 pr-4 text-right font-mono tabular-nums text-content">
                {{ formatPlain(meta.totals.credit) }}
              </td>
              <td class="py-2 text-right">
                <span v-if="meta.ties" class="text-xs font-normal text-success">ties</span>
                <span v-else class="text-xs font-normal text-danger">does not tie</span>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </SurfaceCard>
  </div>
</template>
