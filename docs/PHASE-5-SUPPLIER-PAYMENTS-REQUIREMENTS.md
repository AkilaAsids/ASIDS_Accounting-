# Phase 5 Wave 8 — Supplier Payments — Requirements

- **Status:** Draft — for Gate 1 (human) review
- **Author:** Business Analyst
- **Date:** 2026-09-02
- **Branch:** `feature/phase5-supplier-payments` (stacked on `feature/phase5-bills`, Wave 7 — delivered, PR #8)
- **Depends on:** the bill domain (`src/Core/Purchasing/Domain/Models/Bill.php`, `BillStatus.php`,
  `src/Core/Purchasing/Application/Services/BillService.php`, `BillPostingMap.php`,
  `src/Core/Purchasing/Infrastructure/EloquentPayableBalanceProbe.php`,
  `docs/adr/0019-bills-purchase-invoices-and-payable-posting.md`) and the supplier domain
  (`docs/adr/0018-purchasing-supplier-domain-foundation.md`).

## 0. A finding that changes this wave's premise — read before the rest

The task brief for this wave was written on the assumption that a customer-receipt feature already
exists in this codebase (`src/Core/Sales/Application/Services/ReceiptService.php`, receipt/allocation
migrations and models, ADRs 0014–0017) and that supplier payments are a Dr/Cr mirror of it. **That
feature does not exist in this repository.**

Verified by direct search before writing a word of the requirements below:

- No `Receipt`, `ReceiptService`, receipt migration, receipt model, or receipt-allocation table exists
  anywhere under `src/` (a repo-wide grep for `Receipt` outside documentation returns nothing; a glob for
  `**/ReceiptService.php` returns nothing).
- ADRs 0014–0017 do not exist. The ADR sequence in `docs/adr/` runs 0001–0012 (Phase 3, sales) then jumps
  to 0018–0019 (Phase 5, purchasing, Wave 6–7). There is no Phase 4 ADR.
- `Account.php` has no `WHT_RECEIVABLE` constant (only `RETAINED_EARNINGS`, `OPENING_BALANCE_EQUITY`,
  `TRADE_RECEIVABLES`, `TRADE_PAYABLES` — `Account.php:60-80`), so the brief's proposed "shape to follow"
  for a new `WHT Payable` liability is itself unbuilt on the receivable side.
- `docs/ROADMAP.md` lists **"Phase 4 — Payments and receivables: Receipts, allocation across invoices,
  unallocated credit held on account, withholding tax on receipt"** under `## 🟢 Firm future scope` (line
  318), not under `## ✅ Completed`. The team evidently reordered Phase 5 (Purchasing) ahead of Phase 4
  (Receipts) — both `SalesInvoice` and `Bill` already reserve `PartiallyPaid`/`Paid` statuses and carry
  inert `amount_paid`/`amount_due` columns for exactly this reason (`SalesInvoiceStatus.php:9-13`,
  `BillStatus.php:9-17`) — but neither side's payment/allocation behaviour has been built yet.

**Consequence:** Wave 8 is not "port an established receipt pattern with debits and credits swapped," the
way Wave 7 (bills) was a genuine line-by-line mirror of the real `SalesInvoiceService`. It is the **first
payment/allocation feature built anywhere in this product**, on either side of the ledger. I have grounded
every requirement below in what does exist — the `Bill`/`BillStatus`/`BillService`/`BillPostingMap` pattern
(the nearest real precedent, itself built to receive exactly this wave, per ADR 0019's own words: "Wave 8
... land on ready targets: the `amount_paid`/`amount_due` columns (held at zero) and the reserved
`cancelled`/payment statuses," ADR 0019 Consequences) — rather than a document I cannot read. This is
flagged again in §7 Risks and in my report to the Delivery Manager, because it plausibly affects the
Architect's effort estimate for Wave 8 relative to what "mirror the receipt ADRs" implied.

## 1. Objective and context

A supplier payment is the accounts-payable mirror of the (still-unbuilt) customer receipt: the company
pays a supplier and allocates the payment across that supplier's posted bills. Posting a payment records
**Dr Trade Payables (settle) = Cr Bank/Cash (paid)** — the mirror image of a customer receipt's
**Dr Bank = Cr Trade Receivables**, with debits and credits swapped, exactly as Wave 7's bill posting
mirrored the sales-invoice posting (`docs/adr/0019-bills-purchase-invoices-and-payable-posting.md`
"Context").

This is the last wave of Phase 5 and completes the purchase-to-pay cycle: supplier master data (Wave 6) →
bill recording and posting (Wave 7) → paying what is owed (Wave 8). The two columns Wave 7 shipped inert
for this exact purpose are the target of this wave's write path:

- `bills.amount_paid` / `bills.amount_due` — shipped at zero, held there by
  `bills_no_payments_until_payments_phase` (`CHECK (amount_paid = 0)`,
  `src/Core/Purchasing/Database/Migrations/2026_09_02_000002_create_bills_table.php:188-192`), commented
  `'Dropped by the payments phase (Wave 8).'` (line 199). Wave 8 owns dropping this CHECK.
- The posted-bill immutability trigger already whitelists `status`, `amount_paid`, `amount_due` as mutable
  after posting (`2026_09_02_000005_make_posted_bills_immutable.php`, per ADR 0019 §A5) — anticipated, not
  yet exercised by any caller.
- `BillStatus::PartiallyPaid` and `::Paid` are declared and reserved in the CHECK
  (`bills_status_check`, `2026_09_02_000002…:124-125`) but unreachable until this wave assigns them.
- `EloquentPayableBalanceProbe::outstandingBalance()` reads `amount_due` today "because they agree" with
  `total - amount_paid`, noting explicitly: **"Wave 8 drops it, and at that point the stored column is the
  one the payment allocation maintains"** (`EloquentPayableBalanceProbe.php:48-51`).

**WHT-on-payment** (supplier-side withholding tax): when paying a supplier, the company may withhold tax
and remit only the net, owing the withheld amount to the tax authority —
`Dr Trade Payables (gross) = Cr Bank (net) + Cr WHT Payable (withheld)`. `WHT Payable` would be a new
**liability**, the opposite side of the (equally unbuilt) `WHT Receivable` asset the brief describes for
the receipt side. Nothing named `WHT` exists anywhere in `src/Core/Accounting` or `src/Core/Sales` today (no
account constant, no `TaxType` case — `TaxType.php` has `Vat`, `Svat`, `Exempt`, `ZeroRated` only). This is
genuinely new ground on both sides of the ledger, not a follow-the-existing-shape addition.

## 2. Wave-8 slicing (proposed)

This wave is large, and the brief itself proposes narrowing it. I recommend following the same
narrow-first discipline Wave 7 used (defer cancellation, defer per-supplier AP override, defer negative
bills — ADR 0019 Gate-1 decisions 2–4):

- **8a — Supplier payments and allocation.** Record a payment against a supplier, allocate it across one
  or more of that supplier's outstanding bills, post `Dr Trade Payables = Cr Bank/Cash`, move each
  allocated bill's `amount_paid`/`amount_due` and status. **Detailed below.**
- **8b — WHT-on-payment.** A new `WHT Payable` liability (system key + chart-template leaf + backfill
  migration for existing companies, mirroring how Wave 7 added `Account::TRADE_PAYABLES` — ADR 0019 §A0),
  splitting the posting into `Dr Trade Payables (gross) = Cr Bank (net) + Cr WHT Payable (withheld)`. Needs
  its own requirements pass once 8a's payment/allocation shape is settled, because it changes the posting
  map's line count and the payment header's fields (a withholding rate/amount per payment or per line).
  **Named, not detailed, per the task brief.**
- **Further optional slices (named, not detailed, not committed):**
  - **Cancellation/reversal of a posted payment** — mirrors the deferral pattern bills already used
    (ADR 0019 Gate-1 decision 2); a payment's status column would reserve a `cancelled` state from the
    start the same way `bills_status_check` reserved it two waves early.
  - **Unallocated credit / prepayment held against a supplier** — paying more than is currently owed, or
    recording a payment before any bill exists. Explicitly out of 8a (§3, §6 item 2).

## 3. In / out of scope for 8a

### In scope
- Recording a payment against a single supplier, from a named bank/cash account.
- Allocating that payment across one or more of the supplier's own **outstanding** bills
  (`BillStatus::isOutstanding()` ⇒ `Posted`/`PartiallyPaid`, `BillStatus.php:65-71`).
- Posting the payment: `Dr Trade Payables (Account::TRADE_PAYABLES, 2110) = Cr` the named bank/cash
  account, for the payment's total amount, in one ledger entry.
- Moving each allocated bill's `amount_paid` (up) and `amount_due` (down), preserving the existing
  invariant `bills_amount_due_check` (`amount_due = total - amount_paid`,
  `2026_09_02_000002_create_bills_table.php:166-170`).
- Transitioning each allocated bill's status: `Posted → PartiallyPaid` (some but not all of `amount_due`
  cleared) or `Posted`/`PartiallyPaid → Paid` (fully cleared).
- Dropping (or relaxing) `bills_no_payments_until_payments_phase` so `amount_paid` may move.
- An internal payment number and/or an optional external reference (open question, §6 item 3).
- Closed-period, cross-company and cross-tenant guards, mirroring every posting path built so far.
- Whatever `Account`/permission additions this needs (an Architect decision — see §6).

### Out of scope for 8a — named, not decided here
- **WHT-on-payment** — Wave 8b (§2).
- **Cancellation/reversal of a posted payment** — further optional slice (§2).
- **Unallocated credit / prepayment on account** — a payment that is not fully allocated to outstanding
  bills at the moment it is recorded. See the full-allocation question at §6 item 2.
- **HTTP/API surface** — no routes, no controllers. Bills themselves have none yet
  (`docs/PHASE-5-BILLS-REQUIREMENTS.md` §2, mirroring the sales-invoice precedent), so payments follow the
  same precedent.
- **Multi-currency payments / FX** — `bills_single_currency_until_fx_phase` remains in force; a payment is
  assumed to be in the bill's (the company's base) currency.
- **Partial payment against a single bill from multiple bank accounts in one transaction**, or a single
  payment split across more than one supplier — one supplier, one bank/cash account per payment record.

## 4. Functional requirements (slice 8a only)

Numbered `AC-n.n`. Every AC cites the real code it grounds against, since there is no receipt precedent to
cite instead.

### Epic 1 — Record and allocate a supplier payment

**US-1.** As an accounts-payable clerk, I want to record a payment to a supplier from a named bank/cash
account and allocate it across that supplier's outstanding bills, so what is owed decreases and the
payment is ready to post.

- **AC-1.1** Given a supplier belonging to the company, a bank/cash account belonging to the same company,
  and at least one allocation naming one of that supplier's outstanding bills and an amount, when the
  payment is recorded, then a payment record is created carrying the supplier, the account, the payment
  date, the total amount (the sum of its allocations) and its allocation lines.
- **AC-1.2** Given no allocation lines, recording is refused — mirrors `bill-has-no-lines`
  (`BillService.php:333-338`): a payment with nothing allocated records nothing.
- **AC-1.3** Given an allocation naming a bill that does not belong to the named supplier, it is refused —
  the payable-side equivalent of validating every account a document points at
  (`BillService.php::assertPostable`, `:616-648`).
- **AC-1.4** Given an allocation naming a bill that is not outstanding (`Draft`, already `Paid`, or
  `Cancelled`), it is refused — mirrors the reasoning behind `Bill::scopeOutstanding()`
  (`Bill.php:213-228`, `BillStatus::isOutstanding()`, `BillStatus.php:64-71`): a draft is not yet owed, a
  paid or cancelled bill no longer is.
- **AC-1.5** Given an allocation naming a bill belonging to a different company (two companies sharing one
  `tenant_id`), it is refused — mirrors `BillCannotBePosted::accountOutsideCompany`
  (`BillCannotBePosted.php:117-135`) and `BillPostingMap`'s explicit note that row level security alone
  does not separate sibling companies (`BillPostingMap.php:34-35`).
- **AC-1.6** Given an allocation amount greater than the target bill's current `amount_due`, it is refused
  (pending the confirmation at §6 item 2) — a single bill cannot be driven to a negative payable by this
  slice.
- **AC-1.7** Given the sum of all allocations does not equal the payment's total amount, recording is
  refused (pending confirmation at §6 item 2 — the "full allocation" default, mirroring how Wave 7 shipped
  its narrowest workable posting first and deferred every optional relaxation, ADR 0019 Gate-1 decisions
  2–4).
- **AC-1.8** Given a payment amount of zero or less, it is refused — mirrors
  `BillCannotBePosted::withZeroTotal` (`BillCannotBePosted.php:60-73`) and `bill-total-negative`
  (`BillService.php:419-425`).
- **AC-1.9** Given a bank/cash account that is not of type `Asset`, is not postable, or does not belong to
  the company, recording (or at latest, posting) is refused — mirrors `resolveExpenseAccount()`'s three
  checks (`BillService.php:552-585`), applied to the bank/cash side. Note: `ChartTemplate.php:80-81` leaves
  `1110 Cash in Hand` and `1120 Bank Accounts` **without a system key** — like `1170 Input VAT Recoverable`
  and unlike `1130`/`2110` — so the account cannot be resolved by key; it must be named per payment,
  exactly as a bill line names its expense account explicitly (§6 item 1).
- **AC-1.10** Given a supplier that is dormant or archived, existing bills may still be paid — mirrors the
  bills-side rule that existing documents are unaffected by a party's later dormancy/archival
  (`SupplierStatus.php` docblock referenced at `BillService.php:529-531`; the *analogous* rule here is
  "you may still pay what you already owe an archived supplier," not "you may bill a new one").

### Epic 2 — Posting: ledger entry and bill balance movement

**US-2.** As an accountant, I want a recorded payment to post `Dr Trade Payables = Cr Bank/Cash` and move
every allocated bill's paid/due amounts and status, so the ledger, the payables subledger and cash all
agree.

- **AC-2.1** Given a payment ready to post, one journal entry is written: a debit to
  `Account::TRADE_PAYABLES` (`2110`) for the payment's total amount, and a credit to the named bank/cash
  account for the same amount — the mirror image of `BillPostingMap`'s
  `Dr Expense + Dr Input VAT = Cr Trade Payables` (`BillPostingMap.php:26-36`), with the payable side now
  the debit.
- **AC-2.2** Given the entry posts, each allocated bill's `amount_paid` increases and `amount_due`
  decreases by its allocated amount, preserving `bills_amount_due_check` at every step
  (`amount_due = total - amount_paid`).
- **AC-2.3** Given a bill's `amount_due` reaches exactly zero, its status becomes `Paid`; given it is
  reduced but not to zero, its status becomes `PartiallyPaid` — mirrors the state names already reserved
  in `bills_status_check` and `BillStatus` (`BillStatus.php:20-24`).
- **AC-2.4** Given a bill reaches `Paid`, it leaves the set `EloquentPayableBalanceProbe::outstandingBalance()`
  sums over (`Bill::scopeOutstanding()`, `Bill.php:213-228`) — the supplier's payable balance drops by
  exactly what was allocated, and a fully paid bill contributes nothing further to it.
- **AC-2.5** The posting, the bill-balance updates, and the payment's own posted-state fields are written
  in **one transaction**, mirroring `BillService::post()`'s `DB::transaction` (`BillService.php:247-286`):
  either everything happens or nothing does.
- **AC-2.6** Given two payments race to allocate against overlapping bills, each affected bill is
  re-checked (locked and re-read) inside the transaction before its balance is moved, mirroring
  `BillService::post()`'s `lockForUpdate()->firstOrFail()` re-check (`BillService.php:250-258`) — extended
  here to **potentially several bill rows in one transaction**, which is new: nothing today locks more than
  one document row per posting transaction. Flagged as a risk at §7.
- **AC-2.7** Given a payment posts into a fiscal period that is closed or locked, it is refused before any
  number is reserved — mirrors `BillCannotBePosted::intoClosedPeriod`
  (`BillCannotBePosted.php:75-95`, checked at `BillService.php:238-244` before the transaction opens).
- **AC-2.8** The ledger entry cites the payment as its source document and cannot be posted twice — mirrors
  the unique `journal_entry_id` on `bills` (`2026_09_02_000002_create_bills_table.php:95`) and the
  `source_id` uniqueness that backs it (ADR 0009 §"Traceability moved to the source document").
- **AC-2.9** A posted payment is immutable — its supplier, account, amount, date and allocations cannot be
  changed or deleted — mirroring `2026_09_02_000005_make_posted_bills_immutable.php` (ADR 0019 §A5); only
  the mutation this wave anticipated on `bills` (`amount_paid`/`amount_due`/`status`) is exempted on the
  *bill* side, and a symmetrical trigger is needed on the new payment table(s) themselves.

### Epic 3 — Numbering

**US-3.** As an accountant, I want each posted payment identifiable by an internal number (and, where the
bank provides one, an external reference), so a payment can be found, quoted and reconciled.

- **AC-3.1** Every posted payment carries an internal number (`PAY-…`, format tbd at Gate 2) assigned at
  posting, not at recording — mirrors both invoices (`issue()`) and bills (`post()`) assigning their number
  inside the posting transaction (`BillService.php:262`).
- **AC-3.2** A payment may optionally carry a free-text external reference (bank transaction id, cheque
  number) supplied by the clerk — whether this is enforced unique per supplier per company, the way a
  bill's `supplier_invoice_number` is (`bills_company_supplier_invoice_number_unique`), is an open question
  (§6 item 8): a payment has no *mandatory* external identifier the way a supplier's own invoice number is
  mandatory on a bill, so the same double-payment guard technique may not transfer directly.
- **AC-3.3** Whether the internal payment number must be gapless (like `SalesInvoice`/`JournalVoucher`) or
  need not be (like `Bill`, `DocumentType::Bill::requiresGaplessNumbering() === false`,
  `DocumentType.php:50-63`) is an open question (§6 item 4): a payment, unlike a bill, is a document the
  company itself originates rather than receives, which argues for gapless; but no authority is known to
  audit our internal payment numbers for completeness either, which argues against paying for it.

## 5. Non-functional requirements

- **Immutability.** A posted payment's identifying fields, amount, allocations, account and supplier are
  frozen the moment it posts — mirrors `bills_immutable`/`asids_bills_immutable()`
  (`2026_09_02_000005_make_posted_bills_immutable.php`, ADR 0019 §A5). A payment table needs its own
  trigger; `bill_lines`-style allocation rows need their own, parent-status-driven freeze
  (`asids_bill_lines_immutable()`, ADR 0019 §A5).
- **FORCED row level security.** Both the payment header and its allocation-line table need their own
  `ENABLE … FORCE ROW LEVEL SECURITY` policy — RLS is not transitive between a parent and child table
  (`SalesInvoiceLine.php:59-64`, restated for bills at ADR 0019 §A6) — mirroring
  `2026_09_02_000004_enable_row_level_security_on_bills.php`.
- **Exact money at `currency_precision`.** All amounts (payment total, allocation amounts, the resulting
  `amount_paid`/`amount_due` movement) go through the existing `Money` value object
  (`src/Core/Accounting/Domain/ValueObjects/Money.php`) at the ledger's scale, the same discipline
  `BillService`/`BillPostingMap` already apply — no float arithmetic, `bccomp` for every comparison against
  a stored balance (mirroring `EloquentPayableBalanceProbe::outstandingBalance()`'s
  `bcadd((string) $sum, '0', Money::SCALE)`, `EloquentPayableBalanceProbe.php:65`).
- **Audit.** The payment record is `Auditable`, with an `auditOnly()`/`auditTags()` pair mirroring
  `Bill::auditOnly()` (`Bill.php:239-261`) — at minimum the supplier, account, amount, date, external
  reference and status are worth a trail entry; allocation lines are not independently audited, mirroring
  "a line has no life of its own" (`Bill.php:36`, `BillLine` is not `Auditable`).
- **Multi-tenant scoping.** `BelongsToTenant` + `company_id` scoping on every new model and query, mirroring
  `Bill.php:74` and the explicit note that the tenant-wide scope does not separate sibling companies
  (`EloquentPayableBalanceProbe.php:34-38`).
- **Permissions.** A new `purchasing.payments.*` capability set, sized once §6 item 7 (whether a
  draft-then-post lifecycle applies) is answered — see §6 item 7 for the shape question and §6 item 9 for
  role grants.

## 6. Assumptions and open questions for the human (Gate 1)

One consolidated list, options framed rather than decided, per the Delivery Manager's process. Item 0 is
the finding in §0 and is the most consequential; the rest follow the brief's requested set plus what
reading the real code surfaced.

0. **There is no existing receipt feature to mirror** (§0). Wave 8 is greenfield payment/allocation design
   on the AP side, grounded in the `Bill`/`BillService`/`BillPostingMap` pattern rather than a real
   `ReceiptService`. Please confirm this is understood before Gate 2 architecture proceeds — it may change
   the effort estimate relative to what "mirror ADR 0017" implied, and it means Wave 8's design choices
   (numbering, allocation policy, duplicate protection) are being made for the *first* time in this product,
   not copied from a working receivable-side precedent.
1. **The bank/cash account** — `1110 Cash in Hand` and `1120 Bank Accounts` are both keyless in
   `ChartTemplate.php:80-81` (no system key, unlike `1130`/`2110`). Should a payment name its account
   explicitly per payment (mirroring how a bill line names its expense account,
   `BillService.php::resolveExpenseAccount`), or should the Architect introduce a per-company default
   "primary bank/cash account" setting (a new concept, not currently modelled anywhere)? I recommend
   caller-named this slice — it needs no new setting and mirrors the one analogous real pattern in the
   codebase.
2. **Full allocation vs. partial/credit-on-account.** Must a payment's allocations sum exactly to its total
   amount (refusing any unallocated remainder), deferring "prepayment" or "credit held against a supplier"
   to a later slice — mirroring how Wave 7 deferred cancellation, per-supplier AP override and negative
   bills (ADR 0019 Gate-1 decisions 2–4) — or should 8a allow an unallocated remainder from the start? I
   recommend requiring full allocation this slice.
3. **Per-bill overpayment.** Given the full-allocation default above, should an individual allocation be
   capped at its target bill's current `amount_due` (no single bill can be driven to a negative payable), or
   is deliberately overpaying one bill (to create a documented credit against it) in scope for 8a? I
   recommend capping at `amount_due` this slice, consistent with item 2.
4. **Numbering — gapless or not.** Should the internal payment number (`PAY-…`) be gapless like
   `SalesInvoice`/`JournalVoucher` (a document we originate), or non-gapless like `Bill`
   (`DocumentType::Bill::requiresGaplessNumbering() === false`, `DocumentType.php:56-63`, reasoning: "no
   authority audits *our* internal bill numbers for completeness")? A payment is closer to "originated by
   us" than "received," which argues for gapless, but I have found no authority in the specification that is
   known to audit internal payment-number completeness either. Flagged for Architect/Gate 2, not decided
   here.
5. **Permission namespace and roles.** `purchasing.payments.{view, ?, post}` mirroring
   `purchasing.bills.{view, draft, post}` (`PermissionCatalogue.php:283-300`) and the accountant/bookkeeper/
   viewer grant split at ADR 0019 §F2 — contingent on item 7 below (whether a middle "draft" capability is
   meaningful for a payment at all).
6. **Is WHT-on-payment truly a separate slice (8b), or does it belong in 8a?** The brief's own framing
   (§2 of the task) proposes 8b; I agree with deferring it — it introduces a new liability account (with its
   own chart-template leaf and backfill migration, mirroring how Wave 7 added `Account::TRADE_PAYABLES`) and
   changes the posting map's shape (a third line, `Cr WHT Payable`), which is exactly the kind of scope
   Wave 7 kept out of its own first cut. Confirming this split, not deciding it silently, is the point of
   this question.
7. **Draft-then-post lifecycle, or record-and-post in one step?** Every posted document built so far
   (`SalesInvoice`, `Bill`) uses a two-step draft→post pattern so a bookkeeper can prepare what an
   accountant commits. A payment represents cash that has, in the real world, already moved — does it need
   the same "draft payment, edit it, then post it" workflow, or is a payment recorded and posted as one
   action (with `.post` as the only sensitive capability, and no `.draft`)? This determines whether
   `BillStatus`-style `Draft` even makes sense for a payment, and shapes item 5's permission set. I lean
   toward "no draft stage — record is post" for a payment specifically (unlike a bill or invoice, there is
   little reason to prepare-then-review a payment that has not yet moved cash), but flag it as the human's
   call since it is a business-process decision, not an architecture one.
8. **Duplicate-payment detection.** A bill has a natural, mandatory external key
   (`supplier_invoice_number`) that grounds a real double-payment guard
   (`bills_company_supplier_invoice_number_unique`, ADR 0019 §A4, Gate-1 decision 5). A payment typically
   has no equivalent mandatory external identifier — a bank reference may or may not be captured (§4 AC-3.2).
   Beyond the database-level "one ledger entry per payment record" guarantee (mirroring the unique
   `journal_entry_id`/`source_id`, which prevents the *same* payment record posting twice), is there a
   business requirement to warn or refuse when two *separate* payment records look like the same real-world
   payment (same supplier, same amount, same date)? I have not found a precedent for this in the codebase
   and recommend leaving it to user diligence and the audit trail for 8a, flagged here rather than decided.
9. **Cancellation deferred — confirm explicitly.** Mirroring ADR 0019 Gate-1 decision 2, I recommend the
   payment's status column (if item 7 concludes a status column is needed at all) reserve a `cancelled`
   case in its CHECK from the start, exactly as `bills_status_check` reserved `cancelled` two waves before
   bill cancellation is built, so a later feature adds behaviour rather than a CHECK-widening migration.

## 7. Risks and dependencies

- **No receipt precedent (§0).** The single largest risk to this wave's estimate and design quality: every
  choice here — allocation policy, numbering, duplicate protection, the draft/post question — is being made
  for the first time in this product rather than copied from a working mirror, unlike Wave 7's bills, which
  had a real, finished `SalesInvoiceService` to copy line-by-line. Recommend the Architect treat this
  explicitly as new design, not a mirroring exercise, when scoping Gate 2 effort.
- **Multi-row locking is new.** `BillService::post()` locks exactly one bill row per transaction
  (`lockForUpdate()->firstOrFail()`, `BillService.php:250-258`). A payment allocating across several bills
  in one transaction needs to lock several rows without deadlocking against a concurrent payment or
  cancellation touching an overlapping set — nothing in the codebase does this yet. A fixed lock order (for
  example, by bill id) is the usual mitigation; left to the Architect, flagged here as a correctness risk
  rather than only a performance one, since a deadlock or a lost update here corrupts a payable balance.
- **`WHT Payable` (8b) is entirely new,** unlike `Trade Payables` (which mirrored an existing
  `Trade Receivables` pattern) or `Input VAT Recoverable` (which mirrored existing keyless `Output VAT
  Payable`). It needs its own `ChartTemplate` leaf, its own system key, and its own backfill migration for
  every existing company — the same three-part pattern Wave 7 just built for `Account::TRADE_PAYABLES`
  (ADR 0019 §A0), but with no existing sibling account to model the leaf's placement on.
- **Coherence with `bills`' existing invariants.** `bills_amount_due_check` and
  `bills_no_payments_until_payments_phase` are both live today
  (`2026_09_02_000002_create_bills_table.php:166-192`). 8a's build must drop or relax the second CHECK
  without breaking the first, and must exercise the `amount_paid`/`amount_due`/`status` mutability the
  immutability trigger already anticipated but no caller has yet used (ADR 0019 §A5) — this is the first
  real test of that anticipated seam.
- **`EloquentPayableBalanceProbe`'s comment becomes literally true.** It reads `amount_due` today "because
  they agree" with `total - amount_paid` while `amount_paid` is held at zero (`EloquentPayableBalanceProbe.
  php:48-51`). Once payments write to `amount_paid`, a regression test should confirm the probe's balance
  still agrees with the bill-level invariant after a partial payment — the comment predicted this wave
  explicitly, which is exactly why it is worth re-verifying rather than assuming.
- **Scope discipline.** 8a must not absorb WHT-on-payment, cancellation, or unallocated credit/prepayment —
  each is named as a further slice (§2) precisely so 8a stays the same size of cut Wave 7 was (draft+post
  only, no cancellation, no per-supplier override, no negative documents).
- **Depends on Wave 7 being merged/stable.** 8a's entire target — `bills.amount_paid`/`amount_due`,
  `BillStatus::PartiallyPaid`/`Paid`, `EloquentPayableBalanceProbe` — is Wave 7 output, currently on open PR
  #8 (stacked on PR #7, suppliers) per `docs/STATUS.md`. No code conflict is expected since 8a only adds new
  tables/columns and relaxes one CHECK, but the branch is stacked on `feature/phase5-bills` per the task
  header, so the dependency is already reflected in the branch plan.

## Report to the Delivery Manager

See the assistant's final message for the doc path, scope summary, the consolidated open-questions list
(verbatim), and risks.
