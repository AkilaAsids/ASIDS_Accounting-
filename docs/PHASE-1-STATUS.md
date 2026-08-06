# Phase 1 — Foundation & Identity Platform: build status

**Last updated:** 2026-08-06 (74 of 78 feature tests green; 13 real bugs found by running them)
**State:** The backend is structurally complete — every internal class reference resolves
and nothing is missing. It has still never been executed: no PHP, Composer, Node or
Docker toolchain exists on the machine it was written on. Remaining Phase 1 work is the
Vue front end, the test suite, and the ERD/OpenAPI/deployment documents.

---

## Phase 1 scope (agreed)

Repository and container foundation, tenancy core, identity and authentication,
authorization, organization (companies/branches), hierarchical settings, audit trail,
API surface, Vue 3 shell, test suite, documentation.

**Explicitly deferred to Phase 2** (declared, not forgotten): Attachments,
Notifications engine, Approval Workflows. Each depends on business documents that do
not exist until the Accounting phase, and building them now would mean guessing at
their consumers.

---

## Complete

### Repository & tooling
- `composer.json`, `package.json` with pinned, current dependency sets
- `artisan`, `public/index.php`, `bootstrap/app.php`, `bootstrap/providers.php`
- Pint (strict types enforced), PHPStan level 8 via Larastan, PHPUnit/Pest config
- Vite + TypeScript (strict, `noUncheckedIndexedAccess`) + Tailwind design tokens
- ESLint flat config, Prettier, `.editorconfig`, `.gitattributes`

### Containers & CI
- `docker-compose.yml`: app, nginx, PostgreSQL 17, Redis 7.4, Meilisearch, Horizon,
  scheduler, Vite, Mailpit — with healthchecks and tuned Postgres parameters
- Five-stage `docker/php/Dockerfile` (base → development → vendor → assets → production),
  non-root, OPcache/JIT tuned, preloading enabled only in immutable images
- `docker/php/entrypoint.sh`: fail-fast config checks, dependency readiness waits,
  deliberately **does not** run migrations (avoids the multi-replica race)
- `docker/postgres/init/01-bootstrap.sh`: creates the `NOBYPASSRLS` application role,
  extensions, grants, default privileges, and the test database
- `.github/workflows/ci.yml`: static analysis, Pest with a real Postgres + Redis and
  RLS provisioned to match production, front-end typecheck/lint/test/build, dependency
  advisory audit, single required status check

### Configuration
`config/asids.php` (platform policy: tenancy, password policy, 2FA, audit, rate limits,
regional defaults, limits), `database.php`, `tenancy.php`, `permission.php`,
`sanctum.php`, `horizon.php`, `logging.php`, `filesystems.php`, `cors.php`.
See ADR 0004 for why the other framework config files are intentionally absent.

### Database schema — all Phase 1 migrations
| Migration | Contents |
| --- | --- |
| `2026_01_01_000001` | cache, jobs, job_batches, failed_jobs, sessions, notifications (no `password_reset_tokens` — see AccountLinkService) |
| `2026_01_02_000001/2` | tenants, domains |
| `2026_01_03_000001–6` | users, two_factor_recovery_codes, password_histories, login_histories, user_devices, personal_access_tokens |
| `2026_01_04_000001` | permissions, roles, model_has_permissions, model_has_roles, role_has_permissions |
| `2026_01_05_000001–3` | companies, branches, company_memberships |
| `2026_01_06_000001` | settings |
| `2026_01_07_000001/2` | audit_logs (append-only trigger, seal-only UPDATE path, hash chain), activity_logs |
| `2026_01_08_000001` | row level security policies, FORCED, on 16 tables |

Schema highlights: UUID v7 keys throughout, `timestamptz` everywhere, partial and
expression unique indexes for case-insensitive and single-row-per-parent invariants,
trigram GIN indexes for pickers, `jsonb_path_ops` GIN for audit search, and check
constraints for every enumerated column and every cross-column invariant
(`(tenant_id IS NULL) = is_platform_admin`, SVAT implies VAT, archived implies
`archived_at`, primary branch must be active, and others).

### Platform kernel — `src/Core/Platform`
`ModuleServiceProvider`, `PlatformServiceProvider` (model strictness, morph map,
password defaults, query monitoring), `RequestContext`, `AssignRequestId`,
`ForceJsonResponse`, `ApiResponse` envelope, `ApiExceptionRenderer` (RFC 9457 problem
documents, no internal detail leakage), `PlatformException`/`BusinessRuleViolation`/
`ResourceConflict`, `QueryCriteria` (allow-listed sort/filter/include),
`EloquentRepository`, `ApiController`, `CompliancePackContract` + `NullCompliancePack`,
`TenantContextProcessor` + `AddTenantContext` log scrubber.

### App providers
`AppServiceProvider`, `AuthServiceProvider` (owner-role `Gate::before`, inactive-account
`Gate::after`), `EventServiceProvider`, `RouteServiceProvider` (five rate limiters keyed
by tenant + principal, UUID route pattern).

### Tenancy module — complete
`Tenant`, `Domain`, `TenantStatus`, `TenantContext`, `TenantResolver` (hostname and
`X-Tenant` header, cached with observer-driven invalidation), `TenantScope` (fails
closed), `BelongsToTenant` (auto-stamp + cross-tenant write guard),
`RowLevelSecurity` (scoped bypass + enforcement probe), four bootstrappers
(RLS, cache, filesystem, queue), `ResolveTenant` middleware, `TenantProvisioningService`
(atomic five-part provisioning), `TenantObserver`, repository + contract,
`TenantProvisioned` event, four domain exceptions, sign-up controller/request/resource,
`TenancyServiceProvider`.

### Identity module — complete
`UserStatus`, `LoginOutcome`, `User`, `UserDevice`, `LoginHistory`,
`PasswordHistory`, `TwoFactorRecoveryCode`, `PersonalAccessToken` (with CIDR
restriction), seven domain exceptions, `CreateUserData`, `PasswordPolicyService`,
`TwoFactorService` (two-phase TOTP enrolment, replay-resistant verification, atomic
recovery-code consumption), `DeviceService`, `AuthenticationService` (two-step sign-in
with cache-backed challenge, timing equalisation, account lockout).

Plus, this workstream: `UserService` (invite/accept/reset/suspend/reinstate/deactivate,
seat accounting, last-active-owner protection), `AccountLinkService` (signed
invitation and reset links, single-use via credential fingerprint — no token table),
`AccessTokenService` (abilities intersected with the creator's own permissions),
`UserRepositoryContract` + `EloquentUserRepository`, 7 domain events,
`EnsureTwoFactorIsConfirmed` (step-up + workspace enforcement),
`EnsureSessionIsCurrent` (epoch-based revocation + idle timeout), `UserPolicy`,
`AccessTokenPolicy`, 8 controllers, 10 form requests, 4 resources,
3 notifications, `IdentityServiceProvider`.

### Authorization module — complete
`Permission` and `Role` models, `HasTenantRoles` (memoised `isTenantOwner()`,
`highestRoleLevel()`, `canGrantRole()`), `PermissionDefinition` +
`PermissionCatalogue` (44 capabilities across 6 modules), `RoleTemplate` (5 system
roles), `PermissionSynchroniser`, `RoleProvisioner` (provision + release-time
refresh), `RoleService` (level ordering, owner protection, last-owner protection,
platform-capability refusal, ownership transfer), `PermissionTeamBootstrapper`
(the spatie teams wiring), 5 domain exceptions, 2 domain events, `RolePolicy`,
`PermissionPolicy`, `EnsurePasswordIsNotExpired`, `RoleController`,
`PermissionController`, 3 form requests, 2 resources,
`asids:sync-permissions` command, `AuthorizationServiceProvider`.

### Organization module — complete
`OrganizationStatus` enum, `Company` (fiscal-calendar arithmetic, membership-scoped
`accessibleBy`), `Branch`, `CompanyMembership`, `LedgerActivityProbe` seam +
`NoLedgerActivity`, `CreateCompanyData`, `CompanyService` (atomic company + primary
branch + creator membership; currency/fiscal immutability; unique code/slug
derivation), `BranchService` (single-active-primary invariant), `MembershipService`
(reinstate-not-duplicate, default promotion on revoke), 5 domain exceptions,
4 domain events, `ResolveActiveCompany` middleware, `CompanyPolicy`, `BranchPolicy`,
`CompanyMembershipPolicy`, 3 controllers, 5 form requests, 3 resources,
`OrganizationServiceProvider`.

### API surface, scheduling, seeders — complete
`routes/api.php` (57 endpoints under `api.v1.*`; three middleware layers; step-up
protection on the six credential-bearing routes), `routes/web.php` (SPA catch-all),
`routes/console.php` (four scheduled sweeps, all `onOneServer`),
`resources/views/app.blade.php` (flash-free dark mode from a cookie),
`RevokeExpiredTokensCommand`, `SecurityCheckCommand` (six deployment assertions,
fails the release if RLS is not actually in force), `DatabaseSeeder`,
`PermissionSeeder`, `DemoWorkspaceSeeder` (built by calling the real provisioning
services, so `migrate --seed` is itself an end-to-end integration check),
`TenantFactory`, `UserFactory`, `CompanyFactory`, `BranchFactory`.

### Documentation
ADR 0001 (tenancy strategy), ADR 0002 (tenant/company/branch hierarchy),
ADR 0003 (permissions in code, roles in data), ADR 0004 (minimal config surface),
ADR 0005 (provisioning ownership), README, this file.

---

## Audit module — complete

`AuditEvent` (17 events) and `ActorType` enums, `AuditLog` (write-closed model, canonical
payload, hash computation), `ActivityLog`, `Auditable` trait + `AuditableObserver`
(past-tense hooks only), `AuditRecorder` (synchronous, in-transaction, lock-free,
credential-redacting, never fails a business operation), `AuditChainSealer` (advisory-locked
batch sealing + two-mode verification), `ActivityLogger` (with batching),
`RecordRequestContext` middleware, `AuditLogPolicy` (no write methods by construction),
`ActivityLogPolicy`, 2 controllers, 2 resources, `asids:audit-seal`,
`asids:audit-verify`, `asids:audit-prune`, `AuditServiceProvider` (11 domain-event
listeners covering the privilege and credential changes no model observer can see).

## Settings module — complete

`SettingScope` (4 levels + resolution order) and `SettingType` (10 types with coercion
and validation rules) enums, `SettingDefinition`, `SettingsCatalogue` (13 settings across
localisation, security, branding, notifications — nothing speculative), `Setting` model,
`SettingsResolver` (four-level resolution, per-scope cache + per-request memoisation,
targeted invalidation), `SettingsService` (scope-permission enforcement, atomic group
writes, reset-to-inherit, orphan purge), `SettingPolicy`, `SettingsController`
(server-driven form metadata), `UpdateSettingsRequest`, `SettingResource`,
`SettingsServiceProvider`.

---

## Vue 3 front end — complete

35 files. `styles/app.css` (design tokens as RGB triplets for runtime re-theming; dark
mode as a token swap; `prefers-reduced-motion` honoured globally), `types/api.ts` +
`types/domain.ts` (full wire contracts, no `any`), `api/client.ts` (cookie auth, CSRF
handshake memoised on the promise, RFC 9457 → typed `ApiError`, **automatic step-up replay**,
419 re-handshake), `stores/auth.ts` (session, two-step sign-in, permission checks),
`stores/ui.ts` (cookie-backed theme, notices), `router/index.ts` (13 lazy routes; guard
ordering: auth → interstitials → permission), `app/main.ts` + `App.vue`, `AppLayout` +
`AuthLayout`, 4 UI components (`BaseButton`, `TextField`, `AlertBanner`, `SurfaceCard`),
4 app components (`PermissionGate`, `StepUpDialog`, `NoticeStack`, `ThemeToggle`,
`CompanySwitcher`), 4 auth pages (sign-in, 2FA challenge with recovery-code path,
forgot-password, account-link), dashboard, users, roles (server-driven permission matrix),
settings (server-driven form metadata), security (2FA enrolment, devices, sign-in history),
change-password, 403, 404, `useFormat` (company currency and timezone — never the
browser's), `locales/en.ts`, `tests/Support/vitest.setup.ts`.

## Documentation — complete

| Document | Contents |
| --- | --- |
| [`database/erd.md`](database/erd.md) | Five Mermaid diagrams covering every Phase 1 table, plus the RLS coverage matrix, the index strategy, and the two session variables that are the only exceptions to the audit trail's append-only trigger |
| [`api/openapi.yaml`](api/openapi.yaml) | OpenAPI 3.1 — 54 paths, 69 operations, 24 schemas. Documents the *reasoning* a consumer needs, not just the shapes |
| [`architecture/overview.md`](architecture/overview.md) | Module map and dependency rule, request lifecycle with the two load-bearing orderings, isolation layers, extensibility seams |
| [`deployment/local.md`](deployment/local.md) | First run, demo accounts, quality gates, six troubleshooting entries |
| [`deployment/aws.md`](deployment/aws.md) | Topology, **the five things that will bite you**, release sequence, backup drill, alarm priorities |
| [`SECURITY-REVIEW.md`](SECURITY-REVIEW.md) | OWASP Top 10 treatment with every control marked verified or **UNVERIFIED**; nine residual risks with owners; explicitly **not signed off** |
| [`PHASE-1-CODE-REVIEW.md`](PHASE-1-CODE-REVIEW.md) | The ten bugs found while building, five strengths, seven weaknesses I judge to remain |
| ADRs [0001](adr/0001-tenancy-strategy.md)–[0005](adr/0005-workspace-provisioning-ownership.md) | Tenancy strategy, hierarchy, permissions-in-code, config surface, provisioning ownership |

## Static verification (no PHP available)

A cross-reference over the whole tree, re-run after each workstream:

```
Declared classes: 221
PSR-4: every file matches its namespace and class name.
Route handlers: 65 checked — all resolve.
Every Asids\ class reference resolves — nothing missing.
Policies: 10, covering 48 authorisation methods.

Front end (35 files):
  Internal imports checked: 95 — every @/ import resolves.
  Lazy route components: 13 — all resolve.

OpenAPI (2,603 lines):
  No tabs. 69 operations, all operationIds unique. 41 $refs, all targets declared.
  Not validated against a real OpenAPI parser — PyYAML is not installed either.
```

This proves the tree is internally consistent and will autoload. It does **not** prove it
runs: nothing has been type-checked against the real Laravel 12, spatie 6 or Vue 3.5 APIs,
`vue-tsc` has never run, no migration has been applied, and no test has been executed.
Node is absent too, so the front end has never been built or rendered.

---

## Remaining Phase 1 work

### Test suite — first tranche written

| File | Cases | Covers |
| --- | --- | --- |
| `tests/Pest.php` | — | `toBeProblem`, `toBeEnvelope`, `toNotLeak` expectations; `catchPlatformException` |
| `tests/TestCase.php` | — | Permission catalogue synchronised per test via the real synchroniser; setup under an RLS bypass |
| `tests/Support/InteractsWithTenants.php` | — | Workspaces built through the real provisioning services, not fabricated rows |
| `Feature/Tenancy/TenantIsolationTest` | 19 | Read scoping, fail-closed behaviour, write guarding, escape hatches, cache isolation |
| `Feature/Tenancy/RowLevelSecurityTest` | 11 | Raw SQL, `withoutGlobalScopes()`, raw insert/update/delete — **skips loudly** if policies are not in force |
| `Feature/Authorization/PrivilegeEscalationTest` | 16 | Owner-role refusal, level ordering, last-owner protection, clamping, the owner short circuit |
| `Feature/Identity/AuthenticationTest` | 16 | Identical answers for unknown vs wrong password, cross-workspace credential refusal, lockout, challenge issuance |
| `Feature/Audit/AuditChainIntegrityTest` | 15 | Append-only trigger incl. TRUNCATE, sealing, idempotency, hash-mismatch vs broken-link detection, redaction |

**77 test cases.** Still to write: Organization (company/branch invariants, membership),
Settings (four-level resolution, scope refusal), the Identity HTTP surface end to end, unit
tests for the services, and the Vitest suite for the front end.

## Test suite status

| Tranche | Result |
| --- | --- |
| `Feature/Tenancy` (32) | **all pass** — verified against real FORCED RLS as a NOBYPASSRLS role |
| `Feature/Authorization` (16) | **all pass** |
| `Feature/Audit` (14) | **all pass** — append-only trigger, seal path and tamper detection all verified |
| `Feature/Identity` (16) | 12 pass, **4 fail** |

The four Identity failures are diagnosed and are test-side, not application-side:

1. `toNotLeak('password')` matches the *key* `requires.password_change`, not a leaked value.
   The assertion needs to target the hash and secret values, not the word.
2. + 3. Two lockout tests: the account is not locked after `max_attempts` failures. Needs
   confirming whether `registerFailure` is reached on every attempt, or whether the route
   limiter absorbs some. **This is the one that could still be an application bug** and should
   be settled before the suite is called green.
4. `LoginHistory::value('outcome')` returns a cast enum, not the raw string the test compares
   against.

## Operational hazard found by running the suite

`TENANCY_ENFORCE_RLS=false` against a database whose policies **do** exist is far worse than
either state alone: the policies constrain every query, nothing publishes a tenant for them to
match, and the application reads **empty result sets everywhere with no error**. It presents as
"all my data has vanished".

`asids:security-check` now fails hard on this mismatch in every environment. The two settings
must agree: enforcement on, or the RLS migration rolled back.

## First things to check once PHP exists

These are the specific places where I could not verify an external API by reading:

1. `PermissionTeamBootstrapper` — `setPermissionsTeamId()` and `forgetInstance()` against
   the installed spatie/laravel-permission 6.x.
2. `Builder::macro('applyFilter')` under PHPStan level 8 — six controllers depend on it.
3. `TwoFactorService::verifyTotp` — `verifyKeyNewer()` named arguments against
   pragmarx/google2fa 8.x.
4. The `audit_logs` seal trigger — that a legitimate seal UPDATE passes and any other
   UPDATE is refused. This is the one piece of PL/pgSQL in the platform.
5. `Tenant::getCustomColumns()` — that stancl/tenancy's data-column overflow does not
   swallow a real column.
6. `useId()` in `TextField.vue` — Vue 3.5+ only. If the installed Vue is older, swap for a
   module-scoped counter.
7. `withXSRFToken: true` in `api/client.ts` — axios 1.7+ only; without it the Sanctum
   cookie flow silently fails CSRF on every write.

---

## Blocker: no toolchain on this machine

`php`, `composer`, `node`, `npm` and `docker` are all absent, and Homebrew is not
installed. **No command in this repository has been run** — not `composer install`, not
`php artisan migrate`, not `pest`. The code is written against Laravel 12 / PHP 8.4
APIs and reviewed by reading, but it is unverified.

To make it runnable:

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

```bash
brew install php@8.4 composer node@22 && brew install --cask docker
```

Then, once the remaining classes above exist:

```bash
cp .env.example .env && composer install && npm ci && php artisan key:generate
```

```bash
docker compose up -d postgres redis && php artisan migrate --seed
```

## Suggested order for the next session

1. ~~`Authorization` module~~ — done.
2. ~~`Organization` models + `CompanyService`~~ — done.
3. ~~`UserService` + Identity HTTP layer~~ — done. All five core modules are now
   internally consistent; the remaining gaps are the two leaf modules and the
   routes that expose everything.
4. ~~Routes, seeders, factories~~ — done.
5. ~~`Audit` + `Settings`~~ — done. The backend is structurally complete.
6. **Install the toolchain and run it.** `composer install`, `migrate --seed`,
   `asids:security-check`. Fix whatever the five items above turn up.
7. Test suite, then the Vue front end.
8. ERD, OpenAPI, deployment runbook, code review record.
