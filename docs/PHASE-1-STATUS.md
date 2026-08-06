# Phase 1 — Foundation & Identity Platform: build status

**Last updated:** 2026-08-06 (Authorization workstream complete)
**State:** In progress. The tree does **not** yet install, boot or pass tests — see
"Not yet written" below. Nothing in this repository has been executed.

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
| `2026_01_01_000001` | cache, jobs, job_batches, failed_jobs, sessions, password_reset_tokens, notifications |
| `2026_01_02_000001/2` | tenants, domains |
| `2026_01_03_000001–6` | users, two_factor_recovery_codes, password_histories, login_histories, user_devices, personal_access_tokens |
| `2026_01_04_000001` | permissions, roles, model_has_permissions, model_has_roles, role_has_permissions |
| `2026_01_05_000001–3` | companies, branches, company_memberships |
| `2026_01_06_000001` | settings |
| `2026_01_07_000001/2` | audit_logs (append-only trigger + hash chain), activity_logs |
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

### Identity module — partial (see below)
Complete: `UserStatus`, `LoginOutcome`, `User`, `UserDevice`, `LoginHistory`,
`PasswordHistory`, `TwoFactorRecoveryCode`, `PersonalAccessToken` (with CIDR
restriction), seven domain exceptions, `CreateUserData`, `PasswordPolicyService`,
`TwoFactorService` (two-phase TOTP enrolment, replay-resistant verification, atomic
recovery-code consumption), `DeviceService`, `AuthenticationService` (two-step sign-in
with cache-backed challenge, timing equalisation, account lockout).

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

### Documentation
ADR 0001 (tenancy strategy), ADR 0002 (tenant/company/branch hierarchy),
ADR 0003 (permissions in code, roles in data), ADR 0004 (minimal config surface),
ADR 0005 (provisioning ownership), README, this file.

---

## Not yet written

The tree contains forward references to these classes, so `composer dump-autoload`
will succeed but the application will not boot until they exist.

### Identity — remaining
- `UserService` (create/invite/activate/suspend/deactivate, seat-limit enforcement,
  `setDefaultCompany`) — referenced by `TenantProvisioningService`
- `AccessTokenService`; `UserRepositoryContract` + `EloquentUserRepository`
- Controllers: `AuthenticationController`, `TwoFactorController`, `PasswordController`,
  `ProfileController`, `UserController`, `DeviceController`, `LoginHistoryController`,
  `AccessTokenController`
- Form requests and resources for each of the above
- `EnsureTwoFactorIsConfirmed` middleware — referenced by `bootstrap/app.php`
- `UserPolicy`; domain events + listeners; `IdentityServiceProvider`

### Organization — models/services not started
Migrations are complete. Still needed: `Company`, `Branch`, `CompanyMembership` models;
`CreateCompanyData`; `CompanyService` (referenced by `TenantProvisioningService`),
`BranchService`, `MembershipService`; repositories; `ResolveActiveCompany` middleware
(referenced by `bootstrap/app.php`); controllers, requests, resources, policies;
`OrganizationServiceProvider`.

### Settings — not started
`Setting` model, `SettingDefinition` + `SettingsRegistry` (code-defined catalogue),
`SettingsResolver` (user → company → tenant → system → default, Redis cached),
`SettingsService`, controllers, `SettingsServiceProvider`.

### Audit — not started
`AuditLog`, `ActivityLog` models; `AuditRecorder` (hash chain with per-tenant advisory
lock), `Auditable` trait/observer, `ActivityLogger`; `RecordRequestContext` middleware
(referenced by `bootstrap/app.php`); `asids:audit-verify` and `asids:audit-prune`
commands; controllers; `AuditServiceProvider`.

### Cross-cutting — not started
- `routes/api.php`, `routes/web.php`, `routes/console.php`
- Seeders: `PermissionSeeder`, `RoleTemplateSeeder`, `DemoTenantSeeder`
- Factories: `TenantFactory`, `UserFactory`, `CompanyFactory`, `BranchFactory`
- `asids:security-check` command
- Vue 3 front end: API client, Pinia stores, router with guards, app shell (dark mode,
  company switcher), design-system components, auth pages (sign-in, 2FA challenge,
  password reset), users/roles/settings/security pages, i18n scaffold
- Test suite: `tests/Pest.php`, tenant-aware `TestCase`, feature tests (tenant
  isolation incl. a real RLS test, auth + lockout + 2FA, RBAC, companies, settings,
  audit chain integrity), unit tests, Vitest tests
- `docs/`: ERD (Mermaid), OpenAPI 3.1 spec, architecture overview, local + AWS
  deployment runbooks, security review, Phase 1 code review record

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
2. `Organization` models + `CompanyService` (unblocks provisioning).
3. `UserService` + Identity HTTP layer (completes the sign-in path end to end).
4. `Audit` + `Settings` (both are leaf modules; nothing depends on them).
5. Routes, seeders, factories — at which point `php artisan migrate --seed` should run.
6. Test suite, then the Vue front end.
7. ERD, OpenAPI, deployment runbook, code review record.
