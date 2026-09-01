# Project status — Phase 5 bills / purchase invoices (Minions Team 7)

**Last updated:** 2026-09-01 · **Branch:** `feature/phase5-bills` · **Base:** `feature/phase5-suppliers` (stacked)

## Where we are
Wave 7 of the rolling program. **Bills / purchase invoices** — the purchase-side mirror of sales
invoices, and the **first purchasing document that posts to the ledger**: draft a bill against a
supplier → post it → `Dr Expense(per line) + Dr Input VAT = Cr Trade Payables`. First real use of input VAT.
Backend wave (no HTTP). Cancellation deferred.

| Stage | State |
|---|---|
| 1 · Intake | ✅ | 2 · Requirements | ✅ Gate 1 APPROVED 2026-09-01 |
| 3 · Architecture (ADR 0019) | ✅ Gate 2 APPROVED 2026-09-01 |
| 4 · Build | 🔵 in progress — 8 stages, test-first (Opus) |
| 5 · Review (QA ∥ Security) | ⏳ |
| Delivery | ⏳ stacked PR (base `feature/phase5-suppliers`) |

## Gate decisions (approved)
- supplier_invoice_number required (statutory identity + duplicate key) + internal `BILL-` non-gapless number; cancellation deferred; AP = `Account::TRADE_PAYABLES` (2110), Input VAT `1170` keyless; expense line-level; duplicate supplier-invoice refused per supplier/company; bind real `EloquentPayableBalanceProbe`; input-VAT = refusal + dry-run backfill command; permissions `purchasing.bills.{view,draft,post}` (post sensitive).

## Build stages (ADR 0019)
1. `Account::TRADE_PAYABLES` 2110 + stamp/backfill · 2. `bills`/`bill_lines` schema + FORCED RLS + immutability · 3. `DocumentType::Bill` + `BillStatus` + models/factories · 4. DTOs + `BillPostingMap` + `BillCannotBePosted` · 5. `BillService` (draft/post) + duplicate guard · 6. `EloquentPayableBalanceProbe` rebind (activates Wave 6 rules) · 7. `purchasing.bills.*` + `BillPolicy` · 8. `purchasing:backfill-input-vat-accounts` command (dry-run-first).

## Related (open PRs)
Phase 3 FE **#2** · Phase 4 **#3–#6** · Phase 5 **#7** (suppliers) · Wave 7 → **#8** pending (stacked on #7).

## What you (the human) need to do next
Nothing blocking — Gates 1 & 2 approved; build → QA + Security → PR within ADR 0019. Merge open PRs when ready. Prod (Gate 3) always needs you; none in scope.
