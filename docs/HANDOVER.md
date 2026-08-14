# ASIDS ERP Cloud — engineering handover

**Prepared:** 14 August 2026 · **Branch:** `main` · **HEAD:** `cd0ed44` · **CI:** green, 5/5 · `main` = `origin/main`

Where the build stands, the rules it runs on, and the exact line to pick up next.

## Contents

1. [Where it stands](#1-where-it-stands)
2. [The rules this project runs on](#2-the-rules-this-project-runs-on)
3. [Architecture in sixty seconds](#3-architecture-in-sixty-seconds)
4. [Start here — Milestone 5, Stage 4](#4-start-here--milestone-5-stage-4)
5. [Decisions that govern this milestone](#5-decisions-that-govern-this-milestone)
6. [Working alongside another team](#6-working-alongside-another-team)
7. [Traps that have already cost time](#7-traps-that-have-already-cost-time)
8. [Known debt, carried deliberately](#8-known-debt-carried-deliberately)
9. [Getting it running](#9-getting-it-running)
10. [Where the documentation lives](#10-where-the-documentation-lives)

---

## 1. Where it stands

A commercial multi-tenant accounting and ERP SaaS for Sri Lankan SMEs, with Australia, Singapore, the
UAE and India behind it. It is not an MVP and not a prototype — the standing instruction throughout has
been that shortcuts become permanent liabilities in a product sold to thousands of businesses.

| Metric | Value | |
| --- | --- | --- |
| Tests | 1,319 passed | 3,847 assertions; 0 failed, 0 skipped, 0 risky |
| Coverage | 88.0% | CI figure; floor 85%, enforced |
| PHPStan | Level 8 | clean |
| Modules | 9 | 352 PHP source files, 46 test files |
| CI | 5 jobs, all green | run `31772703024` on `cd0ed44` |

A locally run suite reports a slightly lower coverage figure than CI's — 87.3% against 88.0% on the last
comparison. That gap is runner variance, which lines a given environment happens to exercise, not a
difference in the suite. Treat the CI figure as the project's current status.

| Phase | Delivers | State |
| --- | --- | --- |
| **1** — Platform foundation | Multi-tenancy on PostgreSQL with FORCED row level security, companies, branches, users, sessions, devices, two-factor, roles and permissions, settings, append-only audit trail | ✅ Done, tagged `phase-1` |
| **2** — Accounting core | Fiscal calendar, chart of accounts with a versioned Sri Lankan starter template, immutable double-entry journals enforced by database triggers, gapless document numbering, per-period balances, opening balances, period and year close, trial balance, account ledger | ✅ Done, tagged `phase-2` |
| **3** — Customers & sales invoicing | Eight milestones. Five delivered; Milestone 5 is two stages into six. | 🔵 In progress |

### Phase 3, milestone by milestone

Milestones 5 and 6 were built **in parallel by two teams**, so the numbering is not the order of
delivery — Milestone 6 landed first. See [§6](#6-working-alongside-another-team).

| # | Milestone | State |
| --- | --- | --- |
| 1 | Source-document link on `journal_entries` — a ledger entry gains a traceable cause, plus the database guard against a document posting twice | ✅ Done |
| 2 | Customer domain — model, migration, FORCED RLS, service, policy, permissions, factory; archive-with-balance rule behind a probe seam | ✅ Done |
| 3 | Tax codes — effective-dated rates behind a GiST exclusion constraint, deterministic resolution that refuses rather than guessing | ✅ Done |
| 4 | Draft invoices — schema, status enum, models, DTOs, service, totals, discounts, tax integration, policy, permissions, factories | ✅ Done |
| 5 | **Issuing** — the posting map, numbering, cancellation, immutability. Six stages; Stages 1, 2 and 3 complete, **Stage 4 not started**. | 🔵 Current |
| 6 | HTTP surface — customer and tax-code REST API, `CustomerService` hardening | ✅ Done, merged via PR #1 |
| 7 | Reports — outstanding balance, aged receivables, AR control reconciliation | Not started |
| 8 | Front end — customer and invoice screens, routing, Vitest | Not started |

Milestone 6 was once blocked on debt item I3. **That block is gone** — I3 was resolved as part of the
milestone. Nothing in Milestone 6 waits on Milestone 5, and vice versa.

---

## 2. The rules this project runs on

These are not style preferences. They were set at the outset and every phase so far has been built and
reviewed against them.

### Build discipline

- **One phase at a time.** Never generate ahead. Each phase runs functional analysis → technical
  analysis → database design → implementation plan → risks → code → tests → docs, and does not start
  without an explicit go-ahead. Within a milestone, stages are reviewed one at a time.
- **No placeholders, no TODOs, no stub methods.** Every feature ships complete: validation,
  authorization, error handling, logging, migration, seeder, factory, policy, API resource and tests.
- **Design before build.** Larger units of work get a written design reviewed and approved before
  implementation. Milestone 5's six-stage plan and Milestone 6's requirements/design/task documents both
  came out of that process.

### Git

- Complete unit of work → run tests and validation → fix → final review → commit → verify clean working
  tree → start the next.
- **Never start the next unit of work while the previous one has uncommitted changes.**
- **Never commit knowingly broken code** to mark something complete.
- Descriptive commit messages. Not "updates", "fixes", "done".
- Never commit secrets, API keys, passwords, `.env` files, credentials or private certificates.
- Work that arrives from another branch is cherry-picked with `-x` so the origin SHA is recorded, and the
  original author is preserved.

### Quality gates — all must pass before a commit

| Gate | Command | Bar |
| --- | --- | --- |
| Tests | `./vendor/bin/pest` | All pass, none skipped |
| Coverage | `pest --coverage --min=85` | 85% floor, currently 88.0% |
| Static analysis | `composer analyse` | PHPStan level 8, zero errors |
| Formatting | `composer lint` | Pint clean |
| Security | `php artisan asids:security-check` | All checks pass |

> **Tests are not negotiable.** Do not weaken, skip or delete a test to make a change land. If an
> existing assertion blocks you, the change is wrong or the assertion needs a stronger replacement —
> never a weaker one.

---

## 3. Architecture in sixty seconds

A modular monolith. Each bounded context under `src/Core/<Module>` owns its domain, application,
infrastructure and presentation layers, wired by one service provider registered in
`ModuleServiceProvider`. Namespace root is `Asids\Core\…` — not `Src\`. Factories live in
`database/factories` under `Database\Factories`.

| Module | Files | Owns |
| --- | --- | --- |
| Platform | 21 | Shared kernel: request context, API envelope, problem details, query criteria, compliance-pack seam |
| Tenancy | 30 | Tenant, hostname resolution, tenant scope, row level security, provisioning |
| Identity | 73 | Users, authentication, 2FA, devices, access tokens |
| Authorization | 31 | Roles, permission catalogue, policies |
| Organization | 38 | Companies, branches, memberships |
| Settings | 13 | Hierarchical typed settings: user → company → tenant → system |
| Audit | 22 | Append-only hash-chained audit trail, activity feed |
| Accounting | 77 | Fiscal calendar, chart of accounts, ledger, balances, close, `Money` |
| Sales | 47 | Customers, tax codes, invoices, and the customer/tax-code REST surface |

**Dependency rule.** Modules depend on Tenancy's domain layer and on Platform, never on each other's
internals. Sales depends on Accounting; the reverse must never become true. The single documented
exception is workspace provisioning, recorded in [ADR 0005](adr/0005-workspace-provisioning-ownership.md).

### The three things most likely to bite you

**Tenancy is enforced three times over.** An Eloquent global scope, FORCED PostgreSQL row level security
keyed on the `asids.tenant_id` session variable, and per-tenant cache, filesystem and queue prefixing.
The application connects as `asids_app`, which is `NOSUPERUSER NOBYPASSRLS` — deliberately, because a
superuser bypasses RLS unconditionally even on a FORCED table. `asids_owner` is the superuser and must
never be what the app or the test suite connects as.

**`Money` is a value object, and it is settled.** Scaled integers at scale 4, half-away-from-zero
rounding, largest-remainder `allocate()`, and a public `int $minorUnits`. Floats never touch a monetary
value anywhere in the codebase. `Money.php` has been explicitly off-limits to change for several
milestones — treat modifying it as needing a decision, not a refactor.

**The ledger defends itself in the database.** Journals are append-only with immutability triggers, and
`journal_lines` carries a DEFERRABLE INITIALLY DEFERRED balance constraint. Temporal non-overlap (tax
rate ranges) uses GiST exclusion constraints via `btree_gist`. Business rules that can be expressed as a
constraint generally are, and the tests assert against the database rather than the service wherever the
database is what enforces the rule.

---

## 4. Start here — Milestone 5, Stage 4

Milestone 5 turns a draft invoice into a posted one. It is the milestone where money reaches the ledger,
and it was singled out during design review as warranting the closest scrutiny of the eight. It runs in
six stages, deliberately, so each can be reviewed before the next begins.

| Stage | Scope | State |
| --- | --- | --- |
| 1 | `DocumentType::SalesInvoice`; the `total = subtotal + tax_total` CHECK; the issued-invoice immutability triggers, which permit only `status`, `amount_paid`, `amount_due` and `updated_at` to move once a document leaves draft | ✅ Done |
| 2 | `InvoicePostingMap` and `InvoiceCannotBePosted`; `Account::TRADE_RECEIVABLES` with its chart-template registration and idempotent backfill | ✅ Done — `d781c80` |
| 3 | **Issuing.** `SalesInvoiceService::issue()`, `InvoiceCannotBeIssued`, `IssueInvoiceTest` | ✅ Done — `9f437c9` |
| 4 | Cancellation and reversal through `PostingService::reverse()` | 🔵 **Not started** — next |
| 5 | Permissions, policy, role grants, and removal of the `SalesInvoiceLine` morph alias (decision B6) | ⏳ Not started |
| 6 | Final verification, **ADR 0009**, roadmap update, CI | ⏳ Not started |

**Stage 4 has not started.** It concerns cancellation and reversal, and is expected to use the existing
`PostingService::reverse()` rather than new machinery — but its scope goes through the same inspection and
approval process every stage has, and nothing about it is settled. Do not infer its requirements from this
document.

### What Stage 2 delivered

`InvoicePostingMap` is a pure mapping: it reads an invoice, resolves the accounts, and returns
`JournalLineData` for `PostingService` to post. **It writes nothing, posts nothing and reserves no
number** — that separation is what let it be tested exhaustively before anything could touch the ledger,
and Stage 3 should preserve it rather than fold posting back into the map.

- One debit to receivables for the invoice total; credits to revenue grouped by `revenue_account_id`;
  credits to output tax grouped by *output account*, not by tax code.
- Amounts come from the lines' stored `tax_amount` and `line_subtotal`. Nothing here applies a rate or
  rounds anything, which is precisely why the entry balances exactly rather than nearly.
- The receivable account is the customer's own `receivable_account_id` when set, otherwise the company's
  `Account::TRADE_RECEIVABLES` system account. **Never resolved by account code** — a company may
  renumber its chart freely, and there is no `1130` literal anywhere in Sales.
- Every account is checked for company ownership, postability and type. Refusals are
  `InvoiceCannotBePosted`, never a raw `QueryException`.
- Its public `receivableAccountFor()` exists so Stage 3 can validate before issuing without building the
  whole entry.

### What Stage 3 delivered

`SalesInvoiceService::issue(SalesInvoice $invoice, ?User $actor = null)`, plus the
`InvoiceCannotBeIssued` exception and `IssueInvoiceTest`. Three Sales files; no Accounting file was
touched.

Everything that can refuse runs **before** the transaction opens: draft check, at least one line, positive
total, re-validation, fiscal period, and the posting map. A closed period or an archived account therefore
costs no document number, because `document_sequences` is never reached. Inside the single transaction the
number is reserved, the entry is posted, and the invoice is written **once** — status, number, `issued_at`
and `journal_entry_id` together, because `sales_invoices_number_matches_status_check` refuses an issued
invoice without a number, which makes the single save mandatory rather than merely tidy.

**Two number series, and this is the decision to understand.** The invoice takes `INV-…` from the
`sales_invoice` sequence; its journal entry takes `JV-…` from the journal voucher sequence:

```
INV-2026-06-0001 → JV-2026-06-0001
INV-2026-06-0002 → JV-2026-06-0002
INV-2026-06-0003 → JV-2026-06-0003
```

`document_sequences` is keyed on `(company_id, document_type, period_key)`, so typing the entry
`SalesInvoice` would have drawn both numbers from one counter — invoice 0001, its own entry 0002, next
invoice 0003. Invoice numbers running 1, 3, 5 is exactly the gap `requiresGaplessNumbering()` promises
never to leave, and every single-invoice test passes either way. Only a sequence of them exposes it, and a
test now asserts three in a row on both sides.

`JournalVoucher` is selected through the existing seam — `JournalEntryData::documentType` was already a
constructor parameter — so no `DocumentType` was added and the Accounting numbering architecture is
unchanged.

**Traceability is the source document, not the document type.** The entry carries the invoice as
`source_type`/`source_id`, and the unique index over `source_id` across non-reversing entries is what makes
a second posting impossible. Double issuance is refused by the *database*, not only by the service's status
check — a test proves it by bypassing the service check entirely. This also leaves Stage 4 room:
`PostingService::reverse()` copies the original's document type, so a cancellation draws its mirror from
the journal voucher counter and never consumes an invoice number.

**The B5 re-validation split.** `assertIssuable()` covers the customer, the branch and tax-code company
ownership. The accounts — receivable, revenue, tax output, with ownership, postability and type — stay with
`InvoicePostingMap`, which already validates them and runs before the transaction, so its refusals cost
nothing either. That is a division of responsibility, not duplicated validation. Tax-code company ownership
closed a real gap: the map verifies the output *account* belongs to the company, but nothing verified the
*code* did.

**Money is never recomputed.** The stored `line_subtotal` and `tax_amount` were rounded when the draft was
written; re-resolving a rate at issue would silently reprice a document the customer has already agreed.

**Permissions are deliberately deferred to Stage 5.** `issue()` enforces state and domain rules only. There
is no `sales.invoices.issue` ability and no `SalesInvoicePolicy::issue()`. Nothing HTTP-facing calls
`issue()` yet, so nothing is exposed meanwhile — but do not mistake the current state for the finished
authorization model.

**ADR 0009 is intentionally unwritten.** Stage 6 consolidates it. Until then the decision notes in
[ROADMAP.md](ROADMAP.md) under Milestone 5 are the record, and they carry the numbering model, the
`JournalVoucher` choice, source-document traceability, the B5 split and the permission ordering.

### Suggested first hour

1. Read `src/Core/Sales/Application/Services/SalesInvoiceService.php` — `issue()` and its docblock explain
   the ordering and the two counters.
2. Read `tests/Feature/Sales/IssueInvoiceTest.php`, especially the rollback and concurrency groups. They
   are the specification for what Stage 4 must not break.
3. Read `src/Core/Sales/Application/Services/InvoicePostingMap.php`. Its class docblock explains the shape
   of a sales posting and why grouping is not optional.
4. Read [ADR 0007](adr/0007-draft-invoice-modelling.md) for the draft/issued boundary, then the Stage 1
   migration `2026_03_05_000002_make_issued_invoices_immutable.php` to see where that boundary is
   enforced.
5. Run the suite once, serially, to confirm your environment is sound before changing anything.

The prose in this codebase is load-bearing. Class and migration docblocks explain *why* a decision was
made and what breaks otherwise — several record defects found the hard way. Read them before assuming an
oddity is accidental, and keep writing them at the same density.

---

## 5. Decisions that govern this milestone

These were reviewed and approved before Milestone 5 started. They are settled, and the remaining stages
must be built to them rather than around them. They are **not yet written up as an ADR** — that is Stage
6's job, and it must use **ADR 0009**, because ADR 0008 is occupied by Milestone 6's Sales HTTP surface.
Until then this table, together with the Stage 3 decision notes in [ROADMAP.md](ROADMAP.md), is the record.

| Ref | Decision |
| --- | --- |
| B1 | Receivable and tax output accounts resolve from current configuration *at the moment of issue*. No snapshot columns on the invoice — the posted journal entry is the permanent snapshot, and it names the accounts it used. |
| B2 | A database CHECK enforces `total = subtotal + tax_total`. ✅ Shipped |
| B3 | No `reversal_journal_entry_id` column. The reversal is discoverable through the existing source-document link. |
| B4 | A draft may total zero. At issue, require `total > 0` and at least one line — raised as domain errors, never as raw database exceptions. ✅ Shipped |
| B5 | Re-validate everything at issue: customer, tax codes, revenue accounts, tax output accounts, receivable account, postability and fiscal period. ✅ Shipped, split between `assertIssuable()` and `InvoicePostingMap` |
| B6 | Remove the `SalesInvoiceLine` morph alias in Stage 5. Keep the `sales_invoice` header alias. |
| B7 | Only issued invoices are cancellable; drafts are hard-deleted. Cancellation goes through `PostingService::reverse()`, retains the document number, and is refused when the period is closed. |
| C1 | Adding `DocumentType::SalesInvoice` to the Accounting enum was a **sanctioned exception** to the module boundary — additive only, and reported explicitly. ✅ Shipped |
| C2 | The posting map lives on the Sales side, with account grouping, and is unit-tested before `issue()` exists. ✅ Shipped |
| C3 | `?User $actor` is plumbed through issue and cancel. |

> **The Accounting boundary.** Sales reaching into Accounting is not routine. Two crossings have been
> sanctioned so far — `DocumentType::SalesInvoice` and `Account::TRADE_RECEIVABLES` with its supporting
> chart-template and backfill work — and each was raised, approved and reported before it was made.
> Stage 3 needed no third crossing: it reached `JournalVoucher` through `JournalEntryData`'s existing
> constructor parameter and touched no Accounting file. **If a later stage turns out to need one, stop and
> raise it rather than widening the exception quietly.**

---

## 6. Working alongside another team

Milestone 6 was delivered on `feature/sales-http-api` by a second team, in parallel with Milestone 5, and
merged into `main` through **PR #1** as commit `6a8485c`. That team did not touch the ledger; Milestone 5
did not touch the HTTP layer. The two met cleanly.

**What Milestone 6 shipped:**

- `CustomerController` and `TaxCodeController` under `src/Core/Sales/Presentation/Http/`, with form
  requests (`StoreCustomerRequest`, `UpdateCustomerRequest`, `StoreTaxCodeRequest`,
  `UpdateTaxCodeRequest`, `EndTaxCodeRangeRequest`) and API resources (`CustomerResource`,
  `TaxCodeResource`).
- Company-scoped REST routes at `companies/{company}/customers` (9 endpoints) and
  `companies/{company}/tax-codes` (9 endpoints), behind the `company` middleware, enforcing the
  **existing** `sales.customers.{view,manage}` and `sales.tax-codes.{view,manage}` abilities through the
  existing policies. No new permissions were invented.
- `CustomerApiTest` and `TaxCodeApiTest`, written test-first against the approved contract.
- `docs/api/openapi.yaml` updated, with route-versus-spec coverage verified in CI.
- **`CustomerService` hardening**, which closed five debt items — see [§8](#8-known-debt-carried-deliberately).
- Two incidental platform fixes: framework-thrown 403s now render as `forbidden` rather than `http-403`,
  and the base `TestCase` forces the array cache store.

**Practical consequence for Stage 3:** `CustomerService::update()` no longer takes a `CustomerData` DTO.
Its signature is now `update(Customer $customer, array $attributes)`. If you have older notes or code
assuming the DTO, they are out of date.

### The database test-safety guard

`main` now carries a guard, cherry-picked as `ea0f421` from `bbf9def`, that stops the test suite
destroying the development database.

The failure it prevents: the app container loads the real `.env` through `env_file`, so `DB_DATABASE`
arrives in the *real* environment as `asids_erp` — the development database. PHPUnit's `<env>` block does
not override an existing environment variable, so `RefreshDatabase` would run `migrate:fresh` against
development data.

`phpunit.xml` now boots through `tests/bootstrap.php`, which forces `DB_DATABASE` and `CACHE_STORE` to
safe values **before the framework reads the environment**. It is conditional: it fires only when the
value looks wrong — a database name must contain `"testing"`, and the cache store must be `array`. CI
already uses `asids_erp_testing`, so the guard stays dormant there, and parallel testing's per-token
database names still contain `"testing"`.

One consequence worth knowing: any database whose name lacks `"testing"` is silently redirected to
`asids_erp_testing`. It fails in the safe direction, but it is now a naming convention the project
depends on.

### Dependency advisories will turn CI red without anyone changing anything

`npm audit --audit-level=high` is a CI gate, so a newly published advisory against an existing dependency
fails the build on a commit that did not touch JavaScript. It has happened twice: `happy-dom` during
Milestone 6, and `nanoid` immediately after Stage 3, fixed by `cd0ed44`.

The `nanoid` case is worth reading before the next one. `npm ls nanoid` shows it under `postcss`, a
devDependency, which invites the conclusion that it cannot reach production — but the production graph
reaches it too, through `vue → @vue/compiler-sfc → postcss → nanoid`, and the lockfile marks it
`"dev": false`. **Check `npm audit --omit=dev` rather than reading the tree.** The fix was lockfile-only:
`postcss` already declared `nanoid: ^3.3.16`, which the patched version satisfied, so `package.json` was
untouched and exactly one entry moved out of 443.

---

## 7. Traps that have already cost time

Every one of these has bitten at least once.

**A data migration on a tenant-scoped table silently affects zero rows.** Migrations run as `asids_app`,
which is `NOBYPASSRLS`, and the tenant-scoped tables are FORCED. With no tenant published to the session,
an `UPDATE` matches nothing and PostgreSQL reports `UPDATE 0` as success. Wrap any cross-tenant data
migration in `RowLevelSecurity::bypass()` and assert what it did —
`2026_03_05_000003_stamp_trade_receivables_system_key.php` is the worked example, including its two guard
assertions.

**Deferred constraints do not fire under `RefreshDatabase`.** The wrapping transaction is rolled back
rather than committed, so a test meant to prove a DEFERRABLE trigger works passes whether the trigger
exists or not. Issue `SET CONSTRAINTS ALL IMMEDIATE` first.

**Run Pest serially — never concurrently.** `composer test` maps to `pest --parallel`, and parallel runs
against this suite have produced hundreds of phantom failures that a clean serial run disproved. Run
`./vendor/bin/pest` directly. With `--coverage`, raise the memory limit —
`php -d memory_limit=-1 ./vendor/bin/pest --coverage --min=85` — or the run dies inside php-code-coverage
at PHP's 128 MB default. A full serial run with coverage takes around eight minutes.

**`Gate::before` grants a tenant owner every ability.** Which short-circuits *state* preconditions
written inside policies — `isEditable()`, `isPosted()`. State rules therefore have to live in services,
not policies, and any API resource exposing a `capabilities` block must ask the model for state as well
as asking the gate. The root cause is deliberately unfixed: changing it would subject owners to
membership-based company access, which is a product decision.

**CI must connect as `asids_app`, for tests *and* migrations.** The Postgres service container's
`asids_owner` is a superuser, and a superuser bypasses RLS unconditionally — `FORCE ROW LEVEL SECURITY`
does not apply to it. Running the suite as the owner silently disabled tenant isolation for every test.
Relatedly: **`RowLevelSecurityTest` skipping is an alarm, not a pass.** It skips loudly when RLS is not
in force; eleven skips means isolation is off.

**Cache state is not rolled back between tests.** `RefreshDatabase` rolls the database back and nothing
else. With a persistent store, a tenant slug cached by one test resolves later tests to a rolled-back
tenant id and every RLS-scoped lookup 404s. The base `TestCase` and `tests/bootstrap.php` both force the
array store; do not undo either.

**A keyed account must also be marked a system account.** `accounts_system_key_check` asserts
`(system_key IS NOT NULL) <= is_system`. Setting `system_key` alone fails the constraint.

**Columns with a database default are `null` on an unsaved model.** And throw under
`Model::shouldBeStrict()`. Set them explicitly in the service.

**Lazy loading throws, so eager-load everything a service walks.** Strict mode turns an N+1 into a
`LazyLoadingViolationException`, which is a gift — it surfaces in tests what would otherwise be a
production performance bug.

**Draft-then-act across two commits leaks rows.** Any controller that creates a record and then performs
a refusable action must wrap both in one transaction, or the refusal leaves the draft behind.

**Pint disagrees between PHP 8.4 and 8.5.** On `class_attributes_separation`. CI runs 8.4; local
development here has been on 8.5.9, so a clean local whole-project run can still fail CI on identical
files. Delete `.pint.cache` before a final check.

**Migrations live in the global namespace.** So `use RuntimeException;` in a migration file raises
`ErrorException: use statement with non-compound name has no effect` — at runtime, on the operator's real
`migrate`. Reference global classes directly.

**Pest helper functions are global.** A name collision between two test files takes down the entire
suite, not just the file.

**Verifying by hand.** Use `php artisan tinker --execute="require '<file>';"` — the file needs a `<?php`
tag, and `php artisan tinker <file>` hangs.

**The demo seeder creates two companies and no chart of accounts.** Anything needing a chart must apply
`ChartTemplateService` itself. Verify against a clean `migrate:fresh --seed`, not whatever your local
database happens to contain.

---

## 8. Known debt, carried deliberately

### Resolved by Milestone 6

Five long-standing items were closed by the `CustomerService` hardening. They appear here because older
notes still list them as open.

| Ref | Was | Now |
| --- | --- | --- |
| **I3** | `CustomerService::update()` could not distinguish "clear this field" from "field not supplied" | ✅ Takes a partial attribute array with `array_key_exists` semantics, matching `TaxCodeService` and `ChartOfAccountsService` |
| **I4** | Customer code generation surfaced a concurrent create as a raw `QueryException` | ✅ `UniqueConstraintViolationException` is translated to `ResourceConflict` (409) |
| **M6** | `credit_limit` and `payment_terms_days` were in `Customer::$fillable` | ✅ Removed from `$fillable` |
| **M7** | `CustomerService::archive()` hardcoded scale 4 | ✅ Uses `Money::SCALE` |
| **M8** | `applyAttributes()` assigned before validating | ✅ Validates first |

### Still open

| Ref | Issue | Note |
| --- | --- | --- |
| **N3** | Same-workspace 403-vs-404 existence oracle | A caller can distinguish "exists but forbidden" from "does not exist". Platform-wide — accounts and journals too — so it should be fixed once across all modules rather than per module. Recorded in [STATUS.md](STATUS.md); note that document cites ADR 0008 for it, but ADR 0008 does not in fact contain an N3 section, so the reasoning lives only in STATUS.md today. |
| — | `TenantProvisioningService` does five things in one transaction and depends outward on three modules | ADR 0005 predicted the extraction point; every new module adds tables to it |
| — | Two competing patterns: Phase 1 repositories versus Phase 2 services querying models directly | **Pick one before a third module is written.** The largest architectural decision still open |
| — | A stale `// EXPERIMENT: temporarily disabled` comment sits above an **active** `RecordRequestContext::class` in `bootstrap/app.php:48` | Comment-only defect: the middleware is registered and running, so the comment says the opposite of the truth. Deliberately not fixed here — it is application configuration, outside a documentation change |
| — | The README's module list predates Accounting and Sales | Small, but it is the first file a new developer reads |
| — | `tests/bootstrap.php` keys on the substring `"testing"` | Any future database name without it is silently redirected. Safe direction, but now a convention to honour |

---

## 9. Getting it running

Prerequisites: Docker, PHP 8.4 or newer, Composer 2, Node 22. The repository is
`github.com/AkilaAsids/ASIDS_Accounting-` — the trailing hyphen is part of the real name.

```bash
# install
cp .env.example .env && composer install && npm ci && php artisan key:generate
docker compose up -d
php artisan migrate --seed
```

The application serves on `http://asids.localhost`; a tenant workspace is reached at
`http://{slug}.asids.localhost`. Add both to `/etc/hosts`, or send an `X-Tenant` header against
`http://localhost`. The demo workspace's credentials are printed by the seeder and defined in
`database/seeders/DemoWorkspaceSeeder.php`.

```bash
# the gates, in the order they are usually run
./vendor/bin/pest                                        # serial — never --parallel
php -d memory_limit=-1 ./vendor/bin/pest --coverage --min=85
composer analyse
rm -f .pint.cache && composer lint
php artisan asids:security-check
```

Development and test databases are separate — `asids_erp` and `asids_erp_testing` — and
`tests/bootstrap.php` now enforces that separation even when the container leaks the development value.

---

## 10. Where the documentation lives

Read the ADRs before changing anything they cover. Each records what was decided, what was rejected, and
why — including the honest limits of the choice.

| ADR | Subject |
| --- | --- |
| [0001](adr/0001-tenancy-strategy.md) | Tenancy strategy: single database, row-scoped — including where RLS genuinely does not protect you |
| [0002](adr/0002-tenant-company-branch-hierarchy.md) | Tenant / company / branch hierarchy, and why the three are deliberately distinct |
| [0003](adr/0003-permissions-in-code-roles-in-data.md) | Permissions live in code, roles live in data |
| [0004](adr/0004-minimal-config-surface.md) | Commit only deviating config files |
| [0005](adr/0005-workspace-provisioning-ownership.md) | Where workspace provisioning lives — and the extraction point it predicted |
| [0006](adr/0006-tax-code-modelling.md) | Tax codes: effective-dated rates behind a jurisdictional seam |
| [0007](adr/0007-draft-invoice-modelling.md) | Draft invoices: hard-deletable, issued boundary prepared |
| [0008](adr/0008-sales-http-api-and-customer-update-semantics.md) | The Sales HTTP surface, and attribute-array update semantics for customers (Milestone 6) |
| 0009 | *Not yet written.* Reserved for Milestone 5's decisions, due in Stage 6 |

**ADR 0008 is taken.** Milestone 5's decisions must be written up as **ADR 0009**.

### Also worth reading

- [ROADMAP.md](ROADMAP.md) — what is done, what is firm, what is only proposed. Its ✅ / 🔵 / 🟢 / 🟡
  markers matter: **a 🟡 item carries no authority to write code.**
- [STATUS.md](STATUS.md) — the second team's delivery record for Milestone 6. Written from
  `feature/sales-http-api` before the merge, so its branch and base references are historical.
- [SALES-HTTP-API-REQUIREMENTS.md](SALES-HTTP-API-REQUIREMENTS.md) and
  [SALES-HTTP-API-DESIGN.md](SALES-HTTP-API-DESIGN.md) — the requirements and endpoint contract behind
  Milestone 6.
- [docs/tasks/](tasks/) — the three lane briefs that milestone was built from
  ([Lane A](tasks/lane-a-customer-api.md) customer API, [Lane B](tasks/lane-b-taxcode-api.md) tax-code
  API, [Lane C](tasks/lane-c-customer-hardening.md) service hardening). A useful model for splitting
  parallel work.
- [PHASE-1-STATUS.md](PHASE-1-STATUS.md) and [PHASE-2-STATUS.md](PHASE-2-STATUS.md) — what each phase
  actually delivered.
- [SECURITY-REVIEW.md](SECURITY-REVIEW.md) — deliberately unsigned pending an external penetration test,
  which is a release gate rather than a formality.
- [architecture/overview.md](architecture/overview.md), [database/erd.md](database/erd.md),
  [api/openapi.yaml](api/openapi.yaml), [deployment/local.md](deployment/local.md),
  [deployment/aws.md](deployment/aws.md).

### Beyond Phase 3

Firm scope, ordering partly open: **Phase 4** payments and receivables (the invoice already carries
`amount_paid` and `amount_due` so this phase adds behaviour, not a migration); **Phase 5** purchasing
(`tax_codes.input_account_id` already exists and is type-validated for it); then multi-currency and FX
(`journal_lines` already carries the columns behind a CHECK this phase drops), payroll, the Sri Lankan
compliance pack, platform hardening — Horizon, Meilisearch and S3 are in the mandated stack and none is
wired up — and international expansion, one `CompliancePackContract` implementation per country.

Banking and reconciliation, items and inventory, and the financial statements beyond the trial balance
are *proposals only*. They are listed so they are not forgotten, not so they can be scheduled.
