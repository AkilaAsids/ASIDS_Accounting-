# ADR 0003 — Permissions live in code, roles live in data

- **Status:** Accepted
- **Date:** 2026-08-06

## Context

`spatie/laravel-permission` is a mandated dependency and treats permissions and roles
symmetrically: both are database rows a user could in principle create. The
specification requires RBAC across 100,000 tenants, and it also requires that every
feature be security-reviewable.

Those two requirements pull in different directions if permissions are customer data.

## Decision

- **Permissions are defined in code**, in `PermissionCatalogue`, and synchronised into
  the `permissions` table by `PermissionSynchroniser` (`asids:sync-permissions`, run
  after `migrate` on every deploy). They are **global** — no `tenant_id` — and have no
  write endpoint at all.
- **Roles are customer data**, tenant scoped via spatie's teams mode with
  `tenant_id` as the team key. Customers create, rename and re-scope their own roles.
- Every workspace is provisioned with five **system roles** from `RoleTemplate`:
  owner, administrator, accountant, bookkeeper, viewer. Their permissions are editable;
  their names are not.
- Wildcard permissions are **disabled** (`enable_wildcard_permission => false`).
- The **owner** role grants everything **implicitly**, through a `Gate::before` rule
  keyed on the `is_owner` column — not through pivot rows.

## Rationale

**Why permissions in code.** A permission is a branch in a policy. A row without
corresponding code is a lie — it appears grantable and does nothing. Code without a row
silently denies everyone. Making code authoritative means a capability arrives and
departs with the feature that implements it, and a reviewer can enumerate the entire
security surface of the product by reading one file. That property does not survive
customer-editable permissions.

**Why permissions are global.** A capability is a property of the software, not of a
customer. Per-tenant permission rows would mean 100,000 copies of an identical catalogue,
and a migration that adds one capability would write 100,000 rows.

**Why roles are per-tenant.** An "Accountant" at one customer is unrelated to an
"Accountant" at another. Global roles would force every customer into one firm's idea of
a job description — the single most common reason SMEs end up giving everyone
administrator access.

**Why no wildcards.** `invoice.*` is convenient and unauditable: nobody can answer "who
can void a posted invoice?" by inspection, and a capability added later is granted
retroactively to everyone holding the wildcard. Explicit rows make the question a query.

**Why the owner is implicit.** If ownership were an exhaustive pivot set, then every
capability added in a later phase would be missing from the role of the person paying for
the product until someone remembered to backfill it. The implicit grant is also
narrower than it looks: it is scoped to the owner's own tenant, and `Gate::after`
still denies a suspended account.

**Why role levels exist.** Permissions alone cannot express "an administrator must not
be able to mint another owner". The `level` column gives a total order, and the rule is
uniform: a user may only assign, edit or delete roles **strictly below** their own level.
Self-assignment is refused outright, since it is the shortest path from "can manage
users" to "can do anything".

## Consequences

- Adding a capability is a code change plus `asids:sync-permissions`; existing
  workspaces pick it up via `--refresh-roles`, which grants additions only to system
  roles the customer has not customised.
- A permission removed from the catalogue is **reported, not deleted** — the usual cause
  is a partially deployed release, and cascading the delete would strip live roles.
  Actual removal is an explicit migration.
- `PermissionTeamBootstrapper` must run after `CacheTagBootstrapper`, or one workspace's
  cached role set answers another's lookups. This is the module's most load-bearing
  wiring and is asserted by a tenant-isolation test.
- Role writes go through `RoleService`, never spatie directly, because the invariants
  (level ordering, owner protection, last-owner protection, platform-capability refusal)
  cannot be expressed as database constraints.
- Provisioning writes the `role_has_permissions` pivot directly rather than calling
  `syncPermissions()`, because that method resolves the *current* team from the
  registrar — which during provisioning is not yet the tenant being provisioned, and
  would silently produce roles with no permissions.

## Revisit when

- A customer needs permissions scoped per company rather than per workspace (today a
  role applies across every company the user is a member of; company-level data access is
  handled separately by `company_memberships`).
- Attribute-based rules are required — for example approval limits by amount — which are
  a different mechanism, not more permissions.
