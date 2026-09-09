# Phase 5 Wave 8b — Withholding Tax on Supplier Payments — Requirements

- **Status:** Gate 1 — APPROVED 2026-09-09
- **Author:** Delivery Manager (acting BA)
- **Date:** 2026-09-09
- **Branch:** `feature/phase5-supplier-payments` (Wave 8a — supplier payments — delivered, PR #9) → a new
  branch `feature/phase5-wht-on-payment` stacked on it.
- **Depends on:** the supplier-payment domain delivered in Wave 8a
  (`docs/adr/0020-supplier-payments-and-allocation.md`, `PaymentService::record()`, `PaymentPostingMap`,
  `SupplierPayment`/`PaymentAllocation`) and the supplier domain (`Supplier.php`, ADR 0018).

## 0. What already exists on this branch — read before deciding

WHT is **greenfield on this line**, exactly as payments were. Verified by direct search:

- **No WHT Payable account, and no WHT system key.** The chart (`ChartTemplate.php`) has `2140 Output VAT
  Payable` and the payroll-deduction leaves `2150 PAYE/APIT`, `2160 EPF`, `2170 ETF` — but no WHT Payable.
  `Account.php` declares only `RETAINED_EARNINGS`, `OPENING_BALANCE_EQUITY`, `TRADE_RECEIVABLES`,
  `TRADE_PAYABLES` (`Account.php:60-80`). A `WHT Payable` liability leaf, its system key, a backfill
  migration for existing companies, and a `ChartTemplate::VERSION` bump (`2026.09-lk-sme-3` → `-4`) are the
  **three-part cost** ADR 0020 explicitly deferred to this slice (ADR 0020 §A0, dec. 6).
- **No withholding concept in the tax model.** `TaxType` has `vat`, `svat`, `exempt`, `zero_rated` only
  (`TaxType.php:28-31`); `tax_codes` carry an output/input VAT account, not a WHT rate.
- **The supplier carries a TIN but no WHT rate.** `suppliers.tax_identification_number` was retained at
  Wave 6 "pre-provisioning Wave 8 supplier-WHT/compliance" (`create_suppliers_table.php:56-57`), but there
  is no `wht_rate` / `wht_applicable` field.
- **The Phase-4 receivable-side WHT (WHT-on-receipt) is NOT on this branch** (ADRs 0013–0017 live on
  unmerged `feature/phase4-*` branches; the sequence here runs 0012 → 0018). So there is no established WHT
  posting pattern to mirror — every choice below is first-of-its-kind on this line, grounded in the VAT
  precedent (`BillPostingMap`'s input-VAT handling, `TaxCode`) and the Wave 8a payment pattern.

## 1. Objective and context

Sri Lankan WHT on supplier payments: when the company pays certain suppliers, it **withholds** a percentage
of the payment and remits it to the Inland Revenue Department on the supplier's behalf, paying the supplier
only the net. The withheld amount is a liability the company owes the authority until remitted.

Wave 8a posts a payment as two lines: `Dr Trade Payables (total) = Cr Bank/Cash (total)`. Wave 8b adds a
**third line** so a payment that withholds becomes:

```
Dr Trade Payables        1,000.00     (the bill obligation settled in full)
    Cr Bank / Cash                 950.00   (net cash actually paid to the supplier)
    Cr WHT Payable                  50.00   (withheld, owed to the authority)
```

The bill is **settled in full** (the supplier's claim on us is discharged by 1,000 — they receive 950 in
cash plus a 50 WHT credit they reclaim from the authority). This is the crux decision in §3.

This completes the tax side of purchase-to-pay. It does **not** add WHT certificates, a WHT return, or the
remittance-to-authority payment — those are named later slices (§5).

## 2. Functional requirements

- **FR-1 — A payment may withhold tax.** `PaymentService::record()` accepts an optional WHT instruction and,
  when present, computes the withheld amount, reduces the cash paid by it, and posts the third line.
- **FR-2 — WHT Payable account.** A new liability leaf resolved by a system key (never by code), provisioned
  into every company's chart (new companies via the template; existing companies via a backfill migration
  under `RowLevelSecurity::bypass()` with the effectiveness assertion the trade-receivables backfill uses).
- **FR-3 — The posting balances by construction.** `Dr Trade Payables = Cr Bank + Cr WHT Payable`, all at
  `Money::SCALE`, no float. A zero-WHT payment posts exactly the Wave 8a two-line entry (no empty WHT line).
- **FR-4 — The withheld amount is capped and validated** (non-negative, not greater than the payment amount;
  the net cash must stay ≥ 0).
- **FR-5 — Bill settlement reflects the full obligation** (see the §3 decision): a fully-withheld-and-paid
  bill flips to `Paid`, and the payable balance drops by the gross settled, not the net cash.
- **FR-6 — WHT Payable is a live, reportable liability** that accumulates until a remittance slice clears it.
- **FR-7 — Immutability/lifecycle unchanged.** Still record-and-post in one step, born posted, immutable; the
  WHT figures are frozen with the rest of the payment header.
- **FR-8 — Authorization unchanged in principle:** recording a WHT payment is still the sensitive
  money-moving action (see §3 decision 6 on whether it reuses `purchasing.payments.post`).

## 3. Gate-1 decisions needed (the human decides these; my recommendation follows each)

1. **WHT rate source.** Where does the rate come from?
   - **(A) Per-supplier rate + flag** — add `wht_applicable` + `wht_rate` to `suppliers`; a withholding
     supplier's payments withhold at that rate unless overridden. Simplest; leans on the retained TIN.
   - **(B) WHT tax codes** — add `TaxType::Wht` + WHT codes (rate + WHT Payable account), selected per
     payment (or per bill). Most flexible, mirrors VAT modelling, but heaviest (new code table surface).
   - **(C) Per-payment caller-supplied WHT amount/rate** — the thinnest; the caller computes and the service
     validates. No new master data.
   - *Recommendation:* **(A)** for 8b — a per-supplier rate with an optional per-payment override covers the
     SME case, keeps the slice small, and uses the field Wave 6 already reserved space for. (B) can come
     later if per-service-type rates are needed.
2. **WHT base.** The withheld amount is `rate ×` **what**? The bill's taxable subtotal (excl. VAT), the bill
   total (incl. VAT), or the payment/allocation amount?
   - *Recommendation:* the **allocated amount per bill, on the pre-VAT subtotal share** — SL WHT is on the
     service value, not the VAT. (Confirm; if simplicity is preferred, base it on the allocated gross.)
3. **Bill settlement semantics (the crux).** When 950 cash + 50 WHT settles a 1,000 bill, does the bill's
   `amount_paid` increase by **1,000** (gross — bill is `Paid`) or by **950** (net — bill stays
   `PartiallyPaid` with 50 due)?
   - *Recommendation:* **gross (1,000, `Paid`).** The supplier's obligation is discharged in full; the 50 is
     reclassified from Trade Payables to WHT Payable, not left owing to the supplier. Net-only would leave a
     phantom 50 payable to a supplier who has been fully settled.
4. **WHT Payable account identity.** Code/name/key — recommend `2180 WHT Payable`, `Account::WHT_PAYABLE`
   (`'wht_payable'`), a `Liability` leaf under `2100`, plus the backfill + VERSION bump.
5. **Rounding.** WHT rounded to `Money::SCALE` (4dp) then to currency precision, consistent with VAT — the
   net cash is `payment − Σ WHT` so the entry always balances. Confirm currency-precision rounding of WHT.
6. **Permissions/lifecycle.** Reuse `purchasing.payments.post` (a WHT payment is still recording-and-posting
   a payment), or add a distinct capability? *Recommendation:* **reuse `payments.post`** — same money-moving
   action, no new capability. Record-and-post lifecycle unchanged.
7. **Multiple WHT rates in one payment.** Allow different rates per allocated bill, or one rate per payment?
   *Recommendation:* **one rate per payment for 8b** (per-bill/per-line rates deferred), matching decision 1(A).

## 4. Acceptance criteria (draft — firm up after Gate 1 decisions)

- **AC-1.1** A payment with WHT posts three balanced lines: `Dr Trade Payables (gross) = Cr Bank (net) +
  Cr WHT Payable (withheld)`.
- **AC-1.2** A zero-WHT (or non-withholding-supplier) payment posts exactly the Wave 8a two-line entry.
- **AC-1.3** WHT Payable resolves by system key; a payment refuses to post if the account is missing,
  non-postable, or not a Liability (mirror the Trade Payables checks).
- **AC-1.4** Withheld amount is validated: ≥ 0, ≤ payment amount, net cash ≥ 0; violations refused readably.
- **AC-1.5** The settled bill(s) move by the **gross** allocated (per decision 3) — a fully settled bill →
  `Paid`; the payable balance drops by gross.
- **AC-1.6** WHT Payable accumulates across payments and is a live liability on the trial balance.
- **AC-2.1** Backfill provisions `WHT Payable` into every existing company exactly once (idempotent), under
  bypassed RLS with the effectiveness assertion; new companies get it from the template; VERSION bumped.
- **AC-2.2** A posted WHT payment is immutable (WHT figures frozen with the header); source_id uniqueness
  still blocks a double post.
- **AC-2.3** Authorization: only a holder of `purchasing.payments.post` (accountant/owner/admin) can record a
  WHT payment.

## 5. Scope boundaries — explicitly deferred

- **WHT certificates** issued to suppliers (a document/report) — later slice.
- **The WHT return and the remittance payment** to the authority (clearing WHT Payable) — later slice.
- **Per-service-type WHT codes / multiple rates per payment** — deferred if decision 1 is (A)/decision 7 is
  one-rate.
- **WHT on payments not tied to a bill** (e.g. direct expense payments) — out of scope; 8b withholds on
  bill-settling payments only.
- **VAT/WHT interaction edge cases** (SVAT, reverse-charge) — out of scope.

## 6. Risks

- **Greenfield WHT, no receipt precedent on this line** — every choice is first-of-its-kind; grounded in the
  VAT and Wave 8a payment patterns.
- **Bill-settlement semantics (decision 3) is load-bearing** — the wrong choice leaves phantom payables or
  misstates what the company owes the authority. Needs explicit human sign-off.
- **A backfill on a FORCED-RLS table silently touches zero rows** without the bypass + assertion (the
  recurring trap; see the trade-receivables backfill precedent).
- **The Phase 4 (receivable-side) WHT-on-receipt will need to reconcile** with these choices at a future
  merge — a later task, not an 8b blocker.

---

*Prepared for Gate 1. No architecture and no code — the ADR (Gate 2) follows only after the human approves
these decisions. Per the per-wave gate policy: Gate 1 → STOP → Architecture/ADR → Gate 2 STOP → build.*

## Gate 1 decisions — APPROVED 2026-09-09

The human approved the requirements and resolved all seven decisions:

1. **WHT rate source = per-supplier rate + flag** (decision 1A). Add `wht_applicable` + `wht_rate` to
   `suppliers`; a withholding supplier's payments withhold at that rate (optional per-payment override).
   WHT tax codes (1B) deferred.
2. **WHT base = the allocated amount per bill on the pre-VAT subtotal share** (decision 2) — SL WHT is on the
   service value, not the VAT.
3. **Bill settlement = GROSS** (decision 3, the crux): 950 cash + 50 WHT settling a 1,000 bill moves
   `amount_paid` by the full **1,000**; the bill flips to `Paid`; the payable balance drops by gross. The 50
   is reclassified Trade Payables → WHT Payable, not left owing to the supplier.
4. **WHT Payable = `2180 WHT Payable`**, `Account::WHT_PAYABLE` (`'wht_payable'`), Liability leaf under
   `2100`; provisioned via the template (new companies) + a backfill migration (existing companies, under
   bypassed RLS with the effectiveness assertion); `ChartTemplate::VERSION` bumped `…-3` → `…-4`.
5. **Rounding** consistent with VAT (WHT at `Money::SCALE` then currency precision; net cash = payment − WHT
   so the entry balances).
6. **Permissions = reuse `purchasing.payments.post`** — no new capability; record-and-post lifecycle,
   immutability, and born-posted freeze all unchanged.
7. **One WHT rate per payment** for 8b; per-bill / per-service-type rates deferred.

Deferred scope confirmed: no WHT certificates, no WHT return, no remittance-to-authority payment, no WHT on
non-bill payments, no SVAT/reverse-charge edge cases this slice.

Next: Solution Architect writes the ADR (0021) realising these decisions, then **STOP for Gate 2**.
