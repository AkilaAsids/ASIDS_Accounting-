# ADR 0011 — The receivables reporting HTTP surface and its front end

- **Status:** Accepted
- **Date:** 2026-08-19

## Context

Milestone 7 finished three receivables reports as pure application services and stopped there: no route, no
permission, no page. ADR 0010 records what those reports *mean* — the collectable-status filter, the due-date
ageing and its inclusive buckets, the line-number-1 invariant, why the reconciliation accepts no as-at date.
It says nothing about how any of it reaches a user, and closes by listing "no HTTP surface" as a known
limitation.

Milestone 8 closed that limitation, and this record covers the decisions taken doing so. It does not restate
any report semantics; those remain ADR 0010's. Written at the closure stage rather than as each sub-phase
landed, following ADR 0009, which was likewise written in a Milestone 5 closure stage for decisions taken in
the five stages before it.

One decision here is not about reporting at all. It came out of a test and it contradicts an existing
precedent, which is the main reason this document exists.

## Decision

### D1 — Milestone 8 was narrowed to the reporting slice, and closed against that

The roadmap entry read "front end — customer and invoice screens, routing, Vitest". What was built is the
receivables reporting vertical slice: permission, three endpoints, three pages.

Chosen because the reports were the only finished domain work that **nothing at all** could reach, while
customers had a complete nine-endpoint API from Milestone 6 merely lacking a UI. Between "finished work that
is unreachable" and "finished work that is reachable but unstyled", the first is the more valuable gap to
close, and it is a smaller, independently verifiable increment than a full invoice API plus editor.

The consequence is recorded plainly rather than papered over: **Milestone 8 does not close Phase 3.** Customer
screens, the invoice HTTP surface and invoice screens were not built, and both `ROADMAP.md` and `HANDOVER.md`
carry them as outstanding rather than describing the milestone as finished.

### D2 — A denial is `AuthorizationException`, not `abort(403)`

`ReceivableReportController::authorizeReports()` throws `AuthorizationException`.
`LedgerReportController::authorizeReports()`, its direct precedent, calls `abort(403)`. **The two now
disagree, deliberately.**

`abort(403)` raises a bare Symfony `HttpException`. `ApiExceptionRenderer` has no arm for that, so it falls
through to the generic `HttpExceptionInterface` arm and renders `type: …/http-403`. Every other denial in the
application renders `…/forbidden`: that is what `ProblemCode.Forbidden` in `resources/js/types/api.ts`
branches on, what the endpoint's own `Forbidden` response documents, and precisely the discrepancy the
renderer's `AccessDeniedHttpException` arm was added to close for policy-thrown denials — its comment says so.
`abort(403)` was simply never covered by that fix.

Found by a test, not by reading: the first 8A run failed with *"Failed asserting that
'…/errors/http-403' ends with `/forbidden`"*. The test was asserting the documented contract and the
implementation was wrong, so the implementation changed. `AccountingApiTest` asserts only
`getStatusCode() === 403`, which is why the Accounting side has rendered the wrong `type` unnoticed.

**The Accounting controller was left alone.** It is an Accounting file and Milestone 8 was a Sales milestone;
under the module-boundary rule in `HANDOVER.md` §5 the crossing gets raised, not made quietly. So it is
recorded as open debt instead. Anyone fixing it should expect one assertion in `AccountingApiTest` to become
strictable from a status code to a problem type.

### D3 — A company-scoped page must reload when the company changes

`selectCompany()` posts the selection and re-fetches the session. It does **not** re-mount anything:
`App.vue` keys its `RouterView` on `route.path`, and the inner one in `AppLayout` is unkeyed. So a report
already on screen when the user switches company keeps the previous company's rows, while the heading, the
currency and every figure's formatting have already moved to the new one — one company's balances presented
as another's.

All three report pages therefore `watch` the active company and reload. Not `immediate`, so `onMounted` still
owns the first request and a fresh page makes exactly one.

Rejected: keying the `RouterView` on the company id, which would fix every page at once but re-mount the whole
shell on a switch and discard unsaved state on any form-bearing screen — a much larger change reaching well
outside this milestone. Also rejected: leaving it, matching `TrialBalancePage`. Consistency is not worth
showing an accountant one company's debtors under another's name.

`TrialBalancePage` still has the gap. Deliberate, for the same boundary reason as D2.

### D4 — The empty state is gated on a successful response, never on the row count

Every report page renders its empty state only when `meta` is present.

This is a corrected mistake, not a principle arrived at cleanly. `OutstandingReceivablesPage` originally keyed
on `rows.length === 0`, and its error handler clears the rows — so a refused or failed request rendered *"No
customer has an outstanding balance."* above the error notice. On a receivables report that is not a cosmetic
bug: it tells an accountant their debtors have all paid when in fact nothing was ever loaded. The AR
reconciliation had the identical hazard in a worse form, since a failure would have read as a clean set of
books.

Clearing `meta` on failure is what makes the distinction structural rather than remembered, and each page has
a test asserting a failure renders neither the success wording nor a table.

### D5 — The client renders verdicts and totals; it computes neither

No page sums a column, derives a difference, or infers a reconciliation verdict. Every total comes from
`meta`, and `meta.totals.reconciles` is rendered as given.

The arithmetic half follows the reasoning `LedgerReportController` already states for the trial balance: a
client summing IEEE-754 doubles produces a figure a few cents from the ledger's, and the customer then holds
two numbers with no way to choose. The **verdict** half is a separate point and the sharper one — two opposing
differences of equal size cancel in `meta.totals.difference` while both accounts are wrong, so a page reading
the total would report a clean reconciliation over a broken one. The server sends the verdict precisely so
that inference is never needed.

Each report's spec makes `meta.totals` deliberately inconsistent with the rows shipped alongside it, so a page
that ever starts computing its own totals fails in a test rather than in front of an accountant.

### D6 — The cutoff belongs to the server; the browser's clock is never consulted

`as_of` is sent only when the user has chosen a date. Blank, it is omitted as `undefined` — not as an empty
string, which reaches the API as `as_of=` and is refused as not-a-date — and the server defaults to today and
returns the date it used in `meta.as_of`, which the control is then repopulated from.

A report aged against the client's clock could not be reproduced from the response it produced, and two users
in different timezones would age one book differently. The reconciliation goes further and accepts no date at
all, per ADR 0010 D3; its `meta.as_of` is rendered as text, and a test asserts the request carries a single
argument so a stray parameter cannot creep in later.

### D7 — A discrepancy never depends on colour, and never on a total

The AR control page states each row's verdict in words — `Reconciles` or `Does not reconcile` — with colour
only reinforcing it, and raises an `AlertBanner` (which carries `role="alert"` and pairs its colour with an
icon) whenever `meta.totals.reconciles` is false. The difference is rendered exactly as it arrives: no
`Math.abs`, because the sign says which side is short, and no blanking of a zero, because here a zero is the
answer rather than an empty cell. Blanking zeroes is right on a trial balance, where an accountant scans for
the side carrying a figure, and wrong on this report.

### D8 — Report pages follow the existing conventions rather than introducing better ones

`useMoney().formatPlain`, `SurfaceCard`, `AlertBanner`, `Loading…` text, `ui.notify` for failures,
hand-written tables in `overflow-x-auto`, no store and no per-resource composable. No dependency, no generic
report component, no table component, no date picker, no breadcrumbs, no skeletons, no sidebar grouping.

Two known inconsistencies were left rather than fixed in passing. The application has **two divergent money
formatters** — `useMoney` renders `LKR 1,234,567.50` and `useFormat` renders `LKR 12,34,567.50` for the same
input, both shipped, used by different pages. Report pages use `useMoney`, matching `TrialBalancePage`, which
makes the choice unambiguous here and leaves the divergence for whoever unifies them. And `meta.pagination`
exists on paginated endpoints while **no page renders a pagination control**; the reports are unpaginated
per-company aggregates, so this milestone did not need to resolve it.

## Consequences

**Two report controllers now render 403 differently**, per D2. The Sales one matches the documented contract
and the Accounting one does not. That is worse than either uniform state and is why it is recorded as open
debt rather than left to be discovered.

**Every future company-scoped page owes D3**, and nothing enforces it. A page that forgets the watch is not
broken in any test — it simply shows the wrong company's figures after a switch. The three report pages each
carry a test for it, which is the only mechanism making the convention visible.

**The `pages/**` coverage floor stays at 0** while three pages now sit at 100%. The floor's rationale — that
mounting a screen to watch a table render tests Vue rather than this application — still holds for
declarative pages, and raising it would demand retrofitting thirteen existing ones. The 43 page specs written
here exist because each asserts a way of showing a wrong number or a wrong date, not because of the figure.

**Accessibility: the scroll containers gained a tab stop.** `overflow-x-auto` alone holds no focusable
element, so a keyboard-only user could not scroll the wide tables — on the eight-column aged report, the 90+
and Total columns were rendered but unreachable, and on the reconciliation the per-account verdict was. Each
is now `role="region"` with a name and `tabindex="0"`. `TrialBalancePage` has the same gap, untouched.

## Known limitations and follow-ups

- **`abort(403)` in `LedgerReportController`**, per D2.
- **`TrialBalancePage` does not reload on a company switch**, per D3, and has no tab stop on its table.
- **Two money formatters**, per D8. `useFormat`'s lakh grouping is the correct one for this market; the
  unification is a separate piece of work touching every page.
- **No pagination control exists anywhere**, per D8. The first genuinely unbounded list — the invoice list —
  will need one.
- **Milestone 8's remainder**: customer screens, the invoice HTTP surface, invoice screens. Per D1, carried in
  `ROADMAP.md` and `HANDOVER.md`.
- **Browser verification is partial.** The application was booted and the routes confirmed registered and
  guarded, but the authenticated pages were never rendered with data: reaching them requires entering a
  password, which the agent that built this does not do. The page specs, typecheck, production build and CI
  are the evidence for automated correctness; nobody has yet looked at the three screens.
