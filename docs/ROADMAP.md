# ASIDS ERP Cloud — roadmap

**Last updated:** 2026-08-11

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

### Phase 3 — Milestones 1 to 3

- **Milestone 1** — the source-document link on `journal_entries`, giving a ledger entry a traceable
  cause and the database-level guard against a document posting twice.
- **Milestone 2** — the customer domain: model, migration, FORCED RLS, service, policy, permissions and
  factory, with the archive-with-balance rule live behind a probe seam.
- **Milestone 3** — tax codes: effective-dated rates behind a GiST exclusion constraint, deterministic
  resolution by company and date that refuses rather than guessing, percentage-to-factor conversion through
  the existing `Money`, and the jurisdictional seam. Decisions recorded in
  [ADR 0006](adr/0006-tax-code-modelling.md).

---

## 🔵 Current

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

### Phase 3 — remaining milestones 🟢

Scope firm; these follow from the approved Phase 3 design.

| Milestone | Scope |
| --- | --- |
| 5 | Issuing — `DocumentType::SalesInvoice`, the posting map, duplicate guards, cancellation, the invoice immutability trigger, and the positive-total rule |
| 6 | HTTP surface — endpoints, requests, resources, policies, OpenAPI |
| 7 | Reports — outstanding balance, aged receivables, AR control reconciliation |
| 8 | Front end — customer and invoice screens, routing, Vitest |

Milestone 5 warrants the closest review of the eight: it is where money reaches the ledger.

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
| **I3** | `CustomerService::update()` cannot distinguish "clear this field" from "field not supplied" | **Must be resolved before Milestone 6 finalises HTTP `PUT` semantics** — see below |
| **I4** | Customer code generation reads-then-inserts with no lock | The unique index prevents duplicates, so this is an error-shape defect: a concurrent create surfaces as a raw `QueryException` rather than a 409 |
| **M5** | `CustomerFactory` is not exercised by any test or seeder | Untested code that Milestone 4 will start relying on |
| **M6** | `credit_limit` and `payment_terms_days` are in `Customer::$fillable` | Currently inert — the service assigns directly — but a future `fill()` would bypass `resolveCreditLimit()` validation |
| **M7** | `CustomerService::archive()` hardcodes scale 4 instead of `Money::SCALE` | Cosmetic today; a scale change would miss it |
| **M8** | `applyAttributes()` assigns before validating | The transaction rolls the database back; the in-memory model keeps the invalid values |
| — | `Gate::before` grants a tenant owner every ability | Short-circuits *state* preconditions in policies, so any `capabilities` block must ask the model for state as well as the gate. Root cause unfixed by product decision — changing it would subject owners to membership-based company access |
| — | `TenantProvisioningService` does five things in one transaction and depends outward on three modules | ADR 0005 predicted the extraction point; each new module adds tables to it |

### I3 blocks Milestone 6

Stated separately because it is the only item on this list with a hard dependency.

Milestone 6 exposes `PUT /companies/{companyId}/customers/{customerId}`. A customer has nullable fields
a user must be able to clear — the branch, the receivable account override — and the current DTO cannot
express the difference between omitting a field and clearing it. Shipping the endpoint first would bake
that ambiguity into the public API, where changing it later is a breaking change rather than a
refactor.

**I3 must therefore be resolved before Milestone 6's `PUT` semantics are finalised.** Milestone 3
sidestepped it rather than inheriting it: `TaxCodeService::update()` takes an attributes array and uses
`array_key_exists()`, following `ChartOfAccountsService::update()`, which is the one mechanism in the
codebase that expresses the distinction. That is a precedent for the eventual fix, not the fix itself.

---

## What this document is not

It is documentation. No migration, model, service, module or endpoint exists for anything above Phase 3
Milestone 3, and none should be created on the strength of being listed here. A 🟡 item in particular
carries no authority to write code.
