# ADR 0007 — Draft invoices: hard-deletable, with the issued boundary prepared but unenforced

- **Status:** Accepted
- **Date:** 2026-08-11

## Context

Milestone 4 of Phase 3 builds the invoice domain as far as drafts, and stops before issuing. That split
is not arbitrary — issuing is the moment a document becomes part of the financial record, and it drags in
the posting map, gapless numbering, duplicate-posting guards and cancellation. Keeping it out leaves a
milestone that can be reviewed on its own.

Four decisions were forced by the split. Each had a defensible alternative and each is awkward to reverse
once invoices exist.

The existing constraints shaped all four. `Money` performs exact scaled-integer arithmetic and exposes no
integer accessor. ADR 0006 made a tax rate a fact about a period, resolved through `TaxRateResolver`. The
ledger is append-only, so a posted document's meaning cannot be rewritten. Customers and tax codes both
soft-delete. And Milestone 1 gave `journal_entries` a `source_type`/`source_id` pair with a partial unique
index, so the duplicate-posting guard is already in place waiting for a document to guard.

## Decision

### B1 — WITHDRAWN. `Money` needs no change

**This decision was taken on a false premise and has been reversed. `Money` is unchanged.**

The premise was that `Money::allocate()` could not be called from outside the Accounting module, because it
takes `list<int>` weights and `Money` appeared to expose no way to obtain an amount's minor units. On that
basis a `minorUnits(): int` accessor was approved as a strictly additive change.

It was not needed. `minorUnits` is a **public promoted property** — declared `public int $minorUnits` inside a
*private* constructor — so it was always publicly readable, and `Money` is `final readonly`, which makes it
immutable. The original survey missed it by searching for method and `public readonly` declarations rather
than promoted constructor parameters.

So the header discount is allocated with `$net->minorUnits` and `Money::allocate()` exactly as it already
stood. This is the better outcome: no Milestone 2 accounting code is touched at all, and `allocate()`'s
largest-remainder behaviour remains the only allocation mechanism in the codebase.

Recorded rather than deleted, because the reasoning that a value object should not be reached around still
holds — it simply did not apply here.

### B2 — A never-issued draft is hard-deleted

Deleting a draft removes the row. No `deleted_at` column exists on `sales_invoices`.

This departs from `customers` and `tax_codes`, which soft-delete, and the departure is the point: a draft
that was never issued is not an accounting document. Nothing cites it, no return reports it, and no
auditor will ask about it. Retaining it as a tombstone would imply otherwise.

Deletion is refused for anything other than `draft`. An issued invoice is a statutory record and cannot be
removed at all — which is why no soft-delete column is needed rather than why one is. Retention rules for
issued and cancelled documents belong to Milestone 5.

### B3 — The issued boundary is prepared structurally; enforcement lands with issuing

An invoice stays freely editable while `draft`. Once issued, its accounting-impacting fields must not
change.

Milestone 4 creates the structure that boundary needs and **no behaviour that crosses it**:

| Column | Prepared for |
| --- | --- |
| `status` | CHECK covers all five states, so Milestone 5 adds no migration |
| `number` | Nullable, with `(number IS NULL) = (status = 'draft')` |
| `issued_at`, `issued_by_id` | Nullable, with `(issued_at IS NULL) = (status = 'draft')` |
| `journal_entry_id` | Nullable and UNIQUE, with a draft forbidden from carrying one |

Those CHECKs are the preparation. They make an inconsistent state unrepresentable — a draft with a number,
an issued invoice without one, a draft already pointing at a ledger entry — before any code can produce
one.

**The immutability trigger itself belongs to Milestone 5.** It has nothing to protect in Milestone 4,
because no invoice can leave `draft`, and writing it now would mean writing it against a transition that
does not exist and cannot be tested. Milestone 5 adds it in the same migration that adds issuing, modelled
on `asids_journal_entries_immutable`, which enumerates every column by name so a later addition cannot
become silently mutable.

### B4 — `TaxCode::rateFactor()` fails as a domain error, not a `TypeError`

`rateFactor()` and `chargesTax()` called `bcdiv`/`bccomp` on `$this->rate` without checking it was
populated. On a model with no rate — an unsaved instance, or a partial `select()` omitting the column —
that raised `TypeError: bcdiv(): Argument #1 must be of type string, null given`.

Found during the Milestone 3 hardening review and deliberately left, on the grounds that Milestone 4 is
`rateFactor()`'s first production consumer and a guard is better written against a real call site than a
hypothetical one. This is that milestone.

Both methods now raise a domain error naming the problem. The failure was never reachable through a
current code path; the fix is about what happens when Milestone 4's invoice code holds a `TaxCode` it did
not fully load.

### B5 — A draft may total zero; the positive-total rule belongs to issuing

Neither the database nor the draft service requires a positive total. `total >= 0` is the constraint;
`total > 0` is not.

The Phase 3 design said an invoice total must be positive, and that is right — for a document. It is wrong
for a draft, in two ordinary cases: an invoice being built up has no lines yet and therefore totals zero, and
a fully discounted one legitimately totals zero. Enforcing positivity on the draft would refuse both, so a
user could not save work in progress.

The rule moves to the issuing transition in Milestone 5, where an invoice stops being a working document and
becomes an accounting one. That is also the only place it can be checked meaningfully: a draft is expected to
be incomplete, and the point of issuing is asserting that it no longer is.

Negative totals stay refused at every stage. A negative invoice is a credit note, which is its own document
with its own numbering and its own posting — not an invoice with a minus sign.

## Rationale

**B1** was withdrawn, and the withdrawal is the useful part of the record: an architectural change was
approved to close an API gap that did not exist. The lesson is about the survey, not the design — a promoted
constructor parameter is a public property, and a grep for `public function` and `public readonly` will not
find it. No earlier milestone's code is touched by Milestone 4 as a result.

**B2** looks inconsistent with the module until the question is asked the other way round. Soft deletion
exists so a record can be recovered and so references to it stay resolvable. A draft nobody has referenced
needs neither.

**B3** separates structure from behaviour deliberately. The CHECK constraints cost nothing now and make a
whole class of invalid state impossible before any code exists to create it; the trigger costs a migration
that would have to be written blind.

**B4** converts a crash into a message. It changes no working behaviour, because the crash was unreachable.

## Deferred design considerations

Recorded here so they are not rediscovered as surprises.

**`discount_total` combines line and header discounts.** The column reports the sum of both, which is what a
reader of the document wants, but it means the header portion cannot be read back from the header row alone.
`SalesInvoiceService::updateDraft()` therefore reconstructs it from the difference between each line's
gross-less-own-discount and its stored subtotal. That reconstruction is exact — the difference *is* the
allocated share — but it is indirect, and a dedicated `header_discount` column would be plainer.

Deliberately not changed in Milestone 4: the schema was reviewed and approved in Stage 1, and altering it
mid-milestone to improve a private method's clarity is not a trade worth making. Revisit at the Milestone 4
final review or when the invoice HTTP surface is designed, whichever comes first.

## Consequences

- `Money` is untouched. Its `allocate()` remains the only allocation mechanism in the codebase and must stay
  so; `InvoiceTotalsCalculator` is its first production caller.
- `sales_invoices` has no `deleted_at`. Any future need to retain a removed draft is a migration, not a
  flag flip — and should be argued for rather than assumed.
- Milestone 5 inherits three obligations: the immutability trigger, the transition that sets `number`,
  `issued_at`, `issued_by_id` and `journal_entry_id` together, and the cancellation path. The CHECK
  constraints already refuse any partial version of that transition.
- The `journal_entry_id` UNIQUE constraint is the database-level duplicate-posting guard. It exists from
  Milestone 4 and has nothing to guard until Milestone 5, which is the correct order: the constraint is in
  place before the code that depends on it.
