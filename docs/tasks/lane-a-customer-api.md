# Task: Lane A — Customer REST API

**Owner:** Backend Engineer 1 (BE-1, after Lane C) · **Tests by:** QA (red-first) · **Contract:** [DESIGN §4, §6](../SALES-HTTP-API-DESIGN.md)

## Scope
`companies/{company}/customers` REST surface wiring the EXISTING `sales.customers.{view,manage}` permissions + `CustomerPolicy`. Endpoints (DESIGN §4.1): index, store, show, **put** (partial, from I3), archive, restore, deactivate, reactivate, **delete** (soft — approved at Gate 2).

## File ownership
- **Create (BE-1):** `src/Core/Sales/Presentation/Http/Controllers/CustomerController.php`, `.../Requests/StoreCustomerRequest.php`, `.../Requests/UpdateCustomerRequest.php`, `.../Resources/CustomerResource.php`.
- **Edit (BE-1):** `routes/api.php` (customers group, anchor after `{company}/reports`), `docs/api/openapi.yaml` (after `trial-balance`). **Routes + spec in the SAME commit** (`check-openapi.mjs --require-routes`).
- **Do NOT create** `tests/Feature/Sales/CustomerApiTest.php` — QA owns it. Make it green.

## Acceptance criteria
- Every endpoint enforces its ability via `CustomerPolicy` (403 problem without capability).
- RLS + cross-company isolation proven (see QA CustomerApiTest). Validation → RFC 9457 problems, no raw exceptions.
- PUT: set-null vs omit both work over HTTP. OpenAPI green. Coverage ≥85% on new code.

## Progress
_(BE-1 appends)_

## Issues
_(none yet)_

## Outcome
_(filled at close)_
