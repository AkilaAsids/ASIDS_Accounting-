# Phase 1 — Foundation & Identity Platform: build status

**Last updated:** 2026-08-07
**State:** **Complete and verified.** Every quality gate in `.github/workflows/ci.yml` passes on a
real toolchain against a real PostgreSQL with row level security in force.

| Gate | Result |
| --- | --- |
| Pint | passes |
| PHPStan level 8 (Larastan) | **0 errors** |
| Pest | **462 passing**, 0 skipped, 0 risky |
| Backend coverage | **85.6 %** against a `--min=85` gate |
| `vue-tsc --noEmit` | 0 errors |
| ESLint (`--max-warnings 0`) | 0 problems |
| Vitest | **149 passing** across 7 spec files |
| Front-end coverage | passes per-layer thresholds |
| `vite build` | succeeds |
| `asids:security-check` | passes; RLS confirmed in force as a NOBYPASSRLS role |

---

## Phase 1 scope (agreed)

Repository and container foundation, tenancy core, identity and authentication, authorization,
organization (companies/branches), hierarchical settings, audit trail, API surface, Vue 3 shell,
test suite, documentation.

**Explicitly deferred to Phase 2** (declared, not forgotten): Attachments, Notifications engine,
Approval Workflows. Each depends on business documents that do not exist until the Accounting
phase, and building them now would mean guessing at their consumers.

---

## What running it found

The code was written before any of it could be executed. Making it run surfaced eight defects that
review had not, three of them severe. They are recorded here rather than only in the git history,
because each one is a class of mistake this codebase can make again.

### 1. Suspension did not revoke authority — severe

`spatie/laravel-permission` registers its permission check as a `Gate::before` callback from
`callAfterResolving(Gate::class)`. That fires *while the Gate is being resolved*, so it always
landed at index 0 — ahead of anything `AuthServiceProvider::boot()` could append. Laravel returns
the first non-null result from a before callback, so a user holding a permission through a role was
granted it before the deny-first account-status check ever ran.

The effect: **suspending or deactivating a user revoked nothing.** They kept every capability their
roles granted, and so did any personal access token they had issued. The existing test passed
because it used the *owner*, who is granted by our own callback and therefore reached the deny.

Fixed by setting `permission.register_permission_check_method` to `false` — the flag the package
documents for this purpose — and performing the permission check inside our own callback, after the
account-status check. `PrivilegeEscalationTest` now asserts the behaviour for an ordinary
role-holder and asserts structurally that exactly one before callback is registered, so the ordering
cannot be silently reintroduced by an upgrade.

### 2. Two audit writers threw outside production — severe

`ActivityLogger::describe()` probed conventional attribute names (`name`, `label`, `title`, …) with
`getAttribute()`, and `AuditRecorder` did the same for `tenant_id` and `company_id`. Under
`Model::preventAccessingMissingAttributes()` — enabled everywhere except production — `getAttribute`
**throws** for an attribute the model does not carry rather than returning null.

The effect: role assignment and ownership transfer returned 500 in every developer, CI and staging
environment, and worked in production only because the throw is disabled there. That asymmetry is
worse than a plain failure, because it presents as an environment problem.

Fixed with `Platform\Support\ModelAttributes::peek()`, which answers the speculative question
honestly, and both writers now use it.

### 3. The test suite was reading empty tables — severe

`phpunit.xml` set `TENANCY_ENFORCE_RLS=false` while the test database had the RLS policies applied.
With policies present but enforcement off, nothing publishes `asids.tenant_id`, every policy
evaluates against NULL, and every tenant-scoped read returns zero rows **with no error**. 36 tests
failed and the rest were asserting against emptiness. The two settings must agree; enforcement is
now on in the test environment, and `asids:security-check` fails hard on the mismatch in every
environment.

### 4. PHPStan had never run

`phpstan.neon` declared `checkMissingIterableValueType` and `checkGenericClassInNonGenericObjectType`,
both removed in PHPStan 2.x. That is not an ignored setting — it aborts the run. The gate had been
green in the sense that it had never executed. With the config repaired it reported 441 errors,
since resolved to zero; the substantial ones were incomplete `@property` blocks on every model,
which is why `$company->registration_number` had no type anywhere in the codebase.

### 5. A formatter-induced build failure

`vue-tsc` could not parse `UsersPage.vue` because of `as` assertions inside template interpolations,
which also hid seven further type errors behind the parse failure. Separately, running Prettier for
the first time rewrapped a two-statement inline `@click` handler onto multiple lines, and Vue's
template compiler parses an inline handler as a single expression — so the formatter broke the
production build. Both are fixed, and the handler is now a named method.

### 6. ESLint fought Prettier

385 of the 400 lint problems were `eslint-plugin-vue` formatting rules disagreeing with the
Prettier configuration over the same files. The stylistic rules Prettier owns are now off; nothing
that checks *behaviour* was disabled.

### 7. The coverage gate would have failed CI on memory, not on coverage

`pest --coverage` passes every test and then dies with a fatal error while building the report at
PHP's default 128 MB limit. The CI step now runs with `-d memory_limit=2G`.

### 8. `v-html` on the enrolment QR code

Not exploitable — the SVG is generated server-side from a QR renderer that emits only paths — but
`v-html` executes whatever it is given. It is now delivered as a `data:` URI through `<img>`, which
cannot execute script under any circumstances, and gained an accessible name in the process.

---

## Test suite

**462 backend tests, 1,306 assertions.** No skipped tests: the two that previously skipped are now
executed — the cache-isolation test runs against the `database` store, which honours `cache.prefix`
exactly as Redis does, and asserts both that one workspace cannot read another's value *and* that
the owning workspace can read its own, so it cannot pass against a cache that stores nothing.

| Area | Files | Covers |
| --- | --- | --- |
| Tenancy | `TenantIsolationTest`, `RowLevelSecurityTest`, `WorkspaceRegistrationTest`, `TenantCustomColumnsTest` | Scoping, fail-closed reads, write guarding, RLS against raw SQL as a NOBYPASSRLS role, public sign-up as one transaction, hostname resolution, the `data`-column overflow |
| Identity | `AuthenticationTest`, `UserAdministrationTest`, `SelfServiceTest`, `TwoFactorLifecycleTest`, `AccountLinkTest`, `PasswordPolicyTest` | Enumeration defences, lockout, the administration HTTP surface, seat accounting, 2FA enrolment and step-up, single-use links, password reuse and expiry |
| Authorization | `PrivilegeEscalationTest`, `RoleManagementTest` | Owner-role refusal, level ordering, last-owner protection, clamping, the suspension regression, role CRUD and assignment over HTTP |
| Organization | `CompanyLifecycleTest`, `BranchAndMembershipTest`, `OrganizationApiTest`, `ActiveCompanyResolutionTest` | Accounting-configuration immutability, the single-default and single-primary invariants, membership reinstatement, company scoping |
| Settings | `SettingsResolutionTest`, `SettingsApiTest` | Four-level resolution, scope refusal, coercion, cache invalidation, the public subset |
| Audit | `AuditChainIntegrityTest`, `AuditableTraitTest`, `AuditApiAndCommandsTest` | Append-only trigger including TRUNCATE, sealing and tamper detection, the `Auditable` trait, the console commands |
| Platform | `PlatformHardeningTest`, `QueryCriteriaTest` | Response envelope, problem documents, rate limiting, the sort/filter/include allow-list |

**149 front-end tests** across the API client (step-up replay, RFC 9457 mapping, the CSRF handshake
and its `withXSRFToken` requirement), both stores, router guard ordering, and every UI and app
component.

### The `Auditable` trait is now exercised

No Phase 1 model applies it — this phase's security-relevant changes are captured by the eleven
domain-event listeners instead, and the trait is for the business documents Accounting brings. That
left it shipped but unexercised, and PHPStan does not analyse a trait no class uses, so it was not
type-checked either. `tests/Support/Fixtures/AuditedRecord.php` is a real model on a real table that
applies it, closing both gaps.

---

## Coverage

**85.6 %** of `app/` and `src/`, against a gate of 85. The uncovered remainder is concentrated in
the places where a test would assert the framework rather than the application: service-provider
registration bodies, enum label methods, and exception factories reached only on paths a test cannot
provoke without fabricating an impossible state.

Front-end thresholds are **per layer** rather than one average, deliberately. A single global number
lets thirteen page components' worth of markup dominate the figure and hide a regression in
`api/client.ts`. The logic layers hold at 90–100 %; `pages/` and `app/` are declared at zero, stated
openly in `vite.config.ts` rather than excluded from the report, with the reasoning recorded there.

---

## Findings that are not defects

Two guards turn out to be unreachable through any current path. Both are cheap, both are correct,
and both are now documented by the tests that probe them rather than left to look like live
behaviour:

- **`CannotArchive::lastActiveCompany`.** `create()` makes the first company of a workspace the
  default whether or not the caller asked, `makeDefault()` keeps exactly one, and `archive()`
  refuses the default — so the only company that could ever be the sole active one is always the
  default, and the check above it fires first.
- **`UserService::assertNotLastActiveOwner`.** The policy requires an actor to *outrank* the target
  strictly, and nobody outranks an owner, so no HTTP caller reaches it. It is exercised directly, as
  the backstop it is for console commands and future endpoints.

A third is a deliberate trade-off worth stating plainly: **step-up authentication protects only
users who have enrolled a second factor.** The middleware cannot demand a code from someone who has
never set one up, and refusing outright would make ownership transfer unreachable for exactly the
workspaces most likely to need it. Without workspace-level enforcement, the permission check is the
only control on those routes.

---

## Settled assumptions

The seven items previously listed as unverified are closed:

1. `PermissionTeamBootstrapper` against spatie 6.25 — exercised by every tenant-scoped role test.
2. `Builder::macro('applyFilter')` under PHPStan level 8 — analysed, typed `Builder<Model>`.
3. `TwoFactorService::verifyTotp` against google2fa 8.0.3 — the named arguments match the installed
   signature, and enrolment, verification and replay resistance are covered end to end.
4. The `audit_logs` seal trigger — a legitimate seal UPDATE passes and every other UPDATE is
   refused, verified against real PL/pgSQL.
5. `Tenant::getCustomColumns()` — `TenantCustomColumnsTest` asserts the list matches the table
   exactly, and that routing to the column and to `data` both work. This one was genuinely
   unverified until now: a real column missing from the list is written into `data` instead, reads
   back correctly through the model, and silently disappears from every query that filters on it.
6. `useId()` in `TextField.vue` — Vue 3.5 is installed; the component renders and is covered.
7. `withXSRFToken` in `api/client.ts` — asserted in `client.spec.ts`, and the whole cookie flow was
   driven through a real browser against the running application.

---

## Running it

```bash
brew services start postgresql@17 && brew services start redis
```

```bash
composer install && npm ci && cp .env.example .env && php artisan key:generate
```

```bash
php artisan migrate --seed && php artisan asids:security-check
```

Quality gates, in the order CI runs them:

```bash
vendor/bin/pint --test && vendor/bin/phpstan analyse && php -d memory_limit=2G vendor/bin/pest --coverage --min=85
```

```bash
npm run typecheck && npm run lint && npm run test:coverage && npm run build
```

`pcov` is required for the coverage gate. On this machine it needed the pcre2 headers:

```bash
CPPFLAGS="-I/opt/homebrew/include" pecl install pcov
```

---

## Phase 2 readiness

Phase 1 is complete. The seams Accounting will need are in place and tested: `LedgerActivityProbe`
for the immutability rules, the `Auditable` trait with a working fixture, `CompliancePackContract`
for the Sri Lankan tax module, and a permission catalogue that new modules extend by declaration
rather than by migration.

Two things to carry forward. `TenantProvisioningService` still does five things in one transaction
and is the one class depending outward on three modules — ADR 0005 predicts its extraction point,
and Accounting is where that pressure will show. And the repositories remain inconsistently used:
`EloquentUserRepository` and `EloquentTenantRepository` exist while several controllers query models
directly. That is defensible for a phase with little intricate query construction, but Accounting
should establish the pattern properly rather than let the inconsistency spread.
