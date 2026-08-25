import { onBeforeUnmount, onMounted } from 'vue'

/**
 * The mid-edit confirm-and-discard registry (ADR 0013 §6, Gate-1 decision #6).
 *
 * A company switch is not a route change — `App.vue` never re-mounts the page — so a
 * `router.beforeEach` / `onBeforeRouteLeave` guard would never fire for it. The only choke
 * point that can still abort a switch before it commits server-side is
 * `CompanySwitcher.vue`'s `select()`, which is not, and must not become, coupled to any
 * specific editor. A tiny module-level registry is the seam: an editor page registers "do I
 * have unsaved changes right now" for as long as it is mounted, and the switcher asks the
 * registry rather than any one page.
 *
 * Read-only list/detail pages have nothing to lose and only need `useCompanyReload` — they do
 * not call this.
 */
const guards = new Set<() => boolean>()

/** Called by an editor page (`CustomerFormPage`, `SalesInvoiceEditorPage`) while it is mounted. */
export function useUnsavedGuard(isDirty: () => boolean): void {
  onMounted(() => guards.add(isDirty))
  onBeforeUnmount(() => guards.delete(isDirty))
}

/** Called by `CompanySwitcher.select()` before it commits a switch. */
export function hasUnsavedChanges(): boolean {
  return [...guards].some((guard) => guard())
}
