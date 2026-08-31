# Project status — Phase 4 credit on account (Minions Team 4)

**Last updated:** 2026-08-31 · **Branch:** `feature/phase4-credit-on-account` · **Base:** `feature/phase4-cancellation` (stacked)

## Where we are
Wave 4 **delivered**: unallocated credit on account + apply-credit. A receipt may leave a remainder
(Σ allocations < amount, ≥1 invoice still required) held as **Customer Advances** credit, later
**applied** to another invoice (FIFO or explicit source). Backend wave (no HTTP). Delivered as PR #5.

| Stage | State |
|---|---|
| 1 · Intake | ✅ carried forward |
| 2 · Requirements | ✅ Gate 1 APPROVED 2026-08-26 |
| 3 · Architecture (ADR 0016) | ✅ Gate 2 APPROVED 2026-08-26 (+ currency_precision amendment; + invoice-cancel guard reorder approved 2026-08-31) |
| 4 · Build | ✅ 6 stages test-first (Opus) — `24cbb10`,`f91f25e`,`f3982e9`,`9289a67`,`08eddd1`,`b5571db` |
| 5 · Review (QA ∥ Security) | ✅ **both PASS** — 0 blockers, 0 should-fixes |
| Delivery | ✅ **PR #5 opened** (base `feature/phase4-cancellation`) — autonomy ends here |

## Result
- **Tests:** 1,248 green — wave suites 79, full Sales 972, Accounting 275, +1 QA-added coverage test. **0 regressions.** Pint + PHPStan clean.
- **Reviews:** Security **PASS**, QA **PASS**. Money integrity (subledger==ledger @ currency_precision), FIFO, concurrency (no over-consume), tenant/company isolation, cancellation safety, and the `b5571db` invoice-cancel guard reorder all verified.
- **Delivered:** `2180 Customer Advances` account (+ backfill); `receipt_held_credits` + `credit_applications` (RLS, immutability); variable-line receipt posting; `ReceiptService::applyCredit()` (FIFO/explicit); cancellation interaction; `sales.receipts.apply-credit` permission/policy.

## Known limitations / follow-ups (accepted, non-blocking)
1. **No `reverseApplication()`** this wave (Gate 2): once credit is applied, neither the source receipt nor the target invoice can be cancelled via the service — interim remedy is a manual JV.
2. **No HTTP surface** this wave (Gate 2): apply-credit REST route deferred to a later slice; that slice must resolve the FIFO-path authorization target (Security nit #2).
3. **Defensive hardening (Security nit #1):** `applyCredit()` advances the invoice by `$requested`; add a post-loop `assert remaining==0` / advance-by-sum-of-consumed as belt-and-braces on the money path.

## Related (stacked, all open)
- PR #2 (front-end) · PR #3 (receipts) · PR #4 (cancellation) · **PR #5 (credit-on-account)**. Merge bottom-up.

## What you (the human) need to do next
Nothing blocking. When ready, review/merge the PR chain in order. **Next wave (5, withholding tax on receipt)** is opening now and will STOP at Gate 1 for your approval. Prod deploy (Gate 3) always needs you; none in scope.
