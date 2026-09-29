# Phase 5 Wave 8c — Supplier Payment Cancellation — Requirements

- **Status:** Gate 1 — APPROVED 2026-09-29
- **Wave:** 8c (Phase 5 purchasing gap; follows 8a payments, 8b WHT, and — in the current branch — 9a FX)
- **Precedent:** ADR 0009 (sales invoice issuing & cancellation) is the established cancellation pattern in this codebase; this wave mirrors it on the settlement side.

## 0. What already exists on this branch — read before deciding

The payment foundation was built *anticipating* this slice, so most of the machinery is in place:

- **`PaymentStatus::Cancelled` is reserved** and the DB status CHECK already covers `('posted','cancelled')` — no CHECK-widening migration needed (`PaymentStatus.php`, `2026_09_03_000002_create_supplier_payments_table.php:118`).
- **The freeze trigger already permits exactly one transition.** `asids_supplier_payments_immutable()` refuses every UPDATE to a posted payment *except* `status → cancelled`, refuses any change once already cancelled, and refuses hard DELETE outright (`2026_09_03_000005_make_posted_payments_immutable.php:44-90`). Every other column is frozen by name.
- **Allocations are frozen and kept.** `asids_payment_allocations_immutable()` refuses UPDATE/DELETE on allocation rows, so cancellation cannot delete them — they remain as the historical record.
- **Bills already let `amount_paid`/`amount_due` move** (Wave 8a dropped the phase pin; `bills_amount_due_non_negative_check` is the authority), so restoring them on cancellation uses the same seam the payment path used to relieve them.
- **No cancellation columns exist yet** on `supplier_payments` — deliberately deferred "to the cancellation slice" (`…000002…:24-28`). This wave adds them.
- **FX (9a) and WHT (8b) are in the branch history**, so a cancelled payment may be foreign and/or withholding — the reversal must undo realised FX and the WHT Payable credit, not just cash and payable.
- **The reversal seam exists:** `PostingService::reverse()` writes a mirror entry; journal entries carry `reverses_entry_id`/`reversed_by_entry_id` (ADR 0009 §B3 chose these over a FK on the document).

## 1. Objective and context

Let a posted supplier payment be **cancelled** — reversed cleanly in the ledger and un-applied from the bills it settled — when it was recorded in error (wrong supplier, wrong amount, wrong bank, a bounced/duplicate payment). A payment is a statutory record, so cancellation *reverses* rather than deletes: the payment row, its number, its figures and its allocations all survive; a mirror journal entry undoes the original posting; the settled bills return to what they owed.

This closes the most-needed Phase 5 gap: today a mistaken payment can only be recorded, never undone, which forces manual journal surgery that bypasses the subledger.

## 2. Functional requirements

- **FR-1 — Only a posted payment may be cancelled.** There is no draft (payments are born posted), so there is nothing else to cancel. An already-cancelled payment is refused readably.
- **FR-2 — Cancellation reverses the ledger.** `PaymentService::cancel()` writes a reversal of the payment's journal entry via `PostingService::reverse()`. For a base payment that undoes `Dr Trade Payables = Cr Bank (+ Cr WHT Payable)`; for a foreign payment the mirror also undoes the realised FX line and the transaction-currency overlay. The reversal balances by construction (it is a mirror).
- **FR-3 — Cancellation restores the settled bills.** For each allocation, the bill's `amount_paid` decreases and `amount_due` increases by the allocation amount, and the bill's status is recomputed from its restored `amount_paid` (a fully-paid bill returns to partially-paid or outstanding). Restoration is in the bill's own currency (allocations are stored per bill).
- **FR-4 — Allocations are retained, not deleted.** They stay as the historical record of what the payment did; cancellation is expressed by the payment's status + the reversal + the restored bill figures (consistent with the allocation freeze trigger).
- **FR-5 — Cancellation is atomic.** Reversal, bill restoration and the status/metadata update happen in one transaction, with the affected bills locked (mirroring the record path's `FOR UPDATE` ordering) so concurrent payments/cancellations cannot corrupt `amount_due`.
- **FR-6 — The payment keeps its number and every figure.** Only `status` moves to `cancelled` and the new cancellation-metadata columns are written; the freeze trigger continues to guard everything else.
- **FR-7 — Cancellation metadata is captured and conditionally immutable.** New columns `cancelled_at`, `cancellation_reason`, `cancelled_by_id`, writable only during the posted→cancelled transition and frozen thereafter (mirror ADR 0009 §I).
- **FR-8 — The reversal's period must be open** (today's), not the original payment's — a payment from a closed month may still be cancelled today; what blocks a cancellation is today's period being closed. Checked before anything is written (mirror ADR 0009 §B7).
- **FR-9 — Authorization is a distinct sensitive permission.** Cancelling requires `purchasing.payments.cancel`, separate from `purchasing.payments.post` (mirror `sales.invoices.cancel` vs `.post`).
- **FR-10 — A cancelled payment is fully immutable and cannot be re-cancelled.** To "redo" a payment, record a fresh one — cancellation does not re-open the payment for editing.

## 3. Gate-1 decisions needed (the human decides these; my recommendation follows each)

- **D1 — Which period gates the cancellation?** → *Recommend: the reversal's (today's), per ADR 0009 §B7.* A payment from a closed period is still cancellable; today's period being closed is what refuses it.
- **D2 — Bill status restoration source.** → *Recommend: recompute each affected bill's status purely from its restored `amount_paid`/`amount_due`* (single source of truth), rather than storing/restoring a prior status. Handles the case where another payment also touched the bill.
- **D3 — Retain or delete allocations on cancel?** → *Recommend: retain* (frozen, audit trail; deletion would fight the allocation freeze trigger and lose history).
- **D4 — WHT on a cancelled withholding payment.** → *Recommend: the reversal undoes the WHT Payable credit* (the withholding is unwound with the payment). A future WHT-remittance slice would block cancelling an already-remitted payment; out of scope now.
- **D5 — Reversal linkage.** → *Recommend: reuse the journal seam (`reverses_entry_id`/`reversed_by_entry_id`), no `reversal_journal_entry_id` on the payment* (ADR 0009 §B3).
- **D6 — Cancellation reason required?** → *Recommend: yes, non-blank* (mirror ADR 0009; an auditor needs the why).
- **D7 — Are FOREIGN payments cancellable in this wave, and does the branch stack on the FX branch?** → *Recommend: yes — stack Wave 8c on `feature/phase6-fx-purchasing` and handle foreign (and WHT) cancellation.* FX payments already exist in the branch; an uncancellable foreign payment would be an arbitrary gap, and the mirror-reversal handles realised FX for free. (Alternative: stack on `feature/phase5-wht-on-payment` and defer foreign cancellation — leaves a known gap.)
- **D8 — Scope of what a cancel may touch.** → *Recommend: refuse to cancel if any settled bill has since been cancelled itself* — but since bill cancellation does not exist yet, this is a guard for the future, not reachable now. Confirm we add the guard defensively.

## 4. Acceptance criteria (draft — firm up after Gate 1 decisions)

- **AC-1.1** Cancelling a posted base payment writes a balanced reversal entry that nets the original to zero in the ledger, and sets the payment to `cancelled` with reason/timestamp/actor.
- **AC-1.2** Each settled bill's `amount_paid`/`amount_due` is restored by its allocation and its status recomputed (paid → partially-paid/outstanding); a bill also paid by another payment restores only this payment's contribution.
- **AC-1.3** A withholding payment's cancellation reverses the WHT Payable credit as well as cash and payable.
- **AC-1.4** A foreign payment's cancellation reverses the realised FX line and the overlay; the base entry nets to zero and the bill's transaction-currency `amount_due` is fully restored (per D7).
- **AC-1.5** Cancellation is refused readably for: a non-existent/other-company payment, an already-cancelled payment, a blank reason, and today's period being closed.
- **AC-1.6** The payment keeps its number and every original figure; the freeze trigger still refuses any other change, and a cancelled payment cannot be re-cancelled or edited.
- **AC-1.7** Allocations are retained (not deleted) after cancellation.
- **AC-1.8** Only a holder of `purchasing.payments.cancel` may cancel; `purchasing.payments.post` alone does not suffice.
- **AC-2.1** Cancellation is atomic under concurrency: a payment cancelled while another payment settles the same bill leaves `amount_due` correct and never negative (DB CHECK is the authority).
- **AC-2.2** The new cancellation-metadata columns are writable only in the cancelling transition and frozen thereafter.

## 5. Scope boundaries — explicitly deferred

- **Bill cancellation** — a separate wave (cancelling a posted bill, handling one that already has payments).
- **Supplier credit-on-account / prepayment** — a separate wave.
- **WHT remittance** and its interaction with cancelling an already-remitted payment.
- **Partial cancellation / editing a payment** — cancellation is all-or-nothing; correction is a fresh payment.
- **Cross-currency and rate-table concerns** — unchanged from 9a.
- **HTTP/API surface** — service-layer only this wave, matching how 8a/8b/9a shipped.

## 6. Risks

- **R1 — `amount_due` drift under concurrency.** Mitigate by locking the affected bills (`FOR UPDATE`, ascending id) exactly as the record path does, and relying on `bills_amount_due_non_negative_check` as the DB authority.
- **R2 — Restoring a bill touched by multiple payments.** Recomputing status from `amount_paid` (D2) rather than a stored prior status is what keeps this correct.
- **R3 — FX reversal completeness.** The mirror reversal must reverse the FX plug and overlay; assert the cancelled foreign entry nets to zero in base and the bill's foreign `amount_due` is exactly restored.
- **R4 — Closed-period timing.** Check today's period is open before reserving/writing anything, so a refused cancellation costs nothing (mirror ADR 0009 §B7).
- **R5 — Metadata immutability regressions.** The freeze trigger must permit the three new columns only in the cancelling transition; add mutation-style tests.

---

*Prepared for Gate 1. No architecture and no code — the ADR (Gate 2) follows only after the human approves these decisions. Per the per-wave gate policy: Gate 1 → STOP → Architecture/ADR → Gate 2 STOP → build.*

## Gate 1 decisions — APPROVED 2026-09-29

The human approved all recommendations:

- **D1 — APPROVED:** the reversal's period (today's) gates the cancellation, not the original payment's.
- **D2 — APPROVED:** recompute each affected bill's status from its restored `amount_paid`/`amount_due` (single source of truth).
- **D3 — APPROVED:** retain allocations (frozen, audit trail); cancellation is status + reversal + restored bill figures.
- **D4 — APPROVED:** the reversal undoes the WHT Payable credit with the payment; a remitted-WHT block is a future concern.
- **D5 — APPROVED:** reuse the journal seam (`reverses_entry_id`/`reversed_by_entry_id`); no `reversal_journal_entry_id` on the payment.
- **D6 — APPROVED:** a non-blank cancellation reason is required.
- **D7 — APPROVED:** **stack Wave 8c on `feature/phase6-fx-purchasing`; foreign and withholding payments are cancellable** — the mirror reversal undoes realised FX and the WHT credit.
- **D8 — APPROVED:** add the defensive guard refusing to cancel a payment settling an already-cancelled bill (not reachable until bill cancellation ships).

Gate 2 (ADR) may now be drafted. Build begins only after the ADR is approved at the Gate 2 STOP.
