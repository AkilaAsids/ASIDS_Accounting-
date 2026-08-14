# ADR 0009 — Sales invoice issuing and cancellation: two number series, one ledger seam

- **Status:** Accepted
- **Date:** 2026-08-14

## Context

Milestone 5 turns a draft invoice into a statutory document and back out again. It is where money reaches the
ledger, and it was singled out during Phase 3 design review as warranting the closest scrutiny of the eight
milestones — so it was built in six reviewed stages rather than one:

| Stage | Delivered |
| --- | --- |
| 1 | `DocumentType::SalesInvoice`, the `total = subtotal + tax_total` CHECK, the issued-invoice immutability triggers |
| 2 | `InvoicePostingMap`, `InvoiceCannotBePosted`, `Account::TRADE_RECEIVABLES` and its idempotent backfill |
| 3 | `SalesInvoiceService::issue()` |
| 4 | `SalesInvoiceService::cancel()` and reversal |
| 5 | Permissions, policy, role grants, and B6 |
| 6 | This record, and the documentation closing the milestone |

The staging was not ceremony. Three of the decisions below could not be reversed once invoices existed —
a number series with gaps in it cannot be un-gapped, a column added to an invoice cannot be removed after
customers hold documents citing it, and a permission granted is difficult to withdraw. Each stage was
reviewed against the code before the next began, and the numbering defect in section B was caught by that
review rather than by a test, because no single-invoice test could have caught it.

Two Accounting boundary crossings were sanctioned for this milestone, both in Stages 1–2:
`DocumentType::SalesInvoice`, and `Account::TRADE_RECEIVABLES` with its chart-template registration and
backfill. **Stages 3 to 5 crossed no new boundary and modified no Accounting file.**

## Decision

### B — Two number series, and this is the load-bearing decision

A sales invoice draws its number from the `sales_invoice` document series. **The ledger entry it posts draws
from the journal voucher series**, because its `document_type` is `JournalVoucher`:

```
Invoice 1 issued   → INV-2026-06-0001   JV-2026-06-0001
Invoice 2 issued   → INV-2026-06-0002   JV-2026-06-0002
Cancel invoice 1   → INV-2026-06-0001 retained, reversal takes JV-2026-06-0003
Invoice 3 issued   → INV-2026-06-0003   JV-2026-06-0004
```

Cancelling retains the invoice number and consumes none.

**Why a shared counter was rejected.** `document_sequences` is keyed on `(company_id, document_type,
period_key)`. Typing the ledger entry `SalesInvoice` would have drawn both numbers from one counter: the
invoice takes 0001, its own entry takes 0002, the next invoice starts at 0003. Invoice numbers would run
1, 3, 5 — and each cancellation would consume another. That is precisely the gap
`DocumentType::requiresGaplessNumbering()` promises never to leave, and what Sri Lankan e-invoicing audits
for. **Every single-invoice test passes either way.** Only issuing several in a row exposes it, which is why
`IssueInvoiceTest` and `CancelInvoiceTest` each assert a multi-invoice sequence on both series.

**Why a new Accounting `DocumentType` was rejected.** It would have been a third boundary crossing for this
milestone, and unnecessary: journal voucher semantics already describe what the entry is, and traceability is
provided by the source document rather than by the type. Sales invoices are the first document family where
both a *document* and its *ledger entry* exist — `OpeningBalance` and `YearEndClose` are entry-only, which is
why the shared counter was never a problem before.

`JournalVoucher` is selected through `JournalEntryData::documentType`, an existing constructor parameter, at
the call site in `issue()`. No Accounting code changed.

### C — Posting and reversal reuse the existing seams

Stages 3 and 4 are wiring, not new machinery. They use `InvoicePostingMap`, `PostingService::postNew()`,
`PostingService::reverse()`, `DocumentNumberService`, `FiscalCalendarService` and `SourceDocument` exactly as
they were.

`InvoicePostingMap` stays pure — it writes nothing, posts nothing and reserves no number — which is what let
it be tested exhaustively in Stage 2 before anything could touch the ledger. Stage 3 did not fold posting
back into it.

### B1 — Accounts resolve at issue; the posted entry is the snapshot

Receivable and tax output accounts are resolved from current configuration at the moment of issue. No
snapshot columns were added to the invoice: the posted journal entry names the accounts it used, and that
entry is append-only, so it *is* the permanent record. A column duplicating it could only drift.

### B4 — Zero-total and empty invoices are refused as domain errors

A draft may sit at zero while it is being written. Issuing requires at least one line and `total > 0`, raised
as `InvoiceCannotBeIssued` rather than surfacing as a database error naming a constraint the user has never
heard of. Without this, `journal_lines_one_sided_check` would refuse the posting and the message would be
about journal lines rather than about the invoice.

### B5 — Everything is re-validated at issue

A draft written in March and issued in June has had three months in which its customer could be archived, its
revenue account reclassified, its tax code's output account cleared or its period closed. Draft-time
validation says what was true then; only the check at the moment of posting matters.

The validation is **split, not duplicated**. `assertIssuable()` covers the customer, the branch and tax-code
company ownership. The accounts — receivable, revenue and tax output, with ownership, postability and type —
stay with `InvoicePostingMap`, which already validates them and runs before the transaction opens, so its
refusals cost nothing either. Two copies of one rule drift; a division of responsibility does not. Tax-code
company ownership closed a real gap found during Stage 3: the map verified the output *account* belonged to
the company, but nothing verified the *code* did.

**Money is never recomputed.** `line_subtotal` and `tax_amount` were rounded to the currency when the draft
was written. Re-resolving a rate at issue would silently reprice a document the customer has already agreed,
and recomputing totals would risk a different rounding path producing an entry that does not balance. The
posting map sums stored values, so the entry balances by construction. A reversal likewise mirrors the
original lines with their sides swapped and the amounts **copied**, never recalculated — a recomputation
could round differently and leave a residue behind.

### B7 — Cancellation

Only an issued invoice may be cancelled. A draft is deleted instead — it consumed no number and posted
nothing, so there is nothing to reverse. An already-cancelled invoice is refused. An invoice with money
received against it is refused, which is inert today because `amount_paid` is held at zero by a phase-scoped
CHECK, and stated now so the rule exists before payments do.

Cancelling retains the invoice number, its dates and every figure. The original entry stays posted-then-
reversed in the ledger and a mirror entry is written alongside, so an auditor sees the document, the posting
and the correction.

**Which period must be open is the reversal's, not the invoice's.** `PostingService::reverse()` dates the
mirror today rather than at the original's date, because backdating a correction into a closed period is what
closing exists to prevent. An invoice from a closed March may still be cancelled today; what refuses a
cancellation is today's period being closed. `cancel()` checks this before reserving anything, so a
closed-period refusal costs nothing — `PostingService` would also catch it, but only after taking a number.

The whole operation is one transaction, opened with `lockForUpdate()` on the invoice row so a concurrent
attempt queues rather than races. The service's status check is the readable refusal; the protection is the
lock plus the immutability triggers, which refuse any update to a cancelled invoice and refuse to re-reverse
an entry.

### B3 — No `reversal_journal_entry_id`

Rejected because the relationship already exists twice over: the original entry carries
`reversed_by_entry_id`, the reversal carries `reverses_entry_id`, and both cite the invoice as their source
document. A column on the invoice would be a third copy of a fact the ledger already owns, and the copy most
likely to drift. The unique index over `source_id` deliberately excludes reversing entries, so the reversal
can cite the same invoice without colliding with the original posting.

### B6 — The line morph alias is removed

`SalesInvoiceLine::MORPH_ALIAS` and its `morphMap` registration are gone. A line is never audited separately
— the invoice's own audit entries record the document changing — and can never be a `SourceDocument`, because
what causes a ledger entry is the invoice, not one of its rows. An alias registered for neither purpose
claimed something may point there, and the first caller to believe that claim would have been wrong to. Tests
assert both that the alias resolves to null and that the constant no longer exists, so a reintroduction fails
rather than passing quietly.

### H — Authorization

Two separate sensitive permissions, following `accounting.journals.post` and `.reverse`:

| Permission | Sensitive | Sort order |
| --- | --- | --- |
| `sales.invoices.issue` | yes | 70 |
| `sales.invoices.cancel` | yes | 80 |

Separate rather than combined because raising a document a customer will pay is not the same authority as
undoing one already in the books, and a business may well grant the first without the second.

| Role | issue | cancel |
| --- | --- | --- |
| accountant | ✅ | ✅ |
| bookkeeper | ✗ | ✗ |
| viewer | ✗ | ✗ |
| administrator | ✅ automatic | ✅ automatic |

The administrator role is `PermissionCatalogue::tenantGrantableNames()`, so it picks both up without being
listed — designed behaviour from ADR 0003. No migration: permissions are code, synchronised by
`PermissionSynchroniser`, and a test proves an existing workspace acquires them rather than only a fresh one.

**Policy checks permission and company access; the service enforces state.** `SalesInvoicePolicy::issue()` and
`cancel()` do include a status check, matching `JournalEntryPolicy`, but it is **advisory** — it exists so a
client can decide whether to offer a button without attempting the operation to find out.

It cannot be more than advisory because `Gate::before` grants a tenant owner every ability outright: every
policy method is short-circuited for them, and a state rule expressed only as a policy would be silently
skipped for the person most able to do damage. `SalesInvoiceService` therefore remains the enforcement
boundary for draft-ness, the fiscal period, the posting, the payment figures and every transition rule,
backed by CHECK constraints and triggers the database applies to everyone. Two tests assert the consequence
directly: the gate says yes to an owner and the service then refuses.

`cancel()` deliberately uses the broader `hasBeenIssued()` rather than the service's stricter "must be
`Issued`", so a cancelled invoice passes the policy and is refused by the service. That is right for a
capability question, and duplicating the service's rule would leave two copies of it for a check owners never
reach anyway.

### I — Cancellation metadata is conditionally immutable

`cancelled_at`, `cancellation_reason` and `cancelled_by_id` are writable **only** as part of the issued →
cancelled transition. Before it they cannot exist; after it they cannot change.

The obvious protection — adding them to `asids_sales_invoices_immutable()`'s frozen column list — would have
refused the one update that must set them, because the trigger fires on any non-draft row. So the protection
is split, and each half does what it is good at:

- A CHECK ties all three to the status, closing the gap the frozen list would have left: without it an issued
  invoice could quietly acquire a `cancellation_reason` while remaining issued.
- The trigger freezes them on every update *except* the cancelling one, and its existing
  `OLD.status = 'cancelled'` guard already refuses every update after that.

A column-by-column audit of `sales_invoices` at the close of Stage 5 confirms the frozen set is complete:
25 columns frozen, `status` guarded by transition rules, and exactly three mutable — `amount_paid`,
`amount_due` and `updated_at`, which are the columns Phase 4 needs and are held at zero by a phase-scoped
CHECK meanwhile.

### J — Stage ordering, and the interval it created

Stage 3 built `issue()`, Stage 4 built `cancel()`, and Stage 5 added the permissions. For that interval both
operations were guarded by state and company rules but carried no capability of their own — a caller holding
`sales.invoices.draft` could reach them.

This was deliberate and was safe for one specific reason: **no HTTP or API surface existed for invoices**, and
none exists today. `routes/api.php` exposes customers and tax codes only. Nothing external could reach
`issue()` or `cancel()` during the interval. Declaring authorisation for an operation before it exists means
writing a guard nobody can test, which is how a guard ends up protecting the wrong thing; the guard arrived
with the transitions it guards, and before any exposure.

## Alternatives considered

1. **One counter for both the invoice and its ledger entry.** Rejected: invoice numbers run 1, 3, 5, and each
   cancellation consumes another. Undetectable by any single-invoice test.
2. **A new Accounting `DocumentType` for the ledger side.** Rejected: a third boundary crossing for behaviour
   `JournalVoucher` plus a source document already provides.
3. **`reversal_journal_entry_id` on the invoice.** Rejected: a third copy of a relationship the ledger owns
   twice.
4. **A single combined issue/cancel permission.** Rejected: a business granting one without the other could
   not express it.
5. **State enforcement in the policy.** Rejected: `Gate::before` makes it unreachable for owners.

## Consequences

**The cost, stated plainly.** The ledger entry no longer identifies itself as a sales invoice through
`document_type` — it reads `journal_voucher`. Anything grouping ledger entries by document type will not see
sales invoices as a family, and must join through `source_type`/`source_id` instead. That is a real
readability loss in the ledger, accepted because the alternative corrupts a statutory number series.

**What it buys.** Invoice numbering is independently gapless and stays so through cancellation. The reversal
draws from the journal voucher counter, so Stage 4 needed no numbering work at all. And no Accounting
behaviour changed: `JournalEntryData::documentType` was already a parameter.

**Traceability is stronger, not weaker.** The unique index over `journal_entries.source_id` — which excludes
reversing entries — is what makes a second posting of the same invoice impossible, at the database rather
than in the service. A test proves it by bypassing the service check entirely.

## Known limitations and follow-ups

### `issued_by_id` is not persisted — carried to Milestone 7

`SalesInvoiceService::issue()` accepts `?User $actor` and passes it into the posting path, so the ledger
records who posted the entry. The `sales_invoices.issued_by_id` column exists and is frozen by the invoice
immutability trigger. **The issue flow does not write it.**

Because the column is frozen once the invoice leaves draft, it cannot be backfilled afterwards — the value is
lost for every invoice issued before a fix. Correcting it needs a deliberate follow-up commit with its own
tests, and it is **not** part of Stage 6. Carried to Milestone 7.

### Other open items

- **The `"testing"` substring convention.** `tests/bootstrap.php` guards the development database by forcing
  any `DB_DATABASE` whose name lacks `"testing"` to `asids_erp_testing`. It fails in the safe direction, but
  a future database named otherwise is silently redirected.
- **N3 — unrelated to this milestone.** A same-workspace 403-vs-404 existence oracle, raised by Milestone 6's
  security review and recorded in `STATUS.md`. It is a platform-wide HTTP concern affecting accounts and
  journals as well, and belongs to Milestone 6 work. **It is recorded here for context only and is not a
  Milestone 5 decision.** Note that `STATUS.md` cites ADR 0008 for it while ADR 0008 contains no such section;
  reconciling that is Milestone 6's to do, not this record's.
