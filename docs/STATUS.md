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
| 2 · Requirements | ✅ [PHASE-4-RECEIPTS-REQUIREMENTS.md](PHASE-4-RECEIPTS-REQUIREMENTS.md) — **Gate 1 APPROVED** 2026-08-25 |
| 3 · Architecture | ✅ [ADR 0014](adr/0014-customer-receipts-and-allocation.md) — **Gate 2 APPROVED** 2026-08-25 |
| 4 · Build | ✅ 4 stages, test-first (Backend Engineer, Opus) |
| 5 · Review (QA ∥ Security) | ✅ QA 1177 green / 0 defects (7 test-setup defects fixed) · Security PASS, 0 blockers, 3 nits |
| Delivery | ✅ **PR #3 ready for review** — autonomy ends here (no staging/prod deploy in scope) |

## Result
Customer **receipts + allocation** delivered (backend/service layer): `ReceiptService::record()` records a receipt, allocates it across a customer's issued invoices, updates `amount_paid`/`amount_due` + status, and posts a balanced ledger entry (Dr Bank/Cash, Cr Trade Receivables) with gapless `RCT-` numbering — through the existing `PostingService` seam.
- **Tests:** full Sales + Accounting + Authorization suites **1177 passing / 0 failing**; `composer lint` + `composer analyse` (PHPStan) clean. Receipt code ~89.6% covered.
- **Security:** PASS, 0 blockers; ledger balance, two-layer no-oversell (lock + `amount_paid <= total` CHECK), double-post prevention, RLS on both new tables, authz, immutability all verified.

## Known limitations / fast-follows (not blockers)
- Deferred by scope: receipt cancellation/reversal, unallocated credit on account, withholding tax on receipt, and any HTTP/front-end surface.
- Security nits: a dead `currencyNotBase()` factory (base currency is structural this wave); one generic-vs-named exception on the cross-company branch path; an unreachable `firstOrFail()` inside the txn.
- Env: the full `composer test:coverage` OOMs at the default 128M `php.ini` limit (CI overrides to 2G) — a pre-existing local-run constraint.

## Related
- Wave 1 (Phase 3 front-end) is delivered on `feature/phase3-frontend` → PR #2 (open, in review).

## What you (the human) need to do next
Review and merge **PR #3** (Phase 4 receipts + allocation). Autonomy ended at the PR — no staging/production deploy was in scope.
