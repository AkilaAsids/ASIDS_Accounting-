# Phase 5 Wave 7 — Bills (Purchase Invoices) — Requirements

- **Status:** Draft — for Gate 1 (human) review
- **Author:** Business Analyst
- **Date:** 2026-09-01
- **Branch:** `feature/phase5-bills` (stacked on `feature/phase5-suppliers`, Wave 6 — shipped)
- **Depends on:** the supplier domain (`src/Core/Purchasing/Domain/Models/Supplier.php`,
  `src/Core/Purchasing/Application/Services/SupplierService.php`, `docs/adr/0018-purchasing-supplier-domain-foundation.md`)
  and the sales-invoice domain, which is this slice's blueprint (`src/Core/Sales/Domain/Models/SalesInvoice.php`,
  `SalesInvoiceService.php`, `InvoicePostingMap.php`, ADR 0007, ADR 0009).

## 1. Objective and context

A bill is the purchase-side mirror of a sales invoice: a supplier bills the company for goods or
services, the company records it, and posting it recognises the expense and the recoverable input tax,
and records what is now owed to the supplier. Where a sales invoice posts **Dr Accounts Receivable = Cr
Revenue + Cr Output VAT**, a bill posts the mirror image: **Dr Expense (per line) + Dr Input VAT = Cr
Accounts Payable**.

Two things make this wave structurally significant, not just another CRUD slice:

1. **It is the first purchasing document that posts to the ledger.** Wave 6 built the supplier
   master-data domain and deliberately stopped short of any ledger behaviour — no bills table, a dormant
   `PayableBalanceProbe` bound to `NoPayables` (`src/Core/Purchasing/Infrastructure/NoPayables.php`),
   and the archive/delete/code-lock rules in `SupplierService` shipped **inert**, waiting for this wave
   (`docs/adr/0018-purchasing-supplier-domain-foundation.md` §E, §H item 4). This wave is where those
   rules start to bite.
2. **It is the first real use of `tax_codes.input_account_id`.** The column has existed since the tax
   domain was built but is documented as "unused by sales and populated only by a company that has
   configured it ahead of the purchasing phase" (`src/Core/Sales/Domain/Models/TaxCode.php:100-111`).
   This wave is that phase.

The blueprint for the whole slice is the sales-invoice domain: `SalesInvoice`/`SalesInvoiceLine`
(`src/Core/Sales/Domain/Models/`), `SalesInvoiceService::createDraft/updateDraft/issue/cancel`
(`src/Core/Sales/Application/Services/SalesInvoiceService.php`), and `InvoicePostingMap`
(`src/Core/Sales/Application/Services/InvoicePostingMap.php`), with the draft-vs-issue split and its
reasoning recorded in ADR 0007 and ADR 0009. Every requirement below is written as "mirror X, unless
flagged otherwise" and cites the line it mirrors.

## 2. In / out of scope

### In scope
- A `Bill` model and `BillLine` model, mirroring `SalesInvoice`/`SalesInvoiceLine` structurally
  (header + lines, draft status, computed totals, no product catalogue — free-text lines with a
  required account).
- Draft creation, editing and hard-deletion of drafts (mirrors `createDraft`/`updateDraft`/`deleteDraft`).
- Posting ("issuing") a draft bill to the ledger: gapless internal numbering, the
  Dr Expense + Dr Input VAT = Cr Accounts Payable posting, re-validation at post time (mirrors
  `issue()` and `InvoicePostingMap`).
- A new `DocumentType` case for bills, and (subject to Gate 1, see §5a) its own gapless number series,
  kept separate from whatever journal-voucher series posts its ledger entry — mirroring the two-series
  design in `docs/adr/0009-sales-invoice-issuing-cancellation-and-numbering.md` §B.
- A duplicate supplier-invoice-number guard — a real accounts-payable control that has no analogue on
  the sales side (a customer does not assign your invoice a number; a supplier does, and recording the
  same supplier bill twice is the classic AP double-payment risk).
- Binding the real `EloquentPayableBalanceProbe` over `PayableBalanceProbe`, activating the three
  dormant `SupplierService` rules from Wave 6 (archive-with-balance, delete-with-bill, code-lock-with-bill)
  — this is named explicitly as this wave's job in `docs/adr/0018-purchasing-supplier-domain-foundation.md`
  §A4 ("Wave 7 flips this one line and `SupplierService` does not change") and §H item 4.
- Whatever `Account` system-key and chart-template work the Accounts Payable side of the posting needs
  (an Architect decision, not decided here — see §5c and §6).

### Out of scope — flagged for Gate 1, not decided here
- **Cancellation / reversal of a posted bill** — may be this slice or its own wave. See §5b.
- **Supplier payments and withholding tax (WHT) on payment** — explicitly Wave 8
  (`docs/adr/0018-purchasing-supplier-domain-foundation.md` front matter: "Wave 8 (supplier payments /
  WHT-on-payment) ... named for sequencing only and out of scope"). A bill's `amount_paid`/`amount_due`
  columns, if added, should be held at zero by a phase-scoped CHECK this wave, mirroring
  `sales_invoices.amount_paid`/`amount_due` (`SalesInvoiceService.php:560-563`), so Wave 8 has a
  ready-made target and no schema change of its own.
- **A debit note / supplier credit note document.** A negative bill is refused, mirroring
  `invoice-total-negative` (`SalesInvoiceService.php:545-553`) — a correction to an already-posted bill
  is its own document family, not a bill with a minus sign.
- **HTTP/API surface.** No routes, no controllers. Sales invoices themselves have none yet
  (`docs/adr/0009-sales-invoice-issuing-cancellation-and-numbering.md` §J: "no HTTP or API surface
  existed for invoices, and none exists today"), so bills follow the same precedent.
- **Purchase orders, goods-received notes, or any pre-bill document.** Nothing upstream of the bill
  exists in this system yet; a bill is recorded directly against a supplier.
- **A product/expense catalogue.** Lines are free text with a required account, exactly as sales
  invoice lines are (ADR 0007 decision A1, restated at `SalesInvoiceLine.php:22`).

## 3. Functional requirements

Numbered `AC-n.n`. Every AC cites what it mirrors or where it departs.

### Epic 1 — Draft bill creation and editing

**US-1.** As an accounts-payable clerk, I want to record a draft bill against a supplier, with lines
coded to expense accounts and tax codes, so a purchase is captured before anything is posted.

- **AC-1.1** Given an active supplier (`Supplier::acceptsNewBills()`, `Supplier.php:125-128`) and at
  least one line naming an expense account, an amount and optionally a tax code, when I create a draft
  bill, then it is created in `Draft` status with a computed subtotal, input-VAT total and total, and
  no journal entry — mirrors `createDraft()` (`SalesInvoiceService.php:66-103`) and the CHECK-based
  structural boundary of ADR 0007 §B3 (`number`/`issued_at`/`journal_entry_id` all null while draft).
- **AC-1.2** Given a supplier that is dormant/inactive or archived, a new draft bill against it is
  refused — mirrors `resolveCustomer(forNewInvoice: true)`'s `customer-not-invoiceable` refusal
  (`SalesInvoiceService.php:641-669`), using `Supplier::acceptsNewBills()` as the payable-side
  equivalent. Existing bills are unaffected by a supplier's later dormancy/archival, exactly as existing
  invoices are unaffected by a customer's (`SupplierStatus::acceptsNewBills()` docblock,
  `SupplierStatus.php:32-41`).
- **AC-1.3** Given a line naming an account that is not of type `Expense`, the draft is refused (or, at
  minimum, posting is refused — see AC-3.6) — mirrors `resolveRevenueAccount()`'s
  `AccountType::Income` check (`SalesInvoiceService.php:678-711`), applied to `AccountType::Expense`
  instead.
- **AC-1.4** Given a line names a tax code, it is resolved **by code and by the bill's date**, never by
  id, via the same mechanism sales uses (`TaxRateResolver`, mirrors `resolveTax()`,
  `SalesInvoiceService.php:619-636`), and the resolved rate is snapshotted onto the line and never
  re-resolved later — mirrors ADR 0009 §B5 ("money is never recomputed").
- **AC-1.5** Given a line with a zero quantity/amount, the draft is refused — mirrors
  `invoice-line-zero-quantity` (`SalesInvoiceService.php:470-475`).
- **AC-1.6** Given a bill whose total would be negative, it is refused at every stage (draft included)
  — mirrors `invoice-total-negative` (`SalesInvoiceService.php:545-553`); a negative bill is a
  correction document, out of scope per §2.
- **AC-1.7** Given a draft bill, it may record the supplier's own invoice number/reference as well as
  (once posted) an internal gapless number — see the open question at §5a for which is mandatory and
  when.

**US-2.** As an AP clerk, I want to edit or delete a draft bill, so a mistake made before posting costs
nothing.

- **AC-2.1** Given a draft bill, its header fields and its lines may be changed freely, with
  `array_key_exists()` clear-vs-omit semantics for nullable fields (branch, discount, etc.) — mirrors
  `updateDraft()` (`SalesInvoiceService.php:105-174`, ADR 0008's ADR-cited pattern).
- **AC-2.2** Given a draft bill, it may be hard-deleted; anything else (once posted) is refused —
  mirrors `deleteDraft()`'s draft-only hard deletion (`SalesInvoiceService.php:420-437`) and ADR 0007
  §B2's reasoning ("a never-issued draft is not an accounting document").
- **AC-2.3** Given a change to the bill date, the tax resolution is redone even if no line changed —
  mirrors the "a changed `invoice_date` can change which tax rate applies even when no line moved"
  reasoning (`SalesInvoiceService.php:116-118`).

### Epic 2 — Posting a bill

**US-3.** As an accountant, I want to post a draft bill to the ledger, so the expense and the
recoverable input VAT are recognised and the amount now owed to the supplier is recorded.

- **AC-3.1** Given a draft bill with at least one line and a positive total, when it is posted, then
  the system reserves a gapless internal number from the bill's own document series, posts one journal
  entry **Dr Expense (per line, grouped by account) + Dr Input VAT (grouped by
  `tax_codes.input_account_id`) = Cr Accounts Payable**, dated the bill date, sourced to the bill so a
  second posting is impossible — mirrors `issue()`'s numbering-then-posting sequence
  (`SalesInvoiceService.php:214-308`) and `InvoicePostingMap`'s grouped, additive construction
  (`InvoicePostingMap.php:16-55`), with debits and credits swapped for the payable side.
- **AC-3.2** The posted entry balances **by construction**, because the posting map sums the lines'
  already-rounded stored figures rather than recomputing — mirrors `InvoicePostingMap`'s documented
  reasoning that "the debit equals the sum of the credits by construction... because no rounding
  happens here" (`InvoicePostingMap.php:38-47`).
- **AC-3.3** Given the bill's fiscal period is closed, posting is refused before any number is
  reserved — mirrors `issue()` steps 5–6 (`SalesInvoiceService.php:238-244`).
- **AC-3.4** Given the supplier, an expense account, or a tax code on any line belongs to a different
  company in the same workspace, posting is refused — mirrors `assertIssuable()`'s tax-code
  company-ownership check (`SalesInvoiceService.php:746-781`) and `InvoicePostingMap`'s
  `accountWithinCompany`/`orderedByCode` checks (`InvoicePostingMap.php:274-314`). Row-level security
  is satisfied by either company sharing a tenant, so this explicit check is the only thing that stops
  a bill citing a sibling company's ledger.
- **AC-3.5** Given the supplier has become dormant/archived since the draft was written, posting
  re-validates it and refuses if it no longer accepts new bills — mirrors ADR 0009 §B5, "everything is
  re-validated at issue," applied via `Supplier::acceptsNewBills()`.
- **AC-3.6** Given a line's account does not accept postings, or is not of type `Expense`, posting is
  refused — mirrors `resolveRevenueAccount`/`assertPostable` (`SalesInvoiceService.php:678-711`,
  `InvoicePostingMap.php:316-323`).
- **AC-3.7** Given a line names a tax code with no `input_account_id` configured, posting is refused
  with a message naming the tax code — mirrors `taxCodeHasNoOutputAccount`
  (`InvoicePostingMap.php:219-233`), applied to the input side. This is the first production code path
  that can actually fail this way, since no bill has ever existed to exercise it.
- **AC-3.8** Given the Accounts Payable account used by the posting does not accept postings, is not of
  type `Liability`, or does not exist in this company, posting is refused — mirrors
  `receivableAccountFor()`'s type/postability checks (`InvoicePostingMap.php:97-121`), mirrored for the
  payable side. *(Which account this is — a system key vs. a per-supplier override — is an open
  question; see §5c.)*
- **AC-3.9** Given the bill posts successfully, the supplier's outstanding payable balance (as reported
  by the now-real `EloquentPayableBalanceProbe`) increases by the bill total, so a later attempt to
  archive that supplier while a balance remains is refused — mirrors
  `SupplierService::archive()`'s dormant balance check, now live (`docs/adr/0018...md` §C4, §E).
- **AC-3.10** Given two draft bills for the same supplier carry the same supplier invoice number, the
  configured duplicate policy applies (refuse / warn / allow — see §5e) at create or post time.
- **AC-3.11** Given an empty draft or one totalling zero, posting is refused as a named domain error
  rather than a raw constraint failure — mirrors `InvoiceCannotBeIssued::withoutLines()` /
  `withZeroTotal()` (`SalesInvoiceService.php:227-233`).

### Epic 3 — Cancellation (conditionally in scope — see §5b)

**US-4.** As an accountant, I want to cancel a posted bill, so an error already in the ledger can be
corrected without deleting a statutory record.

*If this wave includes cancellation*, it mirrors `cancel()` (`SalesInvoiceService.php:340-418`) and
ADR 0009 §B7:
- **AC-4.1** Only a posted bill may be cancelled; a draft is deleted instead (AC-2.2).
- **AC-4.2** A cancellation requires a non-blank reason.
- **AC-4.3** Cancelling posts a mirror (reversing) entry dated today, not backdated to the bill's date;
  what must be open is *today's* period, not the bill's — mirrors ADR 0009 §B7.
- **AC-4.4** Cancelling retains the bill's number and consumes none from the series — mirrors ADR 0009
  §B, the load-bearing two-series decision.
- **AC-4.5** A bill with any amount already paid against it cannot be cancelled — mirrors the
  `partiallyPaid` guard (`SalesInvoiceService.php:370-372`), stated now (inert until Wave 8 payments
  exist) for the same reason ADR 0009 §B7 states it now for sales.

*If cancellation is deferred*, this wave still needs the structural preparation ADR 0007 §B3 describes
for sales — the CHECK constraints that make an inconsistent state unrepresentable — even though the
transition itself lands later.

### Epic 4 — Authorization

**US-5.** As a system administrator, I want bill capabilities to be permissioned distinctly from
supplier master-data capabilities, so raising a bill, posting it, and (if in scope) cancelling it can
be granted independently.

- **AC-5.1** New permissions under a `purchasing` group: `purchasing.bills.view`,
  `purchasing.bills.draft`, `purchasing.bills.post` (or `.issue`, naming TBD by the Architect), and, if
  in scope, `purchasing.bills.cancel` — mirroring `sales.invoices.{view,draft,issue,cancel}`
  (`src/Core/Authorization/Domain/Catalogue/PermissionCatalogue.php:249-263`) both in shape and in which
  actions are marked `sensitive`. Posting/issuing and cancelling are sensitive (they commit to the
  ledger / reverse a ledger posting); viewing and drafting are not.
- **AC-5.2** Role grants mirror whichever roles hold `sales.invoices.*` today, exactly as Wave 6 mirrored
  `sales.customers.*` for supplier permissions (`docs/adr/0018...md` §D2).

## 4. Non-functional requirements

- **Immutability once posted.** A posted bill's accounting-impacting fields must not change — mirrors
  the issued-invoice immutability trigger and the CHECKs from ADR 0007 §B3 (`number`,
  `issued_at`/equivalent, `journal_entry_id` all tied to status).
- **FORCED row-level security** on the new `bills`/`bill_lines` tables, reusing the platform's tenant
  context functions — mirrors the suppliers RLS migration (`docs/adr/0018...md` §B5) which itself
  mirrors Sales' (`src/Core/Sales/Database/Migrations/2026_03_02_000002_...`).
- **Exact arithmetic.** Every monetary and quantity column is a decimal string at the ledger's scale,
  never a float — mirrors `SalesInvoiceLine`'s casts (`SalesInvoiceLine.php:177-193`) and `Money`'s
  scaled-integer design throughout the invoice service.
- **Audit.** The bill header is audited (money, dates, supplier, status, supplier invoice number);
  lines are not, following the reasoning that a line has no life of its own — mirrors
  `SalesInvoice::auditOnly()` (`SalesInvoice.php:253-267`) and the explicit non-auditing of
  `SalesInvoiceLine` (`SalesInvoiceLine.php:25-28`).
- **Gapless numbering cost.** `DocumentType::requiresGaplessNumbering()` costs a row lock per document,
  serialising posting within a company (`DocumentType.php:47-59`) — accepted for bills for the same
  reason it is accepted for sales invoices: this is a document an authority may audit for
  completeness.
- **Permissions** — see Epic 4 above; `purchasing.bills.*` as its own permission set, not folded into
  `purchasing.suppliers.*`.

## 5. Assumptions and open questions for the human — Gate 1 (consolidated)

1. **Numbering.** Should a bill carry (a) only an internal gapless number (`BILL-…`, mirroring
   `INV-…`), (b) only the supplier's own invoice number/reference, or (c) both — an internal gapless
   number assigned at posting **and** the supplier's invoice number captured at draft time as a
   distinct field? If (c), is the supplier's invoice number mandatory at draft creation (needed for the
   duplicate check, AC-3.10) or only required by the time the bill is posted?
2. **Cancellation scope.** Is cancellation/reversal of a posted bill part of this wave, or its own
   later wave — mirroring how sales split Milestone 4 (draft only) from Milestone 5 (issue + cancel,
   ADR 0007 / ADR 0009)?
3. **The Accounts Payable account.** Does every bill post to one system-level Accounts Payable account
   (a new `Account::TRADE_PAYABLES` system key, mirroring `Account::TRADE_RECEIVABLES`, which itself
   would need registering in the chart template and backfilling for existing companies — an Architect
   decision, not decided here), or can an individual supplier override it with its own payable account
   — the field Wave 6 explicitly dropped from `suppliers` and named as "deferred to Wave 7"
   (`docs/adr/0018-purchasing-supplier-domain-foundation.md` §B2)?
4. **The expense account.** Is the expense account chosen at the line level on every bill (mirroring
   `revenue_account_id` on a sales invoice line — free text, no catalogue), or can a supplier carry a
   default expense account that pre-fills (but does not force) the line?
5. **Duplicate supplier-invoice-number policy.** When two bills for the same supplier carry the same
   supplier invoice number, should the system refuse outright, warn but allow, or allow silently? And
   at what scope — per supplier, or company-wide?
6. **Binding the real probe.** Should this wave flip `PurchasingServiceProvider`'s
   `PayableBalanceProbe` binding from `NoPayables` to a real `EloquentPayableBalanceProbe`
   (`docs/adr/0018...md` §A4, §E, §H item 4), activating the archive-with-balance, delete-with-bill and
   code-lock-with-bill rules in `SupplierService` for every existing supplier at once? (The brief
   states this is expected; confirming it here because it is a behaviour change with no code change in
   `SupplierService` — worth a deliberate yes.)

## 6. Risks and dependencies

- **Input-VAT readiness.** `tax_codes.input_account_id` is documented as populated only by companies
  that configured it "ahead of the purchasing phase" (`TaxCode.php:100-111`). If most existing tenants'
  tax codes have it unset, AC-3.7 means posting is refused broadly on day one until an administrator
  configures it — a data-readiness risk, not a code defect, but worth flagging before build.
- **Numbering-counter design.** The two-series separation that ADR 0009 §B established for sales
  invoices (the document's own series, kept apart from whatever series posts its ledger entry) must be
  preserved for bills or bill numbers will show gaps on every posting and every cancellation — the
  exact defect ADR 0009 was written to prevent, and one that "every single-invoice test passes either
  way" (ADR 0009 §B), so it needs a multi-bill test the way `IssueInvoiceTest`/`CancelInvoiceTest` have.
- **Probe-activation blast radius.** Flipping `NoPayables` to a real probe activates three dormant
  `SupplierService` rules simultaneously, for every supplier created since Wave 6 — this needs its own
  acceptance check that the binding actually moved, mirroring the one assertion
  `docs/adr/0018...md`'s Risks section says "would have caught Sales closing a milestone with the seam
  still unbound" (`ReceivableBalanceProbeTest.php:89-94`).
- **Sequencing dependency.** Decisions 3 and 4 above (the AP account, the expense account) are
  prerequisites for designing the posting map; deciding them late forces rework of the equivalent of
  `InvoicePostingMap`.
- **Scope discipline.** Supplier payments and WHT-on-payment (Wave 8) and any HTTP surface must stay
  out — the temptation to reach for them while building the posting path mirrors the "Wave-7 boundary
  creep" risk Wave 6's ADR already named for itself (`docs/adr/0018...md` Risks section).

## Report to the Delivery Manager

**(a) Doc path:** `/Users/akilasiriwardhana/Documents/Claude/ASIDS Accounting/docs/PHASE-5-BILLS-REQUIREMENTS.md`

**(b) Scope summary:**
- Draft bill creation/edit/delete against a supplier, lines with expense account + tax code + amount,
  mirroring `SalesInvoiceService::createDraft/updateDraft/deleteDraft`.
- Posting ("issuing") a bill to the ledger: Dr Expense (per line) + Dr Input VAT (via
  `tax_codes.input_account_id`) = Cr Accounts Payable, mirroring `InvoicePostingMap` with debits/credits
  swapped — the first purchasing document to touch the ledger and the first real use of input VAT.
- Gapless internal bill numbering via a new `DocumentType` case, with the two-series design from
  ADR 0009 §B preserved so posting and any future cancellation never create numbering gaps.
- A duplicate supplier-invoice-number guard — a genuine AP control with no sales-side analogue.
- Binding the real `EloquentPayableBalanceProbe`, activating Wave 6's dormant archive/delete/code-lock
  rules on suppliers.
- Cancellation is flagged, not committed to, as in/out of scope for this wave (Gate 1 decision).
- `purchasing.bills.*` permissions mirroring `sales.invoices.*`'s shape and sensitivity.
- Out of scope: supplier payments, WHT-on-payment (both Wave 8), any HTTP/API surface, purchase orders,
  and any debit-note/credit-note document.

**(c) Consolidated open questions for Gate 1 (verbatim from §5):**
1. Numbering — internal gapless `BILL-…` vs. capturing the supplier's own invoice number/reference vs.
   both; and if both, whether the supplier's invoice number is mandatory at draft time.
2. Whether cancellation/reversal of a posted bill is in this slice or a later one.
3. The Accounts Payable account — a system `Account::TRADE_PAYABLES` key vs. a per-supplier override
   (the field Wave 6 deferred).
4. The expense account — line-level (like the invoice's revenue account) vs. a supplier default.
5. Duplicate supplier-invoice-number policy — refuse / warn / allow, and at what scope.
6. Whether this wave binds the real `EloquentPayableBalanceProbe`, activating Wave 6's dormant
   supplier rules.

**(d) Risks:** input-VAT data readiness on existing tax codes; the numbering-counter two-series design
must be preserved or bill numbers will gap; probe-activation blast radius across every existing
supplier needs its own acceptance test; decisions 3–4 above gate the posting-map design and should be
settled before Stage 3 (Architecture) begins; scope discipline against Wave 8 (payments/WHT) and any
HTTP surface.

No commits made. No architecture or stack decisions made — every account-resolution and schema
question above is framed as options for the Architect and the human, not decided here.
