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
