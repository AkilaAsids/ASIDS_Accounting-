# Worklog — Phase 5 bills / purchase invoices (Minions Team 7, Wave 7)

**Date:** 2026-09-01 → 2026-09-02 · **Branch:** `feature/phase5-bills` (base `feature/phase5-suppliers`)

## What shipped
Bills — the purchase-side mirror of sales invoices; first purchasing ledger posting.
Draft → post: `Dr Expense(per line) + Dr Input VAT(via tax_codes.input_account_id) = Cr Trade Payables`.
supplier_invoice_number required (statutory identity + duplicate guard); internal BILL- non-gapless number;
EloquentPayableBalanceProbe rebound (Wave-6 supplier archive-with-balance rules now live); dry-run input-VAT backfill command.

## Commits
- `9dde59a` RED (11 suites, 254 fail) · Stages 1-4 `7ff9e53`/`6284f45`/`7c62fda`/`850e0d1` · Stages 5-8 `112dcb7`/`112e3f2`/`9411423`/`722ed94` · review fixes (asset-only backfill + QA race test).

## Gates
Gate 1 + Gate 2 (ADR 0019) approved. Input-VAT = refusal + dry-run backfill command. Cancellation deferred. No Gate 3.

## Verification
1,465 tests green (Purchasing 380, Accounting 280, Sales 805), 0 regressions. QA PASS, Security PASS-WITH-FIXES (should actioned).

## Review loop
- Security should: backfill command candidate query now requires type=asset + names the resolved account (was: could point at a renumbered non-asset 1170); +test for the non-asset-skip case.
- QA added BillDuplicateNumberRaceTest (index-race translation, non-vacuity proven).
- Harness finding: shared test DB deadlocks under concurrent artisan-test runs — run sequentially / per-runner DBs.
