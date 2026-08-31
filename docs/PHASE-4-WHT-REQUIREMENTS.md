# Phase 4 — Withholding tax (WHT) on customer receipts: requirements

- **Status:** Draft — awaiting Gate 1 (human requirements approval).
- **Date:** 2026-08-31.
- **Wave:** Phase 4, withholding-tax sub-slice. Branch `feature/phase4-withholding-tax`, stacked on `feature/phase4-credit-on-account` (ADR 0016, mid-build at the time of writing).
- **Author:** Business Analyst. This document does **not** decide architecture, schema, or account codes — every design fork is left to the Architect at Gate 2 and is marked as an open question below (§5).

---

## 1. Objective and context

A Sri Lankan customer paying an invoice may be statutorily required to **withhold income tax (WHT)** on the payment and remit only the net cash to the supplier, remitting the withheld portion to the Inland Revenue Department (IRD) on the supplier's behalf. The customer subsequently issues (or is expected to issue) a **WHT certificate** the supplier — this company — uses as evidence to claim the withheld amount as a credit against its own income tax liability when it files its own return.

From this company's point of view as the receiving business:

- The invoice was raised, and remains payable, for its **gross** amount.
- The customer's payment settles that gross receivable **in full**, even though only the **net cash** physically arrives — the missing portion is not a discount, a write-off, or unallocated credit. It is an **asset the company now holds**: a claim against its own future income tax liability.

In accounting terms, a receipt with WHT is, at minimum:

```
Dr  Bank (net cash)
Dr  WHT Receivable (withheld)
    Cr  Trade Receivables (gross, i.e. Σ allocations)
```

This is a natural extension of ADR 0014's two-line receipt posting (`Dr Bank / Cr Trade Receivables`, `docs/adr/0014-customer-receipts-and-allocation.md` §C) — but on the **debit** side, whereas ADR 0016's just-landed unallocated-credit-on-account work extended the **credit** side (`Cr Customer Advances` for an overpayment remainder, `docs/adr/0016-unallocated-credit-on-account-and-apply-credit.md` §C). The two must compose: a receipt could, in principle, carry both a WHT debit line and a Customer Advances credit line in the same posting, and it must still balance:

```
cash received + WHT withheld = Σ allocations (gross, allocated portion) [+ remainder held as Customer Advances]
```

This wave sits entirely at **receipt-record time**. Nothing about invoice *issuing* (ADR 0009/Milestone 5) anticipates WHT, and nothing needs to — the invoice still raises the gross receivable; WHT is a fact about how a specific payment settled it.

## 2. In scope / out of scope

**In scope:**

- Recording WHT withheld against a customer receipt (or against its individual allocation lines — see open question OQ-3), for the **allocated portion** of the receipt.
- Posting the withheld amount to a WHT-receivable-type GL account, coexisting with ADR 0014's Trade Receivables credit and ADR 0016's variable-line Customer Advances credit.
- Refusing invalid WHT input: negative, exceeding what it is claimed against, or finer than the company's `currency_precision`.
- The interaction with **cancellation** (ADR 0015): cancelling a WHT receipt must reverse the WHT line along with the rest of the entry.
- The interaction with **apply-credit** (ADR 0016 §D–§E): confirming whether applying held credit to a later invoice ever involves WHT (working assumption: no — see §3, User Story 6).
- A WHT certificate reference field, if the business needs to record the customer's certificate/document reference against the receipt (see OQ-5).
- Backend only: domain + service + tests, no HTTP — mirroring every receipt wave to date (ADR 0014 §A5, ADR 0015, ADR 0016 §I: "No HTTP surface. Domain + service + tests only.").

**Out of scope (explicitly):**

- **Supplier-side WHT** — this company withholding tax when it pays its own suppliers. That is Phase 5 (Purchases) territory; `tax_codes.input_account_id` already exists, reserved and unused, precisely for that later phase (ADR 0006 §A5 / migration comment `2026_03_03_000001_create_tax_codes_table.php:62-68,159`). Nothing in this wave touches the purchasing side.
- **WHT return/filing to the IRD** — any compliance-pack reporting, reconciliation, or claim-against-income-tax workflow. This wave posts and holds the WHT receivable; using it (e.g., netting it against `8100 Income Tax Expense`, `ChartTemplate.php:149`) at year-end is a distinct, later feature. ADR 0006 §A2 already establishes the seam for jurisdiction-specific statutory logic (`CompliancePackContract`) — WHT filing belongs there, not in the receipt service.
- **Any HTTP surface** this wave.
- **WHT on the Customer Advances remainder** — whether a customer's withholding could ever apply to the *unallocated* portion of an overpayment (rather than only to the invoiced/allocated portion) is an open question (OQ-4), not a decision. This document's working assumption is that WHT attaches only to the allocated portion, because withholding is a statutory percentage of a specific payment against a specific supply, not of money that is not yet earned income.

## 3. Functional requirements

Acceptance criteria are numbered `AC-WHT-<story>.<n>` for traceability, following the `AC-CR-x.y` convention ADR 0016 used for the previous wave.

### User Story 1 — Record a receipt with WHT withheld

*As an accountant recording a customer receipt, I want to record the amount a customer withheld as tax when I record their payment, so that the invoice's receivable is fully cleared even though only the net cash arrived, and the withheld amount is tracked as an asset the company can later claim.*

- **AC-WHT-1.1 (the posting shape).** Given a receipt where the customer withheld tax against the invoice(s) being settled, when the accountant records the receipt with the cash amount actually received, the invoice allocation(s), and the WHT amount withheld, then the receipt posts:
  ```
  Dr  Bank / Cash               <cash amount>
  Dr  WHT Receivable            <WHT amount>
      Cr  Trade Receivables            <Σ allocations>
      Cr  Customer Advances            <remainder>   (only when positive, ADR 0016 §C)
  ```
  balancing by construction (`cash + WHT = Σ allocations [+ remainder]`), summing **stored values, never recomputed** — the same discipline `ReceiptPostingMap::for()` already applies to `Σ allocations` (`src/Core/Sales/Application/Services/ReceiptPostingMap.php:70-76`).
- **AC-WHT-1.2 (regression safety).** Given a receipt that carries no WHT (today's ordinary case, and the overwhelming majority of receipts), when it is recorded, then the posting is byte-identical to what ADR 0016 already produces (two lines when fully allocated, three when there is a remainder) — WHT must be an additive, opt-in line, never a change to the no-WHT path, mirroring ADR 0016's own AC-CR-6.1 regression discipline.
- **AC-WHT-1.3 (refusal — WHT exceeds what it is claimed against).** Given a WHT amount greater than the invoice/allocation amount it is withheld against, when the receipt is recorded, then it is refused by a named domain exception — never silently capped, and never posted as a negative or over-large line. (Mirrors `ReceiptCannotBeAllocated::exceedsAmountDue()`'s reasoning, `ReceiptService.php:156-162`.)
- **AC-WHT-1.4 (refusal — negative WHT; zero WHT is "no WHT").** A negative WHT amount is refused, mirroring `receipt_allocations_amount_positive_check`'s reasoning (ADR 0014 §A, "a zero line is noise; a negative one would silently un-pay"). Whether an explicit zero is accepted as "no WHT was withheld" or must be represented by omitting the field entirely is an Architect/schema decision (see OQ-1/OQ-7); either way the *effect* must be identical to AC-WHT-1.2.
- **AC-WHT-1.5 (refusal — sub-currency-precision).** Given a WHT amount finer than the company's `currency_precision` (e.g. `500.333` in a 2-decimal currency), when the receipt is recorded, then it is refused before anything posts — the exact discipline `ReceiptService::assertAtCurrencyPrecision()` already applies to the receipt's cash amount and to each allocation line (`ReceiptService.php:692-705`, called at `:99` and `:682`; ADR 0016's Gate-2 precision amendment). WHT is a money figure like any other on this receipt and inherits the same rule — this wave must not reopen or weaken it.
- **AC-WHT-1.6 (balances at currency_precision, not Money::SCALE).** The WHT line, like the Customer Advances remainder before it, is computed with exact `Money` arithmetic and rounded to the company's `currency_precision` before it is held and posted — inheriting, not reopening, the reconciliation ADR 0016's Gate-2 amendment already made between the ledger's `currency_precision` rounding (`JournalService::writeLines`) and the subledger's persisted figures.
- **AC-WHT-1.7 (per-allocation cap, if per-allocation).** If WHT is captured per allocation (OQ-3), each allocation's own WHT is validated against that allocation's own amount, not the receipt total, and the receipt-level WHT posted is Σ per-allocation WHT — summed the same stored-value way `Σ allocations` is summed today.

### User Story 2 — Per-receipt vs. per-allocation WHT

*As an accountant, when one receipt settles several invoices for one customer and the customer withholds tax against each invoice individually (as is typical — a customer's remittance advice usually references specific invoices), I want to record WHT at whatever level the customer's certificate is issued at, so my records reconcile against the paperwork the customer gives me.*

This is not decided here — see **OQ-3**. Both shapes are testable:

- **AC-WHT-2.1 (per-receipt).** One WHT amount is captured for the receipt as a whole; the accountant is responsible for its accuracy against however many invoices are being settled.
- **AC-WHT-2.2 (per-allocation).** One WHT amount is captured per allocation line, validated against that line's own amount (AC-WHT-1.7); the receipt-level figure posted is the sum.

Whichever is chosen must satisfy AC-WHT-1.1's balancing equation and AC-WHT-1.5/1.6's precision rules identically.

### User Story 3 — WHT certificate reference

*As an accountant, I want to record the WHT certificate/reference the customer gives me for the amount withheld, because the company needs that evidence to claim the WHT amount against its own income tax later, and needs to trace a receipt back to the paperwork behind it.*

- **AC-WHT-3.1.** Given a receipt with WHT withheld, the accountant may optionally supply a certificate reference (a reference string, and/or a certificate date) — captured for traceability only. This wave does **not** implement a certificate register, matching against IRD filings, or any reconciliation report; that is a later compliance-pack concern (ADR 0006 §A2's seam).
- **AC-WHT-3.2.** The certificate reference, once the receipt is posted, is immutable — consistent with every other column on a posted receipt (ADR 0014 §G's freeze-on-post trigger).
- **AC-WHT-3.3 (open question).** Whether a certificate reference without a WHT amount (or vice versa) should be refused as an inconsistent input, or simply permitted (a certificate may arrive before or after the amount is finalised in the system), is flagged as OQ-5 rather than decided.

### User Story 4 — Refusals are named, never silent

*As an accountant, if I enter WHT incorrectly I want a clear, specific error — never a raw database error, and never a silently adjusted figure.*

- **AC-WHT-4.1.** Every refusal in this wave (over-claim, negative, sub-precision, and any cross-checks the Architect designs) is a named domain exception with an actionable message, following the platform-wide discipline every prior receipt wave states explicitly (ADR 0014 §D: "never a raw `QueryException` or constraint name"; ADR 0016's own error-contract §13).
- **AC-WHT-4.2.** Nothing is written when a refusal occurs — the whole `record()` operation remains one atomic transaction (ADR 0014 §D step-ordering; ADR 0016 §J "a partial apply is impossible").

### User Story 5 — Cancellation reverses the WHT line too

*As an accountant who made a mistake on a WHT receipt, I want cancelling it to reverse the WHT posting along with everything else, so the WHT Receivable balance isn't left overstated after the receipt is undone.*

- **AC-WHT-5.1.** Given a posted receipt with a WHT line, when it is cancelled, then `PostingService::reverse()`'s existing whole-entry mirror (ADR 0015 §C: "mirrors every line of an entry with sides swapped and amounts copied," `PostingService.php:138-147`) reverses the WHT Receivable debit along with the bank, Trade Receivables, and Customer Advances lines — with **no bespoke WHT-specific reversal code**, provided WHT is modelled as an ordinary line within the receipt's single journal entry (this is exactly how ADR 0016's Customer Advances credit line is already reversed generically, ADR 0016 §G Case 1).
- **AC-WHT-5.2.** After cancellation, the WHT Receivable account's balance returns to what it was before the receipt — a delta-restore, never a snapshot, matching the discipline ADR 0015 §C already applies to Trade Receivables and ADR 0016 §G Case 1 applies to Customer Advances.
- **AC-WHT-5.3 (open question — is there an "applied" state for WHT?).** ADR 0016's held credit needed an `applied_amount` guard against cancellation (`heldCreditAlreadyApplied()`) *because* credit could later be consumed by a separate apply-credit event. A WHT receivable, unlike held credit, is not consumed by anything within this wave (there is no "apply WHT to X" operation proposed here) — so, on the working assumption that WHT is a plain ledger line and not a subledger balance with its own lifecycle, no analogous "already applied" cancellation guard should be needed. This is flagged for confirmation, not asserted as settled, because it depends directly on OQ-7 (whether WHT needs its own balance-tracking table at all).

### User Story 6 — Does apply-credit ever involve WHT?

*As an accountant, before I rely on apply-credit for a customer with WHT history, I want to know whether applying their held credit to a later invoice can itself trigger or require a WHT entry.*

- **AC-WHT-6.1 (working assumption — no).** Applying held credit (`ReceiptService::applyCredit()`, ADR 0016 §D: `Dr Customer Advances / Cr Trade Receivables`, no cash) involves no new cash arriving from the customer, and WHT is withheld by the customer at the moment they remit actual cash. The held credit being applied is itself the untaxed remainder of an earlier cash receipt (which may or may not have already carried its own WHT against the invoices *it* originally settled). On this reasoning, apply-credit needs **no WHT-related change** this wave.
- **AC-WHT-6.2 (confirm, don't assume).** The human should confirm there is no real-world Sri Lankan scenario in which a customer notifies the business of additional withholding *after* the cash already arrived and was applied as credit (e.g., a correction to an earlier remittance). If such a case exists, it is out of scope for this wave regardless and should be logged as a known limitation, following the precedent ADR 0016 §N set for "known limitations" it deliberately did not build.

## 4. Non-functional requirements

- **Immutability.** Once a receipt (and its WHT figure, and any certificate reference) posts, it must be frozen exactly like every other money/account/customer/number/ledger-link column the existing trigger already protects (`asids_customer_receipts_immutable()`, described in ADR 0014 §G). If WHT lands as new columns on `customer_receipts` (or `receipt_allocations`), those columns must be added to the frozen set — omitting them is a real risk this document flags in §6.
- **RLS.** If WHT needs a new table (only if OQ-7 concludes it does), it must be tenant-scoped with FORCED RLS and its own policy — RLS is not transitive (ADR 0014 §A, ADR 0016 §B, both established this for every child table added so far).
- **Money precision.** WHT amounts are held and posted at the company's `currency_precision`, never at the finer `Money::SCALE` — inheriting ADR 0016's Gate-2 amendment rather than reopening the question it already answered the hard way (mid-build discovery, `docs/adr/0016-...md` "Gate 2 amendment — APPROVED 2026-08-27").
- **Audit.** `CustomerReceipt` is already `Auditable`, with `auditOnly()` naming the money and status columns it tracks (`CustomerReceipt.php:172-187`). Any new WHT column on that model must be added to `auditOnly()`; if WHT instead lives on a new model, that model needs its own explicit `Auditable`/`auditOnly()`/`auditTags()` decision (the precedent: `CreditApplication` is `Auditable`, `ReceiptHeldCredit` deliberately is not, because its state is fully derivable from its already-audited parents — ADR 0016 §B "Models"). Which of these WHT resembles is an Architect judgement.
- **Permissions.** The working assumption is that recording WHT reuses the existing `sales.receipts.manage` capability (`PermissionCatalogue.php:275`) rather than needing a new one — WHT is an attribute of the same record-and-allocate action, not a distinct money-moving operation the way `apply-credit` was judged to be a distinct action warranting its own permission (ADR 0016 §F's reasoning). This is OQ-6, not settled.
- **Concurrency.** No new locking is anticipated: WHT is captured and posted synchronously inside the same `record()` transaction, touching no row that isn't already locked by the existing allocation flow — unless the Architect's rate-resolution choice (OQ-2) introduces something that itself needs a lock (e.g. a rate table entry). Flagged, not assumed away.
- **Refusal contract.** Named domain exceptions only, following the platform-wide discipline every ADR in this family states (AC-WHT-4.1).

## 5. Assumptions and open questions for the human (Gate 1)

Consolidated below verbatim for the Delivery Manager. Every item frames options; none is decided by this document.

1. **(a) The WHT GL account.** No such account exists today — the only system keys are `RETAINED_EARNINGS`, `OPENING_BALANCE_EQUITY`, `TRADE_RECEIVABLES`, `CUSTOMER_ADVANCES` (`Account.php:60-81`). Options: **(i)** a new system account (a new `Account` system key + a new leaf in `ChartTemplate`, e.g. under Current Assets alongside `1170 Input VAT Recoverable` — another "recoverable from the authority" asset, `ChartTemplate.php:88` — auto-provisioned for new companies and backfilled for existing ones the way `2180 Customer Advances` was, ADR 0016 §A); **(ii)** a caller-named existing asset account per receipt, with no system key, the way the bank/cash account works today (`1110`/`1120` ship with no `system_key`; "the caller names the account and the map validates it," ADR 0014 §C). Naming candidates if (i): "WHT Receivable" or "Advance Income Tax Recoverable."
2. **(b) Rate resolution.** Options: **(i)** reuse the `tax_codes`/`TaxType` infrastructure (ADR 0006) — add a WHT `TaxType` case or a parallel effective-dated rate, resolved the same GiST-exclusion, refuse-rather-than-guess way VAT rates resolve today (`TaxCodeService`/`TaxRateResolver`); **(ii)** the accountant supplies a raw WHT amount at receipt time with no rate table at all — whatever the customer's own remittance advice states; **(iii)** a new, WHT-specific effective-dated rate table, separate from `tax_codes`. Note: WHT in Sri Lanka is typically a statutory rate the **paying customer** applies, not a rate this company sets or negotiates — which may sit awkwardly with `tax_codes`' framing as "the rates a business charges" (ADR 0006 §A2). This is the highest-uncertainty fork in the whole wave.
3. **(c) Per-receipt vs. per-allocation WHT.** Does WHT attach to the receipt as a whole, or to each allocation line individually (a receipt settling several invoices may carry a different WHT certificate per invoice)? See User Story 2.
4. **(d) Does WHT ever touch the Customer Advances remainder?** Working assumption: no — WHT applies only to the allocated (invoiced) portion of a receipt, never to an unallocated overpayment that isn't yet earned income. Confirm, because it changes the balancing equation in AC-WHT-1.1 if wrong.
5. **(e) Certificate-reference capture.** Is a simple optional reference field (mirroring the existing `reference` column already on `customer_receipts` for cheque/bank-transaction id, ADR 0014 §A) sufficient for this wave, or does the business need a structured certificate register (number, issue date, customer TIN, etc.)? Working assumption: a simple field suffices; a register is a later compliance-pack concern.
6. **(f) Permission model.** Reuse `sales.receipts.manage` (working assumption — WHT is an attribute of the same record action) or add a new capability? The `manage`/`cancel`/`apply-credit` precedent splits permissions only when the *action* is distinct (`PermissionCatalogue.php:275-283`); recording WHT does not obviously meet that bar, but confirm.
7. **(g) Schema location (bonus, not in the original six but load-bearing).** Is WHT a column (or columns) on `customer_receipts` and/or `receipt_allocations`, or does it need its own small table? ADR 0016's held credit needed its own table for a specific database reason — the source-uniqueness index forces one non-reversing posting per source document, and apply-credit is a *second* posting against the same balance (ADR 0016 §B "Problem #1"). WHT, as scoped here, posts once, inside the receipt's own single journal entry — it is not obviously subject to that same forcing constraint, which argues for the simpler columns-on-existing-tables shape, but this is an Architect decision, not a schema this document invents.

## 6. Risks and dependencies

1. **Coherence with ADR 0016's variable-line posting.** A WHT debit line and ADR 0016's Customer Advances credit line must compose in the same entry without contradiction (up to four line combinations: with/without WHT × with/without remainder). The highest-risk failure mode is re-discovering, mid-build, the same `currency_precision` vs. `Money::SCALE` phantom-remainder bug ADR 0016 already found and fixed once (its Gate-2 amendment, 2026-08-27) — this document's AC-WHT-1.5/1.6 require inheriting that fix from day one rather than reopening it.
2. **Scope creep toward supplier-side WHT or IRD filing.** Once a WHT receivable exists, there is an obvious temptation to build a claim/offset workflow against `8100 Income Tax Expense` at year-end, or to start on the compliance-pack filing logic. Both are explicitly out of scope (§2) and should be raised as a Gate escalation if discovered mid-build, not quietly absorbed.
3. **Rate-resolution choice (OQ-2/b) is the single highest-uncertainty fork.** Reusing `tax_codes` conflates "this company's own tax codes" with "a rate its customer unilaterally applies," a conceptual mismatch ADR 0006 §A2 did not anticipate. Building the wrong data model here is expensive to unwind once receipts exist and cite it (the exact "difficult to reverse once invoices exist and cite the data" reasoning ADR 0006's own context section states about tax modelling generally).
4. **Immutability-set omission.** If WHT lands as new columns on `customer_receipts`, forgetting to extend the existing freeze trigger leaves a posted WHT figure editable after the fact — a correctness bug in the same family ADR 0016 §L reasoned about when it rejected "held credit as columns" (though WHT, unlike held credit, has no consumption lifecycle, so columns may be a safer fit here — the trigger extension still must not be forgotten).
5. **Stacking dependency.** This branch is stacked on `feature/phase4-credit-on-account`, which per the current git status is still mid-build (uncommitted Stage 3 files: `CreditApplicationPostingMap.php`, `ApplyCreditData.php`). Any change to `ReceiptPostingMap`'s posting contract before this wave's own Gate 2 would ripple into every assumption this document makes about "the current three-line shape." Coordinate with the Delivery Manager before Gate 2 architecture work begins.

---

**This document does not authorize any build.** Stage 2 (this document) is followed by Gate 1 (human approval of the open questions in §5), then Gate 2 (Architect design), then build.
