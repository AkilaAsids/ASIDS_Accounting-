# Project status — Phase 4 withholding tax on receipt (Minions Team 5)

**Last updated:** 2026-08-31 · **Branch:** `feature/phase4-withholding-tax` · **Base:** `feature/phase4-credit-on-account` (stacked)

## Where we are
Wave 5 of the rolling program. **Withholding tax (WHT) on customer receipts**: a customer withholds
tax and pays net, but the invoice's AR is settled in full — `Dr Bank(net) + Dr WHT Receivable = Cr AR(gross)`,
coexisting with ADR 0016's variable-line posting. Backend wave (no HTTP, no new permission).

| Stage | State |
|---|---|
| 1 · Intake | ✅ carried forward |
| 2 · Requirements | ✅ [PHASE-4-WHT-REQUIREMENTS.md](PHASE-4-WHT-REQUIREMENTS.md) — **Gate 1 APPROVED** 2026-08-31 |
| 3 · Architecture (ADR 0017) | ✅ [ADR 0017](adr/0017-withholding-tax-on-customer-receipts.md) — **Gate 2 APPROVED** 2026-08-31 |
| 4 · Build | 🔵 in progress — 4 stages, test-first (Opus) |
| 5 · Review (QA ∥ Security) | ⏳ |
| Delivery | ⏳ stacked PR (base `feature/phase4-credit-on-account`) |

## Gate decisions (approved)
- WHT Receivable account **1180** (system key `wht_receivable`); per-allocation `wht_amount` + `wht_certificate_reference` columns; **settlement = amount + Σwht** (gross allocations may exceed net cash only when WHT covers the gap); certificate reference independent of amount; raw-SQL backfill; reuse `sales.receipts.manage`.

## Build stages (ADR 0017 §F)
1. WHT Receivable account 1180 (new + existing via raw-SQL backfill)
2. Schema + model — `wht_amount`/`wht_certificate_reference` columns + CHECKs (frozen for free by the allocation trigger)
3. Record path + posting — settlement invariant, WHT debit line, `whtReceivableAccountFor()`, exception factories
4. Cancellation & apply-credit interaction (test-only — generic mirror reverses WHT; apply-credit posts no WHT)

## Related (stacked, all open)
- PR #2 (front-end) · PR #3 (receipts) · PR #4 (cancellation) · PR #5 (credit-on-account) · Wave 5 → PR #6 pending.

## What you (the human) need to do next
Nothing right now — Gates 1 & 2 approved; build → QA + Security → PR runs within ADR 0017. Merge the PR chain bottom-up when ready. Prod deploy (Gate 3) always needs you; none in scope.
