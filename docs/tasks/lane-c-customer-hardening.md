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

## Issues (review findings — file:line — blocker/should/nit)
_(none yet)_

## Outcome
_(filled at close)_
