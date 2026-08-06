/**
 * Wire contracts.
 *
 * Every successful response is `{ data, meta }`; every failure is an RFC 9457 problem
 * document. Modelling both precisely here is what lets the rest of the front end handle
 * errors without `any` and without string-matching messages.
 */

export interface ApiMeta {
  request_id: string
  api_version: string
  pagination?: Pagination
  [key: string]: unknown
}

export interface ApiEnvelope<T> {
  data: T
  meta: ApiMeta
}

export interface Pagination {
  total: number
  per_page: number
  current_page: number
  last_page: number
  from: number | null
  to: number | null
}

/**
 * RFC 9457 problem document. `type` is the stable identifier clients branch on — never
 * `title`, which is prose and may be reworded or translated.
 */
export interface ProblemDocument {
  type: string
  title: string
  status: number
  detail: string
  instance?: string
  request_id?: string
  /** Present on 422 from a validation failure. */
  errors?: Record<string, string[]>
  retry_after_seconds?: number
  [key: string]: unknown
}

/** The problem `type` suffixes the front end reacts to structurally. */
export const ProblemCode = {
  Unauthenticated: 'unauthenticated',
  Forbidden: 'forbidden',
  ValidationFailed: 'validation-failed',
  PasswordExpired: 'password-expired',
  TwoFactorConfirmationRequired: 'two-factor-confirmation-required',
  TwoFactorEnrolmentRequired: 'two-factor-enrolment-required',
  NoCompanyMembership: 'no-company-membership',
  WorkspaceUnavailable: 'workspace-unavailable',
  AccountLocked: 'account-locked',
} as const

export type ProblemCodeValue = (typeof ProblemCode)[keyof typeof ProblemCode]

export interface ListParams {
  page?: number
  per_page?: number
  sort?: string
  q?: string
  filter?: Record<string, string | number | boolean | undefined>
  include?: string
}
