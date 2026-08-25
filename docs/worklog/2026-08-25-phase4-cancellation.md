# Worklog — Phase 4 receipt cancellation (Minions Team 3, Wave 3)

**Date:** 2026-08-25 · **Branch:** `feature/phase4-cancellation` (base `feature/phase4-payments`)

## What shipped
Cancel a posted customer receipt: reverse its journal entry via `PostingService::reverse()`
(mirror `JV`, retains the `RCT`), delta-restore each allocated invoice's balance (subtract this
receipt's own allocation from the current locked `amount_paid`), and write cancellation metadata.
Domain + service + tests only — no HTTP surface. Mirrors the invoice-cancel pattern (ADR 0009).

## Commits (test-first, then 3 reviewable stages)
- `113a567` test(payments): receipt cancellation acceptance suite (RED, 36 tests, 31 red baseline)
- `23ca2b7` feat(payments): schema + immutability (Stage 1/3, ADR 0015 §A)
- `5f1cdab` feat(payments): `ReceiptService::cancel()` with delta-restore (Stage 2/3, §B,§C)
- `61036a8` feat(payments): `sales.receipts.cancel` permission/policy/audit (Stage 3/3, §D)

## Verification
- CancelReceipt **36/36**; Sales **906/906**; Accounting **262/262** — 0 regressions (1,204 tests). Pint clean, PHPStan no errors.
- Security review **PASS** (Opus), QA verification **PASS** (Opus). 0 blockers, 0 should-fixes, 5 documented nits.

## Gates
- Gate 1 (requirements — delta restoration) APPROVED 2026-08-25.
- Gate 2 (architecture — ADR 0015) APPROVED 2026-08-25.
- No Gate 3: backend wave, no staging/production deploy in scope. Autonomy ends at the stacked PR.

## Follow-ups (nits, non-blocking)
See `docs/STATUS.md` → "Known nits". The one worth actioning: move `wouldReverseBelowZero()` ahead of
`PostingService::reverse()` so no `JournalEntryReversed` event is dispatched on the defensive-refusal path.

## CI note
Full Sales suite OOMs under coverage collection; run `-d memory_limit=1G -d xdebug.mode=off --no-coverage`
or raise the coverage memory limit.
