# ADR 0002 — Tenant / company / branch hierarchy

- **Status:** Accepted
- **Date:** 2026-08-06

## Context

The specification requires multi-company, multi-tenant, multi-branch operation. Those
three words are often conflated, and conflating them is expensive to undo because the
distinction propagates into every accounting table.

The target market makes this concrete: a Sri Lankan SME group commonly operates two
or three legal entities under common ownership, each filing its own VAT return, with
several shops or warehouses per entity.

## Decision

Three levels, with sharply different meanings:

| Level | Meaning | Owns |
| --- | --- | --- |
| **Tenant** | The subscription. One paying customer of ASIDS. | Users, roles, settings, billing |
| **Company** | A legal entity that keeps its own books. | Chart of accounts, fiscal calendar, base currency, statutory registrations |
| **Branch** | An operating location inside a company. | Nothing — it is a *dimension* on transactions |

- A tenant owns 1..n companies. Every tenant-scoped row carries `tenant_id`.
- A company owns 1..n branches, exactly one of which is primary.
- A company's trial balance is the sum across its branches. Branches do **not** have
  separate books; a branch-level P&L is a filtered report, not a separate ledger.

Data access is governed by **two independent checks**, both of which must pass:

- **Permission** (`invoice.create`) — what a person may *do*. Held via roles.
- **Membership** (`company_memberships`) — whose books they may *touch*.

## Rationale

**Why company is not the tenant.** Billing, user accounts and roles belong to the
customer, not to each legal entity. If company were the tenant, a group with three
entities would need three subscriptions, three copies of every user, and no
consolidated view — which is precisely the pain that makes SMEs abandon
single-company products as they grow.

**Why branch is a dimension, not a set of books.** Making branches ledger-bearing
would require inter-branch elimination entries to produce a company trial balance —
real double-entry complexity for a shop that simply wants to know which outlet is
profitable. Every ASIDS report can filter or group by branch instead.

**Why membership is separate from permissions.** A tenant admin must be able to hire
a bookkeeper for one of five group companies without exposing the other four. A role
alone cannot express that: "Bookkeeper" is a capability set, not a data boundary.
Collapsing the two would force a role per company per job function — a combinatorial
explosion that customers manage badly and auditors cannot read.

## Consequences

- `branches` denormalises `tenant_id` alongside `company_id` so RLS policies and
  tenant-led indexes stay uniform across every table.
- `base_currency_code` and the fiscal year start become immutable once a company has
  posted a journal entry; changing either would silently reinterpret history. The
  enforcement lands with the Accounting phase, and the columns are already positioned
  for it.
- Every future business table carries `tenant_id`, `company_id` and usually a nullable
  `branch_id`. This is the shape all later phases must follow.
- The `fiscal_year_start_day` is capped at 28 by a check constraint, so the fiscal
  calendar is well defined in February.
