# Project status — Phase 4 Payments: receipts + allocation (Minions Team 2)

**Last updated:** 2026-08-25 · **Branch:** `feature/phase4-payments` · **Base:** `main`

## Where we are
Wave 2 of the rolling program. Building **Phase 4 Milestone A — customer receipts + allocation**:
record a receipt, allocate it across a customer's issued invoices, update `amount_paid`/`amount_due`,
and post to the ledger (Dr Bank/Cash, Cr Trade Receivables) through the existing posting machinery.
**Deferred:** withholding tax on receipt, unallocated credit on account. Backend wave (no front-end).

| Stage | State |
|---|---|
| 1 · Intake | ✅ carried forward (ASAP/critical, Balanced) + slice scope confirmed |
| 2 · Requirements | ✅ [PHASE-4-RECEIPTS-REQUIREMENTS.md](PHASE-4-RECEIPTS-REQUIREMENTS.md) — **Gate 1 AWAITING APPROVAL** |
| 3 · Architecture | ⏳ pending Gate 1 |
| 4 · Build | ⏳ |
| 5 · Review (QA ∥ Security) | ⏳ |
| Delivery | ⏳ PR for human merge |

## Related
- Wave 1 (Phase 3 front-end) is delivered on `feature/phase3-frontend` → PR #2 (open, in review).

## What you (the human) need to do next
Review **Gate 1** for Phase 4 — requirements + the open questions (esp. receipt cancellation in-scope-or-not,
under-allocation handling, and the bank/cash account model) — and approve before architecture begins.
