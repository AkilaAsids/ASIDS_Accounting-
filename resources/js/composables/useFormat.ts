import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Formatting that must agree with the server.
 *
 * Money and dates are formatted from the *company's* currency, precision and timezone —
 * never the browser's locale. A Sri Lankan company's books read in LKR whether the user is
 * in Colombo or London, and a transaction date must not shift because a laptop is set to a
 * different timezone.
 */
export function useFormat() {
  const auth = useAuthStore()

  const currency = computed(() => auth.activeCompany?.base_currency_code ?? 'LKR')
  const precision = computed(() => auth.activeCompany?.currency_precision ?? 2)
  const timezone = computed(() => auth.user?.preferences.timezone ?? 'Asia/Colombo')
  const locale = computed(() => auth.user?.preferences.locale ?? 'en')

  /**
   * `en-IN` for LKR, because it is the locale whose *grouping* is the lakh/crore convention
   * (12,34,567) that this market reads.
   *
   * `en-LK` was the obvious choice and is the wrong one: CLDR gives it western grouping
   * (1,234,567), so the intent went unmet with nothing to show it. Nothing else about the locale is
   * used here — the currency and its precision come from the company, and the symbol from the
   * currency code — so this selects a grouping rule rather than a country.
   *
   * The workspace can already express this: `localisation.number_format` defaults to `lakh` and is
   * a public setting. Reading it here, rather than inferring from the currency, is the remaining
   * refinement — it would let an exporter reporting in LKR choose western grouping.
   */
  const numberLocale = computed(() => (currency.value === 'LKR' ? 'en-IN' : locale.value))

  function money(amount: number | string | null | undefined): string {
    if (amount === null || amount === undefined || amount === '') {
      return '—'
    }

    const value = typeof amount === 'string' ? Number.parseFloat(amount) : amount

    if (Number.isNaN(value)) {
      return '—'
    }

    return new Intl.NumberFormat(numberLocale.value, {
      style: 'currency',
      currency: currency.value,
      minimumFractionDigits: precision.value,
      maximumFractionDigits: precision.value,
    }).format(value)
  }

  function number(value: number | null | undefined, decimals = 0): string {
    if (value === null || value === undefined) return '—'

    return new Intl.NumberFormat(numberLocale.value, {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    }).format(value)
  }

  function date(iso: string | null | undefined): string {
    if (!iso) return '—'

    return new Intl.DateTimeFormat(locale.value, {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      timeZone: timezone.value,
    }).format(new Date(iso))
  }

  function dateTime(iso: string | null | undefined): string {
    if (!iso) return '—'

    return new Intl.DateTimeFormat(locale.value, {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      timeZone: timezone.value,
    }).format(new Date(iso))
  }

  /**
   * "3 minutes ago". Used for security telemetry, where the gap matters more than the
   * absolute time — "last seen 4 days ago" reads faster than a timestamp when scanning a
   * device list for something unfamiliar.
   */
  function relative(iso: string | null | undefined): string {
    if (!iso) return 'never'

    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000)
    const formatter = new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' })

    const thresholds: Array<[Intl.RelativeTimeFormatUnit, number]> = [
      ['year', 31_536_000],
      ['month', 2_592_000],
      ['week', 604_800],
      ['day', 86_400],
      ['hour', 3600],
      ['minute', 60],
    ]

    for (const [unit, size] of thresholds) {
      if (Math.abs(seconds) >= size) {
        return formatter.format(-Math.round(seconds / size), unit)
      }
    }

    return formatter.format(-seconds, 'second')
  }

  return { currency, precision, timezone, money, number, date, dateTime, relative }
}
