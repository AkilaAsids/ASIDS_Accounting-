# ADR 0022 — Foreign-currency purchasing + realised FX (Phase 6, Wave 9a)

- **Status:** Proposed — for Gate 2 (human) review
- **Author:** Solution Architect (Phase 6 / Wave 9a)
- **Date:** 2026-09-10
- **Branch:** `feature/phase6-fx-purchasing` (stacked on `feature/phase5-wht-on-payment`, Waves 7/8 — Phase 5
  PRs #3–#10 unmerged; the eventual merge is bottom-up, per the Gate-1 sequencing decision).
- **Supersedes / extends:** nothing. It **turns on** machinery designed in from day one — the nullable
  transaction-currency overlay on `journal_lines` and the `currency_code`/`exchange_rate` columns on `bills`
  and `supplier_payments` — and adds one account and the realised-FX mechanics. It rewrites none of Waves
  7/8/8b; the base-currency path is preserved byte-for-byte.
- **Requirements:** `docs/PHASE-6-FX-REQUIREMENTS.md` (Gate 1 decisions — **APPROVED 2026-09-10**).

## How to read this record

Every point is labelled **(Gate-1 APPROVED)** — binding, decided by the human on 2026-09-10, realised here
verbatim, not relitigated; **(Gate-2 PROPOSED)** — my design realisation, to confirm before build; or
**(UNRESOLVED — needs human approval)** — a fork I will not decide silently, collected at the end. Nothing
here is built until Gate 2 approves this ADR, which the build then follows verbatim. Any implementation
discovery that would change a decision returns to Gate 2.

## Context — what makes 9a the crux, and the one insight that keeps it bounded

A company based in LKR buys a service for USD 1,000. It records a bill in **USD** at an **exchange rate**,
posts the general ledger in **LKR** at that rate, and — when it pays at a **different** rate — recognises the
**realised** gain or loss the rate movement produced. The worked example (requirements §1):

```
Bill: USD 1,000 @ 300.00  →  Dr Expense 300,000  Cr Trade Payables 300,000   (LKR, base)
Pay:  USD 1,000 @ 310.00  →  cash out USD 1,000 = LKR 310,000; the payable was booked at 300,000
      Dr Trade Payables 300,000
      Dr FX Loss           10,000
          Cr Bank                 310,000
```

**This phase does not build multi-currency from nothing.** The ledger already carries `debit`/`credit` in the
company's base currency **always**, plus a nullable overlay — `transaction_currency_code`,
`transaction_amount`, `exchange_rate` (`2026_02_03_000003_create_journal_lines_table.php:84-86`) — where NULL
means "base currency at rate 1". `journal_lines_currency_columns_check` (`:110-117`) already enforces
all-three-present-or-all-absent with a positive rate; `journal_lines_single_currency_until_fx_phase` (`:121-125`)
is the one constraint pinning the overlay to NULL, and its own comment names this phase as the sole dropper.
`bills` and `supplier_payments` each carry `currency_code` + a nullable `exchange_rate` held NULL by their own
`*_single_currency_until_fx_phase` CHECK (`create_bills_table.php:73,182-186`;
`create_supplier_payments_table.php:75,129-133`). `Money` stores integer minor units at `SCALE = 4` with a
per-currency tag (`Money.php:45-61`).

**The load-bearing insight (verified below).** The general ledger's *authority for balance is already the base
`debit`/`credit`, and only that.* `JournalService::assertPostable()` sums `debitMoney`/`creditMoney` in
`base_currency_code` and refuses an entry where they differ (`JournalService.php:167,182-208`); the deferred
constraint trigger `asids_journal_entry_balanced()` does the same at COMMIT
(`2026_02_03_000003…:143-189`). **Neither reads the overlay.** So if the overlay is *metadata that rides
alongside a base amount the FX-aware caller has already computed*, then:

1. **The balance check does not change at all** — it already sums the base amounts, and the FX gain/loss line
   is simply another base amount that makes them equal.
2. **The NULL-overlay path is byte-for-byte today** — a base-currency document writes no overlay, and every
   existing posting path (sales, journals, close, bills, payments) is untouched.
3. **The FX gain/(loss) line is a pure base-currency plug** — it carries *no* overlay (it is not "USD X at
   rate Y"; it is LKR Z of realised difference), so NULL-overlay on that line is correct and meaningful.

That is the whole shape of 9a: compute base amounts at the transaction rate in the two purchasing posting maps,
attach the overlay as traceability, let the existing balance machinery hold, and add one account for the plug.
The ledger is **turned on**, not re-plumbed.

**Three parts carry the design weight, and none has a faithful precedent on this line:**

1. **The realised-FX mechanics on settlement** (§D) — a payment relieves each bill's payable at *that bill's*
   booking rate while paying cash at *the payment's* rate; the base difference is realised FX. This is the
   point of the slice and the subject of the sharpest rounding decisions (§D3, forks 2–3).
2. **The shared-CHECK blast radius** (§B3, Risk 1) — `journal_lines_single_currency_until_fx_phase` guards
   **every** posting path. Dropping it is a ledger-wide event; the slice must prove the base path is unchanged
   across the whole ledger, not just purchasing.
3. **A brand-new system account the chart never defined** (§A5–A6) — `Foreign Exchange Gain/(Loss)` exists in
   no prior template, so its backfill must **create**, not stamp, reproducing the Wave 8b `WHT Payable` RLS
   scaffold exactly (`2026_09_09_000001_provision_wht_payable_account.php`).

## Binding Gate-1 decisions (verbatim from `docs/PHASE-6-FX-REQUIREMENTS.md` → Gate 1 — APPROVED 2026-09-10)

1. **First wave = 9a — foreign purchasing + realised FX:** foreign-currency **bills** + **supplier payments**;
   the ledger overlay turned on for the purchasing posting paths; **realised** FX gain/loss on settlement. Drop
   `journal_lines_single_currency_until_fx_phase`, `bills_single_currency_until_fx_phase`,
   `supplier_payments_single_currency_until_fx_phase`. Foreign sales (9b), unrealised/period-end FX (9c) and a
   rate table (9d) are later slices.
2. **FX account = a single combined `Foreign Exchange Gain/(Loss)`, Income type** — one account carrying
   credits for gains and debits for losses, netting to the period's FX result. Leaf + system key + backfill +
   chart VERSION bump.
3. **Manual per-document rate** — the rate is supplied per document by the caller, validated positive; no rate
   table/feed this slice (9d).
4. **Unrealised / period-end FX deferred** (9c).
5. **Base-currency GL invariant** — `debit`/`credit` remain in base currency; the transaction
   currency/amount/rate populate the existing overlay columns.
6. **Open ISO-4217** — any valid code (Money validates the shape); no per-company allowlist this slice.
7. **Same-currency settlement only** — a payment settles bills in the **same** transaction currency as the
   payment; cross-currency settlement is out of scope.
8. **Rounding** — base-currency posting rounded to `companies.currency_precision`; transaction amounts at
   `Money::SCALE`; rate at the existing `decimal(19,10)` columns; base equivalent = `round(transaction_amount ×
   rate, precision)`.
9. **FX figures frozen once posted, and audited.**

Deferred scope confirmed: unrealised/period-end revaluation, rate table/feed, foreign sales/receipts,
cross-currency settlement, presentation-currency reporting, multi-currency bank reconciliation.

---

# A. Schema

## A0. What 9a turns on, and the one account it adds (Gate-1 APPROVED dec. 1, 2)

9a **drops** three phase-scoped CHECKs and **adds** one account. It adds **no** new table and **no** new
column — every currency column already exists — and it adds **no** immutability migration, because the columns
it uses are already frozen (§A7, a genuine simplification versus Wave 8b).

| Change | Where | Realises |
| --- | --- | --- |
| Drop `journal_lines_single_currency_until_fx_phase` | new Accounting migration (§A2) | dec. 1, 5 |
| Drop `bills_single_currency_until_fx_phase`; add `bills_exchange_rate_positive_check` | new Purchasing migration (§A3) | dec. 1, 3 |
| Drop `supplier_payments_single_currency_until_fx_phase`; add `supplier_payments_exchange_rate_positive_check` | new Purchasing migration (§A4) | dec. 1, 3 |
| `Foreign Exchange Gain/(Loss)` leaf + `Account::FX_GAIN_LOSS` key + template VERSION bump | `Account.php`, `ChartTemplate.php` (§A5) — code, not a migration | dec. 2 |
| Backfill `Foreign Exchange Gain/(Loss)` into existing companies (**create**, not stamp) | new Accounting migration (§A6) | dec. 2 |

The overlay-writing code (`Money::convertedTo`, `JournalLineData`, `JournalService::writeLines`) and the two
posting maps are **code**, shipped in the same wave and cited where they belong (§B–§D).

## A1. Migration files (Gate-2 PROPOSED)

Four migrations, dated after the Wave 8b set (`…09_09_000004`). Two live under Accounting (the ledger CHECK and
the `accounts` table — the same home as the stamp/create precedents), two under Purchasing. Filename order is
the global run order; all four are mutually independent.

| File | Module | Does |
| --- | --- | --- |
| `2026_09_10_000001_provision_fx_gain_loss_account.php` | Accounting | Creates `4910 Foreign Exchange Gain/(Loss)` (keyed) in every existing company that lacks it, under bypassed RLS with both effectiveness assertions (§A6). |
| `2026_09_10_000002_allow_foreign_currency_on_journal_lines.php` | Accounting | Drops `journal_lines_single_currency_until_fx_phase`; the permanent `journal_lines_currency_columns_check` remains the overlay-shape authority (§A2). |
| `2026_09_10_000003_allow_foreign_currency_on_bills.php` | Purchasing | Drops `bills_single_currency_until_fx_phase`, adds `bills_exchange_rate_positive_check` (§A3). |
| `2026_09_10_000004_allow_foreign_currency_on_supplier_payments.php` | Purchasing | Drops `supplier_payments_single_currency_until_fx_phase`, adds `supplier_payments_exchange_rate_positive_check` (§A4). |

The two "drop a phase CHECK and add a stronger invariant" migrations follow the exact precedent of
`2026_09_03_000001_allow_bill_payments.php:30-38`, which dropped `bills_no_payments_until_payments_phase` and
added `bills_amount_due_non_negative_check` in its place. Each `down()` restores the dropped CHECK verbatim.

## A2. `journal_lines` — drop the pin, keep the shape authority (Gate-2 PROPOSED, realising dec. 1, 5)

`2026_09_10_000002…` issues exactly:

```sql
ALTER TABLE journal_lines DROP CONSTRAINT journal_lines_single_currency_until_fx_phase;
```

and nothing else. It does **not** touch `journal_lines_currency_columns_check` (`…000003…:110-117`), which is
permanent and becomes — as its own comment always intended — the sole authority on overlay shape: all three
columns present with a positive rate, or all three absent. `down()` re-adds the dropped CHECK:

```sql
ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_single_currency_until_fx_phase
    CHECK (transaction_currency_code IS NULL);
```

**This is the highest-blast-radius change in the slice** (Risk 1). Before it, no row anywhere may carry an
overlay; after it, any posting path *could*. The guarantee that only the two FX-aware purchasing maps ever
populate the overlay is a **code + test** guarantee, not a CHECK: §H's broad regression asserts that every
other posting path (sales, manual journals, reversal, close) still writes NULL overlay and is byte-for-byte
unchanged. No new columns; the deferred balance trigger and the posted-line immutability trigger are untouched
(`…000003…:143-225`).

## A3. `bills` — permit a foreign rate; the money columns are transaction-currency (Gate-2 PROPOSED, dec. 1, 3)

`bills` already has `currency_code` (char(3), NOT NULL) and a nullable `exchange_rate` (`create_bills_table.php:71-73`).
The migration drops the pin and adds a positivity CHECK in its place:

```sql
ALTER TABLE bills DROP CONSTRAINT bills_single_currency_until_fx_phase;
-- A present rate must be positive. NULL still means "base currency at rate 1" (the service holds the
-- rate-NULL ⟺ currency_code = base invariant; a CHECK cannot compare across to companies.base_currency_code).
ALTER TABLE bills ADD CONSTRAINT bills_exchange_rate_positive_check
    CHECK (exchange_rate IS NULL OR exchange_rate > 0);
```

**The load-bearing semantic decision (fork 1):** a foreign bill's money columns — `subtotal`, `discount_total`,
`tax_total`, `total`, `amount_paid`, `amount_due` (`create_bills_table.php:76-83`) — are held in the
**transaction currency** (`currency_code`), not base. A USD 1,180 bill stores `total = 1180.0000` with
`currency_code = 'USD'`; the base equivalent (LKR 354,000) is **derived** at posting via `exchange_rate` and
lives only in the ledger. This is the natural reading — "you owe USD 1,180", and the AP subledger, the aging,
and the remaining balance are all in the currency you owe — and it is what the existing arithmetic already
does: `BillService::replaceLines()` computes every total in `$currency = $bill->currency_code`
(`BillService.php:340,358,379-435`), so with `currency_code = 'USD'` the whole calculation is USD with no
change. The invariants read in that currency and stay coherent:

- `bills_total_check` (`total = subtotal + tax_total`) and `bills_amount_due_check` (`amount_due = total −
  amount_paid`) (`create_bills_table.php:160-170`) are currency-agnostic arithmetic on the stored decimals —
  all USD, all consistent.
- `bills_amount_due_non_negative_check` (the per-bill cap, `2026_09_03_000001_allow_bill_payments.php:32-36`)
  reads in USD: `amount_paid ≤ total` in the bill's own currency.
- An allocation settling the bill is in USD (§D2), so `allocation ≤ amount_due` is a same-currency comparison;
  `Money` throws `CurrencyMismatch` on any accidental mixing (`Money.php:353-360`), a hard backstop.

`down()` restores `bills_single_currency_until_fx_phase`. **No freeze-trigger change:** the posted-bill
immutability function already freezes `currency_code` and `exchange_rate` by name
(`2026_09_02_000005_make_posted_bills_immutable.php:58-59`), so a posted bill's booking rate is already
immutable (dec. 9).

## A4. `supplier_payments` — permit a foreign rate; amount is transaction-currency (Gate-2 PROPOSED, dec. 1, 3, 7)

Symmetric to §A3. `supplier_payments` already has `currency_code` + nullable `exchange_rate`
(`create_supplier_payments_table.php:71-75`):

```sql
ALTER TABLE supplier_payments DROP CONSTRAINT supplier_payments_single_currency_until_fx_phase;
ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_exchange_rate_positive_check
    CHECK (exchange_rate IS NULL OR exchange_rate > 0);
```

`supplier_payments.amount` and `wht_amount` (`create_supplier_payments_table.php:80`; `…09_09_000003…:26`) are
held in the **transaction currency** — consistent with `amount = Σ allocations` (both USD) and with
`bills.total` (§A3). `supplier_payments_amount_positive_check` (`:121-125`), `supplier_payments_wht_within_amount_check`
(`wht_amount ≤ amount`) and the deferred full-allocation trigger (`Σ payment_allocations.amount = amount`, ADR
0021 §A3) all compare same-currency decimals and are undisturbed. **Same-currency settlement (dec. 7) is a
service refusal, not a CHECK** — a CHECK cannot join `payment_allocations → bills → supplier_payments` to
compare `currency_code`; the service asserts it under the bill lock and the `Money` cross-currency throw is the
backstop (§D2). **No freeze-trigger change:** `currency_code` and `exchange_rate` are already frozen by name on
a posted payment (`2026_09_03_000005_make_posted_payments_immutable.php:72-73`).

## A5. `Foreign Exchange Gain/(Loss)` chart leaf + key + VERSION bump (Gate-1 APPROVED dec. 2)

Four code changes, mirroring how `WHT_PAYABLE` was added at Wave 8b (ADR 0021 §A5):

1. **`Account::FX_GAIN_LOSS`** — a new const beside `WHT_PAYABLE` (`Account.php:89`):
   ```php
   /** Where a settlement's realised exchange difference lands — one combined Income account carrying
    *  credits for gains and debits for losses, netting to the period's FX result (Gate-1 dec. 2).
    *  Resolved by key, never by code, exactly as TRADE_PAYABLES — a company may renumber its chart. */
   public const string FX_GAIN_LOSS = 'fx_gain_loss';
   ```
2. **`ChartTemplate::accounts()`** — insert after `4900 Other Income` (`ChartTemplate.php:125`), under the
   `4000 Income` heading (`:122`):
   ```php
   self::leaf('4910', 'Foreign Exchange Gain/(Loss)', AccountType::Income, parent: '4000', system: Account::FX_GAIN_LOSS),
   ```
   An **Income** leaf (dec. 2). `AccountType::Income` is credit-normal (`AccountType.php:56`), so a gain is a
   credit (raises income) and a loss is a debit (a contra against it) — one account that nets both ways, which
   is exactly the single-combined-account choice. New companies applying the template get it, keyed.
3. **`ChartTemplate::requiredSystemAccounts()`** — add the same leaf with no parent (as the others there,
   `ChartTemplate.php:160-175`):
   ```php
   // Added by Phase 6 Wave 9a. A settlement at a rate different from the booking rate posts a realised FX
   // line here, so a company that skipped the starter chart must still receive it — or its first foreign
   // payment fails to post.
   self::leaf('4910', 'Foreign Exchange Gain/(Loss)', AccountType::Income, system: Account::FX_GAIN_LOSS),
   ```
   This makes `ChartTemplateService::ensureSystemAccounts()` provision it on demand for an empty-chart company
   and as an idempotent safety net — the same status Trade Payables and WHT Payable hold.
4. **`ChartTemplate::VERSION`** — bump `'2026.09-lk-sme-4'` → `'2026.09-lk-sme-5'` (`ChartTemplate.php:40`),
   because the account set changed.

**Consequence to schedule:** the VERSION bump breaks the tests that pin the literal string. `grep` shows
exactly two hard-code `'2026.09-lk-sme-4'` — `tests/Feature/Accounting/WhtPayableSystemAccountTest.php:77` and
`tests/Feature/Accounting/TradePayablesSystemAccountTest.php:109` — and they move to `-5`. Three others assert
via the constant `ChartTemplate::VERSION` (`AccountingApiTest`, `ChartOfAccountsTest`,
`TradeReceivablesSystemAccountTest`) and follow automatically; the stage re-runs the full Accounting suite to
confirm, exactly as Wave 8b's `-3` → `-4` bump did.

## A6. The FX account backfill — CREATE, not stamp (Gate-2 PROPOSED; reproduce the Wave 8b scaffold)

`Foreign Exchange Gain/(Loss)` exists in **no** prior template, so — like `WHT Payable` before it — there is
nothing to stamp: the backfill must **create** the account in each existing company, or that company's first
foreign settlement fails to post (the plug has nowhere to land). `2026_09_10_000001…` reproduces
`2026_09_09_000001_provision_wht_payable_account.php` **verbatim in structure**, differing only in the account's
attributes:

**Reproduced exactly (the recurring zero-rows trap):**

- Runs inside `RowLevelSecurity::bypass(function () { … })`; migrations run as `asids_app` (NOBYPASSRLS) on a
  FORCED `accounts` table, so with no tenant published a data migration sees **zero** rows and reports success
  having created nothing (`…provision_wht_payable…:42`).
- `assertBypassEffective()` — refuses to proceed unless `SELECT asids_rls_bypassed()::int = 1`, cast in SQL
  (`…:142-150`).
- `assertNothingLeftBehind()` — after running, asserts **zero** existing companies still lack a `fx_gain_loss`
  account (`…:156-176`).
- **Raw SQL, never Eloquent** — `RowLevelSecurity::bypass()` suspends the database policy but not Eloquent's
  `TenantScope`, and `BelongsToTenant::creating` throws `NoActiveTenant` in a tenant-less migration (`…:26`).
- **Idempotent by key-absence** — skip any company that already has a live `fx_gain_loss` account (covers all
  prior versions `-1…-4`, re-run-safe, no `template_version` guard).
- **Code-collision guard** reproducing `ChartTemplateService::availableCode()`: prefer `4910`, else the first
  free `4910-1`, `4910-2`, … (`…:116-136`).
- **Per-company loop, raw `INSERT` per row** — a single `INSERT … SELECT` cannot compute a per-company
  available code (`…:44-90`).

**The FX-specific attributes (the only divergence from the WHT precedent):**

```
name             = 'Foreign Exchange Gain/(Loss)'
type             = 'income'
normal_balance   = 'credit'                    -- accounts_normal_balance_matches_type_check: income ⟹ credit
system_key       = 'fx_gain_loss'
is_postable      = true
is_system        = true
sort_order       = 4910
parent_id        = <resolved 4000 Income, or NULL>   -- "code = '4000' AND type = 'income' AND deleted_at IS NULL"
code             = <availableCode('4910')>
template_version = NULL                          -- created by a migration, not a template
```

Parent resolves to the company's `4000 Income` heading when present, else `parent_id = NULL` (a renumbered
company keeps a valid, flat system account). `down()` deletes only the postings-free accounts it created
(`system_key = 'fx_gain_loss' AND is_system = true AND NOT EXISTS (journal_lines)`), under the same bypass —
an FX account that has taken a posting is never hard-deleted (`…:93-109`).

## A7. Immutability & audit — no new migration needed (Gate-2 PROPOSED, realising dec. 9)

Everything dec. 9 asks for is **already** enforced, which is why 9a — unlike Wave 8b — adds no freeze migration:

- **The ledger overlay is frozen** by `asids_journal_lines_immutable()`, which refuses **any** UPDATE/DELETE on
  a posted line unconditionally (`2026_02_03_000003…:198-225`) — the overlay columns are covered without being
  named.
- **A posted bill's `currency_code` + `exchange_rate` are frozen** by name
  (`2026_09_02_000005_make_posted_bills_immutable.php:58-59`).
- **A posted payment's `currency_code` + `exchange_rate` are frozen** by name
  (`2026_09_03_000005_make_posted_payments_immutable.php:72-73`).

The audit trail is the append-only ledger itself: the realised FX is a posted journal line, immutable, dated,
and cited to its payment via the source document. No `auditOnly()` change is required — `currency_code` and
`exchange_rate` are set once at draft/record and then frozen; they never mutate to be audited.

---

# B. The ledger overlay — how a base amount and its transaction face travel together

## B1. `Money::convertedTo()` — the `base = round(txn × rate)` primitive (Gate-2 PROPOSED; the one shared-VO change)

`Money` deliberately throws on cross-currency arithmetic because "no exchange rate exists in the platform until
the FX phase" (`Money.php:37-42,353-360`). **This is that phase.** Given an explicit rate, the conversion is now
defined, and its natural home is `Money` — the one place that already does exact scaled-integer,
half-away-from-zero arithmetic. One new method:

```php
/**
 * This amount converted to another currency at an exchange rate, rounded to the target currency's precision.
 *
 * The FX-phase counterpart to the class's refusal to guess a cross-currency answer: with a caller-supplied
 * rate the answer is defined. `multipliedBy` computes the exact scaled product (keeping this currency's tag);
 * the magnitude is what the rate produced, so it is re-tagged to $currency and rounded to its precision —
 * the single definition of "base equivalent" (Gate-1 dec. 8).
 */
public function convertedTo(string $currency, string $rate, int $precision): self
{
    $converted = $this->multipliedBy($rate);      // Money.php:142-157 — exact, half away from zero, same tag
    return self::ofMinorUnits($converted->minorUnits, self::normaliseCurrency($currency))
        ->roundedTo($precision);                  // Money.php:240-252
}
```

Pure, immutable, float-free; reuses `multipliedBy` (whose factor regex already accepts a 10-dp rate,
`Money.php:144`) and `roundedTo`. `roundedTo` asserts `precision ≤ SCALE = 4` (`:242-243`) — the same guard the
ledger already relies on, and the reason the adjacent `currency_precision > 4` platform bug (requirements §7)
must not reach production before FX (Risk 6). The alternative — a re-tag-and-round expression inlined in each
posting map — is rejected (fork 4): it scatters the FX arithmetic across two maps and invites divergence, when
the value object is the correct and self-documenting home.

## B2. `JournalLineData` — an optional overlay, base amount unchanged (Gate-2 PROPOSED)

`JournalLineData` today is `accountId, ?Money debit, ?Money credit, ?branchId, ?description`
(`JournalLineData.php:18-24`), where `debit`/`credit` are **base-currency** Money. It gains one optional field —
a small readonly value object bundling the overlay, so the all-or-none shape is enforced by the type itself
(you either have the overlay or you don't, mirroring `journal_lines_currency_columns_check`):

```php
// New VO — Accounting/Application/DTOs (or Domain/ValueObjects):
final readonly class ForeignAmount
{
    public function __construct(
        public Money $amount,   // the transaction-currency amount (e.g. USD 1,000) — currency lives on the Money
        public string $rate,    // transaction → base, a positive numeric string (decimal(19,10))
    ) {}
}

// JournalLineData gains:
public ?ForeignAmount $foreign = null,   // null ⇒ base-currency line (overlay NULL), byte-for-byte today
```

`debit`/`credit` keep their exact meaning — the **base** amount and the side — so `amount()`, `isDebit()`,
`isOneSided()`, `isNegative()` (`JournalLineData.php:53-80`) and the whole balance check are untouched. The
overlay is **metadata the map computes alongside the base amount**, not an alternative source of truth:
`fromArray()` (`:29-48`) does not read it (interactive journals are base-currency; the FX-aware callers are the
two purchasing maps, which construct `JournalLineData` directly). The consistency `base = round(txn × rate)` is
a **construction guarantee** — the map builds both the base amount and the overlay from one `convertedTo` call
(§C, §D) — and is asserted by test, exactly as the maps already guarantee balance by construction rather than
by a CHECK.

## B3. `JournalService::writeLines()` — persist the overlay; the ONLY shared-path change (Gate-2 PROPOSED)

`writeLines()` (`JournalService.php:296-319`) is the single place a line is persisted. Today it rounds the base
`debit`/`credit` to `currency_precision` and saves (`:312-315`). It gains a persistence of the overlay when
present, and nothing else:

```php
$line->debit  = ($data->debit  ?? Money::zero($currency))->roundedTo($precision)->toDecimalString();
$line->credit = ($data->credit ?? Money::zero($currency))->roundedTo($precision)->toDecimalString();
$line->description = $data->description;

if ($data->foreign !== null) {                                 // FX line: record the transaction face
    $line->transaction_currency_code = $data->foreign->amount->currency;
    $line->transaction_amount        = $data->foreign->amount->toDecimalString();   // at SCALE=4
    $line->exchange_rate             = $data->foreign->rate;                          // decimal(19,10)
}
// else: the three columns stay NULL — base currency at rate 1, exactly as today.

$line->save();
```

That `if` is the entire change to the shared posting path. When `$data->foreign` is null — every existing
caller, and every base-currency document — the three columns are never touched and default NULL: **the base
path is byte-for-byte today**, which §H's broad regression proves across the whole ledger.
`assertPostable()`'s balance sum (`:182-208`) and `resolveAccounts()` (`:236-290`) do not read the overlay and
do not change. The base `debit`/`credit` the map supplies is already `round(txn × rate)` from `convertedTo`;
`writeLines`' own `roundedTo($precision)` is idempotent on it.

**Why not derive base from the overlay in `writeLines`?** Rejected: it would make `debit`/`credit` carry a
side-only meaning for FX lines, complicate the shared path, and still need a base-only branch for the FX plug
line (which has no overlay). Keeping `debit`/`credit` as the authoritative base amount, with the overlay as
persisted metadata, is the minimal, regression-safe change and the reason `assertPostable` needs no edit.

---

# C. Bill posting in foreign currency

## C1. `BillData` / `BillService` — carry the currency and the manual rate (Gate-2 PROPOSED, dec. 3)

`BillData` (`BillData.php:32-42`) gains two optional fields, both defaulting to "base currency":

```php
public ?string $currencyCode  = null,   // null ⇒ company base currency
public ?string $exchangeRate  = null,   // null ⇒ base (rate 1); a positive numeric string when foreign
```

mapped in `fromArray()` from `currency_code` / `exchange_rate`. `BillService::createDraft()` sets them where it
today hard-codes base (`BillService.php:92,100`):

```php
$bill->currency_code = $data->currencyCode ?? $company->base_currency_code;
$bill->exchange_rate = $bill->currency_code === $company->base_currency_code ? null : $this->assertRate($data->exchangeRate);
```

`assertRate()` refuses a missing/non-positive rate for a foreign bill (`BusinessRuleViolation`
`bill-foreign-rate-required` / `bill-rate-not-positive`), the readable message behind
`bills_exchange_rate_positive_check`. The rate is captured at draft (the bill's tax point is `bill_date`,
`create_bills_table.php:66`) and frozen at posting (§A7). `replaceLines()` already computes every total in
`$bill->currency_code` (`BillService.php:340`), so a USD bill's totals are USD with **no change** to the
calculator. `updateDraft` (`:129-179`) may change currency/rate while the bill is a draft, re-running
`replaceLines` in the new currency; the duplicate-supplier-number guard is currency-agnostic.

## C2. `BillPostingMap` — base at the booking rate, overlay on each line, payable at face (Gate-2 PROPOSED, dec. 5, 8)

`BillPostingMap::for()` (`BillPostingMap.php:63-82`) produces ordered expense debits, input-VAT debits, and one
payable credit, today built directly as base Money because `currency_code = base`. It gains the rate and
converts:

- Let `$txnCcy = $bill->currency_code`, `$base = $bill->company->base_currency_code`, `$rate =
  $bill->exchange_rate ?? '1'`, `$precision = $bill->company->currency_precision`.
- **Payable credit — the face value** `payableBase = Money::of($bill->total, $txnCcy)->convertedTo($base,
  $rate, $precision)` = `round(total × rate)`, with overlay `ForeignAmount(Money::of(total, txnCcy), rate)`
  (`payableLines`, `:114-130`).
- **Debit lines — their transaction amount, converted, summing exactly to the payable.** Each expense/input-VAT
  line's base is its `txn.convertedTo($base, $rate, $precision)` with overlay `ForeignAmount(txn, rate)`
  (`expenseLines`/`inputTaxLines`, `:138-241`). Because a bill posts at **one** rate, `Σ round(txn_i × rate)`
  can differ from `round(total × rate)` by a sub-cent; that residue is reconciled onto the debit lines so the
  set sums **exactly** to `payableBase`. The recommended mechanism is `Money::allocate($payableBase, [txn_i
  minor units])` for the common all-same-sign case (its largest-remainder split is exactly the "distribute the
  cent deterministically" tool, `Money.php:159-232`); a bill with a net-credit line (a mid-bill correction,
  `:164-172`) falls back to a single rounding-unit adjustment on the largest-magnitude debit. **The payable is
  the face value `round(total × rate)`, recomputable from `(total, rate)` alone** — this is what lets
  settlement zero the payable statelessly (§D3, the reason for the choice; fork 3).

For a **base-currency bill** (`exchange_rate` NULL ⇒ `$rate = '1'`, `$txnCcy = $base`): `convertedTo($base,
'1', $precision)` is an identity re-tag-and-round, `Σ debits = total` exactly (`bills_total_check`), `allocate`
returns the inputs unchanged, and every `foreign` overlay is **null** — the map's output is byte-for-byte
today. A bill entry carries **no** FX line: a bill is a booking, not a settlement; realised FX arises only when
the payable is discharged at a different rate (§D).

Nothing in the balance guarantee weakens: `Σ debit base = payableBase = credit`, and every base amount is
already rounded, so the deferred balance trigger passes for any rate.

---

# D. Realised FX on settlement — the crux (Gate-1 APPROVED dec. 1, 5, 7, 8)

## D1. `PaymentData` / `PaymentService` — currency, rate, and the same-currency refusal (Gate-2 PROPOSED)

`PaymentData` (`PaymentData.php:28-42`) gains two optional fields, symmetric to `BillData`:

```php
public ?string $currencyCode = null,   // null ⇒ base; when set, must equal every settled bill's currency
public ?string $exchangeRate = null,   // null ⇒ base (rate 1); a positive numeric string when foreign
```

`PaymentService::record()` today hard-codes `currency_code = base_currency_code` and `exchange_rate = null`
(`PaymentService.php:296-297`) and computes WHT in `$ccy = $company->base_currency_code` (`:158`). Under FX,
`$ccy` becomes the **payment's** currency (which equals the settled bills' currency, so the WHT arithmetic over
`bill->subtotal`/`bill->total` stays same-currency, `:186-205`). One new pre-transaction refusal, placed with
the per-bill validation (after `:151`):

```php
// 7a. Same-currency settlement (dec. 7): the payment's currency must equal every settled bill's currency.
$paymentCcy = $data->currencyCode ?? $company->base_currency_code;
foreach ($data->allocations as $allocation) {
    $bill = $bills[$allocation->billId];
    if ($bill->currency_code !== $paymentCcy) {
        throw PaymentCannotBeRecorded::currencyMismatch($bill->number ?? $allocation->billId, $bill->currency_code, $paymentCcy);
    }
}
$rate = $paymentCcy === $company->base_currency_code ? '1' : $this->assertRate($data->exchangeRate);
```

`assertRate()` mirrors §C1. The header is then stamped `currency_code = $paymentCcy`, `exchange_rate =
$paymentCcy === base ? null : $data->exchangeRate` (replacing `:296-297`). Every existing invariant is
unchanged: `amount = Σ allocations` and the deferred full-allocation trigger operate in the payment currency;
steps 11–17 (lock, re-validate under lock, number, post, allocation rows, gross bill movement, commit,
`:239-337`) are structurally identical — only the `$lines` handed to `postNew` differ (built at step 9). A
**base-currency payment** (`currencyCode` null) is byte-for-byte Waves 8a/8b: `$rate = '1'`, no overlay, no FX
line.

## D2. `PaymentPostingMap` — the shape the map must now produce (Gate-2 PROPOSED, dec. 5, 8)

The Wave 8b map produces `Dr Trade Payables (gross) = Cr Bank (net) + Cr WHT Payable (wht)`
(`PaymentPostingMap.php:59-84`). Under FX it produces, in base currency:

```
Dr Trade Payables   (one line PER settled bill: base relieved at THAT bill's booking rate; overlay = alloc_i @ bill_rate_i)
Dr FX Loss  /  Cr FX Gain   (ONE plug line, base only, no overlay — makes the base entry balance)
    Cr Bank / Cash   (base = round(net_txn × payment_rate); overlay = net_txn @ payment_rate)
   [Cr WHT Payable    (base = round(wht_txn × payment_rate); overlay = wht_txn @ payment_rate) — only when withholding]
```

- **Dr Trade Payables — one line per settled bill** (fork 6). Each settled bill was booked at its own stored
  rate, so a merged line would have no single honest overlay rate; per-bill lines each satisfy `base =
  round(alloc × bill_rate)` with overlay `(alloc_i, bill_rate_i)`, and make the per-bill realised FX
  computable and auditable. The subledger already records per-bill detail (`payment_allocations`), so per-bill
  GL lines are consistent with it. `payableAccountFor()` (`:94-110`) resolves the one Trade Payables account by
  key, unchanged.
- **Cr Bank** — one line, `base = round(net_txn × payment_rate)` where `net_txn = amount_txn − wht_txn`. The
  cash really leaves the bank as one amount at one rate; this is economically exact. `assertBankAccount()`
  (`:147-163`) unchanged.
- **Cr WHT Payable** — present only when withholding, `base = round(wht_txn × payment_rate)` (§D4).
  `whtPayableAccountFor()` (`:119-135`) unchanged.
- **The FX plug — ONE line, resolved only when non-zero.** Let `D = Σ base debits (the per-bill reliefs)` and
  `C = Σ base credits (Bank net + WHT)`. The plug `= C − D`:
  - `C > D` ⇒ **Dr FX Loss** `= C − D` (more base paid out than relieved — the rate moved against us).
  - `D > C` ⇒ **Cr FX Gain** `= D − C`.
  - `C = D` ⇒ **no FX line** (rates match, or base currency) — `AC-2.4`, and the reason the FX account is
    resolved only when the plug is non-zero, so a company that never transacts abroad never needs it (mirrors
    how `WHT Payable` is resolved only when `wht > 0`).

  The plug guarantees the base entry balances **to the cent for any rates and any rounding**; the economic
  realised FX (`Σ alloc_i × (payment_rate − bill_rate_i)`) equals the plug up to sub-cent rounding, and the
  plug is authoritative for balance.

**New signature.** `for()` must receive, per settled bill, the base relieved and its overlay; the payment rate;
and the withholding. The service computes the per-bill relief under the bill lock (it needs `bill.amount_paid`,
§D3) and hands the map a list; the map resolves accounts, converts the credit side at the payment rate, and
plugs FX:

```php
public function for(
    Company $company,
    Account $bankAccount,
    array $reliefs,          // list<PayableRelief>: per bill { Money baseRelieved; ForeignAmount overlay }
    Money $netCashTxn,       // amount_txn − wht_txn, in the payment currency
    Money $whtTxn,           // withheld, in the payment currency (Money::zero when none)
    string $paymentRate,     // txn → base, '1' for a base-currency payment
    int $precision,
    Supplier $supplier,
    ?string $branchId = null,
): array;

public function fxGainLossAccountFor(Company $company): Account;   // NEW — mirrors payableAccountFor
```

`fxGainLossAccountFor()` resolves `Account::FX_GAIN_LOSS`, asserting present / `AccountType::Income` /
postable, with two new `PaymentCannotBePosted` factories `withoutFxAccount(companyName)` →
`fx-account-missing` and `fxAccountWrongType(account)` → `fx-account-wrong-type`, and reuses `accountNotPostable`
with role `'FX gain/loss'` — the exact pattern of `payableAccountFor`/`whtPayableAccountFor`
(`:94-135`). A base-currency payment passes `reliefs` with overlay null, `paymentRate = '1'`, plug zero — the
map emits the Wave-8b shape and never resolves the FX account.

## D3. The payable relief, and why the payable zeroes — the central rounding decision (fork 2)

The payable was **credited at billing** at the bill's face value `round(total_txn × bill_rate)` (§C2). To zero
it in base when the bill is fully paid, settlement must **debit exactly that** across all payments. The relief
basis is the sharp choice, because it trades against `AC-2.4`:

- **(a) Simple** — `relief_i = round(alloc_i × bill_rate)`. Satisfies `AC-2.2`/`AC-2.4` **literally**: when
  `payment_rate = bill_rate`, `Cr Bank = round(alloc × rate) = relief`, so the FX plug is **exactly zero**.
  *Risk:* on a foreign bill settled by several partial payments, `Σ round(alloc_i × bill_rate)` can miss
  `round(total × bill_rate)` by a sub-cent, leaving a **residual payable** for that bill on the AP control
  account after it is fully paid — a visible defect on the trial balance and the aging.
- **(b) Incremental** — `relief_i = round((paid_before + alloc_i) × bill_rate) − round(paid_before ×
  bill_rate)`, using `bill.amount_paid` (pre-update, under the step-11 lock, `PaymentService.php:243-249`). This
  **telescopes**: over full settlement `Σ = round(total × bill_rate) − round(0) = round(total × bill_rate)`, so
  the payable zeroes **exactly**. *Risk:* on a *drifted partial* settled at exactly the matching rate, the plug
  can be ±1 minor unit rather than 0 — a slight deviation from `AC-2.4`'s letter (its spirit — no FX on a full
  or single settlement at a matching rate — is fully honoured; that case gives exactly `round(total × (pr −
  br)) = 0`).

There is **no** basis that is both `AC-2.4`-exact on every partial *and* zero-out-exact — the rounding
non-linearity forbids it. **I recommend (b) incremental**, because a residual payable on a fully-settled
foreign bill is a permanent, visible, trust-eroding defect, whereas (b)'s deviation is a ±1-minor-unit FX on an
edge-of-an-edge (a partial settlement at an exactly matching rate that has already drifted) that is
economically a rounding effect and self-nets. **But `AC-2.4` is an approved acceptance criterion, so I will not
override it silently — this is fork 2 for Gate 2.** If strict `AC-2.4` is preferred, choose (a) and accept the
documented residual (or clear it in the deferred 9c period-end true-up). Choosing (b) means `AC-2.4` is
restated as "zero FX on a full or single settlement at a matching rate; at most one minor unit on a drifted
partial at a matching rate."

This is also why §C2 credits the payable at **face** (`round(total × rate)`), not at the plug (`Σ round(debit ×
rate)`): only the face value is recomputable from `(total, rate)` at settlement time, so (b) telescopes to it
with **no stored payable-base and no extra query** — a stateless zero-out (fork 3).

## D4. WHT × FX — handled coherently, because the two compose (Gate-2 PROPOSED; requirements §4)

A foreign bill paid with withholding is a real combination (a Sri Lankan importer paying an overseas service
provider, withholding tax remitted to the IRD in LKR). It is **in scope for 9a and handled**, not deferred,
because WHT and FX are orthogonal and compose with no conflict:

- WHT is computed **exactly as Wave 8b** — a percentage of each settled bill's pre-VAT share
  (`PaymentService.php:186-205`) — but now in the **payment currency** (`$ccy` = payment currency = bills'
  currency), so `wht_txn` is a foreign amount (e.g. USD 50) and the gross-settlement invariant (dec. 3) is
  untouched: `bill.amount_paid` still moves by the **gross** allocation in the transaction currency
  (`:323-333`).
- WHT is a **credit-side reclassification at the payment rate**: `Cr WHT Payable = round(wht_txn ×
  payment_rate)` and `Cr Bank = round(net_txn × payment_rate)` (`net_txn = amount_txn − wht_txn`). Both are at
  the *same* rate, so the split does not disturb the FX, which arises entirely on the **gross** payable relieved
  at bill rate versus settled at payment rate.

Worked example — bill USD 1,000 @ 300 (service value, no VAT), paid USD 1,000 @ 310 withholding 5% (USD 50):

```
Dr Trade Payables   300,000     (relieve USD 1,000 @ bill 300 — gross, the bill is settled in full)
Dr FX Loss           10,000     (plug: credits 310,000 − debits 300,000)
    Cr Bank                294,500   (net USD 950 @ payment 310)
    Cr WHT Payable          15,500   (withheld USD 50 @ payment 310, owed to the IRD in LKR)
```

Balances (310,000 = 310,000); the realised FX is the same 10,000 as the no-WHT case, on the gross USD 1,000 —
demonstrating the composition. `supplier_payments.wht_amount` snapshots `wht_txn` (USD 50, transaction
currency, consistent with `amount`); `supplier_payments_wht_within_amount_check` holds in that currency. The
`wht_rate` snapshot rule (store only when `wht_amount > 0`, ADR 0021 fork 5) is unchanged.

---

# E. Build stages (test-first, RED → GREEN, independently reviewable)

Each stage is an independent RED→GREEN cycle: QA writes the failing tests, the engineer implements to green. No
stage folds another's reviewer gate. Ordered so each stage's dependencies are already green. Stage 2 carries
the ledger-wide blast radius and its broad regression; it is deliberately separate.

| Stage | Deliverable | Files (create unless noted) | Test-first artefact | Green when |
| --- | --- | --- | --- | --- |
| **1. `Money::convertedTo`** | The `base = round(txn × rate)` primitive. | **edit** `Money.php` | `MoneyConvertedToTest`: `USD 1000 @ 300 → LKR 300000`; a 10-dp rate; `@ '1'` is an identity re-tag+round; rounds half away from zero to the target precision; `precision > SCALE` refused. | conversion exact for every rate |
| **2. Overlay on the ledger + drop the shared CHECK** | `ForeignAmount` VO; `JournalLineData.foreign`; `JournalService::writeLines` persistence; drop `journal_lines_single_currency_until_fx_phase`. | **edit** `JournalLineData.php`, `JournalService.php`; new `ForeignAmount`; `…Accounting/…/2026_09_10_000002_allow_foreign_currency_on_journal_lines.php` | `JournalLineOverlayTest` + the **broad base-path regression** (§H): an FX line round-trips the three overlay columns; a NULL-overlay line stores three NULLs; **every non-purchasing posting path — manual JV, reversal, sales invoice, year-end close — still writes NULL overlay and is byte-for-byte unchanged**; the balance check still refuses an unbalanced base entry; the dropped CHECK no longer refuses a (test-only) overlay row while `currency_columns_check` still refuses a malformed one. | overlay persists; base path provably unchanged ledger-wide |
| **3. FX Gain/Loss account + template + backfill** | `Account::FX_GAIN_LOSS`; `ChartTemplate` leaf + `requiredSystemAccounts` + VERSION `-5`; create-not-stamp backfill. | **edit** `Account.php`, `ChartTemplate.php`; `…Accounting/…/2026_09_10_000001_provision_fx_gain_loss_account.php` | `FxGainLossSystemAccountTest` (shape `WhtPayableSystemAccountTest`): template provisions `4910` keyed Income/credit-normal for a new company; `ensureSystemAccounts()` creates it for an empty-chart company; **backfill idempotent**, **creates for a legacy `-1…-4` company**, **`assertBypassEffective` trips without bypass**, **`assertNothingLeftBehind` trips on a partial run**, **collision → `4910-1`**, parent `4000` or NULL; VERSION `-5` and the two literal-pinned tests updated. | account provisioned everywhere; backfill proven effective |
| **4. Foreign bills** | `BillData` currency/rate; `BillService` create/update; `BillPostingMap` base-at-rate + payable face + overlay; drop `bills` CHECK, add rate-positive CHECK. | **edit** `BillData.php`, `BillService.php`, `BillPostingMap.php`; `…Purchasing/…/2026_09_10_000003_allow_foreign_currency_on_bills.php` | `ForeignBillPostingTest` (extend `BillPostingMapTest`): USD 1,180 @ 300 books LKR 354,000 with per-line overlay; **base-currency bill byte-identical to today, overlay NULL**; `Σ debits = payable` for a many-decimal rate (the reconciliation); a foreign rate ≤ 0 refused; posted bill's rate frozen. | foreign bills post, base path unchanged |
| **5. Foreign payments + realised FX** | `PaymentData` currency/rate; same-currency refusal; per-bill relief; `PaymentPostingMap` FX plug + FX-account resolution; drop `supplier_payments` CHECK, add rate-positive CHECK. | **edit** `PaymentData.php`, `PaymentService.php`, `PaymentPostingMap.php`, `PaymentCannotBePosted.php`, `PaymentCannotBeRecorded.php`; `…Purchasing/…/2026_09_10_000004_allow_foreign_currency_on_supplier_payments.php` | `RealisedFxPaymentTest` (extend `RecordPaymentTest`): the worked example @ 310 (Dr FX Loss) and @ 290 (Cr FX Gain); **rates match ⇒ zero FX, no FX line**; **partial settlement realises FX only on the portion**; a full multi-partial settlement **zeroes the payable exactly** (fork-2 proof); balance-in-base for every rate; cross-currency settlement refused; FX account missing/wrong-type/not-postable named; **base-currency payment byte-identical to 8a/8b, FX account not resolved**. | realised FX correct; base path unchanged |
| **6. WHT × FX** | The foreign withholding payment composed (no new schema). | **edit** none beyond stage 5 (verification-heavy) | `ForeignWhtPaymentTest`: the §D4 worked example (Dr TP gross + Dr FX Loss = Cr Bank net + Cr WHT, all balanced); WHT computed on the foreign pre-VAT base; `wht_amount` snapshot in the payment currency; **base-currency WHT payment unchanged from 8b**. | foreign WHT composes; base WHT unchanged |

---

# F. Test strategy (QA writes tests before implementation)

Shaped by the Accounting posting suites and the Wave 8a/8b purchasing suites, extended for FX. The three pillars
the requirements name (§7 of the ADR brief):

- **The broad base-currency regression (Stage 2, the blast-radius proof).** Dropping the shared
  `journal_lines` CHECK affects **every** posting path. The regression exercises a manual journal voucher, a
  reversal, a sales invoice, a year-end close, a base-currency bill and a base-currency payment, and asserts
  each writes **NULL** overlay (`transaction_currency_code IS NULL` on every line) and produces the **same**
  ledger it does today. This is the direct evidence that only the FX-aware purchasing maps ever populate the
  overlay, since a CHECK no longer enforces it.
- **The realised-FX worked examples (Stage 5).** The rate-up case (`@ 310 → Dr FX Loss 10,000`), the rate-down
  case (`@ 290 → Cr FX Gain 10,000`), **rates-match ⇒ zero FX and no FX line**, **partial settlement realises
  FX only on the portion settled**, and a **multi-partial full settlement that zeroes the payable exactly**
  (the fork-2 invariant). Each asserts the exact journal lines and that `Σ debit = Σ credit` in base.
- **Balance-in-base for every rate (Stages 2, 4, 5).** A property-style sweep over rates (integer, many-decimal,
  1) asserting the deferred balance trigger passes and the base entry balances to the cent, with the FX line as
  the plug — for bills (no FX line, payable = face) and payments (FX plug).

Plus the standard guards: `MoneyConvertedToTest` (exact conversion, precision guard); the
`FxGainLossSystemAccountTest` backfill-effectiveness suite (idempotent, legacy company, both RLS assertions,
collision, parent) mirroring `WhtPayableSystemAccountTest`; schema-CHECK mutation tests that the new
`exchange_rate_positive` CHECKs **non-vacuously** reject a crafted `exchange_rate = 0` row on `bills` and
`supplier_payments`; the cross-currency-settlement refusal; and the two literal VERSION pins updated to `-5`.

---

# Alternatives considered

- **Track a foreign bill's balances in base, not transaction currency** (fork 1, rejected). It would make
  `amount_due` a moving base figure that changes with no economic event, break the "you owe USD 1,000"
  subledger, and force a base-currency re-derivation of the allocation cap. Transaction-currency balances match
  what the company owes and what the existing calculator already computes.
- **Derive the base amount from the overlay inside `writeLines`** (rejected, §B3). It gives `debit`/`credit` a
  side-only meaning for FX lines, complicates the shared path, and still needs a base-only branch for the FX
  plug. Keeping `debit`/`credit` as the authoritative base amount and the overlay as metadata is the minimal,
  regression-safe change.
- **Inline the `txn × rate` re-tag in each posting map** instead of `Money::convertedTo` (fork 4, rejected). It
  scatters FX arithmetic across two maps and invites divergence; the value object is the correct home, and its
  own docstrings anticipate "the FX phase".
- **One merged Dr Trade Payables line at a blended rate** (fork 6, rejected). Bills booked at different rates
  have no single honest overlay rate; per-bill relief lines each satisfy `base = round(alloc × bill_rate)` and
  expose the per-bill FX, consistent with the per-bill subledger.
- **Credit the payable at the plug (`Σ round(debit × rate)`) rather than face** (fork 3, rejected). The plug is
  not recomputable from `(total, rate)`, so settlement zero-out would need the stored payable base or an extra
  query; face value keeps settlement stateless.
- **Simple per-allocation relief `round(alloc × bill_rate)`** (fork 2, kept as the alternative). `AC-2.4`-exact
  but risks a residual payable on multi-partial foreign bills; incremental relief is recommended for the
  guaranteed zero-out.
- **Split gain/loss accounts** (rejected by Gate-1 dec. 2). A single combined Income account netting both ways
  is the approved and simpler SME choice.
- **Scope foreign WHT out of 9a** (rejected, §D4). It composes cleanly — WHT is a credit-side reclassification
  at the payment rate, FX is on the gross at the rate difference — so leaving a real combination undefined
  would be the worse choice.
- **A DB CHECK/trigger for same-currency settlement** (rejected). A CHECK cannot join across
  `payment_allocations → bills`; the service refusal under the bill lock plus the `Money` cross-currency throw
  is the authority, the same "service message + database backstop" split used throughout.

# Risks

- **The shared `journal_lines` CHECK is ledger-wide (Risk 1).** Dropping
  `journal_lines_single_currency_until_fx_phase` removes the guarantee for **every** posting path, leaving it a
  code + test guarantee. Mitigated by Stage 2's broad base-path regression across sales, journals, reversal,
  close, bills and payments, and by `journal_lines_currency_columns_check` remaining the shape authority.
- **Rounding — the base entry must balance to the cent (Risk 2).** Independent per-line `round(txn × rate)`
  need not sum to `round(total × rate)`. Mitigated by making the bill's payable the face plug of its debits
  (§C2) and the payment's FX line the plug of the base entry (§D2); the deferred balance trigger is the
  authority.
- **The payable must return to exactly zero on a fully-paid foreign bill (Risk 3, fork 2).** Simple relief can
  leave a residual. Mitigated by the recommended incremental relief telescoping to the billed face value; the
  `AC-2.4` tension is surfaced as fork 2 for Gate 2, not decided silently.
- **The FX backfill creates, not stamps (Risk 4).** No prior template defined `4910`. Mitigated by reproducing
  the Wave 8b `WHT Payable` scaffold verbatim (bypass + both assertions + collision + parent + idempotency by
  key-absence) and its full test suite.
- **VERSION bump breaks pinned tests (Risk 5).** Two tests hard-code `-4`; the bump updates them to `-5`, as
  Wave 8b's `-3` → `-4` did — a scheduled edit.
- **The adjacent `currency_precision > 4` platform bug (Risk 6, requirements §7).** `Money::roundedTo` asserts
  `precision ≤ SCALE`, so a company mis-configured with precision > 4 would break FX rounding. Recommend
  landing that fix before or during 9a; out of scope to fix here, flagged so it is deliberate.
- **WHT × FX snapshot semantics (Risk 7).** `wht_amount` moves from a base figure (8b, where currency was
  always base) to a transaction-currency figure. For base-currency payments this is identical; for foreign ones
  it is consistent with `amount`. Mitigated by the same-currency rule keeping all payment arithmetic in one
  currency and by the §D4 regression that base-currency WHT is unchanged.

# Consequences

- The purchasing posting paths gain foreign-currency capability; the ledger stays in base currency, the overlay
  rides as traceability, and every base-currency document and every other posting path is unchanged.
- The chart gains its fifth system account, `Foreign Exchange Gain/(Loss)` (Income), the second added by a
  **create** backfill; `ChartTemplate::VERSION` reaches `-5`; `ensureSystemAccounts()` now provisions it.
- `Money` gains its first cross-currency operation, `convertedTo` — the FX-phase counterpart to its long-stated
  refusal to guess a rate.
- Realised FX is recognised on settlement as an immutable, audited journal line; the trial balance and ledger
  stay in base currency (reporting unchanged). Foreign balances sit in `bills`/`supplier_payments` in their
  transaction currency, ready for the deferred slices.
- The named later slices land on ready targets: foreign **sales** (9b) mirrors this on `sales_invoices` and
  drops its own `single_currency` CHECK; **unrealised/period-end** FX (9c) revalues the open foreign balances
  this slice leaves in the subledger and can absorb any fork-2 residual; a **rate table** (9d) replaces the
  manual per-document rate with a lookup, changing only where the rate comes from.

---

# Gate 2 — items to confirm, and the UNRESOLVED forks

**Confirm the (Gate-2 PROPOSED) shapes:**

1. **Schema (§A2–A4):** drop the three `*_single_currency_until_fx_phase` CHECKs; add
   `exchange_rate IS NULL OR exchange_rate > 0` on `bills` and `supplier_payments`; keep
   `journal_lines_currency_columns_check` as the shape authority; **no** new columns, **no** freeze migration
   (currency/rate already frozen).
2. **FX account (§A5–A6):** `4910 Foreign Exchange Gain/(Loss)`, `Account::FX_GAIN_LOSS = 'fx_gain_loss'`,
   Income leaf under `4000`, in `accounts()` **and** `requiredSystemAccounts()`; VERSION `-4` → `-5`;
   create-not-stamp backfill reproducing the Wave 8b scaffold.
3. **Overlay (§B):** `Money::convertedTo`; `JournalLineData.foreign` (`ForeignAmount`); `writeLines` persists
   the three columns when present and NULL otherwise; `assertPostable`/balance untouched.
4. **Bill posting (§C):** `BillData` currency/rate; payable credit at face `round(total × rate)`; debit lines
   converted with the residue reconciled so `Σ debits = payable`; overlay on each line; base bill unchanged.
5. **Payment posting (§D2, D4):** per-bill Trade Payables relief lines; Cr Bank/Cr WHT at the payment rate; one
   FX plug line resolved only when non-zero; the same-currency refusal; foreign WHT composed.
6. **Immutability/audit (§A7):** already enforced; no new migration.

**UNRESOLVED — the human must decide these at Gate 2 (I will not decide them silently):**

- **Fork 1 (balance-tracking currency, §A3):** hold a foreign bill's/payment's balances (`amount_due`,
  allocations, `amount`, `wht_amount`) in the **transaction currency** — my recommendation, with base derived
  in the ledger — or in base currency? *Recommend transaction currency* (the subledger states what you owe; the
  existing calculator already computes in `currency_code`).
- **Fork 2 (payable-relief rounding, §D3):** **incremental** relief `round(cum × br) − round(prev × br)` — my
  recommendation, guaranteeing a fully-paid foreign bill's payable zeroes exactly, at the cost of a possible
  ±1-minor-unit FX on a *drifted partial settled at a matching rate* (a slight restatement of `AC-2.4`) — or
  **simple** relief `round(alloc × br)`, which keeps `AC-2.4` exact but can leave a sub-cent residual payable?
  *Recommend incremental.* This one touches an approved acceptance criterion, which is why it is here.
- **Fork 3 (bill payable basis, §C2):** credit the payable at **face** `round(total × rate)` — my
  recommendation, which makes settlement zero-out stateless — with the debit residue reconciled onto the debit
  lines, or credit it at the **plug** `Σ round(debit × rate)` (debits exact per line, settlement stateful)?
  *Recommend face.* (Coupled to fork 2: incremental relief needs the face value.)
- **Fork 4 (`Money::convertedTo`, §B1):** add the one conversion method to `Money` — my recommendation — or
  inline the re-tag-and-round in each posting map? *Recommend the method.*
- **Fork 5 (foreign WHT scope, §D4):** **handle** the foreign withholding payment in 9a — my recommendation,
  since it composes cleanly — or **scope it out** with a clear "foreign + WHT deferred" refusal? *Recommend
  handle it.*
- **Fork 6 (Trade Payables line granularity, §D2):** **one relief line per settled bill** (each with its
  booking-rate overlay) — my recommendation — or one merged line? *Recommend per-bill.*

---

*Prepared by the Solution Architect (Phase 6 / Wave 9a). Design only — no production code written, nothing
committed, nothing built. Build begins after Gate 2 approval, on this ADR, verbatim; any implementation
discovery that would change a decision above returns to Gate 2. Autonomy ends at PR; production (Gate 3) needs
explicit human approval.*

## Gate 2 decision — APPROVED 2026-09-11

The human approved the architecture **as proposed**, confirming all six (Gate-2 PROPOSED) shapes and
resolving the six forks:

- **Fork 1 — transaction-currency balances.** A foreign bill's/payment's money columns are held in the
  transaction currency; base is derived in the ledger at the stored rate.
- **Fork 2 — incremental payable relief** (`round(cum × br) − round(prev × br)`): a fully-paid foreign bill's
  payable zeroes exactly. **`AC-2.4` is restated** as "zero FX on a full or single settlement at a matching
  rate; at most one minor unit on a drifted partial at a matching rate."
- **Fork 3 — credit the payable at face** `round(total × rate)`, debit residue reconciled onto the debit
  lines (enables fork 2's stateless zero-out).
- **Fork 4 — add `Money::convertedTo()`.**
- **Fork 5 — handle foreign WHT in 9a** (WHT and FX compose; §D4).
- **Fork 6 — one Trade Payables relief line per settled bill** (each with its booking-rate overlay).

Build proceeds strictly within this ADR (6 stages, test-first), Stage 2 carrying the ledger-wide base-path
regression. Any implementation discovery that would change a decision returns to Gate 2. Autonomy ends at PR;
production (Gate 3) needs explicit human approval.
