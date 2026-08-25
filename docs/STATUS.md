# Project status — Phase 4 receipt cancellation (Minions Team 3)

**Last updated:** 2026-08-25 · **Branch:** `feature/phase4-cancellation` · **Base:** `feature/phase4-payments` (stacked on PR #3)

## Where we are
Wave 3 of the rolling program. Building **receipt cancellation / reversal**: cancel a posted
customer receipt → reverse its journal entry (mirror `JV`, keeps its `RCT`) via the existing
`PostingService::reverse()` → restore each allocated invoice's `amount_paid`/`amount_due`/status
(delta subtraction against the current locked state) → write cancellation metadata. Mirrors the
existing invoice-cancel pattern (ADR 0009). Backend wave.

| Stage | State |
|---|---|
| 1 · Intake | ✅ carried forward (ASAP/critical, Balanced) + slice scope confirmed |
| 2 · Requirements | ✅ [PHASE-4-CANCELLATION-REQUIREMENTS.md](PHASE-4-CANCELLATION-REQUIREMENTS.md) — **Gate 1 APPROVED** 2026-08-25 (delta restoration) |
| 3 · Architecture | ✅ [ADR 0015](adr/0015-customer-receipt-cancellation.md) — **Gate 2 APPROVED** 2026-08-25 |
| 4 · Build | ✅ test-first, 3 stages (Backend Engineer, Opus) — `113a567` (RED suite) → `23ca2b7` (schema/trigger) → `5f1cdab` (service/delta-restore) → `61036a8` (permission/policy/audit) |
| 5 · Review (QA ∥ Security) | ✅ **both PASS** — QA (Opus) + Security (Opus), 0 blockers, 0 should-fixes, 5 nits documented below |
| Delivery | ✅ **stacked PR opened** (base `feature/phase4-payments`) — autonomy ends here |

## Result
Receipt cancellation delivered: schema + conditional-immutability trigger, `ReceiptService::cancel()`
with delta-based balance restoration, `ReceiptCannotBeCancelled` (8 factories), and the
`sales.receipts.cancel` permission/policy/audit surface — mirroring the invoice-cancel pattern (ADR 0009).
- **Tests:** CancelReceipt **36/36**; full **Sales 906/906**, **Accounting 262/262** — **0 regressions** (1,204 total). Pint clean, PHPStan no errors.
- **Reviews:** Security **PASS**; QA **PASS**. Correctness pivots proven with teeth: delta-restore multi-receipt (AC-C2.6) and negative-balance guard (AC-C2.7).

## Known nits (none blocking; consistent with the invoice-cancel convention)
1. `wouldReverseBelowZero()` runs *after* `PostingService::reverse()`; safe today (the `JournalEntryReversed` listener is transactional, rollback undoes it). Follow-up: move the guard to a pre-pass so no event fires on the defensive-refusal path.
2. Closed-period test doesn't independently prove "reversal period, not receipt_date" (carried by the dated-today test).
3. No test asserts the audit record for a cancellation (auditOnly() is correct by inspection).
4. `CustomerReceiptPolicy::cancel()` `canAccessCompany` branch not directly exercised (covered at the service level).
5. Lock ordering / live-race proven by inspection (matches `record()`), not a multi-process test.

## CI note
The full Sales suite OOMs under php-code-coverage collection; run with `-d memory_limit=1G -d xdebug.mode=off --no-coverage` (or raise the coverage memory limit).

## Related (stacked)
- Wave 1 — Phase 3 front-end → PR #2 (open)
- Wave 2 — Phase 4 receipts + allocation → PR #3 (open); this wave stacks on it.

## What you (the human) need to do next
Review + merge the stacked PRs in order (#2 front-end, #3 receipts, then this cancellation PR whose base
is `feature/phase4-payments`). Gates 1 & 2 were approved 2026-08-25; no further gate is due for this
backend wave (no staging/production deploy in scope). Optionally action nit #1 as a follow-up.
