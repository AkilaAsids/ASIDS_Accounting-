# Project status — Phase 5 Wave 8b: WHT on supplier payments (Minions Team 8)

**Last updated:** 2026-09-09 · **Branch:** `feature/phase5-wht-on-payment` · **Base:** `feature/phase5-supplier-payments` (Wave 8a, PR #9)

## Where we are
Wave 8b. **Withholding tax on supplier payments** — a withholding supplier's payment posts a third line:
`Dr Trade Payables (gross) = Cr Bank (net) + Cr WHT Payable (withheld)`. Bill settlement is GROSS (the
supplier's obligation is discharged in full; the withheld amount is reclassified to a liability owed to the
authority). Contained extension of Wave 8a — because settlement is gross, 8a's `amount`/allocation invariants
are untouched; WHT is one header snapshot + one credit line. Greenfield WHT on this branch.

| Stage | State |
|---|---|
| 1 · Requirements | ✅ Gate 1 APPROVED 2026-09-09 |
| 2 · Architecture (ADR 0021) | ✅ Gate 2 APPROVED 2026-09-09 (5 forks resolved) |
| 3 · Build | ✅ all 6 stages GREEN — test-first (Opus). Purchasing+Accounting 861 · Sales 805 · Pint+PHPStan clean |
| 4 · Review (QA ∥ Security) | ✅ Security PASS · QA PASS-WITH-FIXES → all applied |
| Delivery | ✅ **PR #10 open** (stacked on `feature/phase5-supplier-payments`) |

## Review outcomes (Stage 4)
- **Security (Opus, independent): PASS** — money integrity (double-guarded `wht ≤ amount`, balances every rounding outcome incl. net-zero, no floats, safe pre-VAT divide), the create-not-stamp backfill (tenant-id binding, idempotency, RLS scaffold, accounts CHECKs, safe `down()`), the freeze function keeping all columns + the two new ones, company-scoped WHT Payable resolution, unchanged authorization. One pre-existing, platform-wide INFO note (currency_precision CHECK allows >4 but `Money::roundedTo()` caps at 4) — filed as a separate backlog task, not an 8b defect.
- **QA (independent): PASS-WITH-FIXES → resolved.** 1,666 green, 0 regressions; every gap was probed and the code confirmed correct — all were test-coverage gaps. Applied: a **multi-bill round-once** test (fork 4 regression guard), the two missing **drop-and-retry** mutation tests (`wht_amount_non_negative`, `wht_rate_range`), the two **backfill trip** tests (`assertBypassEffective`/`assertNothingLeftBehind`), plus partial+VAT, 100%-net-zero-through-`record()`, and WHT-Payable accumulation tests. Purchasing+Accounting 869 green after fixes.

## Build (Stage 3 complete)
1. Supplier WHT config (`wht_applicable`/`wht_rate` + CHECKs; `SupplierService`/`SupplierData`).
2. `2180 WHT Payable` (`Account::WHT_PAYABLE`, template + `requiredSystemAccounts` + VERSION `-3`→`-4`,
   create-not-stamp backfill).
3. `supplier_payments.wht_amount`/`wht_rate` + CHECKs + freeze-trigger update.
4. `PaymentData` `withholdTax`/`whtRateOverride`; `PaymentPostingMap` credit split + `whtPayableAccountFor`.
5. `PaymentService::record()` WHT on the pre-VAT base (`allocation × subtotal/total`), round-once, gross
   settlement (steps 11–17 unchanged); rate default/override/suppress.
6. Authorization unchanged (reuse `purchasing.payments.post`; no-`.wht` scope-creep guard).

Commits: `da69999` Gate 1 · `258eb10` ADR 0021 · `3573ed6` Gate 2 · `9fe69d9` Stages 1–2 · `9b60bb4` Stages 3–6.

---

## Wave 8a — DELIVERED (PR #9, stacked on `feature/phase5-bills`)

Supplier payments + allocation: `Dr Trade Payables = Cr Bank`, gross record-and-post, born posted, gapless
`PAY-`. Security PASS · QA PASS-WITH-FIXES (all applied). Whole app 2,113 green.

## Review outcomes (Stage 5)

- **Security review (Opus, independent): PASS.** No blocking/fix-required defects across tenant isolation (both tables FORCE-RLS with own policies), the PL/pgSQL trigger functions (placeholder/arg parity correct; deferred trigger closes the born-posted rogue-insert hole), authorization gating, money integrity/injection, immutability, and the ascending-id lock-before-sequence order. Two non-blocking INFO notes: (1) add `->forCompany()` to the lock query as defense-in-depth; (2) the HTTP layer (future wave) must authorize + constrain `$company` to the actor's memberships.
- **QA verification (independent): PASS-WITH-FIXES → resolved.** 162 payment + 522 Purchasing + 805 Sales green; full AC matrix; every CHECK proven non-vacuous. Fixes applied:
  1. **Production (branch on ledger lines):** ADR §A2 said the payment branch propagates to its journal lines (bills do) but §B's map signature omitted it, so lines posted `branch_id = NULL`. **Returned to Gate 2; human chose to honour §A2.** `PaymentPostingMap::for()` now takes `branchId` and stamps both lines; `PaymentService` passes the resolved branch. New `RecordPaymentTest` assertions (present + null cases). ADR amended (§B→§A2).
  2. **Reference coverage (AC-3.2):** added a positive test — a caller-supplied `reference` persists, and two payments may share one (dec. 7, non-unique).
  3. **Race test honesty:** reframed the payload-order test to state exactly what it proves; documented that isolating the locked re-read requires an OS-concurrency harness the project lacks (inherited from `BillDuplicateNumberRaceTest`), and that the DB backstop is the tested authority under a race.
  4. Renamed the mislabeled factory relation test.

## Build result (Stage 4 complete)

All six ADR-0020 stages implemented against the RED suites, every stage driven to green:

- **Stage 1** — 5 migrations (relax bills CHECKs; `supplier_payments` + `payment_allocations`; FORCED RLS; payment/allocation freeze + deferred full-allocation constraint triggers). `PaymentSchemaTest` + `PaymentSchemaCheckMutationTest` green.
- **Stage 2** — `DocumentType::Payment` (PAY, gapless), `PaymentStatus`, `SupplierPayment`/`PaymentAllocation` + factories, morph alias. `PaymentModelTest` + `PaymentFactoryTest` green.
- **Stage 3** — `PaymentData`/`PaymentAllocationData`, `PaymentPostingMap` (`Dr Trade Payables = Cr Bank`), `PaymentCannotBeRecorded`/`PaymentCannotBePosted`. `PaymentPostingMapTest` green.
- **Stage 4** — `PaymentService::record()` (one-step record-and-post, multi-bill ascending-id lock, two-series numbering). `RecordPaymentTest` + `PaymentAllocationRaceTest` green.
- **Stage 5** — `purchasing.payments.{view,post}`, role grants (accountant posts; bookkeeper/viewer view-only), `PaymentPolicy`. `PaymentAuthorizationTest` green.
- **Stage 6** — probe regression: `PayableBalanceProbePaymentsTest` green (`amount_due` invariant holds after payment).

Static: Pint clean, PHPStan clean on all Wave 8a paths. Four `RecordPaymentTest` numbering expectations were corrected (they had ignored the bill's own JV; `BillService::post()` consumes a JV, proven by `PostBillTest`) — implementation matches the ADR.

## Gate decisions (approved)
- Born-posted `supplier_payments` + `payment_allocations`; `Dr Trade Payables = Cr Bank` (caller-named, Asset-only); full allocation via a deferred constraint trigger + per-bill cap under lock; ascending-bill-id lock order; drop `bills_no_payments_until_payments_phase` + add `bills_amount_due_non_negative_check`; **gapless `PAY-`** numbering; `purchasing.payments.{view,post}` (accountant posts, bookkeeper/viewer view). WHT-on-payment, cancellation, credit-on-account all DEFERRED.

## Build stages (ADR 0020)
1. Migrations (relax/add bills CHECKs; both payment tables; RLS; freeze + deferred full-allocation triggers) · 2. `DocumentType::Payment` + `PaymentStatus` + models/factories · 3. DTOs + `PaymentPostingMap` + exceptions · 4. `PaymentService::record()` + two-series numbering + wiring · 5. `purchasing.payments.*` + `PaymentPolicy` · 6. probe regression (amount_due invariant).

## Related (open PRs)
Phase 3 FE **#2** · Phase 4 **#3–#6** · Phase 5 **#7** (suppliers) · **#8** (bills) · Wave 8a → **#9** pending.

## What you (the human) need to do next
Nothing blocking — Gates 1 & 2 approved; build → QA + Security → PR within ADR 0020. After 8a, Phase 5 core is complete (deferred: WHT-on-payment 8b, cancellations, credit-on-account); next phase boundary is **Multi-currency/FX**, where I'll check in. Prod (Gate 3) always needs you; none in scope.
