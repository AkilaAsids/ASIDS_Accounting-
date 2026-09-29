# ADR 0023 — Supplier payment cancellation (Wave 8c)

- **Status:** Gate 2 — APPROVED 2026-09-29
- **Date:** 2026-09-29
- **Requirements:** `docs/PHASE-5-PAYMENT-CANCELLATION-REQUIREMENTS.md` (Gate 1 — APPROVED 2026-09-29)
- **Branch:** `feature/phase5-payment-cancellation`, stacked on `feature/phase6-fx-purchasing` (Gate-1 dec. D7)
- **Precedent:** ADR 0009 (sales invoice issuing & cancellation) — this wave is its settlement-side mirror.

## How to read this record

Everything below is **Gate-2 PROPOSED** unless it restates a Gate-1 APPROVED decision. No code is written until the human approves this ADR at the Gate-2 STOP. If implementation uncovers anything that would change a decision here, STOP and return to Gate 2.

## Context

Wave 8a made a supplier payment a born-posted, immutable, statutory record; 8b added withholding; 9a added foreign settlement with realised FX. None of them can be undone. This wave adds the one missing lifecycle transition the foundation already reserved — `posted → cancelled` — so a payment recorded in error is *reversed* (never deleted): a mirror journal entry undoes the posting, the settled bills return to what they owed, and the payment, its number, its figures and its allocations all survive as history.

The foundation was built for exactly this (see requirements §0): the freeze trigger already permits only `status → cancelled`; `PaymentStatus::Cancelled` and the status CHECK already exist; allocations are frozen; and — verified during Gate 2 — the **bill** freeze trigger already lets `status`, `amount_paid` and `amount_due` move in either direction on a posted bill (only `→ draft` is refused, `2026_09_02_000005_make_posted_bills_immutable.php:43-74`), so restoring a bill needs **no bill-schema change**.

## Binding Gate-1 decisions (verbatim from the approved requirements)

- **D1:** the reversal's period (today's) gates the cancellation, not the original's.
- **D2:** recompute each bill's status from its restored `amount_paid`/`amount_due`.
- **D3:** retain allocations (frozen, audit trail).
- **D4:** the reversal undoes the WHT Payable credit with the payment.
- **D5:** reuse the journal reversal seam (`reverses_entry_id`/`reversed_by_entry_id`); no FK on the payment.
- **D6:** a non-blank cancellation reason is required.
- **D7:** stack on the FX branch; foreign and withholding payments are cancellable.
- **D8:** defensively refuse to cancel a payment settling an already-cancelled bill (unreachable until bill cancellation ships).

---

# A. Schema — cancellation metadata on `supplier_payments` (Gate-2 PROPOSED)

## A1. Migration files

1. `…_add_cancellation_columns_to_supplier_payments.php` — three nullable columns + the conditional-immutability extension to the freeze trigger.

No new table, no CHECK-widening (the status CHECK already covers `cancelled`), no bill migration.

## A2. New columns (realising Gate-1 dec. D6)

Mirror ADR 0009 §I (sales invoice cancellation metadata), matching the columns bills reserved but never added:

- `cancelled_at timestamptz NULL`
- `cancellation_reason text NULL`
- `cancelled_by_id uuid NULL` → FK to `users`, `ON DELETE SET NULL` (the actor may later be deleted; the cancellation stands).

All three are NULL for a posted payment and written only in the cancelling transition.

## A3. Freeze-trigger extension — conditional immutability (realising Gate-1 dec. D6; mirror ADR 0009 §I)

`asids_supplier_payments_immutable()` today permits only `status → cancelled` and freezes every other column by name (`2026_09_03_000005_make_posted_payments_immutable.php:44-90`). Extend it so the three new columns are:

- writable **only** in the posted→cancelled transition (when `OLD.status='posted' AND NEW.status='cancelled'`), and
- frozen on every other update (they are already unreachable before cancellation and, because the trigger refuses any update once `OLD.status='cancelled'`, unchangeable after it).

Concretely: add `cancelled_at`, `cancellation_reason`, `cancelled_by_id` to the frozen-by-name list, but guard that list so it is not enforced on the cancelling transition (the transition is the one moment they legitimately change from NULL). The existing `IF (OLD.status='cancelled') THEN RAISE` already blocks re-cancellation and any post-cancellation edit.

The `CREATE OR REPLACE FUNCTION` replaces the body in place; the trigger binding is unchanged.

# B. `PaymentService::cancel()` — one transaction (Gate-2 PROPOSED, realising D1–D5, D8)

Signature: `cancel(SupplierPayment $payment, string $reason, ?User $actor = null): SupplierPayment`.

Order (all in one `DB::transaction`, mirroring the record path's structure and lock discipline):

1. **Guard the request** (cheap, before anything is written):
   - the payment belongs to the acting company (RLS + explicit company scope);
   - it is `posted` (an already-`cancelled` payment → `payment-already-cancelled`);
   - the reason is non-blank (`cancellation-reason-required`, D6).
2. **Guard the period** (D1): today's period must be open, else `payment-cancel-period-not-open`. Checked before reserving/writing anything, so a refused cancel costs nothing (mirror ADR 0009 §B7). *Which* period is the reversal's (today's), never the original payment's.
3. **Lock the settled bills** `FOR UPDATE` in ascending id order — the same order the record path uses — so a concurrent payment/cancel on a shared bill serialises and `amount_due` cannot corrupt (F1). The bills come from the payment's (frozen) allocations.
4. **D8 guard:** if any locked bill is `cancelled`, refuse (`payment-cancel-bill-cancelled`). Unreachable until bill cancellation ships; present so it fails closed when it does.
5. **Reverse the ledger** (D2/D4/D5): `PostingService::reverse($payment->journalEntry, $reason, today, $actor)`. This writes the mirror entry (undoing Trade Payables, Bank, WHT Payable and — for a foreign payment — the realised FX line), links `reverses_entry_id`/`reversed_by_entry_id`, flips the original to `Reversed`, and cites the payment as the reversal's source. See §D for the foreign-overlay change reverse() needs.
6. **Restore each bill** (D2), the exact inverse of the record path (`PaymentService.php:391-403`): `amount_paid -= alloc`, `amount_due += alloc`, and status recomputed from the restored `amount_paid` — `0` ⇒ `Posted` (outstanding), `0 < paid < total` ⇒ `PartiallyPaid`, `paid == total` ⇒ `Paid`. Restoration is in the bill's own currency (allocations are per bill). `bills_amount_due_check`/`bills_amount_due_non_negative_check` are the DB authority.
7. **Set the payment** to `cancelled` with `cancelled_at=now()`, `cancellation_reason`, `cancelled_by_id` — the one update the freeze trigger permits.
8. **Retain allocations** (D3) — untouched; they remain the historical record.

Emit a `SupplierPaymentCancelled` domain event (mirror `JournalEntryReversed`/sales cancellation), for future projections; no listeners this wave.

# C. Bill restoration and concurrency (Gate-2 PROPOSED)

- Recomputing status from `amount_paid` (D2) — rather than storing a pre-payment status — is what keeps a bill correct when **another** payment also touched it: cancelling this payment restores only its own allocation, and the recomputed status reflects the remaining paid amount.
- The ascending-id `FOR UPDATE` lock (F1) is the same discipline as `record()`, so the two operations cannot deadlock against each other and cannot interleave a half-restore.
- No bill migration: the bill freeze trigger already permits these column moves in both directions (verified, §Context).

# D. Foreign reversal must carry the transaction overlay (Gate-2 PROPOSED — the shared-seam item to confirm)

**Finding (Gate 2):** `PostingService::reverse()` mirrors each line but currently **drops the FX overlay** — it builds the mirror `JournalLineData` with no `foreign:` (`PostingService.php:138-147`). The base debit/credit are copied and swapped, so a reversed foreign entry still **balances in base and the realised FX still reverses correctly**. What is lost is the transaction-currency view on the reversal lines: FX/period reports keyed on the overlay would see the original foreign lines but not their reversal.

**Proposal:** extend `reverse()` to reconstruct each line's `ForeignAmount` from its stored overlay (`transaction_currency_code` + `transaction_amount` + `exchange_rate`) and pass it as `foreign:` on the mirrored line. A base line has a NULL overlay → stays NULL, so the base path is byte-for-byte unchanged (the same guard 9a used in `writeLines`). The FX plug line has no overlay and stays base-only.

- **Blast radius:** `reverse()` is shared — used by sales invoice cancellation (ADR 0009) and manual reversals. Because the change is inert when the overlay is NULL, every existing base-currency reversal is unaffected; only foreign reversals gain the overlay. **Requires the full FX regression** (Purchasing + Accounting + Sales) as evidence, exactly as 9a's shared `writeLines` change did.
- **Alternative (rejected):** accept base-only reversal lines. Simpler and the ledger still balances, but it leaves a foreign-reporting asymmetry the moment foreign sales/period revaluation (9b/9c) arrive — a reversal that a currency report cannot see. Fixing it later is a second shared-seam change; doing it now, while the overlay design is fresh, is cheaper and honest.

**This is the one decision in this ADR that touches a shared service; it is called out again in §Gate 2 items to confirm.**

# E. Authorization (Gate-2 PROPOSED, realising D-nfr; mirror ADR 0009 §H)

- **E1 — Capability:** add `purchasing.payments.cancel` (sensitive), separate from `purchasing.payments.post` (mirror `sales.invoices.cancel` vs `.post`).
- **E2 — Role grants:** granted to accountant, owner, admin — the same roles that hold `.post`. (Confirm.)
- **E3 — `PaymentPolicy::cancel`:** authorises the capability; an advisory status check may accompany it but the service is the authority (mirror `JournalEntryPolicy`/ADR 0009 §H).

# F. Exceptions (Gate-2 PROPOSED)

New `PaymentCannotBeCancelled extends BusinessRuleViolation`, mirroring `PaymentCannotBeRecorded`/`PaymentCannotBePosted`, with stable problem codes:

- `payment-already-cancelled`
- `cancellation-reason-required`
- `payment-cancel-period-not-open`
- `payment-outside-company` (or reuse the existing ownership refusal)
- `payment-cancel-bill-cancelled` (D8, defensive)

The reversal's own refusals (`PostedEntryIsImmutable::alreadyReversed`, closed-period from `PostingService`) remain as the DB/posting authority behind the service's readable pre-checks.

# G. Build stages (test-first / RED-first per the gate policy)

1. **Migration + freeze-trigger extension** — schema tests (columns exist; conditional immutability; mutation tests proving the new columns are frozen outside the cancelling transition) RED→GREEN.
2. **`PostingService::reverse()` overlay** (§D) — a unit/feature test that reversing a foreign entry carries the overlay and a base entry stays NULL; then the change.
3. **`PaymentService::cancel()`** — base payment: reversal balances, bills restored, status recomputed, metadata set, allocations retained.
4. **WHT + FX composition** — cancelling a withholding payment reverses WHT Payable; cancelling a foreign payment nets base to zero, reverses realised FX, restores foreign `amount_due`.
5. **Guards** — already-cancelled, blank reason, closed today-period, cross-company, multi-payment bill restoration, concurrency.
6. **Authorization** — capability + policy + role grants.

# H. Test strategy (QA writes tests before implementation)

Feature tests under `tests/Feature/Purchasing/CancelPaymentTest.php` + schema/mutation tests alongside the existing payment schema suites. Run the suites **sequentially** (shared test DB). Because §D touches the shared reversal seam, the acceptance bar includes a **green full FX regression** (Purchasing + Accounting + Sales) and PHPStan clean.

## Alternatives considered

- **Delete the payment instead of reversing** — rejected: it is a statutory record; the freeze trigger refuses DELETE, and an auditor needs the document + its reversal.
- **Store a pre-payment bill status to restore** — rejected in favour of recomputing from `amount_paid` (D2): correct under multiple payments, no extra state.
- **A `reversal_journal_entry_id` on the payment** — rejected (D5): the ledger already owns the relationship.
- **Base-only foreign reversal** — rejected (§D): leaves a foreign-reporting asymmetry.

## Consequences

- A mistaken payment can be undone cleanly, restoring the subledger without manual journal surgery.
- `PostingService::reverse()` becomes overlay-aware — a small, inert-when-NULL improvement that also readies foreign sales/period reversals.
- No new tables and no bill migration; the wave is small and well-contained.

## Risks

- **R1 — `amount_due` drift under concurrency** → ascending-id `FOR UPDATE` + DB non-negative CHECK.
- **R2 — multi-payment bills** → recompute status from `amount_paid` (D2).
- **R3 — FX reversal completeness** → assert the cancelled foreign entry nets to zero in base, reverses the FX line, and restores the foreign `amount_due` exactly; full FX regression for the shared `reverse()` change.
- **R4 — closed-period timing** → check today's period before writing (D1).
- **R5 — metadata immutability regressions** → mutation tests on the three new columns.
- **R6 — shared-seam blast radius (§D)** → the change is inert when the overlay is NULL; base-path reversals proven unchanged by the existing suites.

## Gate 2 — items to confirm

1. **§D — extend `PostingService::reverse()` to carry the FX overlay** (recommended), vs base-only reversal lines. This is the one shared-service change; confirm before build.
2. **§A2 — `cancelled_by_id` on `ON DELETE SET NULL`** (recommended), matching the actor-may-be-deleted pattern.
3. **§E2 — role grants** for `purchasing.payments.cancel` = accountant, owner, admin (recommended).
4. **§F — the `PaymentCannotBeCancelled` problem codes** as listed.

*Prepared for Gate 2. Build begins only after the human approves this ADR.*

## Gate 2 decision — APPROVED 2026-09-29

- **§D — APPROVED:** extend the shared `PostingService::reverse()` to carry the FX overlay onto mirrored lines (inert when NULL; base-path reversals unchanged). Full FX regression (Purchasing + Accounting + Sales) is part of the acceptance bar.
- **§A2 — APPROVED:** `cancelled_by_id` FK `ON DELETE SET NULL`.
- **§E2 — APPROVED:** `purchasing.payments.cancel` granted to accountant, owner, admin.
- **§F — APPROVED:** `PaymentCannotBeCancelled` problem codes as listed.

Build proceeds test-first through the §G stages. Autonomy runs to the PR (independent QA + Security gates first); Gate 3 / production remains a separate human approval.

## Gate 2 — as-built amendments (ratified 2026-09-29)

Two places where the build refined this ADR. Neither changes a Gate-1/Gate-2 decision or a guaranteed behaviour, so they are ratified here in place rather than re-opened; both were confirmed clean by the independent Security and QA reviews.

- **§A3 superseded — the freeze trigger is NOT extended.** §A3 proposed adding the three metadata columns to `asids_supplier_payments_immutable()`'s frozen-by-name list, guarded to skip the cancelling transition. In build this proved to be dead code: the payment freeze already refuses *every* update whose `NEW.status <> 'cancelled'` and *every* update once `OLD.status = 'cancelled'` (`2026_09_09_000004…:33-41`), so the only update that can touch the new columns is the one posted→cancelled transition. The columns are therefore deliberately left OUT of the frozen list (a column in that list may never change on any permitted update — but these must change on the cancelling one), and the conditional-immutability guarantee (AC-2.2) is delivered by that existing status gate plus the new `supplier_payments_cancellation_matches_status_check`. This is a deliberate, documented divergence from the sales precedent, whose freeze permits issued→issued updates and so genuinely needs the extra guard. Verified by the schema + mutation tests and by Security.

- **§B — the `SupplierPaymentCancelled` domain event is deferred.** §B mentioned emitting one "for future projections; no listeners this wave." No purchasing service emits a domain event today (`record()` included), so emitting one only here would be inconsistent and unused; the ledger reversal already dispatches `JournalEntryReversed`, which is the observable signal. Deferred until a listener exists.

- **Two robustness additions (within intent).** `cancel()` also refuses a reason longer than 255 chars (`cancellation-reason-too-long`) — it flows into `journal_entries.reversal_reason` (255) first, so this turns a raw column-length error into a readable refusal; and it asserts every allocated bill was locked before restoring (a `LogicException` on the unreachable skip), mirroring `record()`'s re-validation of every locked bill.
