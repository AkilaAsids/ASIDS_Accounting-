import { api } from '@/api/client'
import type { ApiEnvelope } from '@/types/api'
import type { Customer, CustomerStatus } from '@/types/domain'

/**
 * Typed wrapper over `companies/{company}/customers` (ADR 0013 §2, customer lane).
 *
 * Adds no error handling of its own — every call still rejects with `ApiError`, and callers
 * `try`/`catch` and branch on it exactly as `ChartOfAccountsPage.create()` and
 * `UsersPage.invite()` already do. Its value is being the one place that knows these URL
 * shapes and payload types, so no page hand-rolls a string or lets a JSON number slip into a
 * money field.
 */

export interface CustomerListParams {
  page?: number
  per_page?: number
  q?: string
  sort?: string
  filter?: {
    status?: CustomerStatus
    branch_id?: string
  }
}

/**
 * The write shape shared by create and update.
 *
 * `status` is deliberately not a field here — a customer's state moves only through the
 * lifecycle sub-routes below, never through this body (requirements §4.2.7); the type simply
 * has no such property, so sending one is a compile error, not a runtime discipline. Every
 * money field (`credit_limit`) is `string | null`, never a bare `number`, for the same reason
 * every monetary field in `types/domain.ts` is a string — a JSON number does not round-trip
 * the ledger's `numeric(19,4)` precisely.
 */
export interface CustomerWritePayload {
  code?: string | null
  name?: string
  legal_name?: string | null
  tax_identification_number?: string | null
  vat_registration_number?: string | null
  is_vat_registered?: boolean
  email?: string | null
  phone?: string | null
  website?: string | null
  address_line_1?: string | null
  address_line_2?: string | null
  city?: string | null
  district?: string | null
  postal_code?: string | null
  country_code?: string | null
  payment_terms_days?: number
  credit_limit?: string | null
  receivable_account_id?: string | null
  branch_id?: string | null
  notes?: string | null
}

export type CustomerCreatePayload = CustomerWritePayload & { name: string }
export type CustomerUpdatePayload = CustomerWritePayload

function resourceUrl(companyId: string, customerId?: string): string {
  const base = `/companies/${companyId}/customers`
  return customerId ? `${base}/${customerId}` : base
}

export function listCustomers(
  companyId: string,
  params: CustomerListParams = {},
): Promise<ApiEnvelope<Customer[]>> {
  return api.get<Customer[]>(resourceUrl(companyId), params)
}

export function getCustomer(companyId: string, customerId: string): Promise<ApiEnvelope<Customer>> {
  return api.get<Customer>(resourceUrl(companyId, customerId))
}

export function createCustomer(
  companyId: string,
  body: CustomerCreatePayload,
): Promise<ApiEnvelope<Customer>> {
  return api.post<Customer>(resourceUrl(companyId), body)
}

export function updateCustomer(
  companyId: string,
  customerId: string,
  body: CustomerUpdatePayload,
): Promise<ApiEnvelope<Customer>> {
  return api.put<Customer>(resourceUrl(companyId, customerId), body)
}

export function archiveCustomer(companyId: string, customerId: string): Promise<ApiEnvelope<Customer>> {
  return api.post<Customer>(`${resourceUrl(companyId, customerId)}/archive`)
}

export function restoreCustomer(companyId: string, customerId: string): Promise<ApiEnvelope<Customer>> {
  return api.post<Customer>(`${resourceUrl(companyId, customerId)}/restore`)
}

export function deactivateCustomer(
  companyId: string,
  customerId: string,
): Promise<ApiEnvelope<Customer>> {
  return api.post<Customer>(`${resourceUrl(companyId, customerId)}/deactivate`)
}

export function reactivateCustomer(
  companyId: string,
  customerId: string,
): Promise<ApiEnvelope<Customer>> {
  return api.post<Customer>(`${resourceUrl(companyId, customerId)}/reactivate`)
}

export function deleteCustomer(companyId: string, customerId: string): Promise<ApiEnvelope<null>> {
  return api.delete<null>(resourceUrl(companyId, customerId))
}
