# Project status — Phase 5 supplier domain (Minions Team 6)

**Last updated:** 2026-09-01 · **Branch:** `feature/phase5-suppliers` · **Base:** `main` (independent of the Phase 4 stack)

## Where we are
Phase 5 (Purchasing) opened. **Wave 6: the supplier domain foundation** — the mirror of the Phase 3
customer domain (model, service, policy, permissions, factory), in a new `src/Core/Purchasing/` bounded
context. Master-data CRUD; no ledger posting, no HTTP this slice.

| Stage | State |
|---|---|
| 1 · Intake | ✅ carried forward |
| 2 · Requirements | ✅ [PHASE-5-SUPPLIERS-REQUIREMENTS.md](PHASE-5-SUPPLIERS-REQUIREMENTS.md) — **Gate 1 APPROVED** 2026-08-31 |
| 3 · Architecture (ADR 0018) | ✅ [ADR 0018](adr/0018-purchasing-supplier-domain-foundation.md) — **Gate 2 APPROVED** 2026-09-01 |
| 4 · Build | 🔵 in progress — 5 stages, test-first (Opus) |
| 5 · Review (QA ∥ Security) | ⏳ |
| Delivery | ⏳ PR (base `main`) |

## Gate decisions (approved)
New `src/Core/Purchasing/` module; `suppliers` = customer mirror less credit_limit/AP account, keeps TIN; `S-` non-gapless per-company codes; FORCED RLS; `purchasing.suppliers.{view,manage}` (manage sensitive) to accountant/bookkeeper/viewer; dormant `PayableBalanceProbe`/`NoPayables` seam; domain-only.

## Build stages (ADR 0018 §F)
1. Module skeleton + `suppliers` schema + FORCED RLS
2. `SupplierStatus` enum + `Supplier` model + factory + morph alias
3. `PayableBalanceProbe` + `NoPayables` seam (dormant)
4. `SupplierData` DTO + `SupplierService` (CRUD/lifecycle + archive-with-balance via probe)
5. `purchasing.suppliers.*` catalogue + role grants + `SupplierPolicy`

## Phase 5 roadmap (proposed)
Wave 6 suppliers (this) → Wave 7 bills/purchase invoices (input VAT, tax_codes.input_account_id, gapless numbering) → Wave 8 supplier payments + WHT-on-payment.

## Related (open PRs)
- Phase 3 FE **#2**; Phase 4 **#3–#6** (stacked). Phase 5 branches off `main`, separate line → its own PR #7.

## What you (the human) need to do next
Nothing right now — Gates 1 & 2 approved; build → QA + Security → PR runs within ADR 0018. Review/merge the open PRs when ready. Prod (Gate 3) always needs you; none in scope.
