# Project status — Phase 4 withholding tax on receipt (Minions Team 5)

**Last updated:** 2026-08-31 · **Branch:** `feature/phase4-withholding-tax` · **Base:** `feature/phase4-credit-on-account` (stacked)

## Where we are
Wave 5 **delivered**: withholding tax (WHT) on customer receipts. A customer withholds tax and pays net;
the invoice's AR settles in full — `Dr Bank(net) + Dr WHT Receivable = Cr AR(gross)`, coexisting with
ADR 0016's variable-line posting. Backend wave (no HTTP, no new permission). Delivered as PR #6.

| Stage | State |
|---|---|
| 1 · Intake | ✅ carried forward |
| 2 · Requirements | ✅ Gate 1 APPROVED 2026-08-31 |
| 3 · Architecture (ADR 0017) | ✅ Gate 2 APPROVED 2026-08-31 |
| 4 · Build | ✅ 4 stages test-first (Opus) — `1e550eb`(RED),`af54f44`,`c2e4955`,`fef46ea` |
| 5 · Review (QA ∥ Security) | ✅ Security **PASS**; QA **PASS-WITH-FIXES** → 2 stale-test fixes applied, now green |
| Delivery | ✅ **PR #6 opened** (base `feature/phase4-credit-on-account`) |

## Result
- **Tests:** 40 WHT + full Sales 999 + Accounting green after the 2 stale-test fixes; **0 production regressions** (Σwht=0 byte-identical). Pint + PHPStan clean. QA added a `wht==allocation` boundary test.
- **Reviews:** Security **PASS** (0 blockers, 2 informational nits — deliberate boundaries). QA **PASS-WITH-FIXES**: the 2 fixes were stale Accounting tests (VERSION pin `-3`→`-4`; API test off the now-provisioned `1180` → `1190`), not production defects — applied + a lesson written to `.minions/memory/backend_engineer.md`.
- **Delivered:** `1180 WHT Receivable` account (+ backfill); per-allocation `wht_amount`/`wht_certificate_reference`; settlement invariant (gross may exceed net cash only when WHT covers it); `Dr Bank net + Dr WHT = Cr AR gross` posting; cancellation reverses WHT via the generic mirror (no new code).

## Known limitations (accepted)
- 100%-withheld / zero-net-cash receipt refused by design (manual JV); pure-WHT adjustments out of scope this wave.

## Related (stacked, all open)
- PR #2 (front-end) · PR #3 (receipts) · PR #4 (cancellation) · PR #5 (credit-on-account) · **PR #6 (WHT)**. Merge bottom-up.

## What you (the human) need to do next
Nothing blocking. Review/merge the PR chain in order when ready. **Phase 5 (Purchasing)** opens next and will STOP at Gate 1. Prod deploy (Gate 3) always needs you; none in scope. **Phase 4 (Payments) is now functionally complete** across receipts, allocation, cancellation, credit-on-account, and WHT.
