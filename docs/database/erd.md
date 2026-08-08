# Entity relationship diagram

Every table created by Phase 1 (the platform) and Phase 2 (the accounting core). Framework
infrastructure (`cache`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `notifications`,
`migrations`) is omitted — it carries no domain meaning.

## Reading this

- **`tenants` is the root.** It is the only table that is not tenant-scoped, because it *is*
  the tenant. Everything else carries `tenant_id`, and 16 tables have a FORCED PostgreSQL
  row level security policy keyed on it ([ADR 0001](../adr/0001-tenancy-strategy.md)).
- **`tenant_id` is denormalised onto child tables.** `branches` carries both `tenant_id` and
  `company_id` even though the first is derivable from the second. That costs 16 bytes a row
  and buys a uniform index prefix and a uniform RLS policy on every table in the platform.
- **A nullable `tenant_id` is meaningful, not sloppy.** On `users` it means ASIDS platform
  staff; on `roles` a platform template; on `settings` a system-scope default; on
  `audit_logs` an entry for a platform action. A check constraint enforces the `users` case:
  `(tenant_id IS NULL) = is_platform_admin`.

---

## Tenancy and organisation

```mermaid
erDiagram
    TENANTS ||--o{ DOMAINS : "reachable at"
    TENANTS ||--o{ COMPANIES : owns
    TENANTS ||--o{ USERS : employs
    TENANTS ||--o{ ROLES : defines
    COMPANIES ||--o{ BRANCHES : "operates from"
    COMPANIES ||--o{ COMPANY_MEMBERSHIPS : "grants access via"
    USERS ||--o{ COMPANY_MEMBERSHIPS : "may access via"
    BRANCHES |o--o{ COMPANY_MEMBERSHIPS : "optionally narrowed to"

    TENANTS {
        uuid id PK
        string slug UK "DNS label, shape enforced by CHECK"
        string name
        string status "provisioning|active|suspended|cancelled"
        char country_code "regional defaults for new companies"
        char currency_code
        int max_companies "NULL inherits the plan default"
        int max_users
        timestamptz trial_ends_at
        timestamptz deleted_at
    }

    DOMAINS {
        uuid id PK
        uuid tenant_id FK
        string domain UK "unique on lower(domain)"
        bool is_primary "one per tenant, partial unique index"
        bool is_custom
        timestamptz verified_at "unverified custom hosts do not route"
    }

    COMPANIES {
        uuid id PK
        uuid tenant_id FK
        string code "unique per tenant on upper(code)"
        string slug "unique per tenant on lower(slug)"
        char base_currency_code "immutable once the ledger has activity"
        tinyint fiscal_year_start_month "1-12"
        tinyint fiscal_year_start_day "1-28, so February is defined"
        bool is_vat_registered
        bool is_svat_registered "CHECK: implies is_vat_registered"
        string status "active|archived"
        bool is_default "one per tenant, partial unique index"
    }

    BRANCHES {
        uuid id PK
        uuid tenant_id FK "denormalised for a uniform RLS policy"
        uuid company_id FK
        string code "unique per company on upper(code)"
        bool is_primary "one active per company; CHECK: primary implies active"
        uuid manager_id FK
        string status "active|archived"
    }

    COMPANY_MEMBERSHIPS {
        uuid id PK
        uuid tenant_id FK
        uuid company_id FK
        uuid user_id FK
        uuid branch_id FK "NULL means every branch"
        bool is_default "one per user, partial unique index"
        timestamptz joined_at
        timestamptz revoked_at "revoked, never deleted"
    }
```

**Why membership is a table and not a role.** A tenant administrator must be able to hire a
bookkeeper for one of five group companies without exposing the other four. A role answers
*what may this person do*; membership answers *whose books may they touch*. Both must pass.
Collapsing them would force a role per company per job function
([ADR 0002](../adr/0002-tenant-company-branch-hierarchy.md)).

---

## Identity and credentials

```mermaid
erDiagram
    USERS ||--o{ USER_DEVICES : "signs in from"
    USERS ||--o{ LOGIN_HISTORIES : attempts
    USERS ||--o{ PASSWORD_HISTORIES : "rotated through"
    USERS ||--o{ TWO_FACTOR_RECOVERY_CODES : holds
    USERS ||--o{ PERSONAL_ACCESS_TOKENS : issues
    USER_DEVICES |o--o{ LOGIN_HISTORIES : "recognised as"
    USERS |o--|| COMPANIES : "lands in by default"

    USERS {
        uuid id PK
        uuid tenant_id FK "NULL only for ASIDS staff"
        string email "unique per tenant on lower(email)"
        string password "nullable: an invitee has none until they accept"
        string status "pending_invitation|active|suspended|deactivated"
        bool is_platform_admin "CHECK: (tenant_id IS NULL) = this"
        text two_factor_secret "application-layer encrypted"
        timestamptz two_factor_enrolled_at
        timestamptz two_factor_confirmed_at "CHECK: implies enrolled"
        timestamptz password_changed_at
        bool must_change_password
        smallint failed_login_attempts
        timestamptz locked_until
        uuid default_company_id FK
        timestamptz deleted_at
    }

    USER_DEVICES {
        uuid id PK
        uuid user_id FK
        char fingerprint_hash "SHA-256 of a signed cookie, never the raw value"
        timestamptz trusted_at
        timestamptz trust_expires_at "CHECK: implies trusted_at"
        timestamptz revoked_at "CHECK: revoked implies not trusted"
    }

    LOGIN_HISTORIES {
        uuid id PK
        uuid user_id FK "NULL: attempt against an unknown address"
        string email_attempted "kept even when no user matched"
        string outcome "7 states, incl. two_factor_failed"
        string ip_address
        bool two_factor_used
        timestamptz created_at "append only; no updated_at"
    }

    PASSWORD_HISTORIES {
        uuid id PK
        uuid user_id FK
        string password_hash "hash only; pruned to the retained count"
        timestamptz created_at
    }

    TWO_FACTOR_RECOVERY_CODES {
        uuid id PK
        uuid user_id FK
        char code_hash "SHA-256; high-entropy input needs no slow KDF"
        timestamptz used_at "single use, enforced by conditional UPDATE"
        string used_ip "CHECK: unused code carries no IP"
    }

    PERSONAL_ACCESS_TOKENS {
        uuid id PK
        uuid tenant_id FK
        uuid tokenable_id "the owning user"
        char token UK "SHA-256; plaintext shown once, never stored"
        jsonb abilities "intersected with the creator's own permissions"
        jsonb allowed_ip_ranges "optional CIDR restriction"
        timestamptz expires_at
        timestamptz revoked_at "revoked is distinct from deleted"
    }
```

**No `password_reset_tokens`.** Laravel's table is keyed on the e-mail address, and an
address is unique only *within* a tenant — the same external accountant may hold accounts at
several customers, so a shared table would let a reset requested for workspace A be redeemed
in workspace B. `AccountLinkService` issues expiring signed links bound to the user's UUID
and current credential hash instead, which also makes them single-use with no token store.

---

## Authorization

```mermaid
erDiagram
    TENANTS ||--o{ ROLES : "scopes (spatie team key)"
    ROLES ||--o{ ROLE_HAS_PERMISSIONS : grants
    PERMISSIONS ||--o{ ROLE_HAS_PERMISSIONS : "granted by"
    ROLES ||--o{ MODEL_HAS_ROLES : "assigned via"
    USERS ||--o{ MODEL_HAS_ROLES : holds
    PERMISSIONS ||--o{ MODEL_HAS_PERMISSIONS : "directly granted via"

    PERMISSIONS {
        uuid id PK
        string name UK "CHECK: name = module.resource.action"
        string module
        string resource
        string action
        bool is_sensitive "can move money or weaken security"
    }

    ROLES {
        uuid id PK
        uuid tenant_id FK "NULL = platform template"
        string name "unique per (tenant, name, guard)"
        bool is_system "identity fixed; permissions editable"
        bool is_owner "one per tenant, partial unique index"
        smallint level "total order preventing escalation"
    }

    ROLE_HAS_PERMISSIONS {
        uuid permission_id FK
        uuid role_id FK
    }

    MODEL_HAS_ROLES {
        uuid role_id FK
        string model_type
        uuid model_uuid
        uuid tenant_id "spatie team key"
    }

    MODEL_HAS_PERMISSIONS {
        uuid permission_id FK
        string model_type
        uuid model_uuid
        uuid tenant_id
    }
```

**Permissions are global; roles are tenant data.** A permission is a capability the *software*
offers, so 100,000 copies of an identical catalogue would be waste. A role is a bundle a
*customer* defines — an "Accountant" at one firm is unrelated to another's. Wildcards are
disabled, so "who can void a posted invoice?" is a query rather than an inspection
([ADR 0003](../adr/0003-permissions-in-code-roles-in-data.md)).

The **owner** role holds no pivot rows: its authority comes from a `Gate::before` short
circuit on `is_owner`, so a capability added in a later phase is never accidentally withheld
from the person paying for the product.

---

## Settings and audit

```mermaid
erDiagram
    TENANTS ||--o{ SETTINGS : "overrides for"
    TENANTS ||--o{ AUDIT_LOGS : records
    TENANTS ||--o{ ACTIVITY_LOGS : shows
    USERS |o--o{ AUDIT_LOGS : "acted in"
    USERS |o--o{ SETTINGS : "last changed"

    SETTINGS {
        uuid id PK
        uuid tenant_id FK "NULL = system scope"
        string scope "user|company|tenant|system"
        uuid scope_id "CHECK: required iff scope is user|company"
        string key
        string type "drives coercion and the form control"
        jsonb value "keeps its shape; a boolean stays a boolean"
        bool is_encrypted
    }

    AUDIT_LOGS {
        uuid id PK
        bigint sequence UK "IDENTITY; the chain's total order"
        uuid tenant_id
        uuid company_id
        string auditable_type "morph alias, not a class name"
        uuid auditable_id
        string event "17 values, CHECK constrained"
        jsonb old_values "credentials redacted before write"
        jsonb new_values
        string actor_type "CHECK: user implies actor_id"
        uuid actor_id
        string actor_label "denormalised; reads correctly years later"
        uuid impersonator_id
        char previous_hash "NULL until sealed"
        char hash "NULL until sealed"
        timestamptz sealed_at
        timestamptz created_at
    }

    ACTIVITY_LOGS {
        uuid id PK
        uuid tenant_id
        string log_name "channel: sales|security|organization"
        text description "a sentence a business user reads"
        string subject_label "denormalised, survives a rename"
        uuid batch_id "collapses one bulk action into one entry"
    }
```

**Two log tables, on purpose.** `audit_logs` is a compliance record: append-only, hash-chained,
seven-year retention, read by auditors. `activity_logs` is a product feature: a readable
sentence, mutable, ninety-day retention, read on dashboards. One table cannot be
simultaneously immutable and editable, verbose and readable.

`audit_logs` is guarded by a database trigger that refuses every `UPDATE` and `DELETE`, with
exactly two announced exceptions:

| Session variable | Permits | Constrained to |
| --- | --- | --- |
| `asids.audit_seal = 'on'` | `UPDATE` | Filling `previous_hash`, `hash`, `sealed_at` on a row where `sealed_at IS NULL`, with every other column byte-identical |
| `asids.audit_prune = 'on'` | `DELETE` | `asids:audit-prune` only |

A separate statement trigger blocks `TRUNCATE`, which bypasses row triggers entirely.

The chain is computed **out of band** by `asids:audit-seal` rather than at insert time.
Chaining inline requires reading the tenant's latest hash under a lock held for the whole
surrounding business transaction, which serialises every audited write in the workspace — at
10 million transactions that is the platform's ceiling. Rows are still written atomically
with the change they describe, so nothing is lost; only the newest few minutes are unsealed.

---

## The fiscal calendar

Dates alone cannot answer "may this be posted?", so periods are rows. A journal entry names the
period it falls in, and closing that period is what stops the figures moving underneath a filed
return.

```mermaid
erDiagram
    COMPANIES ||--o{ FISCAL_YEARS : "keeps books by"
    FISCAL_YEARS ||--o{ FISCAL_PERIODS : "divided into"
    FISCAL_YEARS |o--o| JOURNAL_ENTRIES : "closed by"

    FISCAL_YEARS {
        uuid id PK
        uuid tenant_id FK
        uuid company_id FK
        string label "e.g. FY2026, or 2026/27 for an April start"
        date starts_on
        date ends_on "CHECK ends_on > starts_on"
        bool is_closed
        timestamptz closed_at "CHECK is_closed = (closed_at IS NOT NULL)"
        uuid closed_by_id FK
        uuid closing_entry_id FK "the year-end entry; RESTRICT on delete"
    }

    FISCAL_PERIODS {
        uuid id PK
        uuid tenant_id FK
        uuid company_id FK
        uuid fiscal_year_id FK
        smallint sequence "CHECK >= 1, unique within the year"
        string label
        date starts_on
        date ends_on "CHECK ends_on >= starts_on"
        string status "open|closed|locked"
        timestamptz closed_at "CHECK (status = open) = (closed_at IS NULL)"
        uuid closed_by_id FK
        timestamptz reopened_at
        uuid reopened_by_id FK
        string reopen_reason "CHECK (reopened_at IS NULL) = (reopen_reason IS NULL)"
    }
```

Both tables carry a **GiST exclusion constraint** (`fiscal_years_no_overlap`,
`fiscal_periods_no_overlap`) on `(company_id, daterange(starts_on, ends_on))`. Two periods
covering the same day would make "which period does this entry belong to?" ambiguous, and the
answer would depend on row order. A unique index cannot express that; an exclusion constraint
can.

`reopen_reason` is not optional metadata. Reopening a closed period changes figures that may
already have been filed with the Inland Revenue Department, so the database refuses a reopening
that does not say why.

---

## The ledger

```mermaid
erDiagram
    COMPANIES ||--o{ ACCOUNTS : "keeps books in"
    ACCOUNTS |o--o{ ACCOUNTS : "rolls up to"
    COMPANIES ||--o{ JOURNALS : "records through"
    JOURNALS ||--o{ JOURNAL_ENTRIES : contains
    FISCAL_PERIODS ||--o{ JOURNAL_ENTRIES : "falls within"
    JOURNAL_ENTRIES ||--|{ JOURNAL_LINES : "balances across"
    ACCOUNTS ||--o{ JOURNAL_LINES : "debited or credited"
    BRANCHES |o--o{ JOURNAL_LINES : "optionally attributed to"
    JOURNAL_ENTRIES |o--o| JOURNAL_ENTRIES : reverses

    ACCOUNTS {
        uuid id PK
        uuid tenant_id FK
        uuid company_id FK
        uuid parent_id FK "CHECK parent_id <> id"
        string code "unique per company on lower(code), soft-delete aware"
        string name
        string type "asset|liability|equity|income|expense"
        string normal_balance "debit|credit, stored not derived"
        bool is_postable "false for headings; entries may not name one"
        bool is_system
        string system_key "unique per company; retained_earnings, opening_balance_equity"
        bool is_active
        timestamptz archived_at "CHECK is_active = (archived_at IS NULL)"
        string template_version "which starter chart this row came from"
        int sort_order
    }

    JOURNALS {
        uuid id PK
        uuid tenant_id FK
        uuid company_id FK
        string code "unique per company on upper(code)"
        string name
        bool is_general "partial unique: one general journal per company"
        bool is_system
        bool is_active
    }

    JOURNAL_ENTRIES {
        uuid id PK
        uuid tenant_id FK
        uuid company_id FK
        uuid journal_id FK
        uuid fiscal_period_id FK
        string number "NULL until posted, then unique per company"
        string document_type "journal_voucher|opening_balance|year_end_close|..."
        date entry_date
        string description
        string reference
        string status "draft|posted|reversed"
        timestamptz posted_at "CHECK: number, posted_at, posted_by all set together"
        uuid posted_by_id FK
        uuid reverses_entry_id FK "CHECK <> id"
        uuid reversed_by_entry_id FK
        timestamptz reversed_at
        string reversal_reason
        uuid created_by_id FK
    }

    JOURNAL_LINES {
        uuid id PK
        uuid tenant_id FK
        uuid company_id FK
        uuid journal_entry_id FK "cascade: a draft's lines go with it"
        uuid account_id FK "RESTRICT: an account with history cannot be deleted"
        uuid branch_id FK
        smallint line_number
        numeric debit "19,4 — CHECK >= 0"
        numeric credit "19,4 — CHECK >= 0"
        string description
        char transaction_currency_code "FX phase; all-or-nothing CHECK with the two below"
        numeric transaction_amount
        numeric exchange_rate "19,10"
    }
```

Four database-level rules carry the ledger's integrity, and they are in the database rather than
a service because a service can be bypassed by a migration, a console command or a future
developer in a hurry:

| Rule | Mechanism | What it prevents |
| --- | --- | --- |
| A line is debit **or** credit, never both, never neither | `journal_lines_one_sided_check` | A line that means two things at once |
| An entry's debits equal its credits | `journal_lines_balanced` — a **DEFERRABLE INITIALLY DEFERRED** constraint trigger | Books that do not balance. Deferred so a multi-line entry can be written a line at a time and is only checked at commit |
| A posted entry never changes | `journal_entries_immutable`, `journal_lines_immutable` row triggers | Editing history. Corrections go through a reversal, which is what an auditor expects to see |
| One currency until the FX phase | `journal_lines_single_currency_until_fx_phase` | Half-implemented multi-currency. The columns exist; the phase-scoped CHECK is dropped when FX ships, and the permanent all-or-nothing CHECK stays |

The balance trigger is `FOR EACH ROW` rather than `FOR EACH STATEMENT` because PostgreSQL does
not permit a statement-level constraint trigger. It therefore re-checks the same entry once per
line, which is redundant but correct.

> Deferred constraints do not fire under `RefreshDatabase`, because the wrapping transaction is
> rolled back rather than committed. Tests that mean to exercise the trigger must issue
> `SET CONSTRAINTS ALL IMMEDIATE` first, or they pass without the trigger ever running.

---

## Numbering and balances

Two supporting tables, both derived — and both of which state what they are derived from, so
neither becomes a second version of the truth.

```mermaid
erDiagram
    COMPANIES ||--o{ DOCUMENT_SEQUENCES : "numbers documents with"
    ACCOUNTS ||--o{ ACCOUNT_PERIOD_BALANCES : "summarised by"
    FISCAL_PERIODS ||--o{ ACCOUNT_PERIOD_BALANCES : "summarised for"

    DOCUMENT_SEQUENCES {
        uuid id PK
        uuid tenant_id FK
        uuid company_id FK
        string document_type
        string period_key "e.g. 2026-07; the scope a run of numbers restarts in"
        bigint next_number "CHECK >= 1"
    }

    ACCOUNT_PERIOD_BALANCES {
        uuid id PK
        uuid tenant_id FK
        uuid company_id FK
        uuid account_id FK
        uuid fiscal_period_id FK
        numeric debit_total "19,4 — CHECK >= 0"
        numeric credit_total "19,4 — CHECK >= 0"
        int line_count
    }
```

**Numbering is gapless**, which a PostgreSQL sequence cannot provide: a sequence hands out a
number that is lost if the transaction rolls back, and a gap in a numbered document series is
exactly what a revenue authority asks about. Instead `document_sequences` holds a counter row
that is locked (`SELECT … FOR UPDATE`) inside the posting transaction, so a rollback returns the
number. The cost is that concurrent posts of the same document type serialise on that row, which
is the right trade for an SME's volume.

**`account_period_balances` is a cache, not a source.** `journal_lines` remains the truth. The
aggregate is updated inside the same transaction that posts an entry, and two commands exist
because a cache nobody can check is a liability:

```bash
php artisan asids:ledger-verify
```

```bash
php artisan asids:ledger-rebuild --tenant=<slug> --confirm
```

`asids:ledger-verify` recomputes from `journal_lines` and reports any account and period where
the aggregate disagrees; `asids:ledger-rebuild` rewrites the aggregates from the lines, and
requires both flags so it cannot be run absent-mindedly against the wrong tenant.

---

## Row level security coverage

| Policy | Tables | `USING` / `WITH CHECK` |
| --- | --- | --- |
| **Strict** (`tenant_id` NOT NULL) | `companies`, `branches`, `company_memberships`, `fiscal_years`, `fiscal_periods`, `accounts`, `journals`, `journal_entries`, `journal_lines`, `account_period_balances`, `document_sequences` | `bypass OR tenant_id = current` |
| **Nullable tenant** | `users`, `roles`, `model_has_roles`, `model_has_permissions`, `settings`, `audit_logs`, `activity_logs`, `notifications`, `personal_access_tokens`, `user_devices`, `login_histories`, `password_histories`, `two_factor_recovery_codes` | `bypass OR tenant_id IS NULL OR tenant_id = current` |
| **Excluded** | `tenants`, `domains`, `sessions`, `permissions`, `role_has_permissions` | Routing metadata, global catalogue, or read before tenant context exists |

Policies are `FORCE`d so they apply even when the connecting role owns the tables — otherwise
a developer connecting as the schema owner runs with protection silently disabled, and the
isolation tests pass without testing anything. `asids:security-check` fails a release if the
policies are not actually in force.

---

## Index strategy

Every tenant-scoped index leads with `tenant_id`, giving the planner a highly selective first
column: a tenant with 10,000 invoices reads its own 10,000 rows regardless of the other
999,990,000.

| Kind | Purpose | Examples |
| --- | --- | --- |
| Expression unique | Case-insensitive uniqueness | `users (tenant_id, lower(email))`, `companies (tenant_id, upper(code))`, `accounts (company_id, lower(code))`, `journals (company_id, upper(code))` |
| Partial unique | One-row-per-parent invariants | `companies (tenant_id) WHERE is_default`, `branches (company_id) WHERE is_primary`, `roles (tenant_id) WHERE is_owner`, `journals (company_id) WHERE is_general`, `journal_entries (company_id, number) WHERE number IS NOT NULL` |
| GiST exclusion | Non-overlapping date ranges | `fiscal_years`, `fiscal_periods` on `(company_id, daterange)` |
| Trigram GIN | Type-ahead pickers without a scan | `users` name and e-mail |
| `jsonb_path_ops` GIN | "Which change touched this field?" | `audit_logs.new_values`, `audit_logs.tags` |
| Partial b-tree | The sealer's work queue | `audit_logs (tenant_id, sequence) WHERE sealed_at IS NULL` |
| Composite b-tree | The ledger reads that matter, each named for its query | `journal_lines_account_lookup (company_id, account_id, journal_entry_id)`, `fiscal_periods_posting_lookup (company_id, starts_on, ends_on, status)`, `account_period_balances_trial_balance (company_id, fiscal_period_id)` |

Partial unique indexes are how single-row invariants are enforced *in the database* rather
than by a service that a bulk import could bypass. They are also why several service methods
demote the incumbent inside the same transaction — writing the new default first would be
rejected, leaving the first write committed.

## When to revisit

- A tenant-scoped table passing ~500M rows — consider hash partitioning on `tenant_id`.
- Any single tenant exceeding ~10% of total row volume — consider a dedicated database.
- `audit_logs` growth outpacing the seal interval — raise `--batch` or shorten the schedule.
