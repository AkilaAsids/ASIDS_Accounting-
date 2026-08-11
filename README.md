# ASIDS ERP Cloud

Enterprise multi-tenant Accounting & ERP platform for ASIDS Technologies Pvt Ltd.

Laravel 12 · PHP 8.4 · PostgreSQL 17 · Redis · Vue 3 + TypeScript · Meilisearch · Horizon

> **Build state:** Phases 1 (platform foundation) and 2 (accounting core) are complete
> and verified; Phase 3 (customers and sales invoicing) is in progress.
> See [docs/ROADMAP.md](docs/ROADMAP.md) for what is done, what is firm and what is
> only proposed, and the phase status documents for what each delivered:
> [Phase 1](docs/PHASE-1-STATUS.md), [Phase 2](docs/PHASE-2-STATUS.md).

## Architecture at a glance

A **modular monolith**. Each bounded context under `src/Core/<Module>` owns its
domain, application, infrastructure and presentation layers, and is wired by a single
service provider registered in `Asids\Core\Platform\Providers\ModuleServiceProvider`.

```
src/Core/
├── Platform/       Shared kernel: request context, API envelope, problem details,
│                   base repository, query criteria, compliance-pack seam
├── Tenancy/        Tenant, hostname resolution, tenant scope, RLS, provisioning
├── Identity/       Users, authentication, 2FA, devices, access tokens
├── Authorization/  Roles, permission catalogue, policies
├── Organization/   Companies, branches, company memberships
├── Settings/       Hierarchical typed settings (user → company → tenant → system)
└── Audit/          Append-only hash-chained audit trail, activity feed
```

Dependency rule: modules depend on Tenancy's **domain** layer and on Platform, never
on each other's internals. The single documented exception is workspace provisioning
([ADR 0005](docs/adr/0005-workspace-provisioning-ownership.md)).

## Tenancy model

Single database, row-scoped, with three enforcement layers — an Eloquent global scope,
FORCED PostgreSQL row level security, and per-tenant cache/filesystem/queue prefixing.
The reasoning, including the honest limits of RLS in this design, is in
[ADR 0001](docs/adr/0001-tenancy-strategy.md).

Tenant → Company → Branch have deliberately distinct meanings; see
[ADR 0002](docs/adr/0002-tenant-company-branch-hierarchy.md).

## Decision records

| ADR | Subject |
| --- | --- |
| [0001](docs/adr/0001-tenancy-strategy.md) | Tenancy strategy: single database, row-scoped |
| [0002](docs/adr/0002-tenant-company-branch-hierarchy.md) | Tenant / company / branch hierarchy |
| [0003](docs/adr/0003-permissions-in-code-roles-in-data.md) | Permissions live in code, roles live in data |
| [0004](docs/adr/0004-minimal-config-surface.md) | Commit only deviating config files |
| [0005](docs/adr/0005-workspace-provisioning-ownership.md) | Where workspace provisioning lives |
| [0006](docs/adr/0006-tax-code-modelling.md) | Tax codes: effective-dated rates behind a jurisdictional seam |

## Local development

Prerequisites: Docker, PHP 8.4, Composer 2, Node 22.

```bash
cp .env.example .env && composer install && npm ci && php artisan key:generate
```

```bash
docker compose up -d
```

```bash
php artisan migrate --seed
```

The application is served on `http://asids.localhost`; a tenant workspace is reached at
`http://{slug}.asids.localhost`. Add both to `/etc/hosts`, or use the `X-Tenant` header
against `http://localhost`.

| Service | Address |
| --- | --- |
| Application | http://asids.localhost |
| Vite dev server | http://localhost:5173 |
| Horizon | http://asids.localhost/ops/horizon |
| Mailpit | http://localhost:8025 |
| Meilisearch | http://localhost:7700 |

## Quality gates

```bash
composer check
```

Runs Pint (`--test`), PHPStan level 8, and Pest. The same three run in CI along with
`npm run typecheck`, `npm run lint`, `npm run test:coverage` and a production asset
build. Coverage gates: 85% PHP, 80% TypeScript.

## Security posture

- Row level security FORCED on every tenant-scoped table; the application connects as
  a `NOBYPASSRLS` role
- Append-only, SHA-256 hash-chained audit trail with a database-level mutation guard
- TOTP two factor with two-phase enrolment and single-use, individually hashed
  recovery codes
- Per-account lockout plus per-IP and per-identity rate limiting
- Password policy aligned to NIST SP 800-63B, with reuse prevention and rotation
- No public storage disk; documents are served only through short-lived signed URLs
- Credentials scrubbed from every log channel by a Monolog processor
- RFC 9457 problem documents that never leak internal detail outside local

## Licence

Proprietary. © ASIDS Technologies Pvt Ltd.
