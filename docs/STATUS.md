# Project status — Sales HTTP API (Minions Team 17)

**Last updated:** 2026-08-12 · **Branch:** `feature/sales-http-api` · **Base:** `main` @ `552a25c`

## Where we are
Delivering the Sales module's REST surface (customers + tax codes) **in parallel with
Milestone 5** (issuing/ledger posting, owned by Akila). The Minions do not touch the ledger.

| Stage | State |
|---|---|
| 1 · Intake | ✅ scope, team, git strategy, urgency confirmed by Isuru |
| 2 · Requirements | ✅ [SALES-HTTP-API-REQUIREMENTS.md](SALES-HTTP-API-REQUIREMENTS.md) · **Gate 1 APPROVED** 2026-08-12 |
| 3 · Architecture | ✅ [DESIGN](SALES-HTTP-API-DESIGN.md) + [ADR 0008](adr/0008-sales-http-api-and-customer-update-semantics.md) · **Gate 2 APPROVED** 2026-08-12 (keep DELETE, same-409 I4) |
| 4 · Task files | ✅ [docs/tasks/](tasks/) — Lanes A/B/C |
| 5 · Build | ✅ Lane C (`7e4c695`), Lane B (`af8f9dc`), Lane A (`1d82cc6`) + shared 403 fix (`29d0907`) + test-cache fix (`24146d2`) |
| 6 · Review | ✅ Security (Fable) **PASS-WITH-FIXES, 0 blockers**; fixes S1/S2/N1/N2 applied (`62d39f6`) |
| Delivery | ✅ **PR opened for Akila** — autonomy ends here (no staging/prod deploy in scope) |

## Merge with latest main (2026-08-12)
`origin/main` (`d781c80` — Akila's M5 Stage 2 posting map) merged into this branch — **clean, no conflicts**; 3 new M5 migrations applied. Validated together: **Sales + Accounting 790/790 green**. `origin/main` is an ancestor, so the PR merges cleanly.

## Result
Sales module REST surface delivered: **Customer API + Tax-code API + CustomerService hardening**, plus two incidental shared fixes (app-wide 403 rendering, test-cache isolation).
- **Tests:** full Sales suite **453/453**; Accounting suite green (403 fix, no regression). OpenAPI 113/113 routes documented.
- **Security:** no blockers; isolation/authz airtight; ADR D6 confirmed pre-existing.

## Known issues / for Akila's roadmap (NOT introduced by this work — verified pre-existing on `main`)
- `tests/Feature/Tenancy` — 11 failures from a rate-limiter/cache test-isolation gap in workspace registration (identical with main's `TestCase`).
- N3 — same-workspace 403-vs-404 existence oracle; platform-wide pattern (accounts/journals too), per ADR 0008 should be fixed once across all modules, not forked here.
- Stale `// EXPERIMENT: temporarily disabled` comment above an *active* `RecordRequestContext` in `bootstrap/app.php`.

## What you (the human) need to do next
Review + merge the PR (or hand to Akila). Optionally decide on the roadmap items above.

## Scope (3 firm lanes)
- **Lane C** — CustomerService hardening (I3 clear-vs-omit, I4 409, debt M6/M7/M8). Gates Lane A.
- **Lane A** — Customer REST API (`companies/{company}/customers`).
- **Lane B** — Tax-code REST API (`companies/{company}/tax-codes`). Independent.

## Known issues
_None yet._

## Where the full plan lives
- Requirements: [docs/SALES-HTTP-API-REQUIREMENTS.md](SALES-HTTP-API-REQUIREMENTS.md)
- Roadmap context: [docs/ROADMAP.md](ROADMAP.md) (this is the M6 customer/tax-code slice)
- Portal: Minions Team 17
