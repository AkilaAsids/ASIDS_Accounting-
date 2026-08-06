# Architecture overview

ASIDS ERP Cloud is a **modular monolith**: one deployable, one database, seven bounded
contexts with enforced boundaries. This document explains the shape and the reasoning; the
individually contentious decisions have their own ADRs.

## Why a modular monolith and not services

An accounting system is a single consistency boundary. A journal entry, its lines, the
document that produced it and the audit record of who posted it must commit or fail together.
Splitting them across services means either distributed transactions or eventual consistency —
and "eventually consistent double-entry bookkeeping" is a contradiction: a trial balance that
does not balance is not a temporary state, it is a defect.

The monolith is *modular* so that a later extraction is possible where it makes sense.
Candidates that are genuinely independent — search indexing, PDF rendering, the AI assistant,
e-invoicing transmission — are already asynchronous and could move behind a queue without
touching the ledger.

## Module map

```
src/Core/
├── Platform/       Shared kernel. Depends on nothing.
├── Tenancy/        Tenant, hostname resolution, scope, RLS, provisioning
├── Identity/       Users, authentication, 2FA, devices, access tokens
├── Authorization/  Roles, permission catalogue, policies
├── Organization/   Companies, branches, company memberships
├── Settings/       Hierarchical typed settings
└── Audit/          Append-only hash-chained trail, activity feed
```

Each module owns four layers:

| Layer | Contains | May depend on |
| --- | --- | --- |
| `Domain/` | Models, enums, value objects, events, exceptions, contracts | Its own domain; Tenancy's domain; Platform |
| `Application/` | Services, DTOs — the use cases | Own domain, own infrastructure contracts |
| `Infrastructure/` | Eloquent repositories, bootstrappers, observers | Own domain |
| `Presentation/` | Controllers, requests, resources, middleware, console | Own application layer |

**The dependency rule.** Modules depend on Tenancy's *domain* (`TenantContext`,
`BelongsToTenant`, `TenantScope`) and on Platform. They do not reach into each other's
internals. There is exactly one documented exception — `TenantProvisioningService` depends
outward on three modules' application services, because provisioning a workspace is a use case
that spans all of them and has to live somewhere
([ADR 0005](../adr/0005-workspace-provisioning-ownership.md)).

Wiring is centralised: `ModuleServiceProvider` registers the seven module providers in
dependency order, and `bootstrap/providers.php` never changes when a context is added.

## Request lifecycle

```
Request
  │
  ├─ AssignRequestId            correlation id; validated, never trusted from the client
  ├─ ForceJsonResponse          the API never answers with HTML
  ├─ ResolveTenant              hostname or X-Tenant → tenant; suspended workspaces stop here
  │    └─ bootstrappers: RLS session var → cache prefix → spatie team → filesystem → queue
  ├─ statefulApi / auth:sanctum cookie session for the SPA, bearer token for integrations
  ├─ EnsureSessionIsCurrent     epoch check + idle timeout
  ├─ password.fresh             confines an expired password to the endpoints that fix it
  ├─ company                    verifies membership on the named company
  ├─ two-factor                 step-up, on the six credential-bearing routes only
  │
  ├─ FormRequest                shape + authorize()
  ├─ Controller                 delegate; no business logic
  ├─ Service                    invariants, transactions, domain events
  ├─ Repository                 query construction
  │
  ├─ RecordRequestContext       actor, impersonator, token — known only after auth
  └─ ApiResponse / ApiExceptionRenderer
```

**Tenant resolution runs before authentication**, and that order is load-bearing: `users` is
tenant-scoped, so the guard cannot look up the authenticating user until the workspace is
known. Resolving afterwards would mean either an unscoped user lookup — a cross-tenant login —
or a chicken-and-egg failure.

**Bootstrapper order is also load-bearing.** `CacheTagBootstrapper` must precede
`PermissionTeamBootstrapper`, or one workspace's cached role set answers another's
authorisation lookups.

## Tenant isolation

Three independent layers, so no single mistake leaks data:

1. **Eloquent global scope** (`TenantScope`) — the primary mechanism. Fails *closed*: with no
   tenant context, only rows with a NULL `tenant_id` are visible. The tempting alternative
   (return everything) turns every console command and forgotten middleware into a leak.
2. **PostgreSQL row level security**, FORCED on 16 tables. This is the backstop for what the
   scope cannot reach: raw queries, a `withoutGlobalScopes()` left in after debugging, a
   relation traversed from an unscoped model.
3. **Per-tenant prefixing** of cache keys, filesystem paths and queue payloads, so isolation
   extends past the database.

RLS here defends against **our own bugs**, not a compromised database credential — the tenant
travels in a session variable the application role sets itself. That is the standard trade for
single-database tenancy and is stated plainly in [ADR 0001](../adr/0001-tenancy-strategy.md),
along with the dedicated-database path for customers whose regulator requires separation.

`RowLevelSecurity::bypass()` is the only escape hatch. It is deliberately awkward, trivially
greppable, and restores state in a `finally` block so an exception cannot leave a pooled
connection unprotected.

## Authorization

Two orthogonal checks, both of which must pass:

- **Permission** — what a person may *do*. Defined in code (`PermissionCatalogue`, 44
  capabilities), synchronised into the database, never customer-editable.
- **Membership** — whose books they may *touch*. `company_memberships`.

Layered on top: **role levels**, a total order that makes escalation expressible. A user may
only assign, edit or delete roles strictly below their own level; self-assignment is refused
outright, since it is the shortest path from "can manage users" to "can do anything".

Sanctum token abilities are a *narrower* third check — a token's abilities are intersected
with its creator's own permissions at issue time, so a token can never outrank the person who
made it.

## Error handling

Every failure is an RFC 9457 problem document with a stable `type`. Clients branch on `type`,
never on `title`, which is prose that may be reworded or translated.

Two rules govern `ApiExceptionRenderer`:

- A `PlatformException` is a *documented outcome* and is reported as such.
- Anything else is a bug: logged with a stack trace and the request id, reported as an opaque
  500. Messages, SQL, paths and traces never cross the boundary outside local.

A missing model returns 404 without naming the model, because confirming that an id exists
elsewhere is an information leak. A permission failure never names the missing permission,
because that enumerates the capability catalogue.

## Data and performance

- **PostgreSQL 17** only. Row level security, partial and expression indexes, `jsonb_path_ops`,
  identity columns and trigram search are all load-bearing — this is not a portable schema.
- **UUID v7 primary keys** — sortable by creation time, so they index like sequential integers
  without leaking row counts or being guessable across tenants.
- **`timestamptz` everywhere.** A transaction date must not shift because a server moved region.
- **Every tenant-scoped index leads with `tenant_id`**, giving the planner a highly selective
  first column.
- **Redis** for cache, sessions and queues, on separate databases — flushing the cache must not
  destroy pending jobs.
- **Horizon queues partitioned by service-level objective**, not by module: `critical`
  (a user is waiting), `default`, `audit` (isolated so a flood of business jobs cannot delay
  the compliance trail), `reports`, `search`.

Model strictness is on outside production: accessing an unselected attribute or an
uneager-loaded relation throws. In production, lazy loading is prevented and logged rather
than thrown — an unbounded query storm is worse than a missing field on a dashboard.

## Front end

A Vue 3 SPA against the same JSON API third parties use. One web route serves the shell;
Vue Router decides the rest.

- **Cookie authentication**, so no token is ever held in JavaScript and XSS cannot exfiltrate
  a long-lived credential.
- **The API client owns cross-cutting behaviour** — CSRF handshake, problem-document
  normalisation, 419 re-handshake, and step-up replay. A 428
  `two-factor-confirmation-required` is a prompt, not an error: the client collects a code and
  replays the original request, so the user does not lose work.
- **Permission checks in the front end are presentation only.** They hide controls the user
  cannot use; the server authorises every request regardless.
- **Server-driven forms.** Roles and Settings render from the server's catalogues, so a
  capability or setting added later appears with its label and control and no front-end change.

## Extensibility seams

Two interfaces exist specifically so later phases do not require refactoring what is built:

| Seam | Phase 1 implementation | Replaced by |
| --- | --- | --- |
| `CompliancePackContract` | `NullCompliancePack` | The Sri Lankan pack (VAT, SVAT, TIN, EPF/ETF, PAYE, RAMIS), then one per market |
| `LedgerActivityProbe` | `NoLedgerActivity` | Accounting, at which point currency and fiscal-calendar immutability begins to bite with no change to the Organization services |

Both are bound in a provider and overridable. Building them now rather than later is what stops
`base_currency_code` from quietly remaining editable forever — the usual fate of a rule that
has nothing to enforce it on day one.

## What Phase 1 deliberately does not include

Attachments, the notifications engine and approval workflows. Each depends on business
documents that do not exist until the Accounting phase; building them now would mean guessing
at their consumers. Declared, not forgotten — see
[PHASE-1-STATUS.md](../PHASE-1-STATUS.md).

## Decision records

| ADR | Subject |
| --- | --- |
| [0001](../adr/0001-tenancy-strategy.md) | Tenancy: single database, row-scoped, three enforcement layers |
| [0002](../adr/0002-tenant-company-branch-hierarchy.md) | Tenant / company / branch, and why membership ≠ role |
| [0003](../adr/0003-permissions-in-code-roles-in-data.md) | Permissions in code, roles in data |
| [0004](../adr/0004-minimal-config-surface.md) | Commit only deviating config files |
| [0005](../adr/0005-workspace-provisioning-ownership.md) | Where workspace provisioning lives |
