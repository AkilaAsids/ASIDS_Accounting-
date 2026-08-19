<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import { useAuthStore } from '@/stores/auth'
import { useMoney } from '@/composables/useMoney'
import { useUiStore } from '@/stores/ui'
import type { OutstandingReceivableMeta, OutstandingReceivableRow } from '@/types/domain'

/**
 * What each customer currently owes.
 *
 * The same rule the trial balance is built on: this page does not add anything up. The total in the
 * footer is `meta.totals.outstanding`, computed by the server, because a client that summed the
 * column in JavaScript would produce a figure a few cents from the ledger's and the customer would
 * have two numbers with no way to know which to believe.
 *
 * There is no filter form and no date control, and that is the report rather than an omission. The
 * amount owed is current state with no history behind it, so there is no as-at date to ask for — the
 * server reports the day it read the figures and the header states it, which is what a printed copy
 * needs.
 *
 * The ordering is the server's too: largest debt first, then customer code so two equal balances do
 * not swap places between runs. Re-sorting here would throw that away.
 */
const auth = useAuthStore()
const ui = useUiStore()
const { formatPlain } = useMoney()

const rows = ref<OutstandingReceivableRow[]>([])
const meta = ref<OutstandingReceivableMeta | null>(null)
const loading = ref(true)

const companyId = computed<string | null>(() => auth.activeCompany?.id ?? null)

onMounted(load)

/**
 * Reload when the active company changes.
 *
 * Switching company refreshes the session in place — `App.vue` keys its `RouterView` on the route
 * path, not on the company — so a page already on screen is never re-mounted. Without this watch the
 * table would keep the previous company's debtors while the heading, the currency and every figure's
 * formatting had already moved to the new one: one company's balances presented as another's.
 *
 * Not `immediate`, so `onMounted` still owns the first request and a fresh page makes exactly one.
 */
watch(companyId, (id, previous) => {
  if (id !== previous) {
    void load()
  }
})

async function load(): Promise<void> {
  if (companyId.value === null) {
    loading.value = false
    return
  }

  loading.value = true

  try {
    const response = await api.get<OutstandingReceivableRow[]>(
      `/companies/${companyId.value}/reports/outstanding-receivables`,
    )

    rows.value = response.data
    meta.value = response.meta as unknown as OutstandingReceivableMeta
  } catch (thrown) {
    // Cleared rather than left in place: a stale table above a failure notice reads as though the
    // figures are current, and a receivables figure someone acts on must never be one the last
    // successful request left behind.
    rows.value = []
    meta.value = null

    ui.notify(
      'error',
      thrown instanceof ApiError ? thrown.problem.detail : 'Could not load outstanding receivables.',
    )
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-content">Outstanding receivables</h1>
      <p class="mt-1 text-sm text-content-muted">
        What each customer still owes on issued invoices.
        <template v-if="meta">
          Amounts in {{ meta.currency }}, as at {{ meta.as_of }}.
        </template>
      </p>
    </header>

    <SurfaceCard>
      <div v-if="loading" class="py-12 text-center text-sm text-content-muted">Loading…</div>

      <!--
        Phrased as the good news it usually is. Customers with nothing outstanding are excluded by
        the report, so an empty result means everyone has paid rather than that anything is missing.
      -->
      <p v-else-if="rows.length === 0" class="py-12 text-center text-sm text-content-muted">
        No customer has an outstanding balance.
      </p>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <caption class="sr-only">
            Outstanding receivables as at {{ meta?.as_of }}, in {{ meta?.currency }}
          </caption>
          <thead>
            <tr class="border-b border-surface-border text-left text-xs uppercase tracking-wide text-content-subtle">
              <th scope="col" class="py-2 pr-4">Code</th>
              <th scope="col" class="py-2 pr-4">Customer</th>
              <th scope="col" class="py-2 pr-4 text-right">Invoices</th>
              <th scope="col" class="py-2 text-right">Outstanding</th>
            </tr>
          </thead>

          <tbody>
            <tr v-for="row in rows" :key="row.customer_id" class="border-b border-surface-border/60">
              <td class="py-2 pr-4 font-mono text-xs text-content">{{ row.code }}</td>
              <td class="py-2 pr-4 text-content">{{ row.name }}</td>
              <td class="py-2 pr-4 text-right tabular-nums text-content-muted">{{ row.invoice_count }}</td>
              <td class="py-2 text-right font-mono tabular-nums text-content">
                {{ formatPlain(row.outstanding) }}
              </td>
            </tr>
          </tbody>

          <tfoot v-if="meta">
            <tr class="border-t-2 border-surface-border font-semibold">
              <td class="py-2 pr-4" colspan="3">Total outstanding</td>
              <!-- Server-computed. Not a sum of the column above — see the module docblock. -->
              <td class="py-2 text-right font-mono tabular-nums text-content">
                {{ formatPlain(meta.totals.outstanding) }}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </SurfaceCard>
  </div>
</template>
