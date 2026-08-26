# Project status — Phase 4 credit on account (Minions Team 4)

**Last updated:** 2026-08-26 · **Branch:** `feature/phase4-credit-on-account` · **Base:** `feature/phase4-cancellation` (stacked)

## Where we are
Wave 4 of the rolling program. Building **unallocated credit on account + apply-credit**: a receipt may
leave a remainder (Σ allocations < amount, ≥1 invoice still required) held as **Customer Advances**
credit, later **applied** to another invoice. Backend wave (no HTTP). Mirrors the receipts/cancellation
discipline (ADR 0014/0015).

| Stage | State |
|---|---|
| 1 · Intake | ✅ carried forward (ASAP/critical, Balanced) |
| 2 · Requirements | ✅ [PHASE-4-CREDIT-ON-ACCOUNT-REQUIREMENTS.md](PHASE-4-CREDIT-ON-ACCOUNT-REQUIREMENTS.md) — **Gate 1 APPROVED** 2026-08-26 |
| 3 · Architecture | ✅ [ADR 0016](adr/0016-unallocated-credit-on-account-and-apply-credit.md) — **Gate 2 APPROVED** 2026-08-26 (raw-SQL backfill; HTTP deferred; no reverseApplication) |
| 4 · Build | 🔵 in progress — 6 stages, test-first (Backend Engineer, Opus) |
| 5 · Review (QA ∥ Security) | ⏳ |
| Delivery | ⏳ stacked PR (base `feature/phase4-cancellation`) |

## Build stages (ADR 0016 §O)
1. Account `2180 Customer Advances` (new + existing companies via raw-SQL backfill)
2. Held-credit schema + models (`receipt_held_credits` + `credit_applications`, CHECKs, RLS, immutability)
3. Record path + variable-line posting (remainder → held credit; flip ADR 0014 under-allocation test)
4. Apply-credit domain op (`ReceiptService::applyCredit()`, FIFO + explicit source, atomic)
5. Cancellation interaction (unwind untouched credit; refuse applied credit)
6. Permission + policy (`sales.receipts.apply-credit`, accountant-only)

## Known limitation (Gate 2 accepted)
No service-path `reverseApplication()` this wave: once credit is applied, neither the source receipt nor
the target invoice can be cancelled via the service — a mistaken application is undone by a manual JV until
a later slice adds reversal (the interim the project accepted before ADR 0015).

## Related (stacked, all open)
- PR #2 — Phase 3 front-end · PR #3 — Phase 4 receipts+allocation · PR #4 — Phase 4 receipt cancellation
- This wave stacks on #4's branch.

## What you (the human) need to do next
Nothing right now — Gates 1 & 2 approved; build → QA + Security → PR runs autonomously within ADR 0016.
When the stacked PR opens, review/merge the chain in order. Prod deploy (Gate 3) always needs you; none in scope.
