# Phase 4, Milestone A — Customer Receipts and Allocation: Requirements

**Stage: requirements · awaiting Gate 1 approval.** Backend only — no front end in this wave.
Branch `feature/phase4-payments`, off `main`.

## 1. Objective and context

Phase 3 closed with every sales invoice able to reach `issued`, post to the ledger, and carry
`amount_paid` / `amount_due` columns that have sat frozen at `total` / `0` since Milestone 4,
behind the phase-scoped CHECK `sales_invoices_no_payments_until_payments_phase (amount_paid = 0)`
(`src/Core/Sales/Database/Migrations/2026_03_04_000001_create_sales_invoices_table.php:183-187`).
`docs/ROADMAP.md` records this as deliberate: "`amount_paid` and `amount_due` ship on the invoice
in Milestone 4 specifically so this phase adds behaviour rather than a migration." This wave is
that behaviour, for the first slice only.

**Objective:** let a company record money received from a customer and allocate it across that
customer's issued invoices, so `amount_paid`/`amount_due`/`status` move correctly and the ledger
receives one balancing entry per receipt (Dr Bank/Cash, Cr Trade Receivables), following the exact
patterns Milestone 5 established for invoices: gapless numbering, source-document traceability,
double-post prevention, and the re-validate-at-the-moment-of-commit discipline (ADR 0009).

### In scope (Milestone A)
- Recording a customer receipt: customer, date, amount, method, bank/cash account, reference.
- Allocating a receipt across one or more of that customer's **issued** invoices — full and
  partial allocation.
- The invariants: total allocated ≤ receipt amount; per-invoice allocation ≤ that invoice's
  current `amount_due`.
- The effect on each allocated invoice: `amount_paid`/`amount_due` updated, status moved to
  `partially_paid` or `paid`.
- The ledger posting for the receipt: one balancing entry, Dr Bank/Cash Cr Trade Receivables
  (or the customer's own receivable account, matching what the allocated invoices themselves
  post to), gapless numbering, source-document link, no double-post.
- Refusal cases: over-allocation, allocating to a non-issued invoice (draft/cancelled/paid),
  cross-company invoice, zero/negative amounts.
- Reversal/cancellation of a receipt — **proposed, not committed**; see §3.6 and the open
  questions.

### Out of scope (explicitly deferred — do not build)
- **Withholding tax on receipt.** No WHT line, no WHT account, no WHT rate resolution. A receipt
  this wave nets to exactly the amount received; nothing is split off to a tax liability.
- **Unallocated credit held on account.** A receipt this wave must be (by policy — see open
  question OQ-2) either fully allocated or refused outright; there is no "credit balance"
  concept, no customer wallet, and no later "apply this later" flow. If the human answers OQ-2
  the other way (accept an under-allocated receipt and hold the remainder), that is a scope
  change back into this deferred item and must return to Gate 1, not be absorbed silently.
- Any front-end screen. This is a backend-only wave, matching the Sales invoice HTTP precedent
  (Milestone 9) of shipping the domain/service layer before any HTTP surface, though whether HTTP
  is even in this wave is itself open — see OQ-7.
- Supplier-side payments (Phase 5).
- Multi-currency receipts (FX phase; `sales_invoices.exchange_rate` is held NULL by its own
  phase-scoped CHECK and this wave does not touch it).

## 2. Domain model additions needed (requirements, not schema)

The Solution Architect owns the actual schema, migration shape, and file layout. What follows is
what the domain needs to be *able to represent*, grounded in what already exists.

### 2.1 Already exists — do not re-add
- `sales_invoices.amount_paid`, `.amount_due`, `.status` (including `PartiallyPaid`/`Paid` in
  `SalesInvoiceStatus`) — columns and enum cases ship today; only the CHECK holding
  `amount_paid = 0` needs dropping, and `amount_due = total - amount_paid` needs to keep holding
  (`src/Core/Sales/Database/Migrations/2026_03_04_000001_create_sales_invoices_table.php:158-187`).
- `SalesInvoice::scopeCollectable()` already treats `Issued`/`PartiallyPaid` as the live-receivable
  set — the allocation-eligible set for this wave is exactly `Issued` and `PartiallyPaid` (not
  `Draft`, not `Cancelled`, not already `Paid`).
- `DocumentType` enum, `DocumentNumberService`, `PostingService::postNew()`/`reverse()`,
  `JournalEntryData`/`JournalLineData`, `SourceDocument`, the append-only `journal_entries`
  table with its immutability trigger and `reverses_entry_id`/`reversed_by_entry_id` pair — all
  reusable exactly as Milestone 5 reused them for invoices; no new ledger machinery is implied by
  this slice.
- `Money::allocate()` (`src/Core/Accounting/Domain/ValueObjects/Money.php:175-232`) — the
  largest-remainder splitter that guarantees parts sum exactly to a whole with no lost or invented
  minor unit. This is the kind of arithmetic an allocation-across-invoices feature needs (e.g. if
  a partial receipt amount is later split by weight); flagging its existence so the Architect does
  not re-invent it, not prescribing that this feature must use it — a receipt allocated by
  explicit per-invoice amounts the caller states may not need it at all.
- `Account::TRADE_RECEIVABLES` system key and `InvoicePostingMap::receivableAccountFor()` — the
  customer's own `receivable_account_id` overrides the system default; a receipt's credit side
  must resolve the **same** account the allocated invoice(s) originally debited, for the same
  customer, so the subledger and the control account keep agreeing (this is what Milestone 7's AR
  reconciliation report checks).
- Permission catalogue pattern (`accounting.journals.post`/`.reverse`,
  `sales.invoices.issue`/`.cancel`) — two related but separately grantable sensitive capabilities
  is the established shape for "commit to the ledger" vs. "undo something already committed."

### 2.2 New, needed by this slice
- **A receipt entity** — company, customer, receipt date, amount received, payment method,
  the bank/cash account it landed in, an external reference (cheque number / transaction id),
  status (e.g. posted; whether a cancelled/reversed state exists depends on §3.6), audit
  columns matching the `created_by_id`/tenant/RLS pattern every Sales table already carries.
- **A receipt allocation entity (one or more lines)** — receipt, invoice, amount allocated. This
  is the subledger detail: it is *not* itself a ledger posting. Only the receipt as a whole posts
  once to the ledger; allocation lines exist purely to drive each invoice's `amount_paid`/
  `amount_due` and to give an auditor the "this receipt paid these invoices, in these amounts"
  trail that the ledger entry alone cannot show (the ledger entry nets to one Trade Receivables
  figure; it cannot answer "which invoices").
- **A designated bank/cash account concept.** Today's chart template ships `1110 Cash in Hand`
  and `1120 Bank Accounts` as ordinary leaf Asset accounts with **no `system_key`** — unlike
  `TRADE_RECEIVABLES`, there is no stable handle to resolve "the" cash/bank account by. This wave
  needs a requirement for *how a receipt names which asset account it debits* (any postable Asset
  account the caller names vs. a constrained "bank/cash" subset vs. a new system key/account
  category). This is a real gap, not an oversight to paper over — see OQ-4.

## 3. Functional requirements

Numbered `AC-<story>.<n>` for traceability. "Company" below always means "the invoice's/receipt's
company," and every rule that names a customer, account, or invoice implicitly also requires it to
belong to the same company as the receipt (mirroring `SalesInvoiceService::resolveCustomer()` /
`resolveRevenueAccount()` / `assertIssuable()`'s company-ownership checks, which exist precisely
because two companies share one `tenant_id` and RLS alone would let one company reach another's
rows).

### 3.1 Story: Record a customer receipt

*As an accountant or bookkeeper, I want to record money received from a customer, so that it exists
in the system before I decide which invoices it pays.*

- **AC-1.1 Given** a customer, a receipt date, a positive amount, a payment method, a bank/cash
  account, and an optional reference, **when** I record a receipt, **then** it is created against
  that customer and company, dated as given, and captures every one of those fields exactly.
- **AC-1.2 Given** an amount of zero or a negative amount, **when** I try to record a receipt,
  **then** it is refused with a domain error (not a raw database constraint violation), the same
  discipline `InvoiceCannotBeIssued::withZeroTotal()` follows for invoices.
- **AC-1.3 Given** a customer that does not belong to this company, or does not exist, **when** I
  try to record a receipt against it, **then** it is refused — matching
  `SalesInvoiceService::resolveCustomer()`'s "belongs to a different company, or does not exist"
  refusal, not a raw foreign-key error.
- **AC-1.4 Given** a bank/cash account that is not postable, not active, or does not belong to this
  company, **when** I try to record a receipt against it, **then** it is refused with a domain
  error naming the problem — matching `InvoicePostingMap`'s account-ownership/postability/type
  checks for invoices.
- **AC-1.5 Given** a receipt amount expressed with more than four decimal places, or in a currency
  other than the company's base currency, **when** I try to record it, **then** it is refused —
  `Money::of()` already enforces the four-decimal-place limit and this wave does not accept a
  currency other than the invoice's own (multi-currency receipts are FX-phase work).

### 3.2 Story: Allocate a receipt across issued invoices

*As an accountant, I want to apply a recorded receipt against one or more of the customer's issued
invoices, so each invoice's outstanding balance reflects what has actually been collected.*

- **AC-2.1 Given** a receipt for customer X and one issued invoice of X's with `amount_due` ≥ the
  full receipt amount, **when** I allocate the whole receipt to that invoice, **then** the
  invoice's `amount_paid` increases by the allocated amount, `amount_due` decreases by the same
  amount (the existing `amount_due = total - amount_paid` invariant holds throughout), and the
  invoice's status becomes `Paid` if `amount_due` reaches zero, else `PartiallyPaid`.
- **AC-2.2 Given** a receipt larger than any single invoice's `amount_due`, **when** I allocate
  parts of it across two or more of the customer's issued invoices, **then** each named invoice's
  `amount_paid`/`amount_due`/status update independently and correctly, and the sum of the
  allocations against this receipt never exceeds the receipt's own amount (AC-2.4).
- **AC-2.3 Given** an invoice with `amount_due` of, say, 1,000 and a receipt of 400 allocated to
  it, **when** the allocation is recorded, **then** `amount_paid` becomes 400, `amount_due`
  becomes 600, and status becomes `PartiallyPaid` — never `Paid` for a nonzero remaining balance.
- **AC-2.4 Given** allocation lines whose amounts sum to more than the receipt's total amount,
  **when** I try to save them, **then** the whole operation is refused before anything is written
  — "total allocated ≤ receipt amount" is a hard invariant, not a warning.
- **AC-2.5 Given** an allocation line naming an amount greater than that invoice's *current*
  `amount_due` (re-read at the moment of allocating, not the amount someone saw on screen
  earlier), **when** I try to save it, **then** it is refused — the same "re-validate at the
  moment of commit" discipline ADR 0009 §B5 established for issuing, applied here because a
  invoice's `amount_due` can change between the moment a user opens the allocation screen and the
  moment they submit it (another receipt could land in between).
- **AC-2.6 Given** an invoice that is `Draft`, `Cancelled`, or already `Paid`, **when** I try to
  allocate any part of a receipt to it, **then** it is refused with a domain error naming which of
  the three states it is in and why that refuses allocation — `Draft` and `Cancelled` have never
  been or are no longer a live receivable at all; `Paid` has nothing left to allocate against.
- **AC-2.7 Given** an invoice belonging to a different customer than the receipt names, **when**
  I try to allocate to it, **then** it is refused — a receipt only ever pays its own customer's
  invoices.
- **AC-2.8 Given** an invoice belonging to a different company than the receipt, **when** I try to
  allocate to it, **then** it is refused — the cross-company check every Sales service already
  makes, because two companies share one `tenant_id` and RLS alone permits either one's rows.
- **AC-2.9 Given** an allocation amount of zero or negative, **when** I try to save it, **then** it
  is refused — an allocation line of zero is noise (mirroring `JournalLineData::isOneSided()`'s
  refusal of a zero-sided journal line), and a negative one would silently un-pay an invoice
  through the wrong door.

### 3.3 Story: The receipt posts to the ledger exactly once

*As the business, I want every receipt to produce one correct, traceable, un-repeatable ledger
entry, so the cash/bank balance and the trade receivables control account both stay right.*

- **AC-3.1 Given** a receipt with its allocation(s) validated, **when** it is committed, **then**
  exactly one journal entry is posted: a debit to the named bank/cash account for the full receipt
  amount, and a credit to the trade receivables account (the customer's own override if set, else
  the company's `TRADE_RECEIVABLES` system account — resolved the same way
  `InvoicePostingMap::receivableAccountFor()` resolves it for invoices) for the same amount. The
  entry balances by construction, the same way `InvoicePostingMap` sums stored line values rather
  than recomputing a total.
- **AC-3.2 Given** a receipt whose allocations span invoices with *different* resolved receivable
  accounts for the same customer (should not happen in practice, since one customer resolves to
  one receivable account, but must be refused rather than silently mis-posted if it ever does),
  **when** the receipt is committed, **then** it is refused rather than posting a Trade
  Receivables credit against the wrong account, or split across two — flagged here as a case the
  Architect must design against, not solve in this document.
- **AC-3.3 Given** the posting, **when** a number is issued for it, **then** it is drawn from its
  own gapless document-number series inside the same transaction as the posting (following
  `DocumentNumberService::next()`'s "must be called inside the document's own transaction" rule),
  and — per ADR 0009's load-bearing decision — the **receipt's own number and the journal entry's
  number are two separate counters**, not one, for exactly the reason ADR 0009 §B gives for
  invoices: a shared counter under `document_sequences` keyed on `(company_id, document_type,
  period_key)` would draw both from one series and leave the receipt's own numbering non-gapless
  the moment more than one receipt is issued in a period. Whether the ledger side types itself
  `JournalVoucher` (as invoices do) or gets a new `DocumentType::CustomerReceipt` is an
  architecture decision, not this document's to make — see OQ-6.
- **AC-3.4 Given** a receipt that has already posted, **when** any process attempts to post it a
  second time (a double-submit, a retry, a race), **then** the database refuses the second
  posting at the unique-index level — the same guarantee
  `journal_entries.source_id`'s partial unique index (excluding reversing entries) gives invoices
  today, applied with the receipt as the source document via `SourceDocument::for($receipt)`.
- **AC-3.5 Given** the fiscal period the receipt date falls into, **when** that period does not
  accept postings (closed), **then** the receipt is refused before any document number is
  reserved — matching `SalesInvoiceService::issue()`'s ordering ("everything that can refuse runs
  before anything is reserved").
- **AC-3.6 Given** the whole record-and-allocate-and-post operation, **when** any part of it fails,
  **then** none of it is left half-done: no receipt row without its allocations, no allocations
  without the invoices actually being updated, no invoice update without the ledger posting — one
  transaction, matching `SalesInvoiceService::issue()`'s "one transaction covering re-validation,
  numbering and posting."

### 3.4 Story: Refusal cases (consolidated)

Restated here as their own story because "what must NOT be allowed" is as much a requirement as
what must be:

- **AC-4.1** Recording a receipt with a zero or negative amount is refused (= AC-1.2).
- **AC-4.2** Allocating to a draft invoice is refused (= part of AC-2.6).
- **AC-4.3** Allocating to a cancelled invoice is refused (= part of AC-2.6).
- **AC-4.4** Allocating to an invoice already fully paid is refused (= part of AC-2.6).
- **AC-4.5** Allocating to an invoice outside the receipt's customer is refused (= AC-2.7).
- **AC-4.6** Allocating to an invoice outside the receipt's company is refused (= AC-2.8).
- **AC-4.7** Over-allocating a single invoice beyond its current `amount_due` is refused (= AC-2.5).
- **AC-4.8** Over-allocating a receipt beyond its own total amount is refused (= AC-2.4).
- **AC-4.9** Every refusal above is a named domain exception with a message a user can act on
  (`BusinessRuleViolation`/a dedicated exception, following `InvoiceCannotBeIssued` /
  `InvoiceCannotBePosted` / `InvoiceCannotBeCancelled`'s pattern of named static factory methods
  per case) — never a raw `QueryException` or constraint name surfaced to a caller.

### 3.5 Story: Concurrency — two receipts racing the same invoice

*As the business, I want two people recording receipts against the same invoice at the same moment
to never together overpay it, even though each saw the same "outstanding" figure when they
started.*

- **AC-5.1 Given** invoice with `amount_due` 1,000, and two receipts of 700 each submitted for
  allocation against it at the same moment, **when** both commit, **then** exactly one succeeds in
  full, and the second is either refused outright (over-allocation against the now-lower
  `amount_due`) or reduced/refused per whatever policy the Architect designs — but the two must
  never together allocate 1,400 against a 1,000 invoice. This requires the same discipline
  `SalesInvoiceService::issue()`/`cancel()` use: lock the invoice row (`lockForUpdate()`) and
  re-read its current `amount_due` *inside* the transaction before accepting an allocation against
  it, never trusting the figure the caller read before the transaction opened.
- **AC-5.2** The application-level lock is not the only guard: the database itself must refuse an
  invoice ending up with `amount_paid > total` or `amount_due < 0` regardless of what the service
  layer does or fails to do — a CHECK constraint, following `sales_invoices_non_negative_check`'s
  existing pattern, is the backstop the way the immutability trigger is the backstop under
  `SalesInvoiceService`'s own state checks.

### 3.6 Story: Reversing or cancelling a receipt — proposed, flagged for the human

*As an accountant, I want to undo a receipt that was recorded in error (wrong amount, wrong
customer, wrong invoice), the same way `SalesInvoiceService::cancel()` lets me undo an issued
invoice without deleting anything.*

This story is **proposed, not committed**, and is the single largest open question this document
raises (OQ-1). Two shapes are possible and neither is decided here:

- **Option A — full receipt cancellation.** Reverse the ledger entry (`PostingService::reverse()`,
  exactly as invoices do), roll back every allocation line, and return each affected invoice's
  `amount_paid`/`amount_due`/status to what it was before this receipt touched it. This mirrors
  `SalesInvoiceService::cancel()` closely, including "which period must be open is the reversal's,
  not the receipt's."
- **Option B — deferred entirely.** Ship recording and allocation only, with no way to undo a
  receipt in this slice; a mis-recorded receipt is corrected by a manual journal adjustment
  outside this feature until a later milestone adds proper reversal. This keeps the slice
  narrower, at the cost of accountants having no clean undo path for a real and common mistake
  (wrong amount typed, wrong invoice picked).

**If the human picks Option A**, acceptance criteria in the shape of AC-2.x and ADR 0009's §B7
("an invoice with money received against it is refused [cancellation]" — already coded and
waiting for this phase, `InvoiceCannotBeCancelled::partiallyPaid()`) interact directly: cancelling
an invoice that has a live, unreversed receipt allocated against it must stay refused, exactly as
that guard already states. A receipt's own cancellation would need the mirror rule: refuse
(or explicitly redesign) reversing a receipt that has already been superseded by, say, the invoice
itself being cancelled through some other path — this needs the Architect's design, not this
document's invention.

## 4. Non-functional requirements

- **Immutability and audit trail.** Once a receipt has posted, its money fields and its ledger
  link must become immutable the same way `sales_invoices_number_matches_status_check` and
  `asids_sales_invoices_immutable()` make an issued invoice immutable apart from status and
  payment figures — a posted receipt is a statutory record, not a draft. The receipt (and its
  allocation lines) must be `Auditable` the way `SalesInvoice` is, recording who recorded it, what
  it changed, and when (`SalesInvoice::auditOnly()`/`auditTags()` is the pattern to follow).
- **Tenant and company RLS.** The receipt and allocation tables must get their own
  `ENABLE ROW LEVEL SECURITY` / `FORCE ROW LEVEL SECURITY` policies keyed on `tenant_id`, following
  `2026_03_04_000003_enable_row_level_security_on_sales_invoices.php` verbatim — RLS is not
  transitive, so the allocation-lines table needs its own policy even though it always joins
  through a receipt, exactly as `sales_invoice_lines` needs its own policy despite always joining
  through `sales_invoices`.
- **Concurrency / no oversell.** Covered in §3.5 (AC-5.1/5.2) — row-level locking in the service
  plus a database CHECK backstop, matching the two-layer protection Milestone 5 built for issuing
  and cancelling.
- **Decimal/Money correctness.** Every amount in this feature — receipt amount, allocation amount,
  the resulting `amount_paid`/`amount_due` — must go through `Money`/`numeric-string` the way
  `SalesInvoiceService` already does; no float ever touches a monetary value. `Money::allocate()`
  is available if any split-by-weight arithmetic is needed (see §2.1); nothing in this feature may
  invent its own rounding.
- **Least-privilege permission.** Recording a receipt and allocating it are both money-touching
  and ledger-posting actions and should be modelled as their own sensitive capability/capabilities
  under a `sales.receipts.*` (or `accounting`-scoped — Architect's call) namespace, following the
  `sales.invoices.issue`/`.cancel` precedent of two separately grantable sensitive capabilities
  rather than one broad one — whether "record" and "allocate" need to be split the way "issue" and
  "cancel" are is OQ-8.
- **Numbering cost.** Per `DocumentType::requiresGaplessNumbering()`'s own documented cost, giving
  receipts a gapless series serialises receipt issuance per company per period, same as every
  other statutory document type. Worth naming because a high-volume receipts flow (many small
  cash receipts in a day) will queue on this exactly as invoicing does.

## 5. Assumptions and consolidated open questions

### Assumptions made while drafting this document
- A receipt is always against exactly one customer (never a receipt split across unrelated
  customers).
- A receipt's currency is the company's base currency for this wave (no FX).
- "Issued" and "PartiallyPaid" are the only two invoice statuses eligible to receive an
  allocation; `Paid` has nothing left to allocate against and `Draft`/`Cancelled` were never or
  are no longer live receivables.
- The receivable account a receipt credits must match what the allocated invoice(s) themselves
  debited (customer override or system `TRADE_RECEIVABLES`), so the AR control reconciliation
  report (Milestone 7) continues to agree with the ledger.

### Consolidated open questions for the human (Gate 1)

1. **Is receipt cancellation/reversal in scope for this slice, or deferred?** (§3.6). If deferred,
   accountants have no clean undo path for a mis-recorded receipt until a later milestone — is
   that acceptable for this wave?
2. **Is an under-allocated (partially-applied) receipt rejected outright this wave, since
   unallocated credit-on-account is explicitly deferred?** i.e., must a receipt be recorded and
   allocated in one atomic operation that fully allocates it, or can a receipt exist recorded but
   not yet (fully) allocated, sitting in some interim state, without that state being a form of
   "credit held on account"? This is the single biggest scope-boundary question — get it wrong and
   the slice quietly re-implements the deferred feature.
3. **What is the bank/cash account selection model?** Today `1110 Cash in Hand` / `1120 Bank
   Accounts` are ordinary Asset leaves with no `system_key`, unlike `TRADE_RECEIVABLES`. Does a
   receipt accept any postable Asset account the caller names, or does this wave need a new way to
   mark/select "bank and cash" accounts specifically (a new system key, an account
   sub-classification, a company setting)? Left entirely to the Architect, but the human should
   confirm whether a real bank-account entity (with its own identity, e.g. for a later
   reconciliation phase) is expected to start now or whether "any Asset account" is acceptable for
   this slice.
4. (folds into 3 — kept as one question, numbered 3, not duplicated)
5. **What is the permission naming?** `sales.receipts.*` (matching `sales.invoices.*`) versus an
   `accounting`-scoped name, and whether "record a receipt" and "allocate a receipt" need to be
   two separately grantable capabilities (as `issue`/`cancel` are) or one combined one.
6. **Does the ledger entry a receipt posts need its own `DocumentType` case, or does it reuse
   `JournalVoucher`** the way invoices' postings do (ADR 0009 §B rejected a new Accounting
   `DocumentType` for invoices specifically to avoid a third boundary crossing that phase; whether
   the same reasoning applies here, or whether a receipt is different enough to warrant
   `DocumentType::CustomerReceipt`, is an architecture call).
7. **Is any HTTP surface in scope for this wave at all**, or is this — like Milestone 5's issuing
   and cancellation before Milestone 9 shipped its API — domain/service layer only, with HTTP
   deferred to its own later milestone the way invoice issuing sat unreachable from any route for
   several milestones? The task brief says "no front-end," but says nothing about an HTTP/API
   layer specifically.
8. See Q5 — restated for clarity: one combined "manage receipts" capability, or split
   record/allocate the way issue/cancel are split?
9. **Multiple bank/cash accounts per company, and how a receipt states which one it landed in** —
   is this expected to be a free-form account picker in this wave, with an actual "bank account"
   domain entity (statement import, reconciliation) deferred to the separate 🟡-proposed "Banking
   and reconciliation" phase the roadmap already lists as not approved?

## 6. Risks and dependencies

- **Dependency on Milestone 5's posting machinery being reused correctly, not reinvented.** Every
  acceptance criterion above assumes the receipt's ledger posting goes through the *existing*
  `PostingService`/`DocumentNumberService`/`SourceDocument` seam the same way `InvoicePostingMap`
  does for invoices. If the Architect finds a reason those seams do not fit a receipt (e.g. the
  "one receipt debits one bank account, credits a receivables account that must match several
  different invoices' resolved accounts" case flagged in AC-3.2), that is a real architecture
  finding to escalate, not a gap to quietly patch over in this document.
- **Risk: `amount_due`'s CHECK invariant (`amount_due = total - amount_paid`) means allocation and
  invoice-update must be inseparable** — there is no way to write `amount_paid` without the
  database itself demanding a matching `amount_due` in the same statement, which is good (it is a
  hard backstop) but means the service must always compute and write both together, never one
  then the other.
- **Risk: dropping `sales_invoices_no_payments_until_payments_phase` is itself a migration this
  wave must ship**, and it interacts with every existing row — every currently-issued invoice
  already has `amount_paid = 0`, so dropping the CHECK is safe for existing data, but it is a
  schema change on a table with production-shaped rows and should get the same review weight
  Milestone 5's constraint work got.
- **Risk: cross-team/file collision.** Following the Sales HTTP API precedent, if any other lane
  is concurrently touching `SalesInvoiceService`, `SalesServiceProvider`, or `routes/api.php`,
  coordinate before this wave's service registration lands.
- **Dependency: the bank/cash account question (OQ-3) blocks the posting-map design** — the
  Architect cannot finalize how a receipt resolves its debit account without the human's answer,
  so this should be resolved early rather than discovered mid-implementation.
- **Risk: WHT and unallocated-credit are deferred, but the domain model must not accidentally
  foreclose them.** E.g., if OQ-2 is answered "receipts may be recorded without full allocation,"
  the receipt entity is implicitly growing an unallocated-credit concept a milestone early; the
  Architect should keep the schema honest about which of the two deferred features, if either, is
  quietly being half-built by the answer to OQ-2.

## 7. No existing receipt/payment code found

A repository-wide search (`grep -rniE "receipt|payment" src/ database/migrations`) turns up only
prose references — comments anticipating this phase, `payment_terms_days` on `Customer`, and the
`amount_paid`/`amount_due` columns already discussed above. There is no `Receipt`, `Payment`,
`ReceiptAllocation`, or similar model, migration, service, policy, or controller anywhere in
`src/Core`. This slice starts from nothing, not from a partial scaffold.

## Gate 1 decisions — APPROVED 2026-08-25

Human-approved resolutions to the open questions (binding for architecture and build):

1. **Receipt cancellation/reversal — DEFERRED** to a follow-up sub-slice. This wave is record + allocate + post; a posted receipt is immutable for now. (Prepare the structural boundary but do not implement reversal, mirroring how M5 prepared issuing before wiring it.)
2. **Under-allocated receipts — REJECTED.** A receipt must be fully allocated (Σ allocations = receipt amount). Over- and under-allocation both refuse. Accepting a remainder would implicitly create unallocated credit-on-account, which is deferred.
3. **Bank/cash account — pick an existing GL asset account** (e.g. 1110/1120) per receipt; validate it is a postable asset account in the company. No new bank-account entity (that belongs to the deferred Banking phase).
4. **Ledger posting — mirror ADR 0009.** Add `DocumentType::CustomerReceipt` for the receipt's own gapless number; the journal entry reuses `JournalVoucher`; traceability via `source_type`/`source_id` → the receipt; the existing single-post uniqueness guard prevents double posting.
5. **HTTP surface — NONE this wave.** Domain + service layer + tests only (as invoice issuing shipped before its API). API and front-end come later.
6. **Permission — one `sales.receipts.manage`**, granted to the accountant (matching issue/cancel being accountant-only).
7. Concurrency: row-lock-and-re-read (as `issue()`/`cancel()`) + a DB CHECK backstop so racing receipts cannot oversell one invoice — Architect to design.
