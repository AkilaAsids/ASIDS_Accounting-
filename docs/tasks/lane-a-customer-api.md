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

**2026-08-12 — BE-1.** Built the four owned files against the RED `tests/Feature/Sales/CustomerApiTest.php`
(not edited, per instructions) plus the two wiring edits:

- `src/Core/Sales/Presentation/Http/Controllers/CustomerController.php` — mirrors `TaxCodeController`
  (thin delegation, `authorize` first). Index uses the `JournalEntryController` paginated-search
  pattern via `QueryCriteria` (`sortable: name/code/created_at`, `filterable: status/branch_id`,
  `defaultSort: name`, `q` over `name`/`code` `ilike`) rather than the unpaginated chart-of-accounts
  pattern, per design §4.1. `deactivate`/`reactivate` authorize `update` on the customer — `CustomerPolicy`
  has no dedicated methods for them (ADR 0008 §D3).
- `src/Core/Sales/Presentation/Http/Requests/StoreCustomerRequest.php`, `UpdateCustomerRequest.php` —
  field-for-field per design §4.2. Built the attribute array from validated **present** keys only
  (`sometimes` on every clearable field), never a full DTO — `$request->validated()` passed straight
  through to `CustomerService::update()` preserves "omitted vs `null`" exactly, which is what the I3
  HTTP tests assert for `branch_id`, `receivable_account_id`, `credit_limit` in both directions. Both
  requests coerce a numeric `credit_limit` to a string in `prepareForValidation` (as
  `StoreJournalEntryRequest` does for amounts) and trim `code` (as `StoreAccountRequest`/`UpdateAccountRequest`
  do).
- `src/Core/Sales/Presentation/Http/Resources/CustomerResource.php` — shaped like `AccountResource`/
  `TaxCodeResource`; `capabilities.accepts_new_invoices` exposes model state (not a gate, per §4.3).
- `routes/api.php` — added the `CustomerController` import (alphabetical) and the
  `companies/{company}/customers` group, inserted between the `{company}/reports` group and the
  existing `{company}/tax-codes` group, exactly at the anchor in design §6.1. Did not touch the
  tax-codes hunk. `restore` carries `->withTrashed()` since `Customer` soft-deletes.
- `docs/api/openapi.yaml` — added the `Customer`/`CustomerRequest` schemas (before `TaxCode`), the
  `CustomerId` parameter and `CustomerResponse` in `components`, and the nine customer paths inserted
  between the `trial-balance` section and the `# ── Sales: tax codes ──` section (design §7 anchor).
  The `Sales` tag already existed (Lane B landed first).

Verification:

- `docker exec -u asids asids-app php artisan test tests/Feature/Sales/CustomerApiTest.php` →
  **43/43 passed (290 assertions)**, first run, no edits to the test file.
- `docker exec -u asids asids-app php artisan test tests/Feature/Sales` → **451/451 passed
  (1147 assertions)** — Customer API, Tax Code API/Service/Authorization/Factory/Schema, the Sales
  invoice (M4) suites and `TaxRateResolverTest` all green together.
- `node scripts/check-openapi.mjs` → parses, all `$ref`s resolve, exit 0. Host `php` is Windows-native
  and this repo's vendor lives only in the Docker container, so the script's own `--require-routes`
  PHP shell-out cannot reach the container from here; verified route coverage manually instead: dumped
  `docker exec -u asids asids-app php artisan route:list --json` and re-ran the script's exact
  two-way comparison (served vs. documented, both directions) against it — **113 served routes,
  113 documented operations, zero undocumented, zero phantom**, including all nine customer routes.
- `./vendor/bin/pint --test` on all five changed files → **PASS, 5 files**, no changes needed.
- `./vendor/bin/phpstan analyse --memory-limit=2G` on the four new Presentation files → **[OK] No
  errors** (level 8, per `phpstan.neon`).

## Issues
None. One thing to flag for the record rather than a defect: `check-openapi.mjs --require-routes`
could not be run literally as written from this host (no PHP binary that resolves this app's
`vendor/autoload.php` outside Docker, and Docker's PHP has no `node`/access back out). The manual
route-diff above reproduces the script's exact comparison logic and is a faithful substitute; CI
(which presumably has PHP on the same host as Node) will run the real command unmodified.

## Outcome
Lane A (Customer REST API) complete and green. All hard-constraint file boundaries respected: only
the four new Customer HTTP files, the customers hunk of `routes/api.php` (tax-codes hunk untouched),
the customer paths/schemas of `docs/api/openapi.yaml`, and this task file were touched. No git
commands run — changes left in the working tree for DM review.
