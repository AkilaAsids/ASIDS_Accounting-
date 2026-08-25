# Phase 3 front end — Customer and Invoice screens — Design

**Stage: UI/UX design · written against the approved Gate‑1 decisions.** Written by the UI/UX Designer on
Minions delivery run "Phase 3 frontend" (branch `feature/phase3-frontend`). Turns
[`docs/PHASE-3-FRONTEND-REQUIREMENTS.md`](PHASE-3-FRONTEND-REQUIREMENTS.md) — specifically §4 (all 12
screens), §5 (NFRs) and §9 (the binding Gate‑1 decisions) — into developer-ready layout, state and
interaction specs. This document specifies **experience only**: layout, reused primitives, states,
validation display, confirmation patterns, permission/capability visibility, responsive behaviour and
accessibility. It makes no architecture, stack or component-implementation decision beyond what §9 already
settled; anywhere it needs one that isn't settled, it says so and flags it for the Solution Architect rather
than deciding it.

Visual language is taken entirely from the existing Sales and Accounting pages already in
`resources/js/pages/` — `OutstandingReceivablesPage.vue`, `AgedReceivablesPage.vue`, `ArControlPage.vue`,
`ChartOfAccountsPage.vue`, `UsersPage.vue` — and the four primitives in `resources/js/components/ui/`
(`SurfaceCard`, `BaseButton`, `TextField`, `AlertBanner`) plus the app-level `PermissionGate`,
`NoticeStack` and `StepUpDialog`. No new visual style is introduced. Two new interaction patterns are
required by the Gate‑1 decisions (a destructive-action confirm dialog, and pagination) and are specified in
full in §1 below, since nothing in the codebase currently does either.

---

## 1. Interaction & state conventions (shared by both lanes)

Read this section once; every per-screen spec below refers back to it by name rather than repeating it.

### 1.1 The four states, and how a screen is keyed on them

Every data-bearing screen (list, view, editor) carries exactly these states, matching the pattern already
established by `OutstandingReceivablesPage.vue` / `AgedReceivablesPage.vue` / `ArControlPage.vue`:

- **Loading** — `loading.value === true`. Render `<div class="py-12 text-center text-sm text-content-muted">Loading…</div>` in place of the content area (table body, form, detail panel). Nothing else in the surface changes shape under it, so nothing reflows when data arrives (avoids layout jump — ui‑ux‑pro‑max "Content Jumping", Layout/High).
- **Error** — the last request failed. Data arrays/objects that back the view are **cleared**, never left showing the previous successful response (ADR 0011 D4's corrected rule, carried into §4.1.5/§4.6.6 of the requirements). The failure is surfaced via `ui.notify('error', …)` using `ApiError.problem.detail`, exactly as every existing page does. The content area does **not** additionally render an inline "error" panel inside the card — the notice toast is the error state; the card falls through to its empty-state markup only if that markup is itself keyed on a successful response (see next point), otherwise it renders nothing.
- **Empty** — a successful response with zero rows/no record. Keyed on the *response having landed*, never on `array.length === 0` alone: a boolean `meta`/`loaded` flag (or equivalent) must be true. This is the exact bug class ADR 0011 D4 fixed on the reporting pages and the requirements (§4.1.5, §4.6.6) explicitly re-impose it here. Copy is specific to the screen (see each screen's spec), never a bare "No data."
- **Success** — the data table, form, or detail panel renders normally.

This is a strict precedence: **loading** overrides everything; otherwise **error** clears data and defers to
the toast; otherwise **empty** vs. **success** is decided by row count on a landed response.

### 1.2 Confirmation patterns — three tiers, not one

The requirements (§3, §4.5.2, §4.10.1, §4.11.1, §4.12.1, Gate‑1 #2) distinguish actions by consequence. Three
distinct confirmation UIs map to three distinct risk tiers. Using the same `window.confirm()` for all three
(the existing codebase's only precedent, in `ChartOfAccountsPage.vue`/`UsersPage.vue`) is **not** carried
forward for tier 2 and 3 — Gate‑1 #2 explicitly asks for more friction than a browser confirm for hard
delete, and issuing/cancelling an invoice is the single most consequential action in this wave (§4.10.1).

**Tier 1 — Ordinary confirm** (`window.confirm`, unchanged from existing pages)
Used for: customer **archive**, customer **deactivate**, customer **restore/reactivate**. These are
reversible or low-consequence. Matches `ChartOfAccountsPage.vue`'s `archive()` exactly — no new component.

**Tier 2 — Modal confirm dialog, reason optional or required, explicit acknowledgement text**
Used for: **invoice issue**, **invoice cancel** (reason required), customer/invoice **restore after a
conflict is possible**. A modal (built on the same shape as `StepUpDialog.vue`: `Teleport to="body"`,
`role="dialog" aria-modal="true" aria-labelledby`, focus moved to the first control on open, `Esc` closes it
as Cancel, backdrop click closes it as Cancel). Contents:
- A heading naming the action and the record (e.g. "Issue invoice INV‑DRAFT‑… for Silva Traders?").
- One or two sentences of consequence copy, in the exact framing the requirements specify per action (see
  §4.10/§4.11 below) — never generic "Are you sure?".
- For cancel: a required reason `<textarea>` (3–255 chars, live character count, submit disabled until
  valid).
- Two buttons: a `BaseButton variant="danger"` (or `primary` for issue, since issue is not itself "delete"‑shaped) labelled with the verb ("Issue invoice", "Cancel invoice"), and `variant="secondary"` labelled "Go back" / "Cancel" (careful: when the dialog itself is *about* cancelling an invoice, its own dismiss button must say "Go back", never "Cancel," to avoid the reader misreading which button cancels the invoice and which cancels the dialog).

**Tier 3 — Typed/explicit confirm dialog, for hard delete only**
Used for: customer **delete**, invoice draft **delete** (Gate‑1 #2: "behind a secondary control … with a
typed/explicit confirm dialog; never a primary button"). Same modal shell as Tier 2, plus:
- Copy stating plainly that the action is **permanent and has no restore path** — this is the one
  distinguishing fact from archive, and §4.12.3/§4.5 both call out the confusion risk between delete and
  archive explicitly, so the dialog must name the difference in words: "This permanently removes the
  customer. Archiving is reversible and keeps the record; this does not."
- An explicit-confirm control: the reader must type the record's identifying token (customer `code`,
  invoice `number` while issued — but see the note below on drafts) into a text input before the danger
  button un-disables. **For a draft invoice**, `number` is null (§4.6.4) — there is nothing stable to type.
  Use the checkbox variant instead for drafts and for customers with no natural short token risk: a single
  required checkbox labelled exactly "I understand this cannot be undone," which must be checked before the
  danger button enables. This is a UX-level choice (typed token vs. checkbox) driven by whether the record
  has a stable human-readable identifier at the moment of delete; it is not left inconsistent across the two
  entities — the rule is: **typed token when a `code`/`number` exists on the record being deleted, checkbox
  otherwise.**
- The danger button is disabled until the typed/checked condition is met, and is `variant="danger"`, never
  the button style used for tier‑1/2 confirms — visually distinct so a user cannot habituate to "the red
  button always confirms."

**Where "Delete" lives (Gate‑1 #2, secondary control):** never a peer of "Edit"/"Archive" as a first-class
button. Both the customer row/detail actions and the invoice row/detail actions place "Delete" inside an
overflow (kebab, `⋮`) menu opened by a `BaseButton variant="ghost"` icon button labelled
`aria-label="More actions for {{ name/number }}"`. The menu is a simple popover list of text buttons
(reuse the same open/close/focus-trap mechanics the Tier‑2/3 dialog needs — see §1.7 on new components).
"Archive"/"Deactivate"/"Restore" may also live in the overflow menu or as secondary buttons next to "Edit"
— either is acceptable per screen (see each screen's spec) — but "Delete" is **always** in the overflow
menu, styled as `text-danger` inside that menu so it is still visually distinguishable, never colour-only
(it also carries the word "Delete", never an icon alone).

### 1.3 Capabilities-based gating — the double gate

Every action button in both lanes is gated on **two independent conditions**, per requirements §3:

1. `PermissionGate` (or an equivalent `v-if` on `auth.can(...)`) — hides the control from a user who could
   never perform the action under any state, e.g. "Add a customer" behind `sales.customers.manage`.
2. The resource's own `capabilities.can_*` flag — hides the control when the *state* makes the action
   impossible right now for anyone (a bookkeeper who can `sales.invoices.draft` still must not see "Issue"
   because `capabilities.can_issue` folds in "is a draft").

**Design rule: never substitute one for the other.** A control whose visibility depends on state (issue,
cancel, delete-with-no-restore, edit-a-draft, archive-with-balance) is always gated on `capabilities`, full
stop — `PermissionGate` may additionally wrap it where a raw permission also needs checking (e.g. "issue
immediately" at create time, before a `capabilities` object exists yet — see §4.7), but a `capabilities`
flag, once the resource exists, is authoritative and is checked directly in the template
(`v-if="invoice.capabilities.can_issue"`), matching `ChartOfAccountsPage.vue`'s existing
`node.account.capabilities.can_update` precedent named directly in the requirements (§3).

### 1.4 Validation display

- **Field-level (422 `fieldErrors`)** — shown via each field's own `error` prop on `TextField` (or the
  hand-rolled `<select>`/`<textarea>` equivalent using the same `field-label`/`field-hint`/`field-error`
  utility classes), exactly as `ChartOfAccountsPage.vue`'s `create()` and `UsersPage.vue`'s `invite()` do.
  The error sits directly under its field, wired through `aria-describedby`/`aria-invalid` (already built
  into `TextField`).
- **Domain-level (409, or a 422 with no field to pin to — e.g. `customer-not-invoiceable`,
  `invoice-total-not-positive`)** — surfaced as an `ui.notify('error', problem.detail)` toast, never invented
  as a fake field error. This is the existing `create()`/`archive()` pattern across every page read.
- **Per-line (invoice lines only)** — see §2.3 (the line editor) in detail. Indexed keys
  (`lines.0.tax_code`, Gate‑1 #8) map to the specific line row and field; the offending cell gets the same
  red-border + `field-error` treatment as a top-level field, and the line row itself gets a subtle
  `border-danger/40` left-edge marker so a long line list still shows at a glance which row failed without
  needing to read every cell.
- **No client-only validation that duplicates a server domain rule without the server's own wording** —
  e.g. the mutual-exclusion of `discount_percent`/`discount_amount` (§4.7.4) is enforced in the UI
  (disable/clear the other field the moment one is filled) but the *wording* used if the user somehow still
  submits both matches the server's `invoice-line-two-discounts` message, never a client-invented one.

### 1.5 "Totals finalise on save" hint (Gate‑1 #5)

Applies to invoice draft create and draft edit only. Because the UI performs **no** money arithmetic
(§4.7.6/§4.8.6, Gate‑1 #5), the totals area of the line editor (subtotal/discount/tax/total) shows:

- Before the first successful save: every total field renders an em dash `—` (not `0.00` — a zero
  is a real value on other screens per `ArControlPage.vue`'s own convention of never blanking a meaningful
  zero, so it must not be reused here to mean "unknown").
- A single-line hint directly under the totals block, always visible while composing/editing:
  `Totals finalise when you save.` in `text-content-muted text-xs`, with a small info icon
  (reuse `AlertBanner`'s info icon glyph inline rather than the full banner, since this is a persistent
  caption, not a one-off notice).
- After a successful save (create response or update response lands): the totals block switches to
  showing the authoritative figures from that response, in the same `font-mono tabular-nums` treatment as
  every other money column in the app, and the hint text changes to
  `Totals shown are as saved. Editing a line will need saving again to update them.` This second sentence
  matters because the editor stays open after a save (see §2.3), and a user who edits another line after
  saving must not read the *stale* totals as current.

### 1.6 Company switch (ADR 0011 D3, Gate‑1 #6)

Every list/view page: `watch(companyId, (id, prev) => { if (id !== prev) void load() })`, not `immediate`,
exactly as the three existing report pages. No screen-specific design needed beyond re-running §1.1's
loading state.

Every open editor (customer create/edit, invoice draft create/edit): on company switch, a modal appears
immediately (Tier‑2 style, no reason field) stating **"Switching company discards unsaved changes.
Continue?"** with buttons "Discard and switch" (`variant="danger"`, since data loss) and "Stay on this
page" (`variant="secondary"`, default-focused — the safer choice is the default so a user who dismisses
the dialog with Enter does not lose work). Choosing "Discard and switch" clears the local form state and
lets the reload proceed; "Stay on this page" is a no-op that leaves the editor open and — per Gate‑1 #6 —
implies the company switch itself does not take effect for this tab's data until the user leaves the editor
(this half of the behaviour, i.e. *how* the switch is deferred/blocked at the app-shell level, is an
implementation detail for the Architect: the design intent is only that the user is asked before losing
work, and either "block the switch" or "switch elsewhere but keep this editor pinned to the old company
until closed" satisfies that intent — flagged in §3 below since it touches `CompanySwitcher.vue`/`App.vue`
behaviour outside these two pages' own files).

### 1.7 Pagination (Gate‑1 #1)

One shared `Pagination` component (`components/ui/Pagination.vue` — Architect's naming call) used
identically by the customer list and invoice list. Design:

- Rendered inside each `SurfaceCard`'s `#footer` slot, matching the visual slot `UsersPage.vue` already
  uses for its ad hoc prev/next control — this design keeps that placement and that exact prev/next shape
  (not numbered pages, not infinite scroll) for consistency with the one existing precedent in the
  codebase, and because prev/next is the least error-prone control for a keyboard/screen-reader user on a
  dense financial list.
- Layout: `flex items-center justify-between text-sm` — left side "`{{ from }}–{{ to }} of {{ total }}`" in
  `text-content-muted`; right side two `BaseButton variant="secondary" size="sm"` buttons "Previous"/"Next",
  each `:disabled` at the respective boundary (`current_page <= 1` / `current_page >= last_page`), exactly
  as `UsersPage.vue`'s inline markup already does. Only the *component extraction* is new, not the design.
- Only rendered when `meta.pagination.last_page > 1` (both list screens, per requirements §4.1.4/§4.6.2) —
  a single-page result shows no control at all, avoiding a permanently-disabled, useless pair of buttons.
- Buttons are real `<button>` elements (already guaranteed by `BaseButton`), so keyboard/focus-visible
  behaviour is inherited for free.

### 1.8 Responsive / mobile — wide-table fallback

Per NFR §5.2 and the ui‑ux‑pro‑max "Table Handling" rule (Responsive, Medium — verified fit for a
professional data-dense app: horizontal scroll is acceptable *in addition to*, not instead of, a
narrower-viewport alternative when the table is a primary task surface rather than a report glanced at),
this design keeps the existing `overflow-x-auto` + `role="region"` + `aria-label` + `tabindex="0"` pattern
for every table **and adds a stacked-card fallback below the `md` breakpoint (768px) for the two screens
where a user is expected to *act* on a row while on a phone**: the customer list and the invoice list. The
two report-style read-heavy tables that already exist are not in scope for this wave and are not changed by
this decision; the two new list screens being built now use the card fallback because §5.2's "no horizontal
scroll" instruction is strongest exactly where the audience is most likely to be triaging on a phone away
from their desk (a bookkeeper checking which invoices are drafts) rather than reading a static report.

Card fallback shape (`sm:hidden` table, `hidden sm:block` … inverted — table wrapper `hidden md:block`,
card list `md:hidden`):
- Each row becomes a `SurfaceCard`-less bordered block (`rounded-md border border-surface-border p-3`)
  stacked in a `space-y-2` list.
- Primary identifying text (customer name / invoice number-or-"Draft") at `text-sm font-medium`, secondary
  line (code / customer name + date) at `text-xs text-content-muted` directly under it.
- The 1–2 most important numeric/status facts (status badge, outstanding amount / total) right-aligned on
  the same first line as the primary text, using the same `font-mono tabular-nums` treatment as the desktop
  table.
- Row actions collapse into the same overflow-menu button used on desktop (§1.2), placed top-right of the
  card — this keeps exactly one action-affordance shape across breakpoints rather than inventing a second
  one for mobile.
- The whole card is not itself a link/button (avoids a nested-interactive-control a11y problem with the
  overflow menu inside it); tapping the primary text navigates to the view screen via a normal
  `<router-link>` wrapping just that text block, sized to meet the 44×44 touch-target minimum this document
  applies to primary mobile controls (see §1.9).
- The invoice line editor (§2.3) additionally needs its own mobile treatment — specified there, since its
  "table" is an editable grid, not a read-only list, and the two fallbacks are not the same shape.

### 1.9 Accessibility baseline (WCAG 2.1 AA)

Verified against `ui-ux-pro-max --domain ux` queries ("destructive action confirmation dialog", "dense
data table responsive mobile", "error summary validation", "empty state loading skeleton", "touch target
minimum size spacing", "focus order keyboard operable modal") and cross-checked against this project's own
stated AA target (`docs/PHASE-1-CODE-REVIEW.md`) before applying. Applies to every screen in both lanes:

- **Contrast** — every new piece of copy uses the existing semantic tokens (`text-content`,
  `text-content-muted`, `text-content-subtle`, `text-danger`, `text-warning`, `text-success`, `text-info`)
  already tuned for AA in both themes; no raw hex or ad hoc opacity value is introduced. Status is always
  paired with a word (`status_label`, "Draft"/"Issued"/etc., "Reconciles"/"Does not reconcile"‑style
  phrasing), never colour alone, per §4.4.2/§4.9.2 and `AlertBanner`'s and `ArControlPage.vue`'s existing
  icon/word+colour pairing (ui‑ux‑pro‑max "Error Messages"/Accessibility: colour-only signalling flagged
  High severity — verified fit, applied).
- **Keyboard operability & focus order** — every interactive control is a real `<button>`/`<a>`/`<input>`
  (never a `<div>` with a click handler); tab order matches visual order; the overflow menu and every
  modal (Tier 2/3, company-switch-mid-edit) traps focus while open and returns it to the control that
  opened it on close, mirroring `StepUpDialog.vue`'s existing `nextTick` focus call. Verified against
  ui‑ux‑pro‑max "Focus States"/"Keyboard Navigation" (High) — no focus ring is ever removed without a
  replacement; every button and input in this design uses the existing `focus-visible:ring-*` utility
  already on `BaseButton`/`TextField`, never `outline-none` alone.
- **Wide-table scroll region** — every table container keeps `role="region"` + `aria-label` +
  `tabindex="0"` per §5.1 of the requirements, extended to the customer list and invoice list tables exactly
  as it already exists on the three report pages.
- **Error summary vs. inline** — per ui‑ux‑pro‑max "Focusable Error Summary" (Forms/Accessibility, High),
  inline per-field errors (§1.4) are the primary mechanism and are never replaced by a summary-only
  pattern; however, on the invoice line editor specifically — where a 422 can name several lines at once
  — a short summary banner (`AlertBanner kind="error"`, `role="alert"`, already focus-reachable because
  `AlertBanner`'s wrapper is a normal element in flow) appears above the line list on submit failure,
  listing "Line 2: tax code — {{ message }}" per offending line/field, each entry an anchor-style button
  that scrolls/focuses the corresponding cell. This pairs the summary with the inline errors rather than
  substituting one for the other, matching the rule's explicit "retain inline errors" instruction.
- **Touch targets** — per requirements §5.1 ("44×44 touch targets") this design applies 44×44 CSS px as
  the minimum for **primary, standalone mobile controls**: the mobile card's tap-to-view text block, the
  overflow-menu trigger button, every button inside a Tier 2/3 dialog, and every "Add a line"/"Remove
  line"/pagination button. It does **not** enlarge the existing dense-desktop-table row-action links
  (`text-xs`, inline text buttons as already used in `ChartOfAccountsPage.vue`/`UsersPage.vue`) beyond
  their current size, because (a) those are desktop-pointer contexts where the WCAG 2.2 AA Target Size
  minimum is 24×24 CSS px with an inline-text-link exception, which the ui‑ux‑pro‑max "Target Size
  (Minimum)" result confirms as the actual web rule rather than the 44pt/48dp figures which are native
  mobile guidance, and (b) inflating every row action to 44px on a dense accounting table would blow the
  row height budget on the screen with the most rows in the app. This split (44px for the touch surfaces
  that are actually touched on mobile; the existing dense convention for desktop table rows) is a deliberate
  reconciliation between the requirement's stated 44×44 figure and the mobile/web target-size rules
  actually verified in the local database — flagged as a judgement call in §3, since it is the one place
  this document does not apply §5.1 literally everywhere.
- **Reduced motion** — the Tier 2/3 modal's entrance reuses `StepUpDialog.vue`'s existing
  `animate-slide-up` class; no new animation timing is introduced, and none of the app's existing motion is
  additive per-screen, so no new `prefers-reduced-motion` handling is needed beyond what already exists app-wide.

### 1.10 Reused primitives (no new visual style)

| Primitive | Reused for |
|---|---|
| `SurfaceCard` | Every card-shaped section: filters bar, create/edit forms, table wrapper, detail panel, line editor. |
| `BaseButton` (`primary`/`secondary`/`danger`/`ghost`, `sm`/`md`) | Every button. `danger` reserved for the Tier‑3 delete-confirm button and the cancel-invoice confirm button only — never for "Archive"/"Deactivate", which stay `secondary` or `ghost` since they are reversible. |
| `TextField` | Every single-line text/number/date input with a label. |
| `AlertBanner` | Domain warnings shown inline (e.g. "customer has an outstanding balance" style notices when the design calls for a persistent banner rather than a toast — see §2.5), and the invoice line-editor's per-line error summary. |
| `PermissionGate` | Every control gated purely on a static permission with no state component (§1.3). |
| `NoticeStack` / `ui.notify` | Every toast — success and error alike. |
| `useMoney().formatPlain` (Gate‑1 #3) | Every money figure, in `font-mono tabular-nums`, matching the two existing Sales report pages exactly. `useFormat` is not used anywhere in this wave. |
| `opacity-60` row treatment | Archived customers on the list (§4.1.6 already specifies this precedent by name) and cancelled/inactive invoice rows where a de-emphasised-but-still-legible row is wanted. |

New, not-yet-built pieces this design requires (flagged for the Architect in §3, not decided here beyond
their UX shape already specified above): a generic **Tier 2/3 confirm-dialog component**, an **overflow
menu component**, and the **`Pagination` component** already named in Gate‑1 #1.

---

## 2. Screen-by-screen design

Both lanes share `mx-auto max-w-{5xl|6xl} space-y-5` page shells and the `header` pattern (`h1` +
one-line `text-content-muted` subhead) already used by every existing page — every screen below inherits
that shell without restating it.

### 2.1 Customer screens

#### 2.1.1 Customer list (§4.1)

**Layout.** Header ("Customers", one-line subhead). Filter bar (`SurfaceCard`, or a plain `flex` row
matching `UsersPage.vue`'s unwrapped filter row — same visual weight either way): a `TextField` search
box (`label="Search"`, `placeholder="Name or code"`, min-width matching `UsersPage.vue`'s
`min-w-56 flex-1`), a status `<select>` (`Active`/`Inactive`/`Archived`/`All`, matching the `field-label` +
`form-select` styling already on `UsersPage.vue`'s status filter). "Add a customer" `BaseButton` top-right
of the header, behind `PermissionGate permission="sales.customers.manage"`. Below: `SurfaceCard` containing
the table (desktop) / card list (mobile, §1.8).

**Columns (desktop table):** Code (`font-mono text-xs`), Name, Status (badge, word + colour, see §1.9),
Branch (if present), Credit limit (`formatPlain`, right-aligned), row actions (Edit / overflow menu with
Archive-or-Restore, Deactivate-or-Reactivate, Delete — see §1.2/§1.3 for exact gating per row).

**States.**
- *Loading*: `py-12` "Loading…" per §1.1, filter bar stays interactive but disabled-looking is unnecessary
  (matches existing pages, which never disable filters during their own reload).
- *Empty*: two distinct copies depending on whether a filter is active — "No customers match that search."
  when `search`/`statusFilter` is non-default (mirrors `UsersPage.vue`'s "No users match that."); "This
  company has no customers yet." plus the "Add a customer" affordance repeated inline when the list is
  genuinely empty with no filter active (mirrors `ChartOfAccountsPage.vue`'s empty-chart copy, and follows
  the ui‑ux‑pro‑max "Empty States" rule: guide with a message *and* an action, not blank space).
- *Error*: rows cleared, filters retained as typed, toast per §1.1.
- *Success*: table/cards per above; pagination footer per §1.7 when `last_page > 1`.

**Search/filter mechanics.** 300ms debounce on `search` and immediate re-query on `statusFilter` change,
exactly matching `UsersPage.vue`'s `watch([search, statusFilter], …)` pattern (§4.1.2/§4.1.3).

**Row de-emphasis.** Archived rows get `opacity-60` on the whole `<tr>`/card, per §4.1.6 — this is stated
by name in the requirements and is carried forward unchanged.

**Accessibility.** Table wrapper gets `role="region" aria-label="Customers"` + `tabindex="0"` (§1.9). Status
filter and search box both have visible `<label>`s (not placeholder-only — the ui‑ux‑pro‑max "Forms/
Placeholder-only label" anti-pattern is explicitly avoided; `TextField`'s built-in label already guarantees
this).

#### 2.1.2 Customer create (§4.2)

**Layout.** A `SurfaceCard title="Add a customer"` that opens inline below the header (same
toggle-in-place pattern as `ChartOfAccountsPage.vue`'s `creating` boolean and `UsersPage.vue`'s `inviting`
boolean — not a separate route, not a modal, for consistency with both existing create flows). Two-column
`grid sm:grid-cols-2 gap-4` form.

**Fields, grouped for scanability** (a flat 20-field form is the single biggest usability risk on this
screen — grouping is this design's mitigation, not a new component):
1. *Identity* — Name* (required, full-width), Code (optional, hint: "Leave blank to auto-generate", *never*
   offers a status field — §4.2.7 is a hard requirement).
2. *Contact* — Email, Phone, Website.
3. *Address* — Address line 1/2, City, District, Postal code, Country code.
4. *Tax* — VAT registration number, TIN, "Is VAT registered" checkbox.
5. *Commercial terms* — Payment terms (days, numeric), Credit limit (text input, `inputmode` left as
   default text since a leading minus must be typeable per §4.2.2 — `inputmode="numeric"` would suppress
   the minus key on some mobile keyboards), Receivable account (a searchable account picker — reuse
   whatever account-selection control the Architect designates; if none exists yet, a plain `<select>`
   populated from the chart of accounts is an acceptable placeholder shape), Branch.
6. *Notes* — free-text `<textarea>`.

Each group is a `<fieldset>` with a `<legend class="field-label mb-2">` naming it, inside the one form —
this is purely a visual/semantic grouping, not five separate steps; nothing here is progressive disclosure
that hides required fields, since only Name is required and it is always visible first.

**Validation.** Field errors from `fieldErrors` shown per-field (§1.4). `409` (code conflict) as a toast,
matching §4.2.4. Credit limit sent as the exact typed decimal string, never parsed (§4.2.2) — the input is
a plain text field, not a `type="number"`, specifically so the string round-trips untouched.

**Submit.** `BaseButton type="submit" :loading="createBusy"` at the foot of the form. On success: toast
naming the created customer by name (§4.2.5), form collapses, list reloads.

#### 2.1.3 Customer edit (§4.3)

Same `SurfaceCard` shape and field grouping as create, opened from the list row's "Edit" action or the view
screen. Two behaviours distinguish it from create:

- **Omit-vs-null discipline (§4.3.1/§4.3.2).** Every optional field (`branch_id`, `receivable_account_id`,
  `credit_limit`) that has a value shows a small "Clear" affordance next to its input (an inline
  `text-xs text-content-muted hover:text-content underline` button, not a full second control) — clicking
  it blanks the field **and** marks it as "explicitly cleared" in the form's local state, distinct from a
  field the user never touched. Only fields the user actually changed or explicitly cleared are included in
  the `PUT` body; an untouched field is omitted entirely, and an explicitly-cleared one is sent as `null`.
  This is the single most acceptance-critical behaviour on this screen (§4.3's own framing) and is worth
  the extra "Clear" control precisely so a user has an explicit, visible way to produce that intent rather
  than the UI trying to infer it from an emptied text box (which is ambiguous — did they mean "clear" or
  did they just not touch it after a page reload?).
- **Code field.** Read-only/disabled with a hint ("This customer has been invoiced, so its code cannot be
  changed.") when the customer's own data indicates at least one invoice exists and that fact is knowable
  from context available to the page (e.g. an invoice count already being fetched for the view screen); if
  that context is not present at edit time, the field stays editable and a `409`/`422` on submit is
  surfaced as a toast instead (§4.3.3 — the requirements explicitly allow either).

**Gating.** The entire edit control (button on list/view) is hidden when `capabilities.can_update` is
false (§4.3.5) — never shown-then-refused.

#### 2.1.4 Customer view (§4.4)

**Layout.** `SurfaceCard` per logical group (Identity, Contact, Address, Tax, Commercial terms, Notes),
read-only `<dl>` grids (`grid sm:grid-cols-2 gap-4`, each pair a `<dt class="field-label">`/`<dd>`), mirroring
the same grouping as create/edit so a user's mental model of "where is X" carries across the two screens.
Status shown as a badge with `status_label` text (§4.4.2) plus, if archived, the `archived_at` date in
words underneath the badge.

**Actions.** Edit / Archive-or-Restore / Deactivate-or-Reactivate as buttons in the header row (next to the
title, matching the header-action placement already used on every list page), each individually gated on
its own `capabilities` flag (§4.4.3) — never inferred from `status` in the template. "Delete" lives in the
header's overflow menu per §1.2.

**404 / not-found.** A dedicated `NotFoundPage.vue`-style panel (reuse the existing not-found page/pattern
if the router already has one for this shape) with generic wording — never "not found or not yours" broken
into two distinguishable messages (§4.4.4 requires the ambiguity be preserved, not resolved by the UI).

#### 2.1.5 Customer lifecycle actions (§4.5)

- **Archive / Deactivate / Restore / Reactivate** — Tier‑1 confirm (`window.confirm`, §1.2), copy per action
  stating the consequence in one sentence (mirroring `ChartOfAccountsPage.vue`'s
  `Archive {{ code }} {{ name }}? Its history stays readable.`). A `422` "outstanding balance" refusal on
  archive is surfaced as a toast without any client pre-check (§4.5.1 — the UI does not try to compute the
  balance itself). A `422` "already archived, cannot deactivate separately" refusal on deactivate, and a
  `409` restore-code-conflict, are both surfaced as toasts with the server's own wording (§4.5.4/§4.5.5).
- **Delete** — Tier‑3 confirm (§1.2), typed-token variant (the customer has a stable `code`). If the
  server refuses with "customer has been invoiced," the toast additionally suggests archiving as the
  alternative in the same sentence (§4.5.3): *"This customer has been invoiced and cannot be deleted.
  Archive it instead to remove it from active use while keeping the invoice record intact."*
- Every action here appears only when `sales.customers.manage` is held **and** its own `capabilities`
  flag/documented state allows it (§4.5.6) — no flag currently named for delete's "already invoiced" state
  per the requirements' own open question (§7.2/§4.5.3); until the Architect confirms one exists, "Delete"
  is shown whenever `capabilities.can_delete` is true and the 422 is relied on for the invoiced case, per
  the requirements' own guidance that pre-emptive disabling is out of scope absent that flag.

### 2.2 Invoice screens

#### 2.2.1 Invoice list (§4.6)

**Layout.** Header ("Invoices"), filter bar: status `<select>` (`Draft`/`Issued`/`Partially paid`/`Paid`/
`Cancelled`/`All`), a customer picker (searchable select/typeahead — same account-picker-shaped control
referenced in §2.1.2, applied to customers instead), search box (`placeholder="Invoice number or
reference"` — explicitly **not** "search by customer name," since the endpoint does not support that,
§4.6.1, and the placeholder text itself is the cheapest way to prevent a user typing a customer name and
getting confused by an empty result). "New invoice" `BaseButton` top-right, behind
`PermissionGate permission="sales.invoices.draft"`.

**Columns:** Number (or "Draft" badge when null, §4.6.4 — a distinct pill style, `bg-surface-sunken
text-content-subtle`, not a money-adjacent colour, so it never reads as a status verdict), Customer, Invoice
date, Due date, Status (badge + word), Total (`formatPlain`, right-aligned), row actions.

**Row actions by status** (capabilities-gated, §4.6.4): draft → Edit, overflow{Delete}; issued → View,
overflow{Cancel — only if `can_cancel`}; partially_paid/paid/cancelled → View only, no overflow menu shown
at all if nothing in it would ever be enabled (an overflow trigger with an empty menu is worse than no
trigger).

**Sort.** Default `-invoice_date` is never overridden client-side (§4.6.3) — no client sort control is
added in this wave; if a future sort control is wanted it is an Architect/product call, not assumed here.

**States** follow §1.1 exactly; empty copy: "No invoices match that." (filtered) / "This company has no
invoices yet." (unfiltered, paired with the "New invoice" action inline, same empty-state pattern as
customers).

**Line detail is never shown here** (§4.6.5) — the list renders header fields only; anything below "one
row per invoice" is out of scope for this screen by design, not an oversight.

#### 2.2.2 Invoice draft create (§4.7)

**Layout.** A dedicated page (not an inline card toggle like customer create — the line editor is too
large and too stateful to collapse into the list page without the list's own state fighting it for
screen space). Two-part page: a header form section (customer, invoice date, due date, reference, branch,
header discount, notes/terms) above a `SurfaceCard` containing the **line editor** (fully specified in
§2.3), and a sticky-at-bottom-of-card footer with the totals block (§1.5) and the submit button.

**Header fields.**
- Customer* — searchable picker; only `capabilities.accepts_new_invoices` customers should be selectable
  where that information is available from the same list endpoint used elsewhere, so a user does not build
  an entire invoice before discovering `customer-not-invoiceable` at submit — this is a UX nicety, not a
  requirement, and degrades gracefully (server 422 + toast, §4.7.9) if that filtering isn't wired up.
- Invoice date* — date input, defaults to today (client default is fine here; it is not the authoritative
  cutoff of a report, it is a proposed value the user can change before an authoritative save).
- Due date — date input, **left blank by default**, hint text under it: "Leave blank to use the customer's
  payment terms." Blank is sent as omitted, never `""` (§4.7.5).
- Reference, Branch, Notes, Terms — ordinary optional fields, same grouping conventions as customer forms.
- Header discount amount — a `TextField` in the header section, decimal-string, positioned directly above
  the line editor since it is described to the user as "spread across your lines" (one sentence of hint
  text saying exactly that, since a header discount that silently changes line totals on save is otherwise
  surprising given §1.5's "nothing computes until save" framing).

**"Issue immediately."** A checkbox, `PermissionGate permission="sales.invoices.issue"` — entirely absent
(not shown-disabled) for a bookkeeper (§4.7.8/§4.7.7). When checked, the submit button's label changes from
"Save draft" to "Save and issue," and on submit a Tier‑2-style inline confirmation is **not** a separate
modal step here — because the checkbox itself, plus the button label change, already state the
consequence before the click, and inserting a second modal on top of an explicit checkbox the user just set
would be one confirmation too many for this specific flow (unlike issuing an *existing* draft from the view
screen, §2.2.5, where there is no equivalent checkbutton, only that one Tier-2 dialog remains the sole
confirmation).

**Submit failure handling (§4.7.9).** Per-line 422s render via the line editor's own error summary (§2.3.5);
non-line 422s/409s (customer-not-invoiceable, revenue-account-not-postable outside a specific line,
due-date-before-invoice-date) render as a toast unless they resolve to a specific field, in which case that
field gets the inline treatment.

#### 2.2.3 Invoice draft edit (§4.8)

Same page shape as create, pre-filled from the existing invoice (fetched fresh or reused from navigation
state). Differences from create:

- Only reachable when `capabilities.can_update` is true (§4.8.1) — the "Edit" action itself is what's
  gated; there is no separate in-page check once the page has loaded, since reaching it at all already
  implies the gate passed.
- **Full-replace lines warning.** Because any line-level change resubmits the entire `lines` array
  (§4.8.4), the editor does not need to warn the user explicitly on every keystroke — that would be noisy —
  but a one-line note sits above the line list: "Saving replaces every line above." This matters least to a
  user who is only editing one line and most to a future maintainer who might otherwise build a
  per-line-PATCH mental model; stating it in the UI keeps a user who's mid-edit and considering "should I
  also re-check the other lines" from guessing wrong.
- **`due_date` clear asymmetry (§4.8.3).** The "Clear" affordance (§2.1.3's pattern, reused here) on the
  due-date field carries a *different* hint than the generic clear pattern: "Clearing this re-derives the
  due date from the customer's payment terms — it will not stay blank." This is stated at the point of the
  clear action itself (a small caption that appears the moment "Clear" is clicked, before the request
  fires), not buried in a tooltip, because §4.8.3 flags this exact asymmetry as something that must not
  surprise a bookkeeper.
- **Invoice-date change re-render (§4.8.5).** Changing invoice date does not recompute anything client-side;
  on save, if the response's line-level tax figures differ from what was on screen before the save (which
  the UI cannot and does not need to detect — it simply always re-renders from the response), the totals
  and every line's tax column update to the response's values. No special "changed!" highlighting is added
  for this in v1 — re-rendering from the authoritative response is the whole behaviour required, and the
  totals-hint text in §1.5 already primes the user that saved figures can move.

#### 2.2.4 Invoice view (§4.9)

**Layout.** Header (invoice number-or-"Draft" + status badge), a two-column info block (customer, dates,
reference/branch/terms) above a read-only rendering of the line editor's table (same columns, no add/
remove/edit affordances — see §2.3.6 for the read-only variant), then a totals summary block (subtotal,
discount total, tax total, total, amount paid, amount due — every figure exactly as returned, §4.9.1).

- **Overdue** — `is_overdue: true` renders a word ("Overdue") next to the due date, `text-danger` paired
  with a small icon, never colour alone (§4.9.2).
- **Cancelled** — an `AlertBanner kind="warning"` (not `error` — cancellation is a recorded, legitimate
  business event, not a fault) stating cancellation date, reason, and who cancelled it (§4.9.3), placed
  directly under the header so it's the first thing read, before the line detail.
- **Journal entry link** — rendered only if a journal-entry detail route already exists and is reachable
  under this user's permissions (§4.9.4); if the Architect confirms no such route/permission path exists
  for this audience, the `journal_entry_id` is shown as plain text (e.g. a short reference) with no link at
  all, never a link that then 403s or 404s.
- **Company-mismatch 422 (§4.9.5)** — treated identically to the customer view's 404 handling (§2.1.4): a
  generic not-found panel, not a generic error banner, and not a message distinguishing "wrong company"
  from "does not exist."

**Actions.** Edit (draft only) / Issue / Cancel / Delete-overflow, each gated on its own `capabilities`
flag, placed in the header action row exactly as the customer view screen does.

#### 2.2.5 Invoice issue (§4.10)

Tier‑2 confirm dialog (§1.2). Copy (verbatim intent, wording may be refined at build time but must carry
this meaning): *"Issue invoice for {{ customer.name }}, total {{ formatPlain(total) }}? Issuing posts this
to the ledger and assigns an invoice number. It can only be undone by cancelling it — issuing itself cannot
be reversed."* Confirm button: `variant="primary"` labelled "Issue invoice" (not `danger` — issuing is the
intended, positive outcome of a draft's lifecycle, not a destructive act; only cancel and delete use
`danger`). Dismiss button: "Go back."

**Gating.** Button shown only when `capabilities.can_issue` (§4.10.2) — never on the raw
`sales.invoices.issue` permission alone, since an owner's permission is unconditional while the capability
correctly folds in "must still be a draft."

**422 handling (§4.10.3).** Each of the six documented refusals gets its *own* toast wording, not a shared
generic one — this is worth stating explicitly because it is the single clearest place in the whole spec
where a generic message actively misleads the user about which team to go to:

| Code | Toast wording (intent) |
|---|---|
| `invoice-not-a-draft` | "This invoice is no longer a draft — someone else may have issued or cancelled it. Refresh to see its current state." |
| `invoice-has-no-lines-to-issue` | "Add at least one line before issuing." |
| `invoice-total-not-positive` | "The invoice total must be greater than zero before it can be issued." |
| `invoice-period-not-open` | "The accounting period for this invoice's date is closed. Ask Accounting to reopen it, or change the invoice date." |
| `receivable-account-missing` | "This customer has no receivable account configured. Set one on the customer record before issuing." |
| `tax-output-account-missing` | "A tax code on this invoice has no output account configured. Ask an administrator to fix the tax code." |

**On success (§4.10.4).** The view re-renders from the response's `number`, `status`, `issued_at`,
`journal_entry_id` — no client-side guess of the next number is ever shown, before or after.

#### 2.2.6 Invoice cancel (§4.11)

Tier‑2 confirm dialog, reason **required** (3–255 chars, §4.11.1) — the dialog cannot be confirmed with an
empty/short reason; the confirm button stays disabled and a live character-count hint
(`{{ n }}/255 — at least 3 characters`) sits under the textarea. Copy frames cancellation correctly as *not
an undo* (§4.11.4): *"Cancel this invoice? This does not delete or undo the original entry — it posts a
mirror entry that reverses it. The reason you give is recorded against this invoice permanently."*

**Gating.** Shown only when `capabilities.can_cancel` (§4.11.2) — true only while `issued`, per schema.

**422 handling (§4.11.3).** Same per-code-distinct-toast approach as issue; `invoice-reversal-period-not-
open` gets bespoke wording that explicitly names *today's* period, not the invoice's own: *"The current
accounting period is closed, so a reversal cannot be posted today. This refers to today's period, not the
invoice's original one — ask Accounting to reopen the current period, or try again once it reopens."* This
distinction is called out because the requirements flag it by name as a common source of confusion.

**On success.** View re-renders showing both the "original entry untouched" and "mirror entry posted"
framing in the cancelled-state banner already specified in §2.2.4.

#### 2.2.7 Invoice delete (draft only) (§4.12)

Tier‑3 confirm (§1.2). A draft has no `number` (null), so this uses the **checkbox** variant, not
typed-token: *"Delete this draft invoice? This is permanent — there is no restore. [checkbox] I understand
this cannot be undone."* Danger button "Delete draft" disabled until checked.

Gated on `capabilities.can_delete` (§4.12.1); a stray 422 if the state changed underneath the user
(`invoice-not-editable`) is a toast, not a silent failure (§4.12.2). On success, the invoice simply
disappears from the list/redirects away from its view page with a success toast — no restore affordance is
ever offered anywhere, and this is explicitly *not* styled or worded like the reversible customer-archive
flow, to avoid the exact confusion the requirements name (§4.12.3).

### 2.3 The invoice line editor — detailed design

This is the highest-complexity, highest-risk screen component in the wave (requirements §8). It appears
identically inside both draft create (§2.2.2) and draft edit (§2.2.3).

#### 2.3.1 Layout

A `SurfaceCard` (no separate title needed if it sits directly under an "Invoice lines" heading in the
page's own flow) containing:

- **Desktop (`md:` and up):** an editable table. Columns, left to right: `#` (line number, display only),
  Description (text input, flex-grow), Qty (numeric text input, narrow), Unit price (numeric text input,
  narrow), Discount (a compact two-part control — see §2.3.2), Tax code (read-only picker — see §2.3.3),
  Revenue account (searchable select), Line subtotal / Line total (display-only, populated **only** after a
  save per §1.5 — before that, both show `—`), Remove (an icon-only `BaseButton variant="ghost" size="sm"`
  trash icon, `aria-label="Remove line {{ n }}"`).
- **Mobile (below `md`):** each line becomes its own bordered card — same field set, stacked vertically,
  labelled (every input keeps its `<label>`; nothing degrades to placeholder-only on mobile). The card's own
  remove control sits top-right of the card at the 44×44 touch minimum (§1.9) since this is exactly the
  kind of standalone mobile control that minimum is meant for.
- **"Add a line"** button (`variant="secondary"`, full-width on mobile, left-aligned on desktop) below the
  last line/card, always visible, never hidden behind a capability check (line editing is part of the
  create/edit permission already gating the whole page).
- At least one line is enforced client-side (submit disabled, one-line inline note "Add at least one line"
  under the Add-a-line button) mirroring the schema's `minItems: 1`, so this is caught before a round trip
  rather than only after (§4.7.1).

#### 2.3.2 Discount field — mutual exclusion (§4.7.4)

A single labelled control area per line, not two separate always-visible fields fighting for space:
- A small segmented toggle (`%` / amount) directly to the left of one numeric input. Switching the toggle
  clears whichever field was active and switches which one accepts input — this makes "only one may be set"
  structurally true rather than something the user discovers by trying both, and needs no client-side error
  message at all in the common path.
- If a line arrives from a save/load already carrying a legacy or unexpected combination (should not
  happen given the toggle, but a defensive case worth designing for since the data ultimately comes from a
  server response), the toggle defaults to whichever of the two is non-null, and if — implausibly — both
  are non-null, `discount_percent` wins the toggle's initial position and `discount_amount` is cleared
  locally with a one-time inline note explaining why, rather than silently sending both and letting the
  server's `invoice-line-two-discounts` 422 be the first the user hears of it.

#### 2.3.3 Tax code — read-only picker (§4.7.3, Gate‑1 #7)

A `<select>` populated from `GET /companies/{company}/tax-codes?active_only=true`, options labelled
`{{ code }} — {{ name }} ({{ rate }}%)` so the rate is visible at selection time without needing a tooltip,
value bound to the **code string**, never an id (`tax_code`, not `tax_code_id`) — this is stated here
because it is the one field on this screen most likely to be implemented wrong by reflex (binding to id is
the natural Vue-select instinct). Includes a "No tax" empty option. This is read-only in the sense that
the *picker itself* offers no create/edit affordance for tax codes (Gate‑1 #7) — the user can still change
*which* code is selected on the line, that is simply what the field is for.

#### 2.3.4 Amounts — decimal strings, never numbers (§4.7.2)

Every numeric field on a line (`quantity`, `unit_price`, and whichever discount field is active) is a plain
text `<input>` with `inputmode="decimal"` (shows a numeric keyboard on mobile while still allowing a typed
decimal point and, for quantity only, a leading minus for corrections — `quantity` may be negative per
schema, §"May be negative for a correction"). Client-side validation rejects (inline field error, does not
silently round) anything that is not a valid decimal with at most four places, matching the schema's own
"rejected rather than rounded" rule (§4.7.2) — the UI's rejection message uses the same framing so a user
sees consistent wording whether the client or the server caught it.

#### 2.3.5 Per-line error mapping (§4.7.9, Gate‑1 #8)

On a 422 whose `errors` keys are indexed (`lines.0.tax_code`, `lines.2.revenue_account_id`, etc.):
- The exact field on the exact line row gets the inline `field-error` treatment (§1.4).
- The line's row/card gets a `border-l-2 border-danger` accent so a long line list shows at a glance which
  rows have a problem without reading every cell.
- The error-summary banner (§1.9) above the line list lists every offending line/field pair as a clickable
  entry that scrolls to and focuses that exact cell — critical on a 500-line-max invoice where the failing
  line could be far off-screen.
- A key that is *not* line-indexed (a flat/document-level error such as `invoice-total-negative`) renders as
  a normal toast, not force-mapped to a line — the design does not guess which line a flat error belongs to.

This mapping's exact key shape (`lines.0.tax_code` vs. some other indexing) is confirmed, per the
requirements' own open question (§7.8/Gate‑1 #8), as indexed keys — this design assumes that shape is
correct as Gate‑1 states it, and QA is the backstop if the actual API shape differs.

#### 2.3.6 Read-only rendering (used on the invoice view screen, §2.2.4)

Same column layout, no inputs — every cell is plain text/`font-mono tabular-nums` for money columns,
`Remove`/`Add a line` controls absent entirely, tax code shown as `{{ code }} ({{ tax_rate }}%)` text. This
is the same component in a `readonly` mode where feasible, or a visually-matched but non-interactive
sibling — an implementation choice, not a design one, so long as the two never visually diverge (a
developer must not be able to update one table's column widths/labels without the other drifting).

#### 2.3.7 Totals block

Directly under the line list, right-aligned, four rows (Subtotal, Discount, Tax, Total) each
`formatPlain`-formatted once populated, `—` before the first save (§1.5). "Total" row is visually heavier
(`font-semibold`, slightly larger, top border) matching the `tfoot` treatment already used on the three
report pages' totals rows. The "totals finalise on save" hint (§1.5) sits directly below this block, always
present while the page is in create/edit mode, removed entirely on the read-only view screen (§2.3.6) since
there is nothing left to finalise there.

---

## 3. UX risks and points needing an architecture decision

Flagged for the Solution Architect — none of these are decided by this document:

1. **New shared components this design requires but that don't exist yet:** a generic Tier‑2/3 confirm-
   dialog component, an overflow-menu component, and the `Pagination` component Gate‑1 #1 already commits
   to. All three are used identically by both lanes (customer + invoice), so building each once as a shared
   component is the natural read of Gate‑1's own "do not build two" reasoning for pagination — but that
   reasoning was stated for pagination specifically, not generalised, so the Architect should confirm
   whether the dialog and overflow menu are likewise built once under `components/ui` or duplicated per
   page. Recommend the former for the same reasons Gate‑1 gave for pagination.
2. **Company-switch-mid-edit mechanics (§1.6).** This design specifies the *user-facing* behaviour (confirm-
   and-discard) but not how the switch is actually deferred/blocked at the app-shell level while a "Stay on
   this page" choice is in effect — that is `App.vue`/`CompanySwitcher.vue` territory outside these two
   pages' own files and needs an architecture call.
3. **Account/customer searchable-picker control.** Both the customer create/edit form (receivable account)
   and the invoice line editor (revenue account) and invoice header (customer) need a searchable-select
   control. No such control currently exists in `components/ui` (only plain `<select>`s appear in the
   existing pages, e.g. `ChartOfAccountsPage.vue`'s parent-account select). This design assumes one is built
   or a plain `<select>` is judged acceptable at this data volume — that judgement call (build a proper
   typeahead vs. reuse a plain select) belongs to the Architect, since it's a component-investment decision,
   not a visual one.
4. **44×44 touch-target application (§1.9).** This design deliberately applies the requirement's stated
   44×44 figure only to standalone mobile controls and keeps the existing dense-desktop-table row-action
   link size elsewhere, reconciling the requirement's literal wording against the actual WCAG 2.2 AA web
   target-size rule (24×24 with a text-link exception) confirmed via ui‑ux‑pro‑max. Flagging this
   explicitly in case the human intended 44×44 literally everywhere, which would force every desktop
   accounting table in this wave to a materially taller row height than every existing page in the app.
5. **Journal-entry cross-link (§4.9.4/§2.2.4).** Whether a reachable journal-entry detail route/permission
   already exists is not something this design can confirm from the front-end alone — the requirements
   themselves flag this as needing Architect confirmation, and this design's spec (link if confirmed
   reachable, plain text otherwise) is contingent on that answer.
6. **Delete's "already invoiced" pre-emption (§4.5.3/§7.2).** No `capabilities` flag is currently documented
   distinguishing "deletable" from "deletable but will fail because invoiced" for customers. This design
   relies on the 422-plus-suggest-archive toast as the fallback exactly as the requirements allow, but if the
   Architect confirms such a flag exists (or is easy to add within the existing `CustomerResource` without
   new backend work), the delete control could be disabled pre-emptively instead — a strictly better
   experience this design would adopt if that flag turns out to be available.
7. **Header-discount-then-per-line-discount interaction is not independently visualised pre-save.** Because
   *no* client arithmetic is permitted (Gate‑1 #5), a user who sets both a header discount and several
   per-line discounts has no way to see the combined effect until saving. This is a real UX cost inherent to
   the binding Gate‑1 decision, not an oversight in this design — flagging it so the Architect and product
   are not surprised if early users describe the editor as feeling "blind" between edits and saves; the
   mitigation this design offers (the persistent hint text, §1.5) is the full extent of what's possible
   without violating the no-arithmetic rule.

## 4. ui-ux-pro-max rules applied

Verified via `python3 ~/.claude/skills/ui-ux-pro-max/scripts/search.py "<query>" --domain ux` before
applying (all confirmed as good fits for a professional accounting web app; none used unverified):

- **"destructive action confirmation dialog"** → Interaction/Confirmation Dialogs (High): "confirm before
  delete/irreversible actions" — basis for the three-tier confirmation system in §1.2.
- **"dense data table responsive mobile"** → Responsive/Table Handling, Mobile First, Touch Friendly
  (Medium–High): basis for §1.8's card fallback and the decision to add it specifically to the two new list
  screens.
- **"error summary validation"** → Forms/Accessibility "Focusable Error Summary" and Forms "Error
  Placement"/"Inline Validation" (High): basis for §1.4's field-error-plus-summary approach and §1.9's
  explicit "pair, never replace" rule for the line editor's error banner.
- **"empty state loading skeleton"** → Feedback/Empty States, Loading Indicators, Layout/Content Jumping
  (Medium–High): basis for §1.1's state machine and the explicit "no layout jump" rule under Loading.
- **"touch target minimum size spacing"** → Touch/Touch Target Size, Touch Spacing, Accessibility/Target
  Size (Minimum) (High): basis for §1.9's touch-target split, including the explicit reconciliation between
  native 44pt/48dp guidance and the actual WCAG 2.2 web minimum.
- **"focus order keyboard operable modal"** → Interaction/Focus States, Accessibility/Keyboard Navigation
  (High): basis for §1.9's focus-trap/return-focus and "never remove a focus ring without replacement"
  rules applied to every new modal.
- **"type to confirm irreversible delete"** — returned no on-topic match in the local database (results were
  generic success/feedback rules, not the specific typed-confirm pattern). The Tier‑3 typed-token/checkbox
  design in §1.2 is therefore labelled here as an **unverified fallback** — a standard, widely used
  industry pattern for irreversible deletes, not a match pulled from the ui-ux-pro-max database — per the
  skill's own instruction to say so explicitly rather than present it as a verified result.
