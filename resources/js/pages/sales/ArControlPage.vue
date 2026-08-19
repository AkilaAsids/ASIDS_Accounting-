<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import AlertBanner from '@/components/ui/AlertBanner.vue'
import SurfaceCard from '@/components/ui/SurfaceCard.vue'
import { useAuthStore } from '@/stores/auth'
import { useMoney } from '@/composables/useMoney'
import { useUiStore } from '@/stores/ui'
import type { ArControlMeta, ArControlRow } from '@/types/domain'

/**
 * Whether the receivables subledger agrees with the general ledger.
 *
 * This page reports a verdict it does not reach. Every figure — each side, the difference, and
 * whether an account reconciles — arrives decided, and the one thing this screen must never do is
 * work any of it out again. Recomputing a difference from the two columns would be a second
 * implementation of the comparison the whole report exists to make.
 *
 * WHAT MUST NOT BE HIDDEN
 * -----------------------
 * The difference is rendered exactly as it arrives, sign and all. No `Math.abs`, because the sign is
 * what says which side is short; and no blanking of a zero, because on this report a zero is the
 * meaningful answer rather than an empty cell. Blanking zeroes is right on a trial balance, where an
 * accountant scans for the side carrying a figure, and wrong here.
 *
 * `meta.totals.reconciles` is the page-level verdict and is taken as given. It is **not** inferred
 * from `meta.totals.difference`: two opposite errors of equal size cancel in the total while both
 * accounts are wrong, so a page reading the total would call that reconciled. Every row states its
 * own status in words as well, so the state never depends on colour.
 *
 * NO DATE CONTROL
 * ---------------
 * Deliberate, not missing. The subledger side reads current status and current amounts with no
 * history to reconstruct either from, so a past cutoff would have the two halves answering different
 * questions. `meta.as_of` is the day the server produced the report and is shown as text; the
 * browser's clock is never consulted.
 */
const auth = useAuthStore()
const ui = useUiStore()
const { formatPlain } = useMoney()

const rows = ref<ArControlRow[]>([])
const meta = ref<ArControlMeta | null>(null)
const loading = ref(true)

const companyId = computed<string | null>(() => auth.activeCompany?.id ?? null)

onMounted(load)

/**
 * Reload when the active company changes.
 *
 * Switching company refreshes the session in place — `App.vue` keys its `RouterView` on the route
 * path, not the company — so a page already on screen is never re-mounted. Without this the table
 * would keep the previous company's accounts while the heading and currency had moved to the new
 * one, and a reconciliation attributed to the wrong set of books is worse than none.
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
    // No query parameters. The endpoint accepts none, and sending an `as_of` would imply a
    // historical capability the reconciliation does not have.
    const response = await api.get<ArControlRow[]>(
      `/companies/${companyId.value}/reports/ar-control`,
    )

    rows.value = response.data
    meta.value = response.meta as unknown as ArControlMeta
  } catch (thrown) {
    // `meta` is what the template keys both the empty state and the verdict on, so clearing it is
    // what stops a failed request from being reported as a clean reconciliation.
    rows.value = []
    meta.value = null

    ui.notify(
      'error',
      thrown instanceof ApiError ? thrown.problem.detail : 'Could not load the AR control report.',
    )
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-content">AR control</h1>
      <p class="mt-1 text-sm text-content-muted">
        Compares the receivables subledger — what the invoices say is owed — against the general
        ledger, one receivable account at a time.
        <template v-if="meta">
          Amounts in {{ meta.currency }}.
        </template>
      </p>
    </header>

    <!--
      Loud by design. A reconciliation difference means something reached a receivable account
      without going through an invoice, and a report that renders that as an ordinary row is worse
      than no report. `AlertBanner` carries `role="alert"` and pairs its colour with an icon, so the
      warning does not depend on hue.
    -->
    <AlertBanner
      v-if="meta && !meta.totals.reconciles"
      kind="error"
      title="Receivables do not reconcile"
    >
      The invoice subledger and the general ledger disagree for at least one receivable account. The
      affected accounts are marked below. Investigate before relying on any receivables figure.
    </AlertBanner>

    <SurfaceCard>
      <!--
        Stated rather than offered as a control. There is no as-at parameter to change, and a date
        input here would promise a history the report cannot produce.
      -->
      <p v-if="meta" class="mb-3 text-sm font-medium text-content">As produced on {{ meta.as_of }}</p>

      <div v-if="loading" class="py-12 text-center text-sm text-content-muted">Loading…</div>

      <!-- Keyed on `meta`, so this only ever speaks for a request that actually succeeded. -->
      <p v-else-if="meta && rows.length === 0" class="py-12 text-center text-sm text-content-muted">
        This company has no receivable account activity yet.
      </p>

      <!--
        Focusable, so the scroll is reachable without a mouse. The rightmost column carries the
        per-account verdict, so a keyboard-only user who cannot scroll cannot read which account
        failed to reconcile — the one thing this report exists to tell them.
      -->
      <div
        v-else-if="meta"
        class="overflow-x-auto"
        role="region"
        aria-label="AR control reconciliation"
        tabindex="0"
      >
        <table class="min-w-full text-sm">
          <caption class="sr-only">
            AR control reconciliation as produced on {{ meta.as_of }}, in {{ meta.currency }},
            comparing the invoice subledger against the general ledger per receivable account
          </caption>
          <thead>
            <tr class="border-b border-surface-border text-left text-xs uppercase tracking-wide text-content-subtle">
              <th scope="col" class="py-2 pr-4">Account</th>
              <th scope="col" class="py-2 pr-4 text-right">Subledger</th>
              <th scope="col" class="py-2 pr-4 text-right">General ledger</th>
              <th scope="col" class="py-2 pr-4 text-right">Difference</th>
              <th scope="col" class="py-2">Reconciles</th>
            </tr>
          </thead>

          <tbody>
            <tr v-for="row in rows" :key="row.account_id" class="border-b border-surface-border/60">
              <td class="py-2 pr-4">
                <span class="font-mono text-xs text-content">{{ row.code }}</span>
                <span class="ml-2 text-content">{{ row.name }}</span>
              </td>
              <td class="py-2 pr-4 text-right font-mono tabular-nums text-content">
                {{ formatPlain(row.subledger) }}
              </td>
              <td class="py-2 pr-4 text-right font-mono tabular-nums text-content">
                {{ formatPlain(row.general_ledger) }}
              </td>
              <!--
                Rendered exactly as it arrives. A zero is not blanked and a negative keeps its sign:
                both are the answer, not the absence of one.
              -->
              <td
                class="py-2 pr-4 text-right font-mono tabular-nums"
                :class="row.reconciles ? 'text-content' : 'font-medium text-danger'"
              >
                {{ formatPlain(row.difference) }}
              </td>
              <!-- The state is in the words. Colour only reinforces it. -->
              <td class="py-2 text-xs" :class="row.reconciles ? 'text-content-muted' : 'font-medium text-danger'">
                {{ row.reconciles ? 'Reconciles' : 'Does not reconcile' }}
              </td>
            </tr>
          </tbody>

          <tfoot>
            <tr class="border-t-2 border-surface-border font-semibold">
              <td class="py-2 pr-4">Total</td>
              <!-- Server-computed. Not sums of the columns above — see the module docblock. -->
              <td class="py-2 pr-4 text-right font-mono tabular-nums text-content">
                {{ formatPlain(meta.totals.subledger) }}
              </td>
              <td class="py-2 pr-4 text-right font-mono tabular-nums text-content">
                {{ formatPlain(meta.totals.general_ledger) }}
              </td>
              <td
                class="py-2 pr-4 text-right font-mono tabular-nums"
                :class="meta.totals.reconciles ? 'text-content' : 'text-danger'"
              >
                {{ formatPlain(meta.totals.difference) }}
              </td>
              <!--
                Taken from the server's verdict, never derived from the total above it. Two opposing
                errors cancel in that figure while both accounts are wrong.
              -->
              <td class="py-2 text-xs font-normal" :class="meta.totals.reconciles ? 'text-success' : 'text-danger'">
                {{ meta.totals.reconciles ? 'All accounts reconcile' : 'Does not reconcile' }}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </SurfaceCard>
  </div>
</template>
