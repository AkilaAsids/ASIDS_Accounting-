# Phase 1 code review

A review of my own work, conducted by reading it back. Recorded because the specification asks
for one at the end of every phase, and because the honest findings are more useful than a
clean bill of health.

## Method and its limits

No PHP, Composer, Node or Docker exists on the machine this was written on. The review is
therefore: reading every file, plus a static cross-reference (PSR-4 correctness, unresolved
internal references, route-handler existence, front-end import resolution).

```
Declared classes: 221      PSR-4: correct for every file
Route handlers: 65         all resolve
Internal references: 0 unresolved
Front end: 95 imports, 13 lazy routes — all resolve
```

That establishes the tree will autoload. It establishes nothing about behaviour.

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

**1. Nothing has run.** This dominates every other finding. 226 backend classes and 35
front-end files are written against APIs I could not check. Seven specific unverified
assumptions are listed in [PHASE-1-STATUS.md](PHASE-1-STATUS.md); there are certainly others I
did not think to doubt.

**2. No tests.** Every security claim in [SECURITY-REVIEW.md](SECURITY-REVIEW.md) is a design
claim. I recommended deferring the suite rather than writing tests I cannot run, and I stand by
that — but the consequence is that Phase 1 is not complete and should not be treated as such.

**3. `TenantProvisioningService` is doing a lot.** Five concerns in one transaction, and it is
the one class that depends outward on three other modules. ADR 0005 justifies it and predicts
the extraction point. I think that is right, but it is the class I would most expect to regret.

**4. The `Auditable` trait is written and used nowhere.** No Phase 1 model applies it — the
security-relevant changes are captured by the eleven event listeners instead. That is defensible
(the trait is for business documents, which arrive with Accounting) but it means the trait and
its observer are entirely unexercised, including by reading.

**5. Doc comments are long.** I made a deliberate choice to explain *why* at length, because
this codebase will be read by people who were not here. On rereading, some comments restate what
the code says. A future pass should cut those and keep the ones that record a decision.

**6. Repositories are inconsistently used.** `EloquentUserRepository` and
`EloquentTenantRepository` exist, but several controllers query models directly. That is not
wrong — the repository exists for genuinely intricate query construction, which Phase 1 mostly
does not have — but the inconsistency will read as an oversight rather than a choice unless
Accounting establishes the pattern properly.

**7. One dead scope.** `Role::scopeTemplates()` and `isTemplate()` guard against platform
template rows that nothing currently seeds. The guards are correct and cheap, but they protect
against a state that cannot yet occur.

## Specification conformance

| Requirement | State |
| --- | --- |
| SOLID, Clean Architecture, DDD, repository + service, event-driven | Followed |
| No placeholder code, no TODOs, no stubs | Held. `NullCompliancePack` and `NoLedgerActivity` are accurate statements of current schema, not stubs |
| Validation, authorization, business logic, error handling, logging | Present on every endpoint |
| Migration, seeder, factory, resources, policies | Present |
| **Tests** | **Absent** |
| **Documentation** | ERD, 5 ADRs, architecture, 2 runbooks, security review, this file |
| Vue 3 + TS + Pinia + Tailwind, dark mode, WCAG | Present; accessibility unverified without a browser |

## Recommendation

Do not start Phase 2. In order:

1. Install PHP 8.4, Composer, Node 22, Docker.
2. `composer install && migrate --seed && asids:security-check`. Fix what the seven unverified
   assumptions turn up.
3. Write the test suite — tenant isolation under real RLS first, then auth and lockout, then
   RBAC escalation refusal, then the audit chain.
4. Only then call Phase 1 complete.

Phase 1 is roughly 85% built and 0% verified. The second number is the one that matters.
