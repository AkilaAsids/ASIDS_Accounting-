# ADR 0013 — The Phase 3 front end: customer and invoice screens, and the two-lane build that produces them

- **Status:** Accepted
- **Date:** 2026-08-24
- **Supersedes / extends:** nothing. Builds on [ADR 0011](0011-receivables-reporting-frontend-and-http-surface.md)
  (front-end conventions and gotchas) and [ADR 0012](0012-sales-invoice-http-surface.md) (the invoice HTTP
  surface and its `capabilities` contract).

## Context

Milestone 8 built the receivables reporting front end and stopped there; ADR 0011 D1 recorded customer and
invoice screens as outstanding. Milestone 9 added the invoice HTTP surface (ADR 0012) but no UI. Both APIs are
now complete and tested — 54 tests for customers, 63 for invoices — and documented in `docs/api/openapi.yaml`.
This wave is a front-end-only wave over those two existing REST surfaces: no migration, no new endpoint, no
service/policy/permission change.

The requirements are `docs/PHASE-3-FRONTEND-REQUIREMENTS.md`, whose §9 records eight binding Gate-1 decisions.
This ADR honours them exactly and turns them into concrete files, contracts and a build plan. It exists for two
audiences at once: the two front-end engineers who will build the Customer and Invoice lanes **in parallel**,
and QA, who writes red Vitest specs first.

The single most important thing this ADR does — beyond specifying the screens — is **partition every file so
the two lanes never edit the same one.** Merge conflicts between the lanes are made impossible by construction
(§8), not managed after the fact.

Everything below is grounded in files that exist today. The canonical page pattern is
`resources/js/pages/sales/OutstandingReceivablesPage.vue` and its spec; the canonical CRUD page with a create
form, `capabilities`-gated row actions and `window.confirm` is `resources/js/pages/accounting/ChartOfAccountsPage.vue`;
the canonical list with debounced search, a status filter and an inline pagination footer is
`resources/js/pages/users/UsersPage.vue`. The HTTP client is `resources/js/api/client.ts` (`api`, `ApiError`);
money is `resources/js/composables/useMoney.ts`; permission-only gating is
`resources/js/components/app/PermissionGate.vue`.

---

## Decision

### D0 — Naming, routing shape and the "pages call `api` directly" convention are kept

Every existing page imports `api`/`ApiError` from `@/api/client` and calls it directly inside an `async load()`
— there is no per-resource service layer, no data store for domain lists, and no generic table/CRUD component
(ADR 0011 D8 deliberately declined one). This wave **keeps that shape** rather than introducing an abstraction
the codebase has twice chosen not to have. What it adds is narrow and justified per item below.

Two departures from the "everything inline in one page file" habit are taken deliberately, because these
screens are genuinely larger than a chart-of-accounts row:

1. **Typed API modules per resource** (D2) — thin, named wrappers over `api`, so the two lanes do not each
   hand-roll URL strings and the clear-vs-omit payload discipline lives in one testable place.
2. **A small number of dedicated route-level pages plus lane-local components** (D1) — a list page, an
   editor/form page and a detail page per resource, rather than one mega-page with modals. This keeps each
   file small enough for one engineer to own and for QA to spec in isolation, and it lets the router gate each
   screen on its own permission (matching how every route in `router/index.ts` already carries a `permission`
   meta).

---

### 1. Component and page inventory

All new pages live under `resources/js/pages/sales/`, matching the existing sales pages. Lane-local components
live under `resources/js/components/sales/customers/` and `resources/js/components/sales/invoices/` — **separate
directories, one per lane**, which is the mechanism that keeps component files from colliding (§8). Reused
primitives come from `resources/js/components/ui/` and `resources/js/components/app/`.

#### Customer lane — pages

| File (new) | Route | Endpoint(s) | Requirement |
| --- | --- | --- | --- |
| `pages/sales/CustomersListPage.vue` | `sales/customers` | `listCustomers` | §4.1 |
| `pages/sales/CustomerFormPage.vue` | `sales/customers/new` + `sales/customers/:customerId/edit` | `createCustomer`, `updateCustomer`, `getCustomer` | §4.2, §4.3 |
| `pages/sales/CustomerDetailPage.vue` | `sales/customers/:customerId` | `getCustomer` + lifecycle sub-routes | §4.4, §4.5 |

#### Customer lane — components

| File (new) | Purpose |
| --- | --- |
| `components/sales/customers/CustomerForm.vue` | The create/edit form body. Owns the clear-vs-omit payload construction (§4.3.1–4.3.2) so both create and edit share exactly one implementation. Emits a normalised payload; never calls `api` itself. |
| `components/sales/customers/CustomerLifecycleMenu.vue` | Overflow ("⋯") menu rendering archive / restore / deactivate / reactivate / **delete**, each shown only per the customer's `capabilities`/state, each confirmed before firing. Hard-delete uses the shared `ConfirmDialog` typed-confirm (decision #2). |
| `components/sales/customers/CustomerStatusBadge.vue` | Renders `status_label` as text-plus-colour (never colour alone, §4.4.2). |

#### Invoice lane — pages

| File (new) | Route | Endpoint(s) | Requirement |
| --- | --- | --- | --- |
| `pages/sales/SalesInvoicesListPage.vue` | `sales/invoices` | `listSalesInvoices` | §4.6 |
| `pages/sales/SalesInvoiceEditorPage.vue` | `sales/invoices/new` + `sales/invoices/:invoiceId/edit` | `createSalesInvoice`, `updateSalesInvoice`, `getSalesInvoice` | §4.7, §4.8 |
| `pages/sales/SalesInvoiceDetailPage.vue` | `sales/invoices/:invoiceId` | `getSalesInvoice` + `issue`/`cancel`/`delete` | §4.9–4.12 |

#### Invoice lane — components

| File (new) | Purpose |
| --- | --- |
| `components/sales/invoices/InvoiceLineEditor.vue` | The highest-risk component (§7). Owns the dynamic line list, add/remove, per-line discount mutual-exclusion, and per-line 422 mapping. Renders no money totals of its own. |
| `components/sales/invoices/InvoiceLineRow.vue` | One editable line: description, quantity, unit_price, revenue-account picker, `TaxCodePicker`, discount type toggle, and per-field error slots keyed on `lines.<i>.<field>`. |
| `components/sales/invoices/TaxCodePicker.vue` | Read-only picker sourced from `listTaxCodes(active_only=true)` (decision #7). Emits the tax **code** string, never an id (§4.7.3). |
| `components/sales/invoices/InvoiceTotals.vue` | Renders `subtotal`/`discount_total`/`tax_total`/`total`/`amount_paid`/`amount_due` exactly as the API returned them, with the subtle "totals finalise on save" hint (decision #5). Takes strings, formats with `useMoney`, adds nothing. |
| `components/sales/invoices/InvoiceActionsMenu.vue` | Issue / cancel / delete controls, each gated on `capabilities.can_issue`/`can_cancel`/`can_delete`, each confirmed. |
| `components/sales/invoices/CancelInvoiceDialog.vue` | Cancel dialog with the **required** 3–255 char reason (§4.11.1); submit disabled until valid. |
| `components/sales/invoices/InvoiceStatusBadge.vue` | Renders `status_label` (draft/issued/…) and `is_overdue`/`cancelled` in words, not colour alone (§4.9.2). |

#### Reused primitives (both lanes, unchanged)

`components/ui/`: `BaseButton.vue`, `TextField.vue`, `SurfaceCard.vue` (with its `#footer`/`#actions` slots),
`AlertBanner.vue`. `components/app/PermissionGate.vue` for permission-only hiding (e.g. "Add a customer",
"issue immediately"). `composables/useMoney.ts` (`format`, `formatPlain`, `isZero`, `isNegative`).
`stores/ui.ts` (`ui.notify`). `stores/auth.ts` (`auth.activeCompany`, `auth.can`).

#### New shared primitives (built in the pre-step, §8)

`components/ui/Pagination.vue` (§5), `components/ui/ConfirmDialog.vue` (typed-confirm for hard delete, decision
#2), and `composables/useCompanyReload.ts` + `composables/useUnsavedGuard.ts` (§6).

---

### 2. API client layer

Two new typed modules under `resources/js/api/`, each a thin wrapper over the existing `api` singleton. They add
no error handling of their own — every call still rejects with `ApiError` and pages handle it exactly as
`ChartOfAccountsPage.create()` does today. Their value is (a) one place per resource that knows the URL shapes,
(b) the return types, and (c) the **clear-vs-omit payload builders**, which are the acceptance-critical logic
(§4.3, §4.8) and belong in a unit-testable function rather than scattered in a component.

#### `resources/js/api/customers.ts` (Customer lane owns)

```
listCustomers(companyId, params: CustomerListParams): Promise<ApiEnvelope<Customer[]>>
getCustomer(companyId, customerId): Promise<ApiEnvelope<Customer>>
createCustomer(companyId, body: CustomerCreatePayload): Promise<ApiEnvelope<Customer>>
updateCustomer(companyId, customerId, body: CustomerUpdatePayload): Promise<ApiEnvelope<Customer>>
archiveCustomer / restoreCustomer / deactivateCustomer / reactivateCustomer(companyId, customerId): Promise<...>
deleteCustomer(companyId, customerId): Promise<void>
```

- `CustomerListParams` maps to the documented query params: `page`, `per_page`, `q`, `filter[status]`,
  `filter[branch_id]`, `sort`. Passed through `api.get`'s existing `ListParams` shape (`filter` is a nested
  record — see `UsersPage.load`).
- `credit_limit` is always a string or `null`, never a number (§4.2.2).
- `status` is **not a field** on either payload type — it is structurally impossible to send (§4.2.7).

#### `resources/js/api/salesInvoices.ts` (Invoice lane owns)

```
listSalesInvoices(companyId, params: InvoiceListParams): Promise<ApiEnvelope<SalesInvoice[]>>
getSalesInvoice(companyId, invoiceId): Promise<ApiEnvelope<SalesInvoice>>   // returns lines
createSalesInvoice(companyId, body: SalesInvoiceInput): Promise<ApiEnvelope<SalesInvoice>>
updateSalesInvoice(companyId, invoiceId, body: SalesInvoiceInput): Promise<ApiEnvelope<SalesInvoice>>
deleteSalesInvoice(companyId, invoiceId): Promise<void>
issueSalesInvoice(companyId, invoiceId): Promise<ApiEnvelope<SalesInvoice>>
cancelSalesInvoice(companyId, invoiceId, reason: string): Promise<ApiEnvelope<SalesInvoice>>
```

- `InvoiceListParams`: `page`, `per_page`, `q`, `filter[status]`, `filter[customer_id]`, `filter[branch_id]`,
  `sort`. The list **never** sends `include=lines` (§4.6.5); the detail `getSalesInvoice` always receives lines
  from the server regardless.
- Every amount field on `SalesInvoiceInput` is typed `string | null`, never `number` (§4.7.2). `tax_code` is
  `string | null` (§4.7.3).

#### `resources/js/api/taxCodes.ts` (Invoice lane owns — read-only)

```
listTaxCodes(companyId, activeOnly = true): Promise<ApiEnvelope<TaxCode[]>>
```

Read-only, for the `TaxCodePicker` only. No create/update/delete wrappers exist, so the deferred tax-code CRUD
scope cannot be reached from this layer (§Scope out-of-scope, decision #7).

**Error convention (all modules).** No wrapper catches. Callers `try/catch` and branch on `thrown instanceof
ApiError`, reading `thrown.fieldErrors` (per-field), `thrown.problem.detail` (notice text) and `thrown.code`
(stable `type` suffix) — identical to `ChartOfAccountsPage` and `UsersPage`. 401/419/428 remain the client's
job (`client.ts`), untouched.

---

### 3. State/store strategy

**No new Pinia store.** ADR 0011 D8 and requirements §5.6 both weigh this; a CRUD list holds exactly the same
kind of transient, page-scoped state a report page holds, and `auth`/`ui` already cover the cross-page state
(active company, permissions, notices). Introducing a store would be the third pattern for the same job.

Each page holds its own `ref`s in `<script setup>`, mirroring `UsersPage`:

- **List pages** hold: `rows` (`Customer[]` / `SalesInvoice[]`), `meta` (`ApiMeta`, for `meta.pagination`),
  `loading`, plus filter/search/page state — `search` (`q`), `statusFilter`, `customerFilter` (invoice only),
  and the current page number. The current page lives in a `page` ref (source of truth) and is echoed by
  `meta.pagination.current_page`; `load(page)` takes it as an argument exactly as `UsersPage.load` does.
  Search is debounced 300 ms (`UsersPage` pattern, §4.1.2); changing a filter or search **resets to page 1**.
- **Editor pages** (`CustomerFormPage`, `SalesInvoiceEditorPage`) hold a local `form` object, a
  `fieldErrors` record, a `busy` flag, and — for the invoice — a `lines` array. They also hold an original
  snapshot for the clear-vs-omit diff (customer edit, §4.3.1) and an `isDirty` computed for the unsaved-guard
  (§6).
- **Detail pages** hold the fetched resource, `loading`, and an action-in-flight flag.

**Empty/error discipline (both list pages, §4.1.5 / §4.6.6, ADR 0011 D4).** The empty state is keyed on a
**successful response having landed** (`meta` present and `rows.length === 0`), never on `rows.length === 0`
alone. A failed request clears both `rows` and `meta` and surfaces `ui.notify('error', …)`. This is the exact
shape proven by `OutstandingReceivablesPage.spec.ts` ("surfaces a refusal … and renders no figures").

---

### 4. Router and permission gating

New route records added to `resources/js/router/index.ts`, inside the `AppLayout` children block, grouped after
the existing `sales/*` report routes. **All seven records are added in the shared pre-step (§8)** so neither
lane edits the router file.

| Route name | Path | `meta.permission` |
| --- | --- | --- |
| `customers` | `sales/customers` | `sales.customers.view` |
| `customer-new` | `sales/customers/new` | `sales.customers.manage` |
| `customer-detail` | `sales/customers/:customerId` | `sales.customers.view` |
| `customer-edit` | `sales/customers/:customerId/edit` | `sales.customers.manage` |
| `invoices` | `sales/invoices` | `sales.invoices.view` |
| `invoice-new` | `sales/invoices/new` | `sales.invoices.draft` |
| `invoice-detail` | `sales/invoices/:invoiceId` | `sales.invoices.view` |
| `invoice-edit` | `sales/invoices/:invoiceId/edit` | `sales.invoices.draft` |

The route guard's `meta.permission` (owner short-circuited, as on the server) keeps a user off a screen they
could not use — the same presentation-only gate as every existing route. Editor routes gate on `.draft`/`.manage`
so a viewer never reaches a form.

**The route guard is necessary but not sufficient.** Per requirements §3 and Gate-1 decision #2, every
**destructive or state-dependent action button** gates on the resource's own `capabilities` block, which folds
permission **and** current state together, because the route/`PermissionGate` permission check alone is wrong
for an owner (ADR 0012 D4: `Gate::before` grants an owner every ability, so a raw permission check reports they
may issue an already-issued invoice). The rule, applied uniformly:

- **`PermissionGate` (permission only)** is correct for *creating* things and *entering* a state-independent
  flow: "Add a customer" (`sales.customers.manage`), "New invoice" (`sales.invoices.draft`), the "issue
  immediately" checkbox on create (`sales.invoices.issue`, §4.7.8).
- **`capabilities.can_*` (permission AND state)** is the **only** correct gate for: customer edit
  (`can_update`), archive/restore/deactivate/reactivate/delete (per the customer's `capabilities` and status),
  invoice edit/delete (`can_update`/`can_delete`), issue (`can_issue`), cancel (`can_cancel`). These are read
  off the resource returned by the API — never inferred from a `status` string in the template.
- **Both** apply where an action is both permission-guarded at the section level and state-guarded per row.

`CustomerResource.capabilities` ships `can_update`, `can_delete`, `accepts_new_invoices` (confirmed from
`openapi.yaml` — see §Appendix). It does **not** ship a distinct flag per lifecycle verb (no
`can_archive`/`can_restore`); the UI therefore drives archive vs restore vs deactivate vs reactivate from
`status` **for display selection** while gating the *availability* of the whole action set on `can_update`
(manage held) and surfacing the server's 422/409 refusals as notices for the state rules the resource does not
pre-encode (§4.5). This is called out because it is the one place the "never read status in the template"
principle is necessarily softened — and it is softened only to *choose which verb to show*, never to decide
*whether the user is allowed*.

---

### 5. Shared `Pagination` component (Gate-1 decision #1)

One component, `resources/js/components/ui/Pagination.vue`, built once in the pre-step and consumed by both
list pages. This is the first pagination control in the codebase (ADR 0011 D8; ADR 0012 follow-ups). It
generalises the inline footer `UsersPage.vue` already hand-rolls (lines 390–414), so the shape is proven.

**Props**

```ts
defineProps<{
  pagination: Pagination   // the meta.pagination object from @/types/api
  disabled?: boolean       // true while a request is in flight
}>()
```

**Events**

```ts
defineEmits<{ 'update:page': [page: number] }>()
```

**Behaviour**

- Renders nothing when `pagination.last_page <= 1` (the list simply omits it, as `UsersPage` does today via
  `v-if`; the component also self-guards so a consumer cannot render an empty bar).
- Shows "`from`–`to` of `total`" (§4.1.4). `from`/`to` are nullable (`Pagination` type) — rendered as `0` when
  null so an empty page never prints "null–null".
- Prev/Next buttons (`BaseButton variant="secondary" size="sm"`), each `:disabled` at the ends or while
  `disabled`. **Numbered pages are out of scope** for this wave: prev/next matches the existing footer and the
  Gate-1 decision that declined to over-build; page-size selection and numbered pages are a clean fast-follow.
- Wrapped for a11y in a `<nav aria-label="Pagination">`; the count text is announced.
- Consumers pass `:pagination="meta.pagination"` and handle `@update:page="load"`. The page is a **server
  parameter**; the component owns none of the fetching.

`per_page` is not chosen by the UI — the server's default is accepted (no page-size selector this wave),
keeping both lists identical and side-stepping the "two lists disagree on page-size" risk ADR 0011/§8 warns of.

---

### 6. Company-switch reload (Gate-1 decision #6, ADR 0011 D3)

Every company-scoped page in this wave **must** reload when the active company changes, because `App.vue` keys
its `RouterView` on the route path, not the company, so a switch never re-mounts a page already on screen
(ADR 0011 D3). Nothing enforces this — a forgotten watch fails silently, showing one company's data under
another's name — so the pattern is extracted into one composable that every page uses, and QA asserts it per
page (§9). Two composables, both built in the pre-step:

#### `resources/js/composables/useCompanyReload.ts`

Encapsulates the exact `OutstandingReceivablesPage` pattern (module docblock + `watch`, non-immediate):

```ts
export function useCompanyReload(load: () => void | Promise<void>) {
  const auth = useAuthStore()
  const companyId = computed(() => auth.activeCompany?.id ?? null)
  onMounted(() => void load())
  watch(companyId, (id, prev) => { if (id !== prev) void load() })   // NOT immediate
  return { companyId }
}
```

- List and detail pages call `useCompanyReload(load)` and use the returned `companyId` for their URL building
  and the `companyId === null` guard (a user with no active company must not build `/companies/null/...`, per
  `OutstandingReceivablesPage.load`).
- `onMounted` owns the first request; the watch is **not** `immediate`, so a fresh page makes exactly one
  request. This is the single behaviour QA's "reloads once for the new company" spec pins.

#### `resources/js/composables/useUnsavedGuard.ts` — the mid-edit confirm-and-discard flow

Gate-1 decision #6 requires **confirm-and-discard** for an editor open when the company switches ("switching
company discards unsaved changes"), then a reload. The only choke point that can *abort* a switch before it
commits server-side is `CompanySwitcher.select()` (`components/app/CompanySwitcher.vue`), because the switch
posts to the server and re-fetches the session — once that has happened the company has already changed. So the
guard lives at the switch site, backed by a tiny module-level registry:

```ts
// module-scoped
const guards = new Set<() => boolean>()   // each returns true if it has unsaved changes
export function useUnsavedGuard(isDirty: () => boolean) {
  onMounted(() => guards.add(isDirty))
  onBeforeUnmount(() => guards.delete(isDirty))
}
export function hasUnsavedChanges(): boolean { return [...guards].some((g) => g()) }
```

- **Editor pages only** (`CustomerFormPage`, `SalesInvoiceEditorPage`) call `useUnsavedGuard(() => isDirty.value)`.
  Read-only list/detail pages do not — they have nothing to lose and only need `useCompanyReload`.
- `CompanySwitcher.select()` (edited once, in the pre-step) checks `hasUnsavedChanges()` and, if true, runs
  `window.confirm('Switching company discards unsaved changes. Continue?')`; on cancel it aborts the switch (no
  `selectCompany` call), on confirm it proceeds. After the switch commits, the editor's own `useCompanyReload`
  watch resets the form to the new company (or the editor page, seeing the company changed, navigates back to
  its list — an editor for company A's draft is meaningless under company B). `window.confirm` is the
  established destructive-action prompt in this codebase (`ChartOfAccountsPage`, `UsersPage`).
- This keeps confirm-and-discard genuinely abortable, avoids coupling `CompanySwitcher` to any specific editor,
  and adds one shared file edit rather than logic duplicated in two editors.

---

### 7. Invoice line editor — the highest-risk component

`components/sales/invoices/InvoiceLineEditor.vue` + `InvoiceLineRow.vue` + `TaxCodePicker.vue` +
`InvoiceTotals.vue`. Requirements §8 and Gate-1 flag this as the component most likely to be under-scoped;
QA gives it disproportionate attention (§9).

1. **Dynamic add/remove.** The editor holds `lines: LineDraft[]`, each with a stable client-side `key` (a
   local counter — the API assigns no id to a draft line) for `v-for` keying and per-line error mapping.
   "Add line" pushes a blank `LineDraft`; "remove" splices. At least one line is required (§4.7.1); the last
   line's remove is disabled.

2. **Discount mutual-exclusion (§4.7.4).** Each row has a discount-type toggle: `none` / `percent` / `amount`.
   The UI only ever holds one of `discount_percent` / `discount_amount` populated, so it is **structurally
   impossible** to submit both — rather than relying on the server's `invoice-line-two-discounts` 422. Switching
   type clears the other field.

3. **Read-only tax-code picker (§4.7.3, decision #7).** `TaxCodePicker` loads once via `listTaxCodes(active
   _only=true)`, presents `code — name (rate%)` for the human, and **emits the `code` string**. The wire value
   sent per line is `tax_code` (string) or `null`; a tax-code **id** is never in the payload. The picker never
   writes tax codes.

4. **No client money arithmetic (§4.7.6/§4.8.6, decision #5, ADR 0011 D5).** The editor computes **no**
   subtotal, tax, discount or total — not even a "live preview". `InvoiceTotals` renders only the totals from
   the **last successful API response** (the `201`/`200` body), and until the first successful save shows a
   subtle "Totals finalise on save" hint instead of numbers. No preview endpoint is added (no backend work).
   On save, the editor re-renders from the response's recomputed totals **and** recomputed per-line
   `tax_rate`/`tax_amount`/`line_total` (§4.8.5 — a changed `invoice_date` can change the rate), rather than
   keeping pre-edit values.

5. **`lines` replaces every line on update (§4.8.4).** On any line change the editor submits the **full**
   current line set, never a partial patch — the endpoint replaces all lines when `lines` is present.

6. **Clear-vs-omit for the header nullable fields (§4.8.2).** `reference`, `branch_id`, `discount_amount` and
   `due_date` follow the same discipline as customer edit: an untouched field is **omitted**; an explicitly
   cleared field is sent as `null`. `due_date` cleared to `null` re-derives from the customer's terms, and the
   UI states that so a bookkeeper is not surprised (§4.8.3). Amounts are decimal strings up to 4 dp, never
   numbers, never rounded client-side (§4.7.2).

7. **Per-line 422 mapping (§4.7.9, decision #8).** The API emits indexed error keys (`lines.0.tax_code`,
   `lines.2.unit_price`, …). `ApiError.fieldErrors` already flattens `errors` to one message per key. The
   editor maps `lines.<i>.<field>` back to the row at index `<i>` and the named field, highlighting that exact
   input via the same `:error` slot `TextField` uses; header-level and non-field domain refusals
   (`customer-not-invoiceable`, `revenue-account-not-postable`, `tax-code-outside-company`,
   `invoice-total-negative`, `due-date-before-invoice-date`) surface as `ui.notify` notices with the server's
   `detail`. A helper `mapLineErrors(fieldErrors)` in the editor parses the `lines.N.field` prefix.

8. **Create-with-issue (§4.7.7–4.7.8).** The "issue immediately" checkbox appears only under
   `PermissionGate permission="sales.invoices.issue"`. When checked, `issue: true` is sent; the UI does **not**
   assume a draft exists if the combined request fails (ADR 0012 D3 — a refusal leaves no draft), so on failure
   it stays on the create form rather than navigating to a non-existent invoice.

---

### 8. File-ownership split for two PARALLEL build lanes — merge conflicts impossible by construction

Two engineers build the Customer and Invoice lanes concurrently. The rule: **every file is owned by exactly one
lane, or it is a "shared — build first" file touched only in the pre-step, before the two lanes fork.** No file
is edited by both lanes. Because the lanes use separate `pages/sales/*` filenames and separate
`components/sales/{customers,invoices}/` directories, and every file the two would otherwise share is completed
and merged in the pre-step, a conflict between the lanes is not possible.

#### Shared pre-step — "build first", one engineer, merged before either lane starts

These are the only files both lanes depend on. They are small, mostly additive, and independently testable.

| # | File | Action | Why shared |
| --- | --- | --- | --- |
| P1 | `components/ui/Pagination.vue` (+ `.spec.ts`) | new | Both list pages consume it (decision #1, §5). |
| P2 | `components/ui/ConfirmDialog.vue` (+ `.spec.ts`) | new | Both lanes' hard-delete needs the typed-confirm dialog (decision #2). |
| P3 | `composables/useCompanyReload.ts` (+ `.spec.ts`) | new | Every page in both lanes uses it (§6). |
| P4 | `composables/useUnsavedGuard.ts` (+ `.spec.ts`) | new | Both editor pages register with it (§6). |
| P5 | `components/app/CompanySwitcher.vue` | **edit** (add `hasUnsavedChanges()` confirm in `select`) | Single choke point for confirm-and-discard (§6). The only shared file *edited* rather than created. |
| P6 | `types/domain.ts` | **edit** (append `Customer`, `CustomerCapabilities`, `CustomerStatus`, `TaxCode`, `SalesInvoice`, `SalesInvoiceLine`, `SalesInvoiceInput`, `SalesInvoiceCapabilities`, `SalesInvoiceStatus` blocks) | Both lanes import their types from here (existing convention). Adding all blocks in the pre-step means neither lane edits the file. |
| P7 | `router/index.ts` | **edit** (add all 8 route records, §4) | Single router file; adding both lanes' routes here prevents a two-lane merge on it. |
| P8 | `layouts/AppLayout.vue` | **edit** (add both lanes' nav items to the `navigation` array) | Single nav array; same reason as P7. |
| P9 | `locales/en.ts` | **edit** (add `customers`/`invoices` string keys IF the lanes reference them) | Optional — Gate-1 decision #4 keeps English strings inline (like the receivables pages), authored to be cleanly extractable later; this file is touched only if the lanes centralise strings. Default: **not touched**, strings stay inline per lane. |

> P6–P8 are the classic two-lane collision points (shared type file, shared router, shared nav). Moving *all*
> their edits into the pre-step is what makes the guarantee structural rather than a promise to "coordinate".

#### Customer lane — one engineer owns exclusively

| File | Action |
| --- | --- |
| `api/customers.ts` (+ `.spec.ts`) | new |
| `pages/sales/CustomersListPage.vue` (+ `.spec.ts`) | new |
| `pages/sales/CustomerFormPage.vue` (+ `.spec.ts`) | new |
| `pages/sales/CustomerDetailPage.vue` (+ `.spec.ts`) | new |
| `components/sales/customers/CustomerForm.vue` (+ `.spec.ts`) | new |
| `components/sales/customers/CustomerLifecycleMenu.vue` (+ `.spec.ts`) | new |
| `components/sales/customers/CustomerStatusBadge.vue` | new |

#### Invoice lane — the other engineer owns exclusively

| File | Action |
| --- | --- |
| `api/salesInvoices.ts` (+ `.spec.ts`) | new |
| `api/taxCodes.ts` (+ `.spec.ts`) | new |
| `pages/sales/SalesInvoicesListPage.vue` (+ `.spec.ts`) | new |
| `pages/sales/SalesInvoiceEditorPage.vue` (+ `.spec.ts`) | new |
| `pages/sales/SalesInvoiceDetailPage.vue` (+ `.spec.ts`) | new |
| `components/sales/invoices/InvoiceLineEditor.vue` (+ `.spec.ts`) | new |
| `components/sales/invoices/InvoiceLineRow.vue` | new |
| `components/sales/invoices/TaxCodePicker.vue` (+ `.spec.ts`) | new |
| `components/sales/invoices/InvoiceTotals.vue` (+ `.spec.ts`) | new |
| `components/sales/invoices/InvoiceActionsMenu.vue` (+ `.spec.ts`) | new |
| `components/sales/invoices/CancelInvoiceDialog.vue` (+ `.spec.ts`) | new |
| `components/sales/invoices/InvoiceStatusBadge.vue` | new |

**Sequencing.** Pre-step (P1–P8) is built, spec'd green, and merged to `feature/phase3-frontend` first. The two
lanes then branch from that point and never touch a pre-step file again. If a lane discovers a genuine gap in a
shared file mid-build, it does **not** edit it directly — it raises it, the change is made once on the pre-step
surface and both lanes rebase — preserving the no-shared-edit invariant. (Committing/branching is the DM's;
this ADR only defines the partition.)

---

### 9. Test strategy (QA writes red specs first)

Specs mirror the existing page-spec pattern (`OutstandingReceivablesPage.spec.ts`): mock `@/api/client` at the
module boundary, mount the page, drive it, assert. The `api` object is replaced with `vi.fn()`s
(`get`/`post`/`put`/`delete`/`setActiveCompany`/`configure`). Pinia is reset per test; a company is patched
into `auth` (the `signIn()` helper) so `useMoney`/`activeCompany` resolve. Specs exist to catch a **statable
failure mode** — a wrong number, a button shown that should be hidden, state clobbered by a company switch — not
to hit a coverage figure (the `pages/**` floor stays 0, ADR 0011). What to mock: the API client (and, for the
line editor, the tax-code list response). Each lane's specs live beside their files.

**Customer lane — each spec must assert:**

- List renders `data` from the mocked response, never a client-filtered subset; `q`/`filter[status]`
  round-trip to the request params (the request, not the rendered rows, proves server-side filtering).
- A failed list clears `rows` **and** `meta` and does **not** render the "no customers" empty state (ADR 0011
  D4 regression shape).
- Create sends **no** `status` field in any form (assert the request body has no `status` key), and
  `credit_limit` is a `string`.
- Edit sends **exactly** the changed fields: a dedicated spec proving `branch_id`, `receivable_account_id` and
  `credit_limit` can each be cleared to `null` independently, and that an untouched field is **absent** from the
  body (not sent as its current value).
- Each lifecycle action (archive/restore/deactivate/reactivate/delete) fires only after a confirm step, and is
  hidden when its `capabilities`/state gate is false — one spec per action.
- **Company switch while mounted triggers exactly one reload** (patch a new company into `auth`, assert a
  second request to the new company's URL). This is the silent-failure guard (ADR 0011 D3).

**Invoice lane — each spec must assert:**

- Create/edit send decimal **strings** for every amount (`typeof payload.lines[0].unit_price === 'string'`,
  etc.), never a JSON number.
- `tax_code` is sent as the code **string**, never an id, in both create and edit.
- The line editor renders the total from the **last API response**, never a recomputed one: feed a mocked
  response whose `total` is deliberately inconsistent with the lines and assert the page shows the API's number
  (ADR 0011's own technique).
- Issue and cancel are gated on `capabilities.can_issue`/`can_cancel`, **not** raw permission: a spec with an
  **owner-shaped** user and an already-issued/cancelled invoice asserts the control is still hidden (this is the
  exact gap ADR 0012 D4 warns of).
- Cancel cannot submit with an empty or under-3-char reason.
- Delete is offered **only** for a draft; a spec asserts it is absent for every other status.
- The list never sends `include=lines`; the detail always renders `lines`.
- **Company switch while the list, or the view/editor, is mounted reloads** rather than showing the previous
  company's invoice under the new heading; for the editor, the mid-edit path additionally confirms-and-discards.

Shared pre-step specs: `Pagination.spec.ts` (renders count, disables at ends, hides at `last_page<=1`, emits
`update:page`), `ConfirmDialog.spec.ts` (typed-confirm gating), `useCompanyReload.spec.ts` (one mount request +
one reload on change, none when unchanged), `useUnsavedGuard.spec.ts` (registry add/remove, `hasUnsavedChanges`).

---

### 10. Risks and mitigations

| Risk | Mitigation |
| --- | --- |
| **`capabilities` vs permission gating done wrong by reflex** — copying a `PermissionGate` permission-only check onto a state-dependent action (issue/cancel/archive-with-balance) silently reintroduces the owner gap (ADR 0012 D4). | §4 states the rule as a table (permission-only vs capabilities-AND-state), and §9 mandates an owner-shaped spec per state action. Recommend a Gate-2/3 review-checklist item. |
| **Company-switch watch forgotten** — fails silently (wrong company's data), never in a test (ADR 0011 D3 "nothing enforces it"). | The watch is extracted into `useCompanyReload` (§6) so a page opts in with one call, and QA's per-page reload spec (§9) is the enforcing mechanism. |
| **Line editor starts computing money** — a "helpful" live total drifts cents from the ledger (ADR 0011 D5). | §7.4 forbids all arithmetic; `InvoiceTotals` takes strings and renders them; the §9 "deliberately-wrong total" spec fails any page that recomputes. |
| **Two lists diverge on pagination** (page-size, control, param conventions) — the exact risk requirements §8 names. | One shared `Pagination` (P1), server-default `per_page`, no page-size selector this wave (§5). |
| **Clear-vs-omit gotten wrong** — sending an omitted field as its current value silently overwrites, or a cleared field omitted silently persists. | The payload builders live in `api/customers.ts` / the editor, are unit-tested in isolation (§9), and the diff is against an original snapshot, not the live form. |
| **Two-lane merge conflict** on the shared type/router/nav files. | §8: all shared-file edits happen in the pre-step before the fork; lanes use disjoint filenames and directories. Impossible by construction. |
| **Mid-edit company switch loses work unexpectedly** or cannot be aborted. | `useUnsavedGuard` + the confirm at `CompanySwitcher.select` (§6) make it abortable and explicit ("discards unsaved changes"). |

---

## Alternatives considered

1. **One mega-page per resource with modal create/edit** (like `ChartOfAccountsPage`'s inline form). Rejected:
   the invoice editor is far larger than a CoA row, dedicated routes let the router gate each screen on its own
   permission, and separate files keep each within one engineer's ownership for the parallel build.
2. **A Pinia store per resource / a generic CRUD-list composable.** Rejected per ADR 0011 D8 and §3 — the same
   reasoning that declined a generic report component. Pages hold their own transient state as they do today.
3. **A preview endpoint for live invoice totals.** Rejected by Gate-1 decision #5 — it is backend work (out of
   scope) and the "server computes, client never adds up" rule is absolute. Totals appear on save.
4. **Per-lane edits to `router.ts` / `domain.ts` / `AppLayout.vue` coordinated by hand.** Rejected: hand
   coordination is exactly what produces the merge conflict. Moving the edits to a pre-step removes the shared
   surface entirely.
5. **Numbered-page pagination / page-size selector.** Deferred: prev/next matches the existing footer and the
   Gate-1 decision that declined to over-build; adding numbers later touches only `Pagination.vue`.
6. **A dirty-guard via `router.beforeEach` / `onBeforeRouteLeave`.** Rejected for the company-switch case: a
   company switch is not a route change (`App.vue` does not re-mount), so a route guard never fires. The switch
   choke point is `CompanySwitcher.select`, which is where the guard must live.

## Consequences

- The codebase gains its **first pagination control, first typed-confirm dialog, and first reusable
  company-reload composable** — each a small, tested primitive future screens reuse, closing the ADR 0011/0012
  "no pagination client" follow-up.
- The "pages call `api` directly, no domain store, no generic table" convention is **preserved**; the only new
  layer is thin typed API modules, justified by the clear-vs-omit logic needing one testable home.
- The two-lane partition means the wave can be built by two engineers with **zero shared-file contention after
  the pre-step**, at the cost of one upfront serialised pre-step.
- The `capabilities`-AND-state gating discipline and the company-switch watch are now owed by every screen and
  are only enforced by QA's specs — the same standing hazard ADR 0011 recorded, carried forward and mitigated
  by making both patterns one-call composables/tables rather than copy-paste.

## Appendix — contract facts this ADR relies on (from `docs/api/openapi.yaml`)

- **Pagination**: `meta.pagination` = `{ total, per_page, current_page, last_page, from|null, to|null }`
  (`PaginatedMeta`). `listCustomers` returns `PaginatedMeta`; `listSalesInvoices` returns `Meta` in the schema
  but is documented as paginated — the UI reads `meta.pagination` defensively (renders no control when absent).
- **Customer**: `capabilities = { can_update, can_delete, accepts_new_invoices }`. `status ∈ {active, inactive,
  archived}` + `status_label`. `credit_limit` decimal string | null. Create requires only `name`; `status` not
  accepted. List params: `page`, `per_page`, `q`, `filter[status]`, `filter[branch_id]`, `sort ∈ {name,-name,
  code,-code,created_at,-created_at}` (422 on unsupported sort). 409 on duplicate code.
- **SalesInvoice**: `capabilities = { can_update, can_delete, can_issue, can_cancel }`; `can_cancel` true only
  while `status==='issued'`. `status ∈ {draft, issued, partially_paid, paid, cancelled}`. `number` null while
  draft. `lines` present on single GET and on list only with `include=lines`. List params: `page`, `per_page`,
  `q` (number+reference only), `filter[status]`, `filter[customer_id]`, `filter[branch_id]`, `include=lines`,
  `sort` default `-invoice_date`.
- **SalesInvoiceInput**: required `customer_id`, `invoice_date`, `lines` (min 1, max 500). Amounts decimal
  strings ≤4dp. `lines[].tax_code` is the code string (≤32), not an id. Per-line required: `description`,
  `quantity`, `unit_price`, `revenue_account_id`. `discount_percent`/`discount_amount` mutually exclusive.
  `issue: true` create-only, needs `sales.invoices.issue`.
- **TaxCode** (read-only picker): `listTaxCodes?active_only=true`. `rate` is a percentage decimal string
  (`18.0000` = 18%). Never written by this wave.
- **Errors**: RFC 9457 problem docs; branch on `type` suffix via `ApiError.code`. 422 validation carries
  `errors` keyed per field, indexed for lines (`lines.0.tax_code`). 409 `ResourceConflict`. 403 `forbidden`.
  404 not-found (indistinguishable from inaccessible). `invoice-company-mismatch` (ADR 0012 D2) treated as
  not-found-equivalent (§4.9.5).
