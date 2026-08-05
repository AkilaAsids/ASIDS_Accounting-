# ADR 0001 — Tenancy strategy: single database with row-scoped isolation

- **Status:** Accepted
- **Date:** 2026-08-06
- **Deciders:** Chief Architect
- **Supersedes:** —

## Context

ASIDS ERP Cloud must support 100,000 companies and 1,000,000 users on PostgreSQL,
with sub-second dashboards and horizontal scaling. `stancl/tenancy` is a mandated
dependency and supports two models:

1. **Database (or schema) per tenant** — physical separation.
2. **Single database, row-scoped** — one schema, `tenant_id` on every table.

The choice is effectively irreversible once customer ledgers exist, so it is made
before any accounting table is written.

## Decision

**Single database, row-scoped tenancy**, with three enforcement layers:

1. An Eloquent global scope (`TenantScope`) applied by the `BelongsToTenant` trait,
   which also stamps `tenant_id` on write and refuses cross-tenant writes.
2. PostgreSQL **row level security**, FORCED on every tenant-scoped table, keyed on
   the `asids.tenant_id` session variable set by `RowLevelSecurityBootstrapper`.
3. Per-tenant prefixing of cache keys, filesystem paths and queue payloads, so
   isolation extends beyond the database.

A **dedicated database** remains available as an enterprise tier; because the
application always addresses tenants through `TenantContext` and never hardcodes a
connection, moving one tenant to its own database is a deployment change, not a
rewrite.

## Rationale

**Against database-per-tenant at this scale:**

- 100,000 databases means 100,000 migration executions per release. A single schema
  change becomes a multi-hour orchestrated job with partial-failure states, and the
  window during which some tenants run new code against an old schema is the source
  of the worst production incidents in this architecture.
- Connection pooling collapses. PgBouncer pools per (database, user) pair, so a
  hundred thousand databases cannot share a warm pool; connection establishment
  becomes the dominant request cost.
- Cross-tenant reporting — which the platform back office, billing and support
  tooling all need — becomes a fan-out over 100,000 connections instead of a
  `WHERE tenant_id = …`.
- PostgreSQL itself degrades: `pg_class` and the shared catalogues grow with the
  number of relations, and autovacuum scheduling across hundreds of thousands of
  tables is a known operational cliff.

**For row-scoped tenancy:**

- One migration per release, one connection pool, one query planner cache.
- Composite indexes led by `tenant_id` give the planner a highly selective first
  column, so per-tenant queries stay fast even as the table grows: a tenant with
  10,000 invoices reads its own 10,000 rows regardless of the other 999,990,000.
- Aggregate reporting for the platform is a normal query.

**On the honest limits of RLS here.** The tenant is communicated to PostgreSQL via a
session variable the application role sets itself. An attacker executing arbitrary
SQL as that role can also set the variable. RLS in this design therefore defends
against **our own bugs** — a forgotten `where`, a `withoutGlobalScopes()` left in
after debugging, a raw query in a report — and not against a compromised database
credential. That is the correct and standard trade-off for single-database tenancy,
and it is why credential protection (IAM auth, rotation, no shared secrets) is
treated as a first-class control rather than an afterthought. Customers whose
regulator requires physical separation are placed on the dedicated-database tier.

## Consequences

**Positive**

- Single migration path; predictable releases.
- Efficient connection and cache utilisation.
- Cross-tenant analytics and support tooling are trivial.
- Three independent isolation layers, so no single mistake leaks data.

**Negative / accepted risks**

- A missing `tenant_id` filter in a *raw* query that also runs under an RLS bypass
  would leak. Mitigated by: `RowLevelSecurity::bypass()` being the only bypass, being
  greppable, and being asserted absent from HTTP paths in the test suite.
- Very large tenants share table space with small ones. Mitigated by partitioning
  high-volume tables by `tenant_id` hash when a table exceeds ~500M rows — deferred
  until measured, because premature partitioning complicates every query plan.
- `tenant_id` must be denormalised onto child tables (`branches` carries both
  `tenant_id` and `company_id`). Accepted: it keeps every index and every policy
  uniform, at the cost of 16 bytes per row.

## Implementation notes

- `docker/postgres/init/01-bootstrap.sh` creates `asids_app` as `NOBYPASSRLS`; the
  application must never connect as the schema owner in staging or production.
- `RowLevelSecurity::isEnforced()` backs a deployment check that fails the release if
  policies are not actually in force.
- Policies are FORCED so protection also applies when the connecting role owns the
  tables, which is what keeps the isolation tests meaningful in CI.

## Revisit when

- Any single tenant exceeds ~10% of total row volume (consider dedicated database).
- A tenant-scoped table passes ~500M rows (consider hash partitioning).
- A regulated market requires physical separation as a condition of sale.
