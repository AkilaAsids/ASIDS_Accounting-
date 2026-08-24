# Phase 3 front end — Customer and Invoice screens — Requirements

**Stage: Business Analysis · awaiting Gate 1 approval.** Written by the Business Analyst on Minions
delivery run "Phase 3 frontend" (branch `feature/phase3-frontend`). Converts the carried-forward
Milestone 8 scope — see [`ROADMAP.md`](ROADMAP.md), "Phase 3 — carried forward from Milestone 8" — into
testable requirements for the two outstanding front-end lanes. No backend work is authorised or implied
by this document.

## 1. Objective & context

Phase 3's backend is complete through Milestone 9: `CustomerController` and `TaxCodeController` (Milestone
6, `companies/{company}/customers` and `companies/{company}/tax-codes`, nine endpoints each) and
`SalesInvoiceController` (Milestone 9, `companies/{company}/sales-invoices`, seven endpoints) all exist,
are authorised through the existing `sales.customers.*`, `sales.tax-codes.*` and `sales.invoices.*`
abilities, and are documented in `docs/api/openapi.yaml`. Milestone 8 built only the receivables-reporting
front end and explicitly left customer and invoice screens outstanding (`ROADMAP.md`, Milestone 8 section;
[ADR 0011](adr/0011-receivables-reporting-frontend-and-http-surface.md), D1). This run gives the Customer
and Invoice APIs a user interface. Tax-code screens are not part of this wave. No migration, no new
endpoint, and no change to any service, policy or permission is in scope — every requirement below is a
UI over an API that already exists and is already tested.

## 2. Scope

### In scope
- **Customer front end**: list (search, status filter, pagination), create, edit, view, archive/restore,
  deactivate/reactivate, delete — over `companies/{company}/customers` and its five lifecycle sub-routes.
- **Invoice front end**: list (status filter, pagination), draft create, draft edit (line editor), view,
  issue (with confirmation), cancel (with required reason), delete draft — over
  `companies/{company}/sales-invoices` and its `issue`/`cancel` sub-routes.

### Out of scope
- **Tax-code front end** — deferred. The tax-code REST API (Milestone 6) is complete and untouched by this
  run; screens over it are listed in `ROADMAP.md` as "Proposed" (not carried-forward, not firm) and are
  explicitly excluded from this wave. Any tax-code picker needed inside the invoice line editor (§4.6)
  consumes `GET /companies/{company}/tax-codes` read-only — it is not tax-code CRUD.
- **No backend changes of any kind**: no migration, no new endpoint, no service/policy/permission change,
  no OpenAPI addition beyond what already documents the nine + seven endpoints above.
- Payments, receipts, or anything from Phase 4 — `amount_paid`/`amount_due` are rendered as the API
  returns them, never computed or edited client-side.
- Any repair of existing technical debt not required to build these screens (e.g. the two divergent money
  formatters, `abort(403)` vs `AuthorizationException` inconsistency — both per ADR 0011).

## 3. Users & permissions

Three roles reach these screens, per `src/Core/Authorization/Domain/Catalogue/RoleTemplate.php` (the
workspace owner bypasses every check via `Gate::before` and is not listed separately):

| Capability | Accountant | Bookkeeper | Viewer |
| --- | --- | --- | --- |
| `sales.customers.view` (list, view) | Yes | Yes | Yes |
| `sales.customers.manage` (create, edit, archive, restore, deactivate, reactivate, delete) | Yes | Yes | No |
| `sales.invoices.view` (list, view) | Yes | Yes | Yes |
| `sales.invoices.draft` (create, edit, delete draft) | Yes | Yes | No |
| `sales.invoices.issue` | Yes | **No** | No |
| `sales.invoices.cancel` | Yes | **No** | No |

Notes grounded in the policies:
- `CustomerPolicy` (`src/Core/Sales/Policies/CustomerPolicy.php`) gates create/update/archive/restore/delete
  all on `sales.customers.manage` plus company membership — there is no finer split for customers in this
  wave. A bookkeeper therefore has full customer CRUD, including delete; only `view` is separated out for
  the viewer role.
- `SalesInvoicePolicy` (`src/Core/Sales/Policies/SalesInvoicePolicy.php`) separates **drafting** from
  **issuing/cancelling** deliberately: `sales.invoices.draft` covers create, update and delete-of-a-draft;
  `issue` and `cancel` are separate, accountant-only capabilities, mirroring
  `accounting.journals.post`/`.reverse`. A bookkeeper can build and edit a draft invoice end-to-end but
  cannot commit it to the ledger or reverse one.
- Every `*Resource` in the API (`Customer`, `SalesInvoice`) ships a `capabilities` block
  (`can_update`, `can_delete`, `can_issue`, `can_cancel`, `accepts_new_invoices`, …) that folds permission
  **and** current state together — e.g. `can_cancel` is true only while `status === 'issued'`. The UI
  **must** gate every action button on the resource's own `capabilities` object, not on a permission check
  alone: `SalesInvoicePolicy`'s docblock states plainly that the policy's state checks are advisory only
  (short-circuited for an owner by `Gate::before`), and the resource's `capabilities` is the one place that
  combines gate and state correctly. This is the same pattern `ChartOfAccountsPage.vue` already follows for
  `account.capabilities.can_update`.
- Destructive/sensitive actions — **delete** (customer, invoice draft), **issue**, **cancel** — must be
  both (a) rendered only when `capabilities.can_*` is true and (b) confirmed before the request fires (see
  §4 acceptance criteria per screen). `PermissionGate.vue` (`resources/js/components/app/PermissionGate.vue`)
  is the existing component for permission-only gating (e.g. hiding "Add a customer"); it is not sufficient
  on its own for state-dependent actions and must be paired with a `capabilities` check.

## 4. Functional requirements

Every screen is a UI over an endpoint documented in `docs/api/openapi.yaml`; each subsection names the
concrete operation(s). All money fields are decimal strings end to end — the UI never parses one into a
number for arithmetic and never sends a JSON number back (`openapi.yaml` lines 4402-4404, 4746-4748,
4759-4771; matches the existing rule stated in `useMoney.ts` and enforced by `AgedReceivablesPage.vue`/
`OutstandingReceivablesPage.vue` for reporting).

### 4.1 Customer list

**Endpoint:** `GET /companies/{company}/customers` (`listCustomers`).

> As an accountant or bookkeeper, I want to find a customer quickly among many, so I can open, edit or
> raise an invoice against the right one.

1. **Given** the customer list page loads, **when** the request succeeds, **then** it renders the
   `data` array from the response and never a client-computed subset — filtering and search are server
   parameters (`q`, `filter[status]`, `filter[branch_id]`), not client-side array filtering.
2. **Given** a user types into the search field, **when** they pause typing, **then** the list re-queries
   with `q=<term>` after a debounce (matching the 300 ms pattern already used in `UsersPage.vue`) rather
   than firing one request per keystroke.
3. **Given** the status filter is changed among `active` / `inactive` / `archived` / "all", **when** the
   selection changes, **then** the list re-queries with `filter[status]` set (or omitted for "all").
4. **Given** the API returns `meta.pagination` (`PaginatedMeta` schema — `total`, `per_page`,
   `current_page`, `last_page`, `from`, `to`), **when** `last_page > 1`, **then** the page renders a
   pagination control that lets the user move between pages and shows "`from`–`to` of `total`". This is
   the **first page in the codebase to render `meta.pagination`** — no existing precedent exists (ADR 0011,
   "Known limitations": *"No pagination control exists anywhere… the first genuinely unbounded list — the
   invoice list — will need one"*). See open question in §7.
5. **Given** the request fails, **when** the error is shown, **then** the table area is cleared (not left
   showing stale rows) and the empty/loaded state is keyed on a successful response having landed — never
   on `rows.length === 0` alone — following ADR 0011 D4's corrected mistake on the reporting pages.
6. **Given** a row's customer has `status: archived`, **when** rendered, **then** it is visually
   de-emphasised (e.g. the same `opacity-60` treatment `ChartOfAccountsPage.vue` gives an inactive account)
   and its row actions reflect `capabilities` (§4.3–4.5) rather than a status string parsed in the
   template.
7. **Given** a `422` with an unsupported `sort` value (documented on this endpoint), **when** it is
   received, **then** the failure is surfaced via `ui.notify('error', …)` using the problem's `detail`,
   matching every other list page's error handling.

### 4.2 Customer create

**Endpoint:** `POST /companies/{company}/customers` (`createCustomer`), body: `CustomerRequest`.

> As a bookkeeper, I want to add a customer while raising their first invoice, so I don't have to leave the
> flow to set them up elsewhere first.

1. **Given** the create form, **when** it is submitted with only `name` filled in, **then** the request
   succeeds — `name` is the only required field (`CustomerRequest.required: [name]`) and `code` is left
   blank to let the server generate a `C-NNNN` style code.
2. **Given** `credit_limit` is entered, **when** submitted, **then** it is sent as the exact decimal string
   the user typed (or normalised to a valid decimal string), never as a JSON number — a leading minus is
   accepted client-side so the server's own `negative-credit-limit` refusal, not a client guess, names the
   problem.
3. **Given** the form is submitted, **when** the server returns `422` field errors, **then** each error is
   shown against its field via `ApiError.fieldErrors`, matching `ChartOfAccountsPage.vue`'s `create()`.
4. **Given** the server returns `409` (customer code already in use), **when** received, **then** it is
   shown as a notice (not a field error, since the conflict is a domain rule rather than a plain validation
   failure) — matching the `create()` pattern that surfaces non-field errors via `ui.notify`.
5. **Given** creation succeeds, **when** the response returns, **then** the list reloads (or the new
   customer is inserted) and a success notice names the created customer.
6. **Given** the current user lacks `sales.customers.manage`, **when** the page renders, **then** the
   "Add a customer" control is hidden via `PermissionGate` (`permission="sales.customers.manage"`) — the
   server still refuses the request independently.
7. `status` is never sent — the request schema does not accept it (`CustomerRequest` description: *"a
   customer's state moves only through the named lifecycle endpoints"*), so the create form must not offer
   a status field at all.

### 4.3 Customer edit

**Endpoint:** `PUT /companies/{company}/customers/{customer}` (`updateCustomer`), body: `CustomerRequest`.

> As an accountant, I want to change a customer's credit limit or clear their branch assignment without
> touching anything else on the record.

1. **Given** the edit form is opened, **when** it loads, **then** it is pre-filled from the customer
   resource already fetched (or a fresh `GET`), and only fields the user actually changes are included in
   the `PUT` body — **omitted fields must remain untouched by the request payload**, per the clear-vs-omit
   contract (`updateCustomer` description; `docs/SALES-HTTP-API-DESIGN.md`, I3).
2. **Given** the user explicitly clears `branch_id`, `receivable_account_id` or `credit_limit` (e.g. via a
   "clear" control, not merely leaving an input blank on a form that never touched it), **when** submitted,
   **then** that field is sent as JSON `null` in the request body, which the API documents as *clearing*
   that field independently of the others — this is the acceptance-critical distinction the whole edit
   screen exists to get right.
3. **Given** the customer's `code` — **when** the customer has already been invoiced, **then** the code
   field is read-only/disabled in the UI (the API refuses a code change once invoiced; the UI states this
   rather than letting the user discover it via a failed submit). Absent a `capabilities` flag naming this
   directly, the UI may rely on the server's error and surface it as a notice, but should prefer disabling
   the field when the customer's own data (e.g. having at least one invoice) is knowable from context.
4. **Given** the server returns `409` (code already in use) or `422` (validation), **when** received,
   **then** it is surfaced exactly as in create (§4.2.3–4.2.4).
5. **Given** the current user's `capabilities.can_update` on this customer is `false`, **when** the page
   renders, **then** no edit control is offered.

### 4.4 Customer view

**Endpoint:** `GET /companies/{company}/customers/{customer}` (`getCustomer`).

> As a viewer (auditor/lender), I want to see one customer's full record and current status.

1. **Given** a customer id, **when** the detail page loads, **then** it renders every field of the
   `Customer` schema (contact/address, VAT/TIN, `status`, `status_label`, `credit_limit`, `payment_terms_days`,
   `receivable_account_id` or the resolved account, `archived_at`).
2. **Given** the customer is `archived` or `inactive` (deactivated), **when** rendered, **then** the status
   is stated in words (`status_label`), not colour alone (WCAG 1.4.1, and matching ADR 0011 D7's "never
   depends on colour alone" principle already applied to the AR control page).
3. **Given** `capabilities`, **when** rendered, **then** archive/restore/deactivate/reactivate/edit/delete
   controls on this page are shown or hidden exactly per `capabilities.can_update` / `can_delete`, never
   inferred from `status` alone in the template.
4. **Given** a `404` (not found, or not accessible — the API deliberately does not distinguish the two,
   per the `NotFound` response description), **when** received, **then** a generic not-found state is
   shown; the UI must not attempt to infer or state which of the two applies.

### 4.5 Customer lifecycle: archive / restore / deactivate / reactivate / delete

**Endpoints:** `POST .../archive`, `POST .../restore`, `POST .../deactivate`, `POST .../reactivate`,
`DELETE .../{customer}`.

> As an accountant, I want to retire a customer who no longer trades, or fully remove one I added by
> mistake, without risking the receivables record.

1. **Given** a customer with an outstanding balance, **when** "Archive" is attempted, **then** the UI
   attempts the request and surfaces the API's `422` ("the customer has an outstanding balance") as a
   notice — the UI does not need to (and cannot, without duplicating server logic) pre-compute the
   customer's balance to disable the button; it relies on the server's refusal per this endpoint's
   documented rule.
2. **Given** "Archive" or "Delete" is clicked, **when** the click registers, **then** a confirmation
   prompt is shown before the request fires (matching `window.confirm(...)` used for `archive()` in
   `ChartOfAccountsPage.vue` and `act()` in `UsersPage.vue`) — these are destructive/sensitive actions per
   §3 and must never fire on a single click.
3. **Given** "Delete" is attempted on a customer already named on an invoice, **when** the server returns
   `422` ("the customer has been invoiced"), **then** the notice states that archiving is the ordinary path
   for this case (the API's own description explains why: an invoice is a statutory record naming its
   customer). The UI should consider surfacing "Archive" as the suggested alternative directly in that
   notice or by disabling "Delete" pre-emptively is out of scope unless a `capabilities.can_delete` flag
   already communicates it — check `CustomerResource`'s emitted `capabilities` before assuming the flag
   exists (open question, §7).
4. **Given** "Restore" is attempted on a customer whose former code has since been taken by another
   customer, **when** the server returns `409`, **then** the notice states the conflict per the API's
   description and does not silently retry.
5. **Given** "Deactivate" is attempted on an already-archived customer, **when** the server returns `422`,
   **then** the notice states that an archived customer cannot be deactivated separately (archiving already
   subsumes it).
6. Every action in this section is only offered when `sales.customers.manage` is held **and** the relevant
   `capabilities` flag (or documented state) allows it — never on permission alone (§3).

### 4.6 Invoice list

**Endpoint:** `GET /companies/{company}/sales-invoices` (`listSalesInvoices`).

> As a bookkeeper, I want to see every invoice for a customer or in a given status, so I know what is still
> a draft and what has been issued.

1. **Given** the invoice list loads, **when** the request succeeds, **then** it renders `data` with
   `filter[status]` (`draft`/`issued`/`partially_paid`/`paid`/`cancelled`), `filter[customer_id]`, and `q`
   (searches invoice number and reference only, per the endpoint description — **not** the customer name)
   all as server query parameters.
2. **Given** the list is the first genuinely unbounded list in the application per ADR 0011's own framing,
   **when** `meta.pagination.last_page > 1`, **then** a pagination control is rendered — same requirement
   and same open question as §4.1.4/§7.
3. **Given** the default sort is `-invoice_date` (newest first, per the schema default), **when** no sort
   is chosen, **then** the UI does not override it silently with a different client-side order.
4. **Given** a row's invoice is a draft, **when** rendered, **then** it is visually distinguished from an
   issued/cancelled one (no `number` yet — `number` is documented as null exactly while draft) and its
   available actions (edit, delete) differ per §4.8/§4.10 from an issued invoice's (view, cancel).
5. **Given** `include=lines` is **not** requested on the list (only a per-invoice `GET` needs it, per the
   endpoint description: *"omitted, only the header and a summary of the customer are returned"*), **when**
   building the list view, **then** the UI must not attempt to show line-level detail on the list — only on
   the view screen (§4.9).
6. Same empty/error-state and failure-clearing rules as §4.1.5.

### 4.7 Invoice draft create

**Endpoint:** `POST /companies/{company}/sales-invoices` (`createSalesInvoice`), body:
`SalesInvoiceInput`.

> As a bookkeeper, I want to draft an invoice with one or more lines and see the correct tax and total
> before I save it.

1. **Given** the create form, **when** submitted, **then** `customer_id`, `invoice_date` and at least one
   line (`description`, `quantity`, `unit_price`, `revenue_account_id` each required per line) are sent —
   these are the schema's required fields.
2. **Given** amounts (`quantity`, `unit_price`, `discount_amount`, `discount_percent`, header
   `discount_amount`), **when** the user types them, **then** they are sent as decimal strings up to four
   decimal places — never as JSON numbers, and never rounded client-side (schema description: *"rejected
   rather than rounded"*).
3. **Given** a line's `tax_code` field, **when** the user selects a tax code, **then** the UI sends the tax
   **code** (a string, e.g. `"VAT"`), never a tax-code id — the API resolves the applicable rate from
   `(company, code, invoice_date)`, and this wave does not build tax-code CRUD, only a read-only picker
   sourced from `GET /companies/{company}/tax-codes?active_only=true`.
4. **Given** a line sets both `discount_percent` and `discount_amount`, **when** submitted, **then** the UI
   must prevent entering both on one line (mutually exclusive per schema) rather than relying solely on the
   server's `invoice-line-two-discounts` 422.
5. **Given** the user has not typed a `due_date`, **when** submitted, **then** it is omitted (not sent as
   an empty string) so the server derives it from the customer's payment terms — matching the established
   "omit rather than send empty" convention already used for `as_of` in `AgedReceivablesPage.vue`.
6. **Given** totals (`subtotal`, `discount_total`, `tax_total`, `total`) are needed for on-screen review
   before saving, **when** the user is composing a draft, **then** the UI does **not** compute them
   client-side — see §5 (D5 of ADR 0011 applies with equal force here: the same reasoning that keeps a
   report page from summing a column keeps an invoice editor from computing a total that could read a few
   cents from the ledger's). This is a hard constraint: the create endpoint returns the authoritative
   totals in its `201` response; a "live preview" before that first successful save either shows nothing,
   shows the previous saved state, or (if the Architect chooses) issues a preview request — this UI/UX
   choice is flagged in §7, not decided here.
7. **Given** the accountant checks "issue immediately" (`issue: true`) and holds `sales.invoices.issue`,
   **when** submitted, **then** draft and issue happen in one request/transaction — per the schema, a
   refusal to issue leaves no draft behind, so the UI must not assume a draft was created if the combined
   request fails.
8. **Given** the user holds `sales.invoices.draft` but not `.issue`, **when** the create form renders,
   **then** the "issue immediately" option is not offered (`PermissionGate permission="sales.invoices.issue"`).
9. **Given** any of the documented domain refusals (`customer-not-invoiceable`, `revenue-account-not-postable`,
   `tax-code-outside-company`, `invoice-line-two-discounts`, `invoice-total-negative`,
   `due-date-before-invoice-date`), **when** returned as 422, **then** each is surfaced with enough context
   to locate the offending line/field — field-level where `fieldErrors` maps cleanly (e.g. per-line via
   array-indexed keys, if the API emits them that way — confirm with the Architect) and as a notice
   otherwise.

### 4.8 Invoice draft edit

**Endpoint:** `PUT /companies/{company}/sales-invoices/{invoice}` (`updateSalesInvoice`), body:
`SalesInvoiceInput`.

> As a bookkeeper, I want to fix a mistake on a draft invoice before it's issued.

1. **Given** the invoice is not a draft, **when** the edit screen would otherwise be reachable, **then** it
   is not offered — the API refuses with `invoice-not-editable` and a database trigger enforces it
   underneath; the UI gates the edit control on `capabilities.can_update` (which the resource ties to
   draft status) rather than letting the user reach a form that always fails.
2. **Given** the edit form, **when** a field is left untouched, **then** it is omitted from the request
   body (leaves it as-is); **when** a nullable field (`reference`, `branch_id`, `discount_amount`) is
   explicitly cleared, **then** it is sent as `null` — same clear-vs-omit discipline as §4.3.2, called out
   explicitly by name in the endpoint description for these three fields.
3. **Given** `due_date` is explicitly cleared (sent as `null`), **when** submitted, **then** the UI
   communicates that this re-derives the date from the customer's terms rather than leaving it blank — this
   is a deliberate asymmetry from the other nullable fields and must not surprise a bookkeeper who expects
   "clear" to mean "empty."
4. **Given** the `lines` array is included in the request at all, **when** submitted, **then** it
   **replaces every line** — the editor must therefore always submit the full current set of lines on any
   line-level change, never a partial patch, per the endpoint's explicit warning.
5. **Given** `invoice_date` is changed, **when** the lines are recomputed server-side (because a changed
   date can change which tax rate applies), **then** the edit screen re-renders from the response's
   recomputed totals and line-level tax figures rather than keeping the pre-edit values on screen.
6. Live totals during editing are subject to the same "server computes, client never adds up" constraint
   as §4.7.6.

### 4.9 Invoice view

**Endpoint:** `GET /companies/{company}/sales-invoices/{invoice}` (`getSalesInvoice`).

> As a viewer, I want to see one invoice's full detail including its lines, tax breakdown and status
> history.

1. **Given** an invoice id, **when** the detail page loads, **then** it renders the full `SalesInvoice`
   schema including `lines` (always present on the single-invoice `GET`, per the schema description),
   `subtotal`/`discount_total`/`tax_total`/`total`/`amount_paid`/`amount_due` exactly as returned — no
   client-side sum, difference or derived total anywhere on this page.
2. **Given** `is_overdue` is `true`, **when** rendered, **then** it is stated in words, not colour alone
   (same WCAG rule as §4.4.2).
3. **Given** the invoice is `cancelled`, **when** rendered, **then** `cancelled_at`, `cancellation_reason`
   and `cancelled_by_id` (resolved to a name if available) are displayed — the invoice is never shown as if
   nothing happened to it.
4. **Given** `journal_entry_id` is present (issued or cancelled invoice), **when** rendered, **then** the
   UI may link to it only if a journal-entry detail route already exists and is reachable under this user's
   permissions — do not build a new cross-module link as part of this wave if none exists; confirm with the
   Architect.
5. **Given** a `422` "invoice does not belong to the company in the path" (`invoice-company-mismatch`),
   **when** received (e.g. from a stale link after a company switch), **then** it is treated as a
   not-found-equivalent state, not a generic error banner.

### 4.10 Invoice issue

**Endpoint:** `POST /companies/{company}/sales-invoices/{invoice}/issue` (`issueSalesInvoice`).

> As an accountant, I want to commit a finished draft to the ledger, with a clear point of no return.

1. **Given** "Issue" is clicked, **when** the click registers, **then** a confirmation dialog is shown
   stating plainly that issuing is irreversible except by cancellation, before the request fires — this is
   the single most consequential action in this wave (it posts to the ledger) and must be treated as such
   per §3's rule on sensitive actions.
2. **Given** the control is rendered at all, **when** `capabilities.can_issue` is `false` (wrong
   permission, or not a draft), **then** it is not shown — per `SalesInvoicePolicy::issue()`, the state
   check is advisory only for an owner, so the resource's `capabilities` (which folds gate + state) is the
   only correct source, not a raw permission check.
3. **Given** any of the documented 422 refusals (`invoice-not-a-draft`, `invoice-has-no-lines-to-issue`,
   `invoice-total-not-positive`, `invoice-period-not-open`, `receivable-account-missing`,
   `tax-output-account-missing`), **when** returned, **then** each is surfaced as a notice with its
   specific meaning — a generic "could not issue" message is not acceptable given how differently each of
   these needs to be acted on (e.g. "period not open" points at Accounting, not Sales).
4. **Given** issuing succeeds, **when** the response returns, **then** the UI updates to the invoice's new
   `number`, `status`, `issued_at`, and `journal_entry_id` from the response — never assumes a number in
   advance.

### 4.11 Invoice cancel

**Endpoint:** `POST /companies/{company}/sales-invoices/{invoice}/cancel` (`cancelSalesInvoice`), body:
`{ reason }`.

> As an accountant, I need to reverse an issued invoice's posting with an auditable reason.

1. **Given** "Cancel" is clicked, **when** the dialog opens, **then** a reason field is **required**
   (3–255 characters per the schema) and the request cannot be submitted without it — the API requires it
   and states it is "recorded against the document permanently."
2. **Given** the control is rendered, **when** `capabilities.can_cancel` is `false` (per the schema:
   *"true only while the status is issued — never for a cancelled invoice"*), **then** it is not shown.
3. **Given** any of the documented refusals (`invoice-not-issued`, `invoice-already-cancelled`,
   `invoice-partially-paid`, `invoice-reversal-period-not-open`), **when** returned, **then** each is
   surfaced distinctly — in particular, the UI must communicate that `invoice-reversal-period-not-open`
   refers to **today's** period, not the invoice's original period (the endpoint description states this
   explicitly and it is a common source of confusion).
4. **Given** cancellation succeeds, **when** the response returns, **then** the UI shows the invoice's
   original entry is untouched and a mirror entry has been posted — this is stated in the endpoint
   description as "not an undo," and the confirmation copy should reflect that framing rather than implying
   deletion.

### 4.12 Invoice delete (draft only)

**Endpoint:** `DELETE /companies/{company}/sales-invoices/{invoice}` (`deleteSalesInvoice`).

1. **Given** the invoice is a draft, **when** "Delete" is offered, **then** it is gated on
   `capabilities.can_delete` and confirmed before firing (same destructive-action rule as §4.5.2).
2. **Given** the invoice is not a draft, **when** delete is attempted anyway (should not be reachable per
   the gate above, but the server is authoritative), **then** the `422` (`invoice-not-editable`, or wrong
   company) is surfaced as a notice.
3. **Given** deletion succeeds, **when** the `204` returns, **then** there is no restore path offered
   anywhere in the UI — the API has none (ADR 0012 D-notes / `deleteSalesInvoice` description: *"no
   tombstone is kept and there is no restore counterpart"*). This must not be confused with customer
   delete, which likewise has no restore, or with customer/tax-code archive, which does.

## 5. Non-functional requirements

1. **Accessibility — WCAG 2.1 AA**, matching the standard already stated for this project
   (`docs/PHASE-1-CODE-REVIEW.md`: *"Vue 3 + TS + Pinia + Tailwind, dark mode, WCAG… components tested for
   roles, labelling and `aria-*` wiring"*) and the concrete precedent set by ADR 0011's Consequences
   section: every horizontally-scrolling table container needs `role="region"`, an `aria-label`, and
   `tabindex="0"` so a keyboard-only user can reach columns off the right edge (both the customer list and
   the invoice line editor are wide-table candidates). Status must never be conveyed by colour alone
   (§4.4.2, §4.9.2) — pair colour with text, matching `AlertBanner`'s existing icon+colour pairing.
2. **Responsive/mobile.** Both lanes must be usable on a phone-width viewport, consistent with the existing
   pages' `overflow-x-auto` table pattern; no new dependency (data-grid library, etc.) is introduced for
   this, per the "follow existing conventions" precedent (ADR 0011 D8).
3. **i18n.** `resources/js/locales/en.ts` exists and is wired via `vue-i18n` (`resources/js/app/main.ts`),
   but usage is **inconsistent today** — `UsersPage.vue`, `JournalEntriesPage.vue` and `SettingsPage.vue`
   use `$t(...)`, while `ChartOfAccountsPage.vue` and both receivables report pages hardcode English
   strings directly. **No `sales`/`customer`/`invoice` keys exist yet in `en.ts`.** This wave should follow
   whichever convention the Architect designates (see §7); this document does not decide it, but flags that
   either choice is a real inconsistency with at least one existing precedent.
4. **Company switch (ADR 0011 D3).** Every page in both lanes must `watch` the active company id
   (`useAuthStore().activeCompany`) and reload — **not** `immediate` — exactly as
   `OutstandingReceivablesPage.vue` and `AgedReceivablesPage.vue` already do, because `App.vue` keys its
   `RouterView` on the route path, not the company, so a company switch never re-mounts a page already on
   screen. This applies to the list pages, the view pages, and any editor left open — an editor mid-edit
   when the company switches is an open question, not decided here (see §7).
5. **`meta.pagination`.** Both list screens (§4.1, §4.6) must render a pagination control sourced from
   `meta.pagination` — this is a **new** UI element with no precedent anywhere in the codebase (ADR 0011
   confirms zero pages currently render one). See §7 for the open UX question this raises.
6. **Consistency with existing patterns.** No new store is introduced unless the Architect decides one is
   warranted for the invoice line editor's local state; no new per-resource composable unless the Architect
   decides shared list/CRUD logic (search debounce, pagination, permission-gated actions) is worth
   extracting — `ADR 0011 D8` explicitly chose *not* to add a generic report/table component for the
   reporting pages, and that precedent should be weighed, not assumed, for CRUD screens which are a
   different shape of problem (create/edit forms, not read-only reports).
7. **Money formatting.** Use `useMoney().formatPlain`/`.format` (not `useFormat`, which uses lakh grouping)
   for consistency with the two existing Sales pages, per ADR 0011 D8 — this wave does not resolve the
   two-formatter inconsistency, it just picks the same side the existing Sales screens already picked.
8. **No client-side arithmetic on money** anywhere in either lane — every total, tax figure, and derived
   amount is rendered exactly as the API returns it (§4.7.6, §4.8.6, §4.9.1), for the same reason ADR 0011
   D5 gives for the reporting pages: floating-point summation drifts from the ledger's figure by cents, and
   the customer is left holding two numbers with no way to choose.

## 6. Acceptance-test expectations

QA writes **red Vitest specs first**, mirroring the existing page-spec pattern
(`OutstandingReceivablesPage.spec.ts`, `AgedReceivablesPage.spec.ts`, `ArControlPage.spec.ts`). Note the
`pages/**` coverage floor is currently 0 (ADR 0011: *"mounting a screen to watch a table render tests Vue
rather than this application"*) — specs should exist because they assert a specific, statable failure mode
(a wrong number, a hidden button that should show, a state clobbered by a company switch), not because a
coverage percentage demands one. Key behaviours each lane's specs must assert:

**Customer lane**
- List renders `data` from the mocked response, never a client-filtered subset (mock `q`/`filter[status]`
  round-tripping to the request).
- A failed list request clears rows and does not render the "no customers" empty state — mirrors ADR 0011
  D4's own regression test shape.
- Create sends `status` in no form of the request body, ever, regardless of UI state.
- Edit sends **exactly** the changed fields on omission and explicit `null` on clearing — a dedicated spec
  proving `branch_id`/`receivable_account_id`/`credit_limit` can each be cleared independently, and that an
  untouched field is absent from the request body (not sent as its current value).
- Archive/delete/restore/deactivate/reactivate each fire only after a confirmation step, and each is hidden
  when the relevant `capabilities` flag is false — a spec per action, mirroring the permission-and-state
  double gate.
- A company switch while the list page is mounted triggers exactly one reload, matching the existing
  `watch(companyId, …)` spec pattern already used on the two report pages.

**Invoice lane**
- Draft create/edit send decimal strings for every amount field, never a JSON number — a spec that asserts
  the request payload's amount fields are `typeof string`.
- `tax_code` is sent as the code string, never a resolved id, in both create and edit requests.
- The line editor never computes or displays a total that did not come from the last successful API
  response — a spec that feeds a deliberately "wrong" total in a mocked response and asserts the page
  renders that value, not a recomputed one (mirrors ADR 0011's own test technique: *"each report's spec
  makes `meta.totals` deliberately inconsistent with the rows shipped alongside it, so a page that ever
  starts computing its own totals fails in a test"*).
- Issue and cancel are each gated on `capabilities.can_issue`/`can_cancel`, not on the raw permission alone
  — a spec with an owner-shaped user and an already-issued/cancelled invoice, asserting the control is
  still hidden.
- Cancel cannot be submitted with an empty or under-3-character reason.
- Delete is offered only for a draft; a spec asserts it is absent for every other status.
- A company switch while the invoice list, or the invoice view/editor, is mounted reloads rather than
  showing the previous company's invoice under the new company's heading.

## 7. Assumptions & open questions for the human

1. **Pagination control UX.** `meta.pagination` is emitted today and rendered by no page anywhere in the
   codebase (ADR 0011). This wave is what finally needs one, on two screens at once (customer list, invoice
   list). What should it look like — numbered pages, prev/next only, a page-size selector, infinite scroll?
   Should one shared component be built (a first for this codebase, where ADR 0011 deliberately declined a
   generic report component) or should each list page implement its own, matching every other page's
   "no shared table component" precedent? This is an architecture decision, not a requirements one, but it
   blocks both list screens equally and should be resolved before Gate 2.
2. **Hard-delete visibility.** Both lanes expose an irreversible, no-restore `DELETE` (customer never
   invoiced; invoice draft). Should "Delete" be a first-class, always-visible button next to "Archive"
   (customer) or "Edit" (invoice draft), or should it be tucked behind a secondary menu / "advanced" toggle
   to reduce the chance of an accidental irreversible action? The API imposes no UI position; this is a
   product decision.
3. **Money/locale formatting.** `useMoney` (`LKR 1,234,567.50`) and `useFormat` (`LKR 12,34,567.50`, lakh
   grouping) both exist and disagree (ADR 0011 D8). This wave defaults to `useMoney` to match the existing
   Sales pages, but if the product intends lakh grouping for a Sri Lankan SME audience specifically, that
   should be decided now rather than inherited by default into two more screens.
4. **i18n coverage.** Should the new screens use `$t(...)` against new `sales.*`/`customer.*`/`invoice.*`
   keys added to `en.ts` (following `UsersPage.vue`), or hardcoded English strings (following
   `ChartOfAccountsPage.vue` and the receivables report pages)? Both are live precedents in the same
   codebase; this wave should not add a third, undecided approach.
5. **Live totals during invoice line editing.** §4.7.6/§4.8.6 forbid client-side computation of totals, but
   the create/edit endpoints only return authoritative totals on save. Is a "preview" request to the server
   before the first save acceptable (extra round-trips per keystroke/line change), or does the editor show
   no total until saved, or a visibly-marked "unsaved estimate"? This materially shapes the line editor's
   design and should be an Architect decision informed by this question.
6. **Company switch mid-edit.** ADR 0011 D3 mandates a reload-on-switch for company-scoped pages, proven
   for read-only report pages with nothing to lose. What happens to an open, unsaved customer or invoice
   edit form when the company switches mid-edit — discard silently, warn, or block the switch? None of the
   existing pages have a form open long enough for this to have come up before.
7. **Tax-code picker scope.** The invoice line editor needs a read-only tax-code picker (§4.7.3) sourced
   from the existing, in-scope `GET /companies/{company}/tax-codes`. Confirm this read-only consumption
   does not blur into "building tax-code screens," which is explicitly deferred.
8. **Per-line field-level validation mapping.** §4.7.9 assumes the invoice create/update 422 responses can
   be mapped back to a specific line and field. Confirm with the Architect whether `errors` keys are
   indexed per line (e.g. `lines.0.tax_code`) or flat, since that materially affects how precisely the line
   editor can highlight the offending field.

## 8. Risks & dependencies

- **No backend dependency risk**: both APIs are complete and tested (54 tests for customers, 63 for
  invoices per `ROADMAP.md`), so this wave has nothing to wait on functionally.
- **Pagination is a genuinely new UI element** (§7.1) — if built twice, independently, for the two list
  screens without a shared decision first, the two lists risk disagreeing on page-size defaults, control
  placement, or URL/query-param conventions (e.g. whether the page number is reflected in the route), which
  would then need reconciling later.
- **The invoice line editor is the most complex screen in this wave** by a wide margin — dynamic line
  add/remove, per-line discount type mutual exclusion, tax-code resolution, and the "server computes, UI
  never adds up" constraint together make it the highest-risk single component to under-scope. Recommend
  the Architect and QA pay it disproportionate attention relative to the simpler CRUD screens.
- **Two live, disagreeing precedents** (i18n usage; money formatting) mean this wave will extend whichever
  one it copies — silently widening the inconsistency if the choice is made ad hoc per screen rather than
  once, deliberately (§7.3, §7.4).
- **`capabilities`-based gating discipline** is easy to get wrong by reflex — copying a permission-only
  `PermissionGate` check (correct for "Add a customer") onto a state-and-permission action (issue, cancel,
  archive-with-balance) would silently reintroduce exactly the gap `SalesInvoicePolicy`'s docblock warns
  about for an owner. Recommend a specific code-review checklist item for this in Gate 2/3.
- **Company-switch-in-place** (ADR 0011 D3) is now owed by every new page in this wave, and "nothing
  enforces it" per that ADR's own Consequences section — a forgotten `watch` fails silently (wrong
  company's data shown), not loudly (no test failure), so QA's red-spec-first discipline (§6) is the only
  mechanism catching an omission before review.
