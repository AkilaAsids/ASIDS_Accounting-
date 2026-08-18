# ADR 0010 — Receivables reporting, and how an invoice's receivable account is identified

- **Status:** Accepted
- **Date:** 2026-08-18

## Context

Milestone 7 delivers three receivables reports — outstanding balance, aged receivables, and AR control
reconciliation — plus two dormant seams it turned out had to be closed first.

The seams came first because they were not optional. `ReceivableBalanceProbe` and `TaxRateUsageProbe` were
still bound to the "nothing exists yet" stubs that Milestones 2 and 3 created, even though the milestones
meant to replace them had closed. The effect was that **five documented protection rules did nothing**: an
invoiced customer could be archived, renamed or deleted, and a tax rate an issued invoice had already used
could be edited or removed. All five were confirmed by executing the real services against real data before
any code was written, not inferred from reading. The bindings moved; no rule changed.

The reports then raised one genuinely hard question, and it is the reason this record exists: **given an
invoice, which general ledger account did its receivable post to?** Getting that wrong does not produce an
error — it produces a reconciliation report that invents discrepancies, which is worse than no report.

## Decision

### D1 — The receivable account is identified as line number 1 of the invoice's journal entry

Not from the customer, and not by looking for a debit.

`customer.receivable_account_id` is mutable. Repoint a customer after their invoices were issued and their
current setting no longer describes where those invoices posted. Grouping the subledger by it would move old
balances onto the new account while the ledger kept them on the old one — two equal and opposite differences
that cancel in the grand total. The report would manufacture a discrepancy rather than find one, and the
cancellation in the total would hide it from anyone checking only the bottom line.

Line number 1 is a structural property, provable from four facts:

1. `InvoicePostingMap::for()` returns `[...receivableLines, ...revenueLines, ...taxLines]` — the receivable
   line is first by construction.
2. `receivableLines()` returns exactly one line. It returns none only for a zero total, which
   `SalesInvoiceService::issue()` refuses outright under ADR 0009 decision B4.
3. `JournalService::writeLines()` assigns `$lineNumber` from 1 in array order.
4. `journal_lines_immutable` refuses any UPDATE or DELETE once the entry is posted, so the number cannot
   drift afterwards.

**"The debit line" is explicitly rejected as an identifier.** `revenueLines()` and `taxLines()` flip to the
debit side for a net-negative group, so a single entry can carry more than one debit and "the debit line"
identifies nothing.

### D2 — The accounts the reconciliation covers are read from the ledger

Every account any invoice has posted a receivable line to, plus the company's system `trade_receivables`
account. Read from `journal_entries`/`journal_lines`, never from the set of accounts customers currently
point at: an account abandoned by every customer would drop out of that set, and a stranded ledger balance on
an abandoned AR account is exactly the thing this report exists to surface.

An account whose invoices have all been settled or cancelled still appears, with zeroes.

### D3 — AR control reconciliation accepts no as-of date

`LedgerBalanceService::balanceAsAt()` is date-addressable, but the subledger side reads **current** `status`
and **current** `amount_due`, and no invoice history exists from which to reconstruct either.

Aged at a past date the two halves would answer different questions. An invoice issued in June and cancelled
in August, reconciled as at July, shows the receivable outstanding in the ledger — correctly, because the
reversal had not happened yet — and excluded from the subledger, because its status is *now* cancelled.

Rather than accept a date and silently produce that, `arControlReconciliation(Company $company)` takes no
date at all and reports `as_of` as the day it ran. The limitation is structural rather than documented. When
Phase 4 introduces payment history, a dated variant becomes possible.

### D4 — Difference is ledger minus subledger, and every account must agree

`difference = general_ledger − subledger`, so a **positive** difference means the books carry more receivable
than the invoices account for — the direction a stray manual journal into AR shows up in. Negatives are left
visible rather than normalised.

`reconciles` is reported per account **and** requires every account to agree before the totals claim it. Two
opposite manual journals of equal size net to zero in the grand total while both accounts are wrong; a
total-only check would call that reconciled.

### D5 — Outstanding balance is a live snapshot, and excludes zero balances

`amount_due` is current state with no history, so the report offers no as-of date for the same reason as D3.
Customers with no collectable invoices, or whose balance has reached zero, do not appear — a receivables
report listing everybody who owes nothing is a customer list. Filtered in SQL with `HAVING SUM(amount_due) >
0` rather than discarded after transfer.

### D6 — Ageing runs from `due_date`, with inclusive bucket edges

From `due_date` because the domain model says so, not because it is conventional: `Customer::payment_terms_days`
exists and `dueDateFor()` derives the due date from it when the draft is written, so `due_date` is the date
the business itself committed to. Ageing from `invoice_date` would report every invoice as overdue from the
day it was raised, which for thirty-day terms is wrong for a month.

`agedReceivables()` requires an explicit `$asOf` — a report aged on an implicit "now" cannot be reproduced,
and a printed copy could never be reconciled to a later run. Days are counted as `?::date - due_date` in SQL,
because PostgreSQL subtracts two `date` columns as whole days: no timezone, no partial day, no drift between
database and PHP.

| Bucket | Days overdue |
| --- | --- |
| `not_yet_due` | < 0 |
| `days_0_30` | 0 … 30 |
| `days_31_60` | 31 … 60 |
| `days_61_90` | 61 … 90 |
| `days_over_90` | > 90 |

Every band is inclusive at both ends, so an invoice falls in exactly one. An invoice due exactly on the
cutoff is 0 days overdue and lands in `days_0_30`. A future-dated invoice is `not_yet_due` rather than
excluded — it is a real receivable that simply is not late.

### D7 — One source for "what is still owed"

Every method filters through `SalesInvoice::scopeCollectable()` and reads `amount_due`. Neither is restated
anywhere in the reporting service.

`amount_due` rather than `total − amount_paid`: they agree only because a phase-scoped CHECK pins
`amount_paid` at zero, and Phase 4 drops it. At that point the stored column is what payment allocation
maintains, and subtracting here would become a second implementation of arithmetic that phase owns.

Cancellation is excluded **by status, never by amount**. Cancelling does not zero `amount_due` — the CHECK
holds it at `total − amount_paid` — so a cancelled invoice still carries its full figure, and only the status
filter keeps it out. A report summing the column without `collectable()` would count every cancelled invoice
ever raised, and would look entirely plausible doing it.

### D8 — Reports follow the existing architecture, and add no new surface

`Company` as the first parameter, plain arrays out carrying `Money` objects and models, conversion to decimal
strings left to whatever eventually presents them — the shape `LedgerBalanceService` established. No report
DTOs: this codebase has none, and Milestone 7 is not the place to introduce the first.

The GL side asks `LedgerBalanceService::balanceAsAt()` rather than recomputing ledger arithmetic. It already
signs by normal balance and already counts reversed entries alongside posted ones, which is what makes a
cancelled invoice net to nothing on both sides — the subledger by status, the ledger by its reversal, neither
zeroed by hand.

**No Accounting file was modified.** No HTTP route, controller or resource exists for any of these reports.

## Alternatives considered

1. **Group the subledger by `customer.receivable_account_id`.** Rejected under D1: it is not evidence of
   where an old invoice posted, and the errors it creates cancel in the total.
2. **Identify the receivable line as "the debit line".** Rejected: an entry can carry several debits.
3. **Add a receivable-account column to `sales_invoices`.** Rejected: it duplicates a fact the ledger already
   owns — the same argument that rejected `reversal_journal_entry_id` in ADR 0009 — needs a migration, and
   would be null for every invoice issued before it.
4. **Restrict AR accounts to those customers currently name.** Rejected under D2: it loses abandoned
   accounts, which is where a stranded balance hides.
5. **Accept an `$asOf` on the reconciliation because the GL side supports one.** Rejected under D3.
6. **Reconcile only in total.** Rejected under D4: it cannot say which account is wrong, and hides opposing
   errors.

## Consequences

**The cost, stated plainly.** D1 couples receivables reporting to `InvoicePostingMap`'s line ordering. Reorder
the map and the reconciliation misattributes silently — no error, just wrong numbers. `ArControlReconciliationTest`
therefore asserts the ordering directly, so the map cannot be reordered without failing a test first. Anyone
changing the posting map should read that test before doing so.

**What it buys.** The reconciliation stays correct across a customer being repointed, which is the ordinary
operational event that would otherwise corrupt it. A dedicated test covers exactly that case.

**The probe bindings changed behaviour deliberately.** Customers archivable before Milestone 7 may no longer
be, and tax rates editable before may no longer be. That is five documented rules finally working, not a
regression — but it is a real change in what the application permits for existing data.

**`sales_invoice_lines.tax_code_id` gained an index**, because the rate-usage probe runs on every tax-code
update and delete rather than on a reporting path. It is the only schema change in Milestone 7.

## Known limitations and follow-ups

- **No HTTP surface.** None of the three reports is reachable over the API. A `sales.reports.view` permission
  was designed and recommended but deliberately not added, since there is nothing yet to authorize.
- **No historical reconciliation**, per D3, until payment history exists.
- **Outstanding balance excludes negative balances.** `HAVING SUM(amount_due) > 0` also excludes a customer in
  credit. Unreachable today — `amount_paid` is pinned at zero — but Phase 4 makes overpayment possible and the
  rule should be revisited then.
- **The line-ordering coupling** in D1, above.
