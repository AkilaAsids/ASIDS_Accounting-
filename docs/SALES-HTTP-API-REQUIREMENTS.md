# Sales HTTP API — Requirements (Minions Team 17)

**Stage 2 requirements · awaiting Gate 1 approval.** Delivered in parallel with Milestone 5
(issuing/ledger posting), which this work deliberately does not touch.

## 1. Objective

Give the Sales module its REST surface. Customers (M2) and tax codes (M3) have a domain,
service, policy and permissions but **no `Presentation/Http` layer** — the only bounded context
without one. Expose them following the conventions already established by the Accounting module
(`AccountController` + `StoreAccountRequest` + `AccountResource`, RFC 9457 problem documents,
`api.v1.*` route names, company-nested routes behind the `company` middleware).

Invoice endpoints are **out of scope** — their HTTP surface depends on Milestone 5's final
issued-invoice state machine and would collide with it.

## 2. Scope — three lanes

### Lane C — CustomerService hardening (gates Lane A; no new API surface)
Resolve the cross-cutting debt the roadmap records against the customer domain, so the PUT
endpoint in Lane A is built on correct semantics rather than inheriting the defect:

- **I3 — clear-vs-omit.** `CustomerService::update()` currently takes a whole `CustomerData` DTO
  and cannot tell "clear the branch / receivable-account override" from "field not supplied."
  Rework it to the attribute-array + `array_key_exists()` mechanism already used by
  `TaxCodeService::update()` and `ChartOfAccountsService::update()`. **This is the documented
  blocker for a correct `PUT` and must land before Lane A's update endpoint.**
- **I4 — code-generation error shape.** Customer code generation reads-then-inserts with no
  lock; a concurrent create surfaces as a raw `QueryException`. Return an RFC 9457 **409** instead.
- **M6** — remove `credit_limit` / `payment_terms_days` from `Customer::$fillable` (inert today,
  a future `fill()` would bypass `resolveCreditLimit()` validation).
- **M7** — `archive()` uses literal scale `4`; use `Money::SCALE`.
- **M8** — `applyAttributes()` assigns before validating; validate first so a rolled-back
  transaction does not leave an invalid in-memory model.

### Lane A — Customer REST API (M6 slice)
`CustomerController` + form requests + `CustomerResource`, wiring the **existing**
`sales.customers.{view,manage}` permissions and `CustomerPolicy`. Routes nested under
`companies/{company}/customers` behind `company` middleware:

| Verb | Path | Capability |
|---|---|---|
| GET | `/customers` | `customers.view` |
| POST | `/customers` | `customers.manage` |
| GET | `/customers/{customer}` | `customers.view` |
| PUT | `/customers/{customer}` | `customers.manage` (partial semantics from I3) |
| POST | `/customers/{customer}/archive` · `/restore` · `/deactivate` · `/reactivate` | `customers.manage` |

### Lane B — Tax-code REST API (M6 slice)
`TaxCodeController` + requests + `TaxCodeResource`, wiring the existing
`sales.tax-codes.{view,manage}` permissions and `TaxCodePolicy`. Routes under
`companies/{company}/tax-codes`, including the effective-dated-rate lifecycle
(`endRange`, `deactivate`/`reactivate`, `delete`/`restore`). Fully independent of Lanes A and C.

## 3. Acceptance criteria (per lane)

- Every endpoint enforces its permission through the existing policy; a user without the
  capability gets a 403 problem document, not a 200.
- **Tenant + company isolation:** a caller cannot read or mutate a customer/tax-code belonging to
  another workspace (RLS) or a sibling company they are not a member of (403). Explicit tests.
- Validation failures return RFC 9457 problem documents with stable `type` codes; no raw
  framework exceptions leak.
- I3: PUT can set a nullable field to null **and** leave it untouched by omission — both proven
  by test. I4: concurrent-create collision returns 409, not 500.
- Route names follow `api.v1.companies.customers.*` / `...tax-codes.*`; OpenAPI updated and
  `scripts/check-openapi.mjs` passes.
- New + changed code covered to the repo's gates (PHP ≥ 85%). Full suite green, Pint + PHPStan L8 clean.

## 4. Out of scope / exclusions
- Milestone 5 (issuing, ledger posting, numbering, cancellation, immutability trigger) — Akila.
- Invoice HTTP endpoints, aged-receivables / AR reports (need issued invoices).
- P&L / balance-sheet reports (roadmap 🟡 "proposed only").
- Any front-end work (that is M8, and depends on this contract).

## 5. Assumptions
- Customer & tax-code **policies and permissions already exist** and are correct (M2/M3); this
  work wires them into HTTP, it does not redefine authorization.
- The two files shared with the M5 lane — `routes/api.php` and `SalesServiceProvider.php` — are
  the only contention points; managed by per-lane file ownership and small attributed commits.
- Delivery is to a **feature branch + PR** for Akila to review. Nothing is pushed to `origin/main`;
  no production or staging deploy is in scope (autonomy stops at the PR).

## 6. Risks
- **Shared-file collision** with M5 on `routes/api.php` / `SalesServiceProvider.php` — mitigated
  by additive route groups and DM-coordinated commits.
- I3 changes a service signature the just-landed invoice code may call — the Architect confirms
  no M4 caller regresses before Lane C starts.

## 7. Team & gates
Minions Team 17 (Balanced, 7): DM (opus), Solution Architect (fable), 2× Backend (sonnet),
QA (sonnet), Security Reviewer (fable), Documentation (haiku). Gate 1 = these requirements.
Gate 2 = architecture + API contract. Gate 3 (production) is **not reached** — this ends at a PR.
