# Worklog — Phase 4 credit on account (Minions Team 4, Wave 4)

**Date:** 2026-08-26 → 2026-08-31 · **Branch:** `feature/phase4-credit-on-account` (base `feature/phase4-cancellation`)

## What shipped
Unallocated credit on account + apply-credit. A receipt may hold a remainder (Σ allocations < amount,
≥1 invoice required) as Customer Advances credit; `ReceiptService::applyCredit()` later applies it to an
invoice (FIFO by receipt_date then number, or explicit source), posting Dr Customer Advances / Cr AR at
currency_precision. Cancellation unwinds untouched credit and refuses already-applied. Backend only.

## Commits
- `24cbb10` Stage 1 — Customer Advances account 2180 + raw-SQL backfill (ADR 0016 §A)
- `f91f25e` Stage 2 — held-credit schema + models (§B)
- `f3982e9` Stage 3 — record path + variable-line posting @ currency_precision (§C + Gate-2 amendment)
- `9289a67` Stage 4-5 — applyCredit() (FIFO) + cancellation interaction (§D-E)
- `08eddd1` Stage 6 — sales.receipts.apply-credit permission + policy (§F)
- `b5571db` ADR 0015 amend — invoice-cancel guard reorder (Gate 2 approved 2026-08-31)

## Gates
- Gate 1 (requirements) APPROVED 2026-08-26.
- Gate 2 (ADR 0016) APPROVED 2026-08-26; + currency_precision amendment; + guard-reorder decision 2026-08-31.
- No Gate 3 (backend wave, no deploy). Autonomy ended at PR #5.

## Verification
- 1,248 tests green (wave 79, Sales 972, Accounting 275, +1 QA coverage test). 0 regressions. Pint + PHPStan clean.
- Security PASS, QA PASS. 0 blockers, 0 should-fixes.

## Follow-ups (non-blocking) — see STATUS.md
reverseApplication() deferred; HTTP deferred (must resolve FIFO auth target); defensive assert on apply invoice-forward.

## Two flagged-and-approved mid-build decisions (both returned to Gate 2, as policy requires)
1. **currency_precision amendment** — held credit at 4dp diverged from the GL's 2dp; resolved by holding/posting at currency_precision and refusing sub-precision inputs.
2. **invoice-cancel guard reorder** — ADR 0016 assumed the existing `partiallyPaid()` guard refused a credit-applied invoice, but `status!==Issued` fired first with the wrong code; reordered so any paid invoice refuses with `partially-paid`.
