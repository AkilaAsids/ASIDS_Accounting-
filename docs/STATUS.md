# Project status — Phase 5 bills / purchase invoices (Minions Team 7)

**Last updated:** 2026-09-02 · **Branch:** `feature/phase5-bills` · **Base:** `feature/phase5-suppliers` (stacked)

## Where we are
Wave 7 **delivered**: bills / purchase invoices — the purchase-side mirror of sales invoices and the
**first purchasing document that posts to the ledger**. Draft a bill against a supplier → post →
`Dr Expense(per line) + Dr Input VAT = Cr Trade Payables`. First real input-VAT use. Cancellation deferred.
Delivered as PR #8.

| Stage | State |
|---|---|
| 1 · Intake | ✅ | 2 · Requirements | ✅ Gate 1 APPROVED 2026-09-01 |
| 3 · Architecture (ADR 0019) | ✅ Gate 2 APPROVED 2026-09-01 |
| 4 · Build | ✅ 8 stages test-first (Opus) — `7ff9e53`…`722ed94` |
| 5 · Review (QA ∥ Security) | ✅ QA **PASS**; Security **PASS-WITH-FIXES** → should + nit actioned |
| Delivery | ✅ **PR #8 opened** (base `feature/phase5-suppliers`) |

## Result
- **Tests:** 1,465 green — Purchasing 380 (+QA race test), Accounting 280, Sales 805. **0 regressions.** Pint + PHPStan clean.
- **Reviews:** QA **PASS** (added `BillDuplicateNumberRaceTest`); Security **PASS-WITH-FIXES** — the should (backfill command now asset-only + names the resolved account) and its coverage are in; the nit (ASCII `trim`) accepted as negligible.
- **Delivered:** `Account::TRADE_PAYABLES` (2110) + backfill; `bills`/`bill_lines` (+ FORCED RLS, posted-immutability, duplicate-supplier-invoice unique index); `DocumentType::Bill` (first non-gapless); `BillPostingMap`; `BillService` (draft/post + duplicate guard, two-series BILL-/JV numbering); `EloquentPayableBalanceProbe` rebind (Wave-6 supplier protections now live); `purchasing.bills.{view,draft,post}`; the dry-run `purchasing:backfill-input-vat-accounts` command.

## Known limitations (accepted)
- Cancellation/reversal deferred (status CHECK already reserves `cancelled`/payment states). Supplier payments + WHT-on-payment are Wave 8.

## ⚠ Harness note (from QA + Security)
The shared `asids_erp_testing` DB **deadlocks under concurrent `artisan test` runs** (`RefreshDatabase` `migrate:fresh` vs `drop table`). Run suites **sequentially** or per-runner DBs — this was the cause of several intermittent "stall/deadlock" failures. Not a code defect.

## Related (open PRs)
Phase 3 FE **#2** · Phase 4 **#3–#6** · Phase 5 **#7** (suppliers) · **#8** (bills, stacked on #7). Merge bottom-up.

## What you (the human) need to do next
Nothing blocking. Review/merge the open PRs when ready. **Wave 8 (supplier payments + WHT-on-payment)** — the final Phase 5 wave — opens next and STOPs at Gate 1. Prod (Gate 3) always needs you; none in scope.
