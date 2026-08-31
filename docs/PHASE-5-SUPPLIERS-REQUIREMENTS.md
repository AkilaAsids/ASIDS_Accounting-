# Phase 5 — Purchasing: Supplier Domain Foundation — Requirements

**Stage 2 requirements · awaiting Gate 1 approval.** Business Analyst deliverable, mirroring the
customer domain (Phase 3 Milestone 2) as the direct analogue for the supplier side. No
architecture, stack or module-placement decisions are made here — those are framed as open
questions for the Architect at Gate 2.

## 1. Objective & context

### 1.1 Phase 5 overall

Phase 5 is **Purchasing**: the mirror of the Sales side of the ledger. Where Sales lets a company
invoice the parties it sells to, Purchasing lets a company record what it owes to the parties it
buys from. `docs/ROADMAP.md` (lines 325–328) names it as firm scope — "Suppliers, bills, supplier
payments" — and records two things already committed in the schema in anticipation of it:

- `DocumentType`'s docblock (`src/Core/Accounting/Domain/Enums/DocumentType.php:14-15`): *"Purchase
  documents add their cases the same way"* — i.e. a `PurchaseInvoice`/bill `DocumentType` case,
  numbered independently, following exactly how `SalesInvoice` was added in Phase 3 Milestone 5.
- `tax_codes.input_account_id` (`src/Core/Sales/Database/Migrations/2026_03_03_000001_create_tax_codes_table.php:68,159`):
  present, nullable, foreign-keyed to `accounts`, and explicitly commented *"Reserved for
  purchasing, which arrives in a later phase. Unused by sales."* Purchasing is where input VAT
  finally gets a home.

Phase 5 is large — a full mirror of Sales' customer, tax-code-usage, invoice, and receipts
machinery, but for the buying side, plus supplier-specific concerns (bills, goods/services
received, supplier statements, WHT withheld *by* the company on payments to suppliers where
applicable under Sri Lankan law — distinct from Phase 4's WHT-on-receipt).

### 1.2 This slice

This document scopes Phase 5 overall (§2) but details requirements only for its **first slice: the
supplier domain foundation** — the direct mirror of the customer domain built in Phase 3 Milestone
2 (`src/Core/Sales/Domain/Models/Customer.php`, `CustomerService.php`, `CustomerPolicy.php`,
`CustomerFactory.php`, the `customers` migration and its RLS policy). Bills, purchase invoices,
supplier payments and any HTTP/front-end surface are named for sequencing (§2) but are explicitly
**out of scope** for this slice (§3).

## 2. Phase 5 wave breakdown (proposed)

Proposed ordering only — the Delivery Manager and Architect own final sequencing and may reorder,
split or merge these.

| Wave | Scope | Mirrors |
| --- | --- | --- |
| **Wave 6** | Supplier domain foundation (this slice): model, service, policy, permissions, factory. No HTTP. | Phase 3 Milestone 2 (Customer domain) |
| **Wave 7** | Bills / purchase invoices: `PurchaseInvoice` + lines, a new `DocumentType` case, gapless numbering, posting to the ledger (debit expense/input-VAT, credit accounts payable), draft/issue/cancel lifecycle. This is where `tax_codes.input_account_id` and input VAT are first read and posted. | Phase 3 Milestones 3–5 (tax-code usage, invoice modelling, issuing/posting) |
| **Wave 8** | Supplier payments: recording payment against one or more bills, allocation, unallocated credit/advances to suppliers, and — if the compliance pack requires it — WHT the company withholds *on* payments to suppliers (the mirror image of Phase 4's WHT-on-receipt, ADR 0017, but on the payable side). | Phase 4 (Payments and receivables, incl. ADR 0017 WHT) |
| *(HTTP + front-end)* | A REST surface and screens over the above, following the pattern Phase 3's Milestone 6 (`docs/SALES-HTTP-API-REQUIREMENTS.md`) set for customers/tax-codes/invoices. Not numbered as its own wave here — whether it rides with each wave or trails as a dedicated slice (as Sales did) is a DM/Architect sequencing call. | Phase 3 Milestone 6 |

Wave 6 (this slice) is deliberately domain-only, mirroring how Milestone 2 built the customer
domain with **no** `Presentation/Http` layer — that arrived three milestones later
(`docs/SALES-HTTP-API-REQUIREMENTS.md:1`, which records Sales as *"the only bounded context
without one"* until that work landed). See open question (e).

## 3. In / out of scope for this slice (Wave 6)

**In scope:**
- A `Supplier` domain model (or equivalent name — see open question (a) on module placement),
  its migration (table, FORCED RLS, constraints), and an Eloquent factory for tests.
- A `SupplierService` (or `SupplierService`-equivalent) covering create, update (clear-vs-omit
  semantics), deactivate/reactivate, archive/restore, delete — the same operation set
  `CustomerService` implements (`src/Core/Sales/Application/Services/CustomerService.php`).
- A `SupplierPolicy` mirroring `CustomerPolicy.php`: `viewAny`, `view`, `create`, `update`,
  `archive`, `delete`, `restore`, each checking both permission and company membership.
- New permission entries in `PermissionCatalogue` (proposed namespace `purchasing.suppliers.*`
  — see open question (b)) and their assignment into the relevant `RoleTemplate` role(s).
- The archive-with-balance protection, built against a seam (mirroring
  `ReceivableBalanceProbe`/`NoReceivables`, §4.6 below) — dormant until bills exist, exactly as
  the customer domain's receivables probe was dormant until Milestone 4's invoices landed.
- Wiring into a service provider and `ModuleServiceProvider`
  (`src/Core/Platform/Providers/ModuleServiceProvider.php`) — whichever module it lands in
  (open question (a)).

**Out of scope (this slice):**
- Bills / purchase invoices and any posting to the ledger (Wave 7).
- Supplier payments, allocation, WHT-on-payment (Wave 8).
- Any `Presentation/Http` layer — controllers, form requests, resources, routes (mirrors how
  Milestone 2 shipped no HTTP surface for customers; explicit open question (e)).
- Any front-end / screens.
- Supplier statements, aged-payables reporting (the payable-side mirror of
  `ReceivableReportService.php`) — depends on bills existing, so it is a Wave 7+ concern.
- Input VAT / `tax_codes.input_account_id` behaviour itself — this slice only carries fields a
  supplier might need to *support* that later (open question (d)); it does not implement VAT
  resolution or posting.

## 4. Functional requirements

Grounded throughout in the customer domain's model (`Customer.php`), service
(`CustomerService.php`), migration (`2026_03_02_000001_create_customers_table.php`), enum
(`CustomerStatus.php`), policy (`CustomerPolicy.php`) and factory (`CustomerFactory.php`). Every
divergence from the customer mirror is called out explicitly.

**As a purchasing/accounting user, I need to maintain a register of the suppliers my company buys
from, so that later bills, payments and reports have a party to name.**

### 4.1 Create a supplier

- **AC1 (Given** a user with `purchasing.suppliers.manage`, **when** they submit a name and
  optionally a code, **then** a new supplier is created with status Active, `archived_at` null,
  and — if no code was given — a system-generated code.) Mirrors
  `CustomerService::create()` (`CustomerService.php:47-73`), which defaults status to Active and
  `archived_at` to null explicitly rather than relying on a column default — the trap noted at
  `CustomerService.php:61-64` (`Model::shouldBeStrict()` throwing on a read-before-refresh of a
  defaulted column).
- **AC2 (Given** a code is supplied, **when** it duplicates an existing supplier's code in the
  same company (case-insensitively, live rows only), **then** the create is refused as a 409-style
  conflict naming the code.) Mirrors `assertCodeAvailable()` (`CustomerService.php:489-508`) and
  the partial unique index `customers_company_code_unique` (migration, lines 105-109) —
  case-insensitive, `WHERE deleted_at IS NULL`, so a code freed by a soft delete can be reused.
- **AC3 (Given** no code is supplied, **when** the supplier is created, **then** the system
  generates the next sequential code in a company-scoped series (e.g. `S-0001`), derived from the
  highest existing numeric suffix rather than a row count — mirroring `generateCode()`
  (`CustomerService.php:518-538`), which explains why: a deleted customer must not cause the next
  code to collide with one already issued, and gapless numbering is not owed here because no
  authority audits supplier codes for completeness (same reasoning as customer codes; open
  question (c) on the exact prefix/scheme).
- **AC4 (Given** the company is in scope, **when** a supplier is created, **then** it is scoped to
  that `company_id` — never the tenant alone — mirroring the migration's comment that
  *"Two companies in one workspace that both buy from the same shop keep separate supplier
  records"* (adapted from `2026_03_02_000001_create_customers_table.php:11-16`, which makes the
  identical statement about customers and receivable balances/terms belonging to one set of
  books).
- **AC5 (Given** a `branch_id` is supplied, **when** it belongs to a different company than the
  supplier's, **then** the create is refused.) Mirrors `resolveBranchId()`
  (`CustomerService.php:546-565`).

### 4.2 View a supplier

- **AC6 (Given** a user with `purchasing.suppliers.view` and company membership, **when** they
  request a single supplier, **then** they receive it; **when** they lack either, **then** they are
  refused.) Mirrors `CustomerPolicy::view()` (`CustomerPolicy.php:30-34`) — permission and company
  membership are independent checks and both must pass.

### 4.3 List / search suppliers

- **AC7 (Given** a company's suppliers, **when** a user lists them, **then** archived suppliers
  are excluded from the default "selectable" set while inactive ones remain findable —
  mirroring `scopeSelectable()` (`Customer.php:179-185`) and `CustomerStatus::isSelectable()`
  (`CustomerStatus.php:50-53`), whose docblock states the distinction plainly: *"Inactive customers
  are findable but not offered... Archived is hidden from pickers entirely."*
- **AC8 (Given** a partial name or code, **when** a user searches, **then** matches are returned
  via trigram search — mirroring `customers_name_trgm` / `customers_code_trgm` GIN indexes
  (migration lines 111-113), described there as *"Type-ahead without a table scan."*

### 4.4 Edit a supplier

- **AC9 (Given** an update payload, **when** a field is omitted, **then** its current value is
  left untouched; **when** a field is explicitly submitted as `null` where nullable, **then** it is
  cleared.) Mirrors `CustomerService::update()`'s `array_key_exists()` mechanism
  (`CustomerService.php:99-203`), documented there as the distinction "a whole-DTO signature cannot
  express" for fields that are "legitimately clearable" (e.g. `branch_id`, a default account
  override).
- **AC10 (Given** a supplier's code, **when** it has appeared on any bill, **then** the code can no
  longer change.) Direct mirror of the code-lock rule at `CustomerService.php:85-86,101-120`: *"the
  code appears on a document the customer has, and changing it would leave two identifiers for the
  same account."* — this rule is dormant (never fires) until Wave 7 gives bills a probe to ask.
- **AC11 (Given** every rule and resolution the update touches, **when** any one of them fails,
  **then** none of the change is applied — the in-memory model is left exactly as handed in.
  Mirrors the "every effective value is computed and every rule checked before the first
  assignment" discipline stated at `CustomerService.php:94-95`.

### 4.5 Deactivate / reactivate a supplier

- **AC12 (Given** an active, non-archived supplier, **when** deactivated, **then** it stops being
  offered on new bills but remains visible/findable — mirroring `deactivate()`
  (`CustomerService.php:208-221`) and `CustomerStatus::acceptsNewInvoices()`'s supplier-side
  equivalent (a supplier-side `acceptsNewBills()`, per `Customer::acceptsNewInvoices()`,
  `Customer.php:143-146`).
- **AC13 (Given** an archived supplier, **when** deactivation is attempted, **then** it is refused
  — restore first. Mirrors `CustomerService.php:210-215`.
- **AC14 (Given** any supplier, **when** reactivated, **then** status returns to Active and
  `archived_at` is cleared. Mirrors `reactivate()` (`CustomerService.php:223-230`).

### 4.6 Archive / restore a supplier — the balance protection

- **AC15 (Given** a supplier with an outstanding payable balance, **when** archiving is attempted,
  **then** it is refused, naming the balance owed.) Direct mirror of `archive()`
  (`CustomerService.php:239-262`): *"Archiving would remove them from the screens used to
  [pay/reconcile] it."* This is the **supplier-side mirror of the customer probe seam**: built now,
  against a `PayableBalanceProbe`-equivalent contract (mirroring
  `src/Core/Sales/Domain/Contracts/ReceivableBalanceProbe.php`), bound in Wave 6 to a
  `NoPayables`-equivalent (mirroring `src/Core/Sales/Infrastructure/NoReceivables.php`) that
  truthfully reports zero because no bill table exists yet. The seam is dormant, not absent — the
  same distinction `ReceivableBalanceProbe.php:17-23` draws, warning explicitly that *"a constraint
  with nothing to enforce it on day one is usually a constraint that never arrives."* Wave 7 binds
  the real implementation without a line of `SupplierService` changing, exactly as
  `SalesServiceProvider.php:43-54` describes for `EloquentReceivableBalanceProbe`.
- **AC16 (Given** a supplier with zero outstanding balance, **when** archived, **then** `status`
  becomes Archived and `archived_at` is set together, atomically — mirroring the CHECK constraint
  discipline at `2026_03_02_000001_create_customers_table.php:118-125`, which exists because *"a
  mass update moved `status` and left [the timestamp] behind"* on a different table in Phase 2.
- **AC17 (Given** a soft-deleted supplier, **when** restored, **then** the restore is refused if
  another supplier now holds its code (the unique index excludes soft-deleted rows, so a freed code
  can be taken meanwhile) — mirroring `restore()` (`CustomerService.php:302-342`), which resolves
  this as a named conflict rather than a raw constraint violation.

### 4.7 Delete a supplier

- **AC18 (Given** a supplier that has never appeared on a bill, **when** deletion is requested,
  **then** it is soft-deleted.) Mirrors `delete()` (`CustomerService.php:271-285`).
- **AC19 (Given** a supplier that has appeared on any bill — issued, cancelled or draft — **when**
  deletion is requested, **then** it is refused: *"the invoice[/bill] is a statutory record and it
  names its customer[/supplier]; the record has to outlive the relationship"* (direct quote,
  adapted, from `Customer.php:30-32` and `CustomerService.php:264-269,271-282`). Dormant until Wave
  7, via the same probe seam as AC15.

### 4.8 Supplier fields

Grounded in what `Customer` carries (`Customer.php:34-66`, migration lines 45-89), adjusted for the
payable side. Proposed, not final — see open question (d):

| Field | Customer analogue | Notes |
| --- | --- | --- |
| `code` | `code` | System-generated or user-supplied; unique per company, case-insensitive, live rows only |
| `name` | `name` | Required |
| `legal_name` | `legal_name` | Optional |
| `tax_identification_number` | `tax_identification_number` | Optional. On the supplier side this is also the field WHT-on-payment (Wave 8) would need for compliance filing — flagged as an open question (d), not decided here |
| `vat_registration_number` / `is_vat_registered` | same | Optional; same paired-validity rule the customer migration enforces (`customers_vat_registration_check`, migration lines 132-136) — a VAT number without registration, or vice versa, is one of them wrong |
| contact fields (`email`, `phone`, `website`, address block) | same | Direct mirror; no reason to diverge |
| `payment_terms_days` | `payment_terms_days` | The terms *this company* receives from the supplier — conceptually distinct from a customer's terms (which the company grants), but the same shape: days from bill date to due date, zero meaning due on receipt |
| `credit_limit` | `credit_limit` | **Open question:** does a supplier need a credit-limit analogue at all? Nothing on the payable side obviously mirrors "how much this party may owe us" — the company owes the supplier, not vice versa. Likely **not** carried forward; flagged rather than assumed (open question (d)) |
| default input/expense account (mirrors `receivable_account_id`) | `receivable_account_id` | **Open question (d):** should a supplier optionally carry a default account bills post to (a Liability-type "accounts payable" override, and/or a default Expense account for its bills), mirroring `resolveReceivableAccountId()`'s type check (`CustomerService.php:574-612`, which refuses a non-Asset account because *"every invoice's debit"* would misclassify)? The payable-side equivalent would refuse a non-Liability account for the AP override, by the identical reasoning |
| `notes` | `notes` | Direct mirror |
| `status` (Active/Inactive/Archived) | `status` (`CustomerStatus`) | Direct mirror — three states, not a boolean, for the identical reason `CustomerStatus.php:9-13` gives |

## 5. Non-functional requirements

- **Tenant + company isolation.** FORCED row-level security on the supplier table, following
  `2026_03_02_000002_enable_row_level_security_on_sales.php` exactly: `ENABLE` and `FORCE` (not
  merely `ENABLE`), reusing the platform's existing `asids_current_tenant_id()` /
  `asids_rls_bypassed()` functions — the migration's own comment records why FORCE matters: without
  it *"a role that owns the table runs with protection off"* and CI once caught RLS tests passing
  vacuously as a result. Ownership is per-**company**, not per-tenant alone, mirroring the
  customer table's stated reasoning (migration lines 11-16).
- **Immutability / audit.** The supplier model should apply the same `Auditable` concern
  `Customer` does (`Customer.php:69`, `auditOnly()`/`auditTags()` at lines 205-226), recording
  before/after on the fields an auditor would ask about — code, name, payment terms, any default
  account override, status. Soft-deleted (`SoftDeletes`), never hard-deleted once a bill names it
  (§4.7).
- **Naming.** Permission namespace proposed as `purchasing.suppliers.{view,manage}`, following the
  `{module}.{resource}.{action}` shape `PermissionCatalogue::sales()` establishes for
  `sales.customers.{view,manage}` (`PermissionCatalogue.php:235,239`) — open question (b) on the
  exact namespace and module.
- **Coding scheme.** Company-scoped, case-insensitive-unique, non-gapless code — mirroring
  `customers_company_code_unique` and the reasoning in `generateCode()`'s docblock
  (`CustomerService.php:510-517`) that no authority audits supplier codes for completeness, so a
  row-locked gapless sequence would buy nothing. Prefix scheme is an open question (c) — `S-` is
  the natural mirror of `C-` but collides in meaning with nothing existing, so it is safe to
  propose, not to assume.
- **Model strictness trap.** Whichever service creates a supplier must set `status` and
  `archived_at` explicitly rather than relying on column defaults, per the trap
  `CustomerService.php:61-64` names by cross-reference to Phase 1 (`must_change_password`) and
  Phase 2 (`is_closed`) hitting it independently — worth restating here since it is exactly the kind
  of thing a requirements document should flag before an engineer rediscovers it a third time.

## 6. Assumptions & open questions for the human (Gate 1)

Consolidated list — one set, for the Delivery Manager to carry to Gate 1. Framed as options; none
of these is decided by this document.

**(a) Module placement.** Does the supplier domain (and Phase 5 generally) live in a new
`src/Core/Purchasing/` bounded context with its own `ModuleServiceProvider` entry — matching the
platform's stated convention (`docs/architecture/overview.md:20-31`, four layers per module,
`ModuleServiceProvider` registering each in dependency order) and the precedent of Sales itself
being added as its own context depending on Accounting
(`src/Core/Platform/Providers/ModuleServiceProvider.php:43-45`) — or does it live under
`src/Core/Sales/` alongside customers/invoices, given how closely supplier CRUD mirrors customer
CRUD? A new module gives Purchasing its own dependency boundary (it would depend on Accounting the
same way Sales does, and arguably on nothing in Sales); folding it into Sales avoids duplicating
near-identical scaffolding. This is an Architect decision; flagged here because it changes where
every artefact in §4 physically lives.

**(b) Permission namespace and role assignment.** Proposed `purchasing.suppliers.{view,manage}`
(new module prefix) versus `sales.suppliers.{view,manage}` (if (a) resolves to Sales) — the two
questions are linked. Separately: which `RoleTemplate` role(s) get it? The customer permissions
(`sales.customers.{view,manage}`) are held today by `accountant` (`RoleTemplate.php:96-99`),
`bookkeeper` (`RoleTemplate.php:148-149`) and `viewer` read-only (`RoleTemplate.php:191`) — no
dedicated "purchasing" role exists yet. Does supplier management ride on the same three roles, or
does Phase 5 warrant a new role template (e.g. a "purchasing clerk" mirroring the bookkeeper split
between drafting and deciding)? Not decided here.

**(c) Supplier coding/numbering scheme.** `S-` prefix (mirroring `C-` for customers) is proposed
but not fixed — is there an existing convention or a client expectation (e.g. `SUP-`, `V-` for
"vendor") this should follow instead? Also: does the scheme need to be gapless for any Sri Lankan
compliance reason (unlike customer codes, which explicitly do not — migration/service reasoning
cited in §5), given that bills and WHT-on-payment reporting may care about supplier identification
in a way customer codes do not?

**(d) Which fields, especially:**
  - A default account on the supplier (the payable-side mirror of `receivable_account_id`) —
    should it be a Liability-type "accounts payable" override, a default Expense account for its
    bills, both, or neither at this stage? `resolveReceivableAccountId()`'s type-safety reasoning
    (`CustomerService.php:592-602`) would need a payable-side equivalent however this resolves.
  - `tax_identification_number` for later WHT/compliance use (Wave 8's supplier-side WHT, if the
    compliance pack requires it) — carry it now (as proposed in §4.8) so Wave 8 adds behaviour
    rather than a migration, mirroring how Phase 3 deliberately pre-provisioned
    `tax_codes.input_account_id` for this very phase? Or defer until Wave 8 defines the actual
    requirement?
  - Whether `credit_limit` has any supplier-side meaning at all (§4.8 currently assumes not).

**(e) Whether this slice is strictly domain-only (no HTTP).** This document assumes Wave 6 mirrors
Milestone 2 exactly — model, service, policy, permissions, factory, and nothing under
`Presentation/Http` — deferring the REST surface to a later, dedicated slice the way
`docs/SALES-HTTP-API-REQUIREMENTS.md` did for customers (three milestones after the domain
landed). Confirm this is still the intended shape for Phase 5, or whether Purchasing should ship
HTTP alongside the domain from the start (a deliberate departure from the Sales precedent, if so
chosen).

## 7. Risks & dependencies

- **Dormant-rule risk.** AC10, AC15 and AC19 all depend on a probe seam that reports "nothing to
  enforce" until Wave 7 ships bills — the exact pattern `ReceivableBalanceProbe.php:21-23` warns
  about: *"a constraint with nothing to enforce it on day one is usually a constraint that never
  arrives."* The seam interface must be written and tested now, in Wave 6, even though nothing yet
  calls it for real, or the risk repeats.
- **Module-placement dependency (open question (a)).** Everything in §4 is written module-agnostic
  on purpose, but the Architect's answer changes namespaces, service-provider wiring order, and
  possibly which existing Sales code (e.g. a shared address/contact form component the customer
  migration's comment already anticipates reusing, migration lines 21-22) is reusable versus
  duplicated. This should resolve at Gate 2 before Wave 6 implementation starts.
- **Sequencing dependency on Wave 7.** Wave 7 (bills) is where `tax_codes.input_account_id` and a
  new `DocumentType` case are first used — Wave 6 must not invent supplier-side tax or posting
  behaviour that Wave 7's design should own instead.
- **Naming collision risk.** If (a) resolves to "under Sales," permission and route naming
  (`sales.suppliers.*` alongside `sales.customers.*`) risks reading as though suppliers are a kind
  of customer relationship rather than its mirror opposite; worth the Architect's explicit
  attention rather than falling out of a default choice.
- **Scope-creep risk.** Because the customer domain is such a close mirror, there is a temptation
  to over-build Wave 6 with fields or behaviour that only Wave 7/8 actually need (e.g. WHT
  configuration, AP account resolution logic). §3 and open question (d) exist to keep this slice
  bounded to what Wave 6 alone requires.

---

*Prepared by the Business Analyst. Every requirement above cites the customer-domain source it
mirrors; no architecture, stack or module-placement decision is made — those are Gate 2 (Architect)
questions, listed in §6 for the human to answer at Gate 1.*
