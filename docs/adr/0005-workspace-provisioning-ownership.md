# ADR 0005 — Workspace provisioning lives in the Tenancy module

- **Status:** Accepted
- **Date:** 2026-08-06

## Context

Provisioning a workspace touches every core module at once: it creates a tenant and a
hostname (Tenancy), seeds system roles (Authorization), creates the owner account
(Identity), and creates the first company with its primary branch and membership
(Organization). It must be atomic — a tenant with no company and no owner is not a
workspace anyone can sign in to, and a partial provision is worse than a failure
because the slug is already taken and the customer cannot retry.

In a modular monolith with a strict inward-only dependency rule, a use case that spans
four modules has no obvious home.

## Decision

`TenantProvisioningService` lives in `Tenancy\Application\Services` and is permitted
to depend on the **application services** of Identity, Authorization and Organization.

The rule that keeps this from becoming a tangle is asymmetric and enforced by review:

- Tenancy's *application* layer may depend outward on other modules' application
  services.
- No module may depend on Tenancy's application layer. Other modules depend only on
  Tenancy's **domain** layer — `TenantContext`, `BelongsToTenant`, `TenantScope` — which
  depends on nothing.

There is therefore no cycle: the dependency graph has Tenancy's domain at the bottom
and Tenancy's provisioning service at the top.

## Alternatives considered

**A dedicated `Onboarding` module.** Cleanest on paper. Rejected for now because it
would contain exactly one class and would still depend outward on the same four
modules — the same coupling, one more module to navigate. It is the right refactor the
moment provisioning grows beyond one use case (trial conversion, plan migration,
tenant cloning, data import), and the service is written so that move is a namespace
change.

**Inverted contracts.** Define `UserProvisioningContract`, `CompanyProvisioningContract`
and `RoleProvisioningContract` in Tenancy, implemented by the other modules. Rejected:
three interfaces existing solely to reverse an arrow that causes no actual problem.
The indirection would make provisioning harder to read without making it more
testable — the collaborators are already injected and already substitutable.

**Orchestrate in the controller.** Rejected outright: the transaction, the RLS bypass
and the ordering constraints are domain logic, and there are three callers (public
sign-up, back office, demo seeder).

## Consequences

- One class in the codebase depends outward. It is documented here and named
  unambiguously.
- Provisioning is unit testable by substituting the four injected collaborators.
- The transaction boundary and the `RowLevelSecurity::bypass()` scope are in one place,
  so it is possible to verify by reading that no partial state can escape.
- If a future module must participate in provisioning, it is added as a fifth
  injected collaborator — and that is the signal to extract the `Onboarding` module.
