/** Domain shapes, mirroring the API resources one-for-one. */

export type UserStatus = 'pending_invitation' | 'active' | 'suspended' | 'deactivated'
export type OrganizationStatus = 'active' | 'archived'
export type Theme = 'system' | 'light' | 'dark'
export type SettingScope = 'user' | 'company' | 'tenant' | 'system'

export interface User {
  id: string
  first_name: string
  last_name: string | null
  full_name: string
  initials: string
  email: string
  email_verified: boolean
  phone: string | null
  job_title: string | null
  employee_number: string | null
  avatar_path: string | null
  status: UserStatus
  status_label: string
  is_platform_admin: boolean
  is_owner: boolean
  preferences: { locale: string; timezone: string; theme: Theme }
  two_factor_enabled: boolean
  default_company?: { id: string; name: string; code: string } | null
  roles?: Array<{ id: string; name: string; label: string; level: number; is_owner: boolean }>
  company_count?: number
  /** Present only for the account holder or a viewer with the sign-in-history permission. */
  security?: {
    last_login_at: string | null
    last_login_ip: string | null
    last_activity_at: string | null
    password_changed_at: string | null
    password_expired: boolean
    must_change_password: boolean
    is_locked: boolean
    locked_until: string | null
    failed_login_attempts: number
  }
  invited_at: string | null
  invitation_accepted_at: string | null
  deactivated_at: string | null
  created_at: string | null
}

export interface Workspace {
  id: string
  name: string
  slug: string
  locale: string
  timezone: string
  currency_code: string
  country_code: string
  on_trial: boolean
  trial_ends_at: string | null
}

export interface CompanySummary {
  id: string
  name: string
  code: string
  base_currency_code: string
  currency_precision: number
  timezone: string
  is_default: boolean
}

export interface Company {
  id: string
  name: string
  legal_name: string | null
  display_name: string
  code: string
  slug: string
  status: OrganizationStatus
  status_label: string
  is_default: boolean
  archived_at: string | null
  accounting: {
    base_currency_code: string
    currency_precision: number
    fiscal_year_start_month: number
    fiscal_year_start_day: number
    uses_calendar_fiscal_year: boolean
    current_fiscal_year: { starts_on: string; ends_on: string }
  }
  registrations: {
    registration_number: string | null
    tax_identification_number: string | null
    vat_registration_number: string | null
    svat_registration_number: string | null
    is_vat_registered: boolean
    is_svat_registered: boolean
  }
  locale: { country_code: string; timezone: string; locale: string }
  branch_count?: number
  member_count?: number
}

export interface Role {
  id: string
  name: string
  label: string
  description: string | null
  level: number
  is_system: boolean
  is_owner: boolean
  is_template: boolean
  capabilities: {
    renameable: boolean
    deletable: boolean
    permissions_editable: boolean
    grantable_by_current_user: boolean
  }
  permissions?: string[]
  assigned_user_count?: number
}

export interface PermissionGroup {
  module: string
  resources: Array<{
    resource: string
    permissions: Array<{
      id: string
      name: string
      action: string
      label: string
      description: string | null
      is_sensitive: boolean
    }>
  }>
}

export interface UserDevice {
  id: string
  name: string
  device_type: string | null
  platform: string | null
  browser: string | null
  is_trusted: boolean
  trust_expires_at: string | null
  last_ip_address: string | null
  last_seen_at: string | null
  is_current_device: boolean
  revoked_at: string | null
}

export interface LoginHistoryEntry {
  id: string
  outcome: string
  succeeded: boolean
  failure_reason: string | null
  channel: string
  ip_address: string
  country_code: string | null
  device_type: string | null
  platform: string | null
  browser: string | null
  two_factor_used: boolean
  created_at: string
}

export interface AccessToken {
  id: string
  name: string
  description: string | null
  abilities: string[]
  is_usable: boolean
  expires_at: string | null
  revoked_at: string | null
  last_used_at: string | null
  last_used_ip: string | null
  created_at: string | null
}

export interface SettingField {
  key: string
  label: string
  description: string
  group: string
  type:
    | 'string'
    | 'text'
    | 'integer'
    | 'float'
    | 'boolean'
    | 'array'
    | 'json'
    | 'date'
    | 'datetime'
    | 'time'
  value: unknown
  default: unknown
  is_overridden: boolean
  overridden_at: SettingScope | null
  options: Record<string, string> | null
  overridable_at: SettingScope[]
  sort_order: number
}

export interface SettingGroup {
  group: string
  settings: SettingField[]
}

/** Everything the shell needs in one call — see AuthenticationController::sessionPayload. */
export interface SessionPayload {
  authenticated: true
  user: User
  permissions: string[]
  workspace: Workspace | null
  companies: CompanySummary[]
  requires: {
    password_change: boolean
    two_factor_enrolment: boolean
    company_selection: boolean
  }
}

export interface UnauthenticatedPayload {
  authenticated: false
}

export type SessionResponse = SessionPayload | UnauthenticatedPayload

export interface TwoFactorEnrolment {
  secret: string
  otpauth_uri: string
  qr_code_svg: string
  digits: number
  period: number
}

/*
|--------------------------------------------------------------------------
| Accounting
|--------------------------------------------------------------------------
|
| Every monetary field is a `string`, not a `number`, and that is not an
| oversight. JSON numbers become IEEE-754 doubles in JavaScript, and an amount
| that round-trips through one is no longer the amount the ledger stored —
| which is the whole reason the database uses numeric(19,4). Amounts are
| formatted for display and never arithmetic'd in the browser; the server
| computes every total, including the trial balance's.
*/

export type AccountType = 'asset' | 'liability' | 'equity' | 'income' | 'expense'

export type NormalBalance = 'debit' | 'credit'

export type FinancialStatement = 'balance_sheet' | 'profit_and_loss'

export interface Account {
  id: string
  company_id: string
  parent_id: string | null
  code: string
  name: string
  description: string | null
  type: AccountType
  type_label: string
  normal_balance: NormalBalance
  statement: FinancialStatement
  is_permanent: boolean
  is_postable: boolean
  is_system: boolean
  system_key: string | null
  is_active: boolean
  archived_at: string | null
  sort_order: number
  template_version: string | null
  capabilities: {
    can_update: boolean
    can_delete: boolean
    accepts_postings: boolean
  }
  children?: Account[]
}

export interface ChartTemplateOffer {
  version: string
  name: string
  description: string
  /** Shown wherever the template is offered or applied. Not optional. */
  disclaimer: string
  account_count: number
  can_apply: boolean
}

export type JournalEntryStatus = 'draft' | 'posted' | 'reversed'

export interface JournalLine {
  id: string
  line_number: number
  account_id: string
  branch_id: string | null
  debit: string
  credit: string
  side: NormalBalance
  description: string | null
  transaction_currency_code: string | null
  transaction_amount: string | null
  exchange_rate: string | null
  account?: {
    id: string
    code: string
    name: string
    type: AccountType
  }
}

export interface JournalEntry {
  id: string
  company_id: string
  journal_id: string
  fiscal_period_id: string
  number: string | null
  document_type: string
  document_type_label: string
  entry_date: string
  description: string
  reference: string | null
  status: JournalEntryStatus
  status_label: string
  posted_at: string | null
  posted_by_id: string | null
  reverses_entry_id: string | null
  reversed_by_entry_id: string | null
  reversed_at: string | null
  reversal_reason: string | null
  capabilities: {
    can_update: boolean
    can_post: boolean
    can_reverse: boolean
  }
  lines?: JournalLine[]
  period?: {
    id: string
    label: string
    status: PeriodStatus
  }
}

export type PeriodStatus = 'open' | 'closed' | 'locked'

export interface FiscalPeriod {
  id: string
  sequence: number
  label: string
  starts_on: string
  ends_on: string
  status: PeriodStatus
  status_label: string
  accepts_postings: boolean
  closed_at: string | null
  reopened_at: string | null
  reopen_reason: string | null
}

export interface FiscalYear {
  id: string
  label: string
  starts_on: string
  ends_on: string
  is_closed: boolean
  closed_at: string | null
  closing_entry_id: string | null
  periods: FiscalPeriod[]
}

export interface TrialBalanceRow {
  account_id: string
  code: string
  name: string
  type: AccountType
  statement: FinancialStatement
  normal_balance: NormalBalance
  debit: string
  credit: string
  balance: string
}

export interface TrialBalanceMeta {
  from: string
  to: string
  currency: string
  totals: { debit: string; credit: string }
  /**
   * Whether debits equal credits. Computed by the server, because a client
   * summing doubles would produce a figure that disagrees with the ledger and
   * the customer would reasonably blame the accounting.
   */
  ties: boolean
}

export interface AccountLedgerRow {
  entry_id: string
  number: string | null
  entry_date: string
  description: string
  status: JournalEntryStatus
  debit: string
  credit: string
  running_balance: string
}

/*
|--------------------------------------------------------------------------
| Sales — receivables reporting
|--------------------------------------------------------------------------
|
| Every amount is a `string`, for the reason stated above the accounting block:
| a JSON number is an IEEE-754 double by the time any client reads it, and the
| ledger stores numeric(19,4) precisely so that never happens. Amounts here are
| formatted for display and never summed — each report carries the totals the
| server computed, in `meta`.
*/

export interface OutstandingReceivableRow {
  customer_id: string
  code: string
  name: string
  /** How many collectable invoices make up the balance. A count, so a real number. */
  invoice_count: number
  outstanding: string
}

export interface OutstandingReceivableMeta {
  currency: string
  /**
   * The day the figures were read, not a parameter. The balance is current state with no
   * history behind it, so the report offers no as-at date to ask for.
   */
  as_of: string
  totals: { outstanding: string }
}

/**
 * The five ageing buckets, in the order a statement is read.
 *
 * Aged from each invoice's **due date**, not its invoice date, and inclusive at both ends so an
 * invoice falls in exactly one. `not_yet_due` is a real receivable that simply is not late.
 */
export interface AgedReceivableBuckets {
  not_yet_due: string
  days_0_30: string
  days_31_60: string
  days_61_90: string
  days_over_90: string
  total: string
}

export interface AgedReceivableRow extends AgedReceivableBuckets {
  customer_id: string
  code: string
  name: string
}

export interface AgedReceivableMeta {
  currency: string
  /** The cutoff actually used, whether supplied by the caller or defaulted by the server. */
  as_of: string
  totals: AgedReceivableBuckets
}
