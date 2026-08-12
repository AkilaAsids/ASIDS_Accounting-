# Task: Lane C — CustomerService hardening

**Owner:** Backend Engineer 1 (BE-1) · **Gates:** Lane A · **Contract:** [DESIGN §3](../SALES-HTTP-API-DESIGN.md) · [ADR 0008](../adr/0008-sales-http-api-and-customer-update-semantics.md)

## Scope
Resolve customer-domain debt so Lane A's PUT is built on correct semantics. No new API surface.
- **I3** — `CustomerService::update(Customer, array $attributes)` with `array_key_exists` clear-vs-omit (DESIGN §3.1). Mirror `TaxCodeService::update` / `ChartOfAccountsService::update`.
- **I4** — private `save()` translates the `customers_company_code_unique` violation to `ResourceConflict::duplicate('customer','code',…)` → same `duplicate-resource` 409 (DESIGN §3.2). **Decision: same code as pre-check (approved).**
- **M6** — drop `payment_terms_days`, `credit_limit` from `Customer::$fillable`.
- **M7** — `archive()` use `Money::SCALE` not literal `4`.
- **M8** — validate before assign in `applyAttributes()` (satisfied by §3.1 rework for update).

## File ownership (exclusive to BE-1)
`src/Core/Sales/Application/Services/CustomerService.php`, `src/Core/Sales/Domain/Models/Customer.php`, `tests/Feature/Sales/CustomerTest.php`.

## Acceptance criteria
- PUT-equivalent: clearing `branch_id`/`receivable_account_id`/`credit_limit` (present+null) vs leaving untouched (omit) both proven at service level, both directions.
- VAT cross-rule evaluated on effective values. Negative payment terms refused. Code-lock on invoiced customer preserved.
- I4: concurrent create collision → `ResourceConflict` (409), not `QueryException` (500).
- M8: a failed update leaves the in-memory model unchanged.
- The six existing `update()` call sites in `CustomerTest.php` rewritten to arrays. Full Sales suite green; Pint + PHPStan L8 clean.

## Cross-lane consumers
`update()` signature change: audited SAFE — only test callers (ADR 0008 §caller-audit). Do not change `create()`/`CustomerData`.

## Progress
_(BE-1 appends before each handoff)_

**2026-08-12 — BE-1 — I3/I4/M6/M7/M8 implemented, full suite green.**

- **I3**: `CustomerService::update(Customer $customer, array $attributes)` replaces the `CustomerData`
  signature, `array_key_exists` semantics throughout. Every effective value (code, `branch_id`,
  `receivable_account_id`, `credit_limit`, `is_vat_registered`/`vat_registration_number` cross-rule,
  `payment_terms_days`, `country_code`) is computed and every rule checked in the method body before
  `DB::transaction` runs the single assign-and-save block — mirrors
  `ChartOfAccountsService::update()` / `TaxCodeService::update()` exactly. `create()` and
  `CustomerData` unchanged.
- **I4**: private `CustomerService::save(Customer): Customer` (copied from `TaxCodeService::save()`)
  wraps every `$customer->save()` in `create()`/`update()`, catches `QueryException`, matches
  `customers_company_code_unique`, rethrows `ResourceConflict::duplicate('customer','code',...)` —
  same `duplicate-resource` problem code as the pre-check. `deactivate()`/`reactivate()`/`archive()`
  keep their direct `->save()` calls (design scoped I4 to create/update only).
- **M6**: `payment_terms_days` and `credit_limit` removed from `Customer::$fillable`. `update()`
  assigns both directly (not via `fill()`), so mass-assignment guarding has no effect on the new path.
- **M7**: `archive()`'s `bccomp($outstanding, '0', 4)` → `bccomp($outstanding, '0', Money::SCALE)`.
- **M8**: `applyAttributes()` (still used by `create()`) hoists both rule checks and
  `resolveCreditLimit()` above the first assignment. `update()` satisfies M8 by construction — no
  assignment happens until after every check has passed, all inside the `DB::transaction` closure.

**Tests** (`tests/Feature/Sales/CustomerTest.php`): rewrote the six `update()` call sites (previously
`:475/488/500/508/515/525`) to attribute arrays per the ADR 0008 caller-audit table. Added:
clear-vs-omit both directions for `branch_id`, `receivable_account_id`, `credit_limit` (6 tests); the
VAT cross-rule evaluated on effective values, 4 tests covering both directions and both same-update
combinations; I4 — two tests using `ReflectionMethod` to invoke the private `save()` directly on a
`Customer` built to collide with an already-created row's code, bypassing `assertCodeAvailable()`
the same way a genuine concurrent request would, proving the `QueryException` is translated to
`ResourceConflict` (`duplicate-resource`) and never escapes raw; M8 — one test asserting a refused
update leaves `name`, `payment_terms_days`, `code` and `isDirty()` exactly as handed in.

**Verification**: `tests/Feature/Sales/CustomerTest.php` 68/68 green (99 assertions); full
`tests/Feature/Sales` suite 362/362 green; M4 suites
(`SalesInvoiceDraftTest`/`SalesInvoiceSchemaTest`/`SalesInvoiceFactoryTest`/`SalesInvoiceAuthorizationTest`)
132/132 green, confirming the caller audit — no M4 code references `CustomerService::update()`. Pint
clean on all three touched files. PHPStan level 8 clean on the two production files
(`CustomerService.php`, `Customer.php`) and on the whole project via `composer analyse` (test spec
files are out of PHPStan's scope by `phpstan.neon`'s own design, so `CustomerTest.php` is verified by
execution, not static analysis, matching every other Pest spec in the repo).

Only the three owned files were touched. No git commands run — changes left in the working tree for
the Delivery Manager to review and commit.

## Issues (review findings — file:line — blocker/should/nit)
_(none yet)_

## Outcome
_(filled at close)_
