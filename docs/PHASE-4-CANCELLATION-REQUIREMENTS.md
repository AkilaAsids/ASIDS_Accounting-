# Phase 4, Cancellation sub-slice — Customer Receipt Cancellation: Requirements

**Stage: requirements · awaiting Gate 1 approval.** Backend only — no front end in this wave.
Branch `feature/phase4-cancellation`, stacked on `feature/phase4-payments` (receipts + allocation,
already shipped on that branch).

## 1. Objective and scope

`docs/adr/0014-customer-receipts-and-allocation.md` shipped record + allocate + post for customer
receipts and deliberately deferred reversal, preparing but not populating the boundary: "a status
column, a posted-only CHECK, immutability triggers" exist, but "no reversal path is built" (ADR
0014, Context, decision 1; §G). `docs/PHASE-4-RECEIPTS-REQUIREMENTS.md` Gate 1 decision 1 made this
binding: *"Receipt cancellation/reversal — DEFERRED to a follow-up sub-slice… Prepare the structural
boundary but do not implement reversal, mirroring how M5 prepared issuing before wiring it."* This
document is that follow-up sub-slice.

**Objective:** let a company cancel a **posted** customer receipt — reversing its ledger entry,
restoring every allocated invoice's `amount_paid`/`amount_due`/`status` to what they should be with
that receipt's contribution removed, and recording who cancelled it, when, and why — mirroring
`SalesInvoiceService::cancel()` (`docs/adr/0009-sales-invoice-issuing-cancellation-and-numbering.md`
§B7, §I) exactly as ADR 0014 mirrored `issue()` for `record()`.

### In scope
- Cancelling a posted receipt: reverse its journal entry via `PostingService::reverse()` (a mirror
  entry drawing a `JV-…` number, consuming no `RCT-…` number — mirroring "cancelling retains the
  invoice number and consumes none," ADR 0009 §B).
- Restoring every invoice the cancelled receipt allocated to: `amount_paid`/`amount_due`/`status`
  updated to remove exactly this receipt's contribution, under a row lock.
- Cancellation metadata on the receipt: `cancelled_at`, `cancellation_reason`, `cancelled_by_id`.
- The one schema/trigger transition this wave must open: `customer_receipts` moving from fully
  immutable-once-posted to permitting exactly one further transition, posted → cancelled, and
  nothing else.
- Refusal cases: already-cancelled receipt, closed reversal period, missing/non-reversible journal
  entry, missing reason, permission.
- Concurrency: locking the receipt and its allocated invoices during reversal, mirroring
  `issue()`/`cancel()`/`ReceiptService::record()`.

### Out of scope (still deferred — do not build)
- **Unallocated credit on account.** This wave does not add or touch any concept of a receipt
  existing without being fully allocated; that boundary is untouched by cancellation.
- **Withholding tax.** No WHT line, account, or rate resolution is touched by this wave.
- Any front-end or HTTP surface — domain + service layer only, matching how receipt `record()`
  shipped and how invoice `issue()`/`cancel()` shipped before Milestone 9's API (ADR 0009 §J).
- Partial reversal of a receipt (cancelling only some of its allocations) — cancellation is all-or-
  nothing for the whole receipt, mirroring invoice cancellation being all-or-nothing for the whole
  invoice.
- Any change to `receipt_allocations` rows. They are read, never written, during cancellation (§2
  below) — the historical "this receipt paid these invoices" trail is not part of what is undone.

## 2. Domain changes needed (requirements, not schema)

The Solution Architect owns the actual migration/trigger/column design. What follows is what the
domain needs to be able to represent, and — because the correctness of this wave hinges on exactly
which single transition gets opened — how far today's schema already goes.

### 2.1 What already exists and needs no change
- `customer_receipts.status` (string, default `'posted'`) — already present
  (`src/Core/Sales/Database/Migrations/2026_03_08_000001_create_customer_receipts_table.php`).
- **The immutability trigger already excludes `status` (and `updated_at`) from its frozen-column
  list.** Reading `asids_customer_receipts_immutable()`
  (`2026_03_08_000004_make_customer_receipts_immutable.php`), the big `IF (... IS DISTINCT FROM
  ...)` block that raises "has been posted; it is immutable" lists every column *except* `status`
  and `updated_at`. That is the boundary ADR 0014 §G describes as "prepared, not populated" —
  structurally, a bare status change is not yet blocked by that `IF`. What still blocks reaching
  `'cancelled'` today is purely the CHECK, `customer_receipts_status_check CHECK (status IN
  ('posted'))` (`2026_03_08_000001`), and the absence of a finality guard once cancelled.
- `PostingService::reverse()` (`src/Core/Accounting/Application/Services/PostingService.php`) is
  already fully generic: it takes any `JournalEntry`, refuses a draft or already-reversed one
  (`PostedEntryIsImmutable::cannotReverseDraft()`/`alreadyReversed()`), mirrors every line with
  sides swapped and amounts **copied, not recomputed**, dates the mirror at the reversal date (not
  the original entry's date), reuses the original's `document_type` so the mirror draws from the
  *same counter the original drew from* (for a receipt's posting, that is the `JournalVoucher`
  counter — ADR 0014 §B), and carries the same `source` (`SourceDocument::for($receipt)`) so it
  traces back to the receipt. **No change to `PostingService::reverse()` is needed for this wave.**
- `receipt_allocations`' immutability trigger (`asids_receipt_allocations_immutable()`) already
  refuses every UPDATE and DELETE unconditionally, no exceptions. **This is correct as-is and
  requires no relaxation** — cancellation must read allocation rows, never rewrite or delete them,
  exactly as invoice cancellation never touches `sales_invoice_lines`.
- `SalesInvoiceStatus::isCollectable()` (Issued, PartiallyPaid → true) is unaffected by this wave;
  restoring an invoice back to `Issued` or `PartiallyPaid` re-enters the same collectable set a
  later receipt can allocate against.

### 2.2 New, needed by this slice
- **Three cancellation-metadata columns on `customer_receipts`**, matching the shape and naming of
  the invoice's own (`2026_03_06_000001_add_cancellation_to_sales_invoices.php`):
  `cancelled_at` (timestamptz, nullable), `cancellation_reason` (string, nullable),
  `cancelled_by_id` (uuid FK → users, nullable, nullOnDelete). Nullable because they do not exist
  before cancellation.
- **A `Cancelled` status value** added to the receipt's status space (`'cancelled'`, mirroring
  `SalesInvoiceStatus::Cancelled`), and the CHECK widened from `IN ('posted')` to
  `IN ('posted', 'cancelled')` — exactly the "written as a one-value IN so a later reversal
  sub-slice widens it deliberately" the receipts ADR anticipated (ADR 0014 §A).
- **A CHECK tying the three metadata columns to status**, mirroring
  `sales_invoices_cancellation_matches_status_check`: when `status = 'cancelled'`, `cancelled_at`
  and `cancellation_reason` must both be non-null; otherwise all three must be null. This is what
  stops an issued... er, *posted* receipt quietly acquiring a `cancellation_reason` while still
  reading as `'posted'`.
- **The one transition the immutability trigger must open, and only that one:**
  - A **finality guard**: once `OLD.status = 'cancelled'`, refuse the update outright (mirroring
    the invoice trigger's `IF (OLD.status = 'cancelled') THEN RAISE EXCEPTION` at the top of
    `asids_sales_invoices_immutable()`) — a cancelled receipt is frozen exactly as hard as a posted
    one, just in its own terminal state. No un-cancel, no double-cancel via UPDATE.
  - A **metadata guard shaped like the invoice's**: the three new columns may change **only** on an
    update where `NEW.status = 'cancelled'` — i.e. `IF (NEW.status <> 'cancelled' AND
    (cancellation columns changed)) THEN RAISE EXCEPTION`, mirroring
    `asids_sales_invoices_immutable()`'s existing guard verbatim in shape. Combined with the
    finality guard, this confines the three columns to being writable in exactly the posted →
    cancelled transition and frozen on both sides of it.
  - **Everything else stays frozen on the cancelling update too.** The existing frozen-column list
    (id, tenant/company/branch/customer, `number`, `reference`, `receipt_date`, `currency_code`,
    `amount`, `payment_method`, `bank_account_id`, `journal_entry_id`, `posted_at`, `posted_by_id`,
    `created_by_id`, `created_at`) must continue to apply unconditionally, including on the
    cancelling update — cancellation changes `status` and the three metadata columns and nothing
    else on the receipt row. This is the same discipline the invoice trigger already proves: its
    frozen-column `IF` fires on every update regardless of transition, and only the metadata guard
    is transition-scoped.
- **No change to `receipt_allocations`' schema, CHECKs, or trigger.** Restoring invoice balances
  reads these rows; nothing about cancellation writes to this table (§3.2).

## 3. Functional requirements

Numbered `AC-C<story>.<n>` ("C" for cancellation) to keep this document's ids distinct from the
receipts requirements' `AC-<story>.<n>`. Every rule that names a receipt, invoice, or account
implicitly also requires it to belong to the same company as the receipt being cancelled, per the
company-ownership discipline every Sales service already applies.

### 3.1 Story: Cancel a posted receipt — the ledger reversal

*As an accountant, I want to cancel a receipt that was recorded in error, so its ledger entry is
reversed and the affected invoices' balances go back to what they should be without it — the same
way `SalesInvoiceService::cancel()` lets me undo an issued invoice without deleting anything.*

- **AC-C1.1 Given** a posted receipt and a non-blank reason, **when** I cancel it, **then** its
  journal entry is reversed via `PostingService::reverse()`: a mirror entry is posted with every
  line's side swapped and its amount copied (never recomputed) from the original, dated at today
  (the reversal date), not the receipt's own `receipt_date` — the same "amounts copied, never
  recalculated" discipline ADR 0009 §B5 states for invoice reversal, and the same "which period
  must be open is the reversal's, not the [document]'s" rule ADR 0009 §B7 states.
- **AC-C1.2 Given** that reversal, **when** the mirror entry is posted, **then** it draws its number
  from the same counter the original posting drew from — the `JournalVoucher` counter, per ADR
  0014 §B — and the receipt's own `RCT-…` number is retained unchanged and consumes nothing; only
  the mirror entry consumes a new `JV-…` number. This is ADR 0009 §B's "cancelling retains the
  invoice number and consumes none," applied to a receipt.
- **AC-C1.3 Given** the reversal, **when** the mirror entry is posted, **then** it carries
  `source: SourceDocument::for($receipt)`, the same source the original posting cited, so the
  partial unique index over `journal_entries.source_id` (which excludes reversing entries) lets the
  mirror cite the receipt without colliding with the original posting.
- **AC-C1.4 Given** the reversal succeeds, **when** the transaction commits, **then** the original
  journal entry's `status` becomes `Reversed`, and its `reversed_by_entry_id`/`reversed_at`/
  `reversal_reason` are set — this is existing, unmodified `PostingService::reverse()` behaviour,
  asserted here as a requirement on the whole flow, not a new mechanism.
- **AC-C1.5 Given** a blank or whitespace-only reason, **when** I try to cancel a receipt, **then**
  it is refused before any lock is taken or number reserved — mirroring
  `SalesInvoiceService::cancel()`'s `trim($reason) === ''` guard and
  `InvoiceCannotBeCancelled::withoutReason()`.
- **AC-C1.6 Given** the fiscal period the *reversal date* (today) falls into, **when** that period
  does not accept postings (closed), **then** the cancellation is refused before any number is
  reserved — matching `SalesInvoiceService::cancel()`'s ordering exactly, including that it is the
  reversal's period being checked, never the original `receipt_date`'s.

### 3.2 Story: Restoring each allocated invoice's balance — the correctness-critical story

*As the business, I want cancelling a receipt to put every invoice it touched back to the balance it
should have without that receipt's money, even if other receipts have since also paid against the
same invoice — never to erase another receipt's still-live contribution.*

- **AC-C2.1 Given** a posted receipt with one or more `receipt_allocations` rows (untouched,
  historical, read but never written by cancellation), **when** it is cancelled, **then** for each
  allocated invoice the reversal is computed as a **delta against that invoice's current locked
  state**: `amount_paid := amount_paid - allocation.amount`, `amount_due := amount_due +
  allocation.amount` (equivalently `total - amount_paid`), written together in one save — never as
  a restore to a remembered snapshot of "what the invoice looked like right before this receipt
  posted." This is the single load-bearing correctness rule of this whole slice (see Risk 1, §6):
  another receipt may have allocated against the same invoice after this one, and a snapshot
  restore would silently erase that other receipt's contribution.
- **AC-C2.2 Given** the recomputed `amount_paid` for an invoice, **when** it is written, **then**
  `status` becomes `Issued` if the new `amount_paid` is exactly zero, else `PartiallyPaid` if it is
  positive but less than `total` — mirroring the same `amount_due = total - amount_paid` invariant
  and Paid/PartiallyPaid boundary `ReceiptService::record()` already applies going forward, just
  run in reverse. An invoice's status can only move to `Issued` or `PartiallyPaid` by this
  operation, never back to `Draft` and never left at `Paid` with a nonzero `amount_due`.
- **AC-C2.3 Example — single full allocation reversed.** An invoice with `total` 1,000 was moved to
  `amount_paid` 1,000 / `amount_due` 0 / `Paid` by this receipt's sole allocation of 1,000. **When**
  the receipt is cancelled, **then** the invoice becomes `amount_paid` 0 / `amount_due` 1,000 /
  `Issued`.
- **AC-C2.4 Example — single partial allocation reversed.** An invoice with `total` 1,000 sits at
  `amount_paid` 400 / `amount_due` 600 / `PartiallyPaid` after this receipt's allocation of 400.
  **When** the receipt is cancelled, **then** the invoice returns to `amount_paid` 0 / `amount_due`
  1,000 / `Issued`.
- **AC-C2.5 Given** a receipt that allocated across two or more invoices, **when** it is cancelled,
  **then** each named invoice's balance and status are restored independently, using only that
  invoice's own allocation row from this receipt.
- **AC-C2.6 The multi-receipt example — the case that proves AC-C2.1's delta rule.** Invoice `total`
  1,000. Receipt A allocates 400 first (`amount_paid` 400 / `amount_due` 600 / `PartiallyPaid`).
  Receipt B later allocates the remaining 600 (`amount_paid` 1,000 / `amount_due` 0 / `Paid`).
  **When** receipt A is now cancelled, **then** the invoice becomes `amount_paid` 600 (1,000 − 400)
  / `amount_due` 400 / `PartiallyPaid` — **not** `amount_paid` 0 (which would silently discard
  receipt B's still-live 600) and **not** left at `Paid`.
- **AC-C2.7 Given** the recomputed `amount_paid` for an invoice would go negative (i.e., the
  invoice's current `amount_paid`, re-read through the lock, is less than this receipt's
  allocation against it — should not occur absent a bug or a still-undecided policy answer to
  OQ-2, §5), **when** cancellation reaches that invoice, **then** it is refused with a named domain
  error rather than surfacing the database's `sales_invoices_non_negative_check` (or the
  `amount_paid <= total`/`amount_due >= 0` backstop) as a raw constraint violation — the same
  "domain error first, database backstop second" discipline every other refusal in this feature
  family follows.
- **AC-C2.8 Given** the cancellation transaction, **when** it locks affected rows, **then** the
  receipt itself and every allocated invoice are locked with `lockForUpdate()`, the invoices taken
  in **deterministic id order** — the same ordering `ReceiptService::record()` already uses for the
  same reason (preventing deadlock between two multi-invoice operations touching overlapping sets
  of invoices) — before any balance is re-read or written.
- **AC-C2.9 Given** a locked invoice's new `amount_paid`/`amount_due`/`status`, **when** they are
  persisted, **then** all three are written in one save — the `amount_due = total - amount_paid`
  CHECK means neither figure can be written without the other in the same statement, exactly the
  constraint `PHASE-4-RECEIPTS-REQUIREMENTS.md` §6 already flags for the forward direction.

### 3.3 Story: Cancellation metadata

*As an auditor, I want a cancelled receipt to record who cancelled it, when, and why, the same way
a cancelled invoice does.*

- **AC-C3.1 Given** a successful cancellation, **when** the transaction commits, **then** the
  receipt's `status` becomes `Cancelled` and `cancelled_at`, `cancellation_reason`,
  `cancelled_by_id` are written together in one save — mirroring
  `SalesInvoiceService::cancel()`'s single save of `status` plus its three cancellation columns.
- **AC-C3.2 Given** the reason supplied, **when** it is blank after trimming, **then** cancellation
  is refused (= AC-C1.5, restated here for the metadata story's completeness).
- **AC-C3.3 Given** the cancelled receipt's allocations, **when** cancellation completes, **then**
  every `receipt_allocations` row belonging to it is left exactly as it was written at recording
  time — untouched, still the permanent "this receipt paid these invoices, in these amounts" trail
  an auditor reads regardless of the receipt's current status. Cancellation is visible only on the
  receipt header and on the invoices it touched, never by a change to the allocation rows.

### 3.4 Story: Refusal cases (consolidated)

- **AC-C4.1** Cancelling a receipt that is already `Cancelled` is refused —
  `ReceiptCannotBeCancelled::alreadyCancelled()`, mirroring
  `InvoiceCannotBeCancelled::alreadyCancelled()`.
- **AC-C4.2** Cancelling a receipt whose `status` is not `'posted'` for any other reason (defensive;
  no other status is reachable under this wave's schema, exactly as
  `InvoiceCannotBeCancelled::partiallyPaid()` was dead-code-by-design until the payments phase made
  it live) is refused with a named exception, written now so the rule exists before anything could
  reach it.
- **AC-C4.3** Cancelling into a closed fiscal period (the reversal date's period, AC-C1.6) is
  refused before any number is reserved.
- **AC-C4.4** A receipt whose journal entry is missing, or whose journal entry's status is not
  exactly `Posted` (already reversed by some other path), is refused — mirroring
  `InvoiceCannotBeCancelled::withoutJournalEntry()`/`journalEntryNotReversible()`, including the
  same reasoning: comparing to `Posted` explicitly rather than "has been posted" excludes an
  already-reversed entry, which must fail with a message about the entry, not a second silent
  reversal.
- **AC-C4.5 [decide/flag — see OQ-2, §5] An allocated invoice that has been "further changed"
  since this receipt posted.** Under the delta-based restoration this document specifies
  (AC-C2.1/AC-C2.6), a later receipt allocating more against the same invoice, or that invoice
  reaching `Paid` via another receipt, does **not** by itself refuse this cancellation — the
  arithmetic is designed to be safe regardless (AC-C2.6). The one case that *does* refuse is
  AC-C2.7 (would-go-negative). Flagged here because "was this invoice further changed" is exactly
  the kind of guard `InvoiceCannotBeCancelled::partiallyPaid()` adds on the invoice side for a
  receipt with money against it, and the human should confirm no analogous stricter rule is wanted
  here (e.g. "refuse if any other receipt has touched this invoice since," which would be far more
  conservative than what this document assumes).
- **AC-C4.6** There is no "zero allocation" refusal case for cancellation itself: every
  `receipt_allocations.amount` is already `> 0` by construction
  (`receipt_allocations_amount_positive_check`), so no allocation row cancellation reads can ever
  be zero. The only "zero" that appears in this feature is the ordinary boundary where a restored
  `amount_paid` lands exactly on zero (AC-C2.2/AC-C2.3) — a normal status transition, not a
  refusal.
- **AC-C4.7** Cancelling without the required permission is refused (§3.5).
- **AC-C4.8** Every refusal above is a named domain exception (`ReceiptCannotBeCancelled`, following
  `InvoiceCannotBeCancelled`'s per-case static-factory pattern) — never a raw `QueryException` or
  constraint name surfaced to a caller, matching `ReceiptCannotBeRecorded`/`ReceiptCannotBeAllocated`
  /`InvoiceCannotBeCancelled`'s existing discipline.

### 3.5 Story: Permission

*As the business, I want undoing a posted receipt to require its own explicit grant, not to ride
along on the permission that lets someone record one.*

- **AC-C5.1 [open, OQ-1]** Cancelling a receipt requires a distinct capability from recording one.
  ADR 0014 §E already names the intended shape: *"the reversal sub-slice adds a second
  `sales.receipts.cancel` when it lands, exactly as `cancel` arrived separately for invoices."* This
  document assumes that plan (a new `sales.receipts.cancel`, separate from the existing
  `sales.receipts.manage`) and asks the human to confirm it at Gate 1 rather than silently building
  around it, mirroring `sales.invoices.issue`/`.cancel` being separately grantable
  (ADR 0009 §H: "a business may well grant the first without the second").
- **AC-C5.2** `CustomerReceiptPolicy::cancel()` checks the permission **and** `canAccessCompany()`
  — company membership and permission are different questions and both must hold, matching
  `SalesInvoicePolicy`. Any status check in the policy is advisory only, offered so a client can
  decide whether to show a cancel action without attempting it — `Gate::before` short-circuits
  every policy method for a tenant owner, so `ReceiptService` remains the actual enforcement
  boundary for every state rule in §3.1–3.4, exactly as ADR 0009 §H establishes for invoices.
- **AC-C5.3** Granted to the accountant role template, matching `sales.receipts.manage` and
  `sales.invoices.issue`/`.cancel` all being accountant-only.

### 3.6 Story: Concurrency

*As the business, I want cancelling a receipt and a new receipt landing on the same invoice at the
same moment to never leave that invoice's balance wrong, in either direction.*

- **AC-C6.1** Cancellation opens one transaction, locks the receipt row and every allocated invoice
  with `lockForUpdate()` (invoices in deterministic id order, AC-C2.8), and re-reads each invoice's
  current `amount_paid`/`amount_due` through the lock before computing the restore — never trusting
  a figure read before the transaction opened. This is `issue()`/`cancel()`/`ReceiptService::record()`'s
  shared discipline, applied to the reverse direction.
- **AC-C6.2** A concurrent `ReceiptService::record()` call allocating against the same invoice
  queues behind this cancellation's lock (or vice versa) rather than racing it — since both
  operations lock the same invoice rows before reading `amount_due`/`amount_paid`, whichever
  transaction commits first is the one the other sees when it re-reads.
- **AC-C6.3** A concurrent second cancellation attempt on the same receipt queues behind the first;
  once the first commits, the second's re-read finds `status = 'cancelled'` and is refused by
  AC-C4.1 rather than racing to double-reverse the same journal entry (which
  `PostingService::reverse()`'s own `alreadyReversed()` guard would also catch, but only after
  taking a lock the service-level check should have refused first).

## 4. Non-functional requirements

- **Audit trail.** `CustomerReceipt::auditOnly()` must be extended to include `status`,
  `cancelled_at`, `cancellation_reason`, and `cancelled_by_id` — mirroring how `SalesInvoice`'s own
  `auditOnly()`/`auditTags()` already covers its cancellation columns — so a cancellation is visible
  in the audit log with who, when, and why, not only that the status changed.
- **RLS scope.** No new tables and no new RLS policy are needed — `customer_receipts` and
  `receipt_allocations` already carry `ENABLE`/`FORCE ROW LEVEL SECURITY` tenant-isolation policies
  (`2026_03_08_000003_enable_row_level_security_on_customer_receipts.php`); this wave adds columns
  and CHECKs to an already-covered table, not a new table.
- **Conditional immutability.** The three cancellation-metadata columns are writable **only** as
  part of the posted → cancelled transition; before it they do not exist (null), after it they
  cannot change — the same split-protection shape ADR 0009 §I documents for invoices: a CHECK ties
  the columns to status, and the trigger freezes them on every update except the one that sets them
  (§2.2 above).
- **Decimal/Money correctness.** Every figure this feature touches — the mirrored journal lines
  (already handled generically and correctly by `PostingService::reverse()`, §2.1) and the restored
  `amount_paid`/`amount_due` on each invoice — must go through `Money`/`numeric-string` arithmetic
  at `Money::SCALE`, exactly as `ReceiptService::record()` and `SalesInvoiceService::cancel()`
  already do; no float touches a monetary value, and no restoration amount is recomputed from
  anything other than the stored `receipt_allocations.amount` for that invoice.
- **Idempotency.** A second cancellation attempt against the same receipt is refused (AC-C4.1,
  AC-C6.3), and the database backstops hold even if the service is bypassed: the widened
  `customer_receipts_status_check` plus the new cancellation-matches-status CHECK together mean a
  row cannot exist half-cancelled (status cancelled without the metadata, or metadata present
  without the status), and the finality guard in the trigger refuses any further UPDATE once
  `status = 'cancelled'`.

## 5. Assumptions and consolidated open questions

### Assumptions made while drafting this document
- Cancellation is all-or-nothing for the whole receipt; there is no partial/per-allocation reversal.
- Invoice balance restoration is a **delta subtraction** against the invoice's current state, not a
  restore to a remembered snapshot of its state before this receipt posted (AC-C2.1/AC-C2.6) — this
  is the assumption the human should scrutinise hardest; see OQ-2.
- `receipt_allocations` rows are read-only during cancellation; nothing about this feature writes to
  or requires relaxing that table's existing full-freeze trigger.
- No time-window limit on when a posted receipt may be cancelled, mirroring invoice cancellation
  having none either (see OQ-3).
- The reason for cancellation is required and non-blank, mirroring
  `SalesInvoiceService::cancel()`'s existing rule (see OQ-7).

### Consolidated open questions for the human (Gate 1)

1. **Permission naming.** Confirm a new `sales.receipts.cancel`, separate from the existing
   `sales.receipts.manage` — ADR 0014 §E already states this as the intended plan for the reversal
   sub-slice, mirroring `sales.invoices.issue`/`.cancel`. Should this document treat that as decided,
   or is reusing `sales.receipts.manage` for both record and cancel still on the table?
2. **The central correctness policy question: is delta-based restoration (subtract this receipt's
   own contribution from the invoice's *current* balance) the right rule, including when another
   receipt has since further paid — or even fully paid to zero — the same invoice (AC-C2.6,
   AC-C4.5)?** This document assumes yes (the invoice simply moves back from `Paid` to
   `PartiallyPaid` in that case, never losing the other receipt's contribution). An alternative,
   stricter policy would refuse cancelling a receipt if any other receipt has allocated against the
   same invoice since — confirm which policy is wanted.
3. **Time-window limits.** Is there any limit on how long after posting a receipt may be cancelled
   (e.g., only within the same fiscal period, or only while no year-end close has run since)? This
   document assumes none, mirroring invoice cancellation.
4. **Should cancelling a receipt be blocked if the reversal would drop an invoice's status below
   where it was through some path other than this receipt** — e.g., is there any scenario the human
   considers a receipt "not safely cancellable" beyond the negative-`amount_paid` defensive refusal
   (AC-C2.7)? This document assumes no additional business-policy block beyond that arithmetic
   guard.
5. **Should `CustomerReceipt::auditOnly()` be extended to include the new columns** (assumed yes,
   §4) — confirmed, or is a separate audit configuration wanted for cancellation events?
6. **Is a defensive "not posted" refusal (AC-C4.2) worth writing now**, given no other status is
   reachable until this migration ships — mirroring how `InvoiceCannotBeCancelled::partiallyPaid()`
   was written and dormant until the payments phase made it live — or should it wait until it can
   actually be exercised?
7. **Is the cancellation reason required, as this document assumes** (mirroring
   `SalesInvoiceService::cancel()`), or could a receipt cancellation reason be optional?

## 6. Risks and dependencies

1. **Highest risk — restoring invoice balances correctly when multiple receipts have touched the
   same invoice.** A naive "restore to what the invoice looked like before this receipt" would need
   a remembered snapshot, and any such snapshot is stale the moment a second receipt touches the
   same invoice — cancelling the first receipt would then silently erase the second's still-live
   contribution. *Mitigation:* this document specifies restoration as a **delta** against the
   invoice's current, re-read-under-lock state (subtract exactly this receipt's own allocation
   amount), which is correct regardless of what else has happened to the invoice since, as long as
   the invoice's `amount_paid` never needs to go negative to do so (AC-C2.1, AC-C2.6, AC-C2.7). This
   is also the single biggest open question (OQ-2) — the arithmetic is safe, but whether it is the
   *policy* wanted needs the human's confirmation.
2. **Dependency — the migration and trigger changes fall into the same "hard to reverse" categories
   ADR 0009/0014 flagged:** a widened status CHECK, three new columns, and a new permission grant
   are each awkward to walk back once receipts exist in that state. Should get the same review
   weight Milestone 5's and Milestone A's constraint work received.
3. **Risk — `receipt_allocations` must stay untouched.** Any implementation temptation to "delete
   the allocation rows on cancel" or "zero them out" would require relaxing
   `asids_receipt_allocations_immutable()`'s unconditional freeze, which neither ADR 0014 nor this
   document anticipates. This document assumes allocations remain permanent history exactly as
   invoice cancellation leaves `sales_invoice_lines` untouched — a scope check for whoever
   implements this.
4. **Risk — interaction with `InvoiceCannotBeCancelled::partiallyPaid()`.** That guard already
   refuses cancelling an *invoice* while it has money received against it (`amount_paid > 0`), which
   ADR 0009 §B7 wrote in anticipation of this phase. It means an invoice cannot be independently
   cancelled while a receipt's allocation against it is still live — closing one race this document
   need not separately guard against. This should be re-confirmed if a later phase (WHT, credit
   notes) adds another path that reduces `amount_paid` outside of receipt cancellation.
5. **Dependency — `PostingService::reverse()` needs no change.** Confirmed by reading its
   implementation (§2.1): it is already generic over any `JournalEntry`/`document_type`/`source`,
   which is what let ADR 0009 build invoice cancellation as pure wiring; this sub-slice is the same
   kind of wiring, not new ledger machinery.
6. **Risk — cross-team/file collision**, following the receipts ADR's own risk item: if another lane
   is concurrently touching `ReceiptService`, `SalesServiceProvider`, or the receipt migrations,
   coordinate before this wave's schema and service changes land.
