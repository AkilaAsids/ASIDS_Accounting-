# ADR 0019 — Bills (purchase invoices), payable posting, and the first input-VAT use

- **Status:** Proposed — for Gate 2 (human) review
- **Author:** Solution Architect (Stage 3, Phase 5 / Wave 7)
- **Date:** 2026-09-01
- **Branch:** `feature/phase5-bills` (stacked on `feature/phase5-suppliers`, Wave 6 — shipped)
- **Supersedes / extends:** nothing. Extends the Purchasing bounded context of
  `docs/adr/0018-purchasing-supplier-domain-foundation.md` and mirrors the sales-invoice domain
  (`docs/adr/0007`, `docs/adr/0009`).
- **Requirements:** `docs/PHASE-5-BILLS-REQUIREMENTS.md` (Gate 1 decisions — APPROVED 2026-09-01).

## How to read this record

Every point is labelled **(Gate-1 APPROVED)** — binding, decided by the human on 2026-09-01;
**(Gate-2 PROPOSED)** — my design realisation, to confirm before build; or **(UNRESOLVED — needs human
approval)** — a fork I will not decide silently. Nothing here is built until Gate 2 approves this ADR,
which the build then follows verbatim.

A bill is the **payable-side mirror of a sales invoice**. The whole slice is written as "mirror X, swap
Dr/Cr, cite the line" and the sales originals are the review checklist. The load-bearing citations:

- `src/Core/Sales/Domain/Models/SalesInvoice.php`, `SalesInvoiceLine.php`,
  `src/Core/Sales/Domain/Enums/SalesInvoiceStatus.php`
- `src/Core/Sales/Application/Services/SalesInvoiceService.php` (createDraft/updateDraft/deleteDraft/**issue**),
  `InvoicePostingMap.php`
- `src/Core/Sales/Database/Migrations/2026_03_04_000001…` (header), `…000002…` (lines),
  `…000003…` (RLS), `2026_03_05_000001…` (total invariant), `2026_03_05_000002…` (immutability),
  `src/Core/Accounting/Database/Migrations/2026_03_05_000003_stamp_trade_receivables_system_key.php`
- `src/Core/Sales/Infrastructure/EloquentReceivableBalanceProbe.php`,
  `src/Core/Purchasing/Domain/Contracts/PayableBalanceProbe.php`,
  `src/Core/Purchasing/Infrastructure/NoPayables.php`,
  `src/Core/Purchasing/Providers/PurchasingServiceProvider.php`

## Context

Two things make this wave structurally significant, not another CRUD slice:

1. **First purchasing document that posts to the ledger.** Wave 6 shipped supplier master data with a
   **dormant** `PayableBalanceProbe` bound to `NoPayables` (`PurchasingServiceProvider.php:38`) and three
   inert `SupplierService` rules (archive-with-balance `SupplierService.php:217-240`, delete-with-bill
   `:249-263`, code-lock-with-bill `:98-115`). This wave posts and flips that binding, so those rules
   start to bite for every existing supplier (Gate-1 decision 6).
2. **First real use of `tax_codes.input_account_id`** (`TaxCode.php:100-111`) — documented as "unused by
   sales and populated only by a company that has configured it ahead of the purchasing phase." This is
   that phase, and the first production path that can fail because a code has no input account.

The posting is the sales-invoice posting with debits and credits swapped:

```
Sales invoice (InvoicePostingMap.php:23-31):     Bill (this ADR, §D):
  Dr Trade Receivables      (total)                Dr Expense           (per line, by account)
    Cr Sales Revenue        (per account)          Dr Input VAT         (Σ, by input_account_id)
    Cr Output VAT Payable   (Σ, by output acct)      Cr Trade Payables  (total)
```

## Binding Gate-1 decisions (verbatim from `docs/PHASE-5-BILLS-REQUIREMENTS.md` → Gate 1 — APPROVED)

1. Capture the supplier's own invoice number (**REQUIRED** — statutory identity + duplicate-guard key)
   **plus** an internal bill reference (**need not be gapless** — bills are received, not issued). The
   ledger entry still draws a gapless `JV` (ADR 0009 §B two-series preserved). **(Gate-1 APPROVED)**
2. **Cancellation/reversal DEFERRED** — draft + post only. Prepare the structural boundary (status can
   gain `cancelled` later); do not build reversal. **(Gate-1 APPROVED)**
3. **AP = system `Account::TRADE_PAYABLES` (`2110 Trade Payables`)**; per-supplier override deferred.
   **(Gate-1 APPROVED)**
4. **Expense = line-level** (mirror the invoice line's revenue account); supplier default deferred.
   **(Gate-1 APPROVED)**
5. **Duplicate supplier-invoice-number = REFUSE**, per supplier per company. **(Gate-1 APPROVED)**
6. **Bind the real `EloquentPayableBalanceProbe`** this wave; needs a blast-radius test. **(Gate-1 APPROVED)**

---

# A. Schema — `bills` + `bill_lines`, and the Trade Payables system account

## A0. `Account::TRADE_PAYABLES` + chart template + backfill (Gate-1 APPROVED dec. 3; realisation Gate-2 PROPOSED)

The payable posting resolves its credit account **by system key**, never by code, exactly as the
receivable side does (`InvoicePostingMap.php:97-114`, `Account::TRADE_RECEIVABLES` at `Account.php:71`)
— because a company may renumber its chart freely. `Account` today has `RETAINED_EARNINGS`,
`OPENING_BALANCE_EQUITY`, `TRADE_RECEIVABLES` but **no `TRADE_PAYABLES`** (`Account.php:60-71`), so it is
part of this ADR.

- **A0.1 — Add the constant.** `public const string TRADE_PAYABLES = 'trade_payables';` in `Account.php`,
  with the same docblock reasoning as `TRADE_RECEIVABLES`. **(Gate-2 PROPOSED)**
- **A0.2 — Stamp the template leaf.** `ChartTemplate.php:98` already defines `2110 Trade Payables` as a
  postable liability leaf **without** a system key. Add `system: Account::TRADE_PAYABLES` to it (mirror
  `1130` at `:82`), and add `2110 Trade Payables` to `requiredSystemAccounts()` (`:157-166`, mirror the
  `1130` entry at `:164`) so a company provisioned from an empty chart still receives it. Bump
  `ChartTemplate::VERSION` (`:40`) `2026.08-lk-sme-2` → **`2026.09-lk-sme-3`**. **(Gate-2 PROPOSED)**
- **A0.3 — Backfill existing companies.** Migration
  `src/Core/Accounting/Database/Migrations/2026_09_02_000001_stamp_trade_payables_system_key.php`, a
  near-verbatim mirror of `2026_03_05_000003_stamp_trade_receivables_system_key.php`, stamping keyless
  `2110` liability accounts. It **must** reproduce that migration's two hard-won lessons
  (`2026_03_05_000003…:47-66`): set **`is_system = true`** alongside the key
  (`accounts_system_key_check` asserts `(system_key IS NOT NULL) <= is_system`), and run inside
  `RowLevelSecurity::bypass()` with **`assertBypassEffective()`** and **`assertNothingLeftBehind()`** so a
  NOBYPASSRLS role on a FORCED table cannot silently stamp zero rows. Guards: `system_key IS NULL`,
  `code = '2110'`, `type = 'liability'`, `deleted_at IS NULL`, no sibling already holds the key, and
  `template_version IN ('2026.02-lk-sme-1','2026.08-lk-sme-2')` — **both** prior versions, because unlike
  `1130` (keyed at provisioning by the current template) neither existing version stamped `2110`.
  **(Gate-2 PROPOSED)**

**Input VAT is deliberately *not* a system key.** The input-VAT debit resolves through
`tax_codes.input_account_id` (a per-code setting, `TaxCode.php:108-111`), exactly as output VAT resolves
through `output_account_id` — and output VAT's account (`2140`) carries no system key. So `1170 Input VAT
Recoverable` (`ChartTemplate.php:88`) is left keyless; only `2110` gains a key. This keeps input and
output VAT symmetric. **(Gate-2 PROPOSED)**

## A1. Migration files (Gate-2 PROPOSED)

Mirroring the sales module's file-per-concern split (a new migration never edits an applied one — the gap
would be invisible, `2026_03_04_000003…:11-19`). Filename prefixes adjustable at Gate 2.

| File | Mirrors |
| --- | --- |
| `src/Core/Accounting/Database/Migrations/2026_09_02_000001_stamp_trade_payables_system_key.php` | `2026_03_05_000003_stamp_trade_receivables_system_key.php` |
| `src/Core/Purchasing/Database/Migrations/2026_09_02_000002_create_bills_table.php` | `2026_03_04_000001_create_sales_invoices_table.php` + `2026_03_05_000001` (total invariant folded in) |
| `src/Core/Purchasing/Database/Migrations/2026_09_02_000003_create_bill_lines_table.php` | `2026_03_04_000002_create_sales_invoice_lines_table.php` |
| `src/Core/Purchasing/Database/Migrations/2026_09_02_000004_enable_row_level_security_on_bills.php` | `2026_03_04_000003_enable_row_level_security_on_sales_invoices.php` |
| `src/Core/Purchasing/Database/Migrations/2026_09_02_000005_make_posted_bills_immutable.php` | `2026_03_05_000002_make_issued_invoices_immutable.php` |

## A2. `bills` header columns (Gate-2 PROPOSED, realising Gate-1 dec. 1, 2, 3)

A field-by-field mirror of `sales_invoices` (`2026_03_04_000001…:29-105`), with these deliberate
departures — each flagged because it is where a payable differs from a receivable:

| Column | Type / rule | Mirror / departure |
| --- | --- | --- |
| `id`, `tenant_id`, `company_id`, `branch_id` | uuid; FK cascade (tenant/company), `nullOnDelete` (branch) | mirror `:30-41` |
| **`supplier_id`** | `foreignUuid`→`suppliers`, **`restrictOnDelete`** | mirror `customer_id` `:46`; this FK is the guarantee behind `SupplierService::delete()` refusing a billed supplier |
| **`supplier_invoice_number`** | `string(120)`, **`NOT NULL`** | **DEPARTURE.** No sales analogue. Required at draft (the duplicate-guard key, §A4). A supplier assigns it; we do not. |
| **`number`** | `string(40)`, **nullable** | mirror `sales_invoices.number` `:52`. The **internal** bill number `BILL-…`, null while draft, assigned at post (§B). "Internal bill reference" of Gate-1 dec. 1. |
| **`bill_date`** | `date`, NOT NULL | mirror `invoice_date` `:60` — the supplier's invoice date = the tax point; drives tax-rate resolution and fiscal period |
| `due_date` | `date`, NOT NULL | mirror `:62`; derived from `Supplier::dueDateFor()` (`Supplier.php:141-144`) |
| `currency_code` | `char(3)` | mirror `:65` |
| `exchange_rate` | `decimal(19,10)` nullable | mirror `:68`; held null by phase CHECK |
| `subtotal`,`discount_total`,`tax_total`,`total` | `decimal(19,4)` default 0 | mirror `:73-76` |
| `amount_paid`,`amount_due` | `decimal(19,4)` default 0 | mirror `:79-80`; held at zero by phase CHECK this wave (Wave 8 owns them) |
| `status` | `string(16)` default `'draft'` | mirror `:83`; enum §C1 |
| **`posted_at`** | `timestampTz` nullable | mirror `issued_at` `:87` |
| **`posted_by_id`** | FK→`users` `nullOnDelete` | mirror `issued_by_id` `:88` |
| `journal_entry_id` | FK→`journal_entries` **`unique`** `restrictOnDelete`, nullable | mirror `:94` — the database-level guard against posting twice |
| `notes`, `terms`, `created_by_id`, timestamps | mirror `:96-101` | |

**No `reference` free-text column, no `deleted_at`.** Sales' `reference` holds "the customer's own
reference" (`:54-55`); on a bill the counterparty's reference *is* `supplier_invoice_number`, captured
explicitly — a third free-text identifier would be redundant. No soft-delete, mirroring sales
(`2026_03_04_000001…:17-24`): a draft is hard-deleted, a posted bill is a statutory record and cannot be
removed. **(Gate-2 PROPOSED)**

**No cancellation columns this wave.** `cancelled_at` / `cancellation_reason` / `cancelled_by_id` are
**not** added now, mirroring the sales build order — sales added them in a *later* migration
(`2026_03_06_000001…`) with the cancellation feature, not at draft/post. The `status` CHECK reserving
`'cancelled'` (§A4) is the whole of the "prepare the boundary" work Gate-1 dec. 2 asks for.
**(Gate-1 APPROVED dec. 2; realisation Gate-2 PROPOSED)**

## A3. `bill_lines` columns (Gate-2 PROPOSED, realising Gate-1 dec. 4)

A field-by-field mirror of `sales_invoice_lines` (`2026_03_04_000002…:32-87`) with one departure:

| Column | Mirror / departure |
| --- | --- |
| `id`, `tenant_id`, `company_id` | mirror `:33-43` (own `tenant_id` + own RLS policy: RLS is not transitive, `SalesInvoiceLine.php:59-64`) |
| **`bill_id`** | mirror `sales_invoice_id` `:45`, `constrained('bills')->cascadeOnDelete()` |
| `line_number`, `description`, `quantity`, `unit_price` | mirror `:50-57` |
| `discount_percent`, `discount_amount`, `line_subtotal` | mirror `:62-66` |
| `tax_code_id`, `tax_rate`, `tax_amount` | mirror `:70-73`; `tax_rate` is the **snapshot** (`SalesInvoiceLine.php:30-32`), never re-resolved |
| `line_total` | mirror `:75` |
| **`expense_account_id`** | **DEPARTURE.** mirror `revenue_account_id` `:79` (`foreignUuid`→`accounts` `restrictOnDelete`), renamed; validated by the service to be an **Expense** account (Gate-1 dec. 4) |
| `branch_id`, timestamps | mirror `:81-83` |

## A4. Indexes, uniqueness, CHECK constraints (Gate-2 PROPOSED)

**`bills`** — all mirror `sales_invoices` unless flagged:

- Indexes `(tenant_id, company_id, status)` and `(company_id, supplier_id, bill_date)` — mirror `:103-104`.
- `bills_company_number_unique` **partial** unique on `(company_id, number) WHERE number IS NOT NULL` —
  mirror `:109-113` (every draft has a null `number`).
- **DUPLICATE GUARD (Gate-1 dec. 5):** `bills_company_supplier_invoice_number_unique` **unique** on
  `(company_id, supplier_id, supplier_invoice_number)`. Not partial — with no soft-delete every row is
  "live", and a hard-deleted draft frees its number naturally (a deleted draft was a mistake). The service
  trims `supplier_invoice_number` and matches **exactly** (no case-folding: a supplier's "INV/001" and
  "inv/001" are not safely the same document, unlike our own generated codes). The index is the authority
  under concurrency; the service translates its violation into a named refusal (§C3).
  **(Gate-1 APPROVED dec. 5; realisation Gate-2 PROPOSED)**
- `bills_status_check` — `status IN ('draft','posted','partially_paid','paid','cancelled')`. **All five
  declared from the start** even though only `draft`/`posted` are reachable this wave — mirroring
  `sales_invoices_status_check` (`:115-116`) and its reasoning (`SalesInvoiceStatus.php:9-13`): a status
  column widened later is widened while rows already depend on its constraint. This reserves `cancelled`
  (dec. 2) and the payment states (Wave 8) with **no later CHECK-widening migration**.
- `bills_due_after_bill_check` — `due_date >= bill_date` — mirror `:118-122`.
- `bills_number_matches_status_check` — `(number IS NULL) = (status = 'draft')` — mirror `:134-138`.
- `bills_posted_at_matches_status_check` — `(posted_at IS NULL) = (status = 'draft')` — mirror `:140-144`.
- `bills_draft_has_no_entry_check` — `status <> 'draft' OR journal_entry_id IS NULL` — mirror `:149-153`.
- `bills_total_check` — `total = subtotal + tax_total` — mirror `2026_03_05_000001…:32-36` (folded into
  create, because bills post in the same wave they are created).
- `bills_amount_due_check` — `amount_due = total - amount_paid` — mirror `:158-162`.
- `bills_non_negative_check` — `subtotal>=0 AND tax_total>=0 AND total>=0 AND amount_paid>=0 AND discount_total>=0` — mirror `:164-168`.
- Phase-scoped, each dropped by the phase that earns it: `bills_single_currency_until_fx_phase`
  (`exchange_rate IS NULL`) and `bills_no_payments_until_payments_phase` (`amount_paid = 0`) — mirror
  `:177-187`. **`COMMENT`** each with the dropping phase, mirror `:192-193`.

**`bill_lines`** — all mirror `sales_invoice_lines` (`2026_03_04_000002…:91-134`):
`bill_lines_position_unique` on `(bill_id, line_number)`; `quantity <> 0`; single-discount
(`discount_percent IS NULL OR discount_amount IS NULL`); discount-percent range 0–100; tax-rate range
0–100; `rate_needs_code` (`tax_code_id IS NOT NULL OR tax_rate = 0`); `line_total = line_subtotal + tax_amount`.

## A5. Immutability triggers (Gate-2 PROPOSED)

`2026_09_02_000005_make_posted_bills_immutable.php` mirrors `2026_03_05_000002…` **as it stood before
cancellation** (no cancellation branches — those arrive with the cancel feature, exactly as sales added
them in `2026_03_06_000001…`):

- Function `asids_bills_immutable()` + trigger `bills_immutable BEFORE UPDATE OR DELETE … WHEN (OLD.status <> 'draft')`
  (`:102-108`). The `WHEN` clause is what lets the single posting UPDATE through (the row is still `draft`
  at that instant), then freezes every later update (`:99-101`).
- Refuse `DELETE` of a posted bill; refuse `NEW.status = 'draft'` (un-posting strands a number + entry);
  freeze the **enumerated** column list (written by name, not `OLD IS DISTINCT FROM NEW`, so a column
  added later and forgotten is not silently mutable — `:40-42`): `id, tenant_id, company_id, branch_id,
  supplier_id, supplier_invoice_number, number, bill_date, due_date, currency_code, exchange_rate,
  subtotal, discount_total, tax_total, total, posted_at, posted_by_id, journal_entry_id, notes, terms,
  created_by_id, created_at`. **Mutable after posting:** `status`, `amount_paid`, `amount_due` (Wave 8),
  plus `updated_at` — mirror `:16-30`.
- Function `asids_bill_lines_immutable()` + trigger `bill_lines_immutable BEFORE INSERT OR UPDATE OR DELETE`,
  freezing lines by their parent's status (null parent = legitimate cascade from deleting a draft) — mirror
  `:116-142`.

## A6. FORCED row-level security (Gate-1 APPROVED NFR; realisation Gate-2 PROPOSED)

`2026_09_02_000004_enable_row_level_security_on_bills.php` adds `bills` and `bill_lines` to a **new** RLS
migration (never edit an applied one) reusing `asids_rls_bypassed()` / `asids_current_tenant_id()` — a
verbatim mirror of `2026_03_04_000003…:26-44` and of Purchasing's own
`2026_09_01_000002_enable_row_level_security_on_purchasing.php`. `ENABLE` + **`FORCE`** (FORCE is what
applies the policy to the table owner; without it CI passes vacuously — `…000002…:16-19`). `bill_lines`
gets its **own** policy: RLS is not transitive (`SalesInvoiceLine.php:59-64`).

## A7. The `shouldBeStrict()` default trap (Gate-1 APPROVED — restated)

`BillService::createDraft()` **explicitly** sets `status`, `number`, `posted_at`, `journal_entry_id`,
`exchange_rate`, `created_by_id`, `amount_paid`, `amount_due` rather than relying on column defaults —
mirror `SalesInvoiceService.php:86-94` / `SupplierService.php:58-64`. Under `Model::shouldBeStrict()` an
unsaved instance reads a defaulted column back as null and throws; this is the trap Phase 1 hit on
`must_change_password` and Phase 2 on `is_closed`. Set-explicitly-then-`refresh()`.

---

# B. `DocumentType` and numbering (Gate-1 APPROVED dec. 1; realisation Gate-2 PROPOSED)

**Two series, preserved (the ADR 0009 §B decision this wave must not break):**

- **The bill's own internal number** draws `BILL-…` from a **new** `DocumentType::Bill` counter.
- **The ledger entry** draws `JV-…` from the existing `JournalVoucher` counter — *unchanged*. A single
  counter feeding both would hand the bill 0001 and its own entry 0002, gapping bill numbers on every post
  — the exact defect ADR 0009 §B was written to prevent (`SalesInvoiceService.php:198-205`).

**`DocumentType::Bill` (Gate-2 PROPOSED)** — add to `src/Core/Accounting/Domain/Enums/DocumentType.php`
(the enum's docblock already anticipates this: "Purchase documents add their cases the same way",
`:14-15`):

- `case Bill = 'bill';`
- `label()` ⇒ `'Bill'`; `prefix()` ⇒ `'BILL'` (`:37-45`).
- **`requiresGaplessNumbering()` ⇒ `false` for `Bill`** — the first case to do so, exactly the distinction
  the method's docblock reserves ("later phases will add internal document types where it does not",
  `:56-59`). This realises Gate-1 dec. 1: a bill is *received*, not issued, so its internal number need
  not be gapless. Implementation: `return $this !== self::Bill;` (all prior families stay gapless).

**Consequence for `DocumentNumberService` (no code change):** `next()` still draws `BILL-YYYY-MM-0001`
under the same `document_sequences` row lock (`DocumentNumberService.php:50-82`); only
`assertInTransaction()` is skipped for a non-gapless type (`:92-107`). Because `post()` still calls it
*inside* the transaction (mirroring `issue()`), a rolled-back post returns the number anyway — so bills
will not gap in practice, but we neither promise nor pay for gaplessness.

**When assigned:** at **post**, not draft — mirror `issue()` (`SalesInvoiceService.php:274-276`): a draft
is not yet a payable and is already identified by `supplier_invoice_number`. This keeps
`(number IS NULL) = (status = 'draft')` a clean mirror.

**The alternative — "the internal reference is just the supplier's number, no counter"** — is rejected
(§Alternatives): it loses a stable company-owned handle independent of each supplier's numbering scheme,
and breaks the tight mirror of the status-tied CHECKs and immutability that lean on `number`. Flagged for
confirmation at Gate 2 (item 2 below).

---

# C. Models, enum, DTOs, `BillService`

## C1. `BillStatus` enum (Gate-2 PROPOSED)

`src/Core/Purchasing/Domain/Enums/BillStatus.php`, mirroring `SalesInvoiceStatus.php`. **All five cases
declared** (`SalesInvoiceStatus.php:19-25` reasoning); only `Draft`/`Posted` reachable this wave:

```
Draft = 'draft'; Posted = 'posted'; PartiallyPaid = 'partially_paid'; Paid = 'paid'; Cancelled = 'cancelled';
```

Note `Posted` (not sales' `Issued`): we *post* a bill, we *issue* an invoice. Methods mirror the sales
enum: `isEditable()` (`Draft` only, `:48-51`); `hasBeenPosted()` (`!== Draft`, mirror `hasBeenIssued()`
`:59-62`); `isOutstanding()` (`Posted, PartiallyPaid ⇒ true`, mirror `isCollectable()` `:70-76`). No
`Overdue` case — derived, never stored (`:14-17`).

## C2. `Bill` + `BillLine` models (Gate-2 PROPOSED)

`src/Core/Purchasing/Domain/Models/Bill.php` mirrors `SalesInvoice.php`; `BillLine.php` mirrors
`SalesInvoiceLine.php`. Key points:

- `Bill`: `use Auditable, BelongsToTenant, HasFactory, HasUuids;` `MORPH_ALIAS = 'bill';`
  `$table = 'bills';` `$fillable = ['notes', 'terms']` (**not** `supplier_invoice_number`/`number` — every
  figure and identifier is service-controlled, mirror `SalesInvoice.php:90-99`). Relations: `supplier()`,
  `company()`, `branch()`, `lines()` (`hasMany(BillLine, 'bill_id')->orderBy('line_number')`),
  `journalEntry()`, `createdBy()`. Helpers: `isDraft()`, `isEditable()`, money accessors, `isOverdue()`.
- **`auditOnly()`** (mirror `SalesInvoice.php:253-267`): `number, supplier_invoice_number, supplier_id,
  bill_date, due_date, subtotal, discount_total, tax_total, total, status`. `auditTags()` ⇒
  `['purchasing', 'bill']`. **`BillLine` is not audited** — a line has no life of its own
  (`SalesInvoiceLine.php:25-28`).
- Scopes: `scopeForCompany`, `scopeDrafts`, **`scopeOutstanding`** (`status IN ('posted','partially_paid')`,
  mirror `scopeCollectable` `SalesInvoice.php:236-242`) — the probe's source of truth (§E).
- `casts()`: `status ⇒ BillStatus`, `bill_date`/`due_date ⇒ immutable_date`, `posted_at ⇒
  immutable_datetime`, all money `decimal:4`, `exchange_rate decimal:10` — mirror `:280-296`.
- `BillLine`: `use BelongsToTenant, HasFactory, HasUuids;` **no** `Auditable`, **no** `MORPH_ALIAS`
  (`SalesInvoiceLine.php:72-79`); `$fillable = []` (`:82-92`); relations `bill()`, `company()`,
  **`expenseAccount()`** (`belongsTo(Account, 'expense_account_id')`, mirror `revenueAccount()` `:115-118`),
  `taxCode()`, `branch()`; money accessors + `casts()` mirror `:177-193`.

Factories `BillFactory` / `BillLineFactory` mirror the sales factories (states: draft, posted).

## C3. `BillData` + `BillLineData` DTOs (Gate-2 PROPOSED)

Mirror `SalesInvoiceData` / `SalesInvoiceLineData`:

- `BillLineData` (mirror `SalesInvoiceLineData.php`): `description, quantity, unitPrice,
  expenseAccountId, taxCode? (by **code**, resolved by date — never id, `:9-13`), discountPercent?,
  discountAmount?, branchId?`. `fromArray()` normalises decimals through `number_format` (`:82-93`).
- `BillData` (mirror `SalesInvoiceData.php`): `supplierId, billDate, lines,
  **supplierInvoiceNumber** (required — the one non-nullable string beyond the line list), dueDate?,
  discountAmount?, branchId?, notes?, terms?`. Create-only DTO; `updateDraft` takes an attributes array
  for `array_key_exists()` clear-vs-omit semantics (`SalesInvoiceData.php:12-18`). No `number` field — a
  caller may never supply the internal number (`:23-25`).

## C4. `BillService` (Gate-2 PROPOSED, realising Gate-1 dec. 1, 4, 5)

`src/Core/Purchasing/Application/Services/BillService.php`, a `final readonly` service mirroring
`SalesInvoiceService` method-for-method. Constructor injects `TaxRateResolver`, `InvoiceTotalsCalculator`
(reused — arithmetic is identical), **`BillPostingMap`**, `PostingService`, `DocumentNumberService`,
`FiscalCalendarService` (mirror `:57-64`).

- **`createDraft(Company, BillData, ?createdById)`** — mirror `:66-103`. Resolve supplier (`§C5`,
  `forNewBill: true`), derive `due_date` from `Supplier::dueDateFor()` when absent, `assertDates`,
  **assert `supplier_invoice_number` present + not a duplicate** (`§C6`), then `replaceLines()`. Status
  `Draft`; `number`/`posted_at`/`journal_entry_id` null.
- **`updateDraft(Bill, array<string,mixed>)`** — mirror `:121-174`. `assertEditable`; `array_key_exists`
  clear-vs-omit for `branch_id`, `discount_amount`, `notes`, `terms`; recognised keys include
  `supplier_id`, `bill_date`, `due_date`, `supplier_invoice_number`, `lines`. Recompute totals even when
  `lines` omitted — a changed `bill_date` can move the tax rate (`:116-118`). A changed
  `supplier_invoice_number` re-runs the duplicate check (`§C6`).
- **`deleteDraft(Bill)`** — mirror `:430-437`. `assertEditable`, hard delete; lines cascade.
- **`post(Bill, ?User $actor)`** — mirror `issue()` (`:214-308`) **with debits/credits swapped in the
  map only**; the *lifecycle* is identical:
  1. `loadMissing(['company','supplier','lines.taxCode'])`.
  2–4. Status must be `Draft` (`BillCannotBePosted::notADraft`); lines non-empty (`::withoutLines`);
     `total > 0` (`::withZeroTotal`) — mirror `:223-233`.
  5. `assertPostable(bill, company)` (`§C5` — re-validate supplier `acceptsNewBills`, branch, and each
     line's tax-code company ownership) — mirror `assertIssuable()` `:746-781`.
  6. `period = calendar->periodFor(company, bill_date->startOfDay())`; refuse closed **before any number
     is reserved** (`BillCannotBePosted::intoClosedPeriod`) — mirror `:238-244`.
  7. `lines = postingMap->for(bill)` — built **before** the transaction; writes nothing (`§D`), so a
     refusal costs no lock — mirror `:246-247`.
  8. `DB::transaction`: `lockForUpdate()->firstOrFail()`, re-check `Draft` (the only check that holds
     under concurrency, `:250-272`); `number = numbers->next(company, DocumentType::Bill, period)`;
     `entry = posting->postNew(company, new JournalEntryData(entryDate: bill_date->startOfDay(),
     description: LedgerNarration::limit("Bill {number} — {supplier.name}"), lines, reference: number,
     **documentType: DocumentType::JournalVoucher**, source: SourceDocument::for(bill)), actor)` — the JV
     is `JournalVoucher` **by explicit choice**, the two-series decision (`:278-290`,
     `JournalEntryData.php:21-44`, `SourceDocument::for` `SourceDocument.php:48`). One save carrying the
     whole posted state: `status = Posted, number, posted_at = now(), posted_by_id = actor?->getKey(),
     journal_entry_id = entry` (`:294-304`); `refresh()`.

**Nothing recomputed at post** — `line_subtotal`/`tax_amount` were rounded when the draft was written; the
map sums stored values so the entry balances *by construction* (`:191-197`, `InvoicePostingMap.php:38-47`).

## C5. Resolution helpers (Gate-2 PROPOSED)

- `resolveSupplier(Company, id, forNewBill)` — mirror `resolveCustomer()` (`:641-669`): company ownership
  (`supplier-outside-company`); when `forNewBill`, `Supplier::acceptsNewBills()` (`Supplier.php:125-128`)
  else `supplier-not-billable` (mirror `customer-not-invoiceable`). Existing bills unaffected by a
  supplier's later dormancy (`SupplierStatus` docblock).
- **`resolveExpenseAccount(Company, id)`** — mirror `resolveRevenueAccount()` (`:678-711`) with
  `AccountType::Expense` in place of `Income`: company ownership (`expense-account-outside-company`), type
  (`expense-account-wrong-type` — "a bill line debits expense, so it has to be an expense account"),
  postability (`expense-account-not-postable`). The type check is the one the database cannot make (a
  CHECK cannot join to `accounts`).
- `resolveBranchId`, `assertDates`, `assertEditable`, `replaceLines`, `decimal`/`assertDecimal`,
  `existingHeaderDiscount`, `lineDataFrom*` — mirror `:450-920` unchanged (arithmetic is currency-agnostic
  and identical). A negative total is refused at every stage (`bill-total-negative`, mirror
  `invoice-total-negative` `:545-553`) — a negative bill is a debit note, out of scope.

## C6. Duplicate supplier-invoice-number guard (Gate-1 APPROVED dec. 5; realisation Gate-2 PROPOSED)

Both layers, mirroring the supplier-code pattern (`SupplierService.php:384-413`):

- **Pre-check** in `createDraft`/`updateDraft`: query `Bill::forCompany()->where('supplier_id', …)
  ->where('supplier_invoice_number', trim($n))` (excluding self on update) → if exists, throw
  `BusinessRuleViolation` `bill-duplicate-supplier-number` ("Supplier {code} already has a bill recorded
  under invoice number {n}. Recording it again risks paying it twice.").
- **Authority under concurrency:** catch `UniqueConstraintViolationException` on save whose message
  contains `bills_company_supplier_invoice_number_unique` and re-throw as the same named refusal — mirror
  `SupplierService::isDuplicateCodeViolation()` (`:409-413`). The index decides the race the application
  cannot see.

---

# D. `BillPostingMap` — Dr Expense + Dr Input VAT = Cr Trade Payables (Gate-1 APPROVED dec. 3, 4; realisation Gate-2 PROPOSED)

`src/Core/Purchasing/Application/Services/BillPostingMap.php`, a `final readonly` **pure mapping** (writes
nothing, posts nothing, reserves no number — `InvoicePostingMap.php:16-21`) mirroring `InvoicePostingMap`
with **debits and credits swapped**. For a 100,000 purchase at 18% VAT:

```
Operating Expense    100,000.00   (Dr, per expense account)
Input VAT Recoverable 18,000.00   (Dr, Σ per input_account_id)
  Trade Payables               118,000.00   (Cr, total)
```

**`for(Bill): list<JournalLineData>`** — `loadMissing(['company','supplier','lines.taxCode'])` (up front,
or a `LazyLoadingViolationException`, `:68-71`); refuse empty (`BillCannotBePosted::withoutLines`). Returns
**debits first, then the single credit** (a purchase entry reads debits-first; a deliberate, low-stakes
divergence from sales' receivable-first order, since here the *many* are the debits):

```
[ ...expenseLines(bill, lines, currency),   // Dr, grouped by expense account, by code
  ...inputTaxLines(bill, lines, currency),  // Dr, grouped by input_account_id, by code
  ...payableLines(bill, currency) ]         // Cr Trade Payables = total
```

- **`expenseLines`** — mirror `revenueLines()` (`:153-194`): group by `expense_account_id`, sum
  `line_subtotal`, one **debit** per distinct account, ordered by code (`orderedByCode`). Skip zero
  (nets that cancel). Type re-checked here (`AccountType::Expense`, else
  `BillCannotBePosted::expenseAccountWrongType`) — an account can be reclassified after the draft. A
  negative net flips the side (a net credit against an expense is a credit line), mirror `:180-190`.
- **`inputTaxLines`** — mirror `taxLines()` (`:206-263`) on the **input** side: group by
  `tax_codes.input_account_id`, sum stored `tax_amount`, one **debit** per distinct input account, by
  code. Zero-rated/exempt lines contribute nothing (`:213-217`). Refusals mirror the output side:
  - `tax_amount ≠ 0` but `tax_code_id IS NULL` ⇒ `BillCannotBePosted::taxWithoutCode(lineNumber)` (mirror
    `:219-221`).
  - **`tax_code.input_account_id IS NULL` ⇒ `BillCannotBePosted::taxCodeHasNoInputAccount(code)`** — the
    input-side mirror of `taxCodeHasNoOutputAccount` (`:226-228`, `InvoiceCannotBePosted.php:150-161`).
    **This is the first production path that can fail this way** (§Risks — input-VAT readiness). Message
    names the code and the remedy ("Set its input VAT account before posting").
  - Input account type must be **`AccountType::Asset`** (input VAT is recoverable — an asset;
    `ChartTemplate.php:86-88`), else `BillCannotBePosted::taxAccountWrongType`. *(This is the input-side
    counterpart of output VAT's `Liability` check at `:247-249`.)*
- **`payableAccountFor(Bill): Account`** (public — the service may ask "where will this post?") — mirror
  `receivableAccountFor()` (`:97-121`) **without the per-supplier override** (Gate-1 dec. 3): resolve
  `Account::withSystemKey(Account::TRADE_PAYABLES)->forCompany(bill.company_id)`; null ⇒
  `BillCannotBePosted::withoutPayableAccount(company.name)`; type must be **`AccountType::Liability`**
  (`::payableAccountWrongType`); `assertPostable`. `payableLines` returns one **credit** for `bill.total`
  (zero-total produces no line, `:130-135`).

**Balancing by construction:** `Σ expense.line_subtotal + Σ tax_amount = subtotal + tax_total = total =
Cr Trade Payables`, because `bills_total_check` holds `total = subtotal + tax_total` and the service
maintains `subtotal = Σ line_subtotal`, `tax_total = Σ tax_amount` — no rounding happens in the map
(`:38-47`). `orderedByCode` / `accountWithinCompany` / `assertPostable` copied verbatim (`:265-323`); the
cross-company account check is the one RLS cannot make (`:53-56`).

**Exceptions:** `src/Core/Purchasing/Domain/Exceptions/BillCannotBePosted.php` mirrors
`InvoiceCannotBePosted.php` (note its constructor order is `(message, code, context)`, `:22-28`):
`withoutLines`, `withZeroTotal`, `notADraft`, `intoClosedPeriod`, `accountOutsideCompany`,
`accountNotPostable`, `expenseAccountWrongType`, `taxAccountWrongType`, `taxCodeHasNoInputAccount`,
`taxWithoutCode`, `withoutPayableAccount`, `payableAccountWrongType`. (Split as sales splits
`InvoiceCannotBeIssued` (lifecycle) from `InvoiceCannotBePosted` (mapping) is optional; a single
`BillCannotBePosted` is acceptable — flagged Gate 2, item 7.)

## D-note. Input-VAT data readiness (Gate-1 flagged Risk; realisation UNRESOLVED — needs human approval)

`input_account_id` is populated "only by a company that has configured it" (`TaxCode.php:100-111`); most
tenants' VAT codes may have it **null**, so day-one posting of any VAT bill refuses (correctly, AC-3.7).
Options — I recommend (a), and will **not** silently build a backfill that guesses a setting:

- **(a) Correctness by refusal + operator command (recommended).** The refusal above is the guarantee.
  Ship an **optional, idempotent, dry-run-by-default** console command
  `purchasing:backfill-input-vat-accounts` that, per company, sets `input_account_id` on VAT-charging
  codes that have none to that company's `1170 Input VAT Recoverable` leaf **only when exactly one active
  postable such account exists** (else it reports and skips, naming the company). Operator-run and
  reviewable — it never guesses platform-wide, respecting that `input_account_id` is a *setting*, not a
  system key. Uses `RowLevelSecurity::bypass()` + the `assertBypassEffective()` guard
  (`2026_03_05_000003…:142-152`).
- **(b) Do-nothing.** Ship only the refusal; admins set input accounts via `TaxCodeService` before their
  first VAT bill. Simplest; more day-one friction.
- **(c) Forced migration** setting `input_account_id = 1170` for every keyless VAT code — **rejected**:
  overreach on a configured, statutory-adjacent setting.

**Fork for Gate 2:** build the command this wave (a) or defer to operations (b)? *(UNRESOLVED — needs
human approval.)*

---

# E. `EloquentPayableBalanceProbe` + rebind (Gate-1 APPROVED dec. 6; realisation Gate-2 PROPOSED)

`src/Core/Purchasing/Infrastructure/EloquentPayableBalanceProbe.php`, mirroring
`EloquentReceivableBalanceProbe.php` exactly:

- **`outstandingBalance(Supplier): numeric-string`** — mirror `:62-75`:
  `Bill::forCompany(supplier.company_id)->where('supplier_id', supplier.id)->outstanding()->sum('amount_due')`,
  normalised `bcadd((string)$sum, '0', Money::SCALE)`. `scopeOutstanding` (§C2) decides which bills count
  (posted + partially_paid; excludes drafts — not yet owed — and cancelled/paid) and is **not** restated
  here (one definition, `:48-59`). Reads `amount_due`, not `total - amount_paid` (Wave 8 owns that column,
  `:56-59`).
- **`hasAnyBill(Supplier): bool`** — mirror `hasAnyInvoice()` (`:83-89`):
  `Bill::forCompany(supplier.company_id)->where('supplier_id', supplier.id)->exists()`. **No status
  filter** — a bill is a statutory record naming the supplier whatever its state; distinct from owing
  money (`PayableBalanceProbe.php:37-43`). The explicit `forCompany` is not redundant with RLS — two
  companies share a `tenant_id` (`:39-44`).

**Rebind (the one-line flip Gate-1 dec. 6 authorises):** in `PurchasingServiceProvider::register()`
change `$this->app->bind(PayableBalanceProbe::class, NoPayables::class)` (`:38`) →
`EloquentPayableBalanceProbe::class`. `NoPayables` is **kept**, not deleted — it is the honest answer for
any context with no bills, and a test binds it directly (`NoPayables.php:19-21`). Also in `boot()`: add
`Bill::MORPH_ALIAS => Bill::class` to the `morphMap` (`:48-50` — `Bill` is `Auditable`, and an unmapped
class throws) and `Gate::policy(Bill::class, BillPolicy::class)` (`:52`).

**Blast radius (Gate-1 flagged Risk).** The flip activates three dormant `SupplierService` rules for
**every existing supplier at once** — archive-with-balance (`:217-240`), delete-with-bill (`:249-263`),
code-lock-with-bill (`:98-115`) — with **no `SupplierService` change** (`PurchasingServiceProvider.php:30-38`).
Acceptance test §H mirrors `ReceivableBalanceProbeTest.php`, including its one binding assertion
(`:89-94`, "the real implementation, not the stub") — the check that would have caught Sales closing a
milestone with the seam unbound — plus the four "rules this activates" cases (`:289-330`).

---

# F. Authorization (Gate-1 APPROVED dec.; realisation Gate-2 PROPOSED)

## F1. Catalogue — mirror the sales-invoice split, not the supplier split

Bills are a *document with a draft→post lifecycle*, so they mirror `sales.invoices.*`
(`PermissionCatalogue.php:249-263`), **not** `purchasing.suppliers.{view,manage}` (master data). Add to
`purchasing()` (`:283-292`, after the two supplier capabilities):

| Permission | sensitive | sortOrder | Mirrors |
| --- | --- | --- | --- |
| `purchasing.bills.view` | no | 30 | `sales.invoices.view` (`:249`) |
| `purchasing.bills.draft` | no | 40 | `sales.invoices.draft` (`:255`) — drafting is ordinary work |
| `purchasing.bills.post` | **yes** | 50 | `sales.invoices.issue` (`:259`) — commits to the ledger |

**No `purchasing.bills.cancel`** this wave — cancellation is deferred (dec. 2) and the catalogue rule is
"only add a capability when the code that checks it exists" (`:22-24`). *Naming note:* the task header
wrote `{view,manage,post}`; both the task body ("mirror `sales.invoices.*`'s split") and requirements
AC-5.1 point to **`draft`** (the invoice-lifecycle verb), which I adopt. Flagged Gate 2, item 5.

## F2. Role grants (Gate-1 APPROVED dec.; realisation Gate-2 PROPOSED)

Mirror whichever roles hold `sales.invoices.*` today (AC-5.2), the way Wave 6 mirrored
`sales.customers.*` (ADR 0018 §D2). In `RoleTemplate.php`:

- **accountant** (holds `sales.invoices.{view,draft,issue,cancel}`, `:115-118`) ⇒ add
  `purchasing.bills.{view,draft,post}` — the accountant is the side of the split that commits documents.
- **bookkeeper** (holds `sales.invoices.{view,draft}`, `:169-170`) ⇒ add
  `purchasing.bills.{view,draft}` — drafts, never posts.
- **viewer** (holds `sales.invoices.view`, `:213`) ⇒ add `purchasing.bills.view`.
- **owner** inherits via the `['*']` wildcard (`:48`) — no explicit grant. **administrator** inherits via
  `tenantGrantableNames()` (`:57`).

Catalogue tests assert composition + owner-inheritance, not a fixed permission count (ADR 0018
Consequences), so no count assertion breaks.

## F3. `BillPolicy` (Gate-2 PROPOSED)

`src/Core/Purchasing/Policies/BillPolicy.php` mirrors `SalesInvoicePolicy.php` — permission **and**
company membership on every method with a bill to check (`:44-55`); status checks are **advisory** (a
client deciding whether to show a button), never the enforcement, because `Gate::before` short-circuits
owners (`:22-39`):

- `viewAny` ⇒ `can('purchasing.bills.view')`; `view`/`update`/`delete` ⇒ the matching capability +
  `canAccessCompany($bill->company_id)`. `create` ⇒ `can('purchasing.bills.draft')`.
- `update`/`delete` ⇒ `can('purchasing.bills.draft')` + membership (mirror `:62-82`).
- **`post`** ⇒ `$bill->isDraft() && can('purchasing.bills.post') && canAccessCompany(...)` (mirror
  `issue()` `:90-95`; the `isDraft()` is advisory — `BillService::post()` re-checks).
- **No `cancel`** method (deferred). No `BillLinePolicy` — a line is not independently addressable
  (`:40-42`).

Register `Gate::policy(Bill::class, BillPolicy::class)` in the provider `boot()` (§E).

---

# G. Build stages (test-first / RED-first per the gate policy)

Each stage is an independently reviewable RED→GREEN cycle: QA writes the failing tests first, the
engineer implements to green. No stage folds another's reviewer gate.

| Stage | Deliverable | Files (create unless noted) | Test-first artefact | Green when |
| --- | --- | --- | --- | --- |
| **1. Trade Payables system account** | `Account::TRADE_PAYABLES`; `ChartTemplate` stamp `2110` + `requiredSystemAccounts` + `VERSION` bump; backfill migration. | **edit** `Account.php`, `ChartTemplate.php`; `Accounting/…/2026_09_02_000001_stamp_trade_payables_system_key.php` | `TradePayablesSystemAccountTest` (mirror `TradeReceivablesSystemAccountTest`): existing companies (both prior versions) stamped, `is_system` set, idempotent, bypass-effective, nothing-left-behind; fresh company from new template keyed at provisioning. | migration stamps; no dup `2110-1` |
| **2. `bills`+`bill_lines` schema, RLS, immutability** | 4 migrations (§A1). | `Purchasing/…/2026_09_02_000002…005…` | `BillSchemaTest` + `BillSchemaCheckMutationTest` (mirror `SalesInvoiceSchemaTest` + `SupplierSchemaCheckMutationTest`): columns/CHECKs/indexes incl. **both** unique indexes; each CHECK **non-vacuously** rejects a bad row; `RowLevelSecurity::isEnforced('bills'/'bill_lines')`; posted-bill immutability + un-post refusal; cross-tenant raw-SQL read hidden + write refused. | migrations run; RLS + triggers enforce |
| **3. `DocumentType::Bill` + `BillStatus` + models + factories** | enum case; `BillStatus`; `Bill`/`BillLine`; factories; morph alias in provider boot. | `Domain/Enums/BillStatus.php`; `Domain/Models/Bill.php`,`BillLine.php`; `database/factories/Bill*Factory.php`; **edit** `DocumentType.php`, provider | `BillModelTest`/`BillFactoryTest`: casts, scopes (`Outstanding`), `isEditable`/`hasBeenPosted`/`isOutstanding`, morph round-trip; `DocumentType::Bill` prefix `BILL`, `requiresGaplessNumbering() === false` (others still true). | models/enum/factory behave |
| **4. DTOs + `BillPostingMap` + exceptions** | `BillData`/`BillLineData`; `BillPostingMap`; `BillCannotBePosted`. | `Application/DTOs/Bill*Data.php`; `Application/Services/BillPostingMap.php`; `Domain/Exceptions/BillCannotBePosted.php` | `BillPostingMapTest` (mirror `InvoicePostingMapTest`): Dr expense (grouped/ordered) + Dr input VAT (grouped by `input_account_id`) = Cr Trade Payables; balances by construction; **input-VAT refusal names the code**; expense/payable wrong-type + missing payable + cross-company; zero/negative/mixed-rate. | map builds correct balanced lines |
| **5. `BillService`** | createDraft/updateDraft/deleteDraft/**post**; duplicate guard; singleton in provider. | `Application/Services/BillService.php`; **edit** provider | `BillDraftTest` + `PostBillTest` (mirror `SalesInvoiceDraftTest` + `IssueInvoiceTest`): draft CRUD; **duplicate supplier-invoice-number refused** (pre-check + index race); supplier/expense/tax resolution; post lifecycle, closed-period-before-number, cross-company, re-validation; **two-series multi-bill numbering** (BILL non-gapless, JV gapless — post several, assert neither series gaps the other). | full lifecycle green |
| **6. `EloquentPayableBalanceProbe` + rebind** | probe; flip binding; keep `NoPayables`. | `Infrastructure/EloquentPayableBalanceProbe.php`; **edit** provider | `PayableBalanceProbeTest` (**rewrite** the dormant Wave-6 file to mirror `ReceivableBalanceProbeTest`): **binding is `EloquentPayableBalanceProbe`, not `NoPayables`**; balances (outstanding/draft/paid/cancelled/sibling/tenant); `hasAnyBill`; **blast radius** — archive-with-balance / delete-with-bill / code-lock now refuse. | real probe bound; dormant rules live |
| **7. Authorization** | `purchasing.bills.{view,draft,post}`; 3 grants; `BillPolicy`; `Gate::policy`. | `Policies/BillPolicy.php`; **edit** `PermissionCatalogue.php`, `RoleTemplate.php`, provider | `BillAuthorizationTest` (mirror `SalesInvoiceAuthorizationTest`): catalogue declares three, **`post` sensitive / `view`+`draft` not**; accountant post, bookkeeper draft-only, viewer view-only; policy permission×membership; owner inheritance intact. | authz green |

*(Optional Stage 8 — input-VAT backfill command §D-note (a), only if Gate 2 chooses to build it.)*

# H. Test strategy (QA writes tests before implementation)

Port the sales/probe suites into `tests/Feature/Purchasing/`, swapping Dr/Cr and the deferred cases:

- **Schema + CHECK mutation** (mirror `SalesInvoiceSchemaTest.php`, `SupplierSchemaCheckMutationTest.php`):
  every column/index/CHECK exists; **both** unique indexes enforce (partial `number`, full
  supplier-invoice-number); each CHECK **non-vacuously** rejects a crafted bad row (the recent
  `SupplierSchemaCheckMutationTest` lesson, commit `80cc5a9`); phase-scoped CHECKs pin `amount_paid`/
  `exchange_rate`; immutability freezes a posted bill and refuses un-post; `deleteDraft` cascade.
- **Posting map** (mirror `InvoicePostingMapTest.php`): the balanced entry; grouping by expense account
  and by `input_account_id`; ordering (debits by code, then payable); **`taxCodeHasNoInputAccount` names
  the code**; expense/payable/input wrong-type; missing payable (`withoutPayableAccount`); cross-company;
  zero-rated contributes nothing; negative net flips side.
- **Draft lifecycle** (mirror `SalesInvoiceDraftTest.php`): create/edit/delete; `acceptsNewBills` refusal;
  expense-account type/company/postability; tax by code+date snapshot; zero-quantity + negative-total
  refusals; `array_key_exists` clear-vs-omit; **duplicate supplier-invoice-number refused per supplier per
  company, allowed across suppliers/companies**.
- **Post** (mirror `IssueInvoiceTest.php`): the Dr/Cr entry; JV from `JournalVoucher`, BILL from `Bill`;
  closed-period-before-number; cross-company (supplier/expense/tax) refused; re-validation of a
  since-dormant supplier; concurrency lock re-check; `source_id` unique blocks a second post; **the
  two-series multi-bill test** (the ADR 0009 §B risk — "every single-invoice test passes either way").
- **Posted immutability** (mirror `IssuedInvoiceImmutabilityTest.php`): frozen columns, line freeze,
  un-post + delete refused.
- **Payable probe** (mirror `ReceivableBalanceProbeTest.php`): the binding assertion + balances + isolation
  + `hasAnyBill` + the four activated `SupplierService` rules.
- **System account** (mirror `TradeReceivablesSystemAccountTest.php`): stamp idempotency, both template
  versions, no duplicate `2110-1`, bypass-effective.
- **Authorization** (mirror `SalesInvoiceAuthorizationTest.php`): catalogue shape/sensitivity, grants,
  policy permission×membership.

## Alternatives considered

- **Internal reference = the supplier's number, no counter** (§B) — rejected: loses a stable company-owned
  handle independent of each supplier's numbering, and breaks the mirror of the status-tied CHECKs and
  immutability that lean on `number`. The counter is nearly free (reuses `DocumentNumberService`).
- **Make the internal number gapless** — rejected by Gate-1 dec. 1: a bill is received, not issued; no
  authority audits *our* internal bill numbers for completeness, so the per-document row lock buys nothing.
- **Give `1170 Input VAT` a system key like `2110`** — rejected: input VAT resolves per-tax-code
  (`input_account_id`), symmetric with output VAT (`2140`, keyless). Only the AP control account, resolved
  by key, needs one.
- **Per-supplier AP override / supplier-default expense** — deferred (Gate-1 dec. 3, 4); building now is the
  scope creep §Risks warns against.
- **Build cancellation this wave** — deferred (dec. 2); split draft/post from cancel exactly as Sales split
  Milestone 4 from Milestone 5.
- **Force-set `input_account_id` platform-wide** — rejected (§D-note c): overreach on a configured setting.

## Consequences

- Purchasing gains its first ledger-posting document; the dormant Wave-6 probe/rules go live via a
  one-line binding flip, no `SupplierService` change.
- `tax_codes.input_account_id` becomes load-bearing; a tenant without it set sees VAT bills refuse to post
  until configured (a data-readiness task, not a defect — §D-note).
- The permission catalogue grows by three tenant-grantable capabilities; owner/administrator inherit them.
- The chart template's `VERSION` bumps; a backfill stamps `2110` for existing companies. `DocumentType`
  gains its first non-gapless case, exercising a branch present since the ledger was built.
- Wave 8 (supplier payments / WHT-on-payment) and the future bill-cancellation slice land on ready targets:
  the `amount_paid`/`amount_due` columns (held at zero) and the reserved `cancelled`/payment statuses — no
  schema migration of their own beyond dropping a phase CHECK / adding cancellation columns.

## Risks

- **Input-VAT readiness** (requirements §6): broad day-one posting refusals if codes lack `input_account_id`.
  Mitigated by the code-naming refusal (AC-3.7) and the optional operator command (§D-note); the residual
  fork is Gate-2 item 8.
- **Two-series numbering** (ADR 0009 §B): the BILL and JV series must not gap each other; "every
  single-bill test passes either way," so a **multi-bill** test is mandatory (Stage 5, §H).
- **Probe-activation blast radius** (Gate-1 dec. 6): three rules go live for every existing supplier at
  once; mitigated by the binding assertion + activated-rules tests (§E, §H) mirroring
  `ReceivableBalanceProbeTest.php:89-94`.
- **Mirror drift**: hand-copying a large domain invites a renamed problem code or a missed
  `array_key_exists` branch; the file:line citations here are the review checklist, and suites are *ported*
  from the sales originals, not rewritten.
- **Backfill silence**: a data migration on a FORCED table under NOBYPASSRLS silently stamps nothing;
  mitigated by reproducing `assertBypassEffective()` + `assertNothingLeftBehind()` (§A0.3).

## Gate 2 — items to confirm

No UNRESOLVED fork blocks the *schema/service* build; one fork (item 8) affects only an optional operator
command. Confirm the **(Gate-2 PROPOSED)** shapes:

1. **Schema** (§A2–A6): `bills`/`bill_lines` as the `sales_invoices` mirror, with `supplier_invoice_number`
   (NOT NULL), internal `number` (nullable-till-posted), `bill_date`, `expense_account_id`; no free-text
   `reference`, no `deleted_at`, no cancellation columns; all CHECKs incl. status reserving
   `cancelled`+payment states; FORCED RLS; posted-bill immutability.
2. **Numbering** (§B): internal number **uses a counter** — new `DocumentType::Bill`, prefix `BILL`,
   `requiresGaplessNumbering() = false`, assigned at post, stored in `number`; JV unchanged. (Confirm over
   the "supplier's number only, no counter" alternative.)
3. **The duplicate index** (§A4): unique on `(company_id, supplier_id, supplier_invoice_number)`,
   full (not partial), exact-match (trimmed, not case-folded); service translates the violation.
4. **Posting** (§D): Dr Expense (per line, by account) + Dr Input VAT (Σ, by `input_account_id`) = Cr
   Trade Payables (total); ordering debits-then-credit; input-VAT refusal names the code; input account
   typed **Asset**; `Account::TRADE_PAYABLES` (`2110`) resolved by key, no per-supplier override; the
   stamp/backfill + `VERSION` bump (§A0); `1170` stays keyless.
5. **Permissions** (§F): `purchasing.bills.{view,draft,post}` (**`draft`, not `manage`**; `post`
   sensitive), granted accountant (all three) / bookkeeper (view+draft) / viewer (view); `BillPolicy`
   mirrors `SalesInvoicePolicy`; no `cancel` capability.
6. **Probe rebind + blast radius** (§E): flip to `EloquentPayableBalanceProbe`, activating the three
   dormant supplier rules, with the binding + activated-rules acceptance tests.
7. **`BillCannotBePosted`** as a single mapping-exception class (vs. splitting lifecycle refusals into a
   separate `BillCannotBePosted`/`…Posted` pair as sales does) — minor; confirm the single class.
8. **Input-VAT seeding** (§D-note) — *(UNRESOLVED — needs human approval)*: build the optional
   `purchasing:backfill-input-vat-accounts` command this wave **(a)**, or defer to operations **(b)**?

---

*Prepared by the Solution Architect (Stage 3, Phase 5 / Wave 7). Design only — no production code written,
nothing committed. Build begins after Gate 2 approval, on this ADR, verbatim; any implementation discovery
that would change a decision returns to Gate 2.*

## Gate 2 decision — APPROVED 2026-09-01

The human approved the architecture package **as proposed**, and resolved the one fork:

- **Schema, posting, account, numbering, duplicate guard, probe rebind — all confirmed** as designed: `bills`/`bill_lines`; **Dr Expense (per line) + Dr Input VAT (Σ) = Cr Trade Payables**; new `Account::TRADE_PAYABLES` (`2110`) + stamp/backfill + VERSION bump; `1170` Input VAT stays keyless; `DocumentType::Bill` with `requiresGaplessNumbering() = false` (internal `BILL-` counter at post); duplicate unique on `(company_id, supplier_id, supplier_invoice_number)` full exact-match trimmed; `EloquentPayableBalanceProbe` rebind activating Wave 6's three dormant supplier rules (with binding + blast-radius tests).
- **Permissions:** `purchasing.bills.{view, draft, post}` (`post` sensitive) — `draft` (not `manage`), matching the sales-invoice mirror. No `cancel` capability this wave (cancellation deferred, Gate-1 #2).
- **Cancellation DEFERRED** — draft + post only; the status CHECK reserves `cancelled`/payment states so no later widening migration is needed.
- **Fork (input-VAT readiness) → build the command.** Ship the input-VAT posting refusal (`BillCannotBePosted` when a line's tax code lacks `input_account_id`) **AND** a guarded, **dry-run-first** operator command **`purchasing:backfill-input-vat-accounts`** that points existing tax codes at `1170 Input VAT Recoverable` in one reviewed step (Stage 8). NOT force-set platform-wide.

Build proceeds strictly within this ADR (8 stages incl. the backfill command, test-first). Any implementation discovery that would change a decision above returns to Gate 2.
