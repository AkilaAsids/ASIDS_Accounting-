import { api } from '@/api/client'
import type { ApiEnvelope } from '@/types/api'
import type { SalesInvoice, SalesInvoiceInput } from '@/types/domain'

/**
 * Typed wrapper over `companies/{company}/sales-invoices` (ADR 0013 §2).
 *
 * No error handling of its own — every call still rejects with `ApiError`, exactly as
 * `ChartOfAccountsPage.create()`/`UsersPage.invite()` already do. Its only value is one
 * place that knows the URL shapes and keeps every amount a decimal string end to end
 * (`SalesInvoiceInput` — never a JSON number, §4.7.2).
 */

export interface InvoiceListParams {
  page?: number
  q?: string
  filter?: {
    status?: string
    customer_id?: string
    branch_id?: string
  }
}

/**
 * Never sends `include=lines` — that belongs to the single-invoice `GET` only (§4.6.5). The
 * list renders header fields; line detail is out of scope for this screen by design.
 */
export function listSalesInvoices(
  companyId: string,
  params: InvoiceListParams = {},
): Promise<ApiEnvelope<SalesInvoice[]>> {
  return api.get<SalesInvoice[]>(`/companies/${companyId}/sales-invoices`, params)
}

/** Always returns `lines` — the single-invoice `GET` includes them regardless (§4.9.1). */
export function getSalesInvoice(companyId: string, invoiceId: string): Promise<ApiEnvelope<SalesInvoice>> {
  return api.get<SalesInvoice>(`/companies/${companyId}/sales-invoices/${invoiceId}`)
}

export function createSalesInvoice(
  companyId: string,
  body: SalesInvoiceInput,
): Promise<ApiEnvelope<SalesInvoice>> {
  return api.post<SalesInvoice>(`/companies/${companyId}/sales-invoices`, body)
}

/**
 * `body` follows the same clear-vs-omit discipline as `SalesInvoiceInput` on update: an
 * omitted key leaves that field untouched, an explicit `null` clears it, and `lines` — when
 * present at all — replaces every line (§4.8.2/§4.8.4). The caller (the editor page) builds
 * that payload; this wrapper only knows the URL.
 */
export function updateSalesInvoice(
  companyId: string,
  invoiceId: string,
  body: Partial<SalesInvoiceInput>,
): Promise<ApiEnvelope<SalesInvoice>> {
  return api.put<SalesInvoice>(`/companies/${companyId}/sales-invoices/${invoiceId}`, body)
}

/** Draft only. No tombstone, no restore counterpart (§4.12.3). */
export function deleteSalesInvoice(companyId: string, invoiceId: string): Promise<ApiEnvelope<null>> {
  return api.delete<null>(`/companies/${companyId}/sales-invoices/${invoiceId}`)
}

export function issueSalesInvoice(companyId: string, invoiceId: string): Promise<ApiEnvelope<SalesInvoice>> {
  return api.post<SalesInvoice>(`/companies/${companyId}/sales-invoices/${invoiceId}/issue`)
}

export function cancelSalesInvoice(
  companyId: string,
  invoiceId: string,
  reason: string,
): Promise<ApiEnvelope<SalesInvoice>> {
  return api.post<SalesInvoice>(`/companies/${companyId}/sales-invoices/${invoiceId}/cancel`, { reason })
}
