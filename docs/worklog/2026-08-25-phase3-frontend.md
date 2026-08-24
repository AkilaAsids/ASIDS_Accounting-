# Worklog — Phase 3 front-end (Minions Team 1)

**Dates:** 2026-08-24 → 2026-08-25 · **Branch:** `feature/phase3-frontend`

## What shipped
Front-end for the Customer and Sales-invoice domains, over the REST APIs delivered by Milestones 6
and 9. UI-only — no backend, no new endpoints, no migrations.

- **Shared foundations:** `Pagination`, `ConfirmDialog` (3 confirmation tiers), `OverflowMenu`;
  `useCompanyReload` + `useUnsavedGuard` composables; `CompanySwitcher` confirm-and-discard;
  Customer/SalesInvoice/TaxCode types; 8 routes + nav.
- **Customer lane:** list (search/status-filter/pagination), create, edit (clear-vs-omit),
  detail, and the archive/restore/deactivate/reactivate/delete lifecycle — all gated on the
  resource `capabilities`. Mobile card fallback on the list.
- **Invoice lane:** list, draft editor with the line editor (no client-side money math — totals
  come from the API; per-line 422 mapping; discount mutual-exclusion; read-only tax-code picker),
  detail, and issue/cancel/delete-draft, capability-gated. Journal-entry reference rendered as
  plain text (no journal-entry detail route exists yet).

## Process
Intake → Gate 1 (requirements, approved) → architecture (ADR 0013) ∥ design → Gate 2 (approved) →
shared pre-step → QA red acceptance specs (test-first) → Customer ∥ Invoice lanes → integrated
gate → QA verification ∥ Security review → fixes → PR. Three human gates honoured; no agent
reviewed its own work; Architect & Security ran on Opus (Fable unavailable on plan — approved
fallback).

## Verification
Full front-end suite **388 passing / 0 failing**; `vue-tsc` typecheck clean; ESLint clean;
production `vite build` succeeds. Security review: 0 blockers.

## Review findings addressed
- **Security (should):** `CustomerFormPage` did not react to a company switch — fixed to redirect
  to the customer list on switch (ADR 0011 D3), with regression specs.
- **QA verify:** corrected 10 acceptance-spec test-harness artifacts without weakening assertions.
- **Build:** 3 typecheck errors + 1 lint error found by the integrated gate, all fixed.

## Interruptions (environmental, no work lost)
Machine sleep (mitigated with `caffeinate`), a session usage-limit window, and transient
network/classifier errors each interrupted subagents mid-run; all were resumed from their
transcripts with no lost work.

## Known limitations / fast-follows
Searchable pickers (currently plain inputs/selects), invoice line-editor mobile layout, i18n of
the new screens, and Tax-code CRUD front-end are deferred. See STATUS.md.
