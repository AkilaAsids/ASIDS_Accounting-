# ASIDS ERP Cloud — engineering handover

**Prepared:** 20 August 2026 · **Branch:** `main` · **HEAD:** `e28d8e2` · **CI:** green, 5/5 · `main` = `origin/main`

Where the build stands, the rules it runs on, and the exact line to pick up next.

## Contents

1. [Where it stands](#1-where-it-stands)
2. [The rules this project runs on](#2-the-rules-this-project-runs-on)
3. [Architecture in sixty seconds](#3-architecture-in-sixty-seconds)
4. [Start here — the remaining front end](#4-start-here--the-remaining-front-end)
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
| Tests | 1,573 passed | 5,056 assertions; 0 failed, 0 skipped, 0 risky. Plus 205 Vitest tests |
| Coverage | 89.0% | CI figure; floor 85%, enforced |
| PHPStan | Level 8 | clean |
| Modules | 9 | 366 PHP source files, 55 test files |
| CI | 5 jobs, all green | run `32329047764` on `e28d8e2` |

A locally run suite reports a slightly lower coverage figure than CI's — 88.4% against 89.0% on the last
comparison. That gap is runner variance, which lines a given environment happens to exercise, not a
difference in the suite. Treat the CI figure as the project's current status.

| Phase | Delivers | State |
| --- | --- | --- |
| **1** — Platform foundation | Multi-tenancy on PostgreSQL with FORCED row level security, companies, branches, users, sessions, devices, two-factor, roles and permissions, settings, append-only audit trail | ✅ Done, tagged `phase-1` |
| **2** — Accounting core | Fiscal calendar, chart of accounts with a versioned Sri Lankan starter template, immutable double-entry journals enforced by database triggers, gapless document numbering, per-period balances, opening balances, period and year close, trial balance, account ledger | ✅ Done, tagged `phase-2` |
| **3** — Customers & sales invoicing | Nine milestones delivered. Milestone 8 was narrowed to receivables reporting; Milestone 9 then added the sales invoice HTTP surface. **The customer, invoice and tax-code front ends remain outstanding** | 🔵 In progress |

### Phase 3, milestone by milestone

Milestones 5 and 6 were built **in parallel by two teams**, so the numbering is not the order of
delivery — Milestone 6 landed first. See [§6](#6-working-alongside-another-team).

| # | Milestone | State |
| --- | --- | --- |
| 1 | Source-document link on `journal_entries` — a ledger entry gains a traceable cause, plus the database guard against a document posting twice | ✅ Done |
| 2 | Customer domain — model, migration, FORCED RLS, service, policy, permissions, factory; archive-with-balance rule behind a probe seam | ✅ Done |
| 3 | Tax codes — effective-dated rates behind a GiST exclusion constraint, deterministic resolution that refuses rather than guessing | ✅ Done |
| 4 | Draft invoices — schema, status enum, models, DTOs, service, totals, discounts, tax integration, policy, permissions, factories | ✅ Done |
| 5 | **Issuing and cancellation** — the posting map, numbering, reversal, immutability, authorization. All six stages complete. | ✅ Done |
| 6 | HTTP surface — customer and tax-code REST API, `CustomerService` hardening | ✅ Done, merged via PR #1 |
| 7 | **Receivables reporting** — outstanding balance, aged receivables, AR control reconciliation, plus the two probe seams | ✅ Done |
| 8 | **Receivables reporting, end to end** — three report endpoints, `sales.reports.view`, three Vue pages. Narrowed from "customer and invoice screens"; see below | ✅ Done, with remainder |
| 9 | **The sales invoice HTTP surface** — seven endpoints over `SalesInvoiceService`, the company coherence guard, `LedgerNarration`, issue-race hardening. HTTP only, no front end | ✅ Done |

Milestone 6 was once blocked on debt item I3. **That block is gone** — I3 was resolved as part of the
milestone. Nothing in Milestone 6 waits on Milestone 5, and vice versa.

**Phase 3 is not closed.** Milestone 8 was deliberately narrowed by an approved decision to the
receivables-report vertical slice, because the reports were the only finished domain work that nothing at all
could reach. Of the three things its original wording also implied, Milestone 9 delivered one — the invoice
HTTP surface — and **customer screens and invoice screens were not built.** That, plus tax-code screens, is
the work waiting in [§4](#4-start-here--the-remaining-front-end).

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
| Coverage | `pest --coverage --min=85` | 85% floor, currently 89.0% in CI |
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
| Sales | 54 | Customers, tax codes, invoices, receivables reporting, and the customer/tax-code REST surface |

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

## 4. Start here — the remaining front end

**Nothing is in progress.** Phase 3 Milestones 1 to 9 are complete. What remains of Phase 3 is **entirely
front end**: every API those screens need is finished, tested and documented. None of the screens has been
designed, and this document deliberately does not specify them — every milestone so far went through
inspection and approval before implementation, and inferring requirements from a handover would skip that.

| Outstanding | Why it is the state it is |
| --- | --- |
| **Invoice front-end screens** | List, draft editor, and the issue/cancel lifecycle. **No longer blocked** — Milestone 9 delivered the seven endpoints, so this is UI work only. Read [ADR 0012](adr/0012-sales-invoice-http-surface.md) first: the `capabilities` object on each invoice is what a client should build its buttons from, and D9 records a real asymmetry in how out-of-state transitions are refused |
| **Customer front-end screens** | The API is finished and has been since Milestone 6 — nine endpoints, 54 tests. There is no UI over it. No backend work is needed |
| **Tax-code front-end screens** | Same position as customer screens — a complete API with no UI. Never named in Milestone 8's scope, so a proposal rather than a carried commitment |

**Two things the invoice list meets first.** `meta.pagination` is emitted by every paginated endpoint and
**no page in the codebase renders a pagination control** — the invoice list is the first genuinely unbounded
collection, so it cannot quietly show page one and stop. And an invoice editor cannot compute a live total:
there is no float arithmetic in the browser by rule and no preview endpoint, so the draft is saved and the
server's totals read back, which is the precedent `JournalEntriesPage` set for journal entries.

**No browser verification of any invoice screen exists**, because no invoice screen exists.

**Read [ADR 0011](adr/0011-receivables-reporting-frontend-and-http-surface.md) before adding any
company-scoped page.** One finding in it will otherwise cost you an afternoon: switching company refreshes the
session **in place** and never re-mounts the page, so a page that loads only on mount keeps the previous
company's figures under the new company's name and currency. The three report pages watch the active company
and reload; `TrialBalancePage` does not, and still has the gap.

### Milestone 9 — the sales invoice HTTP surface, complete

The invoice domain had been finished since Milestone 5 with nothing able to reach it. Milestone 9 gave it an
API and **no front end**.

| Layer | What exists |
| --- | --- |
| HTTP | Seven operations under `companies/{company}/sales-invoices` — index, store, show, update, destroy, `/issue`, `/cancel`. `POST` accepts `issue: true` to draft and issue in one transaction |
| Permissions | **None added.** The four `sales.invoices.*` capabilities and `SalesInvoicePolicy` have existed since Milestone 5. No migration |
| Requests | `StoreSalesInvoiceRequest`, `UpdateSalesInvoiceRequest` — shape and type only; ownership, postability, tax effectiveness and period state stay in the service, which names the real problem |
| Resources | `SalesInvoiceResource`, `SalesInvoiceLineResource`, carrying a `capabilities` object |
| Tests | 63 in `SalesInvoiceApiTest`, on top of the 156 service tests that already existed |

**Three things to know before touching it.**

`assertBelongsToCompany()` guards every route that binds an invoice, and it is a deliberate exception to
[ADR 0008](adr/0008-sales-http-api-and-customer-update-semantics.md) D6.1 rather than an oversight in the
other modules. `ResolveActiveCompany` publishes the *url* company into `RequestContext`, which is what stamps
`company_id` onto the audit trail — so without the guard, issuing another company's invoice under this
company's URL posts to their ledger while the trail records it against ours. **The platform-wide binding gap
is not fixed** and still applies to customers, tax codes, accounts and journal entries.

`capabilities` asks the state **and** the gate, because `Gate::before` grants an owner every ability and
short-circuits every state guard in the policy. `can_cancel` tests `status === Issued`, not the policy's
looser `hasBeenIssued()` — a cancelled invoice has historically been issued, so copying the policy predicate
would offer Cancel on something already cancelled.

**An out-of-state transition answers 403 to a non-owner and 422 to an owner.** Re-issuing an issued invoice is
`forbidden` to an accountant and `invoice-not-a-draft` to an owner, because the policy's advisory state guards
resolve before the service for anyone `Gate::before` does not bypass. That is existing Milestone 5 behaviour,
not something Milestone 9 introduced, and it is recorded in ADR 0012 D9 as an open API-consistency question
rather than fixed. Both paths are pinned by tests. A client cannot currently tell "you lack the permission"
from "the invoice is in the wrong state" without knowing whether the user is an owner.

Two prerequisite service fixes landed first, in `60cc8ea`. `LedgerNarration` clips composed journal narrations
to the ledger's 255-character columns at **all four** sites — the entry description and the three
`InvoicePostingMap` line descriptions — because `customers.name` and `accounts.name` are as wide as the columns
they were being written into, and a long trading name made an invoice unissuable with a raw database error.
Line ordering, grouping, account selection and amounts are untouched, and `IssueInvoiceTest` now asserts the
receivable is still line 1 so ADR 0010's invariant cannot drift. And `issue()` locks and re-reads the invoice
before numbering, closing a concurrent double-issue race that used to surface as a 500; the unique index and
the immutability trigger remain as backstops with their own tests.

### Milestone 8 — receivables reporting, complete

The three Milestone 7 reports are now reachable end to end.

| Layer | What exists |
| --- | --- |
| Permission | `sales.reports.view`, sortOrder 90, not sensitive. Granted to accountant, bookkeeper and viewer; administrator inherits it via `tenantGrantableNames()`, owner via `Gate::before`. **No migration** — edit `PermissionCatalogue`, edit `RoleTemplate`, run `asids:sync-permissions --refresh-roles`, which skips roles a customer has customised unless `--force` |
| HTTP | `GET companies/{company}/reports/outstanding-receivables`, `/aged-receivables` (optional `as_of`), `/ar-control` (no parameters). `ReceivableReportController` — no FormRequest, no Resource, no DTO, following `LedgerReportController` |
| Front end | `/sales/outstanding-receivables`, `/sales/aged-receivables`, `/sales/ar-control`, three flat navigation items, all gated on `sales.reports.view` |
| Tests | 44 API tests in `ReceivableReportApiTest`; 43 behavioural page specs — the **first page specs in the codebase**. The `pages/**` coverage floor stays at 0 and no existing page was retrofitted |

Two things about the controller worth knowing. It throws `AuthorizationException` rather than `abort(403)`,
so a denial renders as `type: …/forbidden` — the code `ProblemCode.Forbidden` in `types/api.ts` branches on.
`LedgerReportController` uses `abort(403)` and therefore renders `…/http-403`; see §8. And the reports emit
their verdicts (`meta.totals.reconciles`) rather than leaving a client to infer them, because two opposing
differences cancel in a total while both accounts are wrong.

### Milestone 7 — receivables reporting, complete

`ReceivableReportService` carries all three reports, following `LedgerBalanceService`: a `Company` first,
plain arrays out carrying `Money`, no report DTOs.

| Report | Semantics |
| --- | --- |
| `outstandingBalance(Company)` | Live snapshot. Collectable invoices only, `amount_due`, zero balances excluded |
| `agedReceivables(Company, CarbonImmutable $asOf)` | Aged from `due_date` against a required cutoff. Not Yet Due / 0–30 / 31–60 / 61–90 / 90+, inclusive edges, per customer |
| `arControlReconciliation(Company)` | Current date only. Per receivable account and in total; subledger from collectable invoices, ledger from `balanceAsAt()`, difference as ledger − subledger |

**Read [ADR 0010](adr/0010-receivables-reporting-and-ar-account-identification.md) before touching
`InvoicePostingMap`.** An invoice's receivable account is identified as **line number 1** of its journal
entry — provable from the map's ordering, but that couples reporting to it. `ArControlReconciliationTest`
asserts the ordering directly so it cannot change silently.

Milestone 7 also closed two seams that had been left on placeholder implementations after the milestones
meant to replace them had closed. Five documented rules were inert as a result: an invoiced customer could
be archived, renamed or deleted, and a used tax rate could be edited or removed. All five were confirmed by
execution before any code was written, and all five are now live — a deliberate behavioural change for
existing data.

### Milestone 5 — issuing and cancellation, complete

Described here because it is the machinery the reports read and the part of the codebase a new reader most
needs to understand.

| Stage | Scope | State |
| --- | --- | --- |
| 1 | `DocumentType::SalesInvoice`; the `total = subtotal + tax_total` CHECK; the issued-invoice immutability triggers | ✅ Done |
| 2 | `InvoicePostingMap` and `InvoiceCannotBePosted`; `Account::TRADE_RECEIVABLES` with its chart-template registration and idempotent backfill | ✅ Done — `d781c80` |
| 3 | **Issuing.** `SalesInvoiceService::issue()`, `InvoiceCannotBeIssued`, `IssueInvoiceTest` | ✅ Done — `9f437c9` |
| 4 | **Cancellation.** `SalesInvoiceService::cancel()`, `InvoiceCannotBeCancelled`, cancellation metadata, `CancelInvoiceTest` | ✅ Done — `edb89eb` |
| 5 | Permissions, policy, role grants, removal of the `SalesInvoiceLine` morph alias (B6) | ✅ Done — `cfa500d` |
| 6 | [ADR 0009](adr/0009-sales-invoice-issuing-cancellation-and-numbering.md), roadmap and handover | ✅ Done |

**The one thing to read before touching invoices** is
[ADR 0009](adr/0009-sales-invoice-issuing-cancellation-and-numbering.md). It records the numbering model,
which is not inferable from the code and is easy to break.

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
check — a test proves it by bypassing the service check entirely. It is also what makes cancellation free of
the invoice series: `PostingService::reverse()` copies the original's document type, so the mirror draws
from the journal voucher counter and never consumes an invoice number.

**The B5 re-validation split.** `assertIssuable()` covers the customer, the branch and tax-code company
ownership. The accounts — receivable, revenue, tax output, with ownership, postability and type — stay with
`InvoicePostingMap`, which already validates them and runs before the transaction, so its refusals cost
nothing either. That is a division of responsibility, not duplicated validation. Tax-code company ownership
closed a real gap: the map verifies the output *account* belongs to the company, but nothing verified the
*code* did.

**Money is never recomputed.** The stored `line_subtotal` and `tax_amount` were rounded when the draft was
written; re-resolving a rate at issue would silently reprice a document the customer has already agreed.

### What Stage 4 delivered

`SalesInvoiceService::cancel(SalesInvoice $invoice, string $reason, ?User $actor = null)`, plus
`InvoiceCannotBeCancelled`, three cancellation columns and `CancelInvoiceTest`. Again no Accounting file was
touched: cancellation reverses through the existing `PostingService::reverse()`.

A cancellation is not a deletion and not an edit. The invoice keeps its number, dates and every figure; its
original posting stays in the ledger marked reversed; a mirror entry is written alongside. The whole
operation is one transaction opened with `lockForUpdate()` on the invoice row.

**Which period must be open is the reversal's, not the invoice's.** The mirror is dated today, because
backdating a correction into a closed period is what closing exists to prevent. So an invoice from a closed
March may still be cancelled today; what refuses a cancellation is *today's* period being closed.

**Cancellation metadata is conditionally immutable.** `cancelled_at`, `cancellation_reason` and
`cancelled_by_id` are writable only during the issued → cancelled transition. Freezing them in the trigger's
column list would have refused the very update that sets them, so a CHECK ties them to the status and the
trigger guards them on every *other* update.

**Only issued invoices cancel.** Drafts are deleted instead; an already-cancelled invoice is refused; and an
invoice with `amount_paid > 0` is refused — inert today, stated now so the rule exists before payments do.

### What Stage 5 delivered

`sales.invoices.issue` and `sales.invoices.cancel`, both sensitive, held by the accountant alone — the
bookkeeper drafts but neither issues nor cancels, and the administrator inherits both automatically.
`SalesInvoicePolicy::issue()` and `cancel()` check permission and company access, with an **advisory** state
check for UI only. The `SalesInvoiceLine` morph alias was removed (B6).

**The decisions are recorded in [ADR 0009](adr/0009-sales-invoice-issuing-cancellation-and-numbering.md)** —
numbering, posting and reversal architecture, the validation strategy, authorization, and the known
limitations below.

### Suggested first hour

1. Read `src/Core/Sales/Application/Services/SalesInvoiceService.php` — `issue()` and its docblock explain
   the ordering and the two counters.
2. Read `tests/Feature/Sales/IssueInvoiceTest.php`, especially the rollback and concurrency groups. They
   are the specification for what any future change must not break.
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

### Resolved by Milestone 8

| Ref | Was | Now |
| --- | --- | --- |
| — | The three receivables reports had no route, no controller and no `sales.reports.view` permission | ✅ All three reachable over HTTP, permission in the catalogue and granted to three role templates |
| — | `OutstandingReceivablesPage` keyed its empty state on the row count, so a refused request rendered "No customer has an outstanding balance." above the error notice — telling an accountant their debtors had all paid | ✅ Keyed on `meta`, so the empty state speaks only for a request that succeeded. All three report pages now do this, and each has a test asserting a failure does not render the success wording |

### Still open

| Ref | Issue | Note |
| --- | --- | --- |
| — | **`abort(403)` renders as `http-403`, not `forbidden`** | `LedgerReportController::authorizeReports()` uses `abort(403)`, which raises a bare Symfony `HttpException` and falls through `ApiExceptionRenderer` to the generic arm. So the Accounting reports return `type: …/http-403` while their OpenAPI documents `Forbidden` and `types/api.ts` makes `forbidden` the code the front end branches on. `AccountingApiTest` only asserts the status number, which is why it went unnoticed. `ReceivableReportController` throws `AuthorizationException` instead and renders correctly — the two report controllers therefore disagree. Deliberately untouched: it is an Accounting file and was outside Milestone 8's scope |
| — | **`TrialBalancePage` does not reload on company switch** | Same gap the three report pages fix. Switching company refreshes the session in place without re-mounting, so the trial balance keeps the previous company's rows under the new company's currency. Left alone for the same reason as above |
| — | **Out-of-state transitions answer 403 to a non-owner and 422 to an owner** | `SalesInvoicePolicy::issue()` guards on `isDraft()` and `cancel()` on `hasBeenIssued()`, so for anyone `Gate::before` does not bypass the policy answers before the service does. Re-issuing an issued invoice is `forbidden` 403 to an accountant and `invoice-not-a-draft` 422 to an owner. Existing Milestone 5 behaviour, now pinned by tests and recorded in [ADR 0012](adr/0012-sales-invoice-http-surface.md) D9. A client cannot tell "you lack the permission" from "wrong state" without knowing whether the user is an owner — worth deciding before a front end is written against it |
| — | **The platform-wide route-binding gap** | Nested bindings are not parent-scoped, so a member of two companies can address one company's URL with the other's row. [ADR 0008](adr/0008-sales-http-api-and-customer-update-semantics.md) D6.1 accepted it and recommended one platform-wide fix. Milestone 9 made a narrow exception for the **invoice** routes only, because the audit trail takes its company from the URL — see [ADR 0012](adr/0012-sales-invoice-http-surface.md) D2. Customers, tax codes, accounts and journal entries still carry the gap, and the platform-wide fix is still the right answer |
| — | **`useMoney` and `useFormat` disagree on money** | Two formatters, both shipped. On `1234567.5` the first renders `LKR 1,234,567.50` and the second `LKR 12,34,567.50`, because only the second selects the lakh grouping this market reads. The three report pages and `TrialBalancePage` use `useMoney`; `DashboardPage`, `UsersPage` and `SecurityPage` use `useFormat`. Whichever an invoice screen picks will disagree with half the application, so unify before building one |
| — | **No pagination control exists** | `meta.pagination` is emitted by every paginated endpoint and no page renders it. Harmless on the bounded lists shipped so far; the invoice list is the first genuinely unbounded collection |
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
| [0009](adr/0009-sales-invoice-issuing-cancellation-and-numbering.md) | Sales invoice issuing and cancellation: two number series, one ledger seam (Milestone 5) |
| [0010](adr/0010-receivables-reporting-and-ar-account-identification.md) | Receivables reporting, and how an invoice's receivable account is identified (Milestone 7) |
| [0011](adr/0011-receivables-reporting-frontend-and-http-surface.md) | The receivables reporting HTTP surface and its front end (Milestone 8) |
| [0012](adr/0012-sales-invoice-http-surface.md) | The sales invoice HTTP surface, and the company coherence guard it carries (Milestone 9) |

ADRs are sequential through 0012; the next new one is 0013.

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
