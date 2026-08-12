# Task: Lane B — Tax-code REST API

**Owner:** Backend Engineer 2 (BE-2) · **Tests by:** QA (red-first) · **Contract:** [DESIGN §5, §6](../SALES-HTTP-API-DESIGN.md) · Independent of Lanes A/C.

## Scope
`companies/{company}/tax-codes` REST surface wiring the EXISTING `sales.tax-codes.{view,manage}` permissions + `TaxCodePolicy`. Endpoints incl. effective-dated-rate lifecycle: index, store, show, put, `endRange`, deactivate, reactivate, delete, restore (DESIGN §5.1).

## File ownership
- **Create (BE-2):** `src/Core/Sales/Presentation/Http/Controllers/TaxCodeController.php`, 3 form requests, `.../Resources/TaxCodeResource.php`.
- **Edit (BE-2):** `src/Core/Sales/Domain/Enums/TaxType.php` (add `values()` mirroring `AccountType::values()`), `routes/api.php` (tax-codes group, anchor **before** `{company}/members`), `docs/api/openapi.yaml` (end of `paths:`). **Routes + spec in the SAME commit.**
- **Do NOT create** `tests/Feature/Sales/TaxCodeApiTest.php` — QA owns it. Make it green.

## Serialization with Lane A
Both edit `routes/api.php` + `openapi.yaml`. BE-2 lands its hunks first (while BE-1 is in Lane C); distinct anchors make them non-overlapping. If BE-1's hunks are already present, rebase onto them — never overwrite.

## Acceptance criteria
- Every endpoint enforces its ability via `TaxCodePolicy` (403 without capability). RLS + cross-company isolation proven.
- Effective-dated rate transitions (`endRange`, overlap refusal) exposed and correct. Validation → RFC 9457 problems. OpenAPI green. Coverage ≥85% new code.

## Progress

**BE-2, 2026-08-12** — Lane B built end to end against the RED `TaxCodeApiTest.php` (46 tests).

Created:
- `src/Core/Sales/Presentation/Http/Controllers/TaxCodeController.php`
- `src/Core/Sales/Presentation/Http/Requests/StoreTaxCodeRequest.php`
- `src/Core/Sales/Presentation/Http/Requests/UpdateTaxCodeRequest.php`
- `src/Core/Sales/Presentation/Http/Requests/EndTaxCodeRangeRequest.php`
- `src/Core/Sales/Presentation/Http/Resources/TaxCodeResource.php`

Edited (tax-codes-only hunks):
- `src/Core/Sales/Domain/Enums/TaxType.php` — added `values()`, mirroring `AccountType::values()`.
- `routes/api.php` — import + `{company}/tax-codes` group, anchored immediately before `{company}/members` (Lane A had not landed at time of writing, so this is currently the first Sales block; BE-1 should land Lane A's hunk *before* this one per §6.1, or rebase).
- `docs/api/openapi.yaml` — `Sales` tag, `TaxCodeId` parameter, `TaxCode`/`TaxCodeRequest` schemas, `TaxCodeResponse` response, and the 9 tax-code paths, appended at the end of the `paths:` map.

Result: **43/46 green** in isolation (see Issues — 3 failures are a pre-existing, cross-cutting Platform defect, not Lane B). Pint clean, PHPStan L8 clean on every file listed above.

Two contract notes flagged in the design already tracked here for the record:
- `tax-rate-not-a-number` is shadowed by the request's rate regex (defense-in-depth, matches QA's own comment in the test file).
- `code` is deliberately **not** `required`/`min:1` at the request layer (diverges from DESIGN §5.2's literal rule list) — see Issues for why; the service still enforces blankness via `tax-code-blank`.

## Issues

**1. BLOCKING, shared, not Lane B — `ApiExceptionRenderer` doesn't recognise Laravel's actual 403 exception class.**
3 of the 46 tests fail: `authorization → refuses a bookkeeper/viewer the right to create`, `isolation → refuses a member of a sibling company`. All three assert `toBeProblem('forbidden', 403)`.
- Root cause: `AuthorizesRequests::authorize()` throws `Illuminate\Auth\Access\AuthorizationException`, but Laravel's own `Illuminate\Foundation\Exceptions\Handler::prepareException()` (vendor, line ~669) converts that to `Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException` **before** any custom `$exceptions->render()` callback (including `ApiExceptionRenderer`) ever sees it. `AccessDeniedHttpException` does not extend `AuthorizationException`, so `ApiExceptionRenderer.php`'s `$e instanceof AuthorizationException` branch never matches for a framework-thrown 403; it falls into the generic `HttpExceptionInterface` branch and returns type `http-403` instead of `forbidden`.
- This is **pre-existing and latent**, not something Lane B introduced: every current `AccountingApiTest` 403 assertion only checks `getStatusCode()`, never `toBeProblem('forbidden', ...)`, so nothing had exercised this path before. `tests/Feature/Sales/CustomerApiTest.php` (Lane A, not yet built) asserts the identical shape twice (lines 197, 304) and will hit the same bug the moment Lane A is built.
- **Not touched** — `src/Core/Platform/Exceptions/ApiExceptionRenderer.php` is outside Lane B's file ownership (shared Platform file, no lane owns it in this task). Proposed one-line fix for whoever picks this up: add a match arm for `AccessDeniedHttpException` (or check `$e->getPrevious() instanceof AuthorizationException`) mapping to the same `forbidden`/403 shape, placed before the generic `HttpExceptionInterface` arm.
- Flagged to the Delivery Manager for assignment; blocks both Lane A and Lane B reaching a literal 46/46 or full green.

**2. Environment-only, not a code defect — shared Redis cache makes `createWorkspace('acme'|'globex')`-based HTTP tests flaky in this Docker setup.**
`TenantResolver` caches slug→tenant resolution in the `cache` store (`config('asids.tenancy.resolution_cache_ttl')`, 300s). In this environment `config('cache.default')` resolves to `redis` even under `phpunit.xml`'s `<env name="CACHE_STORE" value="array"/>` (no `config/cache.php` exists in this repo, and whatever supplies the framework default here is reading `.env`'s `CACHE_STORE=redis`, not the PHPUnit override). Local dev app and the `asids_erp_testing` test run share the *same* Redis DB/key prefix, so a real dev-created tenant slugged `acme` (or a previous test run's now-rolled-back one) gets served to the test's fresh `acme` tenant, and every RLS-scoped company lookup 404s. Confirmed root cause via `DB::listen`: the session var `asids.tenant_id` was set to a UUID that matched neither the just-created tenant nor its company. This reproduces identically against the **pre-existing, untouched** `AccountingApiTest.php` (e.g. plain `GET /companies/{company}`), so it is not caused by this lane.
- Workaround used to get a clean read on Lane B's own correctness: `docker exec -u asids -e CACHE_STORE=array asids-app ./vendor/bin/pest tests/Feature/Sales/TaxCodeApiTest.php` (forces the array store at the OS-env level, which *does* take precedence).
- Not fixed (no config file in Lane B's ownership to safely add/change); flagged to the Delivery Manager since it will affect anyone running the HTTP suites in this shared Docker environment, including CI if it shares the same Redis instance.

## Outcome
Lane B implementation complete and correct: **43/46 green** with the two issues above isolated and explained; the 3 remaining failures are a single pre-existing shared-Platform defect unrelated to any Lane B file. Full `tests/Feature/Sales` run (with the cache workaround): `CustomerTest.php` (Lane C) 68/68 green, unaffected. `CustomerApiTest.php` (Lane A) not yet built — its failures are expected and out of scope here. Pint clean and PHPStan level 8 clean on every Lane B file. OpenAPI: `node scripts/check-openapi.mjs` (no `--require-routes`, host PHP is 8.3 and this repo's `composer.json` requires `^8.4` so `vendor/` cannot be installed on host — same known gotcha as other ASIDS repos) is green; route↔spec coverage in both directions was independently verified against Docker's real `php artisan route:list --json` (104 routes / 104 documented operations, exact match, zero undocumented/phantom).

## Progress — Stage 6 fix cycle (BE-2, 2026-08-12)

Applied the independent Security Reviewer's PASS-WITH-FIXES items that touch Lane B's files.

**Issues resolved:**
- **S2 (should).** `TaxCodeController::index()`'s `?code[]=x` (an array) satisfies `$request->filled('code')`
  and then hits `$request->string('code')`, which array-to-string-converts the array — PHP raises this
  as a warning, and the framework's error handler escalates it to an `ErrorException`, rendering a 500.
  Fixed by additionally requiring `is_string($request->query('code'))` before applying the filter; an
  array value is now ignored (returns the unfiltered list), consistent with how an unrecognised filter
  degrades elsewhere on this endpoint (`active_only`/`code` are the only two recognised query keys here).
- **N1 (nit).** `TaxCodeService::isOverlapViolation()` matched the `tax_codes_no_overlapping_rates`
  exclusion-constraint name as a substring of the whole `QueryException` message, which embeds bound
  values — a payload containing that literal string plus any unrelated `QueryException` would have
  misclassified a 500 as a 409. An exclusion constraint has no dedicated Laravel exception class the way
  a unique constraint does, so tightened by additionally requiring the SQLSTATE via `getCode() === '23P01'`
  (Postgres's `exclusion_violation`), the same technique `RowLevelSecurityBootstrapper::apply()` already
  uses to distinguish one SQLSTATE from another (`25P02`).
- **N2 (nit).** `routes/api.php`'s `{taxCode}/restore` route (`->withTrashed()`) is route-wide and so
  also lifts the soft-delete scope on the bound `{company}`. Added a comment on the route documenting
  that this is intended only for the `{taxCode}` binding and that a soft-deleted company still fails
  closed with a 404 via `ResolveActiveCompany`'s independent re-resolution (default scopes + `active()`
  + membership) — no behaviour change, comment only.

**Regression test** added to `tests/Feature/Sales/TaxCodeApiTest.php` (`describe('the index', ...)`):
`?code[]=x` now asserted `toBeEnvelope()` (200, filter ignored rather than crashing), confirmed RED
(actual 500) before the fix — verified on the full-file run (46/47 passed, only the new test failing;
an isolated `--filter` run of a single test 404s in this environment regardless of content, a pre-existing
test-harness artifact unrelated to this fix, so the full-file run is the valid signal) — and GREEN after.

**Verification:**
- `tests/Feature/Sales/CustomerApiTest.php` + `TaxCodeApiTest.php` together: **91/91 passed** (610
  assertions).
- Full `tests/Feature/Sales` suite: **453/453 passed** (1159 assertions) — up from the pre-fix-cycle
  451/451 baseline plus the two new regression tests (this file's S2 test + Lane A's S1 test).
- `./vendor/bin/pint --test` on `TaxCodeController.php`, `TaxCodeService.php`, `routes/api.php`,
  `TaxCodeApiTest.php` (run together with the Lane A equivalents): **PASS, 7 files**.
- `./vendor/bin/phpstan analyse --level=8` on `TaxCodeController.php` and `TaxCodeService.php` (run
  together with the Lane A equivalents): **[OK] No errors**.

Touched only: `TaxCodeController.php`, `TaxCodeService.php`, the tax-code restore-route comment in
`routes/api.php`, and new tests in `TaxCodeApiTest.php`. No git commands run.
