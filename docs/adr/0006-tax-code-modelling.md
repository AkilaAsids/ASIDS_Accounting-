# ADR 0006 — Tax codes: effective-dated rates behind a jurisdictional seam

- **Status:** Accepted
- **Date:** 2026-08-11

## Context

Phase 3 adds sales invoicing, and an invoice line needs a tax rate. That single requirement forced five
decisions, each of which had a plausible alternative and each of which is difficult to reverse once
invoices exist and cite the data.

The constraints already in place shaped all five. ADR 0002 makes a company the owner of its statutory
registrations. Phase 1 shipped `CompliancePackContract` as the seam for country-specific statutory logic
and bound `NullCompliancePack` behind it. `Money` performs exact decimal arithmetic in scaled integers and
refuses floats. The ledger is append-only, so a posted document's meaning cannot be rewritten. And the
`companies` table already records VAT and SVAT registration.

Two further pressures were specific to tax. Rates change by government policy, so a rate is a fact about a
*period* rather than about a code. And a mistake in this area is unusually dangerous: a wrong rate produces
an invoice that looks correct, posts a balanced journal entry, ties in the trial balance, and misstates a
tax return — wrong and invisible at once.

## Decision

### A1 — The product catalogue is out of Phase 3

Invoice lines are free text with a required revenue account. There is no product or service catalogue, no
SKU, and no product pricing.

A catalogue belongs to a later Items and Inventory phase, tracked in [ROADMAP.md](../ROADMAP.md) as a
**proposal** rather than a commitment. Nothing in Phase 3 depends on it, and adding one would bring its own
CRUD, API surface and screens into a milestone already carrying invoicing.

### A2 — Tax codes are company data; compliance packs declare which regimes are legitimate

The `tax_codes` table is company-owned configuration, consistent with ADR 0002 — the rates a business
charges are its own, and two companies in one workspace may be registered differently.

Jurisdictional legitimacy is a separate question and belongs to the existing seam.
`TaxCodeService::assertRegimeIsSupported()` consults `CompliancePackContract::supportedTaxRegimes()`. No
second tax-regime architecture is introduced.

**An empty `supportedTaxRegimes()` means "no restriction declared", not "deny all".** This reading is
load-bearing and easy to invert. `NullCompliancePack` — which every company resolves to today, Sri Lanka
included — returns `[]` because no pack has yet enumerated its regimes, not because the jurisdiction
forbids taxation. Treating `[]` as a deny-all would refuse every tax code the product can currently
create. A pack returning a non-empty list is making a positive statement, and anything outside that list is
refused.

Any future pack must therefore either enumerate every regime it permits, or return `[]` and leave the
`TaxType` enum and the database CHECK as the constraint.

### A3 — SVAT is a recognised type; its mechanics are deferred

`TaxType::Svat` exists alongside `Vat`, `ZeroRated` and `Exempt`. A company registered for SVAT can
classify its codes correctly today, and resolution returns whatever rate is configured — ordinarily zero.

The suspended-payment accounting is **not** implemented. SVAT's treatment differs materially from VAT and
implementing it properly needs invoice rules that do not exist yet. It is deferred to the Sri Lankan
compliance phase, which adds behaviour rather than migrating data.

`TaxType::isWithinVatSystem()` keeps exempt distinguishable from zero-rated, because a return reports them
separately and that distinction is the one most easily lost.

### A4 — Rates are stored as percentages

`18.0000` means 18%, in `numeric(9,4)`, with a CHECK bounding it to `0 <= rate <= 100`.

Not fractions. A tax authority publishes 18%, an accountant reads 18% on a screen, and storing `0.1800`
would oblige every human reading the column to convert. The upper bound is not decoration: a rate entered
as basis points — 1800 for 18% — would otherwise multiply every invoice by eighteen, and both the CHECK and
`TaxCodeService::assertRate()` refuse it, the latter with a message that teaches the convention.

Conversion to a multiplication factor happens **only at calculation time**, in `TaxCode::rateFactor()`,
using `bcdiv($rate, '100', 10)`. Exact decimal arithmetic, never a float: in binary floating point
`18.0 / 100` is not `0.18`, and the error would survive into every tax amount the ledger stored. Scale 10
is the widest fraction `Money::multipliedBy()` accepts and more than sufficient — a percentage held to four
places needs six as a fraction.

Application goes through the existing `Money::multipliedBy()` and `Money::roundedTo()`, preserving
half-away-from-zero rounding. No second money implementation exists.

### A5 — A rate change is a new row, and a used rate is immutable

Changing what a code charges means ending the current range and adding a new one. `VAT` at 18% until June
and 20% from July are two rows sharing a code.

A direct consequence: **`code` cannot carry a unique index per company**, because that would make rate
history impossible. Uniqueness and overlap prevention are therefore the same rule, stated once as a GiST
exclusion constraint over `(company_id, upper(code), daterange(effective_from, effective_to, '[]'))`,
restricted to live rows. It reuses the `btree_gist` mechanism `fiscal_years` already uses; no second
temporal mechanism is introduced. The range is inclusive at both ends, so two ranges meeting on a single
day collide — correct, because a document dated that day would otherwise have two candidate rates.

Once a specific row has been applied to a document, its `rate` and `effective_from` become immutable.
Editing them would rewrite history a filed return depends on. The rule is enforced through the
`TaxRateUsageProbe` seam, following `LedgerActivityProbe` and `ReceivableBalanceProbe`: the probe answers a
business question — has this row been applied? — and exposes nothing about the tables that will answer it.
`NoTaxRateUsage` states the truth for the current schema, and Milestone 4 binds a real implementation over
it without `TaxCodeService` changing.

Immutability covers accounting meaning, not labels. The name still changes, an identical rate may be
resubmitted, and ending a range remains permitted — that is how a change is *meant* to be made.

## Rationale

**A2's empty-list reading** is the decision most likely to be misread by whoever writes the Sri Lankan
pack, which is why it is stated three times: here, in the service method, and in the tests. Inverting it
would not fail loudly; it would refuse all tax configuration and look like a bug elsewhere.

**A4's percentage choice** trades one conversion at calculation time for readability everywhere else. The
alternative — storing fractions — moves the conversion to every read, including every screen, export and
report, and each of those is a place to get it wrong.

**A5's new-row model** is the same reasoning that makes the ledger append-only. The alternative, editing a
rate in place, is simpler until the first time someone reprints last year's invoice and finds it now shows
this year's tax.

**Resolution refuses rather than guessing.** No applicable rate raises `NoApplicableTaxRate` rather than
returning 0%, and two covering rows raise `AmbiguousTaxRate` rather than calling `first()`. A silent zero
or a planner-chosen rate is precisely the wrong-and-invisible failure described in the context.

## Consequences

- Rate history is queryable: an invoice corrected months later resolves the rate that applied on its own
  date rather than today's.
- A gap between ranges is a hard failure at invoicing time rather than a silent zero. That is deliberate,
  and it means ending a range without opening its successor is caught by the first invoice attempted.
- `AmbiguousTaxRate` is unreachable while the exclusion constraint holds. It is kept, and tested by dropping
  the constraint inside one test, because an unreachable branch that fails loudly costs nothing and one that
  guesses is how a ledger quietly goes wrong.
- The Sri Lankan compliance phase inherits three obligations: implement `supportedTaxRegimes()` completely
  or return `[]`, add SVAT's suspended-payment mechanics, and decide whether e-invoicing needs anything
  beyond the gapless numbering that already exists.
- `tax_codes.input_account_id` exists, is type-validated as an asset, and is unused until purchasing.
