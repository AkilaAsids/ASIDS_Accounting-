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
| 5 · Build (Phase 1) | 🔵 QA writing red API tests · BE-1 on Lane C hardening (parallel) |
| 5 · Build (Phase 2) | ⏳ BE-1 Lane A + BE-2 Lane B (after Phase 1) |
| 6 · Review | ⏳ Security + QA |
| PR for Akila | ⏳ end state · branch pushed, push access confirmed |

## What you (the human) need to do next
Nothing right now — engineers building. Next human touchpoint is the **final PR** for Akila (no prod/staging deploy in scope).

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
