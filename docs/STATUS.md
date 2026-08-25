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
| 3 · Architecture | 🔵 in progress — Solution Architect (Opus) |
| 4 · Build | ⏳ |
| 5 · Review (QA ∥ Security) | ⏳ |
| Delivery | ⏳ stacked PR (base `feature/phase4-payments`) |

## Related (stacked)
- Wave 1 — Phase 3 front-end → PR #2 (open)
- Wave 2 — Phase 4 receipts + allocation → PR #3 (open); this wave builds on it.

## What you (the human) need to do next
Review **Gate 1** — esp. the central policy question: delta-based balance restoration (subtract this
receipt's own contribution from the invoice's current state) vs. refusing cancellation if another
receipt has since touched the same invoice.
