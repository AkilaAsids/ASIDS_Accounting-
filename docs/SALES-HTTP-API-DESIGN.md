# Sales HTTP API — Architecture & Design (Gate 2)

**Stage 3 deliverable · Minions Team 17 · branch `feature/sales-http-api` (base `main` @ 552a25c).**
Companion decision record: [ADR 0008](adr/0008-sales-http-api-and-customer-update-semantics.md).
Requirements: [SALES-HTTP-API-REQUIREMENTS.md](SALES-HTTP-API-REQUIREMENTS.md) (Gate 1 approved).

Every pattern in this document is extended from code that exists on the branch today; citations are
`file:line` at commit 46a1c28. **Milestone 5 (issuing, ledger posting) is out of scope and is not
referenced by any contract below.**

---

## 1. The pattern being extended

The Accounting module is the canonical HTTP surface. The Sales lanes copy it exactly:

| Concern | Canonical code |
| --- | --- |
| Controller shape (thin delegation, `authorize` first) | `src/Core/Accounting/Presentation/Http/Controllers/AccountController.php:28-153` |
| Base controller + `currentUser()` | `src/Core/Platform/Http/Controllers/ApiController.php:19-39` |
| Success envelope `{data, meta}` | `src/Core/Platform/Http/Responses/ApiResponse.php:25-134` |
| Failure = RFC 9457 problem document | `src/Core/Platform/Exceptions/ApiExceptionRenderer.php:37-215` |
| 422 domain refusals (`BusinessRuleViolation`) | `src/Core/Platform/Exceptions/BusinessRuleViolation.php:17-54` |
| 409 conflicts (`ResourceConflict`) | `src/Core/Platform/Exceptions/ResourceConflict.php:17-65` |
| Create request (trim in `prepareForValidation`) | `src/Core/Accounting/Presentation/Http/Requests/StoreAccountRequest.php:19-46` |
| Update request (every field `sometimes` → partial PUT) | `src/Core/Accounting/Presentation/Http/Requests/UpdateAccountRequest.php:18-42` |
| Decimal-string amounts (regex + numeric→string coercion) | `src/Core/Accounting/Presentation/Http/Requests/StoreJournalEntryRequest.php:44-45,65-93` |
| Resource with `capabilities` block | `src/Core/Accounting/Presentation/Http/Resources/AccountResource.php:14-58` |
| Paginated, searchable index | `src/Core/Accounting/Presentation/Http/Controllers/JournalEntryController.php:37-75` + `src/Core/Platform/Domain/Query/QueryCriteria.php:19-228` |
| Small-catalogue index (unpaginated, `active_only`) | `AccountController::index` (`AccountController.php:35-62`) |
| Company-nested route group behind `company` middleware | `routes/api.php:307-370` |
| Attribute-array update semantics (I3 precedent) | `src/Core/Sales/Application/Services/TaxCodeService.php:97-171` and `src/Core/Accounting/Application/Services/ChartOfAccountsService.php:75-114` |
| DB-constraint → `ResourceConflict` translation | `TaxCodeService::save()` (`TaxCodeService.php:310-338`) |
| HTTP feature-test conventions (`toBeEnvelope`, `toBeProblem`, helpers) | `tests/Feature/Accounting/AccountingApiTest.php:31-105`, `tests/Pest.php:32-65` |

Domain, policies and permissions already exist and are **wired in, not redefined**:

- `sales.customers.{view,manage}` — `src/Core/Authorization/Domain/Catalogue/PermissionCatalogue.php:235-239`
- `sales.tax-codes.{view,manage}` — `PermissionCatalogue.php:241-246`
- `CustomerPolicy` — `src/Core/Sales/Policies/CustomerPolicy.php:23-69`
- `TaxCodePolicy` — `src/Core/Sales/Policies/TaxCodePolicy.php:30-93`
- Policies are **already bound** in `SalesServiceProvider::boot()` (`src/Core/Sales/Providers/SalesServiceProvider.php:76-78`); services already registered (`:38`, `:54`).

---

## 2. Common contract behaviour (both lanes)

### 2.1 Middleware stack and route names

All new routes live inside the existing `Route::prefix('companies')` group in `routes/api.php`,
inside `auth:sanctum` + `session.current` + `password.fresh`, each group carrying the `company`
middleware alias (`ResolveActiveCompany`, `bootstrap/app.php:55`) exactly as the Accounting groups
do (`routes/api.php:329-363`). Route names are `api.v1.companies.customers.*` and
`api.v1.companies.tax-codes.*`. No `two-factor` step-up: `sales.*.manage` is sensitive in the
catalogue, but the Accounting precedent (`accounting.accounts.manage`, equally sensitive) applies
no step-up to its routes; parity is deliberate.

### 2.2 Isolation model — who is stopped where

| Layer | Mechanism | Wrong-actor outcome |
| --- | --- | --- |
| Other **workspace** (tenant) | PostgreSQL RLS on `customers` / `tax_codes` (`src/Core/Sales/Database/Migrations/2026_03_02_000002_*`, `2026_03_03_000002_*`); rows are invisible | `404 not-found` (binding finds nothing; renderer: `ApiExceptionRenderer.php:104-111`) |
| Company in the URL the caller is **not a member of** | `ResolveActiveCompany` scopes by membership (`ResolveActiveCompany.php:70-83`) | `404 company-not-available` (`NoCompanyAccess.php:26-33` — 404 by design, to prevent sibling-company enumeration) |
| Caller is a member of **no** company | `ResolveActiveCompany` | `403 no-company-membership` (`NoCompanyAccess.php:35-45`) |
| Member of the URL company but the **entity belongs to a sibling company** the caller is not a member of | Policy: `$user->canAccessCompany($entity->company_id)` (`CustomerPolicy.php:32-33`, `TaxCodePolicy.php:39-40`) | `403 forbidden` |
| Member of the URL company, **lacking the permission** | Policy `$user->can('sales…')` | `403 forbidden` |
| Body references an **account/branch of a sibling company** | Service checks: `CustomerService::resolveBranchId` (`CustomerService.php:412-431`), `resolveReceivableAccountId` (`:440-478`), `TaxCodeService::accountWithinCompany` (`TaxCodeService.php:579-594`) | `422 branch-outside-company` / `422 account-outside-company` |

One inherited property to be aware of (recorded in ADR 0008 §D6, not changed here): route-model
binding is not parent-scoped anywhere in the codebase, so a caller who is a member of **both**
companies A and B can address B's customer under A's URL; the policy passes because membership of
the *entity's* company is what it checks. This is exactly how `/companies/{company}/accounts/{account}`
behaves today (`AccountController.php:64-68`). Sales follows the platform pattern; fixing it is a
platform-wide decision, not a Sales fork.

### 2.3 Success and failure shapes

- Success: `{data, meta}` envelope via `ApiResponse` — `item` 200, `created` 201, `noContent` 204,
  paginated `collection` puts the paginator in `meta.pagination` (`ApiResponse.php:56-92`).
- Failure: RFC 9457 `application/problem+json` with a stable `type` code. Codes available to every
  endpoint below, from the renderer (`ApiExceptionRenderer.php:67-128`): `validation-failed` 422,
  `unauthenticated` 401, `forbidden` 403, `not-found` 404, `company-not-available` 404,
  `no-company-membership` 403, `business-rule-violation`-family 422, `duplicate-resource` /
  `resource-conflict`-family 409. Endpoint tables list only the codes *specific* to that endpoint.

---

## 3. Lane C — CustomerService hardening (no new API surface; gates Lane A)

All in `src/Core/Sales/Application/Services/CustomerService.php` and
`src/Core/Sales/Domain/Models/Customer.php`. Full decision rationale and the caller audit are in
ADR 0008 §D1–§D5; this section is the implementation contract.

### 3.1 I3 — `update()` takes an attribute array

New signature, replacing `update(Customer $customer, CustomerData $data)` (`CustomerService.php:81`):

```php
/**
 * @param array<string, mixed> $attributes  key absent = leave untouched; key present with
 *                                          null = clear (for nullable columns)
 */
public function update(Customer $customer, array $attributes): Customer
```

Recognised keys (anything else ignored, matching `TaxCodeService::update`, `TaxCodeService.php:87-89`):
`code`, `name`, `legal_name`, `tax_identification_number`, `vat_registration_number`,
`is_vat_registered`, `email`, `phone`, `website`, `address_line_1`, `address_line_2`, `city`,
`district`, `postal_code`, `country_code`, `payment_terms_days`, `credit_limit`,
`receivable_account_id`, `branch_id`, `notes`.

Per-key semantics (each rule is the current behaviour re-expressed for partial updates):

| Key | Present with value | Present with `null` | Absent |
| --- | --- | --- | --- |
| `code` | If normalised value differs from current: invoiced-lock check (`customer-code-locked`, current `CustomerService.php:84-97`), then `assertCodeAvailable` | Refused by request validation (a customer always has a code); service defensively hits `customer-code-blank` (`:355-361`) | untouched |
| `branch_id` | `resolveBranchId` (`:412-431`) | **clear** — set `null` (the behaviour I3 exists to make possible; today's `!== null` guard at `:99` cannot) | untouched |
| `receivable_account_id` | `resolveReceivableAccountId` (`:440-478`) | **clear** — back to the company's system AR default | untouched |
| `credit_limit` | `resolveCreditLimit` (`:322-350`) | **clear** — `null` = unlimited (column comment, migration `2026_03_02_000001:139`) | untouched |
| `is_vat_registered` / `vat_registration_number` | cross-rule evaluated on **effective values** (requested where present, else current): registered without a number → `vat-registration-number-required` (`:293-299`; DB CHECK `customers_vat_registration_check` backs it, migration `:132-136`) | number clearable only while effectively unregistered | untouched |
| `payment_terms_days` | negative → `negative-payment-terms` (`:301-306`); not nullable | refused by validation | untouched |
| `country_code` | uppercased on assignment (`:288`) | clear | untouched |
| plain nullable strings | assigned | clear | untouched |

Ordering (M8 applies): compute effective values and run **every** rule check *before* the first
assignment, then assign and save inside `DB::transaction` — the exact shape of
`ChartOfAccountsService::update()` (`ChartOfAccountsService.php:75-114`) and
`TaxCodeService::update()` (`TaxCodeService.php:97-171`).

`create()` keeps `CustomerData` unchanged — creation has no omitted-vs-null ambiguity
(`src/Core/Sales/Application/DTOs/CustomerData.php:30-31` states the same for tax codes). The DTO's
`fromArray()` (`CustomerData.php:51-75`) becomes the store-request mapping, as its docblock planned.

### 3.2 I4 — code-collision race returns 409

`generateCode()` (`CustomerService.php:384-404`) and `assertCodeAvailable()` (`:355-374`) are both
read-then-write; the losing side of a concurrent create currently surfaces
`UniqueConstraintViolationException` (a 500). Fix, following `TaxCodeService::save()`
(`TaxCodeService.php:310-338`) literally: route every `$customer->save()` in `create()`/`update()`
through a private `save(Customer $customer): Customer` that catches `QueryException`, matches the
constraint name `customers_company_code_unique` (migration `2026_03_02_000001:105-109`) in the
message, and rethrows `ResourceConflict::duplicate('customer', 'code', $customer->code)` — the same
`duplicate-resource` problem code the pre-check already produces (`:370`), because it is the same
conflict caught one layer later. Anything else rethrows unchanged. The constraint stays the
authority; only its refusal's shape changes.

### 3.3 M6 / M7 / M8

- **M6** — remove `payment_terms_days` and `credit_limit` from `Customer::$fillable`
  (`Customer.php:99-100`). Safe: the only mass-assignment writers of these columns are
  `CustomerFactory` (`database/factories/CustomerFactory.php:40-41,77-85`), and factories run
  unguarded (`Model::unguarded()` inside Laravel's `Factory::makeInstance`). Repo-wide grep confirms
  no other `fill()`/`create([...])` writer (ADR 0008 §D4).
- **M7** — `archive()` uses `bccomp($outstanding, '0', 4)` (`CustomerService.php:156`); replace the
  literal with `Money::SCALE` (`Money` already imported, `:9`).
- **M8** — `applyAttributes()` runs its two rule checks *after* assignment
  (`CustomerService.php:275-306`); hoist them above the first assignment so a rolled-back
  transaction leaves no invalid in-memory model. (For `update()` the rework in §3.1 satisfies M8 by
  construction.)

### 3.4 Lane C test changes

`tests/Feature/Sales/CustomerTest.php` — rewrite the six `update()` call sites (`:475`, `:488`,
`:500`, `:508`, `:515`, `:525`) to attribute arrays, and add: clear-vs-omit for `branch_id`,
`receivable_account_id`, `credit_limit` (both directions, per acceptance criterion); the VAT
cross-rule on effective values; the I4 conflict (unique-violation translated to
`ResourceConflict`, testable by rebinding or by racing two creates the way the restore test treats
the same shape, `CustomerService.php:210-213`); M8 (failed update leaves the in-memory model
unchanged).

---

## 4. Lane A — Customer REST API

### 4.1 Endpoints

All paths below are under `/api/v1/companies/{company}` and require membership of `{company}`
(middleware) plus the listed policy ability. Route-name prefix: `api.v1.companies.customers.`.

| Verb & path | Route name | Controller action | Authorization (in order) | Success |
| --- | --- | --- | --- | --- |
| GET `/customers` | `…index` | `index` | `view` on `Company`, `viewAny` on `Customer::class` (mirrors `JournalEntryController.php:39-40`) | 200 paginated collection |
| POST `/customers` | `…store` | `store` | `view` on `Company`, `create` on `Customer::class` (mirrors `AccountController.php:73-74`) | 201 item |
| GET `/customers/{customer}` | `…show` | `show` | `view` on the customer | 200 item |
| PUT `/customers/{customer}` | `…update` | `update` | `update` on the customer | 200 item |
| DELETE `/customers/{customer}` | `…destroy` | `destroy` | `delete` on the customer | 204 |
| POST `/customers/{customer}/archive` | `…archive` | `archive` | `archive` on the customer | 200 item |
| POST `/customers/{customer}/restore` | `…restore` | `restore` | `restore` on the customer; route declares `->withTrashed()` (the model soft-deletes; without it the binding 404s the very row being restored) | 200 item |
| POST `/customers/{customer}/deactivate` | `…deactivate` | `deactivate` | `update` on the customer — `CustomerPolicy` has no `deactivate`/`reactivate` methods, and `TaxCodePolicy` already defines these actions as delegations to `update` (`TaxCodePolicy.php:66-74`); the controller authorizes the ability that exists rather than adding policy surface (ADR 0008 §D3) | 200 item |
| POST `/customers/{customer}/reactivate` | `…reactivate` | `reactivate` | `update` on the customer | 200 item |

`DELETE` is not in the Gate 1 endpoint table but is included here: the table lists `/restore`,
`CustomerService::restore()` only operates on soft-deleted rows (`CustomerService.php:215-222`),
and without a producer of soft-deletes the restore endpoint would be unreachable dead surface.
Policy (`CustomerPolicy::delete`, `:59-63`), service (`CustomerService::delete`, `:184-198`) and
the Accounting precedent (`accounts` exposes `destroy`, `routes/api.php:336`) all exist.
**Flagged for explicit Gate 2 confirmation** (ADR 0008 §D2).

Each state action maps 1:1 onto the existing service method — `archive` (`CustomerService.php:152-175`),
`restore` (`:215-255`), `deactivate` (`:121-134`), `reactivate` (`:136-143`), `delete` (`:184-198`) —
so state transitions stay behind named methods, never a settable `status` field
(`CustomerData.php:14-16` records why).

**Index behaviour** (unbounded list → the `JournalEntryController::index` pattern, not the
unpaginated chart pattern): `QueryCriteria::fromRequest` with `sortable: ['name', 'code', 'created_at']`,
`defaultSort: 'name'`, `filterable: ['status', 'branch_id']`, plus `q` search ≥ 2 chars applied as
`ilike` over `name` and `code` — the two columns given trigram indexes for exactly this
(`2026_03_02_000001_create_customers_table.php:111-113`). Pagination via `paginate($criteria->perPage(),
page: …)->withQueryString()` with `meta.pagination` from the envelope. Soft-deleted rows are never
listed (SoftDeletes global scope); an unknown `filter[status]` value degrades to an empty result the
same way an unknown journal-entry status does today. An unsupported `sort` column is refused with
`unsupported-sort` 422 (`QueryCriteria.php:145-151`).

### 4.2 Form requests

`StoreCustomerRequest` — field ↔ `CustomerData::fromArray` (`CustomerData.php:51-75`) ↔ columns
(migration `2026_03_02_000001:49-94`). `prepareForValidation` trims `code` (as
`StoreAccountRequest.php:40-45`) and coerces a numeric `credit_limit` to string (as
`StoreJournalEntryRequest.php:74-92`).

```php
'name'                      => ['required', 'string', 'min:1', 'max:255'],
'code'                      => ['sometimes', 'nullable', 'string', 'min:1', 'max:32'], // omitted/null → service generates C-NNNN
'legal_name'                => ['sometimes', 'nullable', 'string', 'max:255'],
'tax_identification_number' => ['sometimes', 'nullable', 'string', 'max:64'],
'vat_registration_number'   => ['sometimes', 'nullable', 'string', 'max:64'],
'is_vat_registered'         => ['sometimes', 'boolean'],
'email'                     => ['sometimes', 'nullable', ...EmailAddress::syntax(), 'max:255'], // Platform rule, as ForgotPasswordRequest.php:26; syntax not deliverable — a customer record is not a login
'phone'                     => ['sometimes', 'nullable', 'string', 'max:32'],
'website'                   => ['sometimes', 'nullable', 'string', 'max:255'],
'address_line_1'            => ['sometimes', 'nullable', 'string', 'max:255'],
'address_line_2'            => ['sometimes', 'nullable', 'string', 'max:255'],
'city'                      => ['sometimes', 'nullable', 'string', 'max:96'],
'district'                  => ['sometimes', 'nullable', 'string', 'max:96'],
'postal_code'               => ['sometimes', 'nullable', 'string', 'max:24'],
'country_code'              => ['sometimes', 'nullable', 'string', 'size:2'],
'payment_terms_days'        => ['sometimes', 'integer', 'min:0', 'max:65535'], // unsignedSmallInteger bound
'credit_limit'              => ['sometimes', 'nullable', 'string', 'regex:/^-?\d{1,15}(\.\d{1,4})?$/'], // decimal string, journal-lines convention (StoreJournalEntryRequest.php:44); the minus is allowed through so the service's `negative-credit-limit` names the actual problem
'receivable_account_id'     => ['sometimes', 'nullable', 'uuid'], // ownership/type/postability checked by the service for the accurate error, as StoreAccountRequest.php:31-34 does for parent_id
'branch_id'                 => ['sometimes', 'nullable', 'uuid'],
'notes'                     => ['sometimes', 'nullable', 'string', 'max:5000'],
```

`status`, `archived_at`, `tenant_id`, `company_id`, `created_by_id` are **not accepted** — statuses
move only through the named action endpoints (same reasoning as `StoreAccountRequest`'s refusal of
`normal_balance`, `StoreAccountRequest.php:11-18`).

`UpdateCustomerRequest` — identical rules with every field `sometimes` and these deltas, so
`$request->validated()` is exactly the attribute array `CustomerService::update()` consumes
(present-key = change, `null` = clear, absent = untouched — the `UpdateAccountRequest` pattern,
`UpdateAccountRequest.php:11-17`):

- `name` → `['sometimes', 'string', 'min:1', 'max:255']` (not nullable),
- `code` → `['sometimes', 'string', 'min:1', 'max:32']` (not nullable — a code is never cleared),
- `payment_terms_days`, `is_vat_registered` — not nullable (columns are NOT NULL),
- everything else keeps `nullable` = clearable.

### 4.3 `CustomerResource`

`src/Core/Sales/Presentation/Http/Resources/CustomerResource.php`, `@mixin Customer`, shaped like
`AccountResource` (`AccountResource.php:19-57`):

```
id, company_id, branch_id, code, name, legal_name,
tax_identification_number, vat_registration_number, is_vat_registered,
email, phone, website,
address_line_1, address_line_2, city, district, postal_code, country_code,
payment_terms_days, credit_limit,            // decimal string or null; null = unlimited
receivable_account_id,                       // null = company system AR default
notes,
status (enum value), status_label,           // CustomerStatus::label() (CustomerStatus.php:24), as AccountResource pairs type/type_label
archived_at (ISO 8601 | null), deleted_at (ISO 8601 | null),
capabilities: {
    can_update:  policy 'update',
    can_delete:  policy 'delete',
    accepts_new_invoices: $this->acceptsNewInvoices(),   // model state, NOT a gate — Gate::before
                                                         // short-circuits owners (ROADMAP.md:192)
}
```

Not exposed: `tenant_id`, `created_by_id`, `created_at`/`updated_at` (matching `AccountResource`,
which exposes none of them). Nothing sensitive exists on the row beyond `credit_limit`/terms, which
are precisely what `sales.customers.view` grants sight of (`PermissionCatalogue.php:235`).

### 4.4 Endpoint-specific problem codes (from the service, all existing)

| Endpoint | 409 | 422 (beyond `validation-failed`) |
| --- | --- | --- |
| POST `/customers` | `duplicate-resource` (pre-check `CustomerService.php:370` **and** the I4 race, §3.2) | `customer-code-blank`, `vat-registration-number-required`, `negative-payment-terms`, `credit-limit-not-a-number`, `negative-credit-limit`, `branch-outside-company`, `account-outside-company`, `receivable-account-wrong-type`, `receivable-account-not-postable` |
| PUT `/customers/{customer}` | `duplicate-resource` | all of the above (minus blank-code generation path) plus `customer-code-locked` (`:86-94`) |
| POST `…/archive` | — | `customer-has-outstanding-balance` (`:157-165`) |
| POST `…/deactivate` | — | `customer-archived` (`:124-127`) |
| POST `…/restore` | `customer-code-taken-on-restore` (`:239-248`) | `customer-not-deleted` (`:218-221`) |
| DELETE `/customers/{customer}` | — | `customer-has-invoices` (`:187-194`) |

(Until Milestone 5 binds a real `ReceivableBalanceProbe`, `NoReceivables` makes the
invoice-dependent refusals inert — `SalesServiceProvider.php:40-52`. The endpoints wire them now so
they start biting when M5 lands, with no HTTP change.)

---

## 5. Lane B — Tax-code REST API

### 5.1 Endpoints

Route-name prefix `api.v1.companies.tax-codes.`; same middleware stack. Every action maps onto an
existing `TaxCodeService` method and an existing named `TaxCodePolicy` ability:

| Verb & path | Route name | Action | Policy ability | Service (`TaxCodeService.php`) | Success |
| --- | --- | --- | --- | --- | --- |
| GET `/tax-codes` | `…index` | `index` | `view` on `Company` + `viewAny` | — | 200 collection (unpaginated) |
| POST `/tax-codes` | `…store` | `store` | `view` on `Company` + `create` | `create` (`:48-77`) | 201 item |
| GET `/tax-codes/{taxCode}` | `…show` | `show` | `view` | — | 200 item |
| PUT `/tax-codes/{taxCode}` | `…update` | `update` | `update` | `update` (`:97-171`) — already attribute-array | 200 item |
| POST `/tax-codes/{taxCode}/end-range` | `…end-range` | `endRange` | `endRange` (`TaxCodePolicy.php:61-64`) | `endRange` (`:181-200`) | 200 item |
| POST `/tax-codes/{taxCode}/deactivate` | `…deactivate` | `deactivate` | `deactivate` | `deactivate` (`:208-220`) | 200 item |
| POST `/tax-codes/{taxCode}/reactivate` | `…reactivate` | `reactivate` | `reactivate` | `reactivate` (`:222-234`) | 200 item |
| DELETE `/tax-codes/{taxCode}` | `…destroy` | `destroy` | `delete` | `delete` (`:243-257`) | 204 |
| POST `/tax-codes/{taxCode}/restore` | `…restore` | `restore` | `restore` | `restore` (`:266-300`) | 200 item; route declares `->withTrashed()` |

**Index behaviour** — a company's tax codes are a bounded configuration catalogue like the chart of
accounts, so the `AccountController::index` pattern applies (unpaginated `get()`,
`meta: ['total' => …]`, `AccountController.php:40-61`): `active_only` boolean (default `true`,
matching `AccountController.php:42`; closed historical ranges usually remain `is_active` and stay
visible), optional `code` string filter via `scopeWithCode` (case-insensitive, newest range first —
`TaxCode.php:199-204`), otherwise ordered `code` asc, `effective_from` desc.

### 5.2 Form requests

`StoreTaxCodeRequest` — fields ↔ `TaxCodeData::fromArray` (`src/Core/Sales/Application/DTOs/TaxCodeData.php:59-80`)
↔ columns (migration `2026_03_03_000001:48-78`). `prepareForValidation` trims `code` and coerces a
numeric `rate` to string (journal-lines convention; the DTO's own `rateFrom` does the same at
`TaxCodeData.php:91-98`).

```php
'code'              => ['required', 'string', 'min:1', 'max:32'],
'name'              => ['required', 'string', 'min:1', 'max:255'],
'tax_type'          => ['required', 'string', Rule::in(TaxType::values())], // TaxType gains values(), copied from AccountType::values() (AccountType.php:103) — additive, Lane B owns it
'rate'              => ['required', 'string', 'regex:/^-?\d{1,3}(\.\d{1,4})?$/'], // a percentage at ledger scale; sign, 0–100 and zero-rate-type rules stay with the service so `negative-tax-rate` etc. name the actual problem
'effective_from'    => ['required', 'date'],
'effective_to'      => ['sometimes', 'nullable', 'date'],
'output_account_id' => ['sometimes', 'nullable', 'uuid'],
'input_account_id'  => ['sometimes', 'nullable', 'uuid'],
'notes'             => ['sometimes', 'nullable', 'string', 'max:5000'],
'is_active'         => ['sometimes', 'boolean'],
```

`UpdateTaxCodeRequest` — every field `sometimes`; `code`, `name`, `tax_type`, `rate`,
`effective_from`, `is_active` not nullable; `effective_to`, `output_account_id`,
`input_account_id`, `notes` keep `nullable`. `effective_to: null` **reopens the range** — the
omitted-vs-null distinction the service already implements (`TaxCodeService.php:121-127`);
`$request->validated()` passes straight through as the attribute array.

`EndTaxCodeRangeRequest` — `'last_effective_day' => ['required', 'date']`; the controller parses it
to `CarbonImmutable …->startOfDay()` for `endRange()`.

### 5.3 `TaxCodeResource`

```
id, company_id, code, name,
tax_type (enum value), tax_type_label (TaxType::label()),
rate,                                        // decimal string; A PERCENTAGE — 18.0000 means 18% (TaxCode.php:34-39)
output_account_id, input_account_id,
is_active,
effective_from (Y-m-d), effective_to (Y-m-d | null),
is_open_ended ($this->isOpenEnded()),
notes, deleted_at (ISO 8601 | null),
capabilities: {
    can_update: policy 'update',
    can_delete: policy 'delete',
    charges_tax: $this->chargesTax(),        // model state, not a gate (same Gate::before rule)
}
```

Not exposed: `tenant_id`, `created_by_id`, timestamps, `rateFactor()` (the percentage↔factor
conversion stays with the arithmetic that uses it — `TaxCode.php:152-170`).

### 5.4 Endpoint-specific problem codes (all existing in `TaxCodeService`)

| Endpoint | 409 | 422 (beyond `validation-failed`) |
| --- | --- | --- |
| POST `/tax-codes` | `tax-code-range-overlaps` (`:316-326`) | `tax-code-blank`, `tax-regime-not-supported`, `tax-rate-not-a-number`, `negative-tax-rate`, `tax-rate-above-one-hundred`, `zero-rate-type-with-rate`, `effective-range-inverted`, `output-account-required`, `output-account-wrong-type`, `input-account-wrong-type`, `account-outside-company`, `account-not-postable` |
| PUT `/tax-codes/{taxCode}` | `tax-code-range-overlaps`, `tax-rate-already-applied` (`:475-485`), `tax-rate-start-already-applied` (`:491-499`) | all store codes above |
| POST `…/end-range` | `tax-code-range-overlaps` | `range-ends-before-it-starts` (`:184-192`) |
| POST `…/deactivate` | — | `tax-code-already-inactive` (`:211-214`) |
| POST `…/reactivate` | — | `tax-code-already-active` (`:225-228`) |
| DELETE | — | `tax-code-in-use` (`:246-253`) |
| POST `…/restore` | `tax-code-range-taken-on-restore` (`:284-293`) | `tax-code-not-deleted` (`:269-272`) |

---

## 6. Wiring — the only shared files, and the exact edits

### 6.1 `routes/api.php` (shared: Lane A, Lane B, and nominally M5)

Two **additive** edits each, at *distinct, non-overlapping anchors*, so the hunks merge cleanly in
either landing order:

1. **Imports** (top block, alphabetical — between the `Organization` and `Settings` imports):
   - Lane A adds `use Asids\Core\Sales\Presentation\Http\Controllers\CustomerController;`
   - Lane B adds `use Asids\Core\Sales\Presentation\Http\Controllers\TaxCodeController;`

2. **Groups**, inside the existing `Route::prefix('companies')` group:
   - **Lane A anchor:** immediately **after** the closing `});` of the `{company}/reports` group
     (`routes/api.php:361-363`), before `{company}/members`. Opens a `── Sales ──` section comment
     mirroring the Accounting one (`routes/api.php:317-328`), stating that customers and tax codes
     are company-owned because the receivable and the tax configuration belong to one set of books.
   - **Lane B anchor:** immediately **before** the `{company}/members` group (`routes/api.php:365`)
     — i.e. directly after Lane A's block once both have landed.

```php
Route::prefix('{company}/customers')->name('customers.')->middleware('company')->group(function (): void {
    Route::get('/', [CustomerController::class, 'index'])->name('index');
    Route::post('/', [CustomerController::class, 'store'])->name('store');
    Route::get('{customer}', [CustomerController::class, 'show'])->name('show');
    Route::put('{customer}', [CustomerController::class, 'update'])->name('update');
    Route::delete('{customer}', [CustomerController::class, 'destroy'])->name('destroy');
    Route::post('{customer}/archive', [CustomerController::class, 'archive'])->name('archive');
    Route::post('{customer}/restore', [CustomerController::class, 'restore'])->name('restore')->withTrashed();
    Route::post('{customer}/deactivate', [CustomerController::class, 'deactivate'])->name('deactivate');
    Route::post('{customer}/reactivate', [CustomerController::class, 'reactivate'])->name('reactivate');
});

Route::prefix('{company}/tax-codes')->name('tax-codes.')->middleware('company')->group(function (): void {
    Route::get('/', [TaxCodeController::class, 'index'])->name('index');
    Route::post('/', [TaxCodeController::class, 'store'])->name('store');
    Route::get('{taxCode}', [TaxCodeController::class, 'show'])->name('show');
    Route::put('{taxCode}', [TaxCodeController::class, 'update'])->name('update');
    Route::delete('{taxCode}', [TaxCodeController::class, 'destroy'])->name('destroy');
    Route::post('{taxCode}/end-range', [TaxCodeController::class, 'endRange'])->name('end-range');
    Route::post('{taxCode}/deactivate', [TaxCodeController::class, 'deactivate'])->name('deactivate');
    Route::post('{taxCode}/reactivate', [TaxCodeController::class, 'reactivate'])->name('reactivate');
    Route::post('{taxCode}/restore', [TaxCodeController::class, 'restore'])->name('restore')->withTrashed();
});
```

`->withTrashed()` on the two restore routes is the first use in the codebase and is required:
`Customer` and `TaxCode` soft-delete, so without it implicit binding excludes the very row being
restored and the endpoint could only ever 404. (Accounting never met this: `Account` archives via
`is_active`, it does not soft-delete.)

### 6.2 `src/Core/Sales/Providers/SalesServiceProvider.php` — **no edit required**

Everything the HTTP layer needs is already registered: `CustomerService` (`:38`), `TaxCodeService`
(`:54`), both probes (`:52`, `:67`), and all three policies (`:76-78`). Controllers, form requests
and resources need no container registration. **Neither lane touches this file**, which reduces the
M5 contention surface from the two files the requirements assumed (`SALES-HTTP-API-REQUIREMENTS.md:78-79`)
to `routes/api.php` alone — and M5 has no route work in flight (invoice HTTP is out of scope for
everyone), so in practice the only route-file contention is *between our own two lanes*, managed by
the anchors above and the landing order in §8.

### 6.3 M5 collision protocol

- Never edit `SalesInvoiceService`, `SalesInvoice*`, `InvoiceTotalsCalculator`, `TaxRateResolver`,
  the probes' bindings, or anything under Akila's milestone.
- `routes/api.php` and `docs/api/openapi.yaml` edits are single-purpose, attributed commits
  (one per lane) so any M5 rebase is trivial.
- Lane C's `CustomerService` edits are the one place M4/M5 code could in principle be touched; the
  caller audit (ADR 0008 §D1) shows M4 never calls `CustomerService::update()`, and `create()`'s
  signature is unchanged, so the M4 test setups (`SalesInvoiceDraftTest.php:53` etc.) compile and
  pass untouched.

---

## 7. OpenAPI (`docs/api/openapi.yaml`) and keeping the checker green

`scripts/check-openapi.mjs` enforces: parseable YAML with resolving `$ref`s, unique `operationId`
per operation, declared tags, and **two-way route coverage** against `php artisan route:list`
(`check-openapi.mjs:127-178`). Consequence: **each lane's route commit and OpenAPI commit must be
the same commit**, or the branch has a red window in both directions.

Additions (style copied from the accounts section, `openapi.yaml:1988-2228`):

- **Tag** `Sales` (`description: Customers and the tax codes their invoices will charge.`) appended
  to the tag list after `Accounting` (`openapi.yaml:108-109`). Whichever lane lands first adds it;
  the other rebases onto it.
- **Parameters**: `CustomerId`, `TaxCodeId` in `components/parameters`, cloned from `AccountId`.
- **Schemas**: `Customer`, `CustomerRequest`, `TaxCode`, `TaxCodeRequest` in `components/schemas`
  (field-for-field with §4.3 / §5.3 / the request rules; `capabilities` included). Reuse the shared
  `Problem` schema and `Forbidden` / `NotFound` / `ValidationFailed` responses.
- **Responses**: `CustomerResponse`, `TaxCodeResponse` in `components/responses`, cloned from
  `AccountResponse`.
- **Paths** (operationIds follow the existing camelCase verb style, `openapi.yaml:2109-2175`):
  - Lane A — `/companies/{companyId}/customers` (get `listCustomers`, post `createCustomer`),
    `/customers/{customerId}` (get `getCustomer`, put `updateCustomer`, delete `deleteCustomer`),
    `/archive` `archiveCustomer`, `/restore` `restoreCustomer`, `/deactivate` `deactivateCustomer`,
    `/reactivate` `reactivateCustomer`. **Anchor: immediately after the
    `/companies/{companyId}/reports/trial-balance` section (`openapi.yaml:2623`).**
  - Lane B — `/companies/{companyId}/tax-codes` (get `listTaxCodes`, post `createTaxCode`),
    `/tax-codes/{taxCodeId}` (get `getTaxCode`, put `updateTaxCode`, delete `deleteTaxCode`),
    `/end-range` `endTaxCodeRange`, `/deactivate` `deactivateTaxCode`, `/reactivate`
    `reactivateTaxCode`, `/restore` `restoreTaxCode`. **Anchor: end of the `paths:` map.**
  - 409 responses documented inline with the `Problem` schema on the operations that can produce
    them (store/update/restore/end-range), as `deleteAccount` documents its 422
    (`openapi.yaml:2143-2147`).

Verification per lane commit: `node scripts/check-openapi.mjs --require-routes` (with PHP
available, so the route comparison actually runs — a skip is a fail in CI, `check-openapi.mjs:19-22`).

---

## 8. Build plan and file ownership (two engineers, no shared edits)

**Backend Engineer 1 (BE-1): Lane C then Lane A. Backend Engineer 2 (BE-2): Lane B, starting
immediately.** Lane B never waits on C; Lane A starts only when C is merged. While BE-1 is inside
Lane C (which touches no shared file), BE-2 lands the two shared-file hunks for tax codes — so the
shared files are edited serially without anyone idling.

### File ownership matrix

| File | Owner | Nature |
| --- | --- | --- |
| `src/Core/Sales/Application/Services/CustomerService.php` | BE-1 (Lane C) | modify (I3/I4/M7/M8) |
| `src/Core/Sales/Domain/Models/Customer.php` | BE-1 (Lane C) | modify (M6 `$fillable`) |
| `tests/Feature/Sales/CustomerTest.php` | BE-1 (Lane C) | modify (§3.4) |
| `src/Core/Sales/Presentation/Http/Controllers/CustomerController.php` | BE-1 (Lane A) | create |
| `src/Core/Sales/Presentation/Http/Requests/StoreCustomerRequest.php`, `UpdateCustomerRequest.php` | BE-1 (Lane A) | create |
| `src/Core/Sales/Presentation/Http/Resources/CustomerResource.php` | BE-1 (Lane A) | create |
| `tests/Feature/Sales/CustomerApiTest.php` | BE-1 (Lane A) | create |
| `src/Core/Sales/Presentation/Http/Controllers/TaxCodeController.php` | BE-2 (Lane B) | create |
| `src/Core/Sales/Presentation/Http/Requests/StoreTaxCodeRequest.php`, `UpdateTaxCodeRequest.php`, `EndTaxCodeRangeRequest.php` | BE-2 (Lane B) | create |
| `src/Core/Sales/Presentation/Http/Resources/TaxCodeResource.php` | BE-2 (Lane B) | create |
| `src/Core/Sales/Domain/Enums/TaxType.php` | BE-2 (Lane B) | modify (additive `values()` helper, §5.2) |
| `tests/Feature/Sales/TaxCodeApiTest.php` | BE-2 (Lane B) | create |
| `routes/api.php` | **shared, serialized** | BE-2 hunk first (import + tax-codes group at its anchor); BE-1 hunk second (import + customers group at its anchor) |
| `docs/api/openapi.yaml` | **shared, serialized** | same order; first lander adds the `Sales` tag |
| `src/Core/Sales/Providers/SalesServiceProvider.php` | **nobody** | no change needed (§6.2) |

Cross-lane consumers, named up front: Lane A's controller consumes Lane C's new
`update(Customer, array): Customer` and the unchanged `create(Company, CustomerData, ?string)`.
Lane B consumes nothing from C or A. Nothing consumes A or B.

### Task sequence (each step test-first; commit at every green checkpoint)

**Lane C (BE-1)** — order matters within the lane:
1. Rewrite the six `CustomerTest` update call-sites to arrays *plus* new failing tests for
   clear-vs-omit (branch, receivable account, credit limit), effective-value VAT rule, M8
   no-dirty-model-on-failure → implement `update(Customer, array)` per §3.1 → green → commit
   (`refactor(sales)!: CustomerService::update takes an attribute array (I3)` — internal API, no
   HTTP caller yet, so the break is contained to this commit's own test edits).
2. Failing test for the I4 conflict → private `save()` translation per §3.2 → commit.
3. M6 + M7 + M8-in-create, with the M6 regression test (mass assignment of `credit_limit` is
   discarded/refused under `Model::shouldBeStrict()`) → commit.
4. Full gates: `composer lint && composer analyse && composer test` (+ `test:coverage` ≥ 85).

**Lane A (BE-1, after C merges)**:
5. Requests + Resource + Controller + routes hunk + OpenAPI hunk, driven by
   `tests/Feature/Sales/CustomerApiTest.php` (§9), routes+spec in one commit; checker with
   `--require-routes` green; full gates.

**Lane B (BE-2, immediately)**:
6. `TaxType::values()` + Requests + Resource + Controller + routes hunk + OpenAPI hunk (adds the
   `Sales` tag), driven by `tests/Feature/Sales/TaxCodeApiTest.php`; routes+spec one commit;
   checker green; full gates.

Delivery ends at the PR for Akila's review (`SALES-HTTP-API-REQUIREMENTS.md:80-81`); Gate 3 is not
reached.

---

## 9. Test plan — acceptance-criteria mapping

Both API suites copy the `AccountingApiTest` harness (`AccountingApiTest.php:31-105`): two
workspaces (`acme`, `globex`), owner + `accountant` + `bookkeeper` + `viewer` with memberships, an
`asSales()` request helper, `companyUri()`, and the `toBeEnvelope` / `toBeProblem` expectations
(`tests/Pest.php:32-65`).

| Acceptance criterion (requirements §3) | Proof |
| --- | --- |
| Permission enforced per endpoint, 403 problem | viewer 403 on every write; accountant 200; bookkeeper per role template; `toBeProblem('forbidden', 403)` |
| Tenant isolation (RLS) | index never returns globex ids (the `AccountingApiTest.php:196-206` shape); direct GET of a globex customer/tax-code id → 404 |
| Sibling-company isolation | second acme company without membership: URL-company non-membership → 404 `company-not-available`; membership of URL company but entity of non-member sibling → 403 `forbidden` |
| Validation → problem documents, no leaked framework errors | invented enum value → `toBeProblem('validation-failed', 422)`; unsupported sort → `unsupported-sort` |
| I3 both directions | PUT with `branch_id: null` / `receivable_account_id: null` / `credit_limit: null` clears; PUT omitting them leaves values intact — asserted on the same customer |
| I4 → 409 not 500 | service-level conflict test (Lane C) + HTTP duplicate-code create → `toBeProblem('duplicate-resource', 409)` |
| Route names | `route('api.v1.companies.customers.index', …)` resolves in-test |
| Lane B lifecycle | end-range → successor create; reopen via `effective_to: null`; overlap → 409 `tax-code-range-overlaps`; applied-rate immutability → 409 `tax-rate-already-applied` (probe swap, the `withReceivables()` pattern from `CustomerTest.php:49-70`); delete/restore incl. `withTrashed` binding proof |
| OpenAPI | `node scripts/check-openapi.mjs --require-routes` in CI |
| Coverage / static gates | `composer test:coverage` (≥ 85, `composer.json:66`), `composer lint`, `composer analyse` (PHPStan L8) |

---

## 10. Explicitly out of scope

No invoice endpoints, no issuing, no ledger posting, no numbering, no AR reports, no front end.
No new permissions, no policy rewrites, no changes to `SalesInvoice*`, `TaxRateResolver`,
`InvoiceTotalsCalculator`, or either probe binding. Nothing lands on `main`.
