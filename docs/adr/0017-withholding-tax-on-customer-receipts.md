# ADR 0017 — Withholding tax on customer receipts: a per-allocation debit inside the receipt's own entry, and the settlement invariant

- **Status:** Proposed — awaiting **Gate 2 (Architecture approval)**. Gate 1 is approved and binding (`docs/PHASE-4-WHT-REQUIREMENTS.md` → "Gate 1 decisions — APPROVED 2026-08-31").
- **Date:** 2026-08-31
- **Milestone:** Phase 4, withholding-tax sub-slice (record customer receipts net of customer-withheld income tax). Branch `feature/phase4-withholding-tax`, stacked on `feature/phase4-credit-on-account` (ADR 0016).
- **ADR number:** 0017. 0016 is unallocated credit on account; 0015 is receipt cancellation; 0014 is receipts; 0013 is the Phase 3 front-end ADR on `feature/phase3-frontend` (`docs/adr/0013-phase3-frontend-architecture.md`, present only on that branch). 0017 is the next free number on this branch — verified `docs/adr/` holds `0001`–`0012`, `0014`, `0015`, `0016`, with `0013` reserved elsewhere and no `0017` anywhere.
- **Scope of this document:** DESIGN ONLY. No production code, migrations, tests, permission/route/OpenAPI changes, or dependencies are written by this wave's Stage 3. The only repository write is this ADR. Every non-Gate-1 decision below is labelled **(Gate-2 PROPOSED)** or **(UNRESOLVED — needs explicit human approval)**; nothing so labelled is settled until Gate 2.

---

## How to read the labels

Every load-bearing decision carries exactly one tag:

- **(Gate-1 APPROVED)** — settled by the human on 2026-08-31; binding; the build follows it verbatim.
- **(Gate-2 PROPOSED)** — the Architect's recommendation, needing the human's yes at Gate 2 before the build starts.
- **(UNRESOLVED — needs explicit human approval)** — a genuine fork the Architect will not decide unilaterally.

---

## Context

Four shipped, verified foundations this wave sits on and must not regress. The first three are the receipt family; the fourth is the **current** variable-line posting this wave extends — already committed on this branch (git log: `f3982e9 feat(payments): record path + variable-line posting at currency_precision (Stage 3/6, ADR 0016 §C + Gate-2 amendment)`).

- **ADR 0014** (record + allocate + post): a receipt posts one journal entry; its allocations are subledger detail that never post themselves (`receipt_allocations`, `2026_03_08_000002`). The receivable is resolved per allocated invoice, reusing `InvoicePostingMap::receivableAccountFor()`, and a receipt whose invoices disagree is refused (`ReceiptPostingMap::receivableAccountFor()`, `ReceiptPostingMap.php:141-159`).
- **ADR 0015** (cancellation): reversing a posted receipt by **delta, not snapshot**, through `PostingService::reverse()`, which **mirrors every line of the original entry with sides swapped and amounts copied, not recomputed** (ADR 0015 §C; the whole-entry mirror is the property WHT reversal depends on).
- **ADR 0016** (unallocated credit on account): the receipt posting became **variable-line** — `Dr Bank / Cr Trade Receivables (Σ allocations) / Cr Customer Advances (remainder, when > 0)`; over-allocation refused, under-allocation newly held as customer advances in `receipt_held_credits`; the **currency_precision amendment** (all money held and posted at the company's `currency_precision`, not the finer `Money::SCALE`, closing the phantom-remainder trap — `ReceiptService::assertAtCurrencyPrecision()`, `ReceiptService.php:700-705`, called at `:99` and `:682`).
- **The current record path and posting map, exactly as they stand:**
  - `ReceiptService::record()` (`ReceiptService.php:85-283`): amount parsed positive and at precision (`:91-99`); allocations parsed per-invoice, positive, at precision, aggregated (`allocationAmounts()`, `:664-690`); **over-allocation refused** (`assertNotOverAllocated()`, `:112`, `:717-731` — refuse `Σ allocations > amount`); per-invoice cap re-read under the lock (`:156-162`); each invoice's gross `amount_paid`/`amount_due` moved (`:236-247`); **remainder = `amount − Σ allocations`, rounded to precision** (`:264`), held as `receipt_held_credits` when positive (`:266-279`).
  - `ReceiptPostingMap::for()` (`ReceiptPostingMap.php:59-103`): `Dr Bank(amount)` (`:86`), `Cr Trade Receivables(Σ allocations)` (`:87`), `Cr Customer Advances(remainder)` only when `remainder > 0` (`:92-100`); balances by construction because `amount = Σ allocations + remainder`.
  - `JournalService::assertPostable()` (`JournalService.php:165-211`) refuses fewer than two lines (`:176-180`) and any imbalance (`:204-208`); `writeLines()` rounds every posted line to `currency_precision` (`:312-315`).

The `2180 Customer Advances` account and its create-not-stamp RLS-bypass backfill (`2026_03_10_000001_create_customer_advances_system_accounts.php`) are the exact template this wave mirrors for a new asset account.

## Problem

A Sri Lankan customer settling an invoice may withhold income tax and remit only the **net** cash, forwarding the withheld portion to the IRD on the supplier's behalf and (later) issuing a WHT certificate the supplier claims against its own income tax. From this company's side:

- The invoice was raised, and remains payable, for its **gross** amount. The customer's payment settles that gross receivable **in full**, even though only net cash arrives.
- The withheld portion is not a discount, a write-off, or unallocated credit. It is an **asset** — a claim against the company's own future income tax — and must land in its own GL account.

At minimum a WHT receipt posts, on the **debit** side:

```
Dr  Bank / Cash               <net cash>
Dr  WHT Receivable            <WHT withheld>
    Cr  Trade Receivables            <Σ allocations, gross>
```

This is the mirror image of what ADR 0016 did on the **credit** side (`Cr Customer Advances` for an overpayment remainder). The two must **compose**: one receipt may carry both a WHT debit and a Customer Advances credit in the same entry, and it must still balance. The whole design turns on one equation, stated precisely in §C:

```
net cash + Σ WHT  =  Σ allocations (gross)  +  remainder held as Customer Advances
```

Two facts, discovered by inspection, shape the design and are not preferences:

1. **WHT posts exactly once, inside the receipt's own single journal entry.** Unlike ADR 0016's held credit — which needed its own table because apply-credit is a *second* non-reversing posting against the same balance, and the source-uniqueness index permits only one such posting per source document (ADR 0016 Problem #1) — WHT has **no second posting and no consumption lifecycle** this wave. Claiming/offsetting the receivable against income tax at year-end is explicitly out of scope. So WHT needs **no side table and no subledger balance**: columns on the existing allocation row suffice (§B).
2. **The `receipt_allocations` immutability trigger already refuses every UPDATE and DELETE unconditionally** (`asids_receipt_allocations_immutable()`, `2026_03_08_000004:86-100`) — unlike the receipt *header* trigger, which freezes a hand-written **list of columns by name** (`:43-64`). So WHT columns placed on `receipt_allocations` are frozen **with no trigger change at all**, sidestepping the "forgot to add the new column to the freeze list" omission risk the requirements flag (§6.4). This is a decisive argument for the allocation-table location (§B).

## Approved Gate-1 constraints (all **Gate-1 APPROVED**, binding)

1. **A new system account "WHT Receivable"** (Advance Income Tax Recoverable): a Current-Asset leaf in `ChartTemplate` + a new `Account` system key, auto-provisioned for new companies and **backfilled for existing** ones (mirror `Input VAT Recoverable 1170` and the `2180 Customer Advances` RLS-bypass backfill).
2. **WHT amount is CALLER-SUPPLIED** from the customer's certificate / remittance advice. No rate table, no `tax_codes` reuse. Validated `≥ 0`, `≤ the allocation it applies to`, at `currency_precision`.
3. **WHT is PER-ALLOCATION** — each `receipt_allocation` may carry its own WHT + certificate reference. Each invoice's **gross AR settled = its allocation**; posting `Dr Bank(net) + Dr WHT-Receivable = Cr Trade Receivables(gross)` within the receipt's single entry.
4. **WHT never touches the Customer Advances remainder** — it applies only to allocated (invoiced) portions.
5. **Certificate reference = a simple optional field** this wave (not a structured register — a later compliance-pack concern).
6. **Permission = reuse `sales.receipts.manage`** (`PermissionCatalogue.php:275`) — recording WHT is an attribute of the same record action, not a distinct capability. **No catalogue, role, or policy change.**
7. **Supplier-side WHT and IRD filing remain OUT of scope.**

Deferred to Gate 2: the account code/name (OQ-1); the schema shape — columns vs side table (OQ-7); the exact posting-map extension and immutability handling; and **how "allocation amount" relates to gross-vs-net given per-allocation WHT** (the load-bearing item, §C).

---

## Decision (overview)

- **Account (§A):** add `Account::WHT_RECEIVABLE = 'wht_receivable'`; a `1180 WHT Receivable` **Current-Asset** leaf (right after `1170 Input VAT Recoverable`, the "recoverable from the authority" analogue); register in `ChartTemplate::accounts()` **and** `requiredSystemAccounts()`; bump `ChartTemplate::VERSION` → `2026.08-lk-sme-4`; a **create-not-stamp, raw-SQL, RLS-bypass** backfill migration for every existing company, mirroring the Customer Advances backfill.
- **Schema (§B):** two nullable/defaulted columns on the existing `receipt_allocations` table — `wht_amount numeric(19,4) NOT NULL DEFAULT 0` and `wht_certificate_reference varchar(120) NULL` — with two same-row CHECKs (`wht_amount >= 0`, `wht_amount <= amount`). **No side table. No RLS migration. No immutability-trigger change** (the existing full-freeze trigger already covers them).
- **The settlement invariant (§C):** `receipt.amount` **stays "net cash received"** (unchanged) and `allocation.amount` **stays "gross AR settled"** (unchanged, Gate-1 #3). A receipt's **settlement power** becomes `settlement = amount + Σ wht`; over-allocation refuses `Σ allocations > settlement`; `remainder = settlement − Σ allocations`. When all WHT is zero this is **byte-identical** to ADR 0016.
- **Record path (§D):** `ReceiptAllocationData` gains optional `whtAmount` + `whtCertificateReference`; `ReceiptService::record()` validates and persists per-allocation WHT and computes the settlement invariant; `ReceiptPostingMap::for()` emits a single `Dr WHT Receivable(Σ wht)` line (only when `Σ wht > 0`) and computes the remainder from settlement.
- **Cancellation & apply-credit (§E):** cancellation reverses the WHT debit **generically** through the existing whole-entry mirror — **no new cancel code** (like Customer Advances). Apply-credit is **entirely unaffected** — WHT is captured only at record.
- **Permission:** unchanged — `sales.receipts.manage` (Gate-1 #6). No `PermissionCatalogue`/`RoleTemplate`/`CustomerReceiptPolicy` change.
- **HTTP:** none this wave, matching every receipt wave to date.

---

## A — Account design: `1180 WHT Receivable`, a new Current-Asset system account for every company

**(Gate-1 APPROVED)** that this is a new asset account with a new system key. **(Gate-2 PROPOSED)** for every specific value below.

### The constant and the leaf

- `Account::WHT_RECEIVABLE = 'wht_receivable'` **(Gate-2 PROPOSED)** — a fifth system key beside `RETAINED_EARNINGS`, `OPENING_BALANCE_EQUITY`, `TRADE_RECEIVABLES`, `CUSTOMER_ADVANCES` (`Account.php:60-81`). Value `wht_receivable` is 13 chars, within `accounts.system_key varchar(48)` (`2026_02_02_000001:71`).
- **Code `1180`, name `WHT Receivable`, type `AccountType::Asset`.** **(Gate-2 PROPOSED)** — the code is chosen by inspection, not assumption: `ChartTemplate::accounts()` fills Current Assets (`1100`) with leaves `1110` Cash in Hand, `1120` Bank Accounts, `1130` Trade Receivables, `1140` Other Receivables, `1150` Inventory, `1160` Prepayments, `1170` Input VAT Recoverable (`ChartTemplate.php:80-88`); the next heading is `1200` Non-Current Assets (`:90`). **`1180` is the first free leaf under `1100`** and forces no renumbering — the exact discipline that placed `2180 Customer Advances` as the first free leaf under `2100`. It sits directly after `1170 Input VAT Recoverable`, its nearest sibling in kind: both are "recoverable from the authority" assets (the `1170` comment, `:86-87`). `normal_balance` is **not specified** — `Account::booted()` derives it from the type on save (`:227-238` → `Debit` for an asset), and `accounts_normal_balance_matches_type_check` (`2026_02_02_000001:127`) rejects a mismatch.
  - *Name alternative (Gate-2 confirmable):* the longer, self-documenting `Advance Income Tax Recoverable` (Gate-1's parenthetical). Recommendation: the terse `WHT Receivable`, matching the chart's house style (`Input VAT Recoverable`, `Trade Receivables`) and Gate-1's leading name; the fuller phrase can live in the account description if desired.
- **A required system account.** **(Gate-2 PROPOSED)** — added to **both** `ChartTemplate::accounts()` (as `self::leaf('1180', 'WHT Receivable', AccountType::Asset, parent: '1100', system: Account::WHT_RECEIVABLE)`, after the `1170` line) **and** `ChartTemplate::requiredSystemAccounts()` (as a parentless leaf, `system: Account::WHT_RECEIVABLE`), mirroring exactly how Trade Receivables and Customer Advances appear in both (`:82`/`:168`, `:110`/`:171`). Parentless in the required-accounts path because `ChartTemplateService::ensureSystemAccounts()` creates required accounts without resolving a parent.
- **`ChartTemplate::VERSION` bumped** `2026.08-lk-sme-3` → `2026.08-lk-sme-4` **(Gate-2 PROPOSED)** — the constant's own contract: bump on an addition (`ChartTemplate.php:35-40`).

### Provisioning and backfill

New companies get `1180` automatically: provisioning walks `accounts()`, and `ensureSystemAccounts()` walks `requiredSystemAccounts()` (idempotent under the partial unique index `accounts_company_system_key_unique`, `2026_02_02_000001:113-115`).

Existing companies need the account **created**, not stamped — WHT Receivable has never existed in any template, so there is no row to stamp (the Customer Advances case, not the Trade Receivables case). **(Gate-2 PROPOSED)** a new Accounting migration `2026_03_11_000001_create_wht_receivable_system_accounts.php` that, under `RowLevelSecurity::bypass()`, for each company lacking a `wht_receivable`-keyed active account, **inserts** `1180 WHT Receivable` — a near-verbatim copy of `2026_03_10_000001_create_customer_advances_system_accounts.php`, changing only:

| Field | Customer Advances backfill (`2026_03_10_000001`) | WHT Receivable backfill (proposed) |
| --- | --- | --- |
| key constant | `customer_advances` | `wht_receivable` |
| `name` | `Customer Advances` | `WHT Receivable` |
| `type` | `liability` | `asset` |
| `normal_balance` | `credit` | `debit` (required for an asset by `accounts_normal_balance_matches_type_check`) |
| parent lookup code | `2100` (Current Liabilities) | `1100` (Current Assets) |
| `availableCode` base | `2180` (+ `2180-1`, `2180-2`, …) | `1180` (+ `1180-1`, `1180-2`, …) |
| `sort_order` | `2180` | `1180` |

It inherits the two disciplines the Customer Advances migration carried (`2026_03_10_000001:29-37`):

1. **Bypass RLS explicitly** and prove it took — `assertBypassEffective()` (`:177-187`): `asids_app` is `NOBYPASSRLS` and `accounts` is FORCED, so without the bypass the migration silently touches zero rows.
2. **Assert nothing is left behind** — `assertNothingLeftBehind()` (`:198-220`): after running, every company holds exactly one active `wht_receivable` account, so a company whose first WHT receipt would otherwise fail months later is caught now.

`is_system = true` **and** `system_key` are written together (required by `accounts_system_key_check`, `2026_02_02_000001:137`, the exact trap the Customer Advances migration documents at `:38-43`); `template_version` is left null (these rows are a statement about the past, not a template application). Code collisions are handled by `availableCode()` — the account resolves by key, so `1180` may vary to `1180-1` where taken.

**(Gate-2 PROPOSED — following the resolved ADR 0016 precedent):** raw SQL, not an `ensureSystemAccounts()` iteration — "a migration is a statement about the past," the same reasoning ADR 0016's Gate 2 approved for the Customer Advances backfill. (ADR 0016 raised this as UNRESOLVED and the human chose raw SQL; this wave adopts that choice rather than re-opening it. If the human prefers to re-decide, it is the one carry-over fork.)

### Participation in posting

WHT Receivable is **debited only, at record time** (§C/§D). It is resolved by key, never by code — `Account::query()->forCompany($id)->withSystemKey(Account::WHT_RECEIVABLE)->first()` (`Account.php:206-209`) — exactly as `ReceiptPostingMap::customerAdvancesAccountFor()` resolves Customer Advances (`ReceiptPostingMap.php:170-190`), validated `type === Asset` and `acceptsPostings()`, refusing with a new `ReceiptCannotBePosted::withoutWhtReceivableAccount()` (mirror of `withoutCustomerAdvancesAccount()`, `ReceiptCannotBePosted.php:121-128`) when a company has none. It is resolved **only when `Σ wht > 0`**, so a company that somehow lacks the account can still record ordinary (non-WHT) receipts (regression safety — §C).

---

## B — Schema: two columns on `receipt_allocations`, no side table

**(Gate-1 APPROVED)** per-allocation WHT + certificate reference. **(Gate-2 PROPOSED)** the columns-not-table shape and every value below.

### Why columns, not a side table

ADR 0016 needed a two-table held-credit model because of a **database forcing constraint**: apply-credit posts a *second* non-reversing journal entry against the same balance, and the source-uniqueness index (`journal_entries_source_document_unique`) permits only one per source document, so each apply must be its own source row (ADR 0016 Problem #1, §B). **WHT is not subject to that constraint.** It posts **once**, as a line inside the receipt's *own* single entry (§C); it is never applied, consumed, or re-posted this wave; it has no balance with a lifecycle. There is therefore nothing a side table would carry that the allocation row cannot. The requirements reach the same conclusion (§5 OQ-7). Columns win on every axis: fewer tables, no new RLS policy, no new immutability trigger, and — decisively — the freeze comes for free (below).

### The columns

Migration **(Gate-2 PROPOSED)** `2026_03_11_000002_add_withholding_tax_to_receipt_allocations.php` adds to `receipt_allocations` (`2026_03_08_000002`, where `amount` is `numeric(19,4)` at `:55`):

| Column | Type | Notes |
| --- | --- | --- |
| `wht_amount` | `numeric(19,4) NOT NULL DEFAULT 0` | tax withheld against *this* allocation; `0` means "no WHT" |
| `wht_certificate_reference` | `varchar(120) NULL` | the customer's certificate/document reference, traceability only; matches `customer_receipts.reference varchar(120)` (ADR 0014 §A) |

- **`NOT NULL DEFAULT 0`, not nullable**, **(Gate-2 PROPOSED)** — so `Σ wht` is a plain SQL/`Money` sum with no NULL-vs-0 ambiguity, every existing allocation row is backfilled to `0` by the `DEFAULT` (their receipts carried no WHT), and the regression path (§C) is `Σ wht = 0` for all historical and all non-WHT receipts. A zero line means "no WHT withheld" — the interpretation Gate-1 #2's "validated `≥ 0`" implies (AC-WHT-1.4: an explicit `0` and an omitted field have identical effect).
- **`wht_certificate_reference` is a free optional string**, **(Gate-2 PROPOSED)** — no CHECK. It posts nothing; it is evidence for a later claim (Gate-1 #5). **No cross-field constraint** ties it to `wht_amount`: a certificate may be recorded before or after the amount is finalised, and coupling them adds friction with no accounting benefit (AC-WHT-3.3's working assumption). *Alternative (the human may prefer):* refuse a certificate reference on an allocation whose `wht_amount = 0` as an inconsistent input — see the confirmable in the Gate-2 block.

### CHECKs (same-row, so genuine CHECKs — unlike the cross-table allocation rules)

Both operands live on the same row, so — unlike "allocation ≤ invoice `amount_due`", which cannot be a CHECK because it joins to `sales_invoices` (`2026_03_08_000002:21-28`) — these are enforceable at the database as backstops under the service:

- `receipt_allocations_wht_non_negative_check CHECK (wht_amount >= 0)` — the backstop for Gate-1 #2's `≥ 0`; the readable refusal is raised first in the service (§D).
- `receipt_allocations_wht_not_exceeding_amount_check CHECK (wht_amount <= amount)` — the backstop for Gate-1 #2's "`≤ the allocation it applies to`". This is the direct same-row analogue of `sales_invoices_amount_paid_not_exceeding_total_check` (ADR 0014 §A): the database refuses a WHT larger than the gross AR it is withheld against, even if the service is bypassed.

### Immutability, RLS, audit — all inherited, none new

- **Immutability: no trigger change.** `asids_receipt_allocations_immutable()` refuses **every** UPDATE and DELETE unconditionally (`2026_03_08_000004:86-100`) — it does not enumerate columns, so the two new columns are frozen the instant the allocation is written, with no edit to the trigger and no omission risk. (Had WHT gone on the receipt *header*, its trigger's by-name column list at `2026_03_08_000004:43-64` would have needed extending — precisely the gap the requirements warn about, §6.4. The allocation location makes that risk structurally impossible.)
- **RLS: unchanged.** Same table, same tenant key, same `receipt_allocations` policy. No RLS migration (RLS is per-table, and no table is added).
- **Audit: unchanged, and correctly so.** `ReceiptAllocation` is deliberately **not** `Auditable` (`ReceiptAllocation.php:14-19`), like `SalesInvoiceLine`: an allocation is write-once immutable subledger detail, fully traceable via its parent receipt's audit entry (`CustomerReceipt` is `Auditable`, `CustomerReceipt.php:70,172`) and its own permanent row. The WHT columns inherit that property exactly — they can never change after insert — so no per-column audit is needed and `CustomerReceipt::auditOnly()` is untouched (WHT is not on the header). This is the same reasoning ADR 0016 used to leave `ReceiptHeldCredit` un-audited (state derivable from audited parents, §B).

### Model

`ReceiptAllocation` gains **(Gate-2 PROPOSED)**: `wht_amount` cast `decimal:4` (beside `amount`, `ReceiptAllocation.php:66-71`); `wht_certificate_reference` as a plain string attribute; `@property` docblock entries; and — optionally — a `whtMoney(string $currency): Money` helper beside `amountMoney()` (`:58-61`). No relation, no fillable change (the service assigns attributes directly, as it does for `amount`).

---

## C — The settlement invariant: the load-bearing redefinition

**This is the most important section. (Gate-1 APPROVED)** the posting shape and "gross AR settled = allocation" (Gate-1 #3). **(Gate-2 PROPOSED)** the exact invariant, formula, and the newly-accepted state below — flagged for **explicit human confirmation** because it evolves an invariant ADR 0016 shipped.

### What each figure means — and what does NOT change

| Figure | Meaning | Change? |
| --- | --- | --- |
| `receipt.amount` | **net cash actually received** (the bank debit) | **UNCHANGED** — still ADR 0014's "money received"; `Dr Bank = amount` stays (`ReceiptPostingMap.php:86`) |
| `allocation.amount` | **gross AR settled** for that invoice (reduces the invoice's gross `amount_paid`/`amount_due`; capped by gross `amount_due`) | **UNCHANGED** — it was always gross; Gate-1 #3 confirms it |
| `allocation.wht_amount` | tax withheld against that allocation | **NEW** (§B) |
| `remainder` | excess held as Customer Advances (ADR 0016) | value formula changes (below); mechanism unchanged |

The critical clarification the brief demands: **the meaning of `allocation.amount` does NOT change from gross to net.** It was gross before WHT (it reduces the gross invoice balance, `ReceiptService.php:236-247`, and is capped by gross `amount_due`, `:156-162`) and it stays gross. Before WHT, the *cash* applied to an invoice happened to equal the *gross* applied, because WHT was always zero — so the distinction was invisible, not absent. WHT makes it visible without redefining the stored figure: the **net cash** applied to an invoice is the derived `allocation.amount − wht_amount`, never stored, never the meaning of `allocation.amount`. Because `allocation.amount` keeps its meaning, its invoice-side behaviour (reduce gross balance, cap at gross `amount_due`, credit gross to AR) is **untouched**, and the brief's UNRESOLVED trigger ("if the meaning of `allocation.amount` changes existing behaviour") **does not fire**. What *does* change is a receipt-level relationship, below.

### The invariant

Define the receipt's **settlement power** — the total value it applies to receivables, whether that value arrived as cash or was withheld as tax:

```
settlement  =  amount (net cash)  +  Σ wht
```

The posting, composing WHT (debit side) with ADR 0016's remainder (credit side):

```
Dr  Bank / Cash               amount            (net cash received)
Dr  WHT Receivable            Σ wht             (only when Σ wht > 0)
    Cr  Trade Receivables            Σ allocations     (gross)
    Cr  Customer Advances            remainder         (only when remainder > 0)
```

with

```
remainder  =  settlement − Σ allocations  =  amount + Σ wht − Σ allocations
```

**It balances by construction:** `debits = amount + Σ wht = settlement`, and `credits = Σ allocations + remainder = Σ allocations + (settlement − Σ allocations) = settlement`. `JournalService::assertPostable()`'s `≥ 2 lines` and balance checks (`JournalService.php:176-208`) hold in all four line-count shapes (with/without WHT × with/without remainder).

This is **structurally identical to ADR 0016** with `amount` replaced by `settlement`. The map and service changes are therefore minimal: wherever the current code uses the cash `amount` as the receipt's total settling capacity, it uses `settlement = amount + Σ wht` instead.

### How the existing `record()` invariant/CHECK changes

- **Over-allocation** (`assertNotOverAllocated()`, `ReceiptService.php:717-731`): today refuses `Σ allocations > amount`. It becomes **refuse `Σ allocations > settlement`** — equivalently `Σ (allocation − wht) > amount`, i.e. "the net value applied to invoices exceeds the cash received." **(Gate-2 PROPOSED)**
- **Remainder** (`ReceiptService.php:264`; `ReceiptPostingMap.php:78`): today `amount − Σ allocations`. It becomes `settlement − Σ allocations = amount + Σ wht − Σ allocations`, computed with exact `Money` then `roundedTo($precision)` (`Money.php:110-117,240`). **(Gate-2 PROPOSED)**
- **Per-invoice cap** (`ReceiptService.php:156-162`) and **AR credit** (`ReceiptPostingMap.php:87`): **UNCHANGED** — both are gross-vs-gross (`allocation.amount` vs the invoice's gross `amount_due`; `Σ allocations` credited to AR). WHT never enters them.
- **Per-invoice WHT cap:** `wht ≤ allocation` (Gate-1 #2), enforced in the service (§D) and backed by the same-row CHECK (§B).

### The one newly-accepted state (call it out plainly)

Under ADR 0016, `Σ allocations > amount` was **always** an over-allocation error. Under this ADR it is **permitted when, and only when, WHT makes up the difference** — because gross settled (`Σ allocations`) legitimately exceeds net cash (`amount`) by exactly the withheld tax. Worked example: a gross-1000 invoice, WHT 50, cash 950 → `allocation = 1000`, `wht = 50`, `amount = 950`; `settlement = 1000`; `Σ allocations (1000) > amount (950)` but `= settlement`, so it is **accepted**, `remainder = 0`, and it posts `Dr Bank 950 / Dr WHT 50 / Cr AR 1000`. This new acceptance is **additive**: any existing over-allocation (which has `wht = 0`, so `settlement = amount`) still refuses exactly as before.

### Regression safety (the ADR 0016 AC-CR-6.1 discipline)

When every `wht_amount = 0` (all historical data, and every non-WHT receipt): `Σ wht = 0`, so `settlement = amount`, the over-allocation check is `Σ allocations > amount`, the remainder is `amount − Σ allocations`, and the WHT debit line is **omitted** (emitted only when `Σ wht > 0`). The record path and the posted entry are then **byte-identical** to what ADR 0016 produces today. WHT is a strictly additive, opt-in debit line.

### Ripple to ADR 0014 / 0016

- **ADR 0014:** its "Σ allocations = amount" full-allocation rule was already superseded by ADR 0016 (`assertNotOverAllocated`). This wave only further generalises the *comparand* from `amount` to `settlement`; at `wht = 0` it is ADR 0016's rule unchanged. No new re-opening of ADR 0014.
- **ADR 0016:** the Customer Advances mechanism, held-credit table, apply-credit, and cancellation of held credit are **untouched**. Only the remainder's *value formula* gains the `+ Σ wht` term (zero for every existing receipt). Gate-1 #4 holds: the remainder is still pure excess cash held as a liability; WHT never attaches to it.
- **`allocation.amount` semantics:** **NOT redefined** (see above). No UNRESOLVED on that account.

---

## D — Record path: per-allocation WHT inside the one transaction

**(Gate-1 APPROVED)** caller-supplied, per-allocation, validated. **(Gate-2 PROPOSED)** the mechanics.

### DTO

`ReceiptAllocationData` (`ReceiptAllocationData.php:14-37`) gains two optional fields, so every existing caller compiles and behaves identically:

```
public function __construct(
    public string $salesInvoiceId,
    public string $amount,                              // numeric-string, gross AR settled
    public ?string $whtAmount = null,                   // numeric-string; null ≡ "0" ≡ no WHT
    public ?string $whtCertificateReference = null,     // optional evidence reference
) {}
```

`fromArray()` reads optional `wht_amount` / `wht_certificate_reference` keys (the trimmed-optional-string pattern already in `ReceiptData::optionalString()`, `ReceiptData.php:72-83`). `ReceiptData` itself is unchanged — WHT is per-allocation.

### Service (`ReceiptService::record()`, `ReceiptService.php:85-283`)

All new validation runs **before the transaction and before any number is reserved**, the invariant the class already holds (`:37-73`):

1. In `allocationAmounts()` (`:664-690`), which already parses each line positive and at precision and **aggregates per invoice id** (`:684-686`), capture the WHT alongside each amount into a parallel per-invoice map (aggregated the same way — a caller naming one invoice twice sums both its amount and its WHT; the certificate reference of a doubled line takes the first non-null, a documented edge). For each line's WHT:
   - `wht = Money::of($whtAmount ?? '0', $currency)`.
   - refuse `wht < 0` → new `ReceiptCannotBeAllocated::negativeWithholding($identifier, $whtAmount)` **(Gate-2 PROPOSED)** — the "a negative one would un-pay" reasoning (`zeroOrNegativeLine()`, `ReceiptCannotBeAllocated.php:71-83`).
   - `assertAtCurrencyPrecision($wht, $precision)` — **reuses the existing helper** (`:700-705`), so a sub-precision WHT (`500.333` in a 2-dp currency) is refused via `ReceiptCannotBeRecorded::amountExceedsCurrencyPrecision()` (`ReceiptCannotBeRecorded.php:135-148`) exactly as the cash amount and each allocation already are. WHT reopens nothing about precision (AC-WHT-1.5/1.6).
   - refuse `wht > allocation` (per aggregated invoice) → new `ReceiptCannotBeAllocated::withholdingExceedsAllocation($identifier, $wht, $allocation)` **(Gate-2 PROPOSED)** — the readable refusal ahead of the same-row `wht_amount <= amount` CHECK (§B), mirroring how `exceedsAmountDue()` (`:92-105`) is the readable refusal ahead of the invoice-level CHECK.
2. Compute `totalWht = Σ wht` and `settlement = amount->plus(totalWht)`; refuse **`Σ allocations > settlement`** (§C). **(Gate-2 PROPOSED)** to keep the `wht = 0` regression byte-identical, `assertNotOverAllocated()` calls the **existing** `ReceiptCannotBeRecorded::overAllocated($allocated, $amount)` unchanged when `totalWht` is zero, and a **new** `ReceiptCannotBeRecorded::overAllocatedBeyondSettlement($allocated, $amount, $totalWht)` when `totalWht > 0`, whose message names the breakdown ("allocations total X gross, but the receipt settles only Y = cash Z + withholding W"). The existing `overAllocated()` message and code `receipt-over-allocated` are therefore untouched for the existing tests.
3. Inside the transaction, when building each `ReceiptAllocation` (`:190-201`), set `wht_amount` and `wht_certificate_reference` beside `amount` (`:196`).
4. The posting call (`:214-221`) is unchanged in shape; `ReceiptPostingMap::for()` (§C) now returns the WHT line when present.
5. The remainder (`:264`) is computed from `settlement` instead of `amount`: `settlement->minus($allocated)->roundedTo($precision)`. The held-credit insert (`:266-279`) is otherwise unchanged — the remainder is still pure excess cash (Gate-1 #4).

Everything else in `record()` — customer/bank resolution, lock-and-re-read per invoice, per-invoice cap, numbering, invoice `amount_paid`/`amount_due`/status updates — is **unchanged** (AC-WHT-1.2 regression).

### Posting map (`ReceiptPostingMap::for()`, `ReceiptPostingMap.php:59-103`)

- Sum `totalWht = Σ Money::of($allocation->wht_amount, $currency)` from the stored allocation rows — **stored values summed, never recomputed**, the discipline `Σ allocations` already follows (`:72-76`). The allocations are already loaded (`:61`); `wht_amount` is a column on them.
- `settlement = amount->plus(totalWht)`; `remainder = settlement->minus($allocated)` (replaces `:78`).
- Insert, **only when `totalWht->isPositive()`**, a single `Dr WHT Receivable(totalWht)` line via a new `whtReceivableAccountFor($receipt)` — the exact mirror of `customerAdvancesAccountFor()` (`:170-190`): resolve by system key `Account::WHT_RECEIVABLE`, validate `Asset` + `acceptsPostings()`, refuse `withoutWhtReceivableAccount()`. Omitted when `totalWht = 0` (regression). One WHT line, not per-allocation — WHT Receivable is a single GL account; the per-invoice detail lives in the subledger, exactly as `Σ allocations` is one AR line rather than one per invoice.
- The bank line (`:86`), AR line (`:87`), and Customer Advances line (`:92-100`) are otherwise unchanged.

### Atomicity

The whole operation remains **one `DB::transaction()`** (`:129`); a refusal writes nothing (AC-WHT-4.2), and every refusal is a named domain exception (AC-WHT-4.1), never a raw `QueryException`.

---

## E — Cancellation and apply-credit interaction

**(Gate-1 APPROVED)** direction; **(Gate-2 PROPOSED)** the confirmation that **no new code** is required.

### Cancellation reverses the WHT debit generically — no bespoke code

`ReceiptService::cancel()` (`ReceiptService.php:493-653`) reverses a receipt through `PostingService::reverse()`, which **mirrors every line of the original entry with sides swapped and amounts copied** (ADR 0015 §C; ADR 0016 §G Case 1). Because the WHT debit is an **ordinary line inside the receipt's single journal entry** (§C), the mirror produces a `Cr WHT Receivable(Σ wht)` automatically, unwinding it alongside the bank, AR, and Customer Advances lines. **No WHT-specific reversal code** — precisely how the Customer Advances credit is already reversed (AC-WHT-5.1). After cancellation the WHT Receivable balance returns to what it was before the receipt, a delta-restore by construction of the whole-entry mirror, never a snapshot (AC-WHT-5.2).

**No "already applied" guard for WHT (AC-WHT-5.3, confirmed).** ADR 0016's held credit needed `heldCreditAlreadyApplied()` (`ReceiptService.php:573-579`) *because* credit can be consumed by a later apply event, so reversing the whole entry could over-reverse a partially-consumed subledger. **A WHT receivable is never consumed by anything this wave** — there is no "apply WHT" operation; it is a plain ledger line with no balance-tracking table and no lifecycle (§B, §Problem #1). So the cancellation over-reversal hazard that forced ADR 0016's guard **cannot arise for WHT**, and no analogous guard is added. The existing held-credit branch in `cancel()` (`:563-641`) is **untouched**: WHT creates no held credit and never touches the remainder, so that branch neither sees nor needs WHT.

### Apply-credit is entirely unaffected

`ReceiptService::applyCredit()` (`ReceiptService.php:319-459`) posts `Dr Customer Advances / Cr Trade Receivables` with **no cash arriving** (ADR 0016 §D). WHT is withheld by the customer only at the moment they remit actual cash — i.e. only at **record** time. The held credit being applied is itself the untaxed remainder of an earlier cash receipt. So apply-credit needs **no WHT change** (AC-WHT-6.1): no new line, no new column read, no new refusal. *Known limitation (AC-WHT-6.2), logged not built:* if a customer notifies additional withholding *after* cash was already applied as credit, that correction is out of scope this wave (a manual JV is the interim), the same "known limitation" posture ADR 0016 §N took.

---

## F — Implementation stages (design only; one cohesive backend lane, staged for review)

One lane — the service depends on the schema/account/map, and the posting depends on the account. Staged like ADR 0016 §O; each stage is reviewed against the code before the next. **QA writes tests first (RED) per stage.** Notably **shorter than ADR 0016** (no held-credit tables, no new permission, no new service method, no HTTP).

| Stage | Objective | Expected files | Tests to write first (RED) | Depends on | Acceptance gate | Commit boundary |
| --- | --- | --- | --- | --- | --- | --- |
| **1 — Account** | `1180 WHT Receivable` exists for new and existing companies. | `Account::WHT_RECEIVABLE`; `ChartTemplate` (`accounts()`, `requiredSystemAccounts()`, `VERSION`→`…-4`); new Accounting backfill migration `2026_03_11_000001` (RLS-bypass, **create-not-stamp**, both assertions). | New company provisions it; empty-chart company gets it via `ensureSystemAccounts()`; backfill creates exactly one per legacy company and is idempotent; `assertBypassEffective`/`assertNothingLeftBehind` fire; `type=Asset`, `normal_balance=Debit`, `is_system=true`, resolvable by key. | — | The account resolves by key for every company. | One commit. |
| **2 — Schema + model** | The two columns, their CHECKs, and the inherited freeze. | Sales migration `2026_03_11_000002` (`wht_amount NOT NULL DEFAULT 0`, `wht_certificate_reference NULL`, both CHECKs); `ReceiptAllocation` casts/props. | Each CHECK refuses its violation at the DB (`wht_amount < 0`; `wht_amount > amount`); existing rows backfilled to `0`; the existing full-freeze trigger refuses UPDATE of `wht_amount` (**no trigger change**); RLS still isolates a second tenant. | 1 | Constraints and freeze provable before any code writes WHT. | One commit. |
| **3 — Record path + posting** | A WHT receipt is validated, posted with the WHT debit, and the settlement invariant holds. | `ReceiptAllocationData` (+ fields); `ReceiptService::record()`/`allocationAmounts()`/`assertNotOverAllocated()` (settlement); `ReceiptPostingMap::for()` (WHT line, settlement remainder) + `whtReceivableAccountFor()`; `ReceiptCannotBePosted::withoutWhtReceivableAccount()`; `ReceiptCannotBeAllocated::{negativeWithholding, withholdingExceedsAllocation}`; `ReceiptCannotBeRecorded::overAllocatedBeyondSettlement()`. | No-WHT receipt byte-identical (AC-WHT-1.2); WHT receipt posts `Dr Bank / Dr WHT / Cr AR [/ Cr Customer Advances]`, balanced (AC-WHT-1.1); `Σ alloc > amount` accepted when covered by WHT, refused otherwise; refusals — `wht<0`, `wht>alloc`, sub-precision (AC-WHT-1.3/1.4/1.5); per-allocation WHT with different certs (AC-WHT-1.7/2.2); WHT + remainder compose in one entry; four-dp/precision at `currency_precision` (AC-WHT-1.6); certificate reference captured and frozen. | 2 | The ledger balances for a WHT receipt; the settlement invariant holds; the no-WHT path is unchanged. | One commit. |
| **4 — Cancellation & apply-credit interaction (test-only)** | Prove the generic reversal and apply-credit non-interaction. **No production code.** | — (verification only) | Cancel a WHT receipt → the mirror reverses `WHT Receivable` (Cr), balance returns to prior, delta-restore holds with a later receipt still live (AC-WHT-5.1/5.2); no "already applied" guard needed for WHT (AC-WHT-5.3); apply-credit on a customer with WHT history posts no WHT and is unchanged (AC-WHT-6.1). | 3 | Cancellation and apply-credit are correct with WHT present, with no new code. | One commit (tests). |

**Not in these stages:** any permission/catalogue/role/policy change (Gate-1 #6 — reuse `sales.receipts.manage`); any HTTP/controller/route/OpenAPI/resource work; any WHT claim/offset against income tax, supplier-side WHT, or IRD filing (Gate-1 #7, out of scope).

---

## G — Test strategy (QA asserts test-first; do not weaken existing tests)

Mirrors ADR 0016 §P. Existing suites (`RecordReceiptTest`, `ReceiptPostingMapTest`, `CancelReceiptTest`, `ApplyCreditTest`, `ReceiptAuthorizationTest`, `tests/Feature/Sales/`) stay green; WHT is additive.

- **Posting shape & balance** (new `tests/Feature/Sales/WithholdingTaxReceiptTest.php` + extend `ReceiptPostingMapTest`): single gross-1000 invoice, WHT 50, cash 950 → `Dr Bank 950 / Dr WHT 50 / Cr AR 1000`, three lines, balanced, `remainder = 0`, no Customer Advances line (AC-WHT-1.1); WHT **and** overpayment in one receipt → four lines `Dr Bank / Dr WHT / Cr AR / Cr Customer Advances`, balanced; multi-invoice, different WHT per invoice → one AR credit `Σ alloc`, one WHT debit `Σ wht`, each invoice's gross balance moved by its own `allocation.amount` (AC-WHT-1.7/2.2).
- **Regression (AC-WHT-1.2)** (extend `RecordReceiptTest`/`ReceiptPostingMapTest`): a receipt with no WHT (`wht` null and explicit `0`) posts a **byte-identical** entry to today — two lines when fully allocated, three with a remainder; no WHT debit line; `overAllocated()` message/code unchanged.
- **The settlement invariant:** `Σ alloc > amount` **accepted** when `Σ alloc ≤ amount + Σ wht`; **refused** (`overAllocatedBeyondSettlement`) when `Σ alloc > amount + Σ wht`; `remainder = amount + Σ wht − Σ alloc` at `currency_precision`.
- **Refusals (AC-WHT-4.1), each a named exception, nothing written (AC-WHT-4.2):** `wht < 0` (`negativeWithholding`); `wht > allocation` (`withholdingExceedsAllocation`); sub-precision WHT (`amountExceedsCurrencyPrecision`, reused); plus **DB-bypass** tests driving `wht_amount` negative and `wht_amount > amount` directly, refused by the two CHECKs (the "bypass the service" backstop test ADR 0014 uses for oversell).
- **Precision (AC-WHT-1.5/1.6):** realistic `currency_precision` inputs (per the ADR 0016 Gate-2 amendment); no `Money::SCALE` phantom remainder; the WHT+remainder composition reconciles subledger and ledger.
- **Account (Stage 1):** provisioning, empty-chart `ensureSystemAccounts()`, backfill create/idempotency/assertions, `type/normal_balance/is_system`, resolution by key; `withoutWhtReceivableAccount()` refusal when the account is absent **and** `Σ wht > 0` (and *not* refused for a no-WHT receipt).
- **Immutability & RLS (Stage 2):** the full-freeze trigger refuses UPDATE/DELETE of the WHT columns with no trigger change; a second tenant cannot read them.
- **Cancellation (Stage 4):** cancel a WHT receipt → mirror reverses WHT Receivable, balance nets to zero and returns to prior; delta-restore preserved when another receipt is still live against the same invoice; no "already applied" guard for WHT.
- **Apply-credit non-interaction (Stage 4):** applying held credit for a WHT-history customer posts `Dr Customer Advances / Cr AR` only — no WHT line, no WHT column read.
- **Authorization:** unchanged — `sales.receipts.manage` still gates recording (a holder may record WHT; a non-holder may not). No new authorization test surface (Gate-1 #6).

---

## Accounting invariants (each enforceable)

1. **Every WHT receipt balances** — `debits = amount + Σ wht = settlement = Σ alloc + remainder = credits`, by construction; `JournalService::assertPostable()` (`:204-208`) is the backstop.
2. **WHT ≤ the gross it is withheld against** — service refusal + same-row `wht_amount <= amount` CHECK (§B).
3. **WHT is non-negative** — service refusal + `wht_amount >= 0` CHECK.
4. **WHT is at `currency_precision`** — `assertAtCurrencyPrecision()` reused (§D), so subledger and ledger agree (no phantom remainder).
5. **Gross AR is fully settled** — AR is credited `Σ allocations` (gross), independent of WHT; each invoice's gross balance moves by its own `allocation.amount` (unchanged).
6. **WHT never touches the remainder** — the remainder is `settlement − Σ alloc`, pure excess cash held as Customer Advances; no WHT attaches to it (Gate-1 #4).
7. **WHT is reversed with its receipt** — the whole-entry mirror (§E); no orphaned WHT Receivable balance after cancellation.
8. **The no-WHT path is unchanged** — `Σ wht = 0 ⇒` byte-identical to ADR 0016 (§C).
9. **A posted WHT figure is immutable** — the existing full-freeze allocation trigger (§B), no trigger change.

## Alternatives considered

- **WHT as `receipt.amount` = gross settlement (bank debit = `amount − Σ wht`)** — rejected. It redefines `receipt.amount` from "cash received" (ADR 0014; AC-WHT-1.1's "cash amount actually received") to "gross value settled", and changes the bank debit line from `Dr Bank = amount` to `Dr Bank = amount − Σ wht`. More disruptive, and it contradicts the requirements' own framing. Keeping `amount` = net cash and introducing `settlement = amount + Σ wht` (§C) leaves both `amount` and the bank line untouched.
- **A WHT side table (a `receipt_withholdings` subledger)** — rejected (YAGNI). It would be justified only if WHT posted a second entry or had a consumption lifecycle (ADR 0016's forcing constraint); it has neither this wave (§Problem #1, §B). Columns on `receipt_allocations` carry everything, freeze for free, and add no table/RLS/trigger.
- **WHT columns on the `customer_receipts` header** — rejected. Gate-1 #3 mandates per-allocation WHT (a receipt may withhold differently per invoice), and the header trigger freezes a by-name column list (`2026_03_08_000004:43-64`) that a new column could be forgotten from (requirements §6.4). The allocation table is both required by Gate-1 and structurally freeze-safe.
- **A new `sales.receipts.record-wht` permission** — rejected at Gate 1 (#6): WHT is an attribute of the same record action, not a distinct money-moving operation (contrast apply-credit, which earned its own permission in ADR 0016 §F).
- **Per-allocation WHT debit lines in the GL** — rejected. WHT Receivable is one account; one netted `Dr WHT Receivable(Σ wht)` line matches the single-AR-line precedent and keeps the entry minimal. Per-invoice detail is the subledger's job.

## Consequences

- A fifth system account exists; `ChartTemplate::VERSION` advances to `2026.08-lk-sme-4`; every existing company gains `1180 WHT Receivable` via a create-not-stamp backfill.
- `receipt_allocations` gains two columns and two CHECKs; **no** new table, RLS policy, or immutability trigger.
- `ReceiptPostingMap` gains a WHT debit line and a `whtReceivableAccountFor()` resolver; `ReceiptService::record()` gains WHT validation and the settlement invariant; three new exception factories; the DTO gains two optional fields.
- The receipt posting is now up to **four** lines. The over-allocation invariant generalises from `amount` to `settlement = amount + Σ wht`; `Σ allocations > amount` is newly permitted when covered by WHT. Both reduce to ADR 0016 exactly when `Σ wht = 0`.
- **No** permission, policy, role, HTTP, or apply-credit change. Cancellation gains no code.

## Risks and mitigations

1. **Re-discovering the `currency_precision` vs `Money::SCALE` phantom-remainder trap** ADR 0016 fixed once. *Mitigation:* WHT inherits the fix from day one — `assertAtCurrencyPrecision()` is applied to every WHT input (§D), and `settlement`/`remainder` are computed exact then `roundedTo($precision)`; the WHT+remainder composition is tested at realistic precision (§G). The wave must not reopen or weaken this.
2. **Evolving a shipped invariant** (over-allocation now depends on `settlement`). *Mitigation:* the `wht = 0` path is byte-identical (§C regression); the one newly-accepted state (`Σ alloc > amount` when covered by WHT) is explicitly enumerated and tested; existing over-allocation tests (with `wht = 0`) stay green and their message/code are untouched.
3. **Immutability-set omission** (a posted WHT figure left editable). *Mitigation:* turned into a **non-issue by table choice** — the `receipt_allocations` full-freeze trigger covers the new columns with no edit (§B); a test proves it (Stage 2).
4. **Backfill silently doing nothing** (the RLS-on-a-FORCED-table trap). *Mitigation:* `assertBypassEffective()` + `assertNothingLeftBehind()`, create-not-stamp, mirroring `2026_03_10_000001` (§A).
5. **Scope creep toward supplier-side WHT or IRD filing / claim-against-income-tax.** *Mitigation:* explicitly out of scope (Gate-1 #7); the account holds the receivable but nothing consumes it this wave; discovery mid-build is a Gate escalation, not a quiet absorption.
6. **Stacking dependency on `feature/phase4-credit-on-account` (ADR 0016).** This branch is stacked on it, and ADR 0016's later stages (apply-credit, the cancellation held-credit branch, its permission) are mid-build on the parent branch; this wave modifies the same `ReceiptService::record()` and `ReceiptPostingMap` ADR 0016 touches. *Mitigation:* coordinate with the Delivery Manager before Stage 4 build begins; rebase on the parent's final `record()`/posting-map shape; if the parent's posting contract changes before this wave's Gate 2, the §C/§D deltas return to Gate 2.

---

## Gate 2 — Architecture Approval Required

The build does not start until the human approves the following. Items are labelled by decision status.

1. **ADR 0017 summary.** WHT on customer receipts is a **per-allocation debit inside the receipt's own single journal entry**: `Dr Bank(net cash) / Dr WHT Receivable(Σ wht) / Cr Trade Receivables(Σ alloc gross) [/ Cr Customer Advances(remainder)]`. A new `1180 WHT Receivable` Current-Asset system account (backfilled create-not-stamp) receives it; two columns on `receipt_allocations` carry the per-allocation WHT + certificate reference (no side table); the receipt's settlement invariant becomes `settlement = amount + Σ wht`. Cancellation reverses the WHT line generically (no new code); apply-credit is untouched; the permission is unchanged. No HTTP.
2. **WHT Receivable account (Gate-2 PROPOSED):** code `1180`, name `WHT Receivable`, type `Asset`, normal balance `Debit` (derived), a required system account in both `ChartTemplate::accounts()` (after `1170`) and `requiredSystemAccounts()`, `VERSION` → `2026.08-lk-sme-4`, backfilled by **creating** it for every existing company (RLS-bypass, create-not-stamp, both assertions). *Confirmable:* the terse name `WHT Receivable` vs the fuller `Advance Income Tax Recoverable`.
3. **System key (Gate-2 PROPOSED):** `Account::WHT_RECEIVABLE = 'wht_receivable'`.
4. **Schema — columns, not a table (Gate-2 PROPOSED):** on `receipt_allocations`, `wht_amount numeric(19,4) NOT NULL DEFAULT 0` and `wht_certificate_reference varchar(120) NULL`; CHECKs `wht_amount >= 0` and `wht_amount <= amount`. **No side table, no RLS migration, no immutability-trigger change** (the existing full-freeze trigger already freezes them). Justified: WHT posts once inside the receipt's own entry, with no second posting or consumption lifecycle — none of ADR 0016's table-forcing constraints apply.
5. **The settlement invariant and `allocation.amount` semantics (Gate-2 PROPOSED — the load-bearing item, flagged for explicit confirmation):**
   - `receipt.amount` **stays "net cash received"** (bank debit unchanged); `allocation.amount` **stays "gross AR settled"** (Gate-1 #3) — **its meaning does NOT change from gross to net**, so the brief's UNRESOLVED trigger does not fire. The derived net cash per invoice (`allocation.amount − wht_amount`) is never stored.
   - New: `settlement = amount + Σ wht`; over-allocation refuses `Σ allocations > settlement` (was `> amount`); `remainder = settlement − Σ allocations` (was `amount − Σ allocations`).
   - **Newly-accepted state to confirm:** `Σ allocations > amount` is now permitted **when and only when WHT makes up the difference** (`Σ alloc ≤ amount + Σ wht`). Under ADR 0016 that was always an error.
   - **Regression:** at `Σ wht = 0` everything is byte-identical to ADR 0016. Ripple to ADR 0014/0016 is confined to the remainder's value formula (zero for every existing receipt); the Customer Advances mechanism and apply-credit are untouched.
6. **Record path (Gate-2 PROPOSED):** `ReceiptAllocationData` gains optional `whtAmount` + `whtCertificateReference`; `ReceiptService::record()` validates per-allocation WHT (`wht < 0`, `wht > allocation`, sub-precision) and applies the settlement invariant; `ReceiptPostingMap::for()` emits one `Dr WHT Receivable(Σ wht)` line only when `Σ wht > 0`. One transaction, named refusals, nothing written on refusal.
7. **Posting resolver + error contract (Gate-2 PROPOSED):** new `whtReceivableAccountFor()` (mirror of `customerAdvancesAccountFor()`); reuse `ReceiptCannotBeRecorded::amountExceedsCurrencyPrecision()` for sub-precision WHT and `overAllocated()` unchanged for the `wht = 0` path; new factories `ReceiptCannotBeAllocated::{negativeWithholding, withholdingExceedsAllocation}`, `ReceiptCannotBeRecorded::overAllocatedBeyondSettlement`, `ReceiptCannotBePosted::withoutWhtReceivableAccount`.
8. **Cancellation & apply-credit (Gate-1 APPROVED direction; Gate-2 confirmation of NO new code):** the whole-entry mirror reverses the WHT debit generically; no "already applied" guard (WHT has no consumption lifecycle); apply-credit posts no WHT and is unchanged.
9. **Permission (Gate-1 APPROVED):** reuse `sales.receipts.manage`. No catalogue/role/policy change.
10. **Build stages (Gate-2 PROPOSED):** §F — Account → Schema+model → Record+posting → Cancellation/apply-credit interaction (test-only). Four stages; no permission stage, no HTTP.
11. **Test strategy (Gate-2 PROPOSED):** §G — additive; no existing test weakened; the regression (`wht = 0` byte-identical) and the one newly-accepted state are both asserted; DB-bypass tests back the two CHECKs.
12. **Risks:** §Risks — the phantom-remainder trap and the shipped-invariant evolution are the top two, both mitigated by inheriting the `currency_precision` discipline and the `wht = 0` byte-identical regression.
13. **Items needing explicit human approval (UNRESOLVED / confirmable):**
    - (a) **Certificate-reference / WHT-amount independence** — recommendation: permit them independently (no cross-field constraint; a certificate may precede or follow the amount). The alternative — refuse a certificate reference on an allocation whose `wht_amount = 0` — is a genuine small fork the human may prefer; it is the only place Gate-1 #5's "simple optional field" leaves room.
    - (b) **Backfill mechanism** — recommendation: raw SQL (create-not-stamp), following the ADR 0016 Gate-2 resolution rather than re-opening it. Confirm, or re-decide.
    - Plus confirmation of every Gate-2 PROPOSED value above (account code/name/key, the two columns and CHECKs, the `settlement` invariant and the newly-accepted `Σ alloc > amount` state, the DTO fields, the exception factories, the migration filenames, the four stages).

**STOP — Stage 4 (build) does not begin until these are approved.**

## Gate 2 decision — APPROVED 2026-08-31

The human approved the architecture package **as proposed**:

- **Account `1180 WHT Receivable`** (Asset, normal balance Debit), system key `Account::WHT_RECEIVABLE = 'wht_receivable'`, in `ChartTemplate::accounts()` + `requiredSystemAccounts()`, `VERSION → 2026.08-lk-sme-4`.
- **Per-allocation columns** on `receipt_allocations`: `wht_amount numeric(19,4) NOT NULL DEFAULT 0` (CHECK `>= 0` and `<= amount`) and `wht_certificate_reference varchar(120) NULL`. No side table; no immutability-trigger change (the unconditional allocation freeze already covers them).
- **Settlement invariant CONFIRMED:** `settlement = amount + Σ wht`; over-allocation refuses `Σ allocations > settlement`; `remainder = settlement − Σ allocations`. The **newly-accepted state is approved** — `Σ allocations > amount` is valid exactly when WHT covers the gap (`Σ alloc ≤ amount + Σ wht`). `allocation.amount` keeps its gross meaning (no semantics change). Byte-identical at `Σ wht = 0`.
- **Posting:** `Dr Bank(net) + Dr WHT Receivable(Σ wht) = Cr Trade Receivables(Σ alloc gross) [+ Cr Customer Advances(remainder)]`, at `currency_precision`.
- **Fork (a) — certificate reference INDEPENDENT of wht_amount** (no cross-field constraint). (OQ resolved.)
- **Fork (b) — backfill = raw-SQL create-not-stamp**, mirroring ADR 0016 §A.
- No new permission (reuse `sales.receipts.manage`); no HTTP; cancellation reuses the generic whole-entry mirror; apply-credit unaffected.

Build proceeds strictly within this ADR (4 stages, test-first). Any implementation discovery that would change a decision above returns to Gate 2.
