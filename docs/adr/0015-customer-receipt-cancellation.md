# ADR 0015 — Customer receipt cancellation: reversing a posted receipt by delta, not by snapshot

- **Status:** Accepted
- **Date:** 2026-08-25
- **Milestone:** Phase 4, cancellation sub-slice (record + allocate + post shipped in ADR 0014; branch `feature/phase4-cancellation`, stacked on `feature/phase4-payments`)
- **ADR number:** 0015. 0014 is the receipts ADR; 0013 is reserved for the Phase 3 front-end ADR on another branch. 0015 is the next free number on this branch and does not collide with it.

## Context

ADR 0014 shipped record + allocate + post for customer receipts and **deliberately deferred reversal**, preparing but not populating the structural boundary: a `status` column, a one-value `status IN ('posted')` CHECK, and an immutability trigger whose frozen-column list already *excludes* `status` and `updated_at` (`2026_03_08_000004_make_customer_receipts_immutable.php:43-64`). ADR 0014 §G states this outright: "The `status` column and the trigger's `WHEN`/guard shape are written so the deferred reversal sub-slice can add a posted → cancelled transition the way Stage 4 of Milestone 5 added issued → cancelled — the boundary is prepared, not populated."

This ADR is that sub-slice. It lets an accountant cancel a **posted** receipt: reverse its journal entry, restore every allocated invoice's balance to what it should be with that receipt's money removed, and record who cancelled it, when, and why. It is the mirror of `SalesInvoiceService::cancel()` (ADR 0009 §B7, §I) exactly as ADR 0014 mirrored `issue()` for `record()`. Like every wave in this family, it is domain + service + tests only — no HTTP surface.

`docs/PHASE-4-CANCELLATION-REQUIREMENTS.md` and its **"Gate 1 decisions — APPROVED 2026-08-25"** section are binding. The decisions that shape this ADR:

1. **Balance restoration = DELTA subtraction** against each invoice's *current locked* state (subtract only this receipt's own allocation), never a snapshot restore — so a later receipt's contribution to the same invoice is preserved.
2. **A new `sales.receipts.cancel`** capability, separate from `sales.receipts.manage`, accountant-only.
3. **Cancellation reason REQUIRED** (mirror `SalesInvoiceService::cancel()`).
4. **No time-window limit** beyond requiring an open reversal period.
5. **Write the dormant "not posted" defensive refusal now** (mirrors `InvoiceCannotBeCancelled::partiallyPaid()` being written dormant).
6. **No extra business rules** beyond the negative-balance defensive guard; **extend `CustomerReceipt::auditOnly()`** to the new metadata columns.
7. **`receipt_allocations` stay permanent history** — read, never written or deleted on cancel.

### What already exists and needs no change (confirmed by reading the code)

- **`PostingService::reverse()` is fully generic** (`src/Core/Accounting/Application/Services/PostingService.php:114-178`). It refuses a draft (`PostedEntryIsImmutable::cannotReverseDraft()`) or already-reversed entry (`alreadyReversed()`), mirrors every line with sides swapped and amounts **copied, not recomputed** (`:138-147`), dates the mirror at the reversal date rather than the original's (`:130`), reuses the original's `document_type` so the mirror draws from the same counter the original drew from — for a receipt's posting that is `DocumentType::JournalVoucher` (`:157`, ADR 0014 §B) — and carries `$entry->sourceDocument()` so the mirror traces back to the receipt through the partial unique index that excludes reversing entries (`:162`). It marks the original `Reversed` and sets `reversed_by_entry_id`/`reversed_at`/`reversal_reason` (`:166-169`). **No change to `PostingService::reverse()` is needed for this wave** (Requirements §2.1, Risk 5).
- **The `receipt_allocations` full-freeze trigger** `asids_receipt_allocations_immutable()` refuses every UPDATE and DELETE unconditionally (`2026_03_08_000004:87-100`). This is correct as-is and **requires no relaxation** — cancellation reads allocation rows, never rewrites them, exactly as invoice cancellation never touches `sales_invoice_lines`.
- **`CustomerReceiptPolicy` and its wiring already exist** (`src/Core/Sales/Policies/CustomerReceiptPolicy.php`). The policy is already registered (`SalesServiceProvider::boot()` `:96`) and the morph alias already declared (`:117`). This wave adds one method, not a new policy or new wiring.
- **`SalesInvoiceStatus`** already has `Issued`, `PartiallyPaid`, `Paid`, `Cancelled` and `isCollectable()` (`src/Core/Sales/Domain/Enums/SalesInvoiceStatus.php:21-25,70`); restoring an invoice back to `Issued`/`PartiallyPaid` re-enters the collectable set a later receipt can allocate against, with no enum change.
- **`Money`** already exposes `plus`, `minus`, `isZero`, `isNegative`, `equals`, `isGreaterThan`, `isLessThan` (`src/Core/Accounting/Domain/ValueObjects/Money.php:110-281`) — every operation the delta restore needs, at `Money::SCALE`.

## Decision

### A — Schema migration: open exactly one transition, freeze everything else

One new migration under `src/Core/Sales/Database/Migrations/` (Stage 1 of the build lane, §G), modelled column-for-column on the invoice's own `2026_03_06_000001_add_cancellation_to_sales_invoices.php`. Proposed name `2026_03_09_000001_add_cancellation_to_customer_receipts.php` (dated after the receipts migrations it amends).

**Three cancellation-metadata columns on `customer_receipts`**, matching the invoice's shape and naming verbatim (`2026_03_06_000001:48-57`):

| Column | Type | Notes |
| --- | --- | --- |
| `cancelled_at` | timestamptz, nullable, `after('journal_entry_id')` | when |
| `cancellation_reason` | string(500), nullable | why — 500 to match the invoice's, since `PostingService::reverse()` takes the reason as a string |
| `cancelled_by_id` | uuid FK → users, nullable, `nullOnDelete` | who; nullable even when cancelled, because a cancellation may be performed by the system, the same reasoning as `posted_by_id`/`created_by_id` |

**Widen the status CHECK.** Drop `customer_receipts_status_check CHECK (status IN ('posted'))` (`2026_03_08_000001:111-115`) and re-add it as `CHECK (status IN ('posted', 'cancelled'))` — exactly the "written as a one-value IN so a later reversal sub-slice widens it deliberately" the receipts migration anticipated (`2026_03_08_000001:24-26,110`). No enum is introduced: `CustomerReceipt::status` stays a plain string, as its docblock explains (`CustomerReceipt.php:35-37`), now with two reachable values instead of one.

**A tie-to-status CHECK**, mirroring `sales_invoices_cancellation_matches_status_check` (`2026_03_06_000001:61-72`):

```sql
ALTER TABLE customer_receipts
    ADD CONSTRAINT customer_receipts_cancellation_matches_status_check
    CHECK (
        CASE WHEN status = 'cancelled'
            THEN cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL
            ELSE cancelled_at IS NULL
                AND cancellation_reason IS NULL
                AND cancelled_by_id IS NULL
        END
    )
```

This is what stops a posted receipt quietly acquiring a `cancellation_reason` while still reading `'posted'`, and stops a row existing half-cancelled (status without metadata, or metadata without status). Like the invoice's, `cancelled_by_id` is not required even when cancelled — a system cancellation has no person.

**The immutability-trigger change — permit EXACTLY the posted → cancelled metadata transition and nothing else.** `CREATE OR REPLACE FUNCTION asids_customer_receipts_immutable()` (the trigger references it by name, so replacing the function is the whole change), starting from the shipped body (`2026_03_08_000004:36-67`) and adding two guards in the exact shape the invoice trigger already proves (`2026_03_06_000001:78-139`):

1. **A finality guard at the top**, after the DELETE guard, mirroring `asids_sales_invoices_immutable()`'s `IF (OLD.status = 'cancelled')` block (`2026_03_06_000001:85-88`):
   ```sql
   IF (OLD.status = 'cancelled') THEN
       RAISE EXCEPTION 'Receipt % is cancelled and cannot be changed further. Its posting has already been reversed.', OLD.number
           USING ERRCODE = 'restrict_violation';
   END IF;
   ```
   A cancelled receipt is frozen exactly as hard as a posted one, in its own terminal state — no un-cancel, no double-cancel via UPDATE.

2. **A transition-scoped metadata guard at the bottom**, before `RETURN NEW`, mirroring the invoice's verbatim in shape (`2026_03_06_000001:127-134`):
   ```sql
   IF (NEW.status <> 'cancelled' AND (
       NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at
       OR NEW.cancellation_reason IS DISTINCT FROM OLD.cancellation_reason
       OR NEW.cancelled_by_id IS DISTINCT FROM OLD.cancelled_by_id
   )) THEN
       RAISE EXCEPTION 'Receipt % is not being cancelled, so its cancellation details cannot be set.', OLD.number
           USING ERRCODE = 'restrict_violation';
   END IF;
   ```

**Everything else stays frozen on the cancelling update too.** The existing frozen-column `IF` block (`2026_03_08_000004:43-64` — id, tenant/company/branch/customer, `number`, `reference`, `receipt_date`, `currency_code`, `amount`, `payment_method`, `bank_account_id`, `journal_entry_id`, `posted_at`, `posted_by_id`, `created_by_id`, `created_at`) is kept **unchanged** and continues to fire on every update regardless of transition. Only `status`, the three new metadata columns, and `updated_at` are ever writable — and the three metadata columns only in the one transition. This is the same split-protection discipline ADR 0009 §I documents: the CHECK ties the columns to status, the trigger freezes them on every update except the one that sets them, and the finality guard closes the far side. The combination confines the three columns to being writable in exactly the posted → cancelled transition and frozen on both sides of it.

**No change to `receipt_allocations`' schema, CHECKs, or trigger.** Its unconditional full-freeze (`2026_03_08_000004:87-100`) stays exactly as-is; restoring invoice balances reads these rows and nothing about cancellation writes to the table (§B, Gate-1 #7, Risk 3).

The migration's `down()` restores the trigger function to the shipped `2026_03_08_000004` body, re-narrows the status CHECK to `IN ('posted')`, drops the tie-to-status CHECK, and drops the three columns — trigger function first, then columns, mirroring the invoice migration's teardown ordering (`2026_03_06_000001:144-205`) so the function never references a dropped column.

### B — `ReceiptService::cancel()`: one transaction, `cancel()`'s discipline exactly

A new method on the existing `ReceiptService` (`src/Core/Sales/Application/Services/ReceiptService.php`), signature `cancel(CustomerReceipt $receipt, string $reason, ?User $actor = null): CustomerReceipt`, mirroring `SalesInvoiceService::cancel(SalesInvoice, string $reason, ?User $actor)`. Everything that can refuse cheaply runs before the lock; everything race-sensitive runs under it — the same ordering `record()` and `cancel()` share.

**Before the transaction opens** (cheap refusal, no lock, no number):

1. `$reason = trim($reason)`; if `=== ''`, throw `ReceiptCannotBeCancelled::withoutReason(...)` — mirroring `SalesInvoiceService::cancel()`'s `trim($reason) === ''` guard (`SalesInvoiceService.php:342-345`) and `InvoiceCannotBeCancelled::withoutReason()`. Refused before any lock is taken or number reserved (AC-C1.5, AC-C3.2).

**Inside `DB::transaction(...)`:**

2. **Lock the receipt row and re-read it** with `lockForUpdate()->firstOrFail()`, so a concurrent second cancellation queues here rather than racing (mirroring `SalesInvoiceService.php:349-354`). `loadMissing(['journalEntry', 'allocations'])`.
3. **Refusals against the re-read row**, in order (each a named `ReceiptCannotBeCancelled` factory):
   - `status === 'cancelled'` → `alreadyCancelled()` (AC-C4.1, AC-C6.3).
   - `status !== 'posted'` (defensive; unreachable under this wave's two-value CHECK, written now per Gate-1 #5) → `notPosted()` (AC-C4.2), mirroring `InvoiceCannotBeCancelled::notIssued()`.
   - `journalEntry === null` → `withoutJournalEntry()` (AC-C4.4).
   - `journalEntry->company_id !== receipt->company_id` → `journalEntryOutsideCompany()` — the same sibling-company guard `SalesInvoiceService.php:381-383` applies, since RLS alone is satisfied by either company in a shared tenant.
   - `journalEntry->status !== JournalEntryStatus::Posted` (compared explicitly, not `isPosted()`, so an already-reversed entry fails with a message about the entry rather than a silent second reversal) → `journalEntryNotReversible()` (AC-C4.4), mirroring `SalesInvoiceService.php:388-394`.
4. **Fiscal period of the reversal date (today), before any number is reserved.** `$reversalDate = CarbonImmutable::now()->startOfDay(); $period = $this->calendar->periodFor($receipt->company, $reversalDate);` if `! $period->acceptsPostings()` → `intoClosedPeriod()` (AC-C1.6, AC-C4.3). It is the reversal's period that must be open, never the original `receipt_date`'s — the mirror will be dated today (ADR 0009 §B7).
5. **Lock and re-read every allocated invoice in deterministic id order.** From the locked receipt's `allocations`, collect `sales_invoice_id`s, `sort()` them (byte-ordered uuid, the same ordering `record()` uses at `ReceiptService.php:121-122`), and for each: `SalesInvoice::query()->whereKey($id)->lockForUpdate()->firstOrFail()`. This is the reverse-direction twin of `record()` step 6.
6. **Reverse the journal entry** via `$this->posting->reverse($entry, $reason, $reversalDate, $actor)` (§A, AC-C1.1–C1.4). The mirror draws a `JV-…` number (§F), keeps the receipt's `RCT-…` untouched, cites `SourceDocument::for($receipt)` and marks the original `Reversed`.
7. **Delta-restore each locked invoice** (§C). For each, subtract this receipt's own allocation against it from the current locked `amount_paid`, recompute `amount_due`, recompute status, and write all three in one save.
8. **Write cancellation metadata and flip status in one save on the receipt**, mirroring `SalesInvoiceService.php:409-415`:
   ```php
   $locked->status = 'cancelled';
   $locked->cancelled_at = now();
   $locked->cancellation_reason = $reason;
   $locked->cancelled_by_id = $actor?->getKey();
   $locked->save();
   ```
   The tie-to-status CHECK (§A) means a status written without the metadata — or metadata without the status — is refused by the database, not merely avoided here. `return $locked->refresh();`

**Refusal exceptions** — a new `ReceiptCannotBeCancelled` following the per-case static-factory pattern of `InvoiceCannotBeCancelled` and the receipts family (`ReceiptCannotBeRecorded`/`ReceiptCannotBeAllocated`), never a raw `QueryException` or constraint name (AC-C4.8):

- `withoutReason($identifier)`, `alreadyCancelled($identifier)`, `notPosted($identifier, string $status)`, `withoutJournalEntry($identifier)`, `journalEntryOutsideCompany($identifier)`, `journalEntryNotReversible($identifier, $entryNumber, $status)`, `intoClosedPeriod($identifier, $periodLabel, PeriodStatus)` — each mirroring the identically named `InvoiceCannotBeCancelled` factory (`InvoiceCannotBeCancelled.php:30-174`).
- `wouldReverseBelowZero($invoiceIdentifier, $currentPaid, $allocation)` — the defensive negative-balance guard (§C, AC-C2.7), the one refusal with no invoice-side analogue by that name.

### C — Delta-restore correctness: the load-bearing arithmetic

This is the single correctness-critical decision (Gate-1 #1, Requirements Risk 1, OQ-2 resolved to delta). For each locked invoice, using exact `Money` integer math at `Money::SCALE`, letting `a` = this receipt's own `receipt_allocations.amount` against that invoice (read from the untouched allocation row, never recomputed):

```php
$currentPaid = Money::of($invoice->amount_paid, $currency);   // re-read THROUGH the lock
$allocation  = Money::of($allocation->amount, $currency);     // this receipt's own line

$newPaid = $currentPaid->minus($allocation);                  // subtract, never restore a snapshot

if ($newPaid->isNegative()) {                                 // defensive guard, AC-C2.7
    throw ReceiptCannotBeCancelled::wouldReverseBelowZero(...);
}

$newDue = Money::of($invoice->total, $currency)->minus($newPaid);   // amount_due = total - amount_paid

$invoice->amount_paid = $this->decimal($newPaid);
$invoice->amount_due  = $this->decimal($newDue);
$invoice->status = $newPaid->isZero()
    ? SalesInvoiceStatus::Issued            // exactly zero paid → back to Issued
    : SalesInvoiceStatus::PartiallyPaid;    // positive but < total → PartiallyPaid
$invoice->save();
```

The three columns are written together in one save because the `sales_invoices_amount_due_check (amount_due = total - amount_paid)` invariant means neither figure can be written without the other in the same statement (AC-C2.9). These are exactly the mutable columns the invoice immutability trigger permits (`amount_paid`, `amount_due`, `status`, `updated_at` — ADR 0009 §I). It is the same arithmetic `record()` runs forward (`ReceiptService.php:225-233`), run in reverse: forward `plus`, reverse `minus`.

**Status recomputation rule.** After the subtraction, status is `Issued` iff `newPaid` is exactly zero, else `PartiallyPaid` (`newPaid` is positive and, because no oversell ever pushed `amount_paid` past `total`, strictly less than `total`). It can never land on `Paid` (that requires `amount_due` zero, i.e. no reduction happened) and never on `Draft` (a posted receipt only ever allocated to a collectable invoice). This is the exact inverse of `record()`'s `Paid`/`PartiallyPaid` boundary.

**Why delta is correct where a snapshot is not (the multi-receipt proof, AC-C2.6).** Invoice `total` = 1,000.

| Event | `amount_paid` | `amount_due` | status |
| --- | --- | --- | --- |
| Receipt A allocates 400 | 400 | 600 | PartiallyPaid |
| Receipt B allocates 600 | 1,000 | 0 | Paid |
| **Cancel A** → `1000 − 400` | **600** | **400** | **PartiallyPaid** |

Cancelling A subtracts *A's own* 400 from the current locked 1,000, landing at 600 — B's still-live 600 is untouched, and the invoice correctly reads PartiallyPaid, **not** 0 (which a snapshot of "what the invoice looked like before A" would have wrongly restored, silently erasing B) and **not** left at Paid. A single-receipt full reversal (AC-C2.3: 1,000 → 0, Paid → Issued) and a single-receipt partial (AC-C2.4: 400 → 0, PartiallyPaid → Issued) both fall out of the same one subtraction. Correctness holds regardless of what else touched the invoice since, *as long as* `amount_paid` never needs to go negative — which is the only case AC-C2.7's guard refuses, and which cannot arise absent a bug because every allocation was `<= amount_due` when it posted and nothing reduces `amount_paid` except this same cancellation path.

### D — Permission and policy

Per Gate-1 #2, a **new second capability** `sales.receipts.cancel`, separate from `sales.receipts.manage`, following the invoice `issue`/`cancel` split (ADR 0009 §H) and the plan ADR 0014 §E already named.

- **`PermissionCatalogue::sales()`** (`src/Core/Authorization/Domain/Catalogue/PermissionCatalogue.php`), added immediately after the existing `sales.receipts.manage` definition (`:271`), `sensitive: true`, `sortOrder: 110` (following `manage` at 100, mirroring the invoices' 70/80 issue/cancel pairing):
  ```php
  new PermissionDefinition('sales', 'receipts', 'cancel', 'Cancel customer receipts',
      'Reverse a posted receipt\'s posting and restore the invoices it paid, keeping both ledger entries.',
      sensitive: true, sortOrder: 110),
  ```
  Sensitive because it moves money and posts a reversal to the ledger. No migration: permissions are code, synchronised by `PermissionSynchroniser`, and the administrator picks it up automatically via `tenantGrantableNames()` (ADR 0003, ADR 0009 §H). A test asserts an existing workspace acquires it, not only a fresh one.
- **`RoleTemplate`** (`src/Core/Authorization/Domain/Catalogue/RoleTemplate.php`) — grant `sales.receipts.cancel` to the **accountant** template, immediately after `sales.receipts.manage` (`:118`), matching `sales.receipts.manage` and `sales.invoices.issue`/`.cancel` all being accountant-only (Gate-1 #2, AC-C5.3).
- **`CustomerReceiptPolicy::cancel()`** (`src/Core/Sales/Policies/CustomerReceiptPolicy.php`) — add one method:
  ```php
  public function cancel(User $user, CustomerReceipt $receipt): bool
  {
      return $user->can('sales.receipts.cancel')
          && $user->canAccessCompany($receipt->company_id);
  }
  ```
  Permission **and** company access, both required, matching `SalesInvoicePolicy::cancel()` (`SalesInvoicePolicy.php:109-113`) and the receipt policy's existing `view()`. Any status check would be advisory only — `Gate::before` short-circuits every method for a tenant owner (ADR 0009 §H), so `ReceiptService::cancel()` stays the enforcement boundary for every state rule in §B. The policy's docblock (`CustomerReceiptPolicy.php:14-15`) already anticipates this method arriving with the reversal sub-slice.
- **Provider wiring** (`src/Core/Sales/Providers/SalesServiceProvider.php`) — **nothing new is required.** `Gate::policy(CustomerReceipt::class, CustomerReceiptPolicy::class)` (`:96`), the `CustomerReceipt::MORPH_ALIAS` registration (`:117`), and the `ReceiptService` singleton (`:71`) are all already in place. The reversal reuses all three unchanged.
- **`CustomerReceipt::auditOnly()`** (`CustomerReceipt.php:169-181`) already lists `status`; extend it to add `cancelled_at`, `cancellation_reason`, and `cancelled_by_id` (Gate-1 #6, Requirements §4, AC-C4/NFR), so a cancellation is visible in the audit log with who, when, and why — mirroring `SalesInvoice::auditOnly()` covering its own cancellation columns.

### E — Concurrency: lock ordering, no deadlock, no oversell/undersell

`cancel()` opens one transaction, locks the receipt row first (`lockForUpdate()->firstOrFail()`), then every allocated invoice with `lockForUpdate()` **in deterministic id order** (`sort()`ed uuids), re-reading each invoice's `amount_paid` through the lock before computing any restore — never trusting a figure read before the transaction opened. This is `record()`'s ordering (`ReceiptService.php:119-152`) applied to the reverse direction.

**No deadlock.** Both `record()` and `cancel()` acquire invoice locks in the same total order (ascending id). Two operations touching overlapping invoice sets therefore request locks in a consistent order and cannot form a cycle — the identical argument ADR 0014 §F makes for two racing multi-invoice receipts, now extended to cover a cancel racing a record. The receipt row itself is locked before its invoices; since a receipt's invoice set is fixed at record time and each operation locks its *own* receipt, no two operations contend on the same receipt-then-invoice chain in opposite order.

**No oversell or undersell.**
- *A concurrent `record()` allocating against a shared invoice* queues behind this cancellation's invoice lock (or vice versa). Whichever commits first is what the other re-reads: if the record commits first, cancel's `minus` operates on the higher `amount_paid` and lands correctly; if cancel commits first, record's re-read sees the lower `amount_paid`/higher `amount_due` and validates its cap against the fresh figure (AC-C6.1, AC-C6.2). Neither races to a constraint.
- *A concurrent second cancellation of the same receipt* queues behind the first's receipt-row lock; once the first commits, the second's re-read finds `status = 'cancelled'` and is refused by `alreadyCancelled()` (AC-C6.3) — before it can reach `PostingService::reverse()`, whose own `alreadyReversed()` guard (`PostingService.php:124-126`) is the deeper backstop but would fire only after taking a number.
- *The database backstop still holds.* The delta only ever *lowers* `amount_paid`, so it moves strictly away from the `sales_invoices_amount_paid_not_exceeding_total_check` bound; the negative floor is caught first by `wouldReverseBelowZero()` (§C) and, if the service were bypassed, by `sales_invoices_non_negative_check`/`amount_due` invariant as the raw backstop.

### F — Numbering: the reversal draws a JV, the RCT is retained

The receipt keeps its `RCT-…` number unchanged and consumes nothing on cancellation. The reversal is a *ledger* event only: `PostingService::reverse()` builds the mirror with `documentType: $entry->document_type` (`PostingService.php:157`), which for a receipt's posting is `DocumentType::JournalVoucher` (the original posting used it — ADR 0014 §B, `ReceiptService.php:206`), so the mirror draws the next `JV-…` from the journal-voucher counter and consumes no `RCT-…`. This is ADR 0009 §B's "cancelling retains the [document] number and consumes none," applied to a receipt (AC-C1.2). The mirror cites `SourceDocument::for($receipt)` (via `$entry->sourceDocument()`, `PostingService.php:162`); the partial unique index over `journal_entries.source_id` excludes reversing entries, so the mirror traces back to the receipt without colliding with the original posting (AC-C1.3).

### G — Build stages (single cohesive backend lane)

One lane, not parallelisable — the service depends on the schema and trigger, and the permission/policy wiring depends on the service. Staged for reviewability, mirroring ADR 0014 §H and Milestone 5. The migration is irreversible-in-practice once cancelled receipts exist (a widened status CHECK, three columns, a granted permission), so Stage 1 gets the same review weight as ADR 0009/0014's constraint work (Requirements Risk 2).

| Stage | Delivered | Reviewable artefact |
| --- | --- | --- |
| 1 | Migration: three metadata columns, widened `customer_receipts_status_check`, `customer_receipts_cancellation_matches_status_check`, and the `asids_customer_receipts_immutable()` replacement adding the finality + transition-scoped metadata guards. `receipt_allocations` untouched. | The schema/trigger, provable before any code can reach `'cancelled'` — the conditional-immutability shape reviewed against the invoice's. |
| 2 | `ReceiptService::cancel()` (lock/re-read, refusals, `PostingService::reverse()`, delta-restore, metadata + status flip) and `ReceiptCannotBeCancelled` with all factories. | The transaction; the delta-restore correctness and every refusal land here. |
| 3 | `sales.receipts.cancel` in `PermissionCatalogue`, accountant grant in `RoleTemplate`, `CustomerReceiptPolicy::cancel()`, extended `CustomerReceipt::auditOnly()`. (Provider wiring already exists — nothing added.) | The authorisation surface, arriving with the operation it guards (ADR 0009 §J — no HTTP exists, so no untestable guard is written early). |

### H — Test strategy (QA asserts test-first)

- **Ledger reversal balances.** Cancelling a posted receipt writes a mirror JV with every line's side swapped and amount copied; the original entry becomes `Reversed` with `reversed_by_entry_id`/`reversed_at`/`reversal_reason` set; the mirror is dated today, not `receipt_date`; the two entries net to zero (AC-C1.1–C1.4).
- **Delta-restore across multiple receipts** — the AC-C2.6 table: A(400) then B(600) to Paid; cancel A → `amount_paid` 600, `amount_due` 400, PartiallyPaid, with B intact. Plus AC-C2.3 (full → Issued), AC-C2.4 (partial → Issued), AC-C2.5 (two invoices restored independently from their own allocation rows only).
- **Idempotency.** A second cancel of the same receipt is refused by `alreadyCancelled()` (AC-C4.1); a database-level test bypassing the service asserts the finality guard refuses any UPDATE once `status = 'cancelled'` (AC-C6.3).
- **Every refusal is a named exception** — blank/whitespace reason, not-posted (defensive), already-cancelled, closed reversal period, missing journal entry, journal entry outside company, journal entry not `Posted` (already reversed), would-reverse-below-zero (AC-C4.x, AC-C2.7).
- **Metadata conditional-immutability.** The tie-to-status CHECK refuses status-without-metadata and metadata-without-status; the trigger's transition-scoped guard refuses setting any metadata column on a non-cancelling update; both proven by bypassing the service (ADR 0009 §I discipline).
- **Numbering.** The RCT is unchanged after cancellation and a fresh JV is consumed; a multi-event run asserts the JV series advances while the RCT series does not (the multi-document assertion ADR 0009 §B requires).
- **RLS.** A second tenant cannot read the receipt or drive its cancellation; the sibling-company `journalEntryOutsideCompany()` guard is proven distinct from RLS.
- **Permission gating.** `sales.receipts.cancel` is required and distinct from `sales.receipts.manage` (holding only `manage` cannot cancel); the accountant template grants it; an existing workspace acquires it via `PermissionSynchroniser`; a tenant-owner gate-says-yes / service-still-refuses test proves the policy is advisory and the service is the boundary (ADR 0009 §H).
- **`receipt_allocations` untouched.** After cancellation every allocation row is byte-identical to record time; the full-freeze trigger still refuses UPDATE/DELETE (Gate-1 #7, AC-C3.3).

## Risks and mitigations

1. **Delta-restore is the correctness pivot (highest risk).** A snapshot restore would silently erase a later receipt's still-live contribution to the same invoice. *Mitigation:* subtract only this receipt's own `receipt_allocations.amount` from the invoice's current, re-read-under-lock `amount_paid` (§C), proven correct by the AC-C2.6 multi-receipt table and safe for all cases except a would-go-negative one, which `wouldReverseBelowZero()` refuses.
2. **Irreversible schema on a production-shaped table.** A widened status CHECK, three new columns, and a granted permission are each awkward to walk back once cancelled receipts exist. *Mitigation:* Stage 1 gets ADR 0009/0014 constraint-review weight; the migration is idempotent-safe for existing rows (every current receipt is `'posted'` with null metadata, satisfying both new CHECKs trivially) and has a full `down()`.
3. **Accidental relaxation of the `receipt_allocations` freeze, or of the receipt's frozen-column list.** Any "delete the allocations on cancel" temptation would need to loosen `asids_receipt_allocations_immutable()`, which nothing here does. *Mitigation:* the trigger is explicitly unchanged, the metadata guard is transition-scoped so the frozen-column `IF` keeps firing on the cancelling update, and tests bypass the service to prove both freezes still hold (§H). The reversal touches only `status` + three metadata columns on the receipt and only `amount_paid`/`amount_due`/`status` on each invoice — the exact mutable sets the two triggers already permit.

## Report summary

- **ADR path:** `docs/adr/0015-customer-receipt-cancellation.md` — 0015 confirmed as the next free number (0014 is receipts; 0013 reserved on another branch).
- **Delta-restore design:** For each invoice this receipt allocated to, cancellation subtracts *this receipt's own* allocation amount from the invoice's current `amount_paid` re-read under the lock (`newPaid = currentPaid − allocation`), recomputes `amount_due = total − newPaid`, and sets status to `Issued` if `newPaid` is exactly zero else `PartiallyPaid`, writing all three in one save. It never restores a remembered snapshot, so a later receipt's contribution is preserved — cancelling A when A(400)+B(600) took an invoice to Paid leaves it at 600/PartiallyPaid with B intact, not zeroed. The only refusal is a defensive would-go-negative guard.
</content>
</invoke>
