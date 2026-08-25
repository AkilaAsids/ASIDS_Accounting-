import { computed, onMounted, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Reloads a page when the active company changes.
 *
 * `App.vue` keys its `RouterView` on the route path, not the company, so switching company
 * never re-mounts a page already on screen (ADR 0011 D3). Every company-scoped screen in this
 * wave must reload for that reason, and "must" with nothing enforcing it is exactly how a
 * forgotten watch fails — silently, showing one company's data under another's name, never in
 * a test. This composable is the one-call opt-in: a page calls `useCompanyReload(load)` once
 * and gets the exact `OutstandingReceivablesPage.vue` pattern (module docblock + non-immediate
 * `watch`) for free.
 *
 * `onMounted` owns the first request; the watch is deliberately **not** `immediate`, so a
 * fresh page makes exactly one request rather than two.
 */
export function useCompanyReload(load: () => void | Promise<void>) {
  const auth = useAuthStore()
  const companyId = computed<string | null>(() => auth.activeCompany?.id ?? null)

  onMounted(() => void load())

  watch(companyId, (id, previous) => {
    if (id !== previous) {
      void load()
    }
  })

  return { companyId }
}
