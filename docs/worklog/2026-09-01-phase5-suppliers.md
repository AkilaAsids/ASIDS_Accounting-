# Worklog — Phase 5 supplier domain (Minions Team 6, Wave 6)

**Date:** 2026-09-01 · **Branch:** `feature/phase5-suppliers` (base `main`)

## What shipped
New `src/Core/Purchasing/` bounded context with the supplier domain foundation — the mirror of the
Phase 3 customer domain. `suppliers` table (less credit_limit/AP account, keeps TIN) + FORCED RLS;
Supplier model/enum/factory; dormant PayableBalanceProbe/NoPayables seam; SupplierService (CRUD +
lifecycle + archive-with-balance); purchasing.suppliers.{view,manage} + SupplierPolicy. No ledger, no HTTP.

## Commits
- `ddb0bf2` RED suite (112 fail / 2 RLS-skip) · `eaa4b0d` Stage 1 module+schema+RLS · `6222f9f` Stage 2 model · `ad5ec23` Stage 3 probe · `5adc241` Stage 4 service · `5440c63` Stage 5 authz.

## Gates
Gate 1 approved 2026-08-31; Gate 2 (ADR 0018) approved 2026-09-01 incl. 2 divergences (manage sensitive; TIN audited). No Gate 3 (no deploy).

## Verification
Purchasing 118 green (incl. QA mutation test), Accounting 262 green. Security PASS, QA PASS. 0 blockers.

## Review loop
BE stopped-and-flagged 4 schema CHECK tests failing on PG 25P02 (bare failing insert aborts the
RefreshDatabase txn) — a harness interaction, not a schema defect. Fixed test-side (savepoint via
DB::transaction, mirroring IssueInvoiceTest); QA added SupplierSchemaCheckMutationTest proving non-vacuity.
