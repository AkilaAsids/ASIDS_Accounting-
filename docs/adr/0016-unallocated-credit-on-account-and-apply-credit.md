# ADR 0016 — Unallocated credit on account: a variable-line receipt, a per-receipt held-credit subledger, and apply-credit as a reclassification

- **Status:** Proposed — awaiting **Gate 2 (Architecture approval)**. Gate 1 is approved and binding (`docs/PHASE-4-CREDIT-ON-ACCOUNT-REQUIREMENTS.md` → "Gate 1 decisions — APPROVED 2026-08-26").
- **Date:** 2026-08-26
- **Milestone:** Phase 4, unallocated-credit-on-account sub-slice (record + hold + apply). Branch `feature/phase4-credit-on-account`, stacked on `feature/phase4-cancellation` (ADR 0015).
- **ADR number:** 0016. 0015 is receipt cancellation; 0014 is receipts; 0013 is the Phase 3 front-end ADR on `feature/phase3-frontend` (`docs/adr/0013-phase3-frontend-architecture.md`, confirmed present only on that branch). 0016 is the next free number on this branch — verified no 0016 exists on any branch.
- **Scope of this document:** DESIGN ONLY. No production code, migrations, tests, permission/route/OpenAPI changes, or dependencies are written by this wave's Stage 3. The only repository write is this ADR. Every non-Gate-1 decision below is labelled **(Gate-2 PROPOSED)** or **(UNRESOLVED — needs explicit human approval)**; nothing labelled either is to be treated as settled until Gate 2.

---

## How to read the labels

Every load-bearing decision carries exactly one tag:

- **(Gate-1 APPROVED)** — settled by the human on 2026-08-26; binding; the build follows it verbatim.
- **(Gate-2 PROPOSED)** — the Architect's recommendation, needing the human's yes at Gate 2 before the build starts.
- **(UNRESOLVED — needs explicit human approval)** — a genuine fork the Architect will not decide unilaterally.

---

## Context

Three shipped, verified foundations this wave sits on and must not regress:

- **ADR 0014** (record + allocate + post): a receipt today must be **fully allocated** — `Σ allocations = amount`, both over- and under-allocation refused (`ReceiptService::assertFullyAllocated()`, `src/Core/Sales/Application/Services/ReceiptService.php:439-453`, throwing `ReceiptCannotBeRecorded::overOrUnderAllocated()`). Its posting is a **fixed two-line** contract, Dr Bank / Cr Trade Receivables for the whole amount (`ReceiptPostingMap::for()`, `src/Core/Sales/Application/Services/ReceiptPostingMap.php:55-81`). The receivable account is resolved per allocated invoice, reusing `InvoicePostingMap::receivableAccountFor()` (`InvoicePostingMap.php:97-121`), and a receipt whose invoices disagree is refused (`ReceiptCannotBePosted::receivableAccountsDiffer()`, `ReceiptCannotBePosted.php:100-112`).
- **ADR 0015** (cancellation): reversing a posted receipt by **delta, not snapshot** — subtract this receipt's own allocation from each invoice's current locked `amount_paid` (`ReceiptService::cancel()` `:366-389`), guarded by `wouldReverseBelowZero()` (`:373-379`; `ReceiptCannotBeCancelled.php:171-184`). Cancellation opens exactly one `posted → cancelled` transition through a conditional-immutability trigger (`2026_03_09_000001_add_cancellation_to_customer_receipts.php:79-132`).
- **The ledger seam**: `PostingService::postNew()`/`reverse()` (`src/Core/Accounting/Application/Services/PostingService.php:91-178`), `DocumentNumberService`, `SourceDocument`, and the partial unique index `journal_entries_source_document_unique (tenant_id, source_type, source_id) WHERE source_id IS NOT NULL AND reverses_entry_id IS NULL` (ADR 0014 §C, `2026_03_01_000001:63-67`). `reverse()` mirrors **every** line of an entry with sides swapped and amounts copied, not recomputed (`PostingService.php:138-147`), and carries the original's source document forward (`:162`).

## Problem

A customer overpays: they send more than clears the invoice(s) they are paying. Today the whole receipt is refused (`assertFullyAllocated()`). The business needs the excess **held as credit against that customer**, the ledger to keep balancing, and — approved this wave — the ability to **apply** that held credit to a later invoice without new cash arriving. This is the deferred boundary ADR 0014 Risk 5 named and refused to half-build; this wave opens it deliberately.

Two facts discovered by repository inspection shape the whole design and are not preferences:

1. **The source-uniqueness index forces a per-application record.** A receipt already owns exactly one non-reversing posting whose `source_id` is that receipt. An apply-credit event posts a *second, different* journal entry (Dr Customer Advances / Cr Trade Receivables). If it cited the receipt as its source it would collide at `journal_entries_source_document_unique`; if it cited the target invoice it would collide with that invoice's own issue posting; two applications to one invoice would collide with each other. **Therefore every apply event must be its own source document, which means every apply event must be its own row.** This is why the held-credit model is two tables, not one (§B), decided by a database constraint rather than taste.
2. **An invoice that received applied credit cannot be cancelled — already.** Applying credit raises the target invoice's `amount_paid` (the forward mirror of the record-side split, Gate-1 #2), and `SalesInvoiceService::cancel()` already refuses any invoice with `amount_paid > 0` (`SalesInvoiceService.php:370-371`, `InvoiceCannotBeCancelled::partiallyPaid()`). So "invoice cancelled after credit applied" needs **no new code** — it is refused by an existing, tested guard (§G).

## Approved Gate-1 constraints (all **Gate-1 APPROVED**, binding)

1. Accounting treatment = a **new liability account "Customer Advances"** (new `Account` system key + a Current-Liabilities leaf in `ChartTemplate`). Receipt posting becomes **variable-line**: Dr Bank = Cr Trade Receivables (allocated) + Cr Customer Advances (remainder).
2. **Apply-credit is in scope** end-to-end: record → hold → apply held credit to a later invoice. Apply reclassifies **Dr Customer Advances / Cr Trade Receivables** and restores the target invoice's balance forward (mirror of the record-side split).
3. A receipt must still **name ≥ 1 invoice** — the empty-allocation refusal (`ReceiptCannotBeRecorded::withoutAllocations()`, `ReceiptService.php:412-416`) stays; only a *remainder* on an otherwise-allocated receipt is newly permitted. No pure-prepayment receipts.
4. Held credit is tracked **per-receipt** (each remainder its own record), consumed specifically/FIFO on apply, delta-unwound per-record on cancel.
5. **Over-allocation stays refused** (`Σ allocations > amount`), unchanged from ADR 0014.
6. **Cash refund of held credit is out of scope.**

Deferred to this ADR / Gate 2: the apply-credit **permission** (Gate-1 recommendation: a new accountant-only `sales.receipts.apply-credit`, not broadening `manage`), and the apply-credit **posting/document shape** (Gate-1 direction: a reclassification JV, mirror of the record-side split).

---

## Decision (overview)

- **Account (§A):** add `Account::CUSTOMER_ADVANCES = 'customer_advances'`; a `2180 Customer Advances` Current-Liabilities leaf; register in `ChartTemplate::accounts()` **and** `requiredSystemAccounts()`; bump `ChartTemplate::VERSION`; a backfill migration that **creates** the account for every existing company (unlike Trade Receivables, which only needed *stamping* an account that already existed).
- **Held-credit model (§B):** two tenant-scoped, RLS-forced, immutable-by-trigger tables — `receipt_held_credits` (one per receipt remainder; the mutable balance) and `credit_applications` (one per apply event; each its own JV source document).
- **Receipt posting (§C):** `ReceiptPostingMap` becomes variable-line; AR credited for `Σ allocations`, Customer Advances credited for the remainder when positive; identical two-line output when the remainder is zero (regression-safe).
- **Apply-credit (§D, §E):** `ReceiptService::applyCredit()` — a new operation on the existing service; FIFO across the customer's active held credits by default, with an optional explicit source receipt; each consumed record produces one `credit_application` + one JV.
- **Permission (§F):** new accountant-only `sales.receipts.apply-credit`.
- **Cancellation (§G):** receipt with untouched credit → the generic mirror reverses the whole JV (including Customer Advances); the held-credit record is delta-zeroed and marked cancelled. Receipt whose credit was applied → **refuse** (new guard). Invoice with applied credit → refused by the existing `partiallyPaid()` guard.
- **HTTP (§I):** **not this wave.** Design is provided for a later slice; recommended deferral is a Gate-2 decision.

---

## A — Account design: `2180 Customer Advances`, a new system account created for every company

**(Gate-1 APPROVED)** that this is a new liability account with a new system key. **(Gate-2 PROPOSED)** for every specific value below.

### The constant and the leaf

- `Account::CUSTOMER_ADVANCES = 'customer_advances'` **(Gate-2 PROPOSED)** — a fourth system key beside `RETAINED_EARNINGS`, `OPENING_BALANCE_EQUITY`, `TRADE_RECEIVABLES` (`src/Core/Accounting/Domain/Models/Account.php:60-71`). Value ≤ 48 chars to fit `accounts.system_key varchar(48)` (`2026_02_02_000001_create_accounts_table.php:71`).
- **Code `2180`, name `Customer Advances`, type `AccountType::Liability`.** **(Gate-2 PROPOSED)** — the code is chosen by inspection, not assumption: `ChartTemplate::accounts()` (`src/Core/Accounting/Domain/Catalogue/ChartTemplate.php:97-106`) fills Current Liabilities (`2100`) with leaves `2110` Trade Payables, `2120` Other Payables, `2130` Accruals, `2140` Output VAT Payable, `2150` PAYE/APIT, `2160` EPF, `2170` ETF; the next heading is `2200` Non-Current Liabilities. `2180` is the first free leaf under `2100` and does not force renumbering any existing account (which the platform forbids in spirit — codes are stable handles, `Account.php:53-71`). `normal_balance` is **not specified**: the model derives it from the type on save (`Account::booted()` `:217-228` → `Credit` for a liability), and the `accounts` table would reject a mismatch.
- **A required system account.** **(Gate-2 PROPOSED)** — added to **both** `ChartTemplate::accounts()` (as `self::leaf('2180', 'Customer Advances', AccountType::Liability, parent: '2100', system: Account::CUSTOMER_ADVANCES)`) **and** `ChartTemplate::requiredSystemAccounts()` (as a parentless leaf, `system: Account::CUSTOMER_ADVANCES`), mirroring exactly how Trade Receivables appears in both — with a parent in `accounts()` (`:82`) and parentless in `requiredSystemAccounts()` (`:164`). Parentless in the required-accounts path because `ChartTemplateService::ensureSystemAccounts()` (`ChartTemplateService.php:78-104`) creates required accounts without resolving a parent, exactly as it does for Trade Receivables today.
- **`ChartTemplate::VERSION` bumped** `2026.08-lk-sme-2` → `2026.08-lk-sme-3` **(Gate-2 PROPOSED)** — the constant's own contract: bump "whenever the accounts below change … an addition" (`ChartTemplate.php:36-40`).

### Provisioning and backfill — where this genuinely differs from Trade Receivables

New companies get `2180` automatically: `apply()` walks `accounts()` (`ChartTemplateService.php:34-67`) and `ensureSystemAccounts()` walks `requiredSystemAccounts()` (`:78-104`), and the latter is called after provisioning and is idempotent (guarded by the partial unique index `accounts_company_system_key_unique`, `2026_02_02_000001:113-115`).

Existing companies are the hard case, and it is **not** the Trade Receivables case. Trade Receivables already existed as `1130` in the prior template, so `2026_03_05_000003_stamp_trade_receivables_system_key.php` only had to **stamp** an existing row (`UPDATE … SET system_key, is_system`). Customer Advances **has never existed in any template**, so no row exists to stamp — every existing company needs the account **created**. **(Gate-2 PROPOSED)** a backfill migration that, under `RowLevelSecurity::bypass()`, for each company lacking a `customer_advances`-keyed active account, **inserts** `2180 Customer Advances` (or the next free code variant where `2180` is taken, mirroring `availableCode()`, `ChartTemplateService.php:113-141`), with `is_system = true` **and** `system_key` set together (required by `accounts_system_key_check`, `2026_02_02_000001:137`, the exact trap `2026_03_05_000003:47-55` documents), `normal_balance = 'credit'`, `is_postable = true`, `is_active = true`, `parent_id` = the company's `2100` Current-Liabilities heading when resolvable else null (parentless is acceptable — that is what `ensureSystemAccounts()` produces). It reuses the two disciplines the Trade Receivables migration "learned the hard way" (`2026_03_05_000003:47-66`):
  1. **Bypass RLS explicitly** (`asids_app` is `NOBYPASSRLS`, `accounts` is FORCED — without a bypass the migration silently touches zero rows), asserted by an `assertBypassEffective()` analogue (`2026_03_05_000003:142-152`).
  2. **Assert nothing is left behind** — after running, every company holds exactly one active `customer_advances` account, an `assertNothingLeftBehind()` analogue (`:166-193`), so a company whose first over-payment would otherwise fail months later is caught now.

  **(UNRESOLVED — needs explicit human approval):** whether the backfill inserts via **raw SQL** (the `2026_03_05_000003` precedent — stable, immune to later service drift) or by **iterating companies and calling `ensureSystemAccounts()`** (DRY, reuses tested creation incl. code-collision handling, but couples a migration to a service that may evolve). Recommendation: raw SQL, for the same "a migration is a statement about the past" reasoning `2026_03_05_000003:19-26` gives.

### Participation in posting

Customer Advances is credited at record time (the remainder, §C) and debited at apply time (§D). It is resolved by key, never by code — `Account::query()->forCompany($id)->withSystemKey(Account::CUSTOMER_ADVANCES)->first()` — exactly as `InvoicePostingMap::receivableAccountFor()` resolves Trade Receivables (`InvoicePostingMap.php:106-109`), validated `type === Liability` and `acceptsPostings()`, refusing with a new `ReceiptCannotBePosted::withoutCustomerAdvancesAccount()` (mirror of `InvoiceCannotBePosted::withoutReceivableAccount()`) when a company has none.

---

## B — Held-credit model: two immutable, RLS-forced tables

**(Gate-1 APPROVED)** per-receipt tracking. **(Gate-2 PROPOSED)** the two-table shape and every column below. The two-table shape is **forced** by the source-uniqueness index (see Problem #1), not chosen for convenience — a single balance table cannot back more than one apply posting.

The design answers, explicitly, every question the brief demands:

| Question | Answer |
| --- | --- |
| What creates the credit? | `ReceiptService::record()` when `remainder = amount − Σ allocations > 0` (§C). |
| How is the source receipt identified? | `receipt_held_credits.customer_receipt_id`, **unique** (one held-credit record per receipt — Gate-1 #4). |
| Original amount? | `receipt_held_credits.original_amount` (= the remainder at record time), frozen. |
| Remaining / unapplied? | `receipt_held_credits.remaining_amount`, mutable, `= original_amount − applied_amount` (DB-enforced tie). |
| Applied? | `receipt_held_credits.applied_amount`, mutable, sum of its `credit_applications.amount`. |
| One receipt → multiple applications? | Yes — many `credit_applications` rows may reference one `receipt_held_credit_id`. |
| One invoice ← credit from multiple receipts? | Yes — many `credit_applications` rows may reference one `sales_invoice_id` (each a distinct source document). |
| Partial application? | Yes — an application's `amount` may be less than the record's `remaining_amount`. |
| Cancellation representation? | `receipt_held_credits.status = 'cancelled'` with `remaining_amount` delta-zeroed (§G). |
| Can a cancelled receipt leave usable credit? | No — cancellation is refused if any credit was applied, and zeroes remaining if none was (§G); FIFO only considers `status = 'active' AND remaining_amount > 0`. |
| What prevents double-application? | Consumption decrements `remaining_amount` under a row lock; the DB CHECK `remaining_amount >= 0` (+ the tie + `applied_amount <= original_amount`) is the backstop even if the service is bypassed; each apply's JV is guarded by the source-uniqueness index. |

### `receipt_held_credits` (the per-receipt balance)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid pk | `HasUuids` |
| `tenant_id` | uuid FK → tenants, cascade | own RLS key (not transitive) |
| `company_id` | uuid FK → companies, cascade | denormalised, indexed |
| `customer_id` | uuid FK → customers, **restrict** | the credit's owner — never crosses customers |
| `customer_receipt_id` | uuid FK → customer_receipts, **restrict**, **UNIQUE** | one record per receipt (Gate-1 #4); restrict so the source stays resolvable |
| `currency_code` | char(3) | base currency; matches the receipt |
| `original_amount` | numeric(19,4) | the remainder at record time; frozen |
| `applied_amount` | numeric(19,4) | running; starts `0` |
| `remaining_amount` | numeric(19,4) | running; starts `= original_amount` |
| `status` | string(16), default `'active'` | `active` \| `cancelled`; one-value-widenable IN, the ADR 0014 device |
| `created_by_id` | uuid FK → users, nullable, nullOnDelete | who recorded the parent receipt |
| `created_at` / `updated_at` | timestamptz | |

CHECK constraints (all backstops under the service, the ADR 0014 discipline):

- `receipt_held_credits_original_positive_check CHECK (original_amount > 0)` — a zero remainder creates no record at all (§C).
- `receipt_held_credits_applied_non_negative_check CHECK (applied_amount >= 0)`.
- `receipt_held_credits_remaining_non_negative_check CHECK (remaining_amount >= 0)` — the over-consumption backstop, the analogue of `sales_invoices_amount_paid_not_exceeding_total_check` (ADR 0014 §A).
- `receipt_held_credits_balance_tie_check CHECK (remaining_amount = original_amount - applied_amount)` — the analogue of `sales_invoices_amount_due_check`; `remaining` and `applied` can never be written apart, exactly as `amount_due`/`amount_paid` cannot (`ReceiptService.php:222-237`).
- `receipt_held_credits_applied_not_exceeding_original_check CHECK (applied_amount <= original_amount)`.
- `receipt_held_credits_status_check CHECK (status IN ('active', 'cancelled'))`.
- `receipt_held_credits_cancelled_zero_check CHECK (status <> 'cancelled' OR remaining_amount = 0)` — a cancelled record holds no usable credit.

Indexes: `(tenant_id, company_id, status)` (RLS convention) and `(company_id, customer_id, status)` for the FIFO scan (§E).

### `credit_applications` (one row per apply event — each its own JV source)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid pk | its `MORPH_ALIAS` makes it a `SourceDocument` |
| `tenant_id` | uuid FK → tenants, cascade | own RLS key |
| `company_id` | uuid FK → companies, cascade | denormalised |
| `customer_id` | uuid FK → customers, **restrict** | denormalised for the cross-customer guard and audit |
| `receipt_held_credit_id` | uuid FK → receipt_held_credits, **restrict** | the credit consumed |
| `sales_invoice_id` | uuid FK → sales_invoices, **restrict** | the invoice reduced |
| `currency_code` | char(3) | base currency |
| `amount` | numeric(19,4) | the credit applied by this event |
| `journal_entry_id` | uuid FK → journal_entries, **UNIQUE**, restrict | the reclassification JV; UNIQUE is the double-post backstop, exactly as on the receipt (`2026_03_08_000001:83`) |
| `applied_at` | timestamptz | |
| `applied_by_id` | uuid FK → users, nullable, nullOnDelete | who applied it |
| `created_by_id` | uuid FK → users, nullable, nullOnDelete | audit-column pattern |
| `created_at` / `updated_at` | timestamptz | |

CHECK / unique:

- `credit_applications_amount_positive_check CHECK (amount > 0)` — a zero application is noise, a negative one would un-apply (the `receipt_allocations` reasoning, `2026_03_08_000002:63-67`).
- `journal_entry_id` UNIQUE — one posting per application.

There is **no** `(receipt_held_credit, invoice)` uniqueness: a customer may legitimately apply a record's credit to the same invoice twice in separate events, and the balance checks — not an index — bound it. **(Gate-2 PROPOSED)**.

### Immutability and RLS for both tables (NFR, ADR 0014 §A / §G)

- **RLS:** one migration adds both tables to an `ENABLE`/`FORCE ROW LEVEL SECURITY` + `_tenant_isolation` policy block, copying `2026_03_08_000003_enable_row_level_security_on_customer_receipts.php` verbatim — each its **own** policy keyed on `tenant_id`, because RLS is not transitive (`2026_03_08_000003:14-18`).
- **`credit_applications` immutability:** an **unconditional full freeze** trigger, refusing every UPDATE and DELETE, byte-for-byte the shape of `asids_receipt_allocations_immutable()` (`2026_03_08_000004:86-100`). An application is a historical fact the moment it exists; reversing one is a *new* posting, never an edit (and is out of scope this wave — §N).
- **`receipt_held_credits` immutability:** a **conditional** trigger in the ADR 0015 shape (`2026_03_09_000001:79-132`): a frozen-column `IF` block (id, tenant/company/customer, `customer_receipt_id`, `currency_code`, `original_amount`, `created_by_id`, `created_at`) that fires on every UPDATE, leaving only `applied_amount`, `remaining_amount`, `status`, `updated_at` writable — the exact analogue of the invoice trigger leaving `amount_paid`/`amount_due`/`status` writable (ADR 0009 §I). DELETE is refused outright. No finality guard is needed: unlike a cancelled receipt, a `cancelled` held-credit record is never touched again but also never needs a terminal-state message — its `remaining_amount = 0` and the FIFO filter simply skips it.

### Models

- `ReceiptHeldCredit` (`BelongsToTenant`, `HasUuids`; **not** `Auditable` — its state is fully derivable from its already-audited parents and events, §J), casts `original_amount`/`applied_amount`/`remaining_amount` `decimal:4`, relations `receipt()`, `customer()`, `applications()`.
- `CreditApplication` (`BelongsToTenant`, `HasUuids`, **`Auditable`**; `MORPH_ALIAS = 'credit_application'`), casts `amount` `decimal:4`, `applied_at` `immutable_datetime`, relations `heldCredit()`, `invoice()`, `journalEntry()`, `customer()`. `auditOnly() = ['amount', 'sales_invoice_id', 'receipt_held_credit_id', 'applied_by_id']`, `auditTags() = ['sales', 'credit-application']`.

---

## C — Receipt posting: `ReceiptPostingMap` becomes variable-line

**(Gate-1 APPROVED)** the three-line shape. **(Gate-2 PROPOSED)** the map mechanics.

`ReceiptPostingMap::for()` (`ReceiptPostingMap.php:55-81`) today returns exactly `[Dr Bank(amount), Cr Receivable(amount)]`. It changes to:

```
Dr  Bank / Cash                 amount
    Cr  Trade Receivables               Σ allocations         (the allocated portion)
    Cr  Customer Advances               amount − Σ allocations (only when > 0)
```

- `Σ allocations` is summed from the receipt's `receipt_allocations.amount` rows with `Money::plus` — **stored values summed, never recomputed** (`ReceiptPostingMap` already reads `allocations`, `:57`; the sum discipline is `InvoicePostingMap.php:39-47`).
- `remainder = Money::of(amount) − Σ allocations`, `>= 0` by construction because the service refuses `Σ > amount` (§C-service below). The Customer Advances line is **emitted only when `remainder->isPositive()`** — so a fully-allocated receipt yields the identical two-line entry it does today (**AC-CR-6.1 regression safety**), and `JournalService::assertPostable()`'s `>= 2 lines` and balance checks (`JournalService.php:176-208`) hold in both shapes.
- The AR account is resolved exactly as now (single distinct account across allocated invoices, else `receivableAccountsDiffer()`, `ReceiptPostingMap.php:119-137`) — **unchanged** (AC-CR-2.2). Customer Advances is a *separate* resolution (§A) and must never be conflated with the receivable resolution.
- Balances by construction: `amount = Σ allocations + remainder`, all `Money` integer math at `Money::SCALE` (`Money.php:110-122`).

Corresponding **service change** in `ReceiptService::record()` (`:81-241`), **(Gate-2 PROPOSED)**:

- Keep the empty-allocation refusal (`allocationAmounts()` → `withoutAllocations()`, `:412-416`) — Gate-1 #3.
- Replace `assertFullyAllocated()` (`:439-453`) with `assertNotOverAllocated()`: refuse only when `Σ allocations > amount`; accept `Σ ≤ amount`. Rename `ReceiptCannotBeRecorded::overOrUnderAllocated()` → `overAllocated()` (AC-CR-4.2 — a caller must never be told "over or under" when only "over" is possible; `ReceiptCannotBeRecorded.php:104-123`).
- After the receipt and allocations are saved and the JV posted (`:216-220`), when `remainder > 0`, insert one `receipt_held_credits` row (`original_amount = remaining_amount = remainder`, `applied_amount = 0`, `status = 'active'`) in the **same transaction**. When `remainder = 0`, no held-credit row — identical to today.
- Everything else in `record()` (customer/bank resolution, lock-and-re-read per invoice, per-invoice cap, numbering, invoice `amount_paid`/status updates) is **unchanged** (AC-CR-6.1, AC-CR-6.2).

---

## D — Apply-credit posting: a reclassification JV, sourced to the application

**(Gate-1 APPROVED)** Dr Customer Advances / Cr Trade Receivables, no cash. **(Gate-2 PROPOSED)** everything else.

For each held-credit record a single apply event consumes, one posting:

```
Dr  Customer Advances        applied
    Cr  Trade Receivables            applied
```

- `documentType: DocumentType::JournalVoucher` — **no new `DocumentType`** and **no new gapless counter** (YAGNI; the JV number is the ledger reference, the application uuid is the record id). Gate-1 §OQ-7 called for "a reclassification entry drawing a JV." **(Gate-2 PROPOSED)**.
- `source: SourceDocument::for($application)` — the `credit_application` row, **not** the receipt and **not** the invoice, because of the source-uniqueness index (Problem #1). This requires registering `CreditApplication::MORPH_ALIAS` in `SalesServiceProvider::registerMorphAliases()` (`SalesServiceProvider.php:111-119`) — `SourceDocument::for()` refuses an unmapped model.
- The receivable account is resolved by reusing `InvoicePostingMap::receivableAccountFor($targetInvoice)` (`InvoicePostingMap.php:97-121`), so the credit lands on the same control account the invoice debited — subledger and control keep agreeing (the ADR 0014 §C reasoning, now on the apply side).
- Customer Advances is resolved by key (§A).
- **Forward invoice restore**, the exact mirror of `record()` step 11 (`ReceiptService.php:226-237`): `amount_paid += applied`, `amount_due = total − amount_paid`, status → `Paid` when `amount_due` reaches zero else `PartiallyPaid`, all three written in one save (the `amount_due = total − amount_paid` invariant).
- **Held-credit decrement**, delta: `applied_amount += applied`, `remaining_amount −= applied`, written together (the balance-tie CHECK); `status` stays `active` (a record may be fully consumed yet remain `active` with `remaining_amount = 0` — FIFO skips it via the `remaining_amount > 0` filter, so no status transition is needed on exhaustion).

**(Gate-2 PROPOSED)** a small pure `CreditApplicationPostingMap` (mirroring `ReceiptPostingMap`'s "reads, resolves, returns `JournalLineData`, writes nothing" contract, `ReceiptPostingMap.php:14-21`) so the two-line entry is testable without the ledger. Alternative: a private method on `ReceiptPostingMap`; a separate pure map is preferred for the same single-responsibility reason `InvoicePostingMap` and `ReceiptPostingMap` are separate.

---

## E — FIFO semantics: defined exactly

**(Gate-1 APPROVED)** "consumed specifically/FIFO." **(Gate-2 PROPOSED)** every specific below.

- **The set:** the **same customer's** `receipt_held_credits` where `status = 'active' AND remaining_amount > 0` (the customer resolved from the *target invoice*, so credit never crosses a customer or company boundary — §K).
- **The order:** by the **source receipt's `receipt_date` ascending, then the source receipt's `number` ascending** (gapless, unique per company, so deterministic). The codebase has **no** existing business-FIFO ordering to inherit — `record()`/`cancel()` sort by uuid **only for lock ordering / deadlock avoidance** (`ReceiptService.php:124-125`, `:347-348`), not for business precedence; FIFO here is a new, explicitly-defined business order. `receipt_date` then `number` is chosen because `number` embeds the period and is monotonic with issuance within a company.
- **One or multiple receipts per operation?** **Multiple.** One apply-credit call targets one invoice for a requested amount, and walks the FIFO-ordered set consuming each record until the amount is satisfied or the set is exhausted. Each consumed record yields its own `credit_application` + JV (forced by Problem #1). If the set is exhausted before the amount is satisfied → refuse `insufficientCredit`, nothing written.
- **Explicit source override?** **Yes.** If the caller names a `sourceReceiptId`, only that receipt's held-credit record is considered (specific mode); FIFO is the **default only when no source is named**. A named source with insufficient `remaining_amount` for the requested amount → `insufficientCredit` (it does not silently spill into other records).
- **Cap:** the total applied in one call is capped at the target invoice's current locked `amount_due` (re-read under the lock); exceeding it → over-allocation refusal (§H reuses the `exceedsAmountDue` shape).

---

## F — Permission and policy

**(Gate-2 PROPOSED)** — Gate-1 deferred the name to Gate 2 with the recommendation below.

- **`sales.receipts.apply-credit`**, a **new** accountant-only capability, `sensitive: true`, `sortOrder: 120`, added to `PermissionCatalogue::sales()` immediately after `sales.receipts.cancel` (`PermissionCatalogue.php:279`). Following the `manage`(100)/`cancel`(110) split precedent (ADR 0015 §D) rather than broadening `manage`, because applying credit moves money and posts to the ledger as a distinct action. The hyphen in the action segment matches the existing `sales.tax-codes.*` precedent and is permitted by `permissions_name_matches_parts_check` (composition only, no character-class constraint; `2026_01_04_000001:130`), fitting `action varchar(48)` (`:37`).
- **`RoleTemplate`** — grant `sales.receipts.apply-credit` to the **accountant** template, after `sales.receipts.cancel` (`RoleTemplate.php:122`), matching all sales money-movers being accountant-only.
- **`CustomerReceiptPolicy::applyCredit(User, CustomerReceipt): bool`** — `$user->can('sales.receipts.apply-credit') && $user->canAccessCompany($receipt->company_id)`, both required, matching `cancel()` (`CustomerReceiptPolicy.php:61-65`). Advisory only; `ReceiptService::applyCredit()` is the enforcement boundary. No provider wiring changes (the policy and morph alias for `CustomerReceipt` already exist; only `CreditApplication::MORPH_ALIAS` is added, §D).
- No migration — permissions are code, synchronised by `PermissionSynchroniser`; an existing workspace acquires it via `tenantGrantableNames()` (ADR 0003).

---

## G — Cancellation interaction: every case modelled

**(Gate-1 APPROVED)** the direction. **(Gate-2 PROPOSED)** the guard name and the exact zeroing.

**Case 1 — receipt cancelled, held credit untouched (nothing applied).** `PostingService::reverse()` mirrors the **whole** original JV (`PostingService.php:138-147`), which for a remainder receipt includes the Customer Advances credit — so the held credit is unwound at the GL automatically, no special posting. In the same transaction, `cancel()` (extended, `ReceiptService.php:275-401`) loads and **locks** the receipt's `receipt_held_credits` record, asserts `applied_amount === 0`, then delta-zeroes it: `remaining_amount = remaining_amount − remaining_amount = 0`, `status = 'cancelled'`, written together (the balance-tie CHECK; the `cancelled ⇒ remaining = 0` CHECK). This is the credit-side analogue of the invoice delta-restore (ADR 0015 §C), using the record's own current remaining, never a snapshot (AC-CR-5.1).

**Case 2 — receipt cancelled, credit already applied (in part or full).** **Refuse**, with a new `ReceiptCannotBeCancelled::heldCreditAlreadyApplied($receipt, $applied)` **(Gate-2 PROPOSED)** — the direct `wouldReverseBelowZero()` analogue for the credit balance (`ReceiptCannotBeCancelled.php:171-184`), triggered by `applied_amount > 0` on the locked held-credit record. Refusal is correct, not merely convenient: `reverse()` can only mirror the entry **whole**, so reversing the full original Customer Advances credit while part of it has already been reclassified out (by an apply JV) would over-reverse the subledger. The remedy is to reverse the credit application(s) first — which is out of scope this wave (§N). This mirrors the invoice rule that a partially-paid invoice cannot be cancelled (`SalesInvoiceService.php:370-371`).

**Case 3 — invoice cancelled after credit was applied to it (incl. multiple applications).** **Already handled, no new code.** Applying credit raised the invoice's `amount_paid` (§D); `SalesInvoiceService::cancel()` refuses any invoice with `amount_paid > 0` (`SalesInvoiceService.php:370-371`, `InvoiceCannotBeCancelled::partiallyPaid()`). This holds regardless of how many `credit_applications` (or receipts) contributed — any positive `amount_paid` refuses. The applied credit therefore stays applied and auditable; undoing it requires reversing the application(s), out of scope this wave (§N). **(Gate-1 APPROVED behaviour, via existing guard.)**

**Immutability threading.** `credit_applications` is never written or deleted by any cancellation (full-freeze, §B) — the same "allocations stay permanent history" discipline (ADR 0015 Gate-1 #7). `receipt_held_credits` is touched only in Case 1, only on the columns its conditional trigger permits.

---

## H — Concurrency and locking

**(Gate-2 PROPOSED)** — following ADR 0014 §F / 0015 §E.

**Global lock order (deadlock-free):** every operation acquires row locks in the order **`customer_receipts` → `receipt_held_credits` → `sales_invoices`**, and within a table by **ascending id**. `credit_applications` rows are inserted, never locked. This is a total order, so no cycle can form:

- `record()` locks invoices only (respects the order trivially).
- `applyCredit()` locks the candidate `receipt_held_credits` (ascending id) then the target invoice — held-credits before invoices, per the order. FIFO is the *consumption* order (§E), computed in memory over the locked set; **lock acquisition is by id** so two applies cannot deadlock.
- `cancel()` locks the receipt, then its held-credit record, then its invoices — receipts → held-credits → invoices, per the order.

**Two concurrent applies of the same receipt's credit to different invoices.** Both contend for the same `receipt_held_credits` row lock. One wins, decrements `remaining_amount` under the lock, commits; the other queues, re-reads the now-lower `remaining_amount`, and either fits within it or is refused `insufficientCredit`. Available credit **cannot go negative**: the lock serialises the decrement, and `receipt_held_credits_remaining_non_negative_check` (+ the tie) is the database backstop that holds even if the service is bypassed — the exact two-layer guard ADR 0014 §F uses for oversell.

**Apply racing a cancel of the same receipt.** They contend on the `receipt_held_credits` row (apply locks it directly; cancel locks it after the receipt row). If apply commits first, `applied_amount > 0` and cancel is refused by `heldCreditAlreadyApplied()` (§G Case 2). If cancel commits first, the record reads `status = 'cancelled'`, `remaining_amount = 0`, and apply is refused `insufficientCredit` (FIFO/specific both filter it out). Neither races to a constraint. Because apply never locks the receipt row, and cancel locks it *before* the held-credit row, there is no receipt-then-held-credit cycle.

Each apply's own JV is additionally protected against double-posting by the source-uniqueness index over `credit_applications` (Problem #1) and the `journal_entry_id` UNIQUE column.

---

## I — API / HTTP boundary (DESIGN ONLY; recommended for a later slice)

**(Gate-2 PROPOSED — recommend defer):** prior receipt waves (ADR 0014, 0015) shipped **no HTTP** (confirmed: no receipt controller exists under `src/Core/Sales/Presentation/Http/Controllers/`), and the Gate-1 requirements repeat "No HTTP surface. Domain + service + tests only." **Recommendation: the HTTP surface for record/cancel/apply-credit is a separate later slice, not this wave.** The design is captured here so the domain shape is HTTP-ready and the later slice inherits it. **(UNRESOLVED — needs explicit human approval)** whether HTTP lands in this wave.

When it lands, following the invoice conventions exactly (`routes/api.php:437-444`, `SalesInvoiceController.php:237-248`, `docs/api/openapi.yaml:3302`):

- **Route:** `POST /companies/{company}/customer-receipts/{receipt}/apply-credit` inside a `Route::prefix('{company}/customer-receipts')->name('customer-receipts.')->middleware('company')` group — `customer-receipts` matching the table name and the flat-namespace reasoning `routes/api.php:422-428` gives for `sales-invoices`. A sub-action verb (`/apply-credit`), like `/issue` and `/cancel`, never a status field on a PUT.
  - *Specific-source variant:* the `{receipt}` binding **is** the explicit source, so this route is naturally the specific-source apply. A **FIFO** (customer-wide) apply better fits `POST /companies/{company}/customers/{customer}/apply-credit` **or** the same receipt route with the receipt omitted — **(UNRESOLVED)** which addressing the FIFO case uses.
- **Request payload:** `{ "sales_invoice_id": uuid, "amount": decimal-string }` (+ optional `source_receipt_id` when not addressed by path). Validated by a `FormRequest` mirroring the invoice cancel request's shape.
- **Response:** `ApiResponse::item()` of a new `CreditApplicationResource` (one per created application) or a collection when a FIFO call produces several — mirroring `SalesInvoiceResource` usage (`SalesInvoiceController.php:246-247`).
- **Authorization:** `$this->authorize('applyCredit', $receipt)` (§F).
- **Error categories → HTTP:** the typed exceptions (§ error contract) map through the platform's existing `BusinessRuleViolation` → 422 handler, each carrying its problem code.
- **Idempotency:** apply-credit is **not** idempotent at the service level (each call consumes). A retried HTTP request would create a second application. **(Gate-2 PROPOSED)** the later HTTP slice adds an idempotency-key mechanism (client-supplied key persisted, replayed on retry); it is out of scope for the backend wave. **(UNRESOLVED)** if HTTP is pulled into this wave, idempotency must be resolved with it.

No OpenAPI change is made by this wave.

---

## J — Domain / service boundary

**(Gate-2 PROPOSED):** apply-credit is a new method **on the existing `ReceiptService`**, not a new service. The established pattern is one service per aggregate lifecycle even when permissions differ: invoice `issue` and `cancel` are separate permissions on one `SalesInvoiceService`; receipt `record` and `cancel` are separate permissions on one `ReceiptService`. Held credit is created in `record()`, consumed in `applyCredit()`, unwound in `cancel()` — one cohesive lifecycle, one service, one place for the lock discipline. `ReceiptService` grows by roughly one method (~100 lines over its current ~590); still within reason.

- **Signature:** `applyCredit(Company $company, ApplyCreditData $data, ?User $actor = null): list<CreditApplication>` where `ApplyCreditData = { string $salesInvoiceId; numeric-string $amount; ?string $sourceReceiptId = null }`. Returns the applications created (one per consumed record).
- **Posting stays inside the existing infrastructure:** `PostingService::postNew()` for the JV (§D), never a new posting entry point (AC-CR-2.1 discipline).
- **Atomicity:** the whole operation — every application row, every JV, every invoice-balance and held-credit decrement — is one `DB::transaction()`. A partial apply is impossible (AC-CR-3.6 "nothing half-done").
- **Alternative considered:** a dedicated `CreditApplicationService`. Rejected unless `ReceiptService` becomes unwieldy, per "prefer the existing architecture; add an abstraction only if the existing one cannot safely represent it." It can.
- **Singletons:** `ReceiptService` is already bound (`SalesServiceProvider.php:71`); a `CreditApplicationPostingMap` (if adopted, §D) is bound as a singleton beside `ReceiptPostingMap` (`:70`).

---

## K — Accounting invariants (the design guarantees, each enforceable)

1. **Held credit is never negative** — `receipt_held_credits_remaining_non_negative_check` + row lock (§H).
2. **Applied ≤ available** — decrement under lock; `remaining_amount >= 0` backstop.
3. **Applied ≤ invoice `amount_due`** — re-read under the invoice lock (§E cap); the existing `sales_invoices_amount_paid_not_exceeding_total_check` is the DB backstop (ADR 0014 §A).
4. **A cancelled receipt yields no usable credit** — cancel refuses if applied, else zeroes remaining + `status = 'cancelled'`; FIFO filters both out (§G, §B `cancelled_zero_check`).
5. **Credit never crosses a customer or company** — the customer is resolved from the target invoice; held-credit and application rows carry and check `customer_id`/`company_id` (§E, error contract).
6. **Every journal is balanced** — record posting (`amount = Σ alloc + remainder`) and apply posting (`applied = applied`) balance by construction; `JournalService::assertPostable()` enforces it (`:204-208`).
7. **Every financial mutation has an auditable journal entry** — record → one JV; each apply → one JV; cancel → one reversing JV. No balance moves without a posting.
8. **No double-posting** — the source-uniqueness index (per source document) plus `journal_entry_id` UNIQUE on both `customer_receipts` and `credit_applications`.
9. **Concurrent applies cannot over-consume** — §H.
10. **Subledger agrees with the control account** — apply credits the same receivable account the invoice debited (`InvoicePostingMap::receivableAccountFor()`).

---

## L — Alternatives considered

- **Contra-balance within Trade Receivables (Gate-1 OQ-1 Option B)** — rejected at Gate 1 in favour of a distinct Customer Advances liability; a negative AR subledger balance misstates the receivable on the balance sheet.
- **Held credit as columns on `customer_receipts`** (AC-CR-5.4 anticipated it) — rejected: it would force the receipt's immutability trigger to permit `applied`/`remaining` to move on *every* apply, widening the receipt's mutable set well beyond the single `posted → cancelled` transition and muddying the "a posted receipt is frozen" invariant. A separate table keeps the receipt fully frozen and gives the balance its own lock target, RLS policy, and immutability trigger.
- **A single held-credit balance table, no applications table** — impossible: the source-uniqueness index permits one non-reversing posting per source document, so a second apply against one balance row cannot post (Problem #1).
- **Pooled per-customer running balance (Gate-1 OQ-4 pooled)** — rejected at Gate 1 in favour of per-receipt; pooled loses the receipt-level traceability cancellation needs.
- **A dedicated `DocumentType::CreditApplication` with its own gapless series** — rejected (YAGNI): the reclassification is a ledger-internal event; a JV number suffices, as it does for a receipt cancellation's reversal (ADR 0015 §F).
- **A dedicated `CreditApplicationService`** — deferred (§J); the existing service suffices.

## M — Consequences

- The receipt posting is no longer a fixed two-line contract; ADR 0014 §C's "exactly two lines" statement is superseded for the remainder case (and only then). The two-line output is preserved exactly when there is no remainder.
- One ADR 0014 test flips from refusal to acceptance (the under-allocation case, §P) — a visible, reviewed change, not an incidental diff (AC-CR-4.1).
- A fourth system account exists; `ChartTemplate::VERSION` advances; every existing company gains `2180 Customer Advances` via backfill.
- Two new tables, two new immutability triggers, one new RLS migration, one new permission, one new morph alias.
- `ReceiptService` gains `applyCredit()` and its `cancel()` gains a held-credit branch.

## N — Known limitations (each **UNRESOLVED — needs explicit human approval** on whether to pull forward)

- **Reversing a credit application is out of scope.** Once applied, credit cannot be un-applied by a service path this wave: the source receipt cannot be cancelled (§G Case 2) and the target invoice cannot be cancelled (§G Case 3). The interim remedy is a manual journal adjustment — the same interim the project accepted for deferred receipt cancellation before ADR 0015 (Requirements §3.3 AC-CR-3.5). **Recommendation:** ship without it; add a `reverseApplication()` sub-slice later if demand appears.
- **No cash refund of held credit** — Gate-1 out of scope (#6).
- **No customer statement / credit aging / expiry** — Requirements §2, out of scope.
- **No HTTP surface this wave** (§I).
- **FIFO addressing over HTTP** and **idempotency** are unresolved pending the HTTP decision (§I).

---

## O — Implementation stages (design only; one cohesive backend lane, staged for review)

One lane — the service depends on the schema/models/maps, the permission depends on the service — staged like ADR 0014 §H / 0015 §G. Each stage is reviewed against the code before the next begins; several decisions are irreversible-in-practice once credit exists (a granted permission, a widened posting contract, new immutable tables). **QA writes tests first (RED) per stage.**

| Stage | Objective | Expected files | Tests to write first | Depends on | Acceptance gate | Commit boundary |
| --- | --- | --- | --- | --- | --- | --- |
| **1 — Account** | `2180 Customer Advances` exists for new and existing companies. | `Account::CUSTOMER_ADVANCES`; `ChartTemplate` (`accounts()`, `requiredSystemAccounts()`, `VERSION`); new Accounting backfill migration (RLS-bypass, create-not-stamp, both assertions). | New company provisions it; empty-chart company gets it via `ensureSystemAccounts()`; backfill creates exactly one for each legacy company and is idempotent; `assertBypassEffective`/`assertNothingLeftBehind` fire correctly; type=Liability, normal_balance=Credit, is_system=true. | — | The account resolves by key for every company. | One commit. |
| **2 — Held-credit schema + models** | The two tables, their CHECKs, RLS, immutability triggers, and models. | Migrations: `receipt_held_credits`, `credit_applications`, RLS (copy `2026_03_08_000003`), immutability (conditional for held-credits, full-freeze for applications); `ReceiptHeldCredit`, `CreditApplication` models; `CreditApplication::MORPH_ALIAS` in provider. | Every CHECK refuses its violation at the DB (balance tie, non-negative, applied≤original, cancelled⇒remaining=0, amount>0); RLS isolates a second tenant on each table directly; held-credit trigger freezes the frozen columns and permits the mutable ones; applications trigger refuses all UPDATE/DELETE. | 1 | Constraints provable before any code writes a row. | One commit. |
| **3 — Record path + posting** | A remainder is accepted, posted three-line, and held. | `ReceiptPostingMap` variable-line; `ReceiptService::record()` (`assertNotOverAllocated`, held-credit insert); rename `overOrUnderAllocated()`→`overAllocated()`; `ReceiptCannotBePosted::withoutCustomerAdvancesAccount()`. | Exact payment → no held credit, two-line entry unchanged (AC-CR-6.1); partial+remainder → three-line balanced entry + held-credit row (AC-CR-1.1/1.2/2.1); over-allocation still refused (AC-CR-1.4); `Σ` at four-dp precision; the flipped ADR 0014 under-allocation test (§P). | 2 | The ledger balances for a remainder receipt; held credit created per-receipt. | One commit. |
| **4 — Apply-credit domain op** | Credit applies to a later invoice, FIFO/specific, atomically. | `ApplyCreditData`; `CreditApplicationPostingMap` (or a `ReceiptPostingMap` method); `ReceiptService::applyCredit()`; apply-credit error factories. | Full/partial apply; multi-receipt→one-invoice (FIFO, multiple JVs); one-receipt→multi-invoice; specific-source override; insufficient credit; over-`amount_due`; customer/company mismatch; cancelled-receipt credit unusable; settled invoice refused; concurrent applies cannot over-consume; balances and audit correct. | 3 | Apply is atomic, balanced, and cannot over-consume. | One commit. |
| **5 — Cancellation interaction** | Cancel unwinds untouched credit; refuses applied credit. | `ReceiptService::cancel()` held-credit branch; `ReceiptCannotBeCancelled::heldCreditAlreadyApplied()`. | Cancel with untouched credit → mirror reverses Customer Advances, held-credit zeroed+cancelled; cancel with applied credit → refused; invoice-with-applied-credit cancel → refused by existing `partiallyPaid()`; delta discipline proven by a multi-event table. | 4 | No cancellation drives a credit balance negative or over-reverses. | One commit. |
| **6 — Permission + policy** | The operation is gated. | `PermissionCatalogue` (`apply-credit`, 120); `RoleTemplate` (accountant); `CustomerReceiptPolicy::applyCredit()`. | Permission required and distinct from `manage`/`cancel`; accountant granted; existing workspace acquires it; tenant-owner gate-yes / service-still-refuses proves the policy is advisory. | 4 | Authorization surface arrives with the operation it guards. | One commit. |

**Not in these stages (Gate-2/later):** any HTTP/controller/route/OpenAPI/resource work (§I); a `reverseApplication()` operation (§N).

---

## P — Test strategy (QA asserts test-first; do not weaken existing tests)

**Creation / holding** (extend `tests/Feature/Sales/RecordReceiptTest.php`): exact payment → no held-credit row, two-line entry byte-identical to today (AC-CR-6.1); partial with remainder → three-line balanced entry (Dr Bank / Cr AR Σalloc / Cr Customer Advances remainder) + one `receipt_held_credits` row with `original = remaining = remainder`, `applied = 0` (AC-CR-1.1/1.2); multi-invoice with remainder → each invoice moved independently, AR credited Σalloc, one held-credit row; zero remainder path unchanged; four-dp precision on the remainder; **per-receipt ownership** — two remainder receipts for one customer produce two distinct held-credit rows. **Flip** the existing `it('refuses a receipt under-allocated against its own amount')` (`RecordReceiptTest.php:362-380`) to an acceptance test; **keep** `it('refuses a receipt over-allocated beyond its own amount')` (`:382-391`) and the multi-invoice over-sum refusal (`:393-406`) exactly (AC-CR-4.1 — the split must be visible in the diff).

**Apply-credit** (new `tests/Feature/Sales/ApplyCreditTest.php`): full apply clears a held record and moves the invoice to Paid; partial apply leaves `remaining > 0` and the invoice `PartiallyPaid`; multi-receipt → one invoice (FIFO consumes oldest first, N applications, N JVs); one receipt → multi invoice; FIFO order proven by `receipt_date` then `number`; specific-source override consumes only the named record; insufficient credit refused with nothing written; over-`amount_due` refused; customer mismatch and company mismatch refused; a cancelled receipt's credit is unusable; a settled (Paid/Cancelled) invoice refused; two concurrent applies of one record to different invoices → exactly the available amount consumed, never more (plus a DB-bypass test driving `remaining_amount` negative, refused by the CHECK); duplicate/replayed application creates a distinct row (no service idempotency — documents the HTTP gap).

**Accounting**: record posting shape and balance; the Customer Advances account balance equals Σ live remainders + reversed/applied movements; the AR control agrees with the subledger after apply; the apply JV is Dr Customer Advances = Cr AR = applied and balances; cancellation reversal nets to zero and reverses Customer Advances; every posting traces to its source document (receipt for record, application for apply); audit entries exist for each apply (`CreditApplication` auditable) and for cancellation (receipt status).

**Authorization** (extend `tests/Feature/Sales/ReceiptAuthorizationTest.php`): holder of `sales.receipts.apply-credit` may apply; a holder of only `manage`/`cancel` may not; company owner may; sibling-company member may not; foreign tenant cannot see or drive it (RLS); unauthenticated refused.

**Regression**: the whole existing `RecordReceiptTest`/`CancelReceiptTest`/`ReceiptPostingMapTest` suites stay green except the one deliberate flip (AC-CR-6.1/6.2/6.3).

## Q — Risks and mitigations

1. **Half-building a customer-wallet.** *Mitigation:* the Out-of-scope list (§N) is explicit; per-receipt (not pooled) tracking and refusal-over-cascade cancellation keep the surface minimal; scope creep is a Gate-escalation.
2. **Cancellation over-reversal (highest correctness risk, ADR 0015's own top risk).** *Mitigation:* `reverse()` mirrors whole entries, so a partially-applied receipt is **refused** (§G Case 2), never partially unwound; delta-zero only when untouched; proven by a multi-event table (ADR 0015 §C weight).
3. **Over-consumption under a race.** *Mitigation:* single-order row locking + the `remaining_amount >= 0` DB backstop (§H) — the two-layer guard ADR 0014 §F proved.
4. **Backfill silently doing nothing** (the `2026_03_05_000003` trap). *Mitigation:* RLS-bypass + `assertBypassEffective`/`assertNothingLeftBehind`, and it is a **create** not a stamp (§A).
5. **Flipping a shipped, tested invariant.** *Mitigation:* the under/over test split is visible and reviewed (§P, AC-CR-4.1).
6. **Stacking dependency** on `feature/phase4-cancellation`. *Mitigation:* coordinate with the DM if cancellation changes before this merges.

---

## Gate 2 — Architecture Approval Required

The build does not start until the human approves the following. Items are labelled by decision status.

1. **ADR 0016 summary.** A variable-line receipt posting (Dr Bank = Cr AR + Cr Customer Advances) creates per-receipt held credit in a new `receipt_held_credits` table; apply-credit reclassifies Dr Customer Advances / Cr Trade Receivables per consumed record via a new `credit_applications` table (each row its own JV source, forced by the source-uniqueness index), FIFO by default with an explicit-source override; cancellation reverses untouched credit and refuses applied credit; a new accountant-only permission gates apply. No HTTP this wave (recommended).
2. **Customer Advances account (Gate-2 PROPOSED):** code `2180`, name `Customer Advances`, type Liability, normal balance Credit (derived), a required system account in both `ChartTemplate::accounts()` and `requiredSystemAccounts()`, `VERSION` → `2026.08-lk-sme-3`, backfilled by **creating** it for every existing company (RLS-bypass migration, create-not-stamp).
3. **System key (Gate-2 PROPOSED):** `Account::CUSTOMER_ADVANCES = 'customer_advances'`.
4. **Held-credit model (Gate-2 PROPOSED):** two tables — `receipt_held_credits` (per-receipt balance: original/applied/remaining/status, unique on `customer_receipt_id`) and `credit_applications` (per apply event: amount, held-credit FK, invoice FK, unique `journal_entry_id`, morph-mapped). Both tenant-scoped, FORCED RLS, immutable by trigger (conditional for held-credits, full-freeze for applications).
5. **Apply-credit operation (Gate-2 PROPOSED):** `ReceiptService::applyCredit(Company, ApplyCreditData{salesInvoiceId, amount, sourceReceiptId?}, ?User): list<CreditApplication>`, one transaction.
6. **FIFO definition (Gate-2 PROPOSED):** among the *same customer's* `active` records with `remaining > 0`, ordered by source `receipt_date` then `number`; one call may consume **multiple** records (one JV each); an explicit `sourceReceiptId` overrides FIFO and consumes only that record.
7. **Permission (Gate-2 PROPOSED):** `sales.receipts.apply-credit`, accountant-only, sensitive, sortOrder 120; `CustomerReceiptPolicy::applyCredit()`.
8. **Posting entries (Gate-1 APPROVED shape; Gate-2 mechanics):** record → Dr Bank / Cr AR (Σalloc) / Cr Customer Advances (remainder, when > 0); apply → Dr Customer Advances / Cr AR (applied), JV, sourced to the `credit_application`.
9. **Cancellation behaviour (Gate-1 APPROVED direction; Gate-2 guard):** untouched credit → mirror reverses Customer Advances, held-credit delta-zeroed + `cancelled`; applied credit → refuse via new `heldCreditAlreadyApplied()`; invoice with applied credit → refused by existing `partiallyPaid()`.
10. **Locking / concurrency (Gate-2 PROPOSED):** global order receipts → held-credits → invoices, ascending id within a table; two concurrent applies and apply-vs-cancel proven safe; `remaining_amount >= 0` DB backstop.
11. **API proposal (Gate-2 PROPOSED — recommend defer; UNRESOLVED whether in-wave):** `POST /companies/{company}/customer-receipts/{receipt}/apply-credit`; FIFO addressing and idempotency unresolved pending the defer decision.
12. **Domain/service proposal (Gate-2 PROPOSED):** `applyCredit()` on the existing `ReceiptService` (not a new service); posting stays in `PostingService`.
13. **Error contract (Gate-2 PROPOSED):** reuse `ReceiptCannotBeAllocated::{crossCustomer, crossCompany, toNonCollectableInvoice, exceedsAmountDue, zeroOrNegativeLine, unknownInvoice}` and `ReceiptCannotBeRecorded::intoClosedPeriod`/renamed `overAllocated()`; new codes only where needed — `insufficientCredit` (requested > available, FIFO or specific), `ReceiptCannotBePosted::withoutCustomerAdvancesAccount()`, `ReceiptCannotBeCancelled::heldCreditAlreadyApplied()`. (Duplicate-application is *not* an error — a distinct legitimate event.)
14. **Test strategy (Gate-2 PROPOSED):** enumerated in §P; the one deliberate flip of the ADR 0014 under-allocation test; no existing test weakened.
15. **Implementation stages (Gate-2 PROPOSED):** §O — account → held-credit schema/models → record+posting → apply-credit → cancellation → permission.
16. **Risks:** §Q — cancellation over-reversal and race over-consumption are the top two, both mitigated by refuse-not-cascade and the two-layer lock+CHECK guard.
17. **Decisions requiring explicit human approval (UNRESOLVED):**
    - (a) Backfill mechanism: raw SQL (recommended) vs iterating `ensureSystemAccounts()`.
    - (b) Whether the HTTP surface is in this wave (recommended: **no**, later slice) — and if yes, FIFO route addressing and idempotency.
    - (c) Whether a `reverseApplication()` operation is pulled into this wave (recommended: **no**; interim remedy is a manual JV).
    - Plus confirmation of every Gate-2 PROPOSED value above (account code/name/key, table shapes, permission name, FIFO order, service placement).

**STOP — Stage 4 (build) does not begin until these are approved.**
