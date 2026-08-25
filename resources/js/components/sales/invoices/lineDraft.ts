import type { SalesInvoiceLine, SalesInvoiceLineInput } from '@/types/domain'

/**
 * The line editor's local, editable shape for one invoice line (ADR 0013 §7).
 *
 * Deliberately **not** `SalesInvoiceLine` — that type carries server-computed, read-only
 * figures (`line_subtotal`, `tax_rate`, `tax_amount`, `line_total`) alongside the editable
 * ones, and mixing "what the user is typing" with "what the server last computed" in one
 * object is how a page ends up rendering a stale or client-guessed total. Here the two are
 * separate: the editable fields are always the user's, and the four `*Computed` fields are
 * populated **only** from the last successful save response, `null` before that (§1.5/§7.4).
 *
 * `key` is a stable client-side identity for `v-for` and per-line error mapping — the API
 * assigns no id to a draft line, and an array index is not stable across add/remove.
 */
export interface LineDraft {
  key: number
  /** Only set for a line that came from an existing invoice — never sent back to the API. */
  id: string | null
  description: string
  quantity: string
  unitPrice: string
  discountType: 'none' | 'percent' | 'amount'
  discountPercent: string
  discountAmount: string
  /** The tax **code**, never an id (§4.7.3). Empty string means "no tax". */
  taxCode: string
  revenueAccountId: string
  branchId: string | null
  /** Server-computed, read-only. `null` until the last successful save response. */
  lineSubtotalComputed: string | null
  taxRateComputed: string | null
  taxAmountComputed: string | null
  lineTotalComputed: string | null
  /** Set only for the defensive "both discounts arrived non-null" case (§2.3.2). */
  discountConflictNote: string | null
}

let counter = 0

export function nextLineKey(): number {
  counter += 1
  return counter
}

export function blankLine(): LineDraft {
  return {
    key: nextLineKey(),
    id: null,
    description: '',
    quantity: '',
    unitPrice: '',
    discountType: 'none',
    discountPercent: '',
    discountAmount: '',
    taxCode: '',
    revenueAccountId: '',
    branchId: null,
    lineSubtotalComputed: null,
    taxRateComputed: null,
    taxAmountComputed: null,
    lineTotalComputed: null,
    discountConflictNote: null,
  }
}

/**
 * Maps a line returned by the API back into the editable draft shape, re-rendering from the
 * response rather than keeping whatever was on screen before the save (§4.8.5/§7.4).
 *
 * Defensive case (should not happen given the toggle, but the data ultimately comes from a
 * server response): if both `discount_percent` and `discount_amount` arrive non-null,
 * `discount_percent` wins the toggle's initial position and `discount_amount` is cleared
 * locally with a one-time note, rather than silently carrying both forward (§2.3.2).
 */
export function lineFromApi(line: SalesInvoiceLine): LineDraft {
  const bothPresent = line.discount_percent !== null && line.discount_amount !== null
  const discountType: LineDraft['discountType'] = bothPresent
    ? 'percent'
    : line.discount_percent !== null
      ? 'percent'
      : line.discount_amount !== null
        ? 'amount'
        : 'none'

  return {
    key: nextLineKey(),
    id: line.id,
    description: line.description,
    quantity: line.quantity,
    unitPrice: line.unit_price,
    discountType,
    discountPercent: discountType === 'percent' ? (line.discount_percent ?? '') : '',
    discountAmount: discountType === 'amount' ? (line.discount_amount ?? '') : '',
    taxCode: line.tax_code ?? '',
    revenueAccountId: line.revenue_account_id,
    branchId: line.branch_id,
    lineSubtotalComputed: line.line_subtotal,
    taxRateComputed: line.tax_rate,
    taxAmountComputed: line.tax_amount,
    lineTotalComputed: line.line_total,
    discountConflictNote: bothPresent
      ? 'This line arrived with both a percentage and an amount discount. The percentage was kept; the amount was cleared.'
      : null,
  }
}

/** Builds the wire payload for one line. Amounts stay the exact strings the user typed. */
export function lineToPayload(line: LineDraft): SalesInvoiceLineInput {
  return {
    description: line.description,
    quantity: line.quantity,
    unit_price: line.unitPrice,
    revenue_account_id: line.revenueAccountId,
    tax_code: line.taxCode === '' ? null : line.taxCode,
    discount_percent: line.discountType === 'percent' ? line.discountPercent : null,
    discount_amount: line.discountType === 'amount' ? line.discountAmount : null,
    branch_id: line.branchId,
  }
}

/**
 * Splits a flattened `ApiError.fieldErrors` map into header-level errors and per-line
 * errors, keyed by the line's position in the submitted array (§4.7.9, Gate-1 #8). A key
 * that is not shaped `lines.<i>.<field>` is treated as a header field error — a genuinely
 * flat, non-field domain refusal never reaches here at all, because `fieldErrors` is `{}`
 * for those and the caller notifies instead (matching every other page's 422 handling).
 */
export function mapLineErrors(fieldErrors: Record<string, string>): {
  header: Record<string, string>
  lines: Record<number, Record<string, string>>
} {
  const header: Record<string, string> = {}
  const lines: Record<number, Record<string, string>> = {}
  const linePattern = /^lines\.(\d+)\.(.+)$/

  for (const [key, message] of Object.entries(fieldErrors)) {
    const match = linePattern.exec(key)

    if (match) {
      const index = Number(match[1])
      const field = match[2] as string
      lines[index] = { ...(lines[index] ?? {}), [field]: message }
    } else {
      header[key] = message
    }
  }

  return { header, lines }
}
