# ADR 0014 — Customer receipts and allocation: one entry, two subledgers, and the oversell backstop

- **Status:** Accepted
- **Date:** 2026-08-25
- **Milestone:** Phase 4, Milestone A (record + allocate + post; branch `feature/phase4-payments`)
- **ADR number:** 0014. 0013 is reserved for the Phase 3 front-end ADR being written on another branch; both 0013 and 0014 are absent on this branch, and this record deliberately takes 0014 to avoid colliding with it on merge.

## Context

This is the mirror of Milestone 5 (ADR 0009) on the receiving side. Where an invoice turns a draft into a
statutory document that debits Trade Receivables, a receipt records money arriving and credits it back — Dr
Bank/Cash, Cr Trade Receivables — clearing what the invoice raised. Every seam Milestone 5 built for invoices
(`PostingService::postNew()`, `DocumentNumberService::next()`, `SourceDocument`, `JournalEntryData`/`JournalLineData`,
the two-counter numbering, the source-document uniqueness index) is reused exactly, not reinvented.

The requirements (`docs/PHASE-4-RECEIPTS-REQUIREMENTS.md`) and its **Gate 1 decisions — APPROVED 2026-08-25**
section are binding. The decisions that shape this ADR:

1. **Cancellation/reversal DEFERRED.** This wave is record + allocate + post. A posted receipt is immutable. The
   structural boundary is prepared (a status column, a posted-only CHECK, immutability triggers) but no reversal
   path is built — exactly how Milestone 4 prepared the issued boundary before Milestone 5 wired it.
2. **Receipts must be FULLY allocated.** Σ allocations = receipt amount, refused otherwise. Accepting a remainder
   would implicitly create unallocated credit-on-account, which is deferred — so this wave must not grow a column
   or state that half-builds it.
3. **Bank/cash = an existing GL asset account** the caller names per receipt, validated postable/active/asset/in-company.
   No bank-account entity (that is the deferred Banking phase).
4. **`DocumentType::CustomerReceipt`** for the receipt's own gapless number (RCT-…); the journal entry reuses
   `JournalVoucher`; traceability via `source_type`/`source_id` → the receipt; the existing single-post uniqueness
   guard prevents double posting.
5. **No HTTP surface this wave.** Domain + service + tests only, as invoice issuing shipped before its API.
6. **One permission, `sales.receipts.manage`,** granted to the accountant.
7. **Concurrency:** row-lock-and-re-read (as `issue()`/`cancel()`) plus a DB CHECK backstop so racing receipts
   cannot oversell one invoice — designed below.

## Decision

### A — Data model: two tables, both tenant-scoped, both immutable once posted

Two new tables under `src/Core/Sales/Database/Migrations/`, following the `sales_invoices` / `sales_invoice_lines`
header/child shape verbatim, including the denormalised `tenant_id`/`company_id` on the child so RLS and indexing
stay uniform (`2026_03_04_000002_create_sales_invoice_lines_table.php:35-43`).

#### `customer_receipts` (header)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid pk | `HasUuids` |
| `tenant_id` | uuid FK → tenants, cascade | RLS key |
| `company_id` | uuid FK → companies, cascade | denormalised, indexed |
| `customer_id` | uuid FK → customers, **restrict** | must stay resolvable, like `sales_invoices.customer_id` (`:46`) |
| `branch_id` | uuid FK → branches, nullable, nullOnDelete | optional ledger dimension, as on the invoice |
| `number` | string(40), **NOT NULL** | `RCT-2026-08-0001`; there is no draft state this wave, so a receipt carries its number from insert |
| `reference` | string(120), nullable | cheque number / bank transaction id |
| `receipt_date` | date | the tax point that selects the fiscal period |
| `currency_code` | char(3) | company base currency only this wave |
| `amount` | numeric(19,4) | money received, at the ledger's scale of four (`Money::SCALE`) |
| `payment_method` | string(16) | backed by a `PaymentMethod` enum (cash / bank_transfer / cheque / card), CHECK-constrained |
| `bank_account_id` | uuid FK → accounts, restrict | the asset account debited (Gate-1 #3) |
| `status` | string(16), default `'posted'` | forward-compat; only `'posted'` reachable this wave |
| `journal_entry_id` | uuid FK → journal_entries, **unique**, restrict | the one ledger entry; UNIQUE is the double-post backstop, exactly as `sales_invoices.journal_entry_id` (`:94`) |
| `posted_at` | timestamptz | |
| `posted_by_id` | uuid FK → users, nullable, nullOnDelete | who recorded it, written at insert (see §G) |
| `created_by_id` | uuid FK → users, nullable, nullOnDelete | matches the Sales audit-column pattern |
| `created_at` / `updated_at` | timestamptz | |

CHECK constraints:

- `customer_receipts_amount_positive_check CHECK (amount > 0)` — AC-1.2/AC-4.1. The domain error is raised first
  (see §D); this is the database backstop, not the user-facing message.
- `customer_receipts_status_check CHECK (status IN ('posted'))` — posted-only this wave. Written as a one-value
  IN so a later reversal sub-slice widens it deliberately (the same device as `sales_invoices_status_check`
  covering all five states from the start, `2026_03_04_000001:115-116`). Money-decimals are `numeric(19,4)` to
  match `Money`/`numeric-string` exactly, so nothing is approximated crossing the database boundary.
- Currency is held to base by the service, not a phase-scoped CHECK: there is no `exchange_rate` column on a
  receipt (multi-currency receipts are FX-phase work and adding the column now would half-build them).

Index `(company_id, customer_id, receipt_date)` mirroring the invoice header, plus the RLS-convention
`(tenant_id, company_id, status)`. Unique index `customer_receipts_company_number_unique (company_id, number)`
— **not** partial, because `number` is never null here (contrast the invoice's `WHERE number IS NOT NULL`,
which existed only for the draft state receipts do not have, `:109-113`).

#### `receipt_allocations` (child — the subledger detail, never itself a posting)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid pk | |
| `tenant_id` | uuid FK → tenants, cascade | own RLS key (not transitive) |
| `company_id` | uuid FK → companies, cascade | denormalised |
| `customer_receipt_id` | uuid FK → customer_receipts, **cascade** | a line has no life apart from its receipt |
| `sales_invoice_id` | uuid FK → sales_invoices, **restrict** | the allocated invoice must stay resolvable |
| `amount` | numeric(19,4) | the amount applied to this invoice |
| `created_at` / `updated_at` | timestamptz | |

CHECK / unique constraints:

- `receipt_allocations_amount_positive_check CHECK (amount > 0)` — AC-2.9/AC-4.x. A zero line is noise; a negative
  one would silently un-pay an invoice, the reasoning `JournalLineData::isOneSided()` already applies to journal lines.
- `receipt_allocations_unique_invoice UNIQUE (customer_receipt_id, sales_invoice_id)` — one allocation line per
  invoice per receipt, so "this receipt's amount against this invoice" is a single figure the ≤-`amount_due`
  check can reason about, and a double-submit of the same line collides at the index.
- **What is deliberately NOT a CHECK, because a CHECK cannot join to another table:** "per-allocation ≤ invoice
  `amount_due`" (AC-2.5) and "Σ allocations = receipt amount" (AC-2.4, Gate-1 #2). Both are enforced in the
  service under a row lock (§D/§F). The invoice-level backstop below is what makes the first one safe even if the
  service is bypassed.

#### The no-oversell backstop, and the CHECK this wave DROPS

Two invoice-table migrations ship in Stage 1:

1. **DROP `sales_invoices_no_payments_until_payments_phase`** (`2026_03_04_000001:183-187`, the CHECK holding
   `amount_paid = 0`). Safe for existing data: every currently-issued invoice has `amount_paid = 0` today (the
   CHECK guaranteed it), so dropping it violates nothing — confirmed by the requirements §6. It is nonetheless a
   schema change on a production-shaped table and gets Milestone 5's review weight.

2. **ADD `sales_invoices_amount_paid_not_exceeding_total_check CHECK (amount_paid <= total)`.** This is the
   database backstop for AC-5.2 — the guarantee that two racing receipts cannot together drive an invoice's
   `amount_paid` past its `total`, *regardless of what the service does or fails to do*. Today's
   `sales_invoices_non_negative_check` asserts `amount_paid >= 0` but says nothing about the upper bound
   (`:164-168`); this closes it. It is trivially safe for existing rows (`0 <= total`, and `total >= 0` already
   holds). Combined with the existing `sales_invoices_amount_due_check (amount_due = total - amount_paid)`
   (`:158-162`), `amount_paid <= total` is exactly equivalent to `amount_due >= 0` — so the ledger refuses a
   negative outstanding balance by construction. This is the backstop the way the immutability trigger is the
   backstop under the service's own state checks (ADR 0009 §H).

RLS: a third RLS migration adds both tables to an `ENABLE`/`FORCE ROW LEVEL SECURITY` + `_tenant_isolation`
policy block, copying `2026_03_04_000003_enable_row_level_security_on_sales_invoices.php` verbatim (a new
migration, never an edit to an applied one, and `receipt_allocations` gets its **own** policy because RLS is not
transitive — the same reasoning that gives `sales_invoice_lines` its own policy despite always joining through
its parent).

### B — Numbering: `DocumentType::CustomerReceipt`, two counters, the same load-bearing decision as ADR 0009

Add `case CustomerReceipt = 'customer_receipt'` to `DocumentType` (`src/Core/Accounting/Domain/Enums/DocumentType.php`),
with `label() => 'Customer receipt'`, `prefix() => 'RCT'`, and `requiresGaplessNumbering() => true`. This is the
one sanctioned Accounting boundary crossing this wave (as `DocumentType::SalesInvoice` was for Milestone 5).

The receipt draws `RCT-…` from its own `customer_receipt` counter; **its journal entry draws `JV-…` from the
journal-voucher counter**, selected through the existing `JournalEntryData::documentType` parameter at the call
site — no other Accounting code changes. This is ADR 0009's load-bearing decision applied unchanged: a shared
counter under `document_sequences` keyed on `(company_id, document_type, period_key)`
(`DocumentNumberService::next()` `:41-83`) would hand the receipt 0001 and its entry 0002, leaving receipt numbers
running 1, 3, 5 — the gap `requiresGaplessNumbering()` promises never to leave, and what Sri Lankan e-invoicing
audits for. A receipt is a document *and* a ledger entry both exist, so — like the sales invoice, and unlike
`OpeningBalance`/`YearEndClose` — the shared counter would bite. The number is reserved inside the receipt's own
transaction; `DocumentNumberService::next()` refuses to run outside one, so a rollback returns the number rather
than leaving a hole.

### C — Posting: `ReceiptPostingMap`, pure, analogous to `InvoicePostingMap`

A new `ReceiptPostingMap` under `src/Core/Sales/Application/Services/`, built to the same contract as
`InvoicePostingMap`: it **reads, resolves accounts, and returns `list<JournalLineData>` for `PostingService`;
it writes nothing, posts nothing, reserves no number** (`InvoicePostingMap` docblock `:16-21`), so Stage 2 can
test it exhaustively before anything touches the ledger.

The shape of a receipt posting is two lines:

```
Dr  Bank / Cash (the named asset account)    <receipt amount>
    Cr  Trade Receivables                             <receipt amount>
```

- **Debit side** — the `bank_account_id` the receipt names. Resolved and validated by the map: in company,
  `acceptsPostings()` (postable + active), and `type === AccountType::Asset`, raising `ReceiptCannotBePosted`
  factory methods that mirror `InvoicePostingMap`'s account-ownership/postability/type refusals
  (`InvoicePostingMap::assertPostable()` / `accountWithinCompany()`, and `InvoiceCannotBePosted::accountNotPostable()`
  et al.). Because `1110 Cash in Hand` / `1120 Bank Accounts` ship with **no `system_key`** (unlike
  `TRADE_RECEIVABLES`), there is nothing to resolve by key — the caller names the account and the map validates
  it, per Gate-1 #3.
- **Credit side** — Trade Receivables, resolved the **same way** invoices resolve it, by reusing
  `InvoicePostingMap::receivableAccountFor()` (`:97-121`): the customer's own `receivable_account_id` override
  when set, else the company's `Account::TRADE_RECEIVABLES` system account. This is what keeps the subledger and
  the control account agreeing, which is what Milestone 7's AR reconciliation checks (requirements §2.1).

**AC-3.2 — different receivable accounts across allocated invoices.** A receipt is single-customer, and a
customer resolves to one receivable account, so in practice all its invoices share one. But an invoice posted
while the customer had a *different* override than it has now would have debited a different account, and crediting
today's account would leave the old one uncleared. The map therefore resolves the receivable account **per
allocated invoice** via `receivableAccountFor($invoice)`, collects the distinct set, and:

- exactly one distinct account → credit it for the full receipt amount (one balancing line);
- more than one → **refuse** with a dedicated `ReceiptCannotBePosted::receivableAccountsDiffer()` rather than
  splitting the credit or picking one. This is the case the requirements flag as "must be refused rather than
  silently mis-posted."

The entry balances by construction: one debit and one credit, both the stored receipt amount, summed with
`Money::plus` — the same "sum stored values, never recompute" discipline that makes `InvoicePostingMap` balance
(`:39-47`). `source: SourceDocument::for($receipt)` ties the entry to the receipt and, through the partial unique
index `journal_entries_source_document_unique (tenant_id, source_type, source_id) WHERE source_id IS NOT NULL AND
reverses_entry_id IS NULL` (`2026_03_01_000001:63-67`), makes a second posting of the same receipt impossible at
the database. This requires registering `CustomerReceipt::MORPH_ALIAS` in `SalesServiceProvider::registerMorphAliases()`
— `SourceDocument::for()` refuses an unmapped model (`SourceDocument.php:81-90`).

### D — Service: `ReceiptService::record()`, one transaction, `issue()`'s discipline exactly

A new `ReceiptService` under `src/Core/Sales/Application/Services/`, with `record(Company, ReceiptData, ?User)`
that records the receipt, its allocations, the invoice updates, and the posting in **one transaction** (AC-3.6).
It mirrors `SalesInvoiceService::issue()`'s ordering (`:214-308`): *everything that can refuse runs before
anything is reserved.*

**Before the transaction opens** (cheap refusals, no lock, no number — as the invoice map runs before `issue()`'s
transaction):

1. Resolve the customer for the company — refuse cross-company/nonexistent, reusing the
   `SalesInvoiceService::resolveCustomer()` shape (`:641-669`) → `ReceiptCannotBeRecorded::customerOutsideCompany()`
   (AC-1.3).
2. Parse `amount` through `Money::of()` — enforces the four-decimal limit and base currency (AC-1.5); refuse
   `amount <= 0` as a domain error, not a raw constraint (AC-1.2), the discipline of
   `InvoiceCannotBeIssued::withZeroTotal()`.
3. Resolve and validate the bank/cash account (in company, postable, asset) via `ReceiptPostingMap` — AC-1.4.
4. Validate the allocation set against the *pre-read* invoices for a readable early refusal: each invoice belongs
   to the receipt's customer (AC-2.7) and company (AC-2.8), is `Issued` or `PartiallyPaid` and not
   `Draft`/`Cancelled`/`Paid` (AC-2.6, using `SalesInvoiceStatus::isCollectable()`), each allocation `> 0`
   (AC-2.9), and **Σ allocations === receipt amount** (Gate-1 #2 full-allocation; both over- and under-allocation
   refuse — AC-2.4).
5. Fiscal period for `receipt_date` accepts postings, before any number is reserved (AC-3.5, matching `issue()`
   step 5-6 and `FiscalCalendarService::periodFor()`).

**Inside the transaction:**

6. **Lock and re-read every target invoice** with `lockForUpdate()`, ordered deterministically by invoice id to
   prevent deadlock between two multi-invoice receipts. Re-read `amount_due` *through the lock* and re-assert
   status-collectable and `allocation <= current amount_due` (AC-2.5) — the figure the caller saw on screen is
   never trusted, exactly the B5 "re-validate at the moment of commit" discipline (`issue()` step 8, `:249-272`).
7. Resolve the single receivable account across the locked invoices (§C, AC-3.2).
8. Reserve the `RCT-…` number via `DocumentNumberService::next($company, DocumentType::CustomerReceipt, $period)`
   (gapless, inside the transaction).
9. Post the journal via `PostingService::postNew($company, new JournalEntryData(entryDate: receipt_date,
   description: LedgerNarration::limit(...), lines: $receiptPostingMap->for(...), reference: $rctNumber,
   documentType: DocumentType::JournalVoucher, source: SourceDocument::for($receipt)), $actor)` — the `JV-…`
   number and the source link (§B/§C).
10. For each locked invoice, update `amount_paid` and `amount_due` **together in one save** — the
    `sales_invoices_amount_due_check (amount_due = total - amount_paid)` invariant means neither can be written
    without the other in the same statement (requirements §6). Set `status` → `Paid` when `amount_due` reaches
    zero, else `PartiallyPaid` (AC-2.1/2.3). These are the only mutable invoice columns the immutability trigger
    permits (`2026_03_05_000002` docblock; ADR 0009 §I audit found exactly `amount_paid`, `amount_due`, `status`,
    `updated_at` mutable).
11. Save the receipt row (with its number, `journal_entry_id`, `posted_at`, `status='posted'`) and insert the
    `receipt_allocations` rows.

**Refusal exceptions**, following the named-static-factory pattern of `InvoiceCannotBeIssued` /
`InvoiceCannotBePosted` / `InvoiceCannotBeCancelled` (AC-4.9 — never a raw `QueryException` or constraint name):

- `ReceiptCannotBeRecorded`: `zeroOrNegativeAmount()`, `customerOutsideCompany()`, `bankAccountOutsideCompany()`,
  `bankAccountNotPostable()`, `bankAccountWrongType()`, `currencyNotBase()`, `overOrUnderAllocated()` (Σ ≠ amount),
  `intoClosedPeriod()`.
- `ReceiptCannotBeAllocated`: `toNonCollectableInvoice()` (naming Draft/Cancelled/Paid — AC-2.6), `crossCustomer()`,
  `crossCompany()`, `zeroOrNegativeLine()`, `exceedsAmountDue()` (per-invoice > current `amount_due`).
- `ReceiptCannotBePosted`: bank/receivable account type/postability/ownership refusals, and
  `receivableAccountsDiffer()` (AC-3.2).

### E — Permissions: one `sales.receipts.manage`, accountant-only

Per Gate-1 #6, a single sensitive capability, added to `PermissionCatalogue::sales()`
(`src/Core/Authorization/Domain/Catalogue/PermissionCatalogue.php:232-271`):

```php
new PermissionDefinition('sales', 'receipts', 'manage', 'Manage customer receipts',
    'Record a customer receipt and allocate it across issued invoices, posting it to the ledger.',
    sensitive: true, sortOrder: 100),
```

Sensitive because it moves money and posts to the ledger. **One** capability, not the split `issue`/`cancel`
pattern, because this wave has one action (record-and-allocate is atomic — Gate-1 #2) and cancellation is
deferred; the reversal sub-slice adds a second `sales.receipts.cancel` when it lands, exactly as `cancel` arrived
separately for invoices. Granted to the **accountant** template only (`RoleTemplate.php`, alongside
`sales.invoices.issue`/`.cancel` at `:110-113`), matching issue/cancel being accountant-only. No migration:
permissions are code, synchronised by `PermissionSynchroniser`, and the administrator picks it up automatically
via `tenantGrantableNames()` (ADR 0003 / ADR 0009 §H).

A `CustomerReceiptPolicy` under `src/Core/Sales/Policies/` with `viewAny`/`view`/`create`(record) all checking
`sales.receipts.manage` **and** `canAccessCompany()` — company membership and permission are different questions
and both must hold, as in `SalesInvoicePolicy`. Any state check is advisory only; `ReceiptService` is the
enforcement boundary because `Gate::before` short-circuits every policy method for a tenant owner (ADR 0009 §H).
Registered in `SalesServiceProvider::boot()` next to the existing `Gate::policy()` calls, with `ReceiptService`
and `ReceiptPostingMap` bound as singletons in `register()` and the morph alias added to `registerMorphAliases()`.

### F — Concurrency: the design that proves no oversell

`ReceiptService::record()` opens a transaction, `lockForUpdate()`s each target invoice in a deterministic id
order, and re-reads `amount_due` through the lock before accepting any allocation against it — so of two receipts
racing the same invoice, the second queues, re-reads the now-lower `amount_due`, and is refused by
`ReceiptCannotBeAllocated::exceedsAmountDue()` rather than racing to a constraint (AC-5.1, exactly `issue()`'s
lock-and-re-read, `:249-272`). Independent of the lock, the new `sales_invoices_amount_paid_not_exceeding_total_check
(amount_paid <= total)` — equivalently `amount_due >= 0` given the existing `amount_due = total - amount_paid`
invariant — refuses at the database any update that would oversell the invoice, so even a bypassed or buggy
service cannot drive `amount_paid` past `total` (AC-5.2). The deterministic lock order prevents deadlock between
two receipts that each touch the same two invoices in opposite input order. The lock produces the readable
refusal; the CHECK is the backstop that holds when the service does not.

### G — Immutability and audit

`CustomerReceipt` applies `Auditable` and `BelongsToTenant` like `SalesInvoice`, with `auditOnly()` naming the
money and status columns and `auditTags() => ['sales', 'receipt']`, recording who recorded it and when
(`SalesInvoice::auditOnly()`/`auditTags()` `:253-275`). `posted_by_id` is written at insert and never after — the
immutability trigger freezes it, the same "written here or never" rule as `issued_by_id` (`issue()` `:298-302`).

An `asids_customer_receipts_immutable()` trigger (Stage 1) freezes every column once a row exists, modelled on
`asids_sales_invoices_immutable()` (`2026_03_05_000002`). Because receipts are posted-only this wave, the trigger
freezes them outright (there is no draft state and no permitted transition yet); it refuses DELETE and any UPDATE
that changes a money, account, customer, number, or ledger-link column. The `status` column and the trigger's
`WHEN`/guard shape are written so the deferred reversal sub-slice can add a posted → cancelled transition the way
Stage 4 of Milestone 5 added issued → cancelled — the boundary is prepared, not populated. `receipt_allocations`
gets its own immutability trigger (frozen once written; RLS is not transitive and neither is immutability), the
same reason `sales_invoice_lines` has its own (`2026_03_05_000002:116-142`).

Because posted receipts are immutable and cancellation is deferred, **there is no mutation path this wave** — which
is why the invoice-side guard `InvoiceCannotBeCancelled::partiallyPaid()` (already coded, `SalesInvoiceService::cancel()`
`:370-372`) becomes live and correct the moment this ships: an invoice with a receipt allocated against it now has
`amount_paid > 0` and its cancellation stays refused, as ADR 0009 §B7 anticipated.

### H — Build stages (single cohesive backend lane)

This is one lane, not parallelisable into independent lanes — the service depends on the schema, the posting map,
and the model, and the permission wiring depends on the service. So it is staged for reviewability, mirroring
Milestone 5's six stages. Each stage is reviewed against the code before the next begins; three of these decisions
are irreversible once receipts exist (a gapped RCT series cannot be un-gapped, the dropped payments CHECK cannot
be un-dropped without data, a granted permission is hard to withdraw).

| Stage | Delivered | Reviewable artefact |
| --- | --- | --- |
| 1 | Schema: `customer_receipts` + `receipt_allocations` tables, all CHECKs, both RLS policies, both immutability triggers; **DROP** `sales_invoices_no_payments_until_payments_phase`; **ADD** `sales_invoices_amount_paid_not_exceeding_total_check`; `DocumentType::CustomerReceipt` (+ prefix/label/gapless). | Migrations + enum; constraints provable before any code can create a row. |
| 2 | `CustomerReceipt` + `ReceiptAllocation` models (+ `PaymentMethod` enum, morph alias), `ReceiptData`/`ReceiptAllocationData` DTOs, `ReceiptPostingMap` + `ReceiptCannotBePosted`. | The pure map, tested exhaustively before it can touch the ledger — exactly how `InvoicePostingMap` was Stage 2. |
| 3 | `ReceiptService::record()` (validation, lock/re-read, numbering, invoice `amount_paid`/`amount_due`/status updates, posting) + `ReceiptCannotBeRecorded`/`ReceiptCannotBeAllocated`. | The transaction; the concurrency and full-allocation invariants land here. |
| 4 | `sales.receipts.manage` in the catalogue, accountant grant, `CustomerReceiptPolicy`, `SalesServiceProvider` registration (policy + singletons + morph alias). | The authorisation surface, arriving with the operation it guards (ADR 0009 §J — no HTTP exists, so no untestable guard is written early). |

### I — Test strategy (QA asserts test-first)

- **Allocation invariants:** full single-invoice allocation moves `amount_paid`/`amount_due` and flips
  `Issued → Paid`; a partial one flips `→ PartiallyPaid` and never `Paid` for a nonzero balance (AC-2.1/2.3);
  multi-invoice allocation updates each invoice independently (AC-2.2); Σ ≠ amount (both over and under) refused
  (AC-2.4, Gate-1 #2); the `amount_due = total - amount_paid` invariant holds throughout.
- **No-oversell concurrency:** two receipts of 700 racing one invoice with `amount_due` 1000 — one succeeds, the
  other refused, never 1400 allocated (AC-5.1). **Plus a database-level test that bypasses the service** and drives
  `amount_paid` past `total` directly, asserting `sales_invoices_amount_paid_not_exceeding_total_check` refuses it
  (AC-5.2) — the same "bypass the service check entirely" test ADR 0009 uses for the source-uniqueness index.
- **Ledger correctness:** the entry is Dr bank = Cr AR = receipt amount and balances; the AR credit lands on the
  same account the invoices debited (AC-3.1); the AC-3.2 different-accounts case is refused.
- **Numbering:** a multi-receipt run asserts the `RCT-…` series is gapless *and* the `JV-…` series advances
  independently — the multi-document assertion ADR 0009 §B requires, since no single-receipt test exposes a shared
  counter.
- **Double-post prevention:** bypass the service and attempt a second posting for the same receipt; the partial
  unique index refuses it (AC-3.4).
- **Refusals:** every AC-4.x case is a named domain exception with an actionable message (zero/negative amount,
  draft/cancelled/paid invoice, cross-customer, cross-company, over-allocation, bank account not
  postable/wrong-type/outside-company, closed period).
- **RLS scope:** a second tenant cannot read either table; `receipt_allocations` is isolated by its own policy,
  proven by querying the child table directly.
- **Immutability:** a posted receipt and its allocations refuse UPDATE/DELETE at the trigger.

## Risks and mitigations

1. **Oversell under a race (highest risk).** Two receipts racing one invoice could together exceed its
   `amount_due`. *Mitigation:* the two-layer guard — `lockForUpdate()` + re-read for the readable refusal, and
   `amount_paid <= total` CHECK as the database backstop that holds even if the service is bypassed (§F).
2. **Different receivable accounts across allocated invoices (AC-3.2).** A silent mis-post to the wrong control
   account, invisible because the entry still balances. *Mitigation:* the posting map resolves the receivable
   account per invoice, and refuses (`receivableAccountsDiffer()`) rather than splitting or guessing when more
   than one distinct account appears (§C).
3. **Dropping `sales_invoices_no_payments_until_payments_phase` on a production-shaped table.** *Mitigation:* safe
   because every existing invoice has `amount_paid = 0` (the CHECK guaranteed it); the replacement `amount_paid <=
   total` CHECK is added in the same stage and is trivially satisfied by existing rows. Reviewed with Milestone
   5's constraint-work weight (§A).
4. **Deadlock between two multi-invoice receipts.** *Mitigation:* deterministic invoice lock ordering by id (§F).
5. **Quietly half-building a deferred feature.** Full-allocation (Gate-1 #2) is enforced so no unallocated-credit
   concept sneaks in; no `exchange_rate` column is added so multi-currency is not half-built; the `status` column
   and immutability triggers prepare the reversal boundary without building it (Gate-1 #1, §G). The schema stays
   honest about what is deferred.
