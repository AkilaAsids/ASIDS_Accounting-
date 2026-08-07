# Phase 1 code review

A review of my own work. Recorded because the specification asks for one at the end of every phase,
and because the honest findings are more useful than a clean bill of health.

**Revised 2026-08-07, after the code was run.** The original review was conducted by reading, on a
machine with no PHP, Node or Docker, and said so. It has since been executed: 462 backend tests, 149
front-end tests, PHPStan level 8 clean, 85.6 % coverage. The sections below are kept and marked
where running it changed the verdict — a review that quietly rewrites its own history is worth less
than one that shows where reading was not enough.

## Method

Originally: reading every file, plus a static cross-reference (PSR-4 correctness, unresolved
internal references, route-handler existence, front-end import resolution). That established the
tree would autoload and nothing about behaviour.

Now: the same reading, plus every quality gate executing against a real PostgreSQL with row level
security in force as a NOBYPASSRLS role.

**What reading missed.** Eight further defects, three of them severe — a permission-gate ordering
problem that made suspension revoke nothing, two audit writers that threw outside production, and a
test environment misconfigured such that the whole suite read empty tables. Each is documented in
[PHASE-1-STATUS.md](PHASE-1-STATUS.md#what-running-it-found). None of them was a syntax or wiring
error, which is precisely the class reading catches; all three were about *ordering and
environment* — what runs before what, and which environment disables which check. That is the
lesson worth carrying into Phase 2: static review has a hard ceiling, and it sits exactly where the
security-relevant behaviour lives.

## Bugs found and fixed during the build

Recorded because they indicate the *class* of error that reading catches and does not.

| Bug | How it would have failed |
| --- | --- |
| `array_filter` on PDO options in `config/database.php` | `PDO::ATTR_EMULATE_PREPARES => false` is falsy, so `array_filter` silently deleted it — emulated prepares, quietly reintroducing an injection surface |
| `bigIncrements('sequence')` alongside `uuid('id')->primary()` | Two primary keys; migration fails outright |
| Two `__invoke` methods on `TenantContextProcessor` | Fatal error; fixed by splitting tap from processor |
| Two methods named `includes()` on `QueryCriteria` | Fatal error; renamed to `hasInclude()` |
| `paginate()` colliding between base repository and interface | Fatal error; renamed to `paginateQuery()` |
| `proc_open` in `disable_functions` | Would have broken Horizon, the scheduler and Pest — all use Symfony Process |
| `TenantScope` allowing NULL-tenant rows universally | Platform staff accounts would appear in every customer's user list; made opt-in per model |
| `password_reset_tokens` keyed on e-mail | A reset requested for workspace A redeemable in workspace B. Removed the table; built signed links |
| Audit hash chain computed inline | An advisory lock held for the whole business transaction, serialising every audited write in a workspace |
| Two classes in one middleware file | `PasswordExpired` would not autoload |

The last four are the interesting ones: each was found by asking "what does this do at scale?"
or "what does this do across tenants?", not by reading for syntax.

## What I judge to be good

- **Isolation is layered and each layer states its own limit.** ADR 0001 says plainly that RLS
  does not defend against a compromised credential. A document claiming otherwise would be
  worse than none.
- **Invariants live in the database where they can.** Partial unique indexes for
  one-default-per-tenant, check constraints for `(tenant_id IS NULL) = is_platform_admin` and
  SVAT-implies-VAT, triggers for append-only. A service can be bypassed; a constraint cannot.
- **Catalogues in code, not data.** Permissions and settings are defined in code and
  synchronised outward, so a capability cannot exist without the code that honours it, and the
  whole security surface is readable in one file.
- **Failure modes are chosen deliberately.** `TenantScope` fails closed. `AuditRecorder` never
  fails a business operation. `PermissionSynchroniser` reports orphans rather than cascading a
  delete. Each of those is written down next to the code.
- **Seams built before they are needed.** `CompliancePackContract` and `LedgerActivityProbe`
  exist now precisely so `base_currency_code` immutability does not become a rule nobody ever
  enforces.

## What I judge to be weak

**1. ~~Nothing has run.~~ Resolved.** This dominated every other finding, and it was right to. All
seven listed assumptions are settled, and the three severe defects that running it surfaced were all
in the category I had flagged as unknowable by reading. Worth keeping visible: my own estimate at the
time was "roughly 85 % built and 0 % verified", and the built figure held up — the defects were
concentrated in ordering and environment, not in structure.

**2. ~~No tests.~~ Resolved.** 462 backend and 149 front-end tests, 85.6 % coverage. The security
claims in [SECURITY-REVIEW.md](SECURITY-REVIEW.md) are now executed rather than asserted — with the
exceptions that document still lists as unverified, which are the ones no test can settle.

**3. `TenantProvisioningService` is doing a lot.** Five concerns in one transaction, and it is
the one class that depends outward on three other modules. ADR 0005 justifies it and predicts
the extraction point. I think that is right, but it is the class I would most expect to regret.

**4. ~~The `Auditable` trait is written and used nowhere.~~ Resolved.** It was worse than I recorded:
PHPStan does not analyse a trait no class uses, so it was not type-checked either. A test fixture
model now applies it against a real table, covering the observer wiring, the column filtering and the
tagging. The trait still has no *production* consumer until Accounting, which remains correct.

**5. Doc comments are long.** I made a deliberate choice to explain *why* at length, because
this codebase will be read by people who were not here. On rereading, some comments restate what
the code says. A future pass should cut those and keep the ones that record a decision.

**6. Repositories are inconsistently used.** `EloquentUserRepository` and
`EloquentTenantRepository` exist, but several controllers query models directly. That is not
wrong — the repository exists for genuinely intricate query construction, which Phase 1 mostly
does not have — but the inconsistency will read as an oversight rather than a choice unless
Accounting establishes the pattern properly.

**7. Unreachable guards — now three, and documented.** `Role::scopeTemplates()` and `isTemplate()`
guard against platform template rows nothing seeds. Running the suite found two more of the same
shape: `CannotArchive::lastActiveCompany` and `UserService::assertNotLastActiveOwner` are both
unreachable through any current path, because a rule above each one always fires first. All three
are correct and cheap, and each is now pinned by a test that asserts what *actually* comes back
rather than implying the guard is live behaviour. See
[PHASE-1-STATUS.md](PHASE-1-STATUS.md#findings-that-are-not-defects).

**8. Speculative attribute reads.** Two audit writers probed conventional attribute names with
`getAttribute()`, which throws under model strictness rather than returning null. The bug is fixed,
but the shape is worth naming: any code that asks "does this model happen to have X?" must use
`ModelAttributes::peek()`. The strictness setting differs between production and everywhere else, so
this class of mistake produces failures that look like environment problems.

## Specification conformance

| Requirement | State |
| --- | --- |
| SOLID, Clean Architecture, DDD, repository + service, event-driven | Followed |
| No placeholder code, no TODOs, no stubs | Held. `NullCompliancePack` and `NoLedgerActivity` are accurate statements of current schema, not stubs |
| Validation, authorization, business logic, error handling, logging | Present on every endpoint |
| Migration, seeder, factory, resources, policies | Present |
| **Tests** | **462 backend, 149 front-end; 85.6 % coverage against an 85 % gate** |
| **Documentation** | ERD, 5 ADRs, architecture, 2 runbooks, OpenAPI, security review, this file |
| Vue 3 + TS + Pinia + Tailwind, dark mode, WCAG | Present. Components tested for roles, labelling and `aria-*` wiring; verified in a real browser. A full audit against assistive technology remains outstanding |

## Recommendation

Phase 1 is complete and verified. Phase 2 may begin.

Two things to carry into it, neither blocking:

1. **`TenantProvisioningService`** remains the class I would most expect to regret — see weakness 3.
   Accounting is where the pressure will show, and ADR 0005 already names the extraction point.
2. **Repository usage is inconsistent** — see weakness 6. Establish the pattern properly in
   Accounting rather than letting the inconsistency spread across a module with genuinely intricate
   queries.

And one habit worth keeping: every defect that mattered was found by executing, not by reading, and
each one hid behind something that looked green. A configuration that aborts the analyser, a test
environment that reads empty tables, a gate registered before yours — all three reported success.
Prefer a check that fails loudly over one that cannot fail.
