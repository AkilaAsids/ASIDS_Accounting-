# Phase 6 — Multi-currency / Foreign Exchange (FX) — Requirements

- **Status:** Gate 1 — APPROVED 2026-09-10
- **Author:** Delivery Manager (acting BA)
- **Date:** 2026-09-10
- **Branch:** a new `feature/phase6-fx-*` branch (stacked on the Phase 5 line, or off `main` once the Phase 5
  PRs merge — see §7).
- **Phase boundary:** this is the Phase 5 → Phase 6 crossing the human asked to be checked in on before it is
  crossed. It is deliberately presented as a **scoping** Gate 1: FX is large, and the first decision is how
  much of it the first wave carries.

## 0. What already exists — FX was designed in from day one

This phase does not build multi-currency from nothing. The schema was shaped for it and gated off behind
CHECK constraints that "the FX phase drops" (fourteen references in the code, `ROADMAP.md:330-335`):

- **The ledger already has the right shape.** `journal_lines` carries `debit`/`credit` in the company's
  **base currency always**, plus a nullable overlay — `transaction_currency_code`, `transaction_amount`,
  `exchange_rate` (`2026_02_03_000003_create_journal_lines_table.php:78-86`). NULL overlay = "base currency
  at rate 1". `journal_lines_currency_columns_check` already enforces all-three-present-or-all-absent with a
  positive rate; `journal_lines_single_currency_until_fx_phase` (`:123`) is the one CHECK that currently pins
  the overlay to NULL. **This is the standard functional-currency GL design** (IAS 21): the general ledger is
  kept in one functional/base currency; the transaction currency is recorded alongside for traceability.
- **The documents already carry currency + rate.** `bills`, `sales_invoices`, and `supplier_payments` each
  have `currency_code` + a nullable `exchange_rate` held NULL by their own
  `*_single_currency_until_fx_phase` CHECK (`create_bills_table.php:73,184`;
  `create_sales_invoices_table.php:68,179`; `create_supplier_payments_table.php:75,131`).
- **`Money` is functional-currency-safe.** It stores integer minor units at `Money::SCALE = 4` and carries an
  ISO-4217 currency tag; rounding to `companies.currency_precision` is already defined (`Money.php:20-50`).
- **Company base currency exists.** `companies.base_currency_code` + `currency_precision`
  (`create_companies_table.php:49-53`).
- **What does NOT exist yet:** a **Foreign Exchange Gain/(Loss) account** (the chart has no FX account — the
  nearest is `4900 Other Income`); any **exchange-rate storage/feed**; and any code that reads or writes the
  overlay or realises an FX difference.

So the phase's job is to **turn on** the overlay coherently and add the gain/loss mechanics — not to
re-plumb the ledger.

## 1. Objective

Let a company transact with a supplier (and, in a later slice, a customer) in a currency other than its base
currency: record the document in its **transaction currency** at an **exchange rate**, post the general
ledger in **base currency** at that rate, and — when the document is settled at a **different** rate —
recognise the **realised foreign-exchange gain or loss** the rate movement produced.

Worked example (base LKR, a USD bill):

```
Bill: USD 1,000 @ 300.00  →  booked  Dr Expense 300,000  Cr Trade Payables 300,000   (LKR, base)
Pay:  USD 1,000 @ 310.00  →  cash out is USD 1,000 = LKR 310,000, but the payable was booked at 300,000
      Settlement:  Dr Trade Payables 300,000
                   Dr FX Loss           10,000     (the rate moved against us)
                       Cr Bank                 310,000
```

The ledger stays in LKR throughout; the USD amounts ride as the transaction overlay; the LKR 10,000 is a
**realised** loss the moment the payment posts.

## 2. Why this is a SCOPING Gate 1 — FX is several waves, not one

Multi-currency touches the ledger (shared by every module), both trade documents, settlement, and
period-end. Delivering it all at once would be the scope-discipline failure the gate policy exists to
prevent. The natural decomposition:

| Slice | Contents | Recommendation |
| --- | --- | --- |
| **9a — Foreign purchasing + realised FX (RECOMMENDED FIRST)** | Foreign-currency **bills** and **supplier payments**; the ledger overlay turned on for the purchasing posting paths; **realised** FX gain/loss on payment; the FX gain/loss account; drop the CHECKs on `journal_lines` + `bills` + `supplier_payments`. | **This wave.** Builds directly on Waves 7/8 (bills + payments), where settlement-at-a-different-rate FX naturally arises. |
| **9b — Foreign sales + receipts** | The mirror for `sales_invoices` (and receipts, once built); drop the `sales_invoices` CHECK. | Later slice. |
| **9c — Unrealised FX / period-end revaluation** | Revalue open foreign balances (payables/receivables) at the period-end rate; post unrealised gain/loss; reverse next period. | Later slice — significant, and needs a rate at period-end. |
| **9d — Rate management** | A stored `exchange_rates` table + rate lookup/feed, replacing per-document manual rates. | Later slice. |
| **Deferred beyond Phase 6** | Presentation-currency translation (reporting in a currency other than base), multi-currency bank reconciliation, cross-currency settlement (paying a USD bill from an EUR account). | Not this phase. |

**The first Gate-1 decision (§3.1) is whether 9a is the right first wave, or whether the human wants a
different first slice** (e.g. ledger-only, or purchasing+sales together).

## 3. Gate-1 decisions needed (the human decides; my recommendation follows each)

1. **First-wave scope.** Take **9a — foreign purchasing + realised FX** as the first FX wave (my
   recommendation), or a different slice (ledger-only; purchasing+sales together; something else)?
2. **Rate source.** For 9a, the exchange rate is **supplied per document** by the caller (manual), validated
   positive — no rate table/feed yet (deferred to 9d). Recommend **manual per-document**.
3. **Realised FX in 9a.** Recognise realised FX gain/loss when a foreign bill is settled at a rate different
   from the rate it was booked at (the worked example). Recommend **yes** — it is the point of the slice.
4. **Unrealised FX / period-end revaluation.** **Deferred** to a later slice (9c). Recommend **defer** — it
   needs a period-end rate and a reversal mechanism, and is orthogonal to transacting in foreign currency.
5. **FX Gain/Loss account.** Add the account this slice needs (leaf + system key + backfill + chart VERSION
   bump — the now-familiar three-part cost). One combined **`Foreign Exchange Gain/(Loss)`** account, or a
   **split** gain (income) / loss (expense) pair? Recommend a **single combined account** typed to match its
   usual sign (an Income or an Expense line that nets both ways), for simpler reporting — **confirm the type**
   (SL/IAS-21 practice reports a net FX line; a single Income account carrying debits and credits is the
   common SME choice). *(This is a real fork — see §3 note.)*
6. **Base-currency GL invariant.** The ledger's `debit`/`credit` remain in **base currency**; the transaction
   currency/amount/rate populate the existing overlay columns. Confirm (this is the built design).
7. **Currency validation.** Any valid ISO-4217 code is accepted (Money already validates the shape); no
   per-company currency allowlist this slice. Recommend **open ISO-4217**.
8. **Same-currency settlement only, for 9a.** A payment settles bills that are in the **same transaction
   currency** as the payment; cross-currency settlement (a USD payment against an EUR bill) is **out of
   scope**. Recommend **same-currency-only** (the common case; cross-currency is a later slice).
9. **Rounding.** Base-currency posting rounded to `companies.currency_precision`; transaction amounts at
   `Money::SCALE`; rate at `decimal(19,10)` as the columns already are. The base-currency equivalent of a
   transaction amount = `round(transaction_amount × rate, precision)`. Confirm.

## 4. Functional requirements (draft for 9a — firm up after Gate 1)

- **FR-1** A bill may be recorded in a transaction currency ≠ base, with a supplied positive rate; its GL
  posting is `transaction_amount × rate`, rounded, in base currency, with the overlay populated on each line.
- **FR-2** A supplier payment may be recorded in a foreign currency (same currency as the bills it settles),
  with its own rate; the bank/cash credit is the base-currency equivalent at the payment rate.
- **FR-3** On settlement, the difference between the payable booked (bill rate) and the cash paid (payment
  rate) for the settled amount is posted to **FX Gain/(Loss)** so the entry balances in base currency.
- **FR-4** The base-currency GL always balances (`Σ debit = Σ credit` in base), for any rate, by construction
  — the FX line is exactly the plug.
- **FR-5** The four `*_single_currency_until_fx_phase` CHECKs in scope are dropped; the
  `journal_lines_currency_columns_check` (all-or-none + positive rate) remains the authority on overlay shape.
- **FR-6** A base-currency (or rate-1) document behaves exactly as today — byte-for-byte unchanged
  (the NULL-overlay path).
- **FR-7** FX figures are immutable once posted (frozen with the rest of the document), and audited.
- **FR-8** Reporting stays in base currency (trial balance, ledger) — unchanged; presentation-currency
  translation is out of scope.

## 5. Acceptance criteria (draft — firm up after Gate 1)

- **AC-1.1** A USD bill @ 300 books LKR 300,000 to the GL with the overlay (`USD`, `1000`, `300.0`) on each
  line; the base debit/credit are the rounded base equivalents.
- **AC-1.2** A base-currency bill posts exactly as today, overlay NULL (the FR-6 no-regression proof).
- **AC-2.1** Paying that USD bill @ 310 posts `Dr Trade Payables 300,000 + Dr FX Loss 10,000 = Cr Bank
  310,000`; @ 290 posts `Dr Trade Payables 300,000 = Cr Bank 290,000 + Cr FX Gain 10,000`.
- **AC-2.2** The realised FX equals `settled_transaction_amount × (payment_rate − bill_rate)`, rounded.
- **AC-2.3** A partial settlement realises FX only on the portion settled.
- **AC-2.4** The base-currency entry balances for every rate; the FX line is the plug and is zero when the
  rates match.
- **AC-3.1** FX Gain/(Loss) resolves by system key; provisioned to new companies (template) and existing ones
  (backfill), VERSION bumped.
- **AC-4.1** A negative or zero rate is refused; a payment whose currency differs from its bills' currency is
  refused (same-currency-only, §3.8).

## 6. Scope boundaries — explicitly deferred

- **Unrealised FX / period-end revaluation** (slice 9c).
- **Rate table / automated feed** (slice 9d) — 9a takes a manual per-document rate.
- **Foreign sales invoices / receipts** (slice 9b).
- **Cross-currency settlement** (a payment in a different currency from the bill it pays).
- **Presentation-currency reporting / consolidation** (reporting in a non-base currency).
- **Multi-currency bank reconciliation.**

## 7. Risks & sequencing

- **The ledger CHECK is shared.** Dropping `journal_lines_single_currency_until_fx_phase` affects EVERY
  posting path, not just purchasing. The slice must prove the base-currency (NULL-overlay) path is unchanged
  across the whole ledger (a broad regression), and that the overlay is written only by the FX-aware paths.
- **Branch base.** This stacks on the Phase 5 line (Waves 7/8, PRs #3–#10, unmerged). If those merge to
  `main` first, Phase 6 branches off `main` cleanly; if not, it stacks and the eventual merge order matters.
  **Recommend confirming whether to merge the Phase 5 PRs before starting Phase 6, or to keep stacking.**
- **The `currency_precision > 4` platform bug** (found in Wave 8b's security review, now a separate task) is
  adjacent to FX rounding — worth landing that fix before or during this phase.
- **FX is order-flexible** (`ROADMAP.md:388-392`): "banking before FX assumes a Sri Lankan SME banking in
  rupees; wrong for an exporter." Starting FX now is a legitimate choice; flagged only so it is deliberate.

---

*Prepared for Gate 1. No architecture and no code. Per the per-wave gate policy: Gate 1 → STOP → Architecture
/ADR → Gate 2 STOP → build. Because this is a phase boundary, the first decision is the first-wave scope (§3.1).*

## Gate 1 decisions — APPROVED 2026-09-10

The human approved the requirements and resolved the four decisions put to them, plus the recommended
defaults:

1. **First wave = 9a — Foreign purchasing + realised FX** (decision 3.1): foreign-currency **bills** +
   **supplier payments**, the ledger overlay turned on for the purchasing posting paths, and **realised** FX
   gain/loss on settlement. Drops `journal_lines_single_currency_until_fx_phase`,
   `bills_single_currency_until_fx_phase`, `supplier_payments_single_currency_until_fx_phase`. Foreign sales
   (9b), unrealised/period-end FX (9c) and a rate table (9d) are later slices.
2. **FX account = a single combined `Foreign Exchange Gain/(Loss)`, Income type** (decision 3.5): one account
   carrying credits for gains and debits for losses, netting to the period's FX result. Leaf + system key +
   backfill + chart VERSION bump.
3. **Sequencing = keep stacking on the Phase 5 line** (§7): Phase 6 branches off the current Phase 5 head
   (`feature/phase5-wht-on-payment`, Waves 7/8), not off `main`; the Phase 5 PRs (#3–#10) stay unmerged for
   now and the eventual merge order is bottom-up.
4. **Approved as recommended** (decisions 3.2, 3.4, 3.6–3.9): manual per-document rate (no rate table this
   slice); unrealised/period-end FX **deferred** (9c); base-currency GL invariant; open ISO-4217 (no
   allowlist); **same-currency settlement only** (a payment's currency matches the bills it settles;
   cross-currency deferred); rounding of the base equivalent to `companies.currency_precision`; FX figures
   frozen + audited.

Next: Solution Architect writes the ADR (0022) realising these decisions, then **STOP for Gate 2**.
