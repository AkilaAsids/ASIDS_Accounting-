# Project status — Phase 3 front end (Minions Team 1)

**Last updated:** 2026-08-24 · **Branch:** `feature/phase3-frontend` · **Base:** `main`

## Where we are
Building the **Phase 3 front-end** — Customer screens and Sales-invoice screens — over the
REST APIs that Milestones 6 and 9 already delivered and tested. **UI only: no backend changes,
no new endpoints, no migrations.** Autonomy ends at a PR for human review (no staging/prod deploy
in scope).

| Stage | State |
|---|---|
| 1 · Intake | ✅ scope (Phase 3 FE, then roll on), ASAP/critical, Balanced budget, feature-branch+push+PR — confirmed |
| 2 · Requirements | ✅ [PHASE-3-FRONTEND-REQUIREMENTS.md](PHASE-3-FRONTEND-REQUIREMENTS.md) — **Gate 1 APPROVED** 2026-08-24 (§9) |
| 3 · Architecture ∥ Design | ✅ [ADR 0013](adr/0013-phase3-frontend-architecture.md) + [DESIGN](PHASE-3-FRONTEND-DESIGN.md) — **Gate 2 APPROVED** 2026-08-24 (A: plain select · B: shared overflow menu · C: conditional journal link) |
| 4 · Build | 🔵 in progress — shared pre-step → Customer ∥ Invoice lanes (test-first) |
| 5 · Review (QA ∥ Security) | ⏳ |
| Delivery | ⏳ PR for human merge |

## Scope (two lanes, UI-only)
- **Customer lane** — list/search/filter/paginate, create, edit (clear-vs-omit), view, archive/restore, deactivate/reactivate, delete. Over the 9-endpoint customer API (Milestone 6).
- **Invoice lane** — list/filter/paginate, draft create, draft edit (line editor), view, issue, cancel, delete draft. Over the 7-endpoint sales-invoice API (Milestone 9).
- **Deferred:** Tax-code front-end (a read-only tax-code picker inside the invoice line editor is in scope; tax-code CRUD screens are not).

## Non-negotiable constraints carried into the build
- The UI **never computes money** — every total/tax figure comes from the API (ADR 0011 D5).
- Every page **refetches on company switch** (ADR 0011 D3) — silent failure mode, QA red-specs are the backstop.
- Destructive/sensitive actions gated on the resource `capabilities` (permission **and** state), not permission alone.
- `meta.pagination` rendered (first time in this codebase).

## What you (the human) need to do next
Nothing right now — the build is running (shared pre-step, then two parallel lanes, then review).
Next human touch point is the **PR** for review/merge (autonomy ends there; no staging/prod deploy).

## Note on prior status
This file previously tracked the Milestone 6 Sales HTTP API run (Minions Team 17, merged via PR #1);
that record lives in git history and in [ROADMAP.md](ROADMAP.md).
