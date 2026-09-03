# ADR 0020 — Supplier payments and bill allocation (Wave 8a)

- **Status:** Proposed — for Gate 2 (human) review
- **Author:** Solution Architect (Stage 3, Phase 5 / Wave 8a)
- **Date:** 2026-09-03
- **Branch:** `feature/phase5-supplier-payments` (stacked on `feature/phase5-bills`, Wave 7)
- **Supersedes / extends:** nothing. Extends the Purchasing bounded context of
  `docs/adr/0018-purchasing-supplier-domain-foundation.md` and
  `docs/adr/0019-bills-purchase-invoices-and-payable-posting.md`. This is the wave the bills schema was
  built to receive: `bills.amount_paid`/`amount_due` (held at zero by a phase CHECK) and the reserved
  `partially_paid`/`paid` statuses (ADR 0019 §A5, §C1).
- **Requirements:** `docs/PHASE-5-SUPPLIER-PAYMENTS-REQUIREMENTS.md` (Gate 1 decisions — APPROVED 2026-09-03).

## How to read this record

Every point is labelled **(Gate-1 APPROVED)** — binding, decided by the human on 2026-09-03;
**(Gate-2 PROPOSED)** — my design realisation, to confirm before build; or **(UNRESOLVED — needs human
approval)** — a fork I will not decide silently. Nothing here is built until Gate 2 approves this ADR,
which the build then follows verbatim.

**This wave is greenfield, not a mirror (Gate-1 dec. 0).** There is no customer-receipt code on this
branch — Phase 4 receivables live on unmerged `feature/phase4-*` branches, and Phase 5 branched off
`main`. There is no `ReceiptService`, no receipt migrations, no ADRs 0013–0017 here. So every choice —
allocation policy, numbering, the record-and-post lifecycle, duplicate protection, multi-bill locking — is
made for the *first* time in this product. The design is grounded, line for line, in the one real,
present, finished posting document: the **bill**. The load-bearing originals, cited throughout:

- `src/Core/Purchasing/Domain/Models/Bill.php`, `BillLine.php`;
  `src/Core/Purchasing/Domain/Enums/BillStatus.php`
- `src/Core/Purchasing/Application/Services/BillService.php` (createDraft/updateDraft/deleteDraft/**post**),
  `BillPostingMap.php`; `src/Core/Purchasing/Domain/Exceptions/BillCannotBePosted.php`
- `src/Core/Purchasing/Database/Migrations/2026_09_02_000002_create_bills_table.php` (header + CHECKs),
  `…000003_create_bill_lines_table.php`, `…000004_enable_row_level_security_on_bills.php`,
  `…000005_make_posted_bills_immutable.php`
- `src/Core/Accounting/Application/Services/PostingService.php` (`postNew` — its docblock line 87 names
  "a payment" as its intended caller), `DocumentNumberService.php`;
  `src/Core/Accounting/Domain/Enums/DocumentType.php`;
  `src/Core/Accounting/Domain/Models/Account.php` (`TRADE_PAYABLES`, line 80);
  `src/Core/Accounting/Domain/ValueObjects/SourceDocument.php`;
  `src/Core/Accounting/Database/Migrations/2026_03_01_000001_add_source_document_to_journal_entries.php`
  (the `source_id` uniqueness that stops a document posting twice, lines 63–67)
- `src/Core/Purchasing/Infrastructure/EloquentPayableBalanceProbe.php` (the `amount_due` invariant its
  own comment, lines 48–51, predicted this wave would make load-bearing)
- `src/Core/Authorization/Domain/Catalogue/PermissionCatalogue.php` (`purchasing()`, lines 283–301),
  `RoleTemplate.php`; `src/Core/Purchasing/Policies/BillPolicy.php`;
  `src/Core/Purchasing/Providers/PurchasingServiceProvider.php`

A payment is written as "mirror the bill, invert the money flow (the bill *creates* a payable, the payment
*settles* it), and add the two things a bill has no analogue for: **many bills settled in one document**,
and **a document born posted with no draft**."

## Context

Three things make this wave structurally new, not another CRUD slice.

1. **It is the first document that touches more than one existing posted document in one transaction.**
   `BillService::post()` locks exactly one bill row (`BillService.php:250-258`). A payment allocates across
   several outstanding bills at once, so it must lock *several* bill rows without deadlocking against a
   concurrent payment (or, later, a cancellation) touching an overlapping set. Nothing in the codebase does
   multi-row locking yet — §F is the whole answer.

2. **It is the first document with no draft stage (Gate-1 dec. 5).** A bill and an invoice are prepared as
   a draft and later committed; a payment records cash that has *already* moved, so it is recorded and
   posted in one step. That inverts the bill's lifecycle at the database: a payment is *born posted*, so its
   child rows (allocations) cannot be gated on a parent-draft the way `bill_lines` are
   (`asids_bill_lines_immutable()`, `…000005…:96-122`). The closest real precedent is not the bill at all
   but `PostingService::postNew()` — a journal entry that is drafted and posted inside one call, which its
   docblock (line 87) says is "what every automated posting path uses — a payment, an invoice…". §A5 and §C6
   resolve the born-posted difference.

3. **It makes an anticipated seam load-bearing for the first time.** `bills.amount_paid`/`amount_due` ship
   held at zero by `bills_no_payments_until_payments_phase`
   (`2026_09_02_000002_create_bills_table.php:188-192`), the immutability trigger already permits those two
   columns and `status` to move on a posted bill (`…000005…:18-22`), and
   `EloquentPayableBalanceProbe::outstandingBalance()` already reads `amount_due` "because they agree …
   Wave 8 drops it" (`EloquentPayableBalanceProbe.php:48-51`). This wave drops that CHECK and drives that
   seam. Risk 2 is that the probe's invariant must still hold after a partial payment.

## Binding Gate-1 decisions (verbatim from `docs/PHASE-5-SUPPLIER-PAYMENTS-REQUIREMENTS.md` → Gate 1 — APPROVED)

0. **Greenfield, grounded in the Bill pattern** — not a `ReceiptService` mirror.
1. **Bank/cash account = caller-named per payment** (mirrors a bill line naming its expense account). No new per-company "primary account" setting this slice.
2. **Full allocation required** — a payment's allocations must sum to its total; no unallocated remainder. Prepayment / credit-on-account deferred.
3. **Per-bill allocation capped at the bill's current `amount_due`** — no single bill driven to a negative payable. Deliberate overpayment deferred with item 2.
4. **Numbering = internal `PAY-` number**; the ledger entry draws its `JV` as always. (Architect: confirm gapless vs non-gapless — leans gapless.)
5. **Lifecycle = record-and-post in ONE step.** No draft stage, no `Draft` status. Permissions `purchasing.payments.{view, post}` (`post` sensitive); no `.draft`.
6. **WHT-on-payment = a SEPARATE slice (8b).** 8a posts the two-line `Dr Trade Payables = Cr Bank`.
7. **Duplicate-real-world-payment detection = left to audit/user diligence for 8a.** The DB still guarantees one ledger entry per payment record (unique `journal_entry_id`/`source_id`).
8. **Cancellation deferred** — the payment's status CHECK reserves a `cancelled` case from the start.

---

# A. Schema — `supplier_payments` + `payment_allocations`

## A0. What this wave does NOT add — a deliberate divergence from Wave 7 (Gate-1 APPROVED dec. 1, 6)

Wave 7 opened with a new system account (`Account::TRADE_PAYABLES`), a `ChartTemplate` stamp, a `VERSION`
bump and a backfill migration for every existing company (ADR 0019 §A0). **Wave 8a adds none of that**, and
that is the direct consequence of two Gate-1 decisions:

- **The bank/cash account is caller-named (dec. 1).** `1110 Cash in Hand` and `1120 Bank Accounts` are
  *keyless* in the chart template (`ChartTemplate.php:80-81` — no `system:` argument, unlike `1130`/`2110`
  at `:82`, `:98`). A payment therefore names its account explicitly, exactly as a bill line names its
  expense account (`BillService::resolveExpenseAccount`, `BillService.php:552-585`). No system key, no
  template change, no backfill. **(Gate-2 PROPOSED)**
- **The payable account already exists (dec. 6).** The debit lands in `Account::TRADE_PAYABLES` (`2110`),
  resolved by key exactly as `BillPostingMap::payableAccountFor()` resolves it for the credit
  (`BillPostingMap.php:91-109`). No new account.

The one and only account this wave *would* have added — `WHT Payable` — is Wave 8b (Gate-1 dec. 6), and its
whole three-part cost (leaf + key + backfill) travels with it. **(Gate-1 APPROVED)**

## A1. Migration files (Gate-2 PROPOSED)

Five migrations under `src/Core/Purchasing/Database/Migrations/`, dated after the bills set
(`…09_02_000005`):

| File | Does |
| --- | --- |
| `2026_09_03_000001_allow_bill_payments.php` | **Drops** `bills_no_payments_until_payments_phase`; **adds** `bills_amount_due_non_negative_check` (§A4). |
| `2026_09_03_000002_create_supplier_payments_table.php` | Header table + indexes + CHECKs (§A2, §A4). |
| `2026_09_03_000003_create_payment_allocations_table.php` | Allocation table + indexes + CHECKs (§A3, §A4). |
| `2026_09_03_000004_enable_row_level_security_on_payments.php` | FORCE RLS on both tables (§A6). |
| `2026_09_03_000005_make_posted_payments_immutable.php` | Payment freeze trigger, allocation freeze trigger, deferred full-allocation constraint trigger (§A5). |

## A2. `supplier_payments` header columns (Gate-2 PROPOSED, realising Gate-1 dec. 1, 4, 5, 8)

Mirrors the `bills` header (`2026_09_02_000002_create_bills_table.php:38-106`), with the draft machinery
removed because a payment is born posted (dec. 5).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `uuid` PK | `HasUuids`. Pre-assignable — §C6 depends on it. |
| `tenant_id` | `foreignUuid` → `tenants`, cascade | Mirror `bills:41-43`. |
| `company_id` | `foreignUuid` → `companies`, cascade | Mirror `bills:45-47`. |
| `branch_id` | `foreignUuid` nullable → `branches`, `nullOnDelete` | The reporting dimension, as on `bills:50`; passed to the journal lines. |
| `supplier_id` | `foreignUuid` → `suppliers`, **`restrictOnDelete`** | A payment names its supplier and it must stay resolvable — mirror `bills.supplier_id` (`bills:54`). |
| `bank_account_id` | `foreignUuid` → `accounts`, **`restrictOnDelete`** | The caller-named account the credit lands in (dec. 1); must stay resolvable — mirror `bill_lines.expense_account_id` (`bill_lines:67`). Validated **Asset + postable + same company** in the posting map (§B). |
| `number` | `string(40)` **NOT NULL** | Internal `PAY-…`, drawn inside the record-and-post transaction. **NOT NULL** (unlike `bills.number`, `:62`) because there is no draft — every persisted payment is posted and numbered. |
| `payment_date` | `date` NOT NULL | The value date. Drives the fiscal period and the JV `entry_date`. Mirror `bills.bill_date` (`:66`). |
| `currency_code` | `char(3)` NOT NULL | The company base currency. Load-bearing for `Money`. Mirror `bills.currency_code` (`:71`). |
| `exchange_rate` | `decimal(19,10)` nullable | Held at NULL by a phase CHECK (§A4), exactly as `bills.exchange_rate` (`:73`) — FX becomes one dropped CHECK later, not a populated-table migration. **(Gate-2 PROPOSED — droppable; recommend keep for the mirror.)** |
| `amount` | `decimal(19,4)` NOT NULL | The payment total = Σ allocations (dec. 2). The Cr Bank and the Dr Trade Payables are both this figure. |
| `status` | `string(16)` NOT NULL default `'posted'` | CHECK `IN ('posted','cancelled')` — `cancelled` reserved (dec. 8). No `draft` (dec. 5). |
| `reference` | `string(120)` nullable | Optional external reference (bank txn id, cheque no). **Not** unique — a payment has no mandatory external key, and dec. 7 leaves duplicate detection to audit. |
| `notes` | `text` nullable | Free text; the only `fillable` field (mirror `Bill::$fillable`, `Bill.php:94`). |
| `journal_entry_id` | `foreignUuid` **NOT NULL UNIQUE** → `journal_entries`, `restrictOnDelete` | The database guard against a payment posting twice — mirror `bills.journal_entry_id` (`:95`), but **NOT NULL** because born posted. |
| `posted_at` | `timestampTz` NOT NULL | Mirror `bills.posted_at` (`:90`), NOT NULL for the same reason. |
| `posted_by_id` | `foreignUuid` nullable → `users`, `nullOnDelete` | Mirror `bills.posted_by_id` (`:91`). |
| `created_by_id` | `foreignUuid` nullable → `users`, `nullOnDelete` | Mirror `bills.created_by_id` (`:100`). |
| `created_at` / `updated_at` | `timestampsTz` | |

No `deleted_at`, no cancellation columns — a payment is a statutory record, hard-deletion is refused by the
trigger (§A5), and cancellation columns arrive with the cancellation slice exactly as bills deferred theirs
(ADR 0019 §A2). **(Gate-1 APPROVED dec. 8)**

## A3. `payment_allocations` columns (Gate-2 PROPOSED, realising Gate-1 dec. 2, 3)

Mirrors `bill_lines` (`2026_09_02_000003_create_bill_lines_table.php:26-75`).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `uuid` PK | |
| `tenant_id` | `foreignUuid` → `tenants`, cascade | Its own RLS policy — RLS is not transitive (§A6). |
| `company_id` | `foreignUuid` → `companies`, cascade | Denormalised from the payment for a uniform RLS policy and index prefix — mirror `bill_lines:34-36`. |
| `supplier_payment_id` | `foreignUuid` → `supplier_payments`, **`cascadeOnDelete`** | An allocation has no meaning apart from its payment — mirror `bill_lines.bill_id` (`bill_lines:38-40`). (Cascade only ever fires if the payment is hard-deleted, which §A5 forbids.) |
| `bill_id` | `foreignUuid` → `bills`, **`restrictOnDelete`** | The settled bill must stay resolvable; a posted bill cannot be hard-deleted anyway. |
| `amount` | `decimal(19,4)` NOT NULL | CHECK `> 0` (§A4). A negative allocation would be a refund/debit note — out of scope. |
| `created_at` / `updated_at` | `timestampsTz` | Mirror `bill_lines:71`. |

## A4. Indexes, uniqueness, CHECK constraints (Gate-2 PROPOSED)

**On `bills` (migration `…000001_allow_bill_payments`):**

```sql
-- Wave 7 held amount_paid at zero for exactly this wave to release. Its own comment (bills:199)
-- and the create migration (bills:188-192) name the payments phase as the dropper.
ALTER TABLE bills DROP CONSTRAINT bills_no_payments_until_payments_phase;

-- New invariant realising Gate-1 dec. 3 at the database, not only in the service. `bills_non_negative_check`
-- (bills:172-176) asserts amount_paid >= 0 but NOT amount_due >= 0, so nothing today stops an overpayment
-- driving amount_due negative once amount_paid can move. Given amount_due = total - amount_paid
-- (bills_amount_due_check, bills:166-170), this is equivalent to amount_paid <= total.
ALTER TABLE bills ADD CONSTRAINT bills_amount_due_non_negative_check CHECK (amount_due >= 0);
```

This is the payable-side backstop to the per-bill cap: the service refuses an over-cap allocation readably
(§C4, AC-1.6), and this CHECK is the authority that holds under concurrency and raw access — the same
"service message + database authority" split bills already use for the duplicate-number guard
(`BillService.php:303-323` pre-check, `bills_company_supplier_invoice_number_unique` authority). **NOTE:**
`bills_amount_due_check` and `bills_non_negative_check` are **retained** — the drop must not touch them
(Risk 3).

**On `supplier_payments`:**

```sql
-- Full unique (not partial): number is always set, so unlike bills' partial index on a nullable draft
-- number (bills:110-114) this needs no WHERE clause.
CREATE UNIQUE INDEX supplier_payments_company_number_unique ON supplier_payments (company_id, number);

ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_status_check
    CHECK (status IN ('posted','cancelled'));
ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_amount_positive_check
    CHECK (amount > 0);                                   -- AC-1.8
ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_single_currency_until_fx_phase
    CHECK (exchange_rate IS NULL);                        -- mirror bills:182-186; dropped by the FX phase
```

Indexes: `(tenant_id, company_id, status)` and `(company_id, supplier_id, payment_date)` — mirror
`bills:104-105`.

**On `payment_allocations`:**

```sql
-- One allocation row per bill per payment (Gate-1: unique (payment_id, bill_id)). A bill is not split
-- across two rows within one payment; a second payment against the same bill is a separate payment row.
CREATE UNIQUE INDEX payment_allocations_payment_bill_unique
    ON payment_allocations (supplier_payment_id, bill_id);

ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_positive_check
    CHECK (amount > 0);
```

Indexes: `(tenant_id, company_id)` — mirror `bill_lines:73`; and `(bill_id)` for "which payments settled
this bill?" and to index the FK (PostgreSQL does not index FKs automatically). The unique index already
prefixes `supplier_payment_id`, so loading a payment's allocations is covered.

The per-bill cap itself (`allocation ≤ bill.amount_due`) is **not** a CHECK — a CHECK cannot join to
`bills`, the same reason a bill line's expense-account *type* is validated in the service, not the schema
(`bill_lines` docblock, `:16`; `BillService.php:552-585`). It is enforced in the service under a row lock
(§C4) and backstopped by `bills_amount_due_non_negative_check` above. **(Gate-1 APPROVED dec. 3)**

## A5. Immutability — payment freeze, allocation freeze, and the full-allocation backstop (Gate-2 PROPOSED)

The born-posted lifecycle (dec. 5) changes how immutability is expressed. A bill's triggers gate on
`OLD.status <> 'draft'` (`…000005…:88`) and on the parent being a draft (`…000005…:107`), letting the
draft→post UPDATE and the initial line INSERTs through. A payment has no draft, so those gates do not
apply. The closest real precedent is `asids_journal_entries_immutable()`
(`2026_03_01_000001_add_source_document_to_journal_entries.php:82-125`): a journal entry is also born
posted, and its trigger fires on every UPDATE/DELETE, permitting only the one legal exit (`→ reversed`) and
freezing every column by name.

**(1) `asids_supplier_payments_immutable()` — mirror `asids_journal_entries_immutable()`:**

- `BEFORE UPDATE OR DELETE ON supplier_payments FOR EACH ROW` — **no `WHEN` clause** (unlike bills): every
  row is posted from birth, so every UPDATE/DELETE is a post-hoc mutation to be checked. The record-and-post
  INSERT is untouched (the trigger is not `BEFORE INSERT`).
- `DELETE` → raise (`restrict_violation`): "Payment % is a statutory record and cannot be deleted."
- `OLD.status = 'cancelled'` → raise: already cancelled, nothing further may change (mirror "already
  reversed", `:89-92`).
- `NEW.status <> 'cancelled'` → raise: "a posted payment cannot be modified; cancel it instead" (mirror
  "the only legal transition", `:94-98`). In 8a nothing ever sets `cancelled`, so this makes a posted
  payment **fully immutable** this wave; the cancellation slice adds the behaviour, not a migration
  (dec. 8).
- Every identifying column `IS DISTINCT FROM OLD` → raise: `id, tenant_id, company_id, branch_id,
  supplier_id, bank_account_id, number, payment_date, currency_code, exchange_rate, amount, reference,
  notes, journal_entry_id, posted_at, posted_by_id, created_by_id, created_at`. Listed **by name**, not
  `OLD IS DISTINCT FROM NEW`, so a column added later and forgotten is not silently mutable — the same
  reasoning as `bills` (`…000005…:29`).

**(2) `asids_payment_allocations_immutable()` — freeze existing rows:**

- `BEFORE UPDATE OR DELETE ON payment_allocations FOR EACH ROW` → **always raise**: "the allocations of a
  posted payment cannot be changed." Unlike `asids_bill_lines_immutable()` (`…000005…:96-122`) this does
  **not** fire on INSERT and does **not** gate on parent status — a payment's allocations are inserted while
  the parent is already `posted`, so a parent-status gate would refuse the legitimate creation. INSERT is
  instead guarded by (3).

**(3) `supplier_payments_fully_allocated` — a DEFERRED constraint trigger (the born-posted answer to
`bill_lines`' insert gate):**

```sql
CREATE CONSTRAINT TRIGGER supplier_payments_fully_allocated
    AFTER INSERT OR UPDATE OR DELETE ON payment_allocations
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION asids_assert_payment_fully_allocated();
-- plus the same AFTER INSERT trigger on supplier_payments, so a payment inserted with no allocations at all
-- is caught at commit.
```

`asids_assert_payment_fully_allocated()` asserts, at transaction commit, that for the affected payment
`SUM(payment_allocations.amount) = supplier_payments.amount`. This does two jobs at once:

- It **enforces full allocation (dec. 2) at the database**, so the app's Σ-check (§C4, AC-1.7) is the
  readable message and this is the authority — precisely the split bills use for the duplicate guard.
- It **closes the hole the born-posted model opens.** Because allocations INSERT while the parent is
  already `posted`, trigger (2) cannot refuse a *rogue* post-hoc INSERT of an extra allocation into a
  committed payment. The deferred sum-check does: the offending transaction's commit finds Σ ≠ `amount`
  (the header `amount` is frozen by trigger (1)) and raises. The legitimate record-and-post transaction
  inserts the header (`amount = X`) and allocations (Σ = X) together, and passes at commit.

It fires at commit, so it is a backstop, not the UX path; the clean refusal a user sees is the service's
pre-transaction Σ-check. A cancelled payment (future) keeps `amount` and its allocations, so Σ = `amount`
still holds — the check needs no status scoping. **(Gate-2 PROPOSED — the mechanism; see Alternatives for
the simpler app-only variant and why this is preferred.)**

## A6. FORCED row-level security (Gate-1 APPROVED NFR; realisation Gate-2 PROPOSED)

`2026_09_03_000004_enable_row_level_security_on_payments.php` mirrors the bills RLS migration
(`…000004…:20-54`) verbatim over `['supplier_payments','payment_allocations']`: `ENABLE`, then **`FORCE`**
(without which CI passes vacuously for the table owner), then a `tenant_isolation` policy
`USING (asids_rls_bypassed() OR tenant_id = asids_current_tenant_id())` with the same `WITH CHECK`.
`payment_allocations` gets its **own** policy — RLS is not transitive between a parent and child table
(bills RLS docblock, `…000004…:16-18`).

## A7. The `shouldBeStrict()` / `preventLazyLoading()` traps (Gate-1 APPROVED — restated)

`PaymentService::record()` sets **every** attribute on the new `SupplierPayment` explicitly — `status`,
`number`, `journal_entry_id`, `posted_at`, `posted_by_id`, `exchange_rate` (null), `reference` (null when
absent) — rather than leaning on the column defaults, so an unsaved instance reads back the same as a saved
one under `Model::shouldBeStrict()`. This is the trap Phase 1 hit on `must_change_password`, Phase 2 on
`is_closed`, and `BillService::createDraft()` documents at `BillService.php:96-105`. `PaymentPostingMap`
and `record()` `loadMissing()` every relation they read (`supplier`, `bankAccount`, `allocations.bill`)
before touching it, so nothing lazy-loads under `Model::preventLazyLoading()` (mirror
`BillPostingMap.php:65-67`).

---

# B. `PaymentPostingMap` — Dr Trade Payables (total) = Cr Bank/Cash (total) (Gate-1 APPROVED dec. 1, 6; realisation Gate-2 PROPOSED)

The payable-side settlement is the exact inverse of the bill's payable-side creation. Where
`BillPostingMap` writes `Dr Expense (+ Dr Input VAT) = Cr Trade Payables` (`BillPostingMap.php:26-36`), the
payment writes:

```
Trade Payables (2110)   100,000.00
    Bank Accounts (1120)            100,000.00
```

**Two lines, both equal to the payment `amount`.** Because full allocation is required (dec. 2),
`amount = Σ allocations`, so the single Dr Trade Payables line (`= Σ allocations`) and the single Cr
Bank/Cash line (`= total`) are equal by construction — the entry balances with no rounding, the same way a
bill's single grouped payable credit balances its debits (`BillPostingMap.php:47-50`). Trade Payables is a
**control account**: the per-bill detail lives in `payment_allocations` (the subledger) and the journal
cites the payment through its source document, exactly as bill-line detail lives in `bill_lines` and the
journal carries one grouped credit (`BillPostingMap.php:37-42`). No per-bill journal lines.

`PaymentPostingMap` is a pure mapping — it reads, resolves accounts, and returns `list<JournalLineData>`;
it **writes nothing, posts nothing, reserves no number** (mirror `BillPostingMap.php:17-23`), so its
refusals are free and run before the transaction opens.

```php
final readonly class PaymentPostingMap
{
    /** @return list<JournalLineData> */
    public function for(Company $company, Account $bankAccount, Money $total, Supplier $supplier): array;

    public function payableAccountFor(Company $company): Account;  // mirror BillPostingMap::payableAccountFor
}
```

It validates **every account it touches**, mirroring `BillPostingMap`, as `PaymentCannotBePosted`
(§C, factory names mirror `BillCannotBePosted`):

- **Trade Payables** (`payableAccountFor`): resolved by `Account::TRADE_PAYABLES` key
  (`Account.php:80`, `scopeWithSystemKey`, `:205-208`); `withoutPayableAccount` if absent
  (`BillCannotBePosted.php:104`), `payableAccountWrongType` if not `Liability`
  (`:163`), `accountNotPostable` if not `acceptsPostings()` (`:142`).
- **Bank/cash** (`bankAccount`, caller-named): `bankAccountWrongType` if not `AccountType::Asset`
  (the mirror of the expense-type check, `BillService.php:566-575`, and of AC-1.9);
  `accountNotPostable` if not `acceptsPostings()`; `accountOutsideCompany` if `company_id` differs
  (`BillCannotBePosted.php:124` — RLS does not separate sibling companies sharing a `tenant_id`,
  `BillPostingMap.php:34-35`).

Restricting the funding account to **Asset** matches AC-1.9 and the 8a scope (bank/cash). Non-asset funding
sources — a company overdraft or credit card modelled as a Liability — are **out of scope** for 8a and
listed as a Gate-2 confirm (item 9). **(Gate-2 PROPOSED)**

---

# C. Enum, models, DTOs, exceptions, `PaymentService`

## C1. `PaymentStatus` enum (Gate-2 PROPOSED)

`src/Core/Purchasing/Domain/Enums/PaymentStatus.php`. Only two cases reachable/valued this wave; `Cancelled`
reserved (dec. 8), mirroring how `BillStatus` shipped all five states before four were reachable
(`BillStatus.php:11-24`).

```php
enum PaymentStatus: string
{
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string;      // 'Posted' | 'Cancelled'
    public function isPosted(): bool;      // $this === self::Posted
    public function isCancelled(): bool;   // $this === self::Cancelled
}
```

No `Draft` (dec. 5). No `isEditable()` — a payment is never editable.

## C2. `SupplierPayment` + `PaymentAllocation` models (Gate-2 PROPOSED)

`SupplierPayment` (`Domain/Models/SupplierPayment.php`) mirrors `Bill.php`:

- `use Auditable, BelongsToTenant, HasFactory, HasUuids;` — **no `SoftDeletes`** (a payment is never
  soft-deleted; mirror `Bill.php:71-79`).
- `public const string MORPH_ALIAS = 'supplier_payment';` — **required**: `SourceDocument::for()` round-trips
  the alias (`SourceDocument.php:62-92`) and `Auditable` throws for an unmapped class. Registered in the
  provider (§E). `'supplier_payment'`, not `'payment'`, leaves room for a future `customer_receipt`.
- `protected $fillable = ['notes'];` — every figure and identifier is service-computed (mirror
  `Bill.php:85-94`).
- Relations: `company()`, `supplier()`, `branch()`, `bankAccount()` (→ `Account`, `bank_account_id`),
  `allocations()` (`hasMany(PaymentAllocation, 'supplier_payment_id')`), `journalEntry()`, `createdBy()`.
- `casts()`: `status => PaymentStatus::class`, `payment_date => immutable_date`,
  `posted_at => immutable_datetime`, `exchange_rate => decimal:10`, `amount => decimal:4`.
- `auditOnly()`: `['number','supplier_id','bank_account_id','payment_date','amount','reference','status']`;
  `auditTags(): ['purchasing','payment']` (mirror `Bill.php:239-261`).
- Scopes: `forCompany($companyId)` (mirror `Bill.php:199-202`).

`PaymentAllocation` (`Domain/Models/PaymentAllocation.php`) mirrors `BillLine.php`: `HasUuids`,
`BelongsToTenant`, **no `Auditable`** and **no `MORPH_ALIAS`** — an allocation has no life of its own and is
never a source document (mirror `Bill.php:36`, `BillLine` registers no alias, provider `:56-57`). Relations
`payment()`, `bill()`; `casts(): ['amount' => 'decimal:4']`.

## C3. `PaymentData` + `PaymentAllocationData` DTOs (Gate-2 PROPOSED)

`Application/DTOs/PaymentData.php`, `PaymentAllocationData.php` (mirror `BillData`/`BillLineData`):

```php
final readonly class PaymentData
{
    /** @param list<PaymentAllocationData> $allocations */
    public function __construct(
        public string $supplierId,
        public string $bankAccountId,
        public CarbonImmutable $paymentDate,
        public string $amount,               // numeric-string: caller's stated total, validated == Σ allocations
        public array $allocations,
        public ?string $reference = null,
        public ?string $notes = null,
        public ?string $branchId = null,
    ) {}
    public static function fromArray(array $attributes): self;
}

final readonly class PaymentAllocationData
{
    public function __construct(public string $billId, public string $amount /* numeric-string */) {}
    public static function fromArray(array $attributes): self;
}
```

**`amount` is supplied AND validated (not merely derived)** — the caller states the intended total and the
service refuses if Σ allocations ≠ it (AC-1.7). This catches a client that dropped an allocation, and gives
the deferred DB backstop (§A5) a meaningful `amount` to check against. **(Gate-2 PROPOSED — confirm supplied+validated over derived; item 7.)**

## C4. `PaymentService::record()` — ONE transaction, record-and-post (Gate-2 PROPOSED, realising Gate-1 dec. 2, 3, 5)

`Application/Services/PaymentService.php`, a `final readonly class`, singleton in the provider (mirror
`BillService`). Constructor: `PaymentPostingMap`, `PostingService`, `DocumentNumberService`,
`FiscalCalendarService` (mirror `BillService.php:64-71`, minus the tax/totals collaborators — a payment
computes no tax).

Signature: `public function record(Company $company, PaymentData $data, ?User $actor = null): SupplierPayment`.

The order mirrors `BillService::post()` exactly — **everything that can refuse runs before anything is
reserved** (`BillService.php:214-286`):

**Pre-transaction (free refusals — no lock, no number):**

1. **Resolve supplier** — belongs to `$company` (mirror `resolveSupplier`, `BillService.php:515-527`).
   **Divergence (AC-1.10):** do **not** call `acceptsNewBills()`. Paying what you already owe an archived or
   dormant supplier is allowed; only *new billing* is refused. `PaymentCannotBeRecorded::supplierOutsideCompany`.
2. **Resolve bank/cash account** — belongs to `$company` (`PaymentCannotBeRecorded::bankAccountOutsideCompany`);
   type/postability are the posting map's job (§B), re-checked there before the transaction opens.
3. **Resolve branch** if supplied — belongs to `$company` (mirror `resolveBranchId`, `BillService.php:587-606`).
4. **Assert allocations non-empty** — `PaymentCannotBeRecorded::withoutAllocations` (mirror `bill-has-no-lines`,
   `BillService.php:333-338`; AC-1.2).
5. **Assert `amount > 0`** — `PaymentCannotBeRecorded::amountNotPositive` (mirror `withZeroTotal`,
   `BillCannotBePosted.php:60-73`; AC-1.8).
6. **Assert each allocation `amount > 0`** and **no duplicate `billId`** in the payload
   (`allocationNotPositive`, `duplicateBillAllocation` — the readable message; the unique index §A4 is the
   authority under a race).
7. **Load the target bills** and validate each: belongs to `$company` (AC-1.5,
   `billOutsideCompany`), `supplier_id` matches the named supplier (AC-1.3, `billNotForSupplier`),
   `status->isOutstanding()` (AC-1.4, `billNotOutstanding` — `BillStatus.php:65-71`, `Bill::scopeOutstanding`,
   `Bill.php:222-228`).
8. **Assert Σ allocations == `amount`** — full allocation (AC-1.7, dec. 2). `PaymentCannotBeRecorded::notFullyAllocated`.
   All comparisons via `bccomp` at `Money::SCALE` — no floats (mirror `EloquentPayableBalanceProbe.php:65`).
9. **Build the posting lines** — `$lines = $postingMap->for($company, $bankAccount, Money::of($amount), $supplier)`.
   The map writes nothing, so an account refusal here (`PaymentCannotBePosted`) costs no lock (mirror
   `BillService.php:245`).
10. **Fiscal period** — `$period = $calendar->periodFor($company, $data->paymentDate->startOfDay())`; refuse
    if `! $period->acceptsPostings()` (`PaymentCannotBePosted::intoClosedPeriod`, mirror
    `BillCannotBePosted.php:82`, checked before the transaction at `BillService.php:238-244`; AC-2.7).

**Inside `DB::transaction` (mirror `BillService.php:247-286`):**

11. **Lock the target bills in deterministic order** —
    `Bill::query()->whereKey($billIds)->orderBy('id')->lockForUpdate()->get()`, processed in that id order.
    The fixed ascending-id order is what makes concurrent payments deadlock-free (§F). This is the extension
    of `BillService::post()`'s single-row `lockForUpdate()->firstOrFail()` (`:250-258`) to several rows —
    the one genuinely new mechanism this wave (Risk 1).
12. **Re-validate each locked bill under the lock** — the only checks that hold under concurrency: still
    `isOutstanding()` (a racing payment may have paid it → `billNotOutstanding`), and
    `allocation ≤ bill.amount_due` **re-read now** (the per-bill cap, dec. 3; AC-1.6 →
    `PaymentCannotBeRecorded::allocationExceedsDue`). Refusing here turns the race into a readable message
    instead of the `bills_amount_due_non_negative_check` surfacing as a 500 (mirror `BillService.php:256-258`).
13. **Pre-assign the payment id and post the JV** — see §C6. Draw `PAY-…`
    (`$numbers->next($company, DocumentType::Payment, $period)`, inside the transaction so a rollback returns
    it, `DocumentNumberService.php:41-83`), then `$posting->postNew($company, new JournalEntryData(
    entryDate: $data->paymentDate->startOfDay(), description: LedgerNarration::limit("Payment {$number} — {$supplier->name}"),
    lines: $lines, reference: $number, documentType: DocumentType::JournalVoucher,
    source: SourceDocument::for($payment)), $actor)` (mirror `BillService.php:262-273`). The entry is a **JV**
    (dec. 4); `source_id` uniqueness stops a second post (AC-2.8, `…add_source_document…:63-67`).
14. **Save the fully-formed header** (born posted): `status = Posted`, `number`, `journal_entry_id = $entry->id`,
    `posted_at = now()`, `posted_by_id = $actor?->getKey()`, `amount`, `currency_code`, `exchange_rate = null`,
    all explicit (§A7). One INSERT — there is no half-state to protect against (contrast `BillService.php:276`).
15. **Save the allocation rows** (one per bill, `amount` each).
16. **Move each locked bill** — `amount_paid = bccadd(amount_paid, allocation)`,
    `amount_due = bcsub(total, amount_paid)` (preserving `bills_amount_due_check`), and `status`:
    `amount_due == 0 → Paid`; else `PartiallyPaid` (AC-2.2, AC-2.3). One `save()` per bill carrying all three
    columns together — the immutability trigger permits exactly these (`…000005…:18-22`). Money via `Money`,
    comparisons via `bccomp` — no floats.
17. `return $payment->refresh();`

All of 11–16 commit together; the deferred full-allocation trigger (§A5) passes at commit (Σ = `amount`).

Two named exceptions (both extend `BusinessRuleViolation`, so the future HTTP layer maps them uniformly,
mirror `BillCannotBePosted`): **`PaymentCannotBeRecorded`** for the input/allocation refusals (supplier &
bill ownership, membership, outstanding, cap, full-allocation, positivity, duplicate bill) and
**`PaymentCannotBePosted`** for the account-resolution and closed-period refusals (bank type/postable,
payable resolution/type/postable, cross-company on an account, closed period) — the same division bills draw
between `BusinessRuleViolation` (input) and `BillCannotBePosted` (posting). Each factory carries a stable
problem code (e.g. `payment-bill-not-outstanding`, `payment-allocation-exceeds-due`,
`payment-not-fully-allocated`, `payment-bank-account-wrong-type`).

## C5. Resolution helpers (Gate-2 PROPOSED)

Private helpers mirror `BillService`: `resolveSupplier` (ownership only — no `acceptsNewBills`),
`resolveBankAccount` (ownership; type/postable deferred to the map), `resolveBranchId`
(`BillService.php:587-606`), `assertDecimal`/`decimal` (`BillService.php:748-768`). No tax, expense-account
or duplicate-supplier-number helpers — a payment has no lines, no tax and no supplier-assigned number.

## C6. The born-posted ordering: pre-assigned id + `postNew` (Gate-2 PROPOSED)

A bill exists as a persisted draft before it posts, so `SourceDocument::for($bill)` has a saved key to read
(`BillService.php:266-273`). A payment does not — it is created and posted in one step (dec. 5) — and
`SourceDocument::for()` refuses an empty key (`SourceDocument.php:48-59`), while `journal_entry_id` is
NOT NULL (§A2). The resolution, inside the transaction:

1. `$payment = new SupplierPayment; $payment->id = $payment->newUniqueId();` — pre-assign the UUID.
   `HasUuids` only generates on `creating` when the key is empty, so a pre-assigned id is respected. Now
   `SourceDocument::for($payment)` succeeds (non-empty key + registered morph alias) **without** the row
   being saved.
2. Draw `PAY-…`, then `postNew(...)` with `source: SourceDocument::for($payment)` — the JV is posted citing
   the payment id.
3. `$payment->journal_entry_id = $entry->getKey();` then `$payment->save()` — the header is inserted **once**,
   fully-formed. There is **no** FK from `journal_entries` to `supplier_payments` (the source link is
   polymorphic, `…add_source_document…:44-50`), so the JV-before-header order is legal, and both rows commit
   together atomically.

This keeps `journal_entry_id` NOT NULL and the header born fully-formed, and it is the path
`PostingService::postNew()`'s docblock anticipates for "a payment" (`PostingService.php:87`). The rejected
alternative (nullable `journal_entry_id`, insert header, post JV, then UPDATE the link) is in Alternatives.

---

# D. `DocumentType::Payment` and numbering (Gate-1 APPROVED dec. 4; realisation Gate-2 PROPOSED)

Add one case to `src/Core/Accounting/Domain/Enums/DocumentType.php` (mirror how `Bill` was added,
`DocumentType.php:23`):

```php
case Payment = 'payment';
// label():  'Payment'
// prefix(): 'PAY'      → numbers read PAY-2026-09-0001 (DocumentNumberService.php:82)
```

**Recommendation: `requiresGaplessNumbering()` returns `true` for `Payment` (gapless).** **(Gate-2 PROPOSED
— Gate-1 dec. 4 explicitly deferred this to the Architect.)** Reasoning, weighed against the method's own
rule (`DocumentType.php:50-63`: gapless "for anything a tax authority may audit for completeness, and not
worth it otherwise"):

- **A payment is *originated* by us, not received.** That is the exact hinge on which `Bill` was made the
  sole non-gapless case ("a bill is received, not issued … no authority audits *our* internal bill numbers",
  `:56-58`). A payment sits on the other side of that line, with the *issued* documents (sales invoices,
  journal vouchers, opening balances) — all gapless.
- **Disbursement completeness is a real control.** A gap in a payment/cheque sequence is the classic "what
  happened to that disbursement?" an auditor and an internal control both look for; gaplessness makes a
  hidden or deleted payment impossible rather than merely detectable.
- **The cost is already paid.** Gapless costs a per-document row lock on the sequence
  (`DocumentNumberService.php:24-31`); `record()` is already a transaction that locks several bill rows, and
  the sequence lock is acquired *after* them (§F), so it adds no new contention and cannot deadlock. SME
  per-company/period payment volume is low.
- **It is the smaller code change.** `requiresGaplessNumbering()`'s body is `$this !== self::Bill`
  (`:60-63`), which already yields `true` for `Payment` — so gapless needs **no method change**, only the new
  case/label/prefix, and `Bill` remains the single documented exception. Non-gapless would require widening
  the body to exclude `Payment` too, contradicting that docblock.

The JV the payment posts is unchanged — `DocumentType::JournalVoucher`, gapless as always. Two series are
drawn per payment (`PAY` and `JV`); §G/§H mandate the two-series multi-document test (the ADR 0009 §B trap:
a single-document test passes either way).

---

# E. Authorization (Gate-1 APPROVED dec. 5; realisation Gate-2 PROPOSED)

## E1. Catalogue — two capabilities, no `.draft`

Add to `PermissionCatalogue::purchasing()` (after the bills block, `PermissionCatalogue.php:292-299`):

```php
new PermissionDefinition('purchasing', 'payments', 'view', 'View payments',
    'Read supplier payments and their allocations.', sortOrder: 60),
new PermissionDefinition('purchasing', 'payments', 'post', 'Record and post payments',
    'Record a supplier payment and commit it to the ledger.', sensitive: true, sortOrder: 70),
```

`post` is **sensitive** (it moves money and commits to the ledger, the strongest case for the marker in the
module). There is **no `.draft`** — record-and-post is one step (dec. 5), so the bill's three-way
`{view, draft, post}` collapses to `{view, post}`. Owner (via `Gate::before` wildcard) and Administrator
(via `tenantGrantableNames()`, `PermissionCatalogue.php:66-75`) inherit both automatically — no explicit
grant. **(Gate-1 APPROVED dec. 5; catalogue shape Gate-2 PROPOSED.)**

## E2. Role grants (Gate-2 PROPOSED — recommend, confirm)

| Role | `payments.view` | `payments.post` | Reasoning |
| --- | --- | --- | --- |
| accountant | ✓ | ✓ | The side of the split that commits to the ledger — mirror `bills.post` (`RoleTemplate.php:122-124`). |
| bookkeeper | ✓ | ✗ | Posting a payment moves money; with no `.draft` to hold, the bookkeeper reads payments (to reconcile) but does not commit them — the safe default, mirroring bookkeeper holding `bills.draft` but not `bills.post` (`:180-181`). |
| viewer | ✓ | ✗ | Read-only, mirror `bills.view` (`:228`). |

**Confirm the bookkeeper split (item 5).** The alternative — grant the bookkeeper `payments.post` too, if a
business wants its AP clerk to both record and post — is a coherent business choice; I recommend view-only
for the money-moving action and flag it rather than deciding silently.

## E3. `PaymentPolicy` (Gate-2 PROPOSED)

`src/Core/Purchasing/Policies/PaymentPolicy.php`, mirror `BillPolicy` (permission **AND** company on every
method with a model, `BillPolicy.php:38-78`), minus the draft/lifecycle methods:

```php
public function viewAny(User $user): bool
    { return $user->can('purchasing.payments.view'); }
public function view(User $user, SupplierPayment $payment): bool
    { return $user->can('purchasing.payments.view') && $user->canAccessCompany($payment->company_id); }
public function create(User $user): bool                     // "record and post" — the sensitive capability
    { return $user->can('purchasing.payments.post'); }
```

No `update`, `delete`, `post(…, $payment)` or `cancel` — a payment is immutable (§A5), born posted (no
separate post of an existing model), and uncancellable this wave. Company membership for `create()` is
checked at the service against the target `$company` (there is no model yet), exactly as `BillPolicy::create`
checks only the permission and the service validates the company (`BillPolicy.php:44-47`).

Register in `PurchasingServiceProvider::boot()` (`PurchasingServiceProvider.php:53-61`):
`Relation::morphMap([SupplierPayment::MORPH_ALIAS => SupplierPayment::class])` (merged, per-module) and
`Gate::policy(SupplierPayment::class, PaymentPolicy::class)`. Register `PaymentService` as a singleton in
`register()` (mirror `:32-33`). `PaymentAllocation` gets no alias and no policy (not addressable, not a
source).

---

# F. Concurrency — the multi-bill lock, and the probe invariant made load-bearing

## F1. The lock order, and why it is deadlock-free (Gate-2 PROPOSED, realising Gate-1 §Risk 2 / AC-2.6)

This is the one mechanism with no precedent. Every posting path acquires locks in one global order:

**(1) the document's own bill rows, ascending by `id` → (2) the internal-number sequence → (3) the `JV`
sequence → (4) the ledger inserts.**

- `PaymentService::record()`: locks its N bills `ORDER BY id` (step 11), *then* draws `PAY` (step 13.a),
  *then* `JV` (inside `postNew`), *then* inserts the entry.
- `BillService::post()`: locks its 1 bill (`:250-258`), *then* draws `BILL` (`:262`), *then* `JV`, *then*
  inserts (`:266`). Same order, one bill.

Two concurrent payments over overlapping bills `{A, B}` (A < B) both lock A then B — a total order on the
shared resource, so no cycle. A payment and a `BillService::post()` contend only on a single shared bill
row, which is a plain mutex (one holds, the other waits, no cycle). The property that removes the last cycle
risk: **a transaction locks every bill it will touch *before* it draws any sequence number**, so once it
holds a sequence lock it never waits on a bill — the `JV` sequence (shared by both paths) is only ever
acquired by a holder that already holds all its bills. `PAY` and `BILL` are different `document_sequences`
rows (different `document_type`), so they never contend with each other.

Lost updates are prevented by the re-read under the lock (step 12): the pre-transaction outstanding/cap
checks are advisory; the locked re-read is authoritative, exactly as `BillService::post()` treats its
pre-transaction status check as advisory and its locked re-read as the guard (`BillCannotBePosted.php:34-38`).

## F2. `EloquentPayableBalanceProbe`'s `amount_due` becomes load-bearing (Gate-1 §Risk 5)

`outstandingBalance()` sums `amount_due` over `scopeOutstanding()` bills and normalises with
`bcadd(…, Money::SCALE)` (`EloquentPayableBalanceProbe.php:54-66`). Its comment (`:48-51`) says it reads
`amount_due` rather than `total - amount_paid` because "Wave 8 drops [the phase CHECK], and at that point
the stored column is the one the payment allocation maintains." This wave is that point. The invariant holds
by construction: `record()` maintains `amount_due = total - amount_paid` on every bill it moves (step 16),
`bills_amount_due_check` asserts it at the row (`bills:166-170`), and a fully-paid bill flips to `Paid` and
leaves `scopeOutstanding()` (`Bill.php:222-228`), so the supplier balance drops by exactly what was
allocated and a settled bill contributes nothing (AC-2.4). §H mandates the regression test that a partial
payment leaves probe balance == Σ `amount_due` == Σ (`total − amount_paid`).

---

# G. Build stages (test-first / RED-first per the gate policy)

Each stage is an independently reviewable RED→GREEN cycle: QA writes the failing tests first, the engineer
implements to green. No stage folds another's reviewer gate.

| Stage | Deliverable | Files (create unless noted) | Test-first artefact | Green when |
| --- | --- | --- | --- | --- |
| **1. Relax bills + payment schema, RLS, immutability** | 5 migrations (§A1): drop `bills_no_payments…` + add `bills_amount_due_non_negative_check`; `supplier_payments`+`payment_allocations`; RLS; freeze + deferred full-allocation triggers. | `Purchasing/…/2026_09_03_000001…005…` | `PaymentSchemaTest` + `PaymentSchemaCheckMutationTest` (mirror `BillSchemaTest`+`BillSchemaCheckMutationTest`): every column/index/CHECK incl. both unique indexes; each CHECK **non-vacuously** rejects a crafted bad row; `RowLevelSecurity::isEnforced` on **both** tables (FORCE); payment immutability freezes a posted payment + refuses delete; allocation freeze; **deferred trigger rejects Σ≠amount at commit**; `bills_no_payments…` gone (amount_paid moves) and `amount_due>=0` rejects overpayment while `bills_amount_due_check` survives; cross-tenant raw-SQL read hidden + write refused. | migrations run; RLS + triggers enforce |
| **2. `DocumentType::Payment` + `PaymentStatus` + models + factories** | enum case; `PaymentStatus`; `SupplierPayment`/`PaymentAllocation`; factories; morph alias in provider boot. | `Domain/Enums/PaymentStatus.php`; `Domain/Models/SupplierPayment.php`,`PaymentAllocation.php`; `database/factories/…`; **edit** `DocumentType.php`, provider | `PaymentModelTest`/`PaymentFactoryTest`: casts, relations, `PaymentStatus` label/isPosted; morph round-trip; `DocumentType::Payment` prefix `PAY`, **`requiresGaplessNumbering() === true`** and **`Bill` still `false`**. | models/enum/factory behave |
| **3. DTOs + `PaymentPostingMap` + exceptions** | `PaymentData`/`PaymentAllocationData`; `PaymentPostingMap`; `PaymentCannotBeRecorded`/`PaymentCannotBePosted`. | `Application/DTOs/Payment*Data.php`; `Application/Services/PaymentPostingMap.php`; `Domain/Exceptions/Payment*.php` | `PaymentPostingMapTest` (mirror `BillPostingMapTest`): `Dr Trade Payables (total) = Cr Bank/Cash (total)`; balances by construction; refusals — bank not Asset / not postable / cross-company; payable missing / wrong-type / not-postable — each names its account. | map builds correct balanced lines |
| **4. `PaymentService::record()`** | `record()`; two-series numbering; singleton + provider wiring. | `Application/Services/PaymentService.php`; **edit** provider | `RecordPaymentTest` (mirror `PostBillTest`) + `PaymentAllocationRaceTest` (mirror `BillDuplicateNumberRaceTest`): full record+post; every AC-1.x/2.x refusal; **AC-1.10 archived-supplier still payable**; full-allocation + per-bill cap; status `Posted→PartiallyPaid→Paid`; closed-period-before-number; cross-company (supplier/bill/bank); `source_id` unique blocks a second post; **multi-bill lock re-check + concurrent overlapping-bill race**; **two-series test** (post several payments, assert neither `PAY` nor `JV` gaps the other, and `PAY` is gapless). | full lifecycle green |
| **5. Authorization** | `purchasing.payments.{view,post}`; grants; `PaymentPolicy`; `Gate::policy`. | `Policies/PaymentPolicy.php`; **edit** `PermissionCatalogue.php`, `RoleTemplate.php`, provider | `PaymentAuthorizationTest` (mirror `BillAuthorizationTest`): catalogue declares two, **`post` sensitive / `view` not, no `.draft`**; accountant post, bookkeeper + viewer view-only; policy permission×membership; owner/admin inheritance intact. | authz green |
| **6. Probe regression (Risk 5 / AC-2.4)** | none (verification only). | — | `PayableBalanceProbePaymentsTest` (extend `PayableBalanceProbeTest`): after a partial payment, `outstandingBalance()` == Σ `amount_due` == Σ (`total − amount_paid`); a fully-paid bill (`Paid`) leaves the outstanding set and drops the supplier balance by exactly what was allocated. | invariant holds after payment |

## H. Test strategy (QA writes tests before implementation)

Written fresh (no receipt suite exists) but *shaped* by the bill suites in `tests/Feature/Purchasing/`,
inverting the money flow and dropping the draft/tax cases:

- **Schema + CHECK mutation** (shape `BillSchemaTest.php`, `BillSchemaCheckMutationTest.php`): every
  column/index/CHECK; both unique indexes enforce; each CHECK **non-vacuously** rejects a crafted bad row
  (the `SupplierSchemaCheckMutationTest` lesson, commit `80cc5a9`); `exchange_rate` phase CHECK pins null;
  payment freeze + un-delete refusal; allocation freeze; the **deferred full-allocation trigger** rejects
  an under/over-allocated payment at commit and a rogue post-hoc allocation INSERT; on `bills`, the dropped
  phase CHECK lets `amount_paid` move, the new `amount_due>=0` rejects overpayment, and
  `bills_amount_due_check` still holds.
- **Posting map** (shape `BillPostingMapTest.php`): the balanced two-line entry; bank Asset/postable/company
  checks; payable key-resolution / liability / postable / missing.
- **Record + post** (shape `PostBillTest.php`): the `Dr/Cr` entry; `PAY` from `Payment`, `JV` from
  `JournalVoucher`; closed-period-before-number; cross-company (supplier/bill/bank); **archived-supplier
  still payable** (AC-1.10 — the deliberate divergence from `acceptsNewBills`); full-allocation refusal;
  per-bill cap refusal (pre-check *and* the locked re-read); status transitions; `source_id` unique blocks a
  second post; the **two-series multi-payment** numbering test (ADR 0009 §B — "every single-document test
  passes either way").
- **Concurrency** (shape `BillDuplicateNumberRaceTest.php`): two payments racing on an overlapping bill set —
  the locked re-read refuses the loser readably, no lost update, no negative payable, and (the new mechanism)
  no deadlock across the fixed lock order.
- **Payable probe regression** (shape `PayableBalanceProbeTest.php`): §F2 / AC-2.4.
- **Authorization** (shape `BillAuthorizationTest.php`): catalogue shape/sensitivity, the two grants, policy
  permission×membership, owner/admin inheritance.

## Alternatives considered

- **Derive `amount` from Σ allocations, no caller total** — rejected (§C3): a supplied-and-validated total
  catches a client that dropped an allocation and gives the deferred DB backstop a real figure to check.
- **Nullable `journal_entry_id`: insert header, post JV, then UPDATE the link** — rejected (§C6): it
  weakens the born-fully-formed guarantee, needs the header trigger to permit a post-insert link change, and
  leaves a momentary posted payment with no ledger link. Pre-assigning the UUID keeps NOT NULL and is the
  path `postNew` was written for.
- **No deferred full-allocation trigger — enforce Σ = amount in the app only, freeze allocations on
  UPDATE/DELETE only** — rejected (§A5): the born-posted model lets a rogue INSERT add an allocation to a
  committed payment (no parent-draft to gate on), silently breaking the subledger. The deferred trigger
  closes that and makes full allocation a database invariant. Kept as the fallback if Gate 2 judges the
  constraint trigger too heavy.
- **Per-bill journal lines (one `Dr Trade Payables` per bill)** — rejected (§B): Trade Payables is a control
  account; the per-bill detail is the allocation subledger, cited via the source document, exactly as bill
  lines are grouped into one payable credit (`BillPostingMap.php:37-42`).
- **Resolve a per-company "primary bank account" setting** — rejected by Gate-1 dec. 1: caller-named needs
  no new concept and mirrors the one real analogue (a bill line's expense account).
- **Non-gapless `PAY` like `Bill`** — rejected (§D): a payment is originated, not received; disbursement
  completeness is an audit control; and gapless is the smaller code change (`Bill` stays the sole exception).
- **Build cancellation / WHT / prepayment this wave** — deferred (Gate-1 dec. 6, 8, 2): the scope discipline
  Risk 7 warns against; each is a named later slice.

## Consequences

- Purchasing gains its first multi-document, born-posted transaction. The `bills.amount_paid`/`amount_due`
  seam and the reserved `partially_paid`/`paid` statuses go live; the phase CHECK Wave 7 left for this wave
  is dropped and replaced by a stronger `amount_due >= 0` invariant.
- `DocumentType` gains a second gapless-originated document; `requiresGaplessNumbering()` needs no body
  change (`Bill` remains the single non-gapless case).
- The permission catalogue grows by two tenant-grantable capabilities; owner/administrator inherit them.
- `EloquentPayableBalanceProbe`'s anticipated `amount_due` invariant is exercised for the first time; the
  probe now reports live payable balances that move as payments post.
- Wave 8b (WHT-on-payment) and the payment-cancellation slice land on ready targets: 8b adds a `WHT Payable`
  account (leaf + key + backfill) and a third journal line; cancellation adds columns + the reserved
  `cancelled` transition the trigger already permits — behaviour, not schema widening.

## Risks

- **Multi-row locking is new (Gate-1 Risk 2).** A deadlock or lost update here corrupts a payable balance.
  Mitigated by the fixed ascending-id lock order acquired before any sequence, the locked re-read, and the
  mandatory `PaymentAllocationRaceTest` (§F, §G/§H).
- **The probe's `amount_due` invariant (Gate-1 Risk 5).** Mitigated by the Stage 6 regression test (§F2).
- **Dropping `bills_no_payments_until_payments_phase` must not disturb `bills_amount_due_check` or
  `bills_non_negative_check` (Gate-1 Risk 4).** Mitigated by the schema-mutation test asserting all three
  outcomes after migration.
- **Born-posted immutability differs from bills' draft-gated triggers.** The allocation freeze cannot gate
  on parent-draft; the deferred sum-check + freeze trigger replace it, and the record-and-post INSERT path
  must not be blocked by either — a named schema test.
- **Pre-assigned UUID + JV-before-header ordering (§C6)** relies on there being no FK from `journal_entries`
  to `supplier_payments`; the `source_id` uniqueness test confirms a second post is still refused.
- **Gapless `PAY` under the multi-bill lock:** the sequence lock must be acquired *after* all bill locks
  (§F) — the deadlock-safety property, asserted by the race test.
- **Greenfield, no receipt precedent (Gate-1 Risk / dec. 0).** Every choice is first-of-its-kind; the
  file:line citations to the bill originals are the review checklist, and Phase 4↔5 reconciliation is a
  later merge-time task, not a blocker.

## Gate 2 — items to confirm

No UNRESOLVED fork blocks the build; confirm the **(Gate-2 PROPOSED)** shapes:

1. **Schema** (§A2–A6): `supplier_payments` (born posted — `number`/`journal_entry_id`/`posted_at` NOT NULL,
   no draft, no `deleted_at`) + `payment_allocations`; unique `(company_id, number)` and
   `(supplier_payment_id, bill_id)`; status CHECK reserving `cancelled`; `exchange_rate` phase CHECK; FORCED
   RLS on both; the payment + allocation freeze triggers.
2. **The dropped/added bills CHECKs** (§A4): drop `bills_no_payments_until_payments_phase`; add
   `bills_amount_due_non_negative_check`; retain `bills_amount_due_check` and `bills_non_negative_check`.
3. **The deferred full-allocation trigger** (§A5): `supplier_payments_fully_allocated` as the database
   authority for Gate-1 dec. 2 and the rogue-insert backstop (vs the app-only fallback).
4. **Posting** (§B): `Dr Trade Payables (total) = Cr Bank/Cash (total)`, two lines; Trade Payables by key;
   bank caller-named, validated **Asset** + postable + same company; refusals as `PaymentCannotBePosted`.
5. **Numbering** (§D): `DocumentType::Payment`, prefix `PAY`, **gapless (`requiresGaplessNumbering() = true`)**
   — confirm gapless over non-gapless.
6. **Lifecycle & amount** (§C3, §C4): record-and-post in one `record()`; `amount` **supplied and validated
   == Σ allocations** (vs derived); the pre-assigned-UUID + `postNew` ordering (§C6, vs nullable-then-link).
7. **Concurrency** (§F): the fixed ascending-id bill lock, acquired before any sequence number; the locked
   re-read as the authoritative cap/outstanding check.
8. **Permissions** (§E): `purchasing.payments.{view, post}` (`post` sensitive, **no `.draft`**), granted
   accountant (both) / **bookkeeper (view-only)** / viewer (view-only) — confirm the bookkeeper split;
   `PaymentPolicy` mirrors `BillPolicy`; no `cancel`.
9. **Bank account type** (§B): restricted to **Asset** for 8a (bank/cash); non-asset funding sources
   (overdraft/credit-card modelled as a Liability) deferred — confirm.

---

*Prepared by the Solution Architect (Stage 3, Phase 5 / Wave 8a). Design only — no production code written,
nothing committed. Build begins after Gate 2 approval, on this ADR, verbatim; any implementation discovery
that would change a decision returns to Gate 2.*
