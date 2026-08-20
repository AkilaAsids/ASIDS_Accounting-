# ADR 0012 — The sales invoice HTTP surface, and the company coherence guard it carries

- **Status:** Accepted
- **Date:** 2026-08-20

## Context

`SalesInvoiceService` has carried the whole invoice domain since Milestone 5 — `createDraft`, `updateDraft`,
`deleteDraft`, `issue`, `cancel` — with 156 tests across five files proving it, and **nothing able to reach any
of it.** No controller, no route, no resource. Milestone 8 was narrowed to receivables reporting (ADR 0011 D1),
which left the invoice HTTP surface as the largest single gap in Phase 3 and the thing blocking every invoice
screen.

Milestone 9 closes it in three parts: **9-pre** hardened two defects in the issuing path that an endpoint would
have turned into HTTP 500s, **9A** added the seven endpoints, and **9B** is this record.

Two things shaped the work more than anything else. The domain was already complete, so almost every decision
below is about what the transport layer must *not* do. And one decision — the company coherence guard — is a
deliberate departure from a recorded platform-wide position, which is the part of this record most worth
reading.

## Decision

### D1 — Invoices are exposed under `sales-invoices`, not `invoices`

Seven operations, nested under the company for the same reason the chart of accounts and the customers are: an
invoice belongs to one legal entity, and a flat route invites a query that forgets to scope by it.

| Operation | Method and path |
| --- | --- |
| `listSalesInvoices` | `GET companies/{company}/sales-invoices` |
| `createSalesInvoice` | `POST companies/{company}/sales-invoices` |
| `getSalesInvoice` | `GET companies/{company}/sales-invoices/{invoice}` |
| `updateSalesInvoice` | `PUT companies/{company}/sales-invoices/{invoice}` |
| `deleteSalesInvoice` | `DELETE companies/{company}/sales-invoices/{invoice}` |
| `issueSalesInvoice` | `POST companies/{company}/sales-invoices/{invoice}/issue` |
| `cancelSalesInvoice` | `POST companies/{company}/sales-invoices/{invoice}/cancel` |

`sales-invoices` rather than the shorter `invoices` because **Phase 5 brings purchase invoices**. The flat name
would then either be ambiguous or need a rename, and renaming a route is breaking twice over here: route names
are load-bearing (`AccountLinkService` signs URLs by name) and any integrator built against the old path.

**No `restore` route and no `withTrashed()`.** ADR 0007 B2 gives `sales_invoices` no soft-delete column: a
never-issued draft is hard-deleted because it is not an accounting document, and an issued invoice is a
statutory record that cannot be deleted at all. Customers and tax codes both have restore endpoints; invoices
have nothing to restore *from*, so the route would be surface that can only ever fail.

**No new permission.** All four `sales.invoices.*` capabilities and `SalesInvoicePolicy` have existed since
Milestone 5. **No `two-factor` step-up** on issue or cancel despite both being marked `sensitive`, matching
every `accounting.*.manage` route and `accounting.journals.post`/`.reverse` — the position ADR 0008 D6.3
recorded.

### D2 — Every route that binds an invoice asserts it belongs to the url company

This is a deliberate, Sales-invoice-specific exception to [ADR 0008](0008-sales-http-api-and-customer-update-semantics.md)
**D6.1**, and it is the decision in this record most likely to be mistaken for an inconsistency later.

Nested route bindings are not parent-scoped anywhere in this platform — `scopeBindings()` appears nowhere. So
`/companies/A/sales-invoices/{invoice of company B}` resolves a foreign invoice whenever the caller is a member
of both companies, because `SalesInvoicePolicy` checks membership of the **invoice's** company and passes. ADR
0008 D6.1 examined exactly this, accepted it platform-wide, and explicitly recommended *against* a Sales-only
fix: it is a URL/entity coherence gap rather than a privilege escalation, since the caller could reach the same
row under its correct URL anyway, and fixing it belongs to a platform-wide decision applied to every module at
once.

**That reasoning is sound for reads, and it does not extend to these endpoints.** `ResolveActiveCompany`
publishes the **url** company into `RequestContext`, and `RequestContext` is what stamps `company_id` onto every
audit entry the request writes. Issuing or cancelling company B's invoice under company A's URL therefore posts
to B's ledger while the audit trail records the act against A. That is not a coherence nit; it is a
misattributed audit record of a ledger write, on the two most consequential operations in the module.

So `SalesInvoiceController` checks it explicitly, following the existing Organization-module pattern —
`BranchController::assertBelongsToCompany()` and the equivalent in `CompanyMembershipController` — rather than
inventing a mechanism:

```php
private function assertBelongsToCompany(Company $company, SalesInvoice $invoice): void
{
    if ((string) $invoice->company_id !== (string) $company->getKey()) {
        throw BusinessRuleViolation::make(
            code: 'invoice-company-mismatch',
            message: 'That invoice does not belong to the specified company.',
        );
    }
}
```

Checked **before** the policy, so a mismatch answers as the addressing error it is rather than as a permission
problem, and checked explicitly rather than through scoped bindings so the guarantee does not depend on how the
route was registered — the reasoning `BranchController`'s own docblock gives.

**What this decision is not.** It does not amend ADR 0008 D6.1, which remains the platform's position, and it
does not make the guard a platform-wide policy. Customers, tax codes, accounts and journal entries are
unchanged and still carry the gap. **The broader binding problem remains a platform-wide concern** and should
be resolved once across every module — `->scoped()` bindings, or a path-company assertion in the base
controller — rather than by copying this method into each module as it happens to need it. ADR 0008 D6.1 already
recommends that, and this exception does not replace it.

### D3 — `POST` accepts `issue: true`, and both happen in one transaction

Following `JournalEntryController::store()`, whose reasoning applies unchanged: a salesperson invoicing a
walk-in customer has no use for an intermediate draft, and making them issue two requests leaves a window where
a half-made document is visible to everyone else.

One transaction covers both, and that is the substance rather than tidiness. Drafting commits an invoice;
issuing can still refuse it — a closed period, a customer archived since the draft was written, a reclassified
revenue account, or a caller who may draft but not issue. Left as two commits, **every one of those refusals
would leave the draft behind**, so a salesperson who picks the wrong account and is told so would find a
half-made invoice in the books for each attempt.

The `issue` authorisation check sits **inside** the transaction, after the draft exists, so the policy still
sees a real invoice and the caller still gets a 403 rather than a 422 — it simply no longer leaves a row behind.
A bookkeeper sending `issue: true` receives `forbidden` 403 and the invoice count is unchanged; a test asserts
the count, because that is the only thing that distinguishes this from the naive implementation.

Numbering and posting remain entirely service-owned. The controller passes no number and cannot: the number is
reserved inside the issuing transaction from the `sales_invoice` counter, and the journal entry draws `JV-…`
from a separate counter (ADR 0009), because one counter feeding both would produce invoice numbers 1, 3, 5.

### D4 — `capabilities` are state **and** authorisation, asked separately

The resource reports what the caller may do with this invoice *right now*:

| Flag | Rule |
| --- | --- |
| `can_update` | `isEditable()` **and** `can('update', invoice)` |
| `can_delete` | `isEditable()` **and** `can('delete', invoice)` |
| `can_issue` | `isDraft()` **and** `can('issue', invoice)` |
| `can_cancel` | `status === Issued` **and** `can('cancel', invoice)` |

Both halves are required, and **the gate alone will not do**, because `Gate::before` grants a tenant owner every
ability outright. Every state guard inside `SalesInvoicePolicy` is short-circuited for an owner, so asking the
gate on its own reports that an owner may issue an invoice that is already issued. `JournalEntryResource` carries
the same note for the same reason.

`can_cancel` tests `status === Issued` and **not** the policy's `hasBeenIssued()`. The policy is deliberately
looser — a cancelled invoice returns `true` there — because it answers a *capability* question and leaves the
particular invoice to the service, which permits only `Issued`. A cancelled invoice has historically been
issued, which is exactly why the looser predicate is right in the policy and wrong here: copying it would offer
a Cancel button on something already cancelled, which can only produce an error. Tested for an accountant, a
bookkeeper, a viewer and an owner, across draft, issued and cancelled.

None of this is a security boundary. The service refuses every transition it should regardless of what the
resource reports, and database triggers refuse the writes underneath that. It exists so a client is not offered
an action certain to fail.

### D5 — The HTTP layer computes nothing

`SalesInvoiceController` does not calculate or decide: totals, tax, invoice numbering, journal posting, the
receivable account, the revenue account, the tax output account, the cancellation reversal, fiscal period state,
or invoice status. Each is `SalesInvoiceService`'s, and the controller reads as a short list of delegations —
the shape `ApiController`'s own docblock asks for.

There is no settable `status`. Issuing and cancelling are named endpoints for the reason
`JournalEntryController` gives about posting and reversing: they are different capabilities held by different
people, irreversible in different ways, and a `PUT` that could set `status: issued` would make the draft/issue
split a matter of what the client chose to send.

### D6 — Validation splits at the boundary: shape in the request, domain in the service

`StoreSalesInvoiceRequest` and `UpdateSalesInvoiceRequest` check shape and type only. Ownership, account type,
postability, tax effectiveness, the totals, the fiscal period and every other domain rule stay with the
service, which produces an accurate code — `customer-outside-company`, `revenue-account-not-postable`,
`tax-rate-date-not-covered` — rather than a generic "invalid". This is the split `StoreCustomerRequest`
documents for `branch_id` and `receivable_account_id`, and the API inherited all **43** typed refusals the
domain already knew how to express without adding one.

- **Money is a decimal string**, four places, `^-?\d{1,15}(\.\d{1,4})?$`. Rejected rather than rounded:
  silently dropping a digit is how a total stops matching the document it came from. A submitted JSON number is
  coerced to a string first, as `StoreJournalEntryRequest` does, so the common case is not refused as pedantry —
  though the float has already happened by then for a value like `10.005`, which is why the API documents
  strings.
- **`lines[].tax_code` is a code, not an id**, capped at 32 characters. Which rate applies depends on the
  invoice date, and only company + code + date identifies the correct effective-dated row. An id would let a
  caller name an expired or future rate directly and bypass `TaxRateResolver`. A uuid is 36 characters, so the
  cap refuses one structurally.
- **`lines` requires at least one**, not two. The two-line minimum on a journal entry exists because an entry
  needs something debited *and* something credited; that has no analogue on an invoice.
- **`update` is entirely `sometimes`**, so `$request->validated()` passes through as the attribute array
  `updateDraft()` consumes: key absent = untouched, key present with `null` = clear. Load-bearing for
  `reference`, `branch_id` and `discount_amount`, each of which would otherwise be permanent once set. ADR 0008
  D1 moved customers to the same shape.
- **The cancel reason caps at 255**, not the 500 `sales_invoices.cancellation_reason` holds, because the service
  writes the same string to `journal_entries.reversal_reason`, which is `varchar(255)`. A longer reason would be
  accepted by one column and refused by the other mid-transaction, as a database error rather than a message.
  This was finding F-2 of the Milestone 9 audit, resolved in validation with no migration.
- **Nothing computed or record-owned is accepted**: `status`, `number`, `total`, `subtotal`, `tax_total`,
  `discount_total`, `amount_paid`, `amount_due`, `currency_code`, `exchange_rate`, `company_id`, `tenant_id`,
  `journal_entry_id`, `issued_*`, `cancelled_*`, `created_by_id`. `SalesInvoice::$fillable` is three free-text
  fields and `SalesInvoiceLine::$fillable` is empty, so a caller sending one would be ignored rather than
  obeyed; refusing it at the boundary keeps the contract honest about what the API reads.

### D7 — Journal narrations are clipped to the ledger's column, at all four sites

Completed in **9-pre** (`60cc8ea`) as finding F-1.

Issuing writes four descriptions: one on the journal entry, and one each on the receivable, revenue and tax
lines. Every one is composed from names the user controls, and the arithmetic never worked — `customers.name`
and `accounts.name` are both `varchar(255)`, and so are `journal_entries.description` and
`journal_lines.description`. A long but entirely valid trading name pushed a description past its column,
Postgres raised `22001`, and **the invoice could not be issued at all**: a generic 500 naming nothing, for a
customer record the system had accepted without complaint.

The Milestone 9 design audit found one site. The regression test written for it found the other three, all in
`InvoicePostingMap`, and two of those compose *two* user-controlled names as `account.name — customer.name` —
where no per-part character budget can work, because a 250-character account name leaves nothing to award the
customer.

So the rule is one rule, in `LedgerNarration`, applied at all four: clip the composed narration to 255
characters with an ellipsis when it did not fit. Each site puts its most identifying part first — the invoice
number, or the account name — so clipping the tail keeps what a reader needs to place the entry, without the
rule knowing anything about their shapes. Measured in **characters**, not bytes: the em dash these narrations
use as a separator is one character and three bytes, and counting bytes would clip narrations that fitted.

Nothing is hidden by it. The entry is tied to its invoice by `source_id`, which is exact and never truncated.

**`InvoicePostingMap` was not redesigned.** The change is the description argument and nothing else. Line
ordering, account grouping, account selection, amounts and sides are untouched — and that matters beyond
tidiness, because **ADR 0010 D1 identifies an invoice's receivable account as line number 1 of its journal
entry**, so a disturbed ordering would silently misattribute every balance in the AR control reconciliation.
`IssueInvoiceTest` now asserts the receivable is line 1 with its expected debit alongside the length
assertions, so the two cannot drift apart, and `InvoicePostingMapTest` and `ArControlReconciliationTest` passed
unchanged.

### D8 — `issue()` locks and re-reads before it numbers or posts

Completed in **9-pre** (`60cc8ea`) as finding F-3.

Every check in `issue()` ran before its transaction opened, which is what two racing requests both do: both
read `draft`, both pass, and the loser reached the unique index over `journal_entries.source_id` and came back
as a raw `QueryException`. A *sequential* second attempt was already refused cleanly as `invoice-not-a-draft`;
the racing one was a 500 for the same condition.

`issue()` now opens its transaction by locking the row and re-reading it — the way `cancel()` already did, ten
lines further down the same file — and re-checks the status **before** any document number is reserved, so the
loser costs no number and no contention on `document_sequences`.

**The database protections were not weakened and are not replaced.** The unique index still decides the case the
application cannot see, and the immutability trigger still refuses to rewind an issued invoice to draft. Both
keep their own tests, unchanged. The lock is what turns the ordinary race into a readable refusal instead of a
stack trace.

### D9 — The owner/non-owner state-transition asymmetry is recorded, not fixed

Verified while writing the 9A tests, and it is **existing Milestone 5 policy behaviour rather than anything
Milestone 9 introduced.**

`SalesInvoicePolicy::issue()` guards on `$invoice->isDraft()` and `cancel()` on
`$invoice->status->hasBeenIssued()`. For anyone `Gate::before` does not short-circuit, those guards resolve
*before* the service is reached. So an out-of-state transition answers differently depending on who asks:

| Caller | Re-issuing an issued invoice | Cancelling a draft |
| --- | --- | --- |
| Accountant, bookkeeper, viewer | `forbidden` **403** | `forbidden` **403** |
| Workspace owner | `invoice-not-a-draft` **422** | `invoice-not-issued` **422** |

The refusal is correct in both cases and the ledger is never at risk either way. What differs is its shape, and
the consequence for a client is real: **it cannot distinguish "you lack the permission" from "the invoice is in
the wrong state" without also knowing whether the user is an owner.**

This is labelled a **known API-consistency consideration for future review**, not a defect fixed here. It is an
intentional consequence of the owner bypass that `SalesInvoicePolicy`'s own docblock sets out at length — the
policy's state checks are advisory, the service is authoritative, and a state precondition expressed only as a
policy would be silently skipped for the one person most able to do damage. Changing it means changing policy
code, which was outside Milestone 9's approved scope. **Both paths are now pinned by tests**, so the asymmetry
is visible in the suite rather than surprising, and whoever reviews it has the behaviour written down.

### D10 — The existing HTTP contract is reused unchanged

Nothing about the platform's contract was extended for invoices.

- Success is `{ data, meta }` through `ApiResponse`; failure is an RFC 9457 problem document with a stable
  `type` a client branches on.
- `forbidden` 403 for a denial, and the renderer never echoes which permission was missing — that enumerates
  the catalogue to an attacker. `not-found` 404 for a missing or inaccessible row, deliberately
  indistinguishable so the endpoint cannot confirm an id exists in another workspace.
- Domain refusals are typed **422** `BusinessRuleViolation` codes; `ResourceConflict` renders **409** where a
  constraint race surfaces one. **204** on delete, **201** on create.
- Money is a decimal string at four places in both directions. Dates are `YYYY-MM-DD`; timestamps are ISO-8601;
  enums carry `->value` plus a `_label` sibling.
- List queries go through `QueryCriteria` with allow-listed sort, filter and include sets, because `?sort=` and
  `?filter[]=` reach an `ORDER BY` and a `WHERE`.
- State transitions are named endpoints, never a settable status field.
- `openapi.yaml` gained seven operations and three schemas, and `scripts/check-openapi.mjs --require-routes`
  holds **bidirectional** parity: a served route without an operation fails CI, and so does an operation without
  a route.

## Alternatives considered

1. **`invoices` as the route segment.** Rejected under D1: Phase 5's purchase invoices would force a breaking
   rename of a signed, integrator-facing path.
2. **Relying on `SalesInvoicePolicy` alone for company coherence.** Rejected under D2. The policy passes for a
   member of both companies, and the audit trail takes its company from the URL.
3. **Fixing the binding gap platform-wide in Milestone 9.** Rejected as scope: it touches every module's routes
   and belongs to its own reviewed change. D2 is explicit that this remains the right long-term answer.
4. **Scoped route bindings (`->scoped()`) for the invoice routes only.** Rejected: the guarantee would then
   depend on how the route was registered, and a later route added without it would silently lose the
   protection. `BranchController` rejected it for the same reason.
5. **Two requests for draft-then-issue.** Rejected under D3: every issuing refusal would leave a draft behind.
6. **Authorising `issue` before the transaction opens.** Rejected under D3: the caller would get the right 403
   and an orphaned draft with it.
7. **Building `can_cancel` from `SalesInvoicePolicy::cancel()`.** Rejected under D4: it would offer Cancel on a
   cancelled invoice.
8. **Validating ownership and postability in the FormRequests.** Rejected under D6: it trades a precise refusal
   for a generic one, and duplicates rules the service already enforces and tests.
9. **Widening `journal_entries.description`, or narrowing `customers.name`.** Rejected under D7: the ledger
   column is not this module's, one over-long narration is not a reason to migrate Accounting, and narrowing the
   customer field does nothing for names already stored.
10. **Catching the `QueryException` and translating it to a 409.** Rejected under D8 in favour of closing the
    race window itself. Translating the symptom would leave the window open and the constraint doing work the
    application can do first.

## Consequences

**The cost, stated plainly.** D2 puts a company-coherence check in one module and not the others, so the
platform is now inconsistent in a way a reader can mistake for an oversight in either direction — either the
invoice guard looks like an unnecessary fork, or the customer endpoints look like they forgot it. That is why
this record exists and why ADR 0008 D6.1 now carries a pointer to it. Anyone tempted to remove the guard for
consistency should read D2 first; anyone tempted to copy it into every controller should read the last paragraph
of D2 instead.

**What it buys.** The two operations that write to the ledger and the audit trail cannot be attributed to the
wrong company, and every route that binds an invoice is covered by a test that proves it.

**D9 leaves a real inconsistency in the API.** It is recorded rather than resolved, and it will need a decision
before any client is written against the invoice endpoints in earnest — a front end will otherwise have to
special-case the owner.

**The 43 domain refusals are now client-visible.** Every one of them is a documented problem `type` an
integrator can branch on, and every one is also a promise: renaming a code is a breaking change.

## Known limitations and follow-ups

- **The platform-wide binding gap** remains open for customers, tax codes, accounts and journal entries, per
  D2. It is the right thing to fix once, across all modules.
- **The owner/non-owner asymmetry** in D9 is unresolved by decision.
- **No front end.** Milestone 9 was HTTP only. Customer, invoice and tax-code screens remain outstanding, as
  ADR 0011 D1 recorded and `ROADMAP.md` tracks.
- **`LedgerReportController` still uses `abort(403)`**, so the Accounting reports render `type: …/http-403`
  while every other denial in the application renders `…/forbidden`. `ReceivableReportController` and the
  invoice controller both produce the documented code. Untouched here and still open.
- **Pagination has no client**, and no page renders `meta.pagination`. The invoice list is the first genuinely
  unbounded collection in the API, so whoever builds its screen meets this first.
