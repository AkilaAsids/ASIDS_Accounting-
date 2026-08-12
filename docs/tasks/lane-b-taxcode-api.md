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
_(BE-2 appends)_

## Issues
_(none yet)_

## Outcome
_(filled at close)_
