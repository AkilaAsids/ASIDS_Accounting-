# ASIDS ERP Cloud — roadmap

**Last updated:** 2026-08-14

This exists because the plan previously lived only in commit messages and conversation, so "what is
left?" had no answer anyone could look up.

Its own accuracy is the first thing to check when a stage or milestone lands — the initial version of this
file shipped saying "Stage 3 in progress" a stage after that stopped being true, which is exactly the
failure it was written to prevent.

## How to read the markers

The distinction matters more than the list. Roughly a third of what follows is decided; the rest is a
proposal, and the two must never be confused when someone plans work against this document.

| Marker | Meaning |
| --- | --- |
| ✅ | Completed and verified |
| 🔵 | In progress |
| 🟢 | **Firm** — the scope is established by existing code or by the approved specification |
| 🟡 | **Proposal only** — not approved, not locked, and not a commitment |

A 🟡 item may be reordered, merged, split or dropped. Nothing below turns 🟡 into 🟢 except an explicit
decision recorded here or in an ADR.

Note also that a phase may have firm **scope** and proposed **ordering**; those are marked separately
where they differ, because "we will build this" and "we will build it fourth" are different claims.

---

## ✅ Completed

### Phase 1 — Platform foundation

Multi-tenancy on PostgreSQL with FORCED row level security, companies, branches, users, sessions and
devices, two-factor authentication, roles and permissions with team scoping, settings, and an
append-only audit trail. Tagged `phase-1`. See [PHASE-1-STATUS.md](PHASE-1-STATUS.md).

### Phase 2 — Accounting core

Fiscal calendar with closing and locking, chart of accounts with a versioned Sri Lankan SME starter
template, immutable double-entry journals enforced by database triggers, gapless document numbering,
per-period balance aggregates, opening balances, period and year close, trial balance and account
ledger. Tagged `phase-2`. See [PHASE-2-STATUS.md](PHASE-2-STATUS.md).

### Phase 3 — Milestones 1 to 4

- **Milestone 1** — the source-document link on `journal_entries`, giving a ledger entry a traceable
  cause and the database-level guard against a document posting twice.
- **Milestone 2** — the customer domain: model, migration, FORCED RLS, service, policy, permissions and
  factory, with the archive-with-balance rule live behind a probe seam.
- **Milestone 3** — tax codes: effective-dated rates behind a GiST exclusion constraint, deterministic
  resolution by company and date that refuses rather than guessing, percentage-to-factor conversion through
  the existing `Money`, and the jurisdictional seam. Decisions recorded in
  [ADR 0006](adr/0006-tax-code-modelling.md).

### Phase 3 — Milestone 4, draft invoices

```
Stage 1 complete — schema, SalesInvoiceStatus enum, constraints, indexes, RLS
Stage 2 complete — models, DTOs, service, totals, discounts, tax integration, audit
Stage 3 complete — policy, permissions, role grants, provider registration, factories
```

Draft invoices only. Issuing, ledger posting, cancellation, numbering and payments are Milestone 5, and the
structural boundary between them is already in the database — a draft cannot carry a number, an issued
timestamp or a ledger link, and those CHECKs refuse every partial version of the transition.

The decisions governing this milestone are recorded in
[ADR 0007](adr/0007-draft-invoice-modelling.md): the product catalogue stays out, a never-issued draft is
hard-deleted, the issued boundary is prepared structurally but enforced by Milestone 5's trigger, a draft may
total zero, and decision B1 was withdrawn after its premise proved false.

### Phase 3 — Milestone 6, the Sales HTTP surface

Delivered **out of numerical order**, on `feature/sales-http-api` by a second team working in parallel
with Milestone 5, and merged through PR #1 as `6a8485c`. That team did not touch the ledger; Milestone 5
did not touch the HTTP layer.

`CustomerController` and `TaxCodeController` with their form requests and API resources, wired at
`companies/{company}/customers` and `companies/{company}/tax-codes` behind the company middleware. Nine
endpoints each. Authorization goes through the **existing** `sales.customers.*` and `sales.tax-codes.*`
abilities and the existing policies — no new permissions. Acceptance tests were written test-first
against the approved contract, and `docs/api/openapi.yaml` is verified against the live route list in CI.

Decisions recorded in [ADR 0008](adr/0008-sales-http-api-and-customer-update-semantics.md). The milestone
also closed five debt items through `CustomerService` hardening — I3, I4, M6, M7 and M8, all struck from
the table below — and made two incidental platform fixes: framework-thrown 403s now render as `forbidden`
rather than `http-403`, and the base `TestCase` forces the array cache store.

The requirements, endpoint contract and the three lane briefs it was built from are in
[SALES-HTTP-API-REQUIREMENTS.md](SALES-HTTP-API-REQUIREMENTS.md),
[SALES-HTTP-API-DESIGN.md](SALES-HTTP-API-DESIGN.md) and [docs/tasks/](tasks/); the delivery record is in
[STATUS.md](STATUS.md).

### Test-database safety guard

`phpunit.xml` boots through `tests/bootstrap.php`, which forces `DB_DATABASE` and `CACHE_STORE` to safe
values before the framework reads the environment. Without it the app container's real `.env` values
leaked past PHPUnit's `<env>` block and `RefreshDatabase` ran `migrate:fresh` against the **development**
database. Conditional, so CI and parallel testing are unaffected. Cherry-picked to `main` as `ea0f421`.

---

## 🔵 Current

### Phase 3 — Milestone 5, issuing

Six stages, deliberately, so each can be reviewed before the next begins. This is where money reaches the
ledger, and it warrants the closest review of the eight milestones.

```
Stage 1 complete — DocumentType::SalesInvoice, the total invariant CHECK, the issued-invoice
                   immutability triggers
Stage 2 complete — InvoicePostingMap, InvoiceCannotBePosted, Account::TRADE_RECEIVABLES with its
                   chart-template registration and idempotent backfill
Stage 3 complete — SalesInvoiceService::issue(): one transaction covering re-validation, gapless
                   number reservation and posting, plus InvoiceCannotBeIssued and the rollback and
                   concurrency tests
Stage 4 not started — cancellation and reversal through PostingService::reverse()
Stage 5 not started — permissions, policy, role grants, removal of the SalesInvoiceLine morph alias
Stage 6 not started — final verification, ADR 0009, roadmap update, CI
```

Stage 2 shipped in `d781c80`, Stage 3 in `9f437c9`. The posting map writes nothing, posts nothing and
reserves no document number — it returns `JournalLineData` for the existing `PostingService`, which is what
let it be tested exhaustively before anything could touch the ledger. Stage 3 connects it to issuing
without changing it.

**Stage 4 has not started.** It concerns cancellation and reversal, and is expected to use the existing
`PostingService::reverse()` rather than new reversal machinery — but its scope goes through the same
inspection and approval process every stage has, and nothing about it is settled here.

The decisions governing this milestone are approved but not yet written up. They are due in Stage 6 as
**ADR 0009** — ADR 0008 was taken by Milestone 6 while this milestone was in progress. Until then the
notes below and the summary in [HANDOVER.md](HANDOVER.md) are the record. Two crossings into the Accounting
module have been explicitly sanctioned — `DocumentType::SalesInvoice` and `Account::TRADE_RECEIVABLES` —
and a third would need raising rather than assuming.

#### Stage 3 decision notes, for ADR 0009

Recorded here because Stage 6 will consolidate them and because the numbering decision in particular is
not something a reader would infer from the code.

**Two counters, not one.** The invoice takes `INV-…` from the `sales_invoice` sequence; its journal entry
takes `JV-…` from the journal voucher sequence. `document_sequences` is keyed on
`(company_id, document_type, period_key)`, so typing the entry `SalesInvoice` would have drawn both numbers
from one counter: the invoice takes 0001, its own entry takes 0002, and the next invoice starts at 0003.
Invoice numbers running 1, 3, 5 is exactly the gap `requiresGaplessNumbering()` promises never to leave,
and every single-invoice test passes either way — only a sequence of them exposes it. Both series are now
independently gapless, and a test asserts that across three invoices.

**`JournalVoucher` was selected through the existing seam.** `JournalEntryData::documentType` is already a
constructor parameter, so Stage 3 passes it at the call site. No new `DocumentType`, no change to the
numbering architecture, and no third Accounting boundary crossing — the Stage 3 commit touches three Sales
files and nothing else.

**Traceability moved to the source document.** The entry no longer declares itself a sales invoice through
`document_type`, so the link is `source_type`/`source_id`, which is the stronger one: the unique index over
`source_id` across non-reversing entries is what makes a second posting of the same invoice impossible.
Double issuance is therefore refused by the database, not only by the service's status check — a test
proves it by bypassing the service check entirely.

**This also leaves Stage 4 room.** `PostingService::reverse()` copies the original entry's document type,
so a cancellation will draw its mirror from the journal voucher counter and never consume an invoice
number.

**The B5 re-validation split.** `assertIssuable()` covers the customer, the branch and tax-code company
ownership. The accounts — receivable, revenue and tax output, with their ownership, postability and type
rules — are left to `InvoicePostingMap`, which already validates them and runs before the transaction
opens, so its refusals cost nothing either. The split is a division of responsibility, not duplicated
validation. Tax-code company ownership was a real gap: the map verifies the output *account* belongs to the
company, but nothing verified the *code* did.

**Permissions were deliberately deferred to Stage 5.** `issue()` enforces state and domain rules only.
There is no `sales.invoices.issue` ability and no `SalesInvoicePolicy::issue()`; the authorization layer
arrives with the rest of Stage 5. Nothing HTTP-facing calls `issue()` yet, so nothing is exposed meanwhile.

### Phase 3 — remaining milestones 🟢

Scope firm; these follow from the approved Phase 3 design.

| Milestone | Scope |
| --- | --- |
| 7 | Reports — outstanding balance, aged receivables, AR control reconciliation |
| 8 | Front end — customer and invoice screens, routing, Vitest |

Neither waits on Milestone 5, and Milestone 5 does not wait on them.

---

## 🟢 Firm future scope

Each of these is committed by something already in the repository or by the approved specification. The
**scope** is firm. Where the **position in the sequence** is not, it says so.

### Phase 4 — Payments and receivables

Receipts, allocation across invoices, unallocated credit held on account, withholding tax on receipt.

Firm because Phase 3's boundary was defined against it: `amount_paid` and `amount_due` ship on the
invoice in Milestone 4 specifically so this phase adds behaviour rather than a migration.

### Phase 5 — Purchasing

Suppliers, bills, supplier payments. Named in `DocumentType`'s docblock as arriving with its own phase,
and `tax_codes.input_account_id` already exists and is type-validated for it.

### Multi-currency and FX 🟢 scope · 🟡 ordering

`journal_lines` already carries nullable `transaction_currency_code`, `transaction_amount` and
`exchange_rate` columns behind a CHECK constraint that this phase drops, and the codebase refers to "the
FX phase" in fourteen places. The work is committed; where it falls in the order is not — it could move
earlier for an exporting customer.

### Payroll 🟢 scope · 🟡 ordering

The specification names EPF, ETF and PAYE/APIT, which exist only inside payroll. Likely the largest
single phase in the project.

### Sri Lankan compliance pack 🟢 scope · 🟡 ordering

`CompliancePackContract` is the seam, and `NullCompliancePack` is still bound for `LK`. Covers TIN and
NIC validation, VAT and SVAT mechanics, RAMIS and IRD filing, and e-invoicing.

There is a known ordering tension: gapless invoice numbering was built for e-invoicing and already
exists, while SVAT's suspended-payment accounting was deliberately deferred out of Milestone 3. Parts of
this may need pulling forward rather than waiting for the whole pack.

### Platform hardening and scale 🟢 scope · 🟡 ordering

Horizon, Meilisearch and S3 are in the mandated stack and none is wired up.

This is also where the **external penetration test** belongs. `SECURITY-REVIEW.md` is deliberately
unsigned pending it, and for a commercial product that is a release gate rather than a formality.

### International expansion 🟢 scope · 🟡 ordering

Australia, Singapore, the UAE and India — one `CompliancePackContract` implementation each, which is
what the seam was built for.

---

## 🟡 Proposed only

**Not approved. Not commitments.** Listed so they are not forgotten, not so they can be scheduled.

### Banking and reconciliation 🟡

Bank accounts, statement import, transaction matching, reconciliation. Not named anywhere in the code or
the specification. Included because an accounting product is difficult to sell without it, which is an
argument rather than a decision.

### Items and inventory 🟡

The product and service catalogue, and stock if wanted. Deliberately excluded from Phase 3 by decision
A1/D8: invoice lines are free text with a required revenue account, and a catalogue is a separate domain
that would need its own CRUD, API and screens.

### Financial statements and reporting 🟡

Today only the trial balance and the account ledger exist. The `FinancialStatement` enum and
`AccountType::statement()` are already in place, so the groundwork is laid, but the profit and loss
account, balance sheet, cash flow statement and VAT return are all unbuilt.

### Ordering proposals 🟡

The sequence in which FX, payroll, compliance, platform hardening and international expansion are taken
is **proposed, not settled**. Two orderings are worth arguing about specifically:

- Banking before FX assumes a Sri Lankan SME banking in rupees. Usually right; wrong for an exporter.
- Payroll is large enough that it may deserve its own track rather than blocking the accounting line
  behind it.

---

## Cross-cutting technical debt

Not phases. Each is a known issue carried deliberately, with the decision recorded rather than the fix
applied. They are listed here so they are visible outside the commit messages that record them.

| Ref | Issue | Note |
| --- | --- | --- |
| **M5** | `CustomerFactory` is not exercised by any test or seeder | Untested code that later milestones rely on |
| **N3** | Same-workspace 403-vs-404 existence oracle | A caller can distinguish "exists but forbidden" from "does not exist". Platform-wide — accounts and journals share the pattern — so it wants fixing once across all modules rather than per module. Raised by Milestone 6's security review and recorded in [STATUS.md](STATUS.md) |
| — | `Gate::before` grants a tenant owner every ability | Short-circuits *state* preconditions in policies, so any `capabilities` block must ask the model for state as well as the gate. Root cause unfixed by product decision — changing it would subject owners to membership-based company access |
| — | `TenantProvisioningService` does five things in one transaction and depends outward on three modules | ADR 0005 predicted the extraction point; each new module adds tables to it |
| — | Two competing patterns: Phase 1 repositories versus Phase 2 services querying models directly | Pick one before a third module is written. The largest architectural decision still open |
| — | A stale `// EXPERIMENT: temporarily disabled` comment sits above an **active** `RecordRequestContext::class` in `bootstrap/app.php` | Comment-only: the middleware is registered and running, so the comment states the opposite of the truth |
| — | `tests/bootstrap.php` keys on the substring `"testing"` | Any future database name lacking it is silently redirected to `asids_erp_testing`. Fails in the safe direction, but it is now a naming convention to honour |

### Closed by Milestone 6

Five items were resolved by the `CustomerService` hardening that shipped with the Sales HTTP surface, and
are recorded here rather than deleted because older notes still describe them as open. The reasoning is
in [ADR 0008](adr/0008-sales-http-api-and-customer-update-semantics.md).

| Ref | Was | Resolution |
| --- | --- | --- |
| **I3** | `CustomerService::update()` could not distinguish "clear this field" from "field not supplied" — it took a whole `CustomerData` DTO | ✅ Takes a partial attribute array with `array_key_exists()` semantics, matching `TaxCodeService::update()` and `ChartOfAccountsService::update()`. **This was the hard blocker on Milestone 6's `PUT` semantics; it is gone** |
| **I4** | Customer code generation read-then-inserted with no lock, so a concurrent create surfaced as a raw `QueryException` | ✅ `UniqueConstraintViolationException` is translated to `ResourceConflict` (409) |
| **M6** | `credit_limit` and `payment_terms_days` were in `Customer::$fillable` | ✅ Removed |
| **M7** | `CustomerService::archive()` hardcoded scale 4 | ✅ Uses `Money::SCALE` |
| **M8** | `applyAttributes()` assigned before validating | ✅ Validates first |

---

## What this document is not

It is documentation. Phase 3 Milestones 1 to 4 and 6 are built, and Milestone 5 is built as far as Stage
3. Nothing exists for anything beyond that, and none of it should be created on the strength of being
listed here. A 🟡 item in particular carries no authority to write code.
