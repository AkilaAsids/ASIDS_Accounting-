<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import BaseButton from '@/components/ui/BaseButton.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import { useAuthStore } from '@/stores/auth'
import { useMoney } from '@/composables/useMoney'
import { useUiStore } from '@/stores/ui'
import type { AgedReceivableMeta, AgedReceivableRow } from '@/types/domain'

/**
 * The debtor book by age.
 *
 * Nothing on this page is calculated here. Every bucket is the server's, and so is every column
 * total — `meta.totals`, rendered as given. Eight columns of money summed in JavaScript would drift
 * from the ledger by a few cents and put two different figures in front of the same accountant.
 *
 * THE CUTOFF IS NEVER THE BROWSER'S IDEA OF TODAY
 * -----------------------------------------------
 * `as_of` is sent only when the user has actually chosen a date; left blank it is omitted entirely
 * and the **server** picks today and says which day it used. That matters more here than on most
 * screens: a report aged against the client's clock could not be reproduced from the response it
 * produced, and two people in different timezones would age the same book differently. After every
 * successful request the input is repopulated from `meta.as_of`, so the control always shows the
 * cutoff the figures were actually aged against rather than what someone typed.
 *
 * Ageing runs from the **due date**, which the header states, because a reader who assumes invoice
 * date will misread every column — for thirty-day terms, by a month.
 */
const auth = useAuthStore()
const ui = useUiStore()
const { formatPlain } = useMoney()

const rows = ref<AgedReceivableRow[]>([])
const meta = ref<AgedReceivableMeta | null>(null)
const loading = ref(true)
const asOf = ref('')

const companyId = computed<string | null>(() => auth.activeCompany?.id ?? null)

/** The bucket columns, so the header, every row and the footer cannot drift apart. */
const buckets = [
  { key: 'not_yet_due', label: 'Not yet due' },
  { key: 'days_0_30', label: '0–30' },
  { key: 'days_31_60', label: '31–60' },
  { key: 'days_61_90', label: '61–90' },
  { key: 'days_over_90', label: '90+' },
] as const

onMounted(load)

/**
 * Reload when the active company changes.
 *
 * Switching company refreshes the session in place — `App.vue` keys its `RouterView` on the route
 * path, not the company — so a page already on screen is never re-mounted. Without this the table
 * would keep the previous company's debtors while the heading and currency had moved to the new one.
 *
 * The chosen cutoff is deliberately kept across the switch: "the same date, the other company" is
 * what someone comparing two sets of books is asking for.
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
    const response = await api.get<AgedReceivableRow[]>(
      `/companies/${companyId.value}/reports/aged-receivables`,
      // Omitted when blank so the server applies its own default. Sending an empty string would be
      // refused as not-a-date rather than read as "you choose".
      { as_of: asOf.value === '' ? undefined : asOf.value },
    )

    rows.value = response.data
    meta.value = response.meta as unknown as AgedReceivableMeta

    // Populated from the response so the control shows the cutoff actually used, including when the
    // server chose it.
    asOf.value = meta.value.as_of
  } catch (thrown) {
    // Both cleared, and `meta` is what the template keys the empty state on: a failed request must
    // not leave stale figures on screen, and must not be reported as "nothing is outstanding"
    // either. A reassuring sentence over a failure is worse than no sentence.
    rows.value = []
    meta.value = null

    ui.notify(
      'error',
      thrown instanceof ApiError ? thrown.problem.detail : 'Could not load aged receivables.',
    )
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-content">Aged receivables</h1>
      <p class="mt-1 text-sm text-content-muted">
        What is owed, by how overdue it is. Aged from each invoice's due date, not its invoice date.
        <template v-if="meta">
          Amounts in {{ meta.currency }}.
        </template>
      </p>
    </header>

    <SurfaceCard>
      <form class="flex flex-wrap items-end gap-3" @submit.prevent="load">
        <div>
          <label for="aged-as-of" class="field-label">As at</label>
          <input
            id="aged-as-of"
            v-model="asOf"
            type="date"
            class="form-input mt-1 rounded-md border-surface-border bg-surface-raised text-sm text-content focus:border-primary-500 focus:ring-primary-500"
          />
        </div>
        <BaseButton type="submit" :loading="loading">Run</BaseButton>
      </form>
    </SurfaceCard>

    <SurfaceCard>
      <!--
        Stated above the table as well as inside the caption. A printed or screenshotted ageing with
        no date on it cannot be reconciled to a later run, and "as at" is the first thing anyone
        checking one asks.
      -->
      <p v-if="meta" class="mb-3 text-sm font-medium text-content">Aged as at {{ meta.as_of }}</p>

      <div v-if="loading" class="py-12 text-center text-sm text-content-muted">Loading…</div>

      <!--
        Keyed on `meta` rather than on the row count alone, so this only ever speaks for a request
        that actually succeeded. After a refusal the notice carries the reason and this says nothing.
      -->
      <p
        v-else-if="meta && rows.length === 0"
        class="py-12 text-center text-sm text-content-muted"
      >
        Nothing is outstanding as at {{ meta.as_of }}.
      </p>

      <!--
        Focusable, so the scroll is reachable without a mouse. This is the widest table in the
        application — eight columns, guaranteed to overflow on a phone — and a plain `overflow-x-auto`
        div contains no tab stop, leaving a keyboard-only user unable to reach the 90+ and Total
        columns at all. `role="region"` with a name makes the tab stop explicable to a screen reader.
      -->
      <div
        v-else-if="meta"
        class="overflow-x-auto"
        role="region"
        aria-label="Aged receivables"
        tabindex="0"
      >
        <table class="min-w-full text-sm">
          <caption class="sr-only">
            Aged receivables as at {{ meta.as_of }}, in {{ meta.currency }}, aged from each invoice's
            due date
          </caption>
          <thead>
            <tr class="border-b border-surface-border text-left text-xs uppercase tracking-wide text-content-subtle">
              <th scope="col" class="py-2 pr-4">Code</th>
              <th scope="col" class="py-2 pr-4">Customer</th>
              <th v-for="bucket in buckets" :key="bucket.key" scope="col" class="py-2 pr-4 text-right">
                {{ bucket.label }}
              </th>
              <th scope="col" class="py-2 text-right">Total</th>
            </tr>
          </thead>

          <tbody>
            <tr v-for="row in rows" :key="row.customer_id" class="border-b border-surface-border/60">
              <td class="py-2 pr-4 font-mono text-xs text-content">{{ row.code }}</td>
              <td class="py-2 pr-4 text-content">{{ row.name }}</td>
              <td
                v-for="bucket in buckets"
                :key="bucket.key"
                class="py-2 pr-4 text-right font-mono tabular-nums text-content"
              >
                {{ formatPlain(row[bucket.key]) }}
              </td>
              <td class="py-2 text-right font-mono tabular-nums font-medium text-content">
                {{ formatPlain(row.total) }}
              </td>
            </tr>
          </tbody>

          <tfoot>
            <tr class="border-t-2 border-surface-border font-semibold">
              <td class="py-2 pr-4" colspan="2">Total</td>
              <!-- Server-computed. Not sums of the columns above — see the module docblock. -->
              <td
                v-for="bucket in buckets"
                :key="bucket.key"
                class="py-2 pr-4 text-right font-mono tabular-nums text-content"
              >
                {{ formatPlain(meta.totals[bucket.key]) }}
              </td>
              <td class="py-2 text-right font-mono tabular-nums text-content">
                {{ formatPlain(meta.totals.total) }}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </SurfaceCard>
  </div>
</template>
