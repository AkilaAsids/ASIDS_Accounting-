# Project status — Sales HTTP API (Minions Team 17)

**Last updated:** 2026-08-12 · **Branch:** `feature/sales-http-api` · **Base:** `main` @ `552a25c`

## Where we are
Delivering the Sales module's REST surface (customers + tax codes) **in parallel with
Milestone 5** (issuing/ledger posting, owned by Akila). The Minions do not touch the ledger.

| Stage | State |
|---|---|
| 1 · Intake | ✅ scope, team, git strategy, urgency confirmed by Isuru |
| 2 · Requirements | ✅ drafted — [SALES-HTTP-API-REQUIREMENTS.md](SALES-HTTP-API-REQUIREMENTS.md) · **⛔ awaiting Gate 1** |
| 3 · Architecture | ⏳ blocked on Gate 1 |
| 4–6 · Build + review | ⏳ |
| PR for Akila | ⏳ end state (no prod/staging deploy in scope) |

## What you (the human) need to do next
**Gate 1:** approve the requirements above so architecture + build can start.

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
