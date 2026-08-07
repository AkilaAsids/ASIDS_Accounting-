import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useFormat } from '@/composables/useFormat'

/**
 * Formatting that must agree with the server.
 *
 * Money and dates are formatted from the *company's* currency, precision and timezone — never the
 * browser's locale. A Sri Lankan company's books read in LKR whether the user is in Colombo or
 * London, and a transaction date must not shift because a laptop is set to a different timezone.
 * That second one is the dangerous case: a date that renders one day earlier in a London browser
 * puts a March invoice in the previous fiscal year.
 */

/** Seeds the store the composable reads from, without going near the network. */
function withSession(overrides: {
  currency?: string
  precision?: number
  timezone?: string
  locale?: string
}): void {
  const auth = useAuthStore()

  auth.$patch({
    user: {
      id: 'user-1',
      first_name: 'Kumari',
      last_name: 'Silva',
      full_name: 'Kumari Silva',
      email: 'kumari@acme.test',
      preferences: {
        timezone: overrides.timezone ?? 'Asia/Colombo',
        locale: overrides.locale ?? 'en',
      },
    },
    companies: [
      {
        id: 'company-1',
        name: 'Demo Trading',
        code: 'DTL',
        is_default: true,
        base_currency_code: overrides.currency ?? 'LKR',
        currency_precision: overrides.precision ?? 2,
      },
    ],
  } as never)
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('money', () => {
  it('formats in the company’s currency', () => {
    withSession({ currency: 'LKR' })

    const formatted = useFormat().money(1234567.5)

    expect(formatted).toContain('1')
    expect(formatted).toMatch(/LKR|Rs/)
  })

  it('uses the company’s precision', () => {
    withSession({ currency: 'LKR', precision: 0 })

    // Some currencies have no minor unit, and showing "LKR 100.00" for a company configured to zero
    // decimals disagrees with every document the server renders.
    expect(useFormat().money(100)).not.toContain('.')
  })

  it('groups LKR by the lakh convention rather than the western one', () => {
    withSession({ currency: 'LKR' })

    // 12,34,567 is what the market reads. Western grouping is not wrong arithmetic, it is a number
    // a Sri Lankan bookkeeper has to stop and re-read.
    expect(useFormat().money(1234567)).toContain('12,34,567')
  })

  it('accepts a decimal string, which is how the API sends amounts', () => {
    withSession({ currency: 'LKR' })

    // Amounts arrive as strings so no precision is lost in JSON. A formatter that only took numbers
    // would silently render "NaN" for every real payload.
    expect(useFormat().money('2500.00')).toContain('2,500')
  })

  it('renders a dash for a missing amount rather than zero', () => {
    withSession({})

    const format = useFormat()

    // "—" and "0.00" mean different things on a financial statement: one is "not recorded", the
    // other is "recorded as nothing".
    expect(format.money(null)).toBe('—')
    expect(format.money(undefined)).toBe('—')
    expect(format.money('')).toBe('—')
  })

  it('renders a dash for a value that is not a number', () => {
    withSession({})

    expect(useFormat().money('not-a-number')).toBe('—')
  })

  it('falls back to LKR when no company is selected', () => {
    // A user with no company membership still renders a shell, and it must not throw.
    expect(useFormat().money(10)).toMatch(/LKR|Rs/)
  })
})

describe('numbers', () => {
  it('formats with no decimals by default', () => {
    withSession({})

    expect(useFormat().number(1500)).toBe('1,500')
  })

  it('formats with the requested decimals', () => {
    withSession({})

    expect(useFormat().number(1.5, 2)).toBe('1.50')
  })

  it('renders a dash for a missing value', () => {
    withSession({})

    expect(useFormat().number(null)).toBe('—')
  })
})

describe('dates', () => {
  it('formats a date', () => {
    withSession({})

    expect(useFormat().date('2026-03-31')).toMatch(/2026/)
  })

  it('renders a dash for a missing date', () => {
    withSession({})

    expect(useFormat().date(null)).toBe('—')
    expect(useFormat().dateTime(undefined)).toBe('—')
  })

  it('renders an instant in the user’s timezone, not the browser’s', () => {
    withSession({ timezone: 'Asia/Colombo' })
    const colombo = useFormat().dateTime('2026-03-31T20:00:00Z')

    setActivePinia(createPinia())
    withSession({ timezone: 'UTC' })
    const utc = useFormat().dateTime('2026-03-31T20:00:00Z')

    // The same instant, two configured zones. Colombo is UTC+5:30, so 20:00Z is the following day
    // there — which is exactly the boundary that would otherwise file a transaction in the wrong
    // period.
    expect(colombo).not.toBe(utc)
  })
})

describe('relative time', () => {
  it('reports never for a missing timestamp', () => {
    withSession({})

    // "never" rather than "—": on a sign-in history screen the distinction between "no record" and
    // "has never signed in" is the answer the administrator is looking for.
    expect(useFormat().relative(null)).toBe('never')
  })

  it('describes a recent instant in seconds', () => {
    withSession({})

    expect(useFormat().relative(new Date(Date.now() - 5_000).toISOString())).toMatch(/second/)
  })

  it('describes an older instant in a larger unit', () => {
    withSession({})

    const threeDaysAgo = new Date(Date.now() - 3 * 24 * 60 * 60 * 1000).toISOString()

    // Picks the largest unit that fits, so a list of devices reads "3 days ago" rather than
    // "259200 seconds ago".
    expect(useFormat().relative(threeDaysAgo)).toMatch(/day/)
  })
})
