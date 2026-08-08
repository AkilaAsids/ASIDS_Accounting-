# Phase 2 — Accounting core: build status

**Last updated:** 2026-08-08
**State:** **Complete and verified.** Every quality gate passes on a real toolchain against a real
PostgreSQL with row level security in force, and the three accounting screens have been exercised
in a browser against seeded books.

| Gate | Result |
| --- | --- |
| Pint | passes |
| PHPStan level 8 (Larastan) | **0 errors** |
| Pest | **734 passing**, 0 skipped, 0 risky |
| Backend coverage | **86.4 %** against a `--min=85` gate |
| `vue-tsc --noEmit` | 0 errors |
| ESLint (`--max-warnings 0`) | 0 problems |
| Vitest | **162 passing** across 8 spec files |
| `vite build` | succeeds |
| `node scripts/check-openapi.mjs` | 95 routes, 95 documented, all `$ref`s resolve |
| `asids:security-check` | passes (2 informational warnings, both expected outside production) |

Row level security was checked separately against `pg_class` and `pg_policies`: all eight
accounting tables report `relrowsecurity`, `relforcerowsecurity` and exactly one isolation policy.

Of the 734 backend tests, **272 are Phase 2** across seven files.

---

## Scope (agreed)

Accounting core only, designed before it was built. Fiscal calendar, chart of accounts, the
double-entry ledger, per-period balances, the trial balance, opening balances, period and year
close, the HTTP surface, and three Vue screens.

Explicitly **not** in this phase: invoicing, payments, bank reconciliation, tax and VAT return
preparation, and foreign currency conversion. The multi-currency *columns* exist per the ruling in
[the design](PHASE-2-DESIGN.md#81-multi-currency-columns-build-the-shape-now-keep-the-phase-base-currency-only);
no conversion logic does.

---

## What running it found

Six defects, all found by running the code rather than reading it. Four are recorded in the
tranche commits; the two below were found in the final browser pass and are the ones worth reading.

### 1. A rejected post left a draft behind — real, customer-visible

`JournalEntryController::store()` drafted the entry, committed, and *then* posted it. Every way
posting can be refused — the entry does not balance, the period is closed, the caller may draft but
not post — left the draft committed in the customer's books.

Reproduced in the browser: two mistyped amounts produced two error toasts and two orphan drafts
named "Consulting fee received" sitting in the journal. An accountant correcting a typo would
accumulate one draft per attempt, and nothing in the interface would tell them why.

The fix is one transaction around both steps, so `post: true` means all of it or none of it. The
authorisation check stays *inside* the transaction, after the draft exists, so the policy still
sees a real entry and the response is still a `403` — it just no longer leaves a row behind.
`PostingService::postNew()` had existed since tranche 3 for exactly this and was not being used.

Two regression tests, one per refusal path, assert the entry count is unchanged.

### 2. The API offered actions that could only fail

A posted entry reported `can_post: true` and `can_update: true` to a workspace owner, so the
journal list rendered a "Post" link beside every already-posted entry. Clicking it produced a 422.

The cause is not in the accounting module. `Gate::before` grants a tenant owner every ability
outright, which is correct for *permissions* but also short-circuits the status guards inside
`JournalEntryPolicy` — `$entry->isEditable()` never runs for an owner.

The ledger was never at risk: `PUT`, `DELETE` and re-`POST` on a posted entry were each verified to
return 422, and the immutability trigger sits behind them. This was the interface offering a button
whose only outcome is an error.

`JournalEntryResource` now asks two questions separately — the gate for "may this person", the
entry for "is this meaningful in this state" — with the reason stated in the code, since the
obvious reading is that the second check is redundant.

> **Carried into Phase 3.** The root cause is unfixed by design. Making the owner short-circuit
> apply only to permission checks and not to model policies would subject owners to
> `canAccessCompany`, which is membership-based — a tenant owner who has not joined a company would
> lose access to it. That is a product decision about what "owner" means, not a bug fix, and it
> should be taken deliberately rather than as a side effect of this phase. Until then, any resource
> exposing a `capabilities` block for a model whose policy carries a *state* precondition must
> consult the model as well as the gate.

### 3–6. Recorded in the tranche commits

A `periods()` relation whose own `orderBy` silently demoted an appended `orderByDesc`, posting a
year-end entry into January. Two `fiscal_periods_closed_check` violations where new code moved
`status` without moving `closed_at` — the tranche 1 constraint was right and the new code was
wrong. An `is_closed` column left to the database default, so the in-memory model returned `null`
and threw under model strictness — the same trap as Phase 1's `must_change_password`.

---

## Findings that are not defects

**The OpenAPI specification had never been valid YAML.** A plain scalar containing `": "` sat in
`docs/api/openapi.yaml` from Phase 1, so the file could not be parsed by anything. It was never
caught because nothing read it. Fixed, and `scripts/check-openapi.mjs` now parses it, resolves
every `$ref`, and compares it against `php artisan route:list` in both directions. The check runs
in CI with `--require-routes`, so "PHP was unavailable, comparison skipped" fails rather than
passes quietly.

It found two undocumented Phase 1 endpoints while it was there: `GET /users/{userId}/devices`, and
`PATCH /roles/{roleId}` — the latter an incidental artefact of `apiResource`, now documented as
what it is rather than pretended away.

**Deferred constraints do not fire under `RefreshDatabase`.** The wrapping transaction is rolled
back rather than committed, so a test that means to prove the balance trigger works passes whether
the trigger exists or not. Tests that exercise it issue `SET CONSTRAINTS ALL IMMEDIATE` first.

**Three guards are unreachable and kept anyway**, each with a comment saying why: they encode a
rule that becomes reachable the moment a caller is added, and deleting them would make the next
caller's mistake silent.

---

## What the browser pass verified

Against seeded books — the starter chart applied, a fiscal year opened, and two posted entries
(LKR 1,250,000 cash sale, LKR 180,000 rent):

- **Trial balance** renders grouped by statement section, blanks zero cells, and reports
  `1,430,000.00` on both sides with the server's own `ties` verdict. Cash nets correctly to
  `1,070,000.00`.
- **Chart of accounts** renders the hierarchy with headings indented and non-postable.
- **Journal entries** shows gapless numbering (`JV-2026-07-0001`, `-0002`, then `JV-2026-08-0001`
  after the month rolled), and the account picker offers only postable accounts.
- An unbalanced entry is refused with a message naming the difference, and — after the fix above —
  leaves nothing behind. Verified by listing entries before and after: three, then three.

No JavaScript exceptions. Every console error in the pass was a deliberate probe.

---

## Coverage

86.4 % overall against a `--min=85` gate. The accounting module's own figures are higher than the
platform average; what pulls the total down is Phase 1 infrastructure with few callers
(`TenantNotFound`, `TenantUnavailable`, the queue bootstrapper) rather than anything in this phase.

Front-end coverage uses per-layer thresholds. `pages/` sits at 0 deliberately — the three
accounting screens are verified in a browser against a real server, which is worth more than a
mounted component asserting against a mocked client. `useMoney` is unit-tested at 11 cases, because
the property worth testing there is what it refuses to do: there is no arithmetic in it, and that
absence is the design.

---

## Running it

```bash
php artisan migrate --force
```

```bash
php artisan asids:sync-permissions
```

```bash
php artisan asids:ledger-verify
```

`asids:ledger-verify` recomputes every account-period aggregate from `journal_lines` and reports
disagreement. `asids:ledger-rebuild --tenant=<slug> --confirm` rewrites them; both flags are
required so it cannot be run absent-mindedly against the wrong tenant.

---

## The starter chart of accounts

Ships as `2026.02-lk-sme-1`, roughly 45 accounts for a Sri Lankan SME, applied only onto an empty
chart. It carries **no tax or VAT mappings** — those are configured separately, per the ruling in
the design — and every response that exposes it carries a disclaimer.

**It is a starting point, not statutory advice.** A qualified Sri Lankan accounting or tax
professional must review it before it is relied on for a statutory filing. The version string is
stored on every account created from it, so a later revision can be identified and reconciled.

---

## Phase 3 readiness

Three things to carry forward.

The **owner short-circuit** described above is a product decision waiting to be taken, and every
module that exposes capabilities to a client inherits the workaround until it is.

The **repository inconsistency** flagged at the end of Phase 1 was not resolved here. Accounting
introduced services rather than repositories and queries models directly from them. That has worked
well and the services are the right seam, but it means the codebase now has two patterns and the
Phase 1 note still stands: pick one before a third module arrives.

**`TenantProvisioningService`** still does five things in one transaction, still depends outward on
three modules, and now has a fourth module's tables to provision. ADR 0005 predicted its extraction
point; this is the phase where the pressure became visible rather than theoretical.
