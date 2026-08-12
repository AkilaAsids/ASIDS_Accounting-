# ADR 0008 — The Sales HTTP surface, and attribute-array update semantics for customers

- **Status:** Proposed — written for Gate 2 review (Minions Team 17, Stage 3)
- **Date:** 2026-08-12

## Context

Customers (Milestone 2) and tax codes (Milestone 3) are the only bounded context with a full
domain, service, policy and permission set but no `Presentation/Http` layer. Their REST surface is
being added on `feature/sales-http-api`, in parallel with Milestone 5 (issuing / ledger posting),
which this work must not touch. Invoice endpoints are out of scope. The full endpoint contract
lives in [SALES-HTTP-API-DESIGN.md](../SALES-HTTP-API-DESIGN.md); this record holds the decisions
and the one change that could break neighbouring work.

The blocking debt is recorded in `docs/ROADMAP.md:184-208`: **I3** — `CustomerService::update()`
takes a whole `CustomerData` DTO and therefore cannot distinguish "clear this nullable field" from
"field not supplied". The roadmap states plainly that I3 *must* be resolved before the customer
`PUT` semantics are finalised, because shipping the ambiguity would bake it into a public API where
changing it later is a breaking change (`ROADMAP.md:195-208`). Alongside it ride **I4** (customer
code-generation race surfaces as a raw `QueryException`), **M6** (`credit_limit` /
`payment_terms_days` in `$fillable`), **M7** (hardcoded scale 4 in `archive()`), and **M8**
(`applyAttributes()` assigns before validating) — `ROADMAP.md:186-191`.

The codebase already contains exactly one mechanism that expresses omitted-vs-null:
`update(Model, array $attributes)` with `array_key_exists()`, used by
`ChartOfAccountsService::update()` (`src/Core/Accounting/Application/Services/ChartOfAccountsService.php:75-114`)
and `TaxCodeService::update()` (`src/Core/Sales/Application/Services/TaxCodeService.php:97-171`).
`TaxCodeData`'s docblock explicitly documents that its own update path avoided a DTO *because of
I3* (`src/Core/Sales/Application/DTOs/TaxCodeData.php:13-28`).

## Decision

### D1 — `CustomerService::update()` takes an attribute array

The signature changes from

```php
public function update(Customer $customer, CustomerData $data): Customer   // CustomerService.php:81
```

to

```php
/** @param array<string, mixed> $attributes */
public function update(Customer $customer, array $attributes): Customer
```

with `array_key_exists()` semantics: key absent = untouched, key present with `null` = clear (for
nullable columns), unrecognised keys ignored — precisely the `TaxCodeService::update()` /
`ChartOfAccountsService::update()` contract, including validate-everything-before-assigning inside
`DB::transaction`. Per-key behaviour is specified in the design doc §3.1. `create()` keeps
`CustomerData`: creation has no omitted-vs-null ambiguity, and the DTO's `fromArray()`
(`CustomerData.php:51-75`) was written to become the store-request mapping.

The alternative — nullable sentinel objects or an `Optional<T>` wrapper on the DTO — would be a
third update idiom in a codebase that already standardised on the array, and was rejected for that
reason alone.

#### Caller audit — every existing caller of `CustomerService::update()`

Method: exhaustive greps over the whole repository at 46a1c28 — (a) every textual reference to
`CustomerService`, (b) every `->update(` call in non-test code (`src/`, `app/`, `database/`,
`routes/`), (c) every `update($customer…)`-shaped call anywhere including tests, (d) every
`new CustomerData(` / `CustomerData::fromArray(` construction.

| Caller | Where | What it does | Verdict |
| --- | --- | --- | --- |
| `CustomerTest` — "changes details" | `tests/Feature/Sales/CustomerTest.php:475-479` | `update($customer, new CustomerData(name…, paymentTermsDays: 60, creditLimit…))` | **Rewritten in the same commit** to `update($customer, ['name' => …, 'payment_terms_days' => 60, 'credit_limit' => …])`. Same assertions pass — every field it asserts on is supplied. |
| `CustomerTest` — "changes the code" | `CustomerTest.php:488` | `update($customer, new CustomerData(name: 'Silva', code: 'NEW'))` | Rewritten: `['code' => 'NEW']`. |
| `CustomerTest` — "refuses code change once invoiced" | `CustomerTest.php:500-501` | expects `BusinessRuleViolation` (`customer-code-locked`) | Rewritten: `['code' => 'NEW']`; the lock check keys off `array_key_exists('code')` + changed value, so the refusal still fires. |
| `CustomerTest` — "refuses a taken code" | `CustomerTest.php:508-509` | expects `ResourceConflict` | Rewritten: `['code' => 'taken']`. |
| `CustomerTest` — "permits keeping its own code" | `CustomerTest.php:515` | code unchanged + rename | Rewritten: `['name' => 'Renamed', 'code' => 'SILVA']`. |
| `CustomerTest` — audit trail records credit-limit change | `CustomerTest.php:525` | `update(…creditLimit: '1000000.0000')` | Rewritten: `['credit_limit' => '1000000.0000']`. Audit assertion unaffected — `Auditable` reads the model diff, not the input shape. |
| **Production code** | — | `SalesInvoiceService` (M4) contains **no reference to `CustomerService`** at all; the M4 suites (`SalesInvoiceDraftTest.php:53,409,421,562,572`, `SalesInvoiceSchemaTest.php:37`, `SalesInvoiceFactoryTest.php:43`, `SalesInvoiceAuthorizationTest.php:50`) call only `create()` and `archive()`, whose signatures do not change | **No production caller exists. M4 is untouched.** |
| **Seeders** | `database/seeders/` (3 files) | no reference to `Customer` or `CustomerService` | unaffected |
| **Factories** | `database/factories/CustomerFactory.php:30-31` | mentions `CustomerService` in a comment only; factories never call the service | unaffected |
| **Console / commands / jobs** | — | none exist for Sales | unaffected |

**Verdict: SAFE.** The only call sites are six lines inside the test file Lane C already owns, and
they are converted in the same commit that changes the signature. `CustomerData` itself is
untouched, so the ~30 `create()` call sites across the Sales suites compile and behave identically.
Two backstops make a missed caller impossible to ship: PHP raises a `TypeError` on a `CustomerData`
argument against an `array` parameter, and `composer analyse` (PHPStan level 8, part of the merge
gate) fails on any call the greps could conceivably have missed.

### D2 — `DELETE /customers/{customer}` is added to complete the lifecycle (Gate 2 flag)

The Gate 1 endpoint table (`SALES-HTTP-API-REQUIREMENTS.md:41-47`) lists `POST …/restore` but no
`DELETE`. `CustomerService::restore()` operates **only** on soft-deleted rows
(`CustomerService.php:215-222`); without an HTTP producer of soft-deletes, the restore endpoint
would be dead surface that can only ever return `customer-not-deleted`. `CustomerPolicy::delete`
(`CustomerPolicy.php:59-63`), `CustomerService::delete()` (`:184-198`, refused once invoiced) and
the Accounting precedent (`DELETE /accounts/{account}`, `routes/api.php:336`) all exist, so the
omission reads as an oversight in the table rather than a decision. Added, and **flagged for
explicit confirmation at Gate 2** — removing it is a five-minute change if the omission was
deliberate.

Both restore routes carry `->withTrashed()` — the first such use in the codebase, forced by the
fact that `Customer` and `TaxCode` are the first soft-deleting models to be route-bound.
Accounting never needed it because `Account` archives by flag rather than soft-deleting.

### D3 — Customer deactivate/reactivate authorize the `update` ability

`CustomerPolicy` defines `viewAny/view/create/update/archive/delete/restore` but no
`deactivate`/`reactivate` (`CustomerPolicy.php:23-69`). Rather than widening a policy this work is
told not to redefine, the two endpoints authorize `update` — which is exactly what
`TaxCodePolicy` declares those actions to be (`deactivate`/`reactivate` delegate to `update`,
`TaxCodePolicy.php:66-74`). Same capability, no new authorization surface. If a later phase wants
them separable, adding the named methods is additive.

### D4 — M6, M7, M8 land as specified

- **M6**: `payment_terms_days` and `credit_limit` leave `Customer::$fillable`
  (`Customer.php:99-100`). Grep evidence: the only mass-assignment writers are
  `CustomerFactory` (`CustomerFactory.php:40-41,77-85`), and Laravel factories build models inside
  `Model::unguarded()`, so they are unaffected. No `fill()`/`Customer::create([...])` exists
  elsewhere.
- **M7**: `archive()` compares at `Money::SCALE` instead of literal `4` (`CustomerService.php:156`).
- **M8**: `applyAttributes()`'s two rule checks (`:293-306`) run before its assignments (`:275-291`);
  the new `update()` is validate-first by construction, matching `ChartOfAccountsService.php:77-91`.

### D5 — I4 keeps one problem code for one conflict

The race loser is translated where the constraint fires, following `TaxCodeService::save()`
(`TaxCodeService.php:310-338`): a private `CustomerService::save()` catches `QueryException`,
matches `customers_company_code_unique` (migration `2026_03_02_000001:105-109`) and rethrows
`ResourceConflict::duplicate('customer', 'code', …)` — HTTP 409, problem code `duplicate-resource`,
**identical** to the pre-check's refusal (`CustomerService.php:370`). A client sees one code for
one semantic (this customer code is taken), whether the collision was caught before or during the
insert. A retry loop for generated codes was considered and rejected: the requirement asks for the
error shape, the window is milliseconds-rare, and a retry hides a signal integrations may want.

### D6 — Inherited platform behaviours are kept, not forked

Recorded so the Security Reviewer sees them as conscious choices:

1. **Nested bindings are not parent-scoped** anywhere in the platform, so a user who is a member of
   companies A *and* B can address B's customer under A's URL; the policy checks membership of the
   entity's company (`CustomerPolicy.php:32-33`) and passes. Identical to
   `/companies/{company}/accounts/{account}` today. Not a privilege escalation (the caller could
   reach the same row under its correct URL); it is a URL/entity coherence gap. Fixing it belongs
   to a platform-wide decision (`->scoped()` bindings or a path-company assertion in the base
   controller) applied to every module at once — a one-line-per-route change this ADR recommends as
   a future roadmap debt entry rather than a Sales-only fork.
2. **Index/store authorize `view` on the Company** (`AccountController.php:37,73` pattern), which
   couples the Sales lists to `organization.companies.view` (`CompanyPolicy.php:26-30`) in addition
   to `sales.*.view`. Role templates that grant Sales visibility must also grant company visibility
   — already true for the shipped templates (the accountant/viewer suites pass on exactly this
   chain).
3. **No `two-factor` step-up** on `sales.*.manage` routes, matching every `accounting.*.manage`
   route despite both being `sensitive: true` in the catalogue.

## Consequences

- Lane A's `PUT` gets true partial semantics: `branch_id: null`, `receivable_account_id: null` and
  `credit_limit: null` become expressible clears, each provably distinct from omission — the
  acceptance criterion Gate 1 set.
- The internal service API breaks in one commit whose blast radius is six test lines the same
  commit rewrites; nothing in M4/M5 references the method.
- `SalesServiceProvider.php` needs **no change** (policies bound at `:76-78`, services registered
  at `:38`/`:54`), shrinking the anticipated M5 contention surface to `routes/api.php` plus
  `docs/api/openapi.yaml`, both edited in single-purpose commits at pre-agreed anchors
  (design doc §6, §8).
- `TaxType` gains a `values()` helper (mirroring `AccountType::values()`) so `Rule::in()` follows
  the house validation style — additive, owned by Lane B.
- The OpenAPI document grows a `Sales` tag and 18 operations; `scripts/check-openapi.mjs
  --require-routes` forces each lane to land routes and spec in the same commit or fail CI in both
  directions.
