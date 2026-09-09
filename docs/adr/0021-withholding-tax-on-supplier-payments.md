# ADR 0021 — Withholding tax (WHT) on supplier payments (Wave 8b)

- **Status:** Proposed — for Gate 2 (human) review
- **Author:** Solution Architect (Stage 3, Phase 5 / Wave 8b)
- **Date:** 2026-09-09
- **Branch:** `feature/phase5-wht-on-payment` (stacked on `feature/phase5-supplier-payments`, Wave 8a —
  delivered, PR #9).
- **Supersedes / extends:** nothing. A **focused extension** of `docs/adr/0020-supplier-payments-and-allocation.md`
  (Wave 8a — supplier payments & allocation, Gate 2 APPROVED 2026-09-03). It adds a third journal line, one
  account, and two columns to each of two tables; it rewrites none of 8a. It is the slice ADR 0020 §A0 and
  its Consequences named as the next target: "8b adds a `WHT Payable` account (leaf + key + backfill) and a
  third journal line."
- **Requirements:** `docs/PHASE-5-WHT-ON-PAYMENT-REQUIREMENTS.md` (Gate 1 decisions — **APPROVED 2026-09-09**).

## How to read this record

Every point is labelled **(Gate-1 APPROVED)** — binding, decided by the human on 2026-09-09, realised here
verbatim, not relitigated; **(Gate-2 PROPOSED)** — my design realisation, to confirm before build; or
**(UNRESOLVED — needs human approval)** — a fork I will not decide silently, collected at the end. Nothing
here is built until Gate 2 approves this ADR, which the build then follows verbatim.

## Context — what makes 8b small, and the one insight that keeps it small

Sri Lankan WHT on a supplier payment withholds a percentage of the service value, pays the supplier the
net, and owes the withheld amount to the Inland Revenue Department until remitted. Wave 8a posts a payment
as two lines — `Dr Trade Payables (amount) = Cr Bank/Cash (amount)` (`PaymentPostingMap.php:59-70`). Wave 8b
adds a **third line** so a withholding payment becomes:

```
Dr Trade Payables        1,000.00     (the bill obligation, settled IN FULL)
    Cr Bank / Cash                 950.00   (net cash actually paid to the supplier)
    Cr WHT Payable                  50.00   (withheld, owed to the authority)
```

**The load-bearing insight (Gate-1 dec. 3, verified below).** Because bill settlement is **gross** — the
1,000 obligation is discharged in full, the 50 reclassified from Trade Payables to WHT Payable rather than
left owing to the supplier — the payment's own money figure is **unchanged from 8a**:

- `supplier_payments.amount = Σ allocations = gross = 1,000`, exactly as before
  (`PaymentPostingMap.php:42-46`, `PaymentService.php:153-156`).
- The deferred full-allocation constraint trigger, which asserts `Σ payment_allocations.amount = supplier_payments.amount`
  at commit (`2026_09_03_000005_make_posted_payments_immutable.php:122-177`), still holds untouched — WHT
  never enters that equation.
- Each settled bill still moves by the **gross** allocated: `amount_paid += allocation`,
  `amount_due = total − amount_paid`, flip to `Paid`/`PartiallyPaid` (`PaymentService.php:263-273`) — no
  change.

WHT touches only two things: **(1)** the payment header snapshots how much was withheld, and **(2)** the
posting map splits the single credit into two. Steps 11–17 of `record()` — lock, allocations, gross bill
movement, deferred trigger — are **literally unchanged** (§C2 confirms line by line). This is why 8b is an
extension, not a rewrite.

**Two parts of 8b are genuinely first-of-its-kind on this line, and carry the design weight:**

1. **`WHT Payable` is a brand-new account that no prior chart template ever defined.** The trade-receivables
   and trade-payables backfills *stamp a key onto an existing* `1130`/`2110`
   (`2026_03_05_000003_stamp_trade_receivables_system_key.php`,
   `2026_09_02_000001_stamp_trade_payables_system_key.php`). `2180 WHT Payable` exists in **none** of
   `2026.02-lk-sme-1`, `2026.08-lk-sme-2`, `2026.09-lk-sme-3` (the current template jumps `2170 ETF` →
   `2200`, `ChartTemplate.php:106-108`). So the backfill must **create**, not stamp — a new backfill shape.
   §A6 is the whole answer, and Risk 1 the whole warning.
2. **The pre-VAT base of a *partial* allocation must be derived** from the bill's stored `subtotal`/`total`,
   because WHT is on the service value, not the VAT (Gate-1 dec. 2), and an allocation may settle only part
   of a bill. §C2 derives it; Risk 2 warns on it.

Everything else is grounded, line for line, in three present, finished originals: the Wave 8a payment
(`PaymentService`, `PaymentPostingMap`, `SupplierPayment`), the bill's input-VAT posting
(`BillPostingMap.php:187-241` — the precedent for a tax line resolved by key and validated by type), and the
two stamp backfills (the RLS-bypass-and-assert scaffold, reproduced with the create difference).

## Binding Gate-1 decisions (verbatim from `docs/PHASE-5-WHT-ON-PAYMENT-REQUIREMENTS.md` → Gate 1 — APPROVED 2026-09-09)

1. **WHT rate source = per-supplier rate + flag** — add `wht_applicable` + `wht_rate` to `suppliers`; a
   withholding supplier's payments withhold at that rate, with an optional per-payment override. WHT tax
   codes deferred.
2. **WHT base = the allocated amount per bill on the pre-VAT subtotal share** — WHT is on the service value,
   not the VAT.
3. **Bill settlement = GROSS** (the crux): 950 cash + 50 WHT settling a 1,000 bill moves `amount_paid` by the
   full **1,000**; the bill flips to `Paid`; the payable balance drops by gross. The 50 is reclassified Trade
   Payables → WHT Payable, not left owing to the supplier.
4. **`2180 WHT Payable`**, `Account::WHT_PAYABLE` (`'wht_payable'`), a Liability leaf under `2100`;
   provisioned via the template (new companies) + a backfill migration (existing companies, under bypassed
   RLS with the effectiveness assertion); `ChartTemplate::VERSION` bumped `…-3` → `…-4`.
5. **Rounding** consistent with VAT — WHT at `Money::SCALE` then currency precision; net cash = payment − WHT
   so the entry balances.
6. **Permissions = reuse `purchasing.payments.post`** — no new capability; record-and-post lifecycle,
   immutability, born-posted freeze all unchanged.
7. **One WHT rate per payment** for 8b; per-bill / per-service-type rates deferred.

Deferred scope confirmed: no WHT certificates, no WHT return, no remittance-to-authority payment, no WHT on
non-bill payments, no SVAT/reverse-charge edge cases this slice.

---

# A. Schema

## A0. What 8b adds that 8a deliberately did not — the WHT Payable three-part cost (Gate-1 APPROVED dec. 4)

Wave 8a added **no** account, template change or backfill, and said so explicitly: "The one and only account
this wave *would* have added — `WHT Payable` — is Wave 8b (Gate-1 dec. 6), and its whole three-part cost
(leaf + key + backfill) travels with it" (ADR 0020 §A0). This wave pays that cost, and adds two column-pairs
besides:

| Addition | Where | Realises |
| --- | --- | --- |
| `WHT Payable` leaf + `Account::WHT_PAYABLE` key + template VERSION bump | `Account.php`, `ChartTemplate.php` (§A5) | dec. 4 |
| Backfill of `WHT Payable` into existing companies (**create**, not stamp) | new Accounting migration (§A6) | dec. 4 |
| `wht_applicable` + `wht_rate` on `suppliers` | new Purchasing migration (§A2) | dec. 1 |
| `wht_amount` + `wht_rate` snapshot on `supplier_payments`, and the freeze-trigger update | new Purchasing migrations (§A3, §A4) | dec. 3, 5, 7 |

## A1. Migration files (Gate-2 PROPOSED)

Four migrations, dated after the Wave 8a set (`…09_03_000005`). Three live under Purchasing (its tables),
one under Accounting (the `accounts` table is Accounting-owned — the same home as the two stamp precedents).
Filename order is the global run order; the four are mutually independent except that the freeze update
(`…000004`) needs the `supplier_payments` WHT columns (`…000003`) to exist first.

| File | Module | Does |
| --- | --- | --- |
| `2026_09_09_000001_provision_wht_payable_account.php` | Accounting | Creates `2180 WHT Payable` (keyed) in every existing company that lacks it, under bypassed RLS with both effectiveness assertions (§A6). |
| `2026_09_09_000002_add_withholding_to_suppliers.php` | Purchasing | `suppliers.wht_applicable` + `wht_rate` + CHECKs (§A2). |
| `2026_09_09_000003_add_withholding_to_supplier_payments.php` | Purchasing | `supplier_payments.wht_amount` + `wht_rate` + CHECKs (§A3). |
| `2026_09_09_000004_freeze_withholding_columns_on_posted_payments.php` | Purchasing | `CREATE OR REPLACE FUNCTION asids_supplier_payments_immutable()` adding the two new columns to the frozen-by-name list (§A4). |

The `Account::WHT_PAYABLE` const, the `ChartTemplate` leaf/`requiredSystemAccounts`/VERSION changes, and the
model/DTO/service edits are **code**, not migrations — they ship in the same wave (§E stages) and are cited
where they belong. The backfill migration (`…000001`) is deliberately self-contained raw SQL and does **not**
call `ChartTemplate`, so it cannot drift if the template changes later (§A6).

## A2. `suppliers` — the per-supplier WHT configuration (Gate-2 PROPOSED, realising Gate-1 dec. 1)

`suppliers` already carries `tax_identification_number`, retained at Wave 6 "pre-provisioning Wave 8
supplier-WHT/compliance" (`2026_09_01_000001_create_suppliers_table.php:56-57`). This wave adds the rate it
was reserved for. Two columns, in the migration's `Schema::table('suppliers', …)`:

| Column | Type | Notes |
| --- | --- | --- |
| `wht_applicable` | `boolean` NOT NULL default `false` | Whether this supplier's payments withhold by default. Default `false` — an existing supplier is non-withholding until configured, and a non-withholding supplier's payments are byte-for-byte 8a (AC-1.2). |
| `wht_rate` | `decimal(5,2)` **nullable** | A **percentage** (`5.00` = 5%), not a fraction (dec. 1). `decimal(5,2)` holds `0.00`–`999.99`; the range CHECK caps it at 100. Nullable — a non-withholding supplier has no rate. |

Two CHECK constraints, both **non-vacuously** testable (§F, the `SupplierSchemaCheckMutationTest` lesson,
commit `80cc5a9`):

```sql
-- Rate in [0,100] when present. Mirrors the shape of an existing bounded numeric expectation.
ALTER TABLE suppliers ADD CONSTRAINT suppliers_wht_rate_range_check
    CHECK (wht_rate IS NULL OR (wht_rate >= 0 AND wht_rate <= 100));

-- Required IFF applicable — the same biconditional shape as suppliers_archived_check
-- ((status='archived') = (archived_at IS NOT NULL), create_suppliers_table.php:116-120):
--   applicable  ⟹ a rate is present;  not applicable ⟹ no rate.
ALTER TABLE suppliers ADD CONSTRAINT suppliers_wht_applicable_requires_rate_check
    CHECK (wht_applicable = (wht_rate IS NOT NULL));
```

**Model/DTO/service touch (Gate-2 PROPOSED):**

- `Supplier.php`: add `wht_applicable`, `wht_rate` to `$fillable` (`Supplier.php:78-96`); to `casts()`
  (`'wht_applicable' => 'boolean'`, `'wht_rate' => 'decimal:2'`, `Supplier.php:212-220`); and to `auditOnly()`
  (`Supplier.php:187-199`) — a changed WHT rate is exactly the auditable change the TIN was added for
  (`Supplier.php:181-184`).
- `SupplierData.php`: two new constructor params `bool $whtApplicable = false`, `?string $whtRate = null`,
  mapped in `fromArray()` (`SupplierData.php:22-73`).
- `SupplierService` create/update: map the two fields, and validate the DTO-level pairing (rate present iff
  applicable, rate ∈ [0,100]) with a readable `BusinessRuleViolation` before the DB CHECK surfaces as a
  constraint name — the "service message + database authority" split used throughout (ADR 0020 §A4).

**(UNRESOLVED — needs human approval) fork 1:** whether 8b wires the supplier **write path**
(`SupplierData`/`SupplierService`) so a withholding supplier can be configured now, or whether 8b lands only
the schema + payment posting and a later slice wires the supplier edit fields. My recommendation: **wire it in
8b** — the schema is inert without a way to set `wht_applicable`/`wht_rate`, and the two-field extension is
small and well-bounded. Flagged because it widens 8b beyond the payment itself.

## A3. `supplier_payments` — the WHT snapshot (Gate-2 PROPOSED, realising Gate-1 dec. 3, 5, 7)

Two columns on the payment header. The header snapshots the withheld total and the rate applied; the
per-bill split is **not** stored — `payment_allocations` is unchanged (its `amount` is the **gross**
allocated per bill, dec. 3), so the subledger and the deferred full-allocation trigger are untouched.

| Column | Type | Notes |
| --- | --- | --- |
| `wht_amount` | `decimal(19,4)` **NOT NULL default 0** | The total withheld, rounded to currency precision (§C3). `0` for a non-withholding payment — the default makes every existing/zero-WHT payment 8a-identical. `Cr WHT Payable = wht_amount`; `Cr Bank = amount − wht_amount`. |
| `wht_rate` | `decimal(5,2)` **nullable** | The one rate applied (dec. 7), snapshotted for audit/reporting and to reconstruct the split. NULL when no WHT was withheld. |

CHECK constraints:

```sql
ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_wht_amount_non_negative_check
    CHECK (wht_amount >= 0);                                          -- FR-4
-- Net cash = amount − wht_amount ≥ 0, expressed as the DB authority behind the service's readable check.
ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_wht_within_amount_check
    CHECK (wht_amount <= amount);                                     -- FR-4, AC-1.4
ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_wht_rate_range_check
    CHECK (wht_rate IS NULL OR (wht_rate >= 0 AND wht_rate <= 100));
-- A positive withholding must record the rate it used. (A zero withholding may leave the rate NULL —
-- see "zero-WHT == 8a" below and fork 3.)
ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_wht_rate_present_when_withheld_check
    CHECK (wht_amount = 0 OR wht_rate IS NOT NULL);
```

**Model touch:** `SupplierPayment.php` — add `wht_amount`/`wht_rate` to `casts()` (`'wht_amount' => 'decimal:4'`,
`'wht_rate' => 'decimal:2'`, `SupplierPayment.php:214-223`) and to `auditOnly()` (`SupplierPayment.php:190-201`).
Not `$fillable` — every figure is service-computed (`SupplierPayment.php:87-96`).

## A4. Immutability — freeze the new columns by name (Gate-2 PROPOSED; the column-drift risk)

The payment freeze trigger `asids_supplier_payments_immutable()` freezes every identifying column **by name**,
not `OLD IS DISTINCT FROM NEW`, precisely so "a column added later and forgotten is not silently mutable"
(`2026_09_03_000005_make_posted_payments_immutable.php:22-25,63-82`). 8b adds two such columns, so the freeze
list **must** grow with them or Risk 3 (freeze-trigger column drift) is realised: a posted payment's
`wht_amount`/`wht_rate` would be quietly editable, breaking the "a posted WHT payment is immutable" guarantee
(AC-2.2).

`2026_09_09_000004_freeze_withholding_columns_on_posted_payments.php` issues a **`CREATE OR REPLACE FUNCTION
asids_supplier_payments_immutable()`** restating the whole body (that is how replace works) with the two
columns added to the `IS DISTINCT FROM` block, immediately after `amount`
(`…000005…:74`):

```sql
    ...
    OR NEW.amount IS DISTINCT FROM OLD.amount
    OR NEW.wht_amount IS DISTINCT FROM OLD.wht_amount     -- new
    OR NEW.wht_rate IS DISTINCT FROM OLD.wht_rate         -- new
    OR NEW.reference IS DISTINCT FROM OLD.reference
    ...
```

Nothing else about the trigger changes — the `DELETE`/`cancelled`/`NEW.status <> 'cancelled'` gates and the
`BEFORE UPDATE OR DELETE` binding (`…000005…:46-100`) are reproduced verbatim. `down()` restores the prior
body (without the two columns). No change to the allocation freeze or the deferred full-allocation trigger —
they never referenced these columns.

## A5. `WHT Payable` chart leaf + key + VERSION bump (Gate-1 APPROVED dec. 4; realisation Gate-2 PROPOSED)

Four code changes, mirroring how `TRADE_PAYABLES` was added at Wave 7:

1. **`Account::WHT_PAYABLE`** — a new const beside `TRADE_PAYABLES` (`Account.php:80`):
   ```php
   /** Where a withholding payment's withheld tax lands — the liability owed to the authority until remitted.
    *  Resolved by key, never by code (a company may renumber its chart), exactly as TRADE_PAYABLES. */
   public const string WHT_PAYABLE = 'wht_payable';
   ```
2. **`ChartTemplate::accounts()`** — insert after `2170 ETF Payable` (`ChartTemplate.php:106`), before
   `2200`:
   ```php
   self::leaf('2180', 'WHT Payable', AccountType::Liability, parent: '2100', system: Account::WHT_PAYABLE),
   ```
   A Liability leaf under `2100 Current Liabilities` (dec. 4), keyed — so new companies applying the template
   get it, keyed, at provisioning (`ChartTemplateService::apply()`, `ChartTemplateService.php:45-66`).
3. **`ChartTemplate::requiredSystemAccounts()`** — add the same leaf (no parent, as the others there,
   `ChartTemplate.php:157-169`):
   ```php
   // Added by Phase 5 Wave 8b. A withholding payment credits it, so a company that skipped the starter
   // chart must still receive it — or its first WHT payment fails to post.
   self::leaf('2180', 'WHT Payable', AccountType::Liability, system: Account::WHT_PAYABLE),
   ```
   This makes `ChartTemplateService::ensureSystemAccounts()` (`ChartTemplateService.php:78-104`) provision it
   on demand for an empty-chart company and as an idempotent safety net — the same status Trade Payables
   holds (`ChartTemplate.php:165-167`).
4. **`ChartTemplate::VERSION`** — bump `'2026.09-lk-sme-3'` → `'2026.09-lk-sme-4'` (`ChartTemplate.php:40`),
   because the account set changed (an addition is exactly what the doc-comment says the bump is for,
   `ChartTemplate.php:36-40`). New companies stamp `-4`; existing companies stay on their version and get the
   account from the §A6 backfill.

**Consequence to schedule:** four Accounting tests pin the current VERSION string —
`tests/Feature/Accounting/{TradePayablesSystemAccountTest,AccountingApiTest,TradeReceivablesSystemAccountTest,ChartOfAccountsTest}.php`.
The stage that bumps VERSION updates those `-3` → `-4` assertions, exactly as Wave 7's `-2` → `-3` bump did.

## A6. The `WHT Payable` backfill — CREATE, not stamp (Gate-2 PROPOSED; the recurring zero-rows trap)

**This is the one part of 8b with no faithful precedent, and it must not be written as if it had one.** The
trade-receivables/payables migrations **stamp a `system_key` onto an existing** `1130`/`2110` — an account
every prior template already created, keyless (`…stamp_trade_receivables…:84-103`, `…stamp_trade_payables…:82-101`).
`2180 WHT Payable` exists in **no** prior template, so there is nothing to stamp: the backfill must **create**
the account in each existing company.

It reproduces the precedents' RLS scaffold exactly, and diverges only in the operation:

**Reproduced verbatim from the precedents (the recurring trap, Gate-1 Risk / FR-2):**

- Runs inside `RowLevelSecurity::bypass(function () { … })` (`…stamp_trade_payables…:77`). Migrations run as
  `asids_app`, which is `NOBYPASSRLS`, and `accounts` is FORCED — so with no tenant published a data
  migration sees **zero** rows and reports success having created nothing.
- `assertBypassEffective()` — refuses to proceed unless `SELECT asids_rls_bypassed()::int = 1`, cast in SQL
  (PDO can return `'f'`, and `(bool) 'f'` is `true`) (`…stamp_trade_payables…:142-152`).
- `assertNothingLeftBehind()` — after running, asserts **zero** existing companies still lack a `wht_payable`
  account; a partial run (a row skipped, a concurrent insert) otherwise leaves a company whose first WHT
  payment fails months later for a reason nothing recorded (`…stamp_trade_payables…:164-193`).
- **Raw SQL, never Eloquent.** Verified necessary, not stylistic: `RowLevelSecurity::bypass()` suspends only
  the **database** policy (`RowLevelSecurity.php:36-53`); it does **not** remove Eloquent's `TenantScope`,
  and `BelongsToTenant::creating` throws `NoActiveTenant` when it saves with no active tenant
  (`BelongsToTenant.php:48-63`). So `ensureSystemAccounts()` (Eloquent) cannot run in a tenant-less migration
  — the same reason the stamps use raw `DB::update` (`…stamp_trade_receivables…:76-80`). The backfill reads
  and writes with raw SQL only.

**The create difference (Gate-2 PROPOSED):**

- **Idempotency is keyed on the account's absence, not on `template_version`.** The stamps guard on
  `template_version IN (prior versions)` because they match by **code** and must not touch a hand-made
  `1130`/`2110` (`…stamp_trade_payables…:89-100`). The WHT backfill matches by **key absence** — "this
  company has no `wht_payable` account" — which naturally covers **all** prior versions (`-1`, `-2`, `-3`)
  and is re-run-safe (a company that already has the key, including a fresh `-4` company from the template,
  is skipped). It needs **no** `template_version` guard.
- **A code-collision guard replaces the version guard.** The unique index
  `accounts_company_code_unique (company_id, lower(code)) WHERE deleted_at IS NULL`
  (`2026_02_02_000001_create_accounts_table.php:104-108`) forbids two `2180`s in one company. No template
  ever defined `2180`, so a collision can only come from a hand-numbered chart — but it must be handled, not
  hit as a raw constraint error. The backfill reproduces `ChartTemplateService::availableCode()`
  (`ChartTemplateService.php:113-141`): prefer `2180`, else the first free `2180-1`, `2180-2`, ….
- **Parent resolution.** Attach the new leaf to the company's `2100 Current Liabilities` heading when it is
  resolvable (`code = '2100' AND type = 'liability' AND deleted_at IS NULL`), else `parent_id = NULL` — a
  renumbered company keeps a valid (flat) system account, exactly as `ensureSystemAccounts()` creates the
  required accounts with no parent (`ChartTemplateService.php:93-100`). `parent_id = NULL` satisfies
  `accounts_not_own_parent_check` (`…create_accounts_table…:141`).

**Recommended mechanism — a PHP loop over companies, raw SQL per row (Gate-2 PROPOSED):** inside the bypass,
`SELECT id, tenant_id FROM companies`, and for each company that has no live `wht_payable` account, compute
the available code and the parent id with raw `SELECT`s, then one raw `INSERT` with every column the CHECKs
require set explicitly:

```
id               = (string) Str::uuid()      -- or gen_random_uuid() in SQL
tenant_id        = <company.tenant_id>        -- the migration has no tenant; bind it from the company row
company_id       = <company.id>
parent_id        = <resolved 2100, or NULL>
code             = <availableCode(2180)>
name             = 'WHT Payable'
type             = 'liability'
normal_balance   = 'credit'                   -- accounts_normal_balance_matches_type_check: liability ⟹ credit
is_postable      = true
is_system        = true                       -- accounts_system_key_check: (system_key NOT NULL) <= is_system
system_key       = 'wht_payable'
is_active        = true                       -- accounts_archived_check: is_active = (archived_at IS NULL)
archived_at      = NULL
template_version = NULL                        -- created by a migration, not a template — as ensureSystemAccounts does
sort_order       = 2180
created_at/updated_at = now()
```

The per-row loop is chosen over a single `INSERT … SELECT … FROM companies` because a single statement cannot
compute a per-company available code (the `2180-N` fallback), and skipping a colliding company would make
`assertNothingLeftBehind()` fail. **(UNRESOLVED — needs human approval) fork 2:** confirm the per-company
loop with collision-and-parent resolution, over the thinner single `INSERT … SELECT` that assumes `2180` is
always free (simpler, but raises on any hand-numbered collision). I recommend the loop for correctness on a
real installed base.

`down()` deletes only the accounts this migration created — `system_key = 'wht_payable' AND is_system = true`
with no journal lines — under the same bypass + `assertBypassEffective()`, mirroring the stamps' scoped
rollback (`…stamp_trade_payables…:107-129`). A `WHT Payable` that has already taken a posting is **not**
deleted (a system account with history is never hard-deleted, `…create_accounts_table…:24-29`); the down path
refuses or skips it rather than violating the restrict-on-delete FK.

---

# B. `PaymentPostingMap` — the credit-side split (Gate-1 APPROVED dec. 3, 4; realisation Gate-2 PROPOSED)

The debit is unchanged; only the credit splits. `for()` gains a `Money $wht` parameter and, when it is
positive, resolves `WHT Payable` and emits a second credit:

```php
/**
 * @return list<JournalLineData>
 *   $wht = 0  → Dr Trade Payables (total) = Cr Bank/Cash (total)          (exactly Wave 8a, AC-1.2)
 *   $wht > 0  → Dr Trade Payables (total) = Cr Bank/Cash (total − wht) + Cr WHT Payable (wht)   (AC-1.1)
 */
public function for(
    Company $company,
    Account $bankAccount,
    Money $total,
    Money $wht,              // NEW — Money::zero($company->base_currency_code) when none
    Supplier $supplier,
    ?string $branchId = null,
): array;

public function whtPayableAccountFor(Company $company): Account;   // NEW — mirror payableAccountFor
```

The exact body (extending `PaymentPostingMap.php:59-70`):

```php
$payable = $this->payableAccountFor($company);
$bank    = $this->assertBankAccount($company, $bankAccount);
$net     = $total->minus($wht);                                    // wht ≤ total guaranteed (§C2, DB CHECK)

$lines = [$this->line($payable, $total, $narration, $branchId, creditSide: false)];   // Dr Trade Payables (gross) — unchanged

if ($net->isPositive()) {                                          // omit a zero bank line (100%-withheld edge)
    $lines[] = $this->line($bank, $net, $narration, $branchId, creditSide: true);
}
if ($wht->isPositive()) {                                          // the third line, only when withholding
    $whtAccount = $this->whtPayableAccountFor($company);
    $lines[] = $this->line($whtAccount, $wht, $narration, $branchId, creditSide: true);
}
return $lines;
```

- **The entry balances by construction.** `Dr total = Cr net + Cr wht = (total − wht) + wht = total`, for any
  `wht`, exactly the guarantee dec. 5 relies on (net cash is *defined* as `total − wht`). No rounding happens
  in the map — it reads a `wht` the service already rounded (§C3), the same way `BillPostingMap` sums
  pre-rounded `tax_amount` and never rounds (`BillPostingMap.php:43-50`).
- **`Money::minus`/`isPositive`** are the value object's own (`Money.php:117,259`). `Money::of` and
  `Money::zero` are already used here (`PaymentPostingMap.php`).
- **`whtPayableAccountFor()` mirrors `payableAccountFor()`'s checks exactly** (`PaymentPostingMap.php:80-96`),
  resolved by `Account::WHT_PAYABLE`: `PaymentCannotBePosted::withoutWhtPayableAccount` if absent,
  `whtPayableAccountWrongType` if not `AccountType::Liability`, `accountNotPostable` if not
  `acceptsPostings()` — the same three checks `BillPostingMap` makes on its input-VAT account, and Trade
  Payables on its own (`BillPostingMap.php:100-108,225-229`; AC-1.3).
- **Resolved only when `wht > 0`.** A zero-WHT payment never touches `WHT Payable`, so a company that has not
  been backfilled (or never withholds) still posts the 8a two-line entry (AC-1.2). This is why the resolution
  sits inside the `if ($wht->isPositive())` branch, not at the top.
- **The 100%-withheld net-zero edge:** a 100% rate on a VAT-free, fully-allocated bill yields `net = 0`. The
  `if ($net->isPositive())` guard omits the zero bank line, which `journal_lines_one_sided_check` would
  refuse anyway (the same reason `BillPostingMap` skips zero lines, `BillPostingMap.php:118-122`). `total > 0`
  always, so at least one credit line always exists.

Two new factories on `PaymentCannotBePosted` (mirroring `withoutPayableAccount`/`payableAccountWrongType`,
`PaymentCannotBePosted.php:44-81`), each with a stable problem code:
`withoutWhtPayableAccount(string $companyName)` → `wht-payable-account-missing`;
`whtPayableAccountWrongType(Account $account)` → `wht-payable-account-wrong-type`. `accountNotPostable`
already exists and is reused with role `'WHT payable'` (`PaymentCannotBePosted.php:108-121`).

---

# C. Service and DTO

## C1. `PaymentData` — the WHT instruction shape (Gate-2 PROPOSED, realising Gate-1 dec. 1)

Two new optional fields on `PaymentData` (`PaymentData.php:28-37`), both defaulting to "follow the supplier":

```php
public ?bool   $withholdTax     = null,   // null = follow supplier.wht_applicable; true/false force on/off for THIS payment
public ?string $whtRateOverride = null,   // numeric-string percentage; overrides supplier.wht_rate when withholding
```

Mapped in `fromArray()` from `withhold_tax` / `wht_rate_override` (`PaymentData.php:42-63`). No caller-supplied
WHT **amount**.

**Why intent-in, amount-computed — and how it mirrors Wave 8a's `amount`.** Wave 8a made `amount` *supplied
and validated* because the caller genuinely knows the intended total and the service can cross-check it
against `Σ allocations` (`PaymentData.php:14-17`, ADR 0020 §C3). WHT is the opposite case: the withheld
amount depends on each bill's **pre-VAT proportional share** (§C2), which only the service can compute — a
caller-supplied amount could be "validated" only by re-deriving it, so it would add surface without adding a
real cross-check. So the caller supplies the **intent** (withhold or not, at what rate) and the service is the
authority on the **amount** — the same division of labour, applied to the field the caller actually knows.

The tri-state `withholdTax` + optional rate is the minimal shape covering all four real cases without
ambiguity:

| Supplier | `withholdTax` | `whtRateOverride` | Result |
| --- | --- | --- | --- |
| non-withholding | `null` | `null` | no WHT — 8a two-line entry (AC-1.2) |
| withholding | `null` | `null` | WHT at `supplier.wht_rate` (dec. 1 default) |
| withholding | `false` | — | suppressed this payment (dec. 1 "unless overridden") |
| withholding | `null`/`true` | `'10.00'` | WHT at 10% instead of the supplier's rate |
| non-withholding | `true` | `'10.00'` | one-off withhold at 10% (explicit) |

**(UNRESOLVED — needs human approval) fork 3:** confirm this shape — a tri-state flag + optional **rate**
override, service-computed amount — over (a) an explicit caller-supplied **amount** override (rejected above)
or (b) a two-value `wht_rate` alone with no suppress flag (cannot express "suppress a withholding supplier for
one payment"). I recommend the shape above.

## C2. `PaymentService::record()` — where WHT enters, and the proof steps 11–17 are untouched (Gate-2 PROPOSED)

WHT is computed **pre-transaction** (a free refusal, like everything else that can refuse — ADR 0020 §C4),
inserted between the existing step 8 (full-allocation Σ-check) and step 9 (build lines). The bills are already
loaded and validated at step 7 (`PaymentService.php:124-151`), and a posted bill's `subtotal`/`total` are
immutable, so no extra query and no lock are needed to read them.

**New step 8a — resolve the effective rate:**

```php
$apply = $data->withholdTax ?? $supplier->wht_applicable;         // supplier default unless the caller forces it
$rate  = null;
if ($apply) {
    $rate = $data->whtRateOverride ?? $supplier->wht_rate;        // supplier rate unless overridden
    if ($rate === null) {
        throw PaymentCannotBeRecorded::whtRateMissing($supplier->name);        // told to withhold, no rate anywhere
    }
    $rate = $this->assertDecimal($rate);
    if (bccomp($rate, '0', 2) < 0 || bccomp($rate, '100', 2) > 0) {
        throw PaymentCannotBeRecorded::whtRateOutOfRange($rate);               // caller-supplied override is range-checked here
    }
}
```

**New step 8b — the WHT amount, from the pre-VAT base of each allocated bill (Gate-1 dec. 2, 5):**

A bill stores `subtotal` (pre-VAT, net of discount) and `total = subtotal + tax_total`
(`2026_09_02_000002_create_bills_table.php:76,79`, CHECK `bills_total_check` `:162-163`). WHT is on the
service value, so the pre-VAT share of an allocation that pays `allocation` of a bill's `total` is
`allocation × subtotal / total` — which equals `subtotal` exactly when the bill is fully allocated
(`allocation == total`), and is the proportional part when it is partial:

```php
$base = '0';
foreach ($data->allocations as $allocation) {
    $bill = $bills[$allocation->billId];                          // loaded & validated at step 7
    // Guard: an outstanding bill has total > 0 (a zero bill has amount_due 0 and cannot be allocated,
    // PaymentService.php:140-150), so the divide is always safe.
    $share = bcdiv(
        bcmul($this->assertDecimal($allocation->amount), $bill->subtotal, Money::SCALE + 4),
        $bill->total,
        Money::SCALE,                                             // each bill's pre-VAT share rounded at Money::SCALE (dec. 5)
    );
    $base = bcadd($base, $share, Money::SCALE);
}

// One rate (dec. 7): rate × Σ bases, rounded once to currency precision (dec. 5), consistent with VAT.
$whtRaw   = bcdiv(bcmul($base, $rate, Money::SCALE + 4), '100', Money::SCALE);
$wht      = $rate === null ? Money::zero($ccy) : Money::of($whtRaw, $ccy)->roundedTo($this->currencyPrecision($ccy));
$whtValue = $wht->toDecimalString();                             // stored in supplier_payments.wht_amount
```

- `$ccy = $company->base_currency_code` (already used at `PaymentService.php:160,238`). `Money::roundedTo()`
  rounds to the currency's minor units (`Money.php:240-252`) — the VAT-consistent second rounding step
  (dec. 5). If a currency-precision helper is not already available to the service, it is the same lookup VAT
  uses; this ADR does not invent a new API.
- **`wht ≤ amount` holds by construction:** each `share ≤ allocation` (since `subtotal ≤ total`), so
  `base ≤ Σ allocation = amount`; with `rate ≤ 100`, `wht ≤ base ≤ amount`. The DB CHECK
  `supplier_payments_wht_within_amount_check` (§A3) is the authority; the service adds a readable
  `PaymentCannotBeRecorded::whtExceedsAmount` as a defensive backstop (largely unreachable once the rate is
  range-checked, but it makes the net-cash-≥-0 rule explicit, FR-4).

**(UNRESOLVED — needs human approval) fork 4 (rounding of a multi-bill withholding):** with one rate, `rate ×
Σ bases` (round **once**, recommended above) can differ by a minor unit from `Σ round(rate × base_i)` (round
per bill). I recommend round-once — it is the literal reading of dec. 5 ("WHT at `Money::SCALE` then currency
precision"), minimises drift, and matches how VAT rounds a computed figure once. Flagged because it is a
cent-level policy choice on multi-bill payments.

**New step 9 (amended) — pass the WHT to the map:**
`$lines = $this->postingMap->for($company, $bankAccount, Money::of($amount, $ccy), $wht, $supplier, $branchId);`
(the only change to `PaymentService.php:160`).

**New step 14 (amended) — snapshot on the header:** two assignments added beside `$payment->amount = $amount`
(`PaymentService.php:240`): `$payment->wht_amount = $whtValue;` and
`$payment->wht_rate = $wht->isPositive() ? $rate : null;` (rate snapshotted only when something was actually
withheld — keeps a zero-WHT header 8a-identical, §A3, fork 5). Set explicitly like every other attribute, so
an unsaved instance reads back the same as a saved one under `Model::shouldBeStrict()` (ADR 0020 §A7).

**Steps 11, 12, 13, 15, 16, 17 — UNCHANGED, and here is the proof (Gate-1 dec. 3):**

| Step | What it does | Why WHT does not touch it |
| --- | --- | --- |
| 11 lock bills | `whereKey($billIds)->orderBy('id')->lockForUpdate()` (`PaymentService.php:186-191`) | WHT reads `subtotal`/`total` (immutable); it changes no bill and needs no extra lock. |
| 12 re-validate under lock | still-outstanding + `allocation ≤ amount_due` (`:201-213`) | The cap is on the **gross** allocation, unchanged (dec. 3). |
| 13 number + JV | draw `PAY`, `postNew` (`:220-229`) | Same lifecycle; only the `$lines` handed to `postNew` differ, built at step 9. |
| 15 allocation rows | one row per bill, `amount` = **gross** (`:251-258`) | Allocations are gross (dec. 3). WHT is nowhere in the subledger. |
| 16 move bills | `amount_paid += allocation` (gross), status flip (`:263-273`) | Gross settlement (dec. 3): the bill moves by 1,000, not 950. |
| 17 commit | deferred trigger asserts `Σ allocations = amount` (`:275-276`) | `Σ allocations = amount = gross` still, so the trigger passes exactly as in 8a. |

This table is the verification the requirements asked for: **settlement is gross, so 8a's money invariants are
undisturbed and WHT is purely additive** — one header snapshot and one extra credit line.

Two new `PaymentCannotBeRecorded` factories (mirroring the existing style — message, problem code, context,
`PaymentCannotBeRecorded.php:25-160`): `whtRateMissing(string $supplier)` → `payment-wht-rate-missing`;
`whtRateOutOfRange(string $rate)` → `payment-wht-rate-out-of-range`; and the defensive
`whtExceedsAmount(string $wht, string $amount)` → `payment-wht-exceeds-amount`.

## C3. Rounding (Gate-1 APPROVED dec. 5)

Two steps, both above and both VAT-consistent: (1) each bill's pre-VAT **share** is computed at `Money::SCALE`
(4dp) with `bcdiv`/`bcmul`, no float; (2) the summed base × rate is rounded **once** to the currency's minor
units via `Money::roundedTo()` (`Money.php:240-252`) — exactly the precision VAT is stored at. Net cash is
then `amount − wht` (`Money::minus`), so the journal entry balances by construction regardless of the rounding
outcome (§B). All comparisons via `bccomp`/`bcadd`/`bcdiv` at `Money::SCALE` (mirror
`PaymentService.php:100-156`, `EloquentPayableBalanceProbe.php:65`).

## C4. Authorization — unchanged (Gate-1 APPROVED dec. 6)

**No catalogue, role, or policy change.** A WHT payment is still recording-and-posting a supplier payment, so
it reuses `purchasing.payments.post` (sensitive) unchanged (ADR 0020 §E, `PermissionCatalogue::purchasing()`
`:283-301`, `PaymentPolicy::create`). No new capability, no new grant, no `.wht` sub-permission. §F includes a
test that asserts the capability set is unchanged, so a silent scope creep is caught.

---

# D. Build stages (test-first, RED → GREEN, independently reviewable)

Each stage is an independently reviewable RED→GREEN cycle: QA writes the failing tests first, the engineer
implements to green. No stage folds another's reviewer gate. Ordered so each stage's dependencies are already
green.

| Stage | Deliverable | Files (create unless noted) | Test-first artefact | Green when |
| --- | --- | --- | --- | --- |
| **1. Supplier WHT config** | `suppliers.wht_applicable`/`wht_rate` + CHECKs; model casts/fillable/audit; `SupplierData` + `SupplierService` two-field wiring. | `…Purchasing/…/2026_09_09_000002_add_withholding_to_suppliers.php`; **edit** `Supplier.php`, `SupplierData.php`, `SupplierService.php` | `SupplierWhtSchemaTest` + `SupplierWhtSchemaCheckMutationTest`: both columns; range CHECK **non-vacuously** rejects `wht_rate = 150`; the biconditional CHECK rejects applicable-without-rate **and** not-applicable-with-rate; a configured withholding supplier round-trips through `SupplierService`. | column + CHECKs enforce; supplier configurable |
| **2. WHT Payable account + template + backfill** | `Account::WHT_PAYABLE`; `ChartTemplate` leaf + `requiredSystemAccounts` + VERSION `-4`; the create-backfill migration. | **edit** `Account.php`, `ChartTemplate.php`; `…Accounting/…/2026_09_09_000001_provision_wht_payable_account.php` | `WhtPayableSystemAccountTest` (shape `TradePayablesSystemAccountTest`): template provisions `2180` keyed for a new company; `ensureSystemAccounts()` creates it for an empty-chart company; **backfill idempotent** (re-run stamps 0), **creates for a legacy `-1/-2/-3` company**, **`assertBypassEffective` trips if RLS not bypassed**, **`assertNothingLeftBehind` trips on a partial run**, **code-collision → `2180-1`**, parent = `2100` when present / NULL when renumbered; VERSION `-4` and the four pinned tests updated. | account provisioned everywhere, backfill proven effective |
| **3. Payment WHT snapshot + freeze** | `supplier_payments.wht_amount`/`wht_rate` + CHECKs; the `CREATE OR REPLACE` freeze update; model casts/audit. | `…/2026_09_09_000003_add_withholding_to_supplier_payments.php`, `…/2026_09_09_000004_freeze_withholding_columns_on_posted_payments.php`; **edit** `SupplierPayment.php` | `PaymentWhtSchemaTest` + `PaymentWhtSchemaCheckMutationTest`: both columns + defaults; `wht_amount >= 0`, `wht_amount <= amount`, rate range, rate-present-when-withheld each **non-vacuously** reject a crafted row; **the freeze trigger now refuses an UPDATE to `wht_amount` and to `wht_rate`** on a posted payment (the column-drift regression). | columns + CHECKs + freeze enforce |
| **4. DTO + posting-map split + exceptions** | `PaymentData` two fields; `PaymentPostingMap::for()` signature + split + `whtPayableAccountFor`; new `PaymentCannotBePosted` factories. | **edit** `PaymentData.php`, `PaymentPostingMap.php`, `PaymentCannotBePosted.php` | `PaymentPostingMapWhtTest` (extend `PaymentPostingMapTest`): `wht > 0` → **three** balanced lines `Dr TP (total) = Cr Bank (net) + Cr WHT (wht)`; `wht = 0` → **two** lines identical to 8a and WHT Payable **not resolved**; WHT Payable missing / wrong-type / not-postable each named; the 100%-withheld net-zero case emits no bank line. | map splits/keeps correctly |
| **5. `record()` WHT computation** | rate resolution + override + suppress; pre-VAT base (full & partial); net cash; snapshot; steps 11–17 unchanged. | **edit** `PaymentService.php`, `PaymentCannotBeRecorded.php` | `RecordWhtPaymentTest` (extend `RecordPaymentTest`): the worked example (1,000 → 950+50, bill `Paid`, payable −1,000); WHT on the **pre-VAT** base with VAT present; **partial allocation proportional base**; supplier-default vs override vs suppress; rate-out-of-range + rate-missing refused; **zero-WHT payment byte-identical to 8a** (same lines, `wht_amount = 0`, `wht_rate = NULL`); snapshot frozen (post-hoc UPDATE refused); `Σ allocations = amount` still passes. | full WHT lifecycle green |
| **6. Authorization unchanged (verification only)** | none. | — | `PaymentWhtAuthorizationTest`: the purchasing capability set is **unchanged** (no `.wht`), a `payments.post` holder records a WHT payment, a non-holder is refused — reuse proven, scope-creep caught. | authz unchanged |

---

# E. Test strategy (QA writes tests before implementation)

Shaped by the Wave 8a suites in `tests/Feature/Purchasing/` and the system-account suites in
`tests/Feature/Accounting/`, extended for WHT:

- **Schema + CHECK mutation** (shape `BillSchemaCheckMutationTest`, the `SupplierSchemaCheckMutationTest`
  lesson, commit `80cc5a9`): every new column/CHECK **non-vacuously** rejects a crafted bad row — supplier
  rate > 100, supplier applicable-without-rate and rate-without-applicable, payment `wht_amount < 0`,
  `wht_amount > amount`, rate present when withheld. Vacuous passes (a CHECK that never fires) are the failure
  mode this guards.
- **Immutability of the WHT columns** (shape the payment-freeze test): a posted payment refuses an UPDATE to
  `wht_amount` and to `wht_rate` — the direct regression for Risk 3 (freeze-trigger column drift). Absent the
  §A4 `CREATE OR REPLACE`, this test fails, which is the point.
- **Backfill effectiveness under bypassed RLS** (shape `TradePayablesSystemAccountTest` /
  `stamp_trade_payables` behaviour): idempotent re-run stamps zero; a legacy `-1/-2/-3` company gains a keyed
  `2180`; `assertBypassEffective()` trips when RLS is not bypassed (the recurring zero-rows trap, Risk 1);
  `assertNothingLeftBehind()` trips on a simulated partial run; a company already using `2180` gets `2180-1`;
  parent resolves to `2100` or NULL.
- **Posting map three-line and two-line** (extend `PaymentPostingMapTest`): the balanced three-line split;
  the two-line zero-WHT entry identical to 8a with WHT Payable never resolved; WHT Payable
  missing/wrong-type/not-postable each named; the net-zero edge omits the bank line.
- **Service WHT computation** (extend `RecordPaymentTest`): the worked example and its ledger; WHT on the
  pre-VAT base with VAT present (WHT on `subtotal`, not `total`); **partial-allocation proportional base**;
  supplier-default / override / suppress; rate range and rate-missing refusals; net cash ≥ 0; **gross
  settlement** (bill moves by gross, flips `Paid`, payable drops by gross); **zero-WHT == 8a** (identical
  lines and header); snapshot frozen.
- **Authorization unchanged** (shape `BillAuthorizationTest`): the capability set is unchanged; reuse of
  `payments.post` proven; no `.wht` capability introduced.

---

# Alternatives considered

- **Net settlement (bill moves by 950, stays `PartiallyPaid` with 50 due)** — rejected by Gate-1 dec. 3. It
  would leave a phantom 50 payable to a supplier already fully settled, and misstate what the company owes the
  authority. Gross settlement is what makes 8a's `amount = Σ allocations` and the deferred trigger hold
  untouched (§C2).
- **WHT tax codes (`TaxType::Wht` + rate-carrying codes)** — rejected by Gate-1 dec. 1. Heaviest option (a new
  code-table surface); per-supplier rate + optional override covers the SME case and uses the field Wave 6
  reserved. Codes can come later if per-service-type rates are needed (deferred, §5 of the requirements).
- **A separate WHT capability (`purchasing.payments.wht` / a distinct lifecycle)** — rejected by Gate-1 dec. 6.
  A WHT payment is the same money-moving action; reusing `payments.post` keeps the catalogue and roles
  unchanged (§C4).
- **Caller-supplied WHT amount (parallel to Wave 8a's `amount`)** — rejected (§C1). Unlike `amount`, the WHT
  amount is a service-computed function of per-bill pre-VAT proportional shares; a supplied amount could be
  validated only by re-deriving it, so it adds surface without a real cross-check. The caller supplies intent;
  the service owns the amount.
- **Per-bill WHT stored on `payment_allocations`** — rejected. dec. 3 keeps allocations gross and dec. 7 uses
  one rate per payment, so a single header `wht_amount`/`wht_rate` is sufficient and leaves the subledger and
  the deferred full-allocation trigger untouched. Per-line WHT is the deferred multi-rate slice.
- **Backfill as a STAMP (mirror the two precedents literally)** — rejected (§A6): `2180` exists in no prior
  template, so there is nothing to stamp; the backfill must **create**. Reproducing the stamps' RLS scaffold
  while changing the operation is the correct read of the precedent, not copying its UPDATE.
- **Single `INSERT … SELECT FROM companies` backfill** — kept as the simpler fallback (fork 2). Rejected as
  the default because it cannot compute a per-company available code and would raise on a hand-numbered `2180`
  collision; the per-company loop handles collision and parent resolution.
- **Round WHT per bill then sum** — kept as fork 4. Round-once is recommended as the literal reading of dec. 5
  and the VAT-consistent choice.

# Risks

- **The backfill silently touches zero rows (the recurring trap; Gate-1 Risk).** A NOBYPASSRLS role on a
  FORCED `accounts` table with no tenant published matches nothing and reports success. Mitigated exactly as
  the precedents: `RowLevelSecurity::bypass()` + `assertBypassEffective()` + `assertNothingLeftBehind()`, and
  a stage-2 test that trips each assertion.
- **The backfill CREATES, not stamps — a new shape (§A6).** Parent resolution and code collision have no
  stamp precedent. Mitigated by reproducing `availableCode()`/`ensureSystemAccounts()` semantics in raw SQL,
  a NULL-parent fallback for renumbered charts, and a collision test.
- **Pre-VAT base derivation for partial allocations (§C2, Gate-1 Risk 2 analogue).** A wrong base withholds on
  the VAT or on the wrong fraction. Mitigated by the proportional `allocation × subtotal / total` derivation
  (exact when fully allocated), the `total > 0` guard, `bc`-only arithmetic, and both a full and a partial
  base test.
- **Freeze-trigger column drift (§A4).** A new column absent from the by-name freeze list is silently
  mutable. Mitigated by the `CREATE OR REPLACE` adding both columns and the immutability regression test that
  fails without it.
- **Caller-supplied override rate (§C1, §C2).** An out-of-range or negative override. Mitigated by the
  service range-check (`[0,100]`) plus the DB range CHECK, and the `wht_amount <= amount` CHECK as the
  net-cash backstop.
- **VERSION bump breaks pinned tests (§A5).** Four Accounting tests assert `-3`; the bump updates them, as
  Wave 7's `-2` → `-3` did — a scheduled edit, not a surprise.
- **Phase-4 receivable-side WHT-on-receipt reconciliation.** Not on this branch (requirements §0); a later
  merge-time task, not an 8b blocker, but these choices (per-supplier rate, gross settlement, `wht_payable`
  key) are the ones it will reconcile against.

# Consequences

- Purchasing gains its first tax-splitting payment: the credit side becomes two lines when withholding, one
  when not; the debit and every money invariant of 8a are unchanged.
- The chart gains its fourth system account (`WHT Payable`), the first added by a **create** backfill rather
  than a stamp; `ChartTemplate::VERSION` reaches `-4`; `ensureSystemAccounts()` now provisions it on demand.
- `suppliers` carries the WHT configuration the retained TIN anticipated; `supplier_payments` carries a frozen
  WHT snapshot.
- `WHT Payable` accumulates as a live, reportable liability on the trial balance (FR-6), ready for the
  deferred remittance slice to clear.
- The named later slices land on ready targets: WHT certificates and the WHT return/remittance read the
  `wht_amount`/`wht_rate` snapshot and the `WHT Payable` balance; per-service-type rates extend the
  per-supplier rate into codes; WHT on non-bill payments extends the base beyond an allocation.

# Gate 2 — items to confirm, and the UNRESOLVED forks

**Confirm the (Gate-2 PROPOSED) shapes:**

1. **Supplier schema (§A2):** `wht_applicable` (bool, default false) + `wht_rate` (`decimal(5,2)`, nullable);
   range CHECK `[0,100]`; biconditional `wht_applicable = (wht_rate IS NOT NULL)`.
2. **Payment schema (§A3):** `wht_amount` (`decimal(19,4)` NOT NULL default 0) + `wht_rate` (`decimal(5,2)`
   nullable); CHECKs `>= 0`, `<= amount`, rate range, rate-present-when-withheld.
3. **Immutability (§A4):** `CREATE OR REPLACE asids_supplier_payments_immutable()` adds `wht_amount` and
   `wht_rate` to the frozen-by-name list.
4. **Account (§A5):** `2180 WHT Payable`, `Account::WHT_PAYABLE = 'wht_payable'`, Liability leaf under `2100`,
   in `accounts()` **and** `requiredSystemAccounts()`; `ChartTemplate::VERSION` `-3` → `-4`.
5. **Backfill (§A6):** create-not-stamp, raw SQL under `RowLevelSecurity::bypass()` with
   `assertBypassEffective()` + `assertNothingLeftBehind()`; idempotent by key-absence; code-collision → suffix;
   parent `2100` or NULL.
6. **Posting (§B):** `for()` gains `Money $wht`; credit splits when `wht > 0`, stays single when `wht = 0`;
   `whtPayableAccountFor()` resolves by key with Liability + postable + missing checks; net-zero omits the bank
   line.
7. **Service (§C2):** WHT computed pre-transaction on the pre-VAT base; steps 11–17 unchanged (gross
   settlement); snapshot on the header; rate resolution + override + suppress.
8. **Authorization (§C4):** unchanged — reuse `purchasing.payments.post`, no new capability.

**UNRESOLVED — the human must decide these at Gate 2 (I will not decide them silently):**

- **Fork 1 (§A2):** does 8b **wire the supplier write path** (`SupplierData`/`SupplierService`) so a
  withholding supplier can be configured now — my recommendation — or land only schema + payment posting and
  defer the supplier-edit fields to a later slice?
- **Fork 2 (§A6):** the backfill mechanism — **per-company loop** with collision-and-parent resolution (my
  recommendation, correct on a real installed base) or the thinner single `INSERT … SELECT` that assumes
  `2180` is always free?
- **Fork 3 (§C1):** the WHT instruction shape — tri-state `withholdTax` flag + optional **rate** override with
  a service-computed amount (my recommendation) or an alternative (caller-supplied amount; rate-only with no
  suppress)?
- **Fork 4 (§C2):** multi-bill rounding — `rate × Σ bases` rounded **once** (my recommendation, the literal
  reading of dec. 5) or `Σ round(rate × base_i)` per bill?
- **Fork 5 (§A3, minor):** snapshot `wht_rate` **only when `wht_amount > 0`** (my recommendation, keeps a
  zero-WHT header 8a-identical) or always store the resolved rate even when the withheld amount rounds to 0?

---

*Prepared by the Solution Architect (Stage 3, Phase 5 / Wave 8b). Design only — no production code written,
nothing committed. Build begins after Gate 2 approval, on this ADR, verbatim; any implementation discovery
that would change a decision returns to Gate 2.*
