# Phase 4 — Unallocated Credit Held on Account: Requirements

**Stage: requirements · awaiting Gate 1 approval.** Backend only — no front end, no HTTP surface,
matching the two prior receipts waves. Branch `feature/phase4-credit-on-account`, stacked on
`feature/phase4-cancellation`.

## 1. Objective and context

Today, a customer receipt must be **fully allocated**: `Σ allocations = receipt amount`, or the
whole `record()` call is refused — both over- and under-allocation refuse
(`ReceiptService::assertFullyAllocated()`, throwing `ReceiptCannotBeRecorded::overOrUnderAllocated()`,
`src/Core/Sales/Application/Services/ReceiptService.php:439-453`). This was not an oversight; it was
a deliberate, named decision:

- `docs/PHASE-4-RECEIPTS-REQUIREMENTS.md` §"Out of scope (explicitly deferred)" lists **"Unallocated
  credit held on account"** by name: *"A receipt this wave must be … either fully allocated or
  refused outright; there is no 'credit balance' concept, no customer wallet, and no later 'apply
  this later' flow"* (lines 41-45), and its own OQ-2 calls this *"the single biggest scope-boundary
  question"* (lines 339-344).
- **Gate 1 decision #2** on that document made it binding: *"Under-allocated receipts —
  REJECTED. … Accepting a remainder would implicitly create unallocated credit-on-account, which is
  deferred"* (line 418).
- ADR 0014 §"Decision" repeats it as Gate-1 #2 (*"Receipts must be FULLY allocated … Accepting a
  remainder would implicitly create unallocated credit-on-account, which is deferred — so this wave
  must not grow a column or state that half-builds it"*, lines 22-24) and names it again in **Risk 5,
  "Quietly half-building a deferred feature"**: *"Full-allocation (Gate-1 #2) is enforced so no
  unallocated-credit concept sneaks in"* (lines 365-367).
- `ReceiptService`'s own class docblock states the current invariant outright: *"RECORD-AND-ALLOCATE
  IS ATOMIC … There is no `record` that leaves a receipt unallocated … Accepting a remainder would be
  unallocated credit-on-account, a deferred feature this wave must not half-build"*
  (`ReceiptService.php:41-45`).

**What "unallocated credit on account" means here:** a customer pays more than is needed to clear
the invoice(s) they're paying (or pays with nothing specific to allocate to yet), and the excess
does not vanish, get refused, or get force-allocated — it is held, associated with that customer,
available to reduce a future invoice's balance. This is the deferred boundary those three documents
all point at. This wave is the one that opens it.

**Why now:** the receipts (ADR 0014) and cancellation (ADR 0015) waves are both shipped and
verified (see `docs/PHASE-4-CANCELLATION-REQUIREMENTS.md`/ADR 0015). The full-allocation rule and the
delta-restore-on-cancel design are both stable, tested foundations this wave must stay compatible
with, not redesign.

## 2. In scope / out of scope

### In scope

- Recording a customer receipt whose allocations sum to **less than** the receipt amount
  (`Σ allocations < amount`), with the remainder recorded as unallocated credit associated with that
  customer, instead of being refused as it is today.
- The ledger continuing to balance for such a receipt: Dr Bank = Cr (AR for whatever was actually
  allocated) + Cr (the held-credit amount) — see §3.2. Exactly which account the credit side lands on
  is **not** decided here (§5, OQ-1).
- How the held credit is represented, tracked, and made auditable — functional requirements only;
  the schema/entity shape is the Architect's call (§3.3).
- Keeping cancellation (ADR 0015) correct for a receipt that carries held credit: cancelling such a
  receipt must unwind the credit it created, using the same delta discipline ADR 0015 established for
  invoice balances (§3.5).
- Keeping the existing, already-shipped over-allocation refusal exactly as it is — this wave does not
  touch that half of the invariant (§3.4, §2 "still refused" below).

### The key scope question this document does NOT answer

**Whether *applying* held credit to a later invoice is in this slice, or a further deferral, is the
single open question this wave must resolve at Gate 1** — the direct descendant of the deferred
document's OQ-2. Two shapes are possible, mirroring exactly how ADR 0014 deferred receipt
cancellation and ADR 0015 later built it as its own sub-slice:

- **Option A — apply-credit ships in this slice.** An accountant can apply some or all of a
  customer's held credit to one of that customer's issued invoices, moving `amount_paid`/
  `amount_due`/`status` the same way a receipt allocation does, without any new cash arriving.
- **Option B — apply-credit is deferred again.** This slice only records and tracks held credit;
  it sits inert (visible, auditable) until a further sub-slice adds the ability to consume it. Until
  then, a mis-recorded overpayment is corrected by a manual journal adjustment, the same interim
  workaround `PHASE-4-RECEIPTS-REQUIREMENTS.md` §3.6 named for deferred cancellation before ADR 0015
  shipped it (lines 274-277).

Both shapes are written out fully in §3.3 so the human can pick between them at Gate 1 without this
document pre-deciding.

### Out of scope regardless of the answer above

- **No HTTP surface.** Domain + service + tests only, as both prior receipts waves shipped (ADR 0014
  §"Decision" Gate-1 #5, ADR 0015 §"Context").
- **Over-allocation stays refused exactly as today.** `Σ allocations > amount` is not being relaxed by
  this wave in any direction — only the under-allocation half of the invariant changes.
- **No cash refund of held credit.** Paying held credit back out to the customer in cash is a
  different feature (a payment/disbursement, arguably adjacent to Phase 5 supplier-side payments or a
  dedicated refunds feature) and is not this slice's concern — flagged as OQ-8 so it is not silently
  assumed either way.
- **No customer statement / credit-aging / credit-expiry.** This wave is about correctly holding and
  (maybe) applying a balance, not reporting on it over time.
- **No withholding tax, no multi-currency.** Both remain deferred exactly as ADR 0014 §"Out of scope"
  states them; this wave does not reopen either.
- **No new bank-account entity.** Unchanged from Gate-1 #3 of the receipts requirements — a receipt
  still names an existing postable Asset account.

## 3. Functional requirements

Numbered `AC-CR-<story>.<n>`, continuing the traceability convention of the two prior requirements
documents.

### 3.1 Story: Record a receipt that is not fully allocated

*As an accountant, I want to record a receipt for more than the invoice(s) I'm applying it to, so
the customer's overpayment is tracked rather than the whole receipt being refused.*

- **AC-CR-1.1 Given** a receipt amount and a set of allocation lines whose sum is strictly **less**
  than the receipt amount, **when** I record the receipt, **then** it is accepted (not refused with
  `overOrUnderAllocated()` as it is today), the named allocations post exactly as they do now
  (`ReceiptService.php:226-237`, unchanged), and the remainder (`amount − Σ allocations`) is recorded
  as unallocated credit associated with the receipt's customer.
- **AC-CR-1.2 Given** the same receipt, **when** its posting is examined, **then** the journal entry
  still balances by construction: the debit side is unchanged (Dr the named bank/cash account for the
  full receipt amount, ADR 0014 §C), and the credit side sums to the same total split across (a) the
  trade-receivables account for whatever was actually allocated to invoices, and (b) whatever account
  the Architect designates for the held-credit remainder (§5, OQ-1) — `Money::plus`/`Money::minus`
  arithmetic throughout, never recomputed, matching `InvoicePostingMap`'s "sum stored values" rule
  (ADR 0014 §C).
- **AC-CR-1.3 Given** a receipt whose allocation lines sum to **zero** (no invoice named at all —
  every allocation is empty), **when** I try to record it, **then** whether this is accepted (100% of
  the receipt becomes held credit, no invoice touched) or still refused is an open question (§5, OQ-3)
  — today `ReceiptService::allocationAmounts()` refuses an empty allocation set outright
  (`ReceiptCannotBeRecorded::withoutAllocations()`, `ReceiptService.php:412-416`), which is a second,
  separate invariant from the Σ-equals-amount one this wave is relaxing, and relaxing it is a bigger
  structural change than accepting a remainder against at least one named invoice.
- **AC-CR-1.4 Given** a receipt whose allocation lines sum to **more** than the receipt amount,
  **when** I try to record it, **then** it is refused exactly as it is today
  (`ReceiptCannotBeRecorded::overOrUnderAllocated()` or its renamed successor — §3.4) — this wave does
  not touch that refusal.

### 3.2 Story: The ledger keeps balancing when a remainder is held

*As the business, I want a receipt that leaves money unallocated to still post one correct,
traceable, balancing ledger entry, so the bank balance and every affected control account stay right.*

- **AC-CR-2.1 Given** a receipt of 1,000 allocated 700 to one invoice with a 300 remainder, **when**
  it posts, **then** the entry is: Dr Bank 1,000 / Cr Trade Receivables 700 / Cr [held-credit account]
  300 — three lines, still summing to zero net, still one posting, still through the existing
  `PostingService`/`DocumentNumberService`/`SourceDocument` seam (ADR 0014 §C) with no new posting
  entry point invented.
- **AC-CR-2.2 Given** the existing AC-3.2 refusal (allocations spanning invoices with different
  resolved receivable accounts must refuse rather than mis-post, ADR 0014 §C), **when** a receipt also
  carries a remainder, **then** that refusal is unaffected — the remainder's account is a separate
  question from which receivable account the *allocated* portion credits, and the two must not be
  conflated by whatever the posting map becomes.
- **AC-CR-2.3 Given** the receivable account and the held-credit account happen to be different types
  (Asset vs. Liability, per §5 OQ-1's Option A) or the same subledger family (per Option B), **when**
  the entry is built, **then** it still balances purely by the stored figures summing to zero — the
  posting map's contract (currently "exactly two lines," ADR 0014 §C) must be revisited to allow a
  variable line count, which is an architecture decision, not this document's.

### 3.3 Story: Held credit is tracked, visible, and (if in scope) applicable

*As an accountant, I want to see how much credit a customer is currently holding and where it came
from, so I can apply it to a future invoice or explain it to the customer.*

- **AC-CR-3.1 Given** one or more receipts that left remainders for the same customer, **when** I ask
  "how much unallocated credit does this customer currently hold," **then** the system answers
  correctly and traceably to the receipt(s) that created it — whether that is a running balance on
  `Customer`, a ledger of credit-events, or a per-receipt remainder column is the Architect's design
  (§5, OQ-4: per-receipt vs. pooled).
- **AC-CR-3.2 (only if Option A / apply-credit is in scope) Given** a customer holding credit and one
  of that customer's issued (or partially-paid) invoices, **when** an accountant applies some or all
  of the held credit to that invoice, **then** the invoice's `amount_paid`/`amount_due`/`status` move
  exactly as a normal receipt allocation would (mirroring AC-2.1/AC-2.3 of ADR 0014), the customer's
  held-credit balance decreases by the applied amount, and **no new debit to any bank/cash account is
  posted** — the cash already arrived when the original receipt was recorded; only a reclassification
  entry (Dr held-credit account / Cr Trade Receivables) moves, if the credit was held off the AR
  control account at all (depends on OQ-1).
- **AC-CR-3.3 (only if Option A) Given** an attempt to apply more credit than the customer currently
  holds, **when** the application is attempted, **then** it is refused before anything is written —
  the same "hard invariant, not a warning" discipline AC-2.4 already applies to over-allocating a
  receipt (ADR 0014 §3.2).
- **AC-CR-3.4 (only if Option A) Given** two concurrent attempts to apply the same customer's credit
  (to the same or different invoices) at the same moment, **when** both commit, **then** exactly the
  available balance is consumed, never more — the same lock-and-re-read discipline `record()`/
  `cancel()` already use for invoice `amount_due` (ADR 0014 §F, ADR 0015 §E) must apply to whatever
  holds the credit balance.
- **AC-CR-3.5 (only if Option B) Given** apply-credit is deferred, **when** a receipt records a
  remainder, **then** the held credit is visible and auditable but has no code path that consumes it
  in this slice — an accountant must use a manual journal adjustment to apply it in the interim, the
  same interim workaround already named for deferred cancellation (`PHASE-4-RECEIPTS-REQUIREMENTS.md`
  lines 274-277) before this document's own predecessor (ADR 0015) filled that gap.

### 3.4 Story: Existing refusals and tests must be revisited deliberately, not incidentally

*As QA, I need to know exactly which already-shipped, already-tested behaviour this wave is
intentionally changing, so a flipped assertion reads as a reviewed decision, not a regression.*

- **AC-CR-4.1 Given** ADR 0014's own test strategy explicitly proves *"Σ ≠ amount (both over and
  under) refused"* (ADR 0014 §I, "Allocation invariants," line 331) and the requirements document's
  AC-2.4/AC-4.8 name the same both-directions refusal, **when** this wave ships, **then** the
  under-allocation half of that test must be **replaced** with an acceptance test (asserting the
  remainder becomes tracked credit), and the over-allocation half must **remain** exactly as it is —
  this split must be visible in the test diff, not buried in an unrelated refactor.
- **AC-CR-4.2 Given** `ReceiptCannotBeRecorded::overOrUnderAllocated()` currently names both
  directions in one factory method, **when** only the over-allocation direction still refuses,
  **then** the exception's naming/message should be revisited (e.g. a dedicated
  `overAllocated()`) so a caller is never told "over or under" when only "over" is possible — a
  requirement on clarity (mirroring AC-4.9's "every refusal is a named domain exception with an
  actionable message"), not a decision on the exact name.

### 3.5 Story: Cancelling a receipt must unwind the credit it created

*As an accountant, I want cancelling a mis-recorded receipt to undo everything it did — including any
credit it left on the customer's account — not just the invoices it allocated to.*

- **AC-CR-5.1 Given** a posted receipt of amount X that allocated Y to invoices and held `(X − Y)` as
  credit, **when** it is cancelled via `ReceiptService::cancel()` (ADR 0015), **then** in the same
  transaction that reverses the journal entry and delta-restores the allocated invoices (ADR 0015 §B
  steps 6-7, unchanged), the customer's held-credit balance must also decrease by exactly `(X − Y)` —
  using the same **delta, never a snapshot** discipline ADR 0015 §C proved correct for invoice
  balances, so a later receipt's or a later credit-application's still-live use of that same credit
  pool is not wrongly clobbered by restoring a remembered "before" figure.
- **AC-CR-5.2 (only if Option A / apply-credit is in scope) Given** a receipt's held credit has
  already been partially or fully applied to an invoice by the time someone tries to cancel that
  original receipt, **when** cancellation is attempted, **then** the system must refuse rather than
  drive the customer's credit balance negative — the direct analogue of ADR 0015's
  `wouldReverseBelowZero()` guard (`ReceiptService.php:373-379`,
  `ReceiptCannotBeCancelled::wouldReverseBelowZero()`), but for a credit balance instead of an
  invoice's `amount_paid`. This is a materially new correctness case, not a restatement of an existing
  one, and only exists if Option A ships in or before this slice.
- **AC-CR-5.3 (if Option B) Given** apply-credit is deferred, **when** a receipt with held credit is
  cancelled, **then** nothing has consumed that credit yet, so AC-CR-5.2's guard is unreachable this
  wave — but the credit-tracking entity/column this receipt populated must still be zeroed/reversed on
  cancel (AC-CR-5.1), and the "nothing is left half-done" discipline (ADR 0014 AC-3.6) extends to that
  entity the same way it already extends to the receipt, its allocations, and the ledger entry.
- **AC-CR-5.4 Given** the receipt's own immutability (a posted receipt is frozen apart from the
  posted→cancelled transition, ADR 0015 §A), **when** the held-credit remainder is stored on the
  receipt itself (one design option under OQ-4), **then** it must be included in whatever the
  immutability trigger already protects and in whatever cancellation transition already unlocks —
  consistent with how the three cancellation-metadata columns were added to the same trigger's
  transition guard rather than a new one (ADR 0015 §A).

### 3.6 Story: Interaction with the existing full-allocation code path (regression safety)

*As the business, I want every already-shipped guarantee about receipts that this wave does not
intend to change to keep holding exactly as it does today.*

- **AC-CR-6.1** Recording a receipt that is fully allocated (`Σ allocations = amount`) continues to
  behave exactly as today — no remainder, no credit created, unchanged code path (AC-1.1/AC-2.1 of
  ADR 0014, untouched).
- **AC-CR-6.2** Every existing refusal case in `ReceiptService::record()` unrelated to the
  allocation-sum check — zero/negative amount, customer outside company, bank account
  not-postable/wrong-type/outside-company, allocating to a non-collectable/cross-customer/
  cross-company invoice, per-invoice over-allocation against `amount_due`, closed period — is
  unaffected by this wave and must keep passing exactly as tested (ADR 0014 §I).
- **AC-CR-6.3** Every existing cancellation refusal and guarantee unrelated to held credit — already
  cancelled, not posted, missing/outside-company/non-reversible journal entry, closed reversal period,
  reason required, `receipt_allocations` staying permanent history — is unaffected (ADR 0015 §H).

## 4. Non-functional requirements

- **Immutability discipline.** A posted receipt is frozen by
  `asids_customer_receipts_immutable()` apart from the one posted→cancelled transition ADR 0015 opened
  (`2026_03_08_000004`, widened by ADR 0015 §A). If held credit is represented as a column on the
  receipt itself, it inherits this freeze and must be threaded through the same trigger discipline
  (frozen on every update except the transitions that legitimately touch it) rather than a new,
  separately-reasoned freeze rule.
- **RLS.** Any new table or column this wave adds must get its own `ENABLE`/`FORCE ROW LEVEL SECURITY`
  policy keyed on `tenant_id`, following `2026_03_04_000003_enable_row_level_security_on_sales_invoices.php`
  verbatim (ADR 0014 §A) — RLS is not transitive, exactly the reasoning that already gave
  `receipt_allocations` its own policy despite always joining through its parent receipt.
- **Exact `Money` math.** Every figure — the receipt amount, each allocation, the remainder, the
  running or per-event credit balance — goes through `Money`/`numeric-string` at `Money::SCALE`
  (four decimal places), the same discipline `ReceiptService::record()`/`cancel()` already apply
  throughout (`Money::of()`, `->plus()`, `->minus()`, `->isNegative()`, `->isZero()`). No float ever
  touches a monetary value; no new rounding rule is invented.
- **Audit trail.** `CustomerReceipt::auditOnly()` (`CustomerReceipt.php:172-187`) already lists the
  money, method, account, status and cancellation columns. Whatever entity/column ends up holding the
  remainder must be added to the equivalent audit surface — mirroring how ADR 0015 §D (Gate-1 #6)
  extended `auditOnly()` with the three new cancellation columns rather than leaving them silently
  untracked.
- **Permissions.** Recording a receipt (with or without a remainder) is naturally still
  `sales.receipts.manage` — no new capability is needed just to *create* held credit, since it is a
  side-effect of the existing record operation. **Applying** held credit to an invoice (if Option A) is
  a distinct, separately money-moving action and should be considered for its **own** capability
  rather than folded into `sales.receipts.manage` — mirroring how `sales.receipts.cancel` was
  deliberately added as a **second**, separate capability rather than broadening `manage`
  (ADR 0015 §D, Gate-1 #2: *"A new second capability `sales.receipts.cancel`, separate from
  `sales.receipts.manage`, following the invoice `issue`/`cancel` split"*). This is OQ-6 below, and
  only arises at all if Option A is chosen.
- **Concurrency.** If held credit is a pooled, mutable balance (OQ-4's pooled option), it needs the
  same row-lock-and-re-read discipline `record()`/`cancel()` already give invoice `amount_due` — two
  concurrent operations touching the same customer's credit (one receipt creating it, one application
  consuming it, or two applications) must never leave the balance wrong, the same "never trust a
  figure read before the transaction opened" rule ADR 0014 §F and ADR 0015 §E both apply.

## 5. Assumptions and consolidated open questions for the human (Gate 1)

### Assumptions made while drafting this document

- The over-allocation refusal (`Σ allocations > amount`) is unaffected by this wave; only the
  under-allocation refusal is being relaxed.
- Cancellation (ADR 0015) is a settled, tested foundation this wave must remain compatible with, not
  redesign — the delta-restore discipline it proved for invoice balances is the template for how held
  credit must also be unwound on cancel.
- A receipt still names an existing postable Asset account for its debit side (Gate-1 #3 of the
  receipts requirements, unchanged) — this wave does not touch the bank/cash account model.
- No HTTP surface, no multi-currency, no withholding tax — all unchanged from the prior two waves.

### Consolidated open questions for the human (Gate 1)

1. **What is the accounting treatment / which GL account holds the credit?** Today's chart template
   (`src/Core/Accounting/Domain/Catalogue/ChartTemplate.php`) has no "Advances from Customers,"
   "Customer Deposits," or "Unearned Revenue" liability leaf anywhere under `2100 Current
   Liabilities` (only `2110 Trade Payables`, `2120 Other Payables`, `2130 Accruals`,
   `2140 Output VAT Payable`, `2150-2170` statutory payables exist), and the only system keys the
   platform resolves by name are `Account::RETAINED_EARNINGS`, `::OPENING_BALANCE_EQUITY`, and
   `::TRADE_RECEIVABLES` (`Account.php:60-71`) — there is no fourth key for this. Two broad options,
   neither decided here: **(A)** a new liability account (e.g. "Customer Advances," a new system key,
   posted to on the credit side of the remainder) versus **(B)** a contra-balance held within the
   Trade Receivables subledger itself (the AR control account still nets correctly; the customer's own
   running balance simply goes negative, with no separate GL line). Each has different reporting and
   AR-reconciliation consequences (Milestone 7's reconciliation report, ADR 0014 §C) that only the
   Architect can properly weigh.
2. **Is *applying* held credit to a later invoice in this slice, or a further deferral?** (§2, §3.3).
   If deferred, held credit is recorded and auditable but inert until a later sub-slice — mirroring
   exactly how receipt cancellation was deferred by ADR 0014 and then built as its own sub-slice by
   ADR 0015.
3. **Is a wholly-unallocated receipt (zero invoices named, 100% held as credit) permitted this wave,
   or must every receipt still name at least one invoice, with only the remainder held?** Today
   `ReceiptService::allocationAmounts()` refuses an empty allocation set outright
   (`ReceiptCannotBeRecorded::withoutAllocations()`) — a separate invariant from the one this wave
   relaxes, and lifting it is a bigger structural change than "allow a remainder."
4. **Is held credit tracked per-receipt (each receipt's remainder is its own row, applied
   specifically/FIFO) or as a single pooled running balance per customer (all receipts' remainders
   merge into one number)?** This affects both the delta-restore-on-cancel design (§3.5) and, if
   Option A is chosen, how "apply credit" picks which receipt's credit to consume.
5. **Confirming over-allocation handling is unchanged** — `Σ allocations > amount` continues to refuse
   exactly as ADR 0014 shipped it; this wave touches only the under-allocation direction. (Stated as a
   question only to get an explicit yes at Gate 1, since it is easy to assume "relax the invariant" means
   both directions.)
6. **If apply-credit is in scope (OQ-2 = Option A), what is the permission model?** Reuse
   `sales.receipts.manage`, or add a new capability (e.g. `sales.receipts.apply_credit` or a
   `sales.credit.apply` namespace), following the `manage`/`cancel` split precedent ADR 0015 §D
   established (a new, separate, accountant-granted capability rather than broadening an existing one)?
7. **If apply-credit is in scope, does it produce its own ledger posting** (a reclassification entry,
   Dr held-credit account / Cr Trade Receivables, with its own document type/number) **or is it folded
   into the existing receipt/journal machinery some other way?** This is a new posting shape, not a
   variant of the existing two-line (or now, per §3.2, possibly three-line) receipt posting — left
   entirely to the Architect, but the human should know it is new ground, not a small extension.
8. **Should a customer be able to have held credit refunded in cash rather than applied to a future
   invoice?** Out of scope for this slice by default (§2) — confirming that is not silently expected
   here, since it is a materially different feature (a disbursement, not an allocation) that would
   belong with Phase 5 supplier-side payments or a dedicated refunds feature.

## 6. Risks and dependencies

1. **Accidentally re-implementing this as a full customer-balance/wallet feature.** The pooled-balance
   framing (OQ-4) risks growing into a mini-ledger subsystem — statements, aging, interest, partial
   cash refunds — well beyond "hold this receipt's remainder until an invoice needs it." *Mitigation:*
   this document's §2 "Out of scope regardless" list is deliberately explicit about what stays excluded
   no matter how OQ-1/OQ-2/OQ-4 are answered; the Architect and any reviewer should treat scope creep
   into those items as a Gate-1-worthy escalation, not a quiet addition.
2. **Cancellation interaction is the highest-risk item, mirroring ADR 0015's own top risk.** ADR 0015
   named delta-restore "the correctness pivot" for invoice balances; this wave adds a second balance
   (held credit) that must be delta-restored the same way, and — if apply-credit ships in or before
   this slice — a new "would-go-negative" guard class (AC-CR-5.2) that has no invoice-side analogue to
   copy verbatim. *Mitigation:* treat the credit-balance delta-restore and its negative-balance guard
   with the same design and test weight ADR 0015 §C/§H gave the invoice-side one, including an
   equivalent to the AC-C2.6 multi-event proof table.
3. **Changing an already-shipped, already-tested invariant.** ADR 0014's test suite explicitly proves
   both directions of `Σ ≠ amount` refuse (§I, line 331); this wave deliberately breaks the
   under-allocation half. *Mitigation:* AC-CR-4.1 requires the test change to be a visible, reviewed
   split (over-allocation test unchanged, under-allocation test flipped to an acceptance test), not an
   incidental diff QA discovers later.
4. **No existing GL account resolves this by convention.** Unlike `TRADE_RECEIVABLES`, there is no
   system key or chart-template leaf today for "money held for a customer that isn't revenue yet" —
   whatever OQ-1 decides, it is new chart-of-accounts ground, not a lookup that already exists.
   *Mitigation:* flagged prominently in §5 OQ-1 so the Architect scopes the chart-template change (if
   any) explicitly rather than discovering the gap mid-implementation.
5. **Dependency on both prior receipts waves staying green.** This wave sits on top of ADR 0014
   (record/allocate/post) and ADR 0015 (cancellation); §3.6 lists exactly what must keep passing
   unchanged. Any schema or service change here must not regress either wave's shipped, verified
   behaviour beyond the one deliberate test flip in Risk 3.
6. **Branch/stacking dependency.** `feature/phase4-credit-on-account` is stacked on
   `feature/phase4-cancellation`, so this wave's build inherits ADR 0015's cancellation code as its
   base — coordinate with the DM if cancellation changes further in parallel before this wave merges.

## 7. Existing code this wave must read before designing (for the Architect)

- `docs/adr/0014-customer-receipts-and-allocation.md` — full-allocation rule, the two-line posting map
  contract (§C), the numbering/permission/immutability decisions this wave must stay compatible with.
- `docs/adr/0015-customer-receipt-cancellation.md` — the delta-restore design (§C) this wave's
  credit-unwind-on-cancel must mirror, and the cancellation-metadata migration pattern (§A) this wave's
  own migration (if it touches `customer_receipts`) should follow.
- `src/Core/Sales/Application/Services/ReceiptService.php` — `record()` (`:81-241`) and `cancel()`
  (`:275-401`), especially `assertFullyAllocated()` (`:439-453`, the exact check this wave relaxes) and
  the delta-restore loop in `cancel()` (`:366-389`, the template for restoring held credit too).
- `src/Core/Sales/Domain/Models/CustomerReceipt.php` — the model's docblock, `auditOnly()`
  (`:172-187`), and casts, especially the note that `status` is deliberately a plain string "so nothing
  is gained by narrowing it to a type that would have exactly one case" — the same forward-compatible
  reasoning likely applies to wherever the credit remainder is stored.
- `src/Core/Sales/Database/Migrations/2026_03_08_000001_create_customer_receipts_table.php` and
  `2026_03_08_000004_make_customer_receipts_immutable.php` — the schema and trigger this wave's
  migration (if any) must extend the same way ADR 0015's did, not replace.
- `src/Core/Accounting/Domain/Models/Account.php` (`:53-71`) — the three existing system keys, and the
  absence of a fourth for this feature (§5, OQ-1).
- `src/Core/Accounting/Domain/Catalogue/ChartTemplate.php` (`:96-109`) — today's Current Liabilities
  leaves, confirming no "Advances from Customers"/"Customer Deposits" account ships today.

## Gate 1 decisions — APPROVED 2026-08-26

The human approved the following, which are now **binding** on the architecture (ADR 0016) and build:

1. **Accounting treatment = a NEW liability account "Customer Advances"** (a new `Account` system key + a Current-Liabilities leaf in `ChartTemplate`). The remainder posts to it: **Dr Bank = Cr Trade Receivables (allocated) + Cr Customer Advances (remainder)** — the receipt posting becomes variable-line, not the fixed two-line contract of ADR 0014 §C. (OQ-1 → Option A.)
2. **Apply-credit IS IN SCOPE this wave** — full end-to-end: record → hold → **apply held credit to a later invoice**. Applying reclassifies **Dr Customer Advances / Cr Trade Receivables** and restores the target invoice's balance forward, the mirror of the record-side split. (OQ-2 → include now.) The apply operation's posting shape and permission are for the Architect to design (see below) and confirm at Gate 2.
3. **A receipt must still name ≥1 invoice** — the existing empty-allocation refusal (`ReceiptCannotBeRecorded::withoutAllocations()`) stays; only a *remainder* on an otherwise-allocated receipt is newly permitted. No fully-unallocated (pure-prepayment) receipts this wave. (OQ-3 → require ≥1 invoice.)
4. **Held credit is tracked PER-RECEIPT** (each receipt's remainder is its own credit record), consumed specifically/FIFO on apply, and delta-unwound per-record on cancel. (OQ-4 → per-receipt.)
5. **Over-allocation stays refused** (Σ allocations > amount), unchanged from ADR 0014. (OQ-5 → confirmed.)
6. **Cash refund of held credit is OUT of scope** (a disbursement, not an allocation). (OQ-8 → confirmed out.)

**Deferred to Gate 2 (Architect proposes, human confirms):** OQ-6 the apply-credit **permission** (recommendation: a new accountant-only `sales.receipts.apply-credit` capability, following the `manage`/`cancel` split of ADR 0015 §D — not broadening `manage`); OQ-7 the apply-credit **posting/document shape** (reclassification entry drawing a `JV`, mirror of the record-side split).

**Cancellation interaction (binding):** cancelling a receipt that left held credit must delta-unwind that credit record too (reverse the Customer-Advances credit), and — because apply-credit is in scope — a receipt whose held credit has since been (partly) applied needs a defensive guard analogous to `wouldReverseBelowZero()` so cancellation cannot drive a credit balance negative.
