import { api } from '@/api/client'
import type { ApiEnvelope } from '@/types/api'
import type { TaxCode } from '@/types/domain'

/**
 * Read-only tax-code lookup (ADR 0013 §2, decision #7).
 *
 * Tax-code screens are out of scope for this wave (requirements §2) — this module exists
 * only so `TaxCodePicker.vue` has a typed, single place to source the list from. No
 * create/update/delete wrapper exists here, and none should be added: the deferred
 * tax-code CRUD scope must stay unreachable from this layer.
 */
export function listTaxCodes(companyId: string, activeOnly = true): Promise<ApiEnvelope<TaxCode[]>> {
  return api.get<TaxCode[]>(`/companies/${companyId}/tax-codes`, { active_only: activeOnly })
}
