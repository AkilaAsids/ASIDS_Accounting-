# Project status — Phase 5 supplier domain (Minions Team 6)

**Last updated:** 2026-09-01 · **Branch:** `feature/phase5-suppliers` · **Base:** `main` (independent of the Phase 4 stack)

## Where we are
Wave 6 **delivered**: the supplier domain foundation, in a new `src/Core/Purchasing/` bounded context —
the mirror of the Phase 3 customer domain (model, service, policy, permissions, factory, dormant
payable-balance probe). Master-data CRUD; no ledger posting, no HTTP. Delivered as PR #7 (off `main`).

| Stage | State |
|---|---|
| 1 · Intake | ✅ | 2 · Requirements | ✅ Gate 1 APPROVED 2026-08-31 |
| 3 · Architecture (ADR 0018) | ✅ Gate 2 APPROVED 2026-09-01 |
| 4 · Build | ✅ 5 stages test-first (Opus) — `eaa4b0d`,`6222f9f`,`ad5ec23`,`5adc241`,`5440c63` |
| 5 · Review (QA ∥ Security) | ✅ **both PASS** — 0 blockers, 0 should-fixes |
| Delivery | ✅ **PR #7 opened** (base `main`) |

## Result
- **Tests:** Purchasing **118 green** (incl. a QA mutation test proving the CHECK negatives are non-vacuous); Accounting **262 green** (boot integrity — new module registration undisturbed). Pint + PHPStan clean.
- **Reviews:** Security **PASS**, QA **PASS**. FORCED RLS + tenant/company isolation, authz (manage sensitive), S-code race safety, and the archive-with-balance seam all verified.
- **Delivered:** `src/Core/Purchasing/` module + provider; `suppliers` table (customer mirror less credit_limit/AP account, keeps TIN) + FORCED RLS; `Supplier`/`SupplierStatus`/factory; `PayableBalanceProbe`/`NoPayables` (dormant); `SupplierService`; `purchasing.suppliers.{view,manage}` + `SupplierPolicy`.
- **Test-harness fix:** the 4 CHECK-negative schema tests now savepoint the failing insert (PG 25P02) — proven non-vacuous by `SupplierSchemaCheckMutationTest`.

## Forward-looking follow-ups (for the Wave-7 HTTP slice; non-blocking)
Enforce `authorize('view', $company)` before `create` at the supplier controller (as Sales does); keep `code`/lifecycle fields out of request mass-assignment; flip the `PayableBalanceProbe` binding to the real Eloquent probe (the binding test catches a miss).

## Phase 5 roadmap
Wave 6 suppliers ✅ → Wave 7 bills/purchase invoices (input VAT, `tax_codes.input_account_id`, new DocumentType, gapless numbering, ledger posting) → Wave 8 supplier payments + WHT-on-payment.

## Related (open PRs)
Phase 3 FE **#2** · Phase 4 **#3–#6** (stacked) · Phase 5 **#7** (off `main`). Merge #2–#6 bottom-up; #7 is independent.

## What you (the human) need to do next
Nothing blocking. Review/merge the open PRs when ready. **Wave 7 (bills)** opens next and STOPs at Gate 1 — it's the first purchasing slice that posts to the ledger, so its gates matter. Prod (Gate 3) always needs you; none in scope.
