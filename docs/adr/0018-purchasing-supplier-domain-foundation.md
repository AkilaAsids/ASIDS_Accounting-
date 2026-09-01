# ADR 0018 — Purchasing bounded context, and the supplier domain foundation

- **Status:** Proposed — written for Gate 2 review (Minions Phase 5 / Wave 6, Stage 3 Architecture)
- **Date:** 2026-08-31
- **Branch:** `feature/phase5-suppliers` (off `main`)
- **Supersedes / relates to:** mirrors the Phase 3 Milestone 2 customer domain (ADR 0008 records the
  customer update semantics this reuses). Wave 7 (bills) and Wave 8 (supplier payments / WHT-on-payment)
  are named for sequencing only and are out of scope here.

## How to read this record

Every decision carries one of three labels:

- **(Gate-1 APPROVED)** — bound by `docs/PHASE-5-SUPPLIERS-REQUIREMENTS.md` → "Gate 1 decisions —
  APPROVED 2026-08-31" (lines 340-351). Not reopened here.
- **(Gate-2 PROPOSED)** — the Architect's concrete realisation of a Gate-1 decision (exact paths,
  names, sort orders, filenames, method signatures). These are what Gate 2 confirms.
- **(UNRESOLVED — needs human approval)** — a genuine fork this ADR will not decide silently.

There are **no UNRESOLVED forks** in this slice: Gate 1 closed every open question the Business
Analyst raised (requirements §6). The Gate-2 items in §H are confirmations of shape, not unresolved
choices.

## Context

Phase 5 is Purchasing — the payable-side mirror of Sales. Wave 6 (this slice) builds only the
**supplier master-data domain**: the direct analogue of the customer domain shipped in Phase 3
Milestone 2 (`src/Core/Sales/Domain/Models/Customer.php`,
`src/Core/Sales/Application/Services/CustomerService.php`,
`src/Core/Sales/Policies/CustomerPolicy.php`, `database/factories/CustomerFactory.php`, the
`customers` migration and its RLS policy). No bills, no ledger posting, no HTTP, no reports — those
are Waves 7-8 and a later HTTP slice, exactly as customers preceded their own HTTP surface by three
milestones (`docs/SALES-HTTP-API-REQUIREMENTS.md:1`).

The design principle for this ADR is **mirror, do not invent**. Where the customer domain made a
decision, the supplier domain makes the identical one and cites it. Where Gate 1 directed a
divergence (a new module, deferred fields, `S-` codes), that divergence is called out explicitly.

## A. The Purchasing bounded context

### A1 — A new `src/Core/Purchasing/` module (Gate-1 APPROVED)

Suppliers land in a **new bounded context**, not folded under Sales (requirements line 344). It
depends on Accounting the way Sales does, and **does not depend on Sales**. This matches the
platform's stated convention — four layers per module, one `ModuleServiceProvider` line per context
(`docs/architecture/overview.md:33-51`) — and the precedent of Sales itself being added as its own
Accounting-dependent context (`src/Core/Platform/Providers/ModuleServiceProvider.php:43-45`).

### A2 — Layer layout and namespace (Gate-2 PROPOSED)

Namespace root `Asids\Core\Purchasing\…`, mirroring `Asids\Core\Sales\…` (autoloaded by the existing
`"Asids\\": "src/"` PSR-4 map in `composer.json`; no composer change). The four-layer split mirrors
Sales one-for-one, including Sales' habit of placing policies in a top-level `Policies/` directory
rather than under `Domain/` or `Presentation/` (`src/Core/Sales/Policies/CustomerPolicy.php`):

| Layer | File (create) | Mirrors |
| --- | --- | --- |
| `Domain/Models/` | `Supplier.php` | `Sales/Domain/Models/Customer.php` |
| `Domain/Enums/` | `SupplierStatus.php` | `Sales/Domain/Enums/CustomerStatus.php` |
| `Domain/Contracts/` | `PayableBalanceProbe.php` | `Sales/Domain/Contracts/ReceivableBalanceProbe.php` |
| `Application/DTOs/` | `SupplierData.php` | `Sales/Application/DTOs/CustomerData.php` |
| `Application/Services/` | `SupplierService.php` | `Sales/Application/Services/CustomerService.php` |
| `Infrastructure/` | `NoPayables.php` | `Sales/Infrastructure/NoReceivables.php` |
| `Policies/` | `SupplierPolicy.php` | `Sales/Policies/CustomerPolicy.php` |
| `Providers/` | `PurchasingServiceProvider.php` | `Sales/Providers/SalesServiceProvider.php` |
| `Database/Migrations/` | two files (see §B) | `Sales/Database/Migrations/2026_03_02_000001…` + `…000002…` |
| `database/factories/` (app-level) | `SupplierFactory.php` (`Database\Factories\` namespace) | `database/factories/CustomerFactory.php` |

Wave 7 will add `Infrastructure/EloquentPayableBalanceProbe.php` and a `Presentation/Http/` tree; this
slice ships neither.

### A3 — Module registration order (Gate-2 PROPOSED)

Insert `PurchasingServiceProvider::class` into `ModuleServiceProvider::MODULES` **immediately after
`SalesServiceProvider::class`** (currently line 45), before `SettingsServiceProvider::class`, with the
matching `use Asids\Core\Purchasing\Providers\PurchasingServiceProvider;` import.

Order rationale, mirroring the comment Sales carries at
`src/Core/Platform/Providers/ModuleServiceProvider.php:43-45`: Purchasing must register **after
Accounting** (Wave 7's bills will post through Accounting's `PostingService`, and Wave 7's supplier
account resolution will reference `Account`). It has **no dependency on Sales in either direction**, so
its position relative to Sales is free; placing it directly after Sales keeps the three
Accounting-dependent business contexts grouped and reads as the mirror it is. `bootstrap/providers.php`
does not change (`ModuleServiceProvider` docblock, lines 17-28).

### A4 — `PurchasingServiceProvider` (Gate-2 PROPOSED)

Mirrors `SalesServiceProvider` (`src/Core/Sales/Providers/SalesServiceProvider.php:37-109`), reduced to
this slice:

- `register()`:
  - `$this->app->singleton(SupplierService::class);`
  - `$this->app->bind(PayableBalanceProbe::class, NoPayables::class);` — **the dormant binding**. This
    is the one deliberate difference from the *current* `SalesServiceProvider`, which binds the real
    `EloquentReceivableBalanceProbe` (line 55) because invoices now exist. Purchasing is at the
    "Milestone 2" state: no bills table exists, so it binds `NoPayables`, exactly as Sales bound
    `NoReceivables` at Milestone 2 before Milestone 5 moved the binding. Wave 7 flips this one line to
    `EloquentPayableBalanceProbe::class` and `SupplierService` does not change (see §E).
- `boot()`:
  - `$this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');`
  - `Relation::morphMap([Supplier::MORPH_ALIAS => Supplier::class]);` — not decoration:
    `Supplier` applies `Auditable`, and an audit entry for an unmapped class throws
    (`SalesServiceProvider.php:89-108`).
  - `Gate::policy(Supplier::class, SupplierPolicy::class);`

## B. The `suppliers` schema (Gate-2 PROPOSED, realising Gate-1 decisions 3 & 4)

### B1 — Migration files

Two migrations under `src/Core/Purchasing/Database/Migrations/`, timestamped to sort **after** every
existing migration on this branch (latest is `2026_03_07_000001`) and after the Phase 1 tenant-context
functions the RLS policy reuses:

- `2026_09_01_000001_create_suppliers_table.php`
- `2026_09_01_000002_enable_row_level_security_on_purchasing.php`

The `2026_09_01` prefix is a proposal; the load-bearing invariant is only that both sort last and after
`branches`/`companies`/`users` (the tables `suppliers` foreign-keys to) and after
`2026_01_08_000001_enable_row_level_security.php` (which defines `asids_current_tenant_id()` /
`asids_rls_bypassed()`). A same-day `2026_08_31_…` prefix would satisfy the invariant equally.

### B2 — Columns

A field-by-field mirror of the `customers` table
(`src/Core/Sales/Database/Migrations/2026_03_02_000001_create_customers_table.php:28-100`), **less the
two deferred columns**:

| Column | Type / rule | Mirror source | Change |
| --- | --- | --- | --- |
| `id` | `uuid` primary | migration:29 | — |
| `tenant_id` | `foreignUuid` → `tenants`, cascade | migration:33-35 | — |
| `company_id` | `foreignUuid` → `companies`, cascade | migration:37-39 | — |
| `branch_id` | `foreignUuid` nullable → `branches`, `nullOnDelete` | migration:43 | — (advisory dimension, kept) |
| `code` | `string(32)` | migration:49 | `S-` series (§B4) |
| `name` | `string` | migration:50 | — |
| `legal_name` | `string` nullable | migration:51 | — |
| `tax_identification_number` | `string(64)` nullable | migration:54 | **kept (Gate-1 dec. 4)** — pre-provisions Wave 8 WHT/compliance |
| `vat_registration_number` | `string(64)` nullable | migration:55 | — |
| `is_vat_registered` | `boolean` default `false` | migration:56 | — |
| `email` / `phone(32)` / `website` | nullable | migration:59-61 | — |
| `address_line_1/2`, `city(96)`, `district(96)`, `postal_code(24)`, `country_code` char(2) | nullable | migration:64-69 | — |
| `payment_terms_days` | `unsignedSmallInteger` default `30` | migration:74 | — (terms this company *receives*; same shape) |
| `notes` | `text` nullable | migration:85 | — |
| `status` | `string(16)` default `'active'` | migration:88 | — |
| `archived_at` | `timestampTz` nullable | migration:89 | — |
| `created_by_id` | `foreignUuid` nullable → `users`, `nullOnDelete` | migration:91 | — |
| timestamps / soft-delete | `timestampsTz()` + `softDeletesTz()` | migration:93-94 | — |
| ~~`credit_limit`~~ | — | migration:78 | **DROPPED (Gate-1 dec. 4)** — deferred to Wave 7 |
| ~~`receivable_account_id`~~ analogue (AP/expense account) | — | migration:83 | **DROPPED (Gate-1 dec. 4)** — deferred to Wave 7 |

### B3 — Indexes, uniqueness, CHECK constraints

Mirror the customer migration one-for-one (renaming `customers_*` → `suppliers_*`), **dropping only the
credit-limit check** since the column is gone:

- Indexes: `['tenant_id', 'company_id', 'status']` and `['company_id', 'branch_id']` (migration:98-99) —
  leading with `tenant_id` per platform convention and to match the RLS predicate.
- `suppliers_company_code_unique`: `UNIQUE (company_id, upper(code)) WHERE deleted_at IS NULL`
  (migration:105-109) — case-insensitive, live rows only, so a soft-deleted code is reusable and codes
  are unique **per company**, not per workspace.
- Trigram GIN: `suppliers_name_trgm`, `suppliers_code_trgm` (migration:112-113) — type-ahead without a
  table scan.
- `suppliers_status_check`: `status IN ('active','inactive','archived')` (migration:115-116).
- `suppliers_archived_check`: `(status = 'archived') = (archived_at IS NOT NULL)` (migration:121-125) —
  status and the timestamp cannot disagree; Phase 2's `fiscal_periods` mass-update taught this.
- `suppliers_vat_registration_check`: `NOT is_vat_registered OR vat_registration_number IS NOT NULL`
  (migration:132-136).
- ~~`customers_credit_limit_check`~~ (migration:128) — **not created** (no `credit_limit` column).
- `COMMENT ON TABLE/COLUMN` statements adapted to the payable side (owned by a company because "two
  companies in one workspace that both buy from the same shop keep separate supplier records",
  adapting migration:11-16 / 138-140).

### B4 — Coding: `S-` prefix, non-gapless, company-scoped (Gate-1 APPROVED; realisation Gate-2 PROPOSED)

`S-0001` style, mirroring the customer `C-` convention (requirements line 346). Generated from the
highest existing numeric suffix, not a row count, so a deleted supplier cannot cause the next code to
collide (`CustomerService::generateCode()`, `CustomerService.php:518-538`). **Not gapless, and it does
not need to be** — gapless numbering is owed to documents like bills (Wave 7), never to master-data
codes; no authority audits supplier codes for completeness. Service realisation in §C.

### B5 — FORCED row-level security (Gate-1 APPROVED; realisation Gate-2 PROPOSED)

A dedicated RLS migration mirroring
`src/Core/Sales/Database/Migrations/2026_03_02_000002_enable_row_level_security_on_sales.php:20-55`
with a local `TABLES = ['suppliers']`. For the `suppliers` table it runs, in order: `ENABLE ROW LEVEL
SECURITY`, then **`FORCE ROW LEVEL SECURITY`** (the line that makes the policy apply to the table's
owner too — without it CI once ran RLS tests vacuously), then a `suppliers_tenant_isolation` policy
`FOR ALL USING/WITH CHECK (asids_rls_bypassed() OR tenant_id = asids_current_tenant_id())`, **reusing
the platform's existing tenant-context functions** rather than redefining them. Each module owns its RLS
migration (Sales did not edit the Phase 1
`2026_01_08_000001_enable_row_level_security.php`), so there is **no central table list to amend** and
`RowLevelSecurity::isEnforced('suppliers')` (`src/Core/Tenancy/Infrastructure/RowLevelSecurity.php:74`)
works unchanged.

### B6 — The `shouldBeStrict()` status/`archived_at` default trap (Gate-1 APPROVED — restated)

`status` and `archived_at` carry DB defaults, but an unsaved model returns `null` for a defaulted
column and reading it back before a refresh throws under `Model::shouldBeStrict()` — the trap Phase 1
hit on `must_change_password` and Phase 2 on `is_closed` (`CustomerService.php:61-64`). Therefore both
the **service** (`SupplierService::create()`, §C) and the **factory** (`SupplierFactory`, §G) set
`status = SupplierStatus::Active` and `archived_at = null` **explicitly**, exactly as
`CustomerFactory.php:43-46` does. This is called out in the schema section because it is a schema-shaped
trap; it is enforced in code, not the column.

## C. `Supplier` model + `SupplierService`

### C1 — `SupplierStatus` enum (Gate-2 PROPOSED)

Byte-for-byte mirror of `CustomerStatus` (`src/Core/Sales/Domain/Enums/CustomerStatus.php:18-54`):
three cases `Active`/`Inactive`/`Archived`, `label()`, `isSelectable()` (true unless `Archived`), and
the invoice-side predicate renamed for the payable side: `acceptsNewBills()` (returns `$this ===
self::Active`), the analogue of `CustomerStatus::acceptsNewInvoices()` (lines 39-42) and the name
requirements AC12 specifies (requirements line 168).

### C2 — `Supplier` model (Gate-2 PROPOSED)

Mirror of `Customer` (`src/Core/Sales/Domain/Models/Customer.php`):

- Traits `Auditable`, `BelongsToTenant`, `HasFactory`, `HasUuids`, `SoftDeletes` (lines 69-76);
  `MORPH_ALIAS = 'supplier'` (line 78); `protected $table = 'suppliers'`.
- `$fillable` (lines 82-100) — the **identical 16-field list**, which already excludes the deferred
  columns and the guarded lifecycle fields, and already includes `tax_identification_number`.
- Relations `company()`, `branch()`, `createdBy()` (lines 105-138). **No `receivableAccount()`
  analogue** (deferred).
- `acceptsNewBills()` (mirrors `acceptsNewInvoices()`, lines 143-146), `isArchived()`,
  `dueDateFor(CarbonImmutable $billDate): CarbonImmutable` (mirrors `dueDateFor()`, lines 159-162 — due
  date from the supplier's terms).
- Scopes `scopeForCompany()`, `scopeSelectable()` (Active + Inactive), `scopeActive()` (lines 168-194).
- `auditTags(): ['purchasing', 'supplier']` (mirrors lines 223-226).
- `auditOnly()` (mirrors lines 205-218) → `['code', 'name', 'legal_name', 'vat_registration_number',
  'is_vat_registered', 'payment_terms_days', 'status']`, **dropping** the deferred `credit_limit` and
  `receivable_account_id`. **(Gate-2 PROPOSED — confirm §H item 5)**: recommend **adding
  `tax_identification_number`** to this list (a reasoned divergence from the customer mirror, which
  omits it) because the TIN was retained specifically for WHT/compliance, and a changed supplier TIN is
  precisely the sort of thing an auditor asks about. Kept as a flagged proposal rather than assumed.
- `casts()` (lines 231-242) → `status => SupplierStatus::class`, `is_vat_registered => 'boolean'`,
  `payment_terms_days => 'integer'`, `archived_at => 'immutable_datetime'`. **No `credit_limit` cast**
  (deferred).

### C3 — `SupplierData` DTO (Gate-2 PROPOSED)

Mirror of `CustomerData` (`src/Core/Sales/Application/DTOs/CustomerData.php:17-96`), **removing the
`creditLimit` and `receivableAccountId` constructor parameters and their `fromArray()` keys**. `code`
stays nullable (service generates one); `status` stays absent (creation is always Active; transitions go
through named methods — the DTO docblock's reasoning at lines 13-16). `fromArray()` keeps the
empty-string-to-null normalisation (`optionalString()`, lines 85-96).

### C4 — `SupplierService` (Gate-2 PROPOSED)

Mirror of `CustomerService` (`src/Core/Sales/Application/Services/CustomerService.php`), **less the two
deferred resolvers**. Constructor takes the probe: `public function __construct(private
PayableBalanceProbe $payables) {}` (mirrors line 45). `GENERATED_CODE_PATTERN = '^S-[0-9]{1,18}$'`
(mirrors line 43, `C-` → `S-`).

Public methods, one-for-one:

| Method | Mirror | Notes / divergence |
| --- | --- | --- |
| `create(Company, SupplierData, ?string $createdById = null)` | lines 47-73 | sets `status=Active`, `archived_at=null` explicitly (§B6); `resolveBranchId()`; code via `assertCodeAvailable()`/`generateCode()`. **No** `receivable_account_id` / `credit_limit` assignment. |
| `update(Supplier, array $attributes)` | lines 99-203 | `array_key_exists()` clear-vs-omit (ADR 0008). Recognised keys mirror the customer set **minus** `credit_limit`, `receivable_account_id`. Keeps: code-lock, VAT cross-rule, `payment_terms_days >= 0`, `country_code` upper-casing, `branch_id` resolution, validate-before-assign inside `DB::transaction`. |
| `deactivate(Supplier)` | lines 208-221 | refused if archived (`supplier-archived`). |
| `reactivate(Supplier)` | lines 223-230 | status → Active, clears `archived_at`. |
| `archive(Supplier)` | lines 239-262 | **balance protection via the probe** (§E); sets `status`/`archived_at` together. |
| `restore(Supplier)` | lines 302-342 | re-take check inside a transaction → `supplier-code-taken-on-restore`. |
| `delete(Supplier)` | lines 271-285 | soft-delete; refused once any bill names it (§E). |
| `outstandingBalance(Supplier): numeric-string` | lines 352-355 | delegates to the probe. |

Private helpers mirror lines 364-617, **dropping `resolveCreditLimit()` (456-484) and
`resolveReceivableAccountId()` (574-612)**. `generateCode()` returns `sprintf('S-%04d', $next)`
(mirrors line 537). `isDuplicateCodeViolation()` matches the substring `'suppliers_company_code_unique'`
(mirrors line 440). `save()` maps a code race to `ResourceConflict::duplicate('supplier', 'code', …)`
(mirrors lines 412-425). Problem codes rename `customer-*` → `supplier-*` throughout
(`supplier-code-locked`, `supplier-has-outstanding-balance`, `supplier-has-bills`,
`supplier-not-deleted`, `supplier-code-blank`); `branch-outside-company`,
`vat-registration-number-required`, `negative-payment-terms` keep their names.

**(Gate-2 PROPOSED — copy only)** `archive()`'s user-facing message flips direction (the company owes the
supplier, not vice versa): e.g. *"You still owe supplier %s %s. Archiving would remove them from the
screens used to pay it. Settle the balance first."* Wording is a Gate-2 detail, not a structural
decision.

## D. Authorization

### D1 — `purchasing.suppliers.{view,manage}` in the catalogue (Gate-1 APPROVED; realisation Gate-2 PROPOSED)

Add a new `purchasing` group to `PermissionCatalogue`
(`src/Core/Authorization/Domain/Catalogue/PermissionCatalogue.php`), mirroring the `sales()` group
(lines 232-271):

- New method `private static function purchasing(): array` returning two `PermissionDefinition`s, and
  spread `...self::purchasing()` in `all()` (lines 33-45) **immediately after `...self::sales()`** (line
  42), before `...self::platform()`.
- `new PermissionDefinition('purchasing', 'suppliers', 'view', 'View suppliers', 'See the suppliers a
  company buys from and their terms.', sortOrder: 10)`
- `new PermissionDefinition('purchasing', 'suppliers', 'manage', 'Manage suppliers', 'Add, edit, archive
  and restore suppliers.', sensitive: true, sortOrder: 20)`

`sortOrder` restarts at 10/20 within the new group, matching every other group's local numbering
(sales is 10-90, lines 235-269). The name composes as `{module}.{resource}.{action}` per
`PermissionDefinition::name()` (`PermissionDefinition.php:43-46`), which the `permissions` CHECK
constraint enforces.

**(Gate-2 PROPOSED — confirm §H item 3)** `manage` is marked `sensitive: true`, mirroring
`sales.customers.manage` (line 239). The customer marker's original justification (credit limit +
payment terms) is *partly* deferred here (no `credit_limit` this slice), so a reviewer could argue for
non-sensitive. Recommend **keeping `sensitive: true`**: `payment_terms_days` and the compliance-bearing
TIN still ride on `manage`, mirror-consistency is worth more than a marginal downgrade, and the flag is
trivially reversible in Wave 7 when the payable account and credit limit arrive. Flagged rather than
assumed.

### D2 — Role grants (Gate-1 APPROVED; realisation Gate-2 PROPOSED)

Granted to the **same roles that hold `sales.customers.*` today** (requirements line 345), by adding
literal permission strings to `RoleTemplate::all()`
(`src/Core/Authorization/Domain/Catalogue/RoleTemplate.php`):

| Role | Grant | Insert after (mirrors customer grant) |
| --- | --- | --- |
| `accountant` (level 60) | `purchasing.suppliers.view` + `purchasing.suppliers.manage` | lines 98-99 |
| `bookkeeper` (level 40) | `purchasing.suppliers.view` + `purchasing.suppliers.manage` | lines 148-149 |
| `viewer` (level 10) | `purchasing.suppliers.view` (read-only) | line 191 |

No new role (Gate-1 dec. 2). The `owner` template inherits automatically: it is `['*']`
(RoleTemplate.php:48) and `resolvedPermissions()` expands to `tenantGrantableNames()` (lines 235-247),
which now includes the two new non-platform capabilities — so
`PrivilegeEscalationTest`'s "owner gets every grantable capability" assertion
(`tests/Feature/Authorization/PrivilegeEscalationTest.php:294-299`) stays green with no edit.

### D3 — `SupplierPolicy` (Gate-2 PROPOSED)

Mirror of `CustomerPolicy` (`src/Core/Sales/Policies/CustomerPolicy.php:23-69`) with
`sales.customers.*` → `purchasing.suppliers.*`: `viewAny` (view), `view`/`update`/`archive`/`delete`/
`restore` each checking **both** `purchasing.suppliers.{view|manage}` **and**
`$user->canAccessCompany($supplier->company_id)` — permission and membership are independent questions
and both must pass (policy docblock, lines 10-22). `archive()` and `restore()` delegate to `update()`;
`delete()` is kept separate (its own method) so a later stricter requirement has somewhere to go (lines
47-63). Wired via `Gate::policy(Supplier::class, SupplierPolicy::class)` in the provider boot (§A4).

## E. The `PayableBalanceProbe` seam (Gate-1 APPROVED; realisation Gate-2 PROPOSED)

The archive/delete/code-lock rules depend on bills, which do not exist until Wave 7. They are built
**now against a seam**, dormant but present — the exact pattern
`ReceivableBalanceProbe.php:9-24` documents, warning that *"a constraint with nothing to enforce it on
day one is usually a constraint that never arrives."*

- **`Domain/Contracts/PayableBalanceProbe.php`** — interface mirroring
  `ReceivableBalanceProbe` (`src/Core/Sales/Domain/Contracts/ReceivableBalanceProbe.php:25-44`):
  - `outstandingBalance(Supplier $supplier): string` (numeric-string at the ledger's scale) — what the
    company **still owes** this supplier.
  - `hasAnyBill(Supplier $supplier): bool` — whether **any** bill (draft, issued, or cancelled) names
    the supplier; the analogue of `hasAnyInvoice()` (lines 37-43). Distinct from owing money: a
    fully-paid bill owes nothing yet still blocks deletion because the document names the supplier.
    **(Gate-2 PROPOSED — name)** `hasAnyBill` reads correctly for the payable side and matches the
    requirements' "bill" vocabulary (AC10/AC19); `hasAnyInvoice` would be the literal mirror.
- **`Infrastructure/NoPayables.php`** — dormant binding mirroring `NoReceivables`
  (`src/Core/Sales/Infrastructure/NoReceivables.php:20-34`): `outstandingBalance()` returns `'0.0000'`,
  `hasAnyBill()` returns `false`. Not a stub — an accurate statement of a schema with suppliers and no
  bills table.
- **Binding** in `PurchasingServiceProvider::register()`: `bind(PayableBalanceProbe::class,
  NoPayables::class)` (§A4). Wave 7 adds `EloquentPayableBalanceProbe` (the analogue of
  `src/Core/Sales/Infrastructure/EloquentReceivableBalanceProbe.php`) and flips this **one line** —
  the archive, delete and code-lock rules begin to bite with **not a line of `SupplierService`
  changing**, exactly as `SalesServiceProvider.php:43-55` describes for the receivables seam.

`NoPayables` is kept, never deleted, after Wave 7: it is the honest answer for any context with no bill
table, and a test wanting "this supplier owes nothing" binds it directly (mirroring the note at
`SalesServiceProvider.php:50-54`).

## F. Build stages (test-first / RED-first per the gate policy)

Each stage is an independently reviewable RED→GREEN cycle: QA writes the failing tests first, then the
engineer implements to green. No stage folds another's reviewer gate.

| Stage | Deliverable | Files (create unless noted) | Test-first artefact | Green when |
| --- | --- | --- | --- | --- |
| **1. Module skeleton + schema** | Minimal `PurchasingServiceProvider` (boot: `loadMigrationsFrom` only), registered in `ModuleServiceProvider`; both migrations (`suppliers` + FORCED RLS). | `Providers/PurchasingServiceProvider.php`; `Database/Migrations/…000001…`, `…000002…`; **edit** `ModuleServiceProvider.php` | `SupplierSchemaTest`: columns/indexes/CHECKs exist; `RowLevelSecurity::isEnforced('suppliers')`; raw-SQL cross-tenant read hidden + write refused. | migrations run; RLS enforced |
| **2. Enum + Model + Factory** | `SupplierStatus`, `Supplier`, `SupplierFactory`; add morph alias to provider boot. | `Domain/Enums/SupplierStatus.php`; `Domain/Models/Supplier.php`; `database/factories/SupplierFactory.php`; **edit** provider | `SupplierModelTest` / `SupplierFactoryTest`: casts, `scopeSelectable`/`scopeActive`, `acceptsNewBills`, `dueDateFor`, factory states, morph-alias round-trip. | model + factory behave |
| **3. Probe seam (dormant)** | `PayableBalanceProbe` + `NoPayables`; bind in provider register. | `Domain/Contracts/PayableBalanceProbe.php`; `Infrastructure/NoPayables.php`; **edit** provider | `PayableBalanceProbeTest` (dormant): bound instance is `NoPayables`; `outstandingBalance` = `'0.0000'`; `hasAnyBill` = `false`. | dormant seam bound |
| **4. DTO + Service** | `SupplierData`, `SupplierService` (singleton in provider). | `Application/DTOs/SupplierData.php`; `Application/Services/SupplierService.php`; **edit** provider | `SupplierTest`: the full CRUD/lifecycle suite (§G), incl. archive-with-balance via a bound test probe. | all service rules pass |
| **5. Authorization** | `purchasing()` catalogue group + spread; three role grants; `SupplierPolicy`; `Gate::policy` in provider boot. | `Policies/SupplierPolicy.php`; **edit** `PermissionCatalogue.php`, `RoleTemplate.php`, provider | `SupplierAuthorizationTest`: catalogue declares both + `manage` sensitive/`view` not; accountant+bookkeeper manage, viewer view-only; policy permission×membership matrix. | authz green; owner inheritance intact |

## G. Test strategy (QA writes tests before implementation)

Mirror the customer domain's own suites — `tests/Feature/Sales/CustomerTest.php`,
`ReceivableBalanceProbeTest.php`, `TaxCodeAuthorizationTest.php` — into
`tests/Feature/Purchasing/`, dropping only the deferred-field cases:

- **Creation & coding** (mirror `CustomerTest.php:73-179`): generated `S-0001`; numbering from the
  highest suffix not a count (delete-then-create yields `S-0003`); supplied code accepted;
  case-insensitive duplicate refused (`ResourceConflict`); blank code refused; **another company in the
  same workspace reuses a code** (per-company uniqueness); oversized numeric suffix does not break
  generation; blank optionals → null; country code upper-cased. **Omit** the `credit_limit` cases.
- **Validation** (mirror `CustomerTest.php:181-250`): VAT-registered requires a number; negative payment
  terms refused; zero-day terms = due on receipt; `dueDateFor()` arithmetic. **Omit** credit-limit
  validation and the receivable-account tests (`CustomerTest.php:252-303`) entirely.
- **Lifecycle & the dormant balance rule** (mirror `CustomerTest.php:305-375`): deactivate without
  hiding; archive when nothing owed; **refuse archive when a bound probe reports a balance**; archive
  once settled; reactivate clears the timestamp; refuse deactivating an archived supplier; DB refuses a
  `status`/`archived_at` mismatch (the CHECK). Use the same `withPayables($balance, $hasBill)` helper
  shape as `withReceivables()` (`CustomerTest.php:50-71`) — bind a fake `PayableBalanceProbe` and
  re-resolve the singleton.
- **Delete / restore** (mirror `CustomerTest.php:377-470`): soft-delete a mistake; **refuse delete when
  the probe reports `hasAnyBill`**; restore when the code is free; refuse + name the conflict when the
  code was reused; a refused restore changes nothing (shared transaction); code freed for reuse after
  soft-delete.
- **Update semantics** (mirror `CustomerTest.php:472-691`): change details; code change allowed until a
  bill exists, then refused (`supplier-code-locked`); duplicate code refused; keep-own-code permitted;
  **clear-vs-omit for `branch_id`** (present-null clears, omitted untouched); VAT cross-rule on
  *effective* values; validate-before-assign leaves the in-memory model clean. **Omit** the
  `credit_limit` / `receivable_account_id` clear-vs-omit cases.
- **Audit** (mirror `CustomerTest.php:693-719`): a change and the archive transition land in
  `AuditLog` under `Supplier::MORPH_ALIAS`.
- **RLS / tenant isolation** (mirror `CustomerTest.php:721-778`): raw-SQL reads hide another
  workspace's suppliers; a restore stays inside the tenant; a cross-tenant write throws — each guarded
  by `->skip(fn () => ! RowLevelSecurity::isEnforced('suppliers'), …)` so the suite never passes
  vacuously under a bypassing role.
- **Probe seam, dormant** (mirror the *binding* assertion of `ReceivableBalanceProbeTest.php:89-94`):
  assert the bound `PayableBalanceProbe` is `NoPayables` and reports zero/false. The full
  bills-driven probe suite is Wave 7's, when `EloquentPayableBalanceProbe` exists.
- **Authorization** (mirror `TaxCodeAuthorizationTest.php:76-115` and `CustomerTest.php:780-842`): the
  catalogue declares both capabilities and marks `manage` sensitive / `view` not; accountant and
  bookkeeper get `manage`, viewer gets `view` only; the policy enforces permission **and** membership
  (an accountant with no membership is refused).

## Alternatives considered

- **Fold suppliers under `src/Core/Sales/`** — rejected at Gate 1 (requirements line 344): it would read
  as though suppliers are a kind of customer, and it would put `purchasing`-namespaced permissions and
  a payable-side probe inside a context named for selling. A new bounded context is the platform's
  stated convention for a new domain that depends on Accounting.
- **Carry `credit_limit` / a default AP account now** (a fuller mirror) — rejected at Gate 1 (dec. 4):
  neither has a defined payable-side meaning until bills exist; Wave 7 owns them. Building them now is
  the scope-creep requirements §7 warns against.
- **Skip the probe seam until Wave 7** — rejected: the rules would be written and never exercised, and
  "we will remember to block archiving once bills land" is the promise that does not get kept
  (`ReceivableBalanceProbe.php:21-23`). Dormant-and-tested is the whole point of the seam.
- **Bind a Wave-7 Eloquent probe now** — impossible and wrong: no bills table exists. `NoPayables` is
  the accurate statement of this schema, not a placeholder.

## Consequences

- Purchasing exists as a first-class bounded context with its own provider, migrations, RLS, permission
  group and policy — Wave 7 (bills) and Wave 8 (payments/WHT) extend it without restructuring.
- The archive/delete/code-lock rules ship **inert**: correct, tested against a bound fake probe, and
  activated by a one-line binding flip in Wave 7 — no `SupplierService` change.
- The permission catalogue grows by two tenant-grantable capabilities; the owner inherits them via the
  wildcard, and no permission-count assertion exists to break (the catalogue tests assert composition
  and owner-inheritance, not a fixed total — `PrivilegeEscalationTest.php:277-300`).
- One documentation drift (non-blocking): `docs/architecture/overview.md:92` says RLS is "FORCED on 16
  tables" — already stale (its module map predates Accounting/Sales). Adding `suppliers` makes the count
  wrong by one more. Left for a later overview refresh, not this slice.

## Risks

- **Dormant-rule risk** (requirements §7, first bullet): AC10/AC15/AC19 enforce nothing until Wave 7.
  Mitigated by building **and testing** the seam now against a bound fake probe (§G), and by a Wave-7
  acceptance check that the binding actually moved to `EloquentPayableBalanceProbe` — the mirror of
  `ReceivableBalanceProbeTest.php:89-94`, the one assertion that would have caught Sales closing a
  milestone with the seam still unbound.
- **Mirror-drift risk**: hand-copying a large domain invites subtle divergence (a renamed problem code,
  a missed `array_key_exists` branch). Mitigated by the file:line citations above being the review
  checklist, and by the test suites being ported (not rewritten) from the customer originals.
- **Wave-7 boundary creep**: the temptation to add supplier-side tax/posting/AP-account behaviour now.
  Explicitly out of scope; the deferred columns and the dormant probe are the guardrails.
- **`sensitive` and `auditOnly` divergences** (§H items 3, 5): two small, reasoned departures from the
  literal customer mirror. Surfaced for explicit Gate-2 confirmation rather than decided silently.

## H. Gate 2 — items to confirm

No UNRESOLVED forks block the build. Confirm the following **(Gate-2 PROPOSED)** shapes:

1. **Module + provider structure** (§A): new `src/Core/Purchasing/` context, namespace
   `Asids\Core\Purchasing\…`, four-layer layout, `PurchasingServiceProvider` registered **immediately
   after** Sales in `ModuleServiceProvider`.
2. **`suppliers` table shape + coding** (§B): the mirrored columns **less** `credit_limit` and the AP
   account; FORCED RLS via a dedicated migration reusing the tenant-context functions; `S-` prefix,
   non-gapless, company-scoped, case-insensitive-unique-on-live-rows; migration filenames
   `2026_09_01_000001/000002` (prefix adjustable).
3. **Permission sort order / grants / sensitivity** (§D): `purchasing.suppliers.view` (sortOrder 10) +
   `.manage` (sortOrder 20, **`sensitive: true`** proposed), spread after `sales()`; granted to
   accountant + bookkeeper (manage) and viewer (view). Confirm the `sensitive` flag stays `true` despite
   the deferred credit limit.
4. **The probe seam** (§E): interface `PayableBalanceProbe` with `outstandingBalance()` +
   **`hasAnyBill()`** (name to confirm), bound to `NoPayables` this slice, flipped to
   `EloquentPayableBalanceProbe` in Wave 7.
5. **`auditOnly()` TIN divergence** (§C2): confirm whether `tax_identification_number` is **added** to
   the supplier `auditOnly()` list (recommended, given the compliance reason it was retained) or the
   customer list is mirrored exactly (TIN omitted).

---

*Prepared by the Solution Architect (Stage 3, Phase 5 / Wave 6). Design only — no production code
written, nothing committed. Build begins after Gate 2 approval, on this ADR, verbatim.*

## Gate 2 decision — APPROVED 2026-09-01

Approved **as proposed**, including both flagged departures from the literal customer mirror:
1. **`purchasing.suppliers.manage` is `sensitive: true`** (the customer `manage` is not) — deciding who you pay is a sensitive action.
2. **`Supplier::auditOnly()` includes `tax_identification_number`** (the customer omits its TIN) — the TIN is retained for later WHT/compliance, so changes to it must be audited.

All Gate-2 PROPOSED values confirmed: new `src/Core/Purchasing/` context + `PurchasingServiceProvider` after Sales; the `suppliers` table (customer mirror less `credit_limit` and the AP account, keeps TIN) with FORCED RLS and per-company case-insensitive-unique `S-` non-gapless codes; `SupplierService`/`SupplierPolicy`; `purchasing.suppliers.{view,manage}` (view 10, manage 20 sensitive) granted to accountant + bookkeeper (manage) and viewer (view); dormant `PayableBalanceProbe`/`NoPayables` seam with method `hasAnyBill()`; the 5-stage test-first build. Build proceeds strictly within this ADR; any implementation discovery that would change a decision returns to Gate 2.
