import { describe, expect, it } from 'vitest'
import {
  blankLine,
  lineFromApi,
  lineToPayload,
  mapLineErrors,
  nextLineKey,
} from '@/components/sales/invoices/lineDraft'
import type { SalesInvoiceLine } from '@/types/domain'

/**
 * The line editor's pure data-shaping helpers, exercised directly rather than only through
 * `InvoiceLineRow.spec.ts`'s discount-toggle scenarios: `lineFromApi`'s three non-conflict
 * discount shapes (percent-only/amount-only/neither), `lineToPayload`'s wire-shape ternaries,
 * and `mapLineErrors`'s header-vs-line-error split (Gate-1 #8).
 */
function invoiceLine(overrides: Partial<SalesInvoiceLine> = {}): SalesInvoiceLine {
  return {
    id: 'line-1',
    line_number: 1,
    description: 'Consulting',
    quantity: '2.0000',
    unit_price: '100.0000',
    discount_percent: null,
    discount_amount: null,
    line_subtotal: '200.0000',
    tax_code_id: null,
    tax_code: null,
    tax_rate: '0.0000',
    tax_amount: '0.0000',
    line_total: '200.0000',
    revenue_account_id: 'acc-1',
    branch_id: null,
    ...overrides,
  }
}

describe('lineDraft', () => {
  describe('nextLineKey / blankLine', () => {
    it('hands out a fresh, strictly increasing key on every call', () => {
      const first = nextLineKey()
      const second = nextLineKey()

      expect(second).toBeGreaterThan(first)
    })

    it('starts a blank line with no discount and every server-computed figure unset', () => {
      const line = blankLine()

      expect(line.discountType).toBe('none')
      expect(line.id).toBeNull()
      expect(line.lineTotalComputed).toBeNull()
      expect(line.discountConflictNote).toBeNull()
    })
  })

  describe('lineFromApi', () => {
    it('keeps discountType "percent" when only a percent discount is present, with no conflict note', () => {
      const draft = lineFromApi(invoiceLine({ discount_percent: '10.0000', discount_amount: null }))

      expect(draft.discountType).toBe('percent')
      expect(draft.discountPercent).toBe('10.0000')
      expect(draft.discountAmount).toBe('')
      expect(draft.discountConflictNote).toBeNull()
    })

    it('keeps discountType "amount" when only an amount discount is present, with no conflict note', () => {
      const draft = lineFromApi(invoiceLine({ discount_percent: null, discount_amount: '5.0000' }))

      expect(draft.discountType).toBe('amount')
      expect(draft.discountAmount).toBe('5.0000')
      expect(draft.discountPercent).toBe('')
      expect(draft.discountConflictNote).toBeNull()
    })

    it('keeps discountType "none" when neither discount is present', () => {
      const draft = lineFromApi(invoiceLine({ discount_percent: null, discount_amount: null }))

      expect(draft.discountType).toBe('none')
      expect(draft.discountPercent).toBe('')
      expect(draft.discountAmount).toBe('')
      expect(draft.discountConflictNote).toBeNull()
    })

    it('assigns a fresh client-side key and never sends the tax code as null when one is set', () => {
      const draft = lineFromApi(invoiceLine({ tax_code: 'VAT' }))

      expect(draft.key).toEqual(expect.any(Number))
      expect(draft.taxCode).toBe('VAT')
    })

    it('falls back to an empty tax code when the API returns none', () => {
      const draft = lineFromApi(invoiceLine({ tax_code: null }))

      expect(draft.taxCode).toBe('')
    })
  })

  describe('lineToPayload', () => {
    it('sends discount_percent and nulls discount_amount for a percent-type line', () => {
      const payload = lineToPayload({ ...blankLine(), discountType: 'percent', discountPercent: '10' })

      expect(payload.discount_percent).toBe('10')
      expect(payload.discount_amount).toBeNull()
    })

    it('sends discount_amount and nulls discount_percent for an amount-type line', () => {
      const payload = lineToPayload({ ...blankLine(), discountType: 'amount', discountAmount: '20' })

      expect(payload.discount_amount).toBe('20')
      expect(payload.discount_percent).toBeNull()
    })

    it('nulls both discount fields for a no-discount line', () => {
      const payload = lineToPayload({ ...blankLine(), discountType: 'none' })

      expect(payload.discount_percent).toBeNull()
      expect(payload.discount_amount).toBeNull()
    })

    it('sends tax_code as null, never an empty string, when no tax is selected', () => {
      const payload = lineToPayload({ ...blankLine(), taxCode: '' })

      expect(payload.tax_code).toBeNull()
    })

    it('sends the tax code string as-is when one is selected', () => {
      const payload = lineToPayload({ ...blankLine(), taxCode: 'VAT' })

      expect(payload.tax_code).toBe('VAT')
    })
  })

  describe('mapLineErrors', () => {
    it('splits line-shaped keys from header-level keys', () => {
      const { header, lines } = mapLineErrors({
        'lines.0.tax_code': 'That tax code does not belong to this company.',
        customer_id: 'Unknown customer.',
      })

      expect(lines).toEqual({ 0: { tax_code: 'That tax code does not belong to this company.' } })
      expect(header).toEqual({ customer_id: 'Unknown customer.' })
    })

    it('merges multiple field errors reported for the same line index', () => {
      const { lines } = mapLineErrors({
        'lines.2.quantity': 'Required.',
        'lines.2.unit_price': 'Required.',
      })

      expect(lines).toEqual({ 2: { quantity: 'Required.', unit_price: 'Required.' } })
    })

    it('returns an empty header/lines split when given no errors at all', () => {
      expect(mapLineErrors({})).toEqual({ header: {}, lines: {} })
    })
  })
})
