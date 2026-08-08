import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useMoney } from '@/composables/useMoney'
import { useAuthStore } from '@/stores/auth'
import type { CompanySummary } from '@/types/domain'

/**
 * Money formatting.
 *
 * The property worth testing is what this module *does not* do. Amounts arrive as decimal strings
 * because the ledger stores `numeric(19,4)` and JavaScript's number type cannot hold them exactly, so
 * this formats and never adds. There is no `sum` here to test, and that absence is the design.
 *
 * What is tested: that the company's own currency and precision are used rather than the browser's
 * defaults, that a zero is recognised without parsing it, and that an unparseable value is passed
 * through rather than becoming "NaN" in front of a customer.
 */

function withCompany(overrides: Partial<CompanySummary> = {}): void {
  const auth = useAuthStore()

  // Assigned directly rather than through `$patch`: the auth store is a setup store, so its refs are
  // exposed as refs, and `activeCompany` is derived from `companies` rather than stored alongside it.
  auth.companies = [
    {
      id: 'company-1',
      name: 'Acme',
      code: 'ACME',
      is_default: true,
      base_currency_code: 'LKR',
      currency_precision: 2,
      timezone: 'Asia/Colombo',
      ...overrides,
    },
  ]
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('currency and precision', () => {
  it('uses the active company’s currency, not the browser’s locale default', () => {
    withCompany()

    const { currency, format } = useMoney()

    // A Sri Lankan company's books are in rupees whether the user's laptop is set to en-US or not.
    expect(currency.value).toBe('LKR')
    expect(format('1234.56')).toContain('1,234.56')
  })

  it('honours a currency with no minor units', () => {
    withCompany({ base_currency_code: 'JPY', currency_precision: 0 })

    const { formatPlain } = useMoney()

    // Not every currency has cents. Formatting yen to two places invents precision the amount does
    // not have.
    expect(formatPlain('1234')).toBe('1,234')
  })

  it('falls back to plain formatting for a currency Intl does not know', () => {
    withCompany({ base_currency_code: 'ZZZ' })

    const { format } = useMoney()

    // A customer's books should stay readable rather than throwing because of an unusual code.
    expect(format('100.00')).toContain('100.00')
  })

  it('defaults sensibly when no company is active', () => {
    const { currency, precision } = useMoney()

    expect(currency.value).toBe('LKR')
    expect(precision.value).toBe(2)
  })
})

describe('formatting', () => {
  beforeEach(withCompany)

  it('formats to the company’s precision', () => {
    const { formatPlain } = useMoney()

    expect(formatPlain('1000')).toBe('1,000.00')
    expect(formatPlain('1000.5')).toBe('1,000.50')
  })

  it('returns an empty string for a missing amount', () => {
    const { format } = useMoney()

    // Blank rather than "0.00": an absent value and a zero mean different things on a report, and a
    // column of invented zeroes is how a reader loses trust in the rest.
    expect(format(null)).toBe('')
    expect(format(undefined)).toBe('')
    expect(format('')).toBe('')
  })

  it('passes through a value it cannot parse rather than showing NaN', () => {
    const { format } = useMoney()

    // If the server ever sends something unexpected, the raw value is more use to whoever is looking
    // at it than a placeholder.
    expect(format('not-a-number')).toBe('not-a-number')
  })

  it('formats a negative amount', () => {
    const { formatPlain } = useMoney()

    expect(formatPlain('-500.00')).toContain('500.00')
  })
})

describe('zero and sign detection', () => {
  beforeEach(withCompany)

  it('recognises zero in the forms the API emits', () => {
    const { isZero } = useMoney()

    // Tested against the actual shapes `numeric(19,4)` produces, not just '0'.
    expect(isZero('0')).toBe(true)
    expect(isZero('0.00')).toBe(true)
    expect(isZero('0.0000')).toBe(true)
    expect(isZero('-0.0000')).toBe(true)
    expect(isZero(null)).toBe(true)
  })

  it('does not mistake a small amount for zero', () => {
    const { isZero } = useMoney()

    // The case that matters: a hundredth of a rupee is not nothing, and blanking it from a trial
    // balance would hide the very rounding residue an accountant is hunting for.
    expect(isZero('0.0001')).toBe(false)
    expect(isZero('0.01')).toBe(false)
    expect(isZero('-0.0001')).toBe(false)
  })

  it('detects a negative without parsing it', () => {
    const { isNegative } = useMoney()

    expect(isNegative('-1.00')).toBe(true)
    expect(isNegative('1.00')).toBe(false)
    expect(isNegative(null)).toBe(false)
  })
})
