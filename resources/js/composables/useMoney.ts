import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Formatting monetary amounts that arrive as decimal strings.
 *
 * WHY THERE IS NO ARITHMETIC HERE
 * -------------------------------
 * The ledger stores `numeric(19,4)` and the API emits strings precisely so that no amount is ever a
 * JavaScript number. `Number('0.1') + Number('0.2')` is 0.30000000000000004, and a trial balance
 * summed that way disagrees with the one the server computed — at which point the customer has two
 * figures and no way to tell which is right.
 *
 * So this module formats and it does not add. Every total the interface displays is one the server
 * calculated, including the trial balance's. The single `Number()` call below is for display only:
 * `Intl.NumberFormat` needs a number, and a value that has already been rounded to the currency's
 * precision by the server survives that round trip exactly.
 */
export function useMoney() {
  const auth = useAuthStore()

  const currency = computed<string>(() => auth.activeCompany?.base_currency_code ?? 'LKR')

  const precision = computed<number>(() => auth.activeCompany?.currency_precision ?? 2)

  /**
   * The company's currency, grouped and symbolised for the user's locale.
   *
   * Falls back to plain decimal formatting when the currency code is not one `Intl` knows — a
   * customer's books should still be readable rather than throwing.
   */
  const format = (amount: string | null | undefined): string => {
    if (amount === null || amount === undefined || amount === '') {
      return ''
    }

    const value = Number(amount)

    if (!Number.isFinite(value)) {
      // Returned verbatim rather than as "NaN". If the server ever sends something unexpected, the
      // raw value is more use to whoever is looking at it than a placeholder.
      return amount
    }

    try {
      return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.value,
        minimumFractionDigits: precision.value,
        maximumFractionDigits: precision.value,
      }).format(value)
    } catch {
      return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: precision.value,
        maximumFractionDigits: precision.value,
      }).format(value)
    }
  }

  /**
   * The amount without a currency symbol, for a column that already has one in its heading.
   */
  const formatPlain = (amount: string | null | undefined): string => {
    if (amount === null || amount === undefined || amount === '') {
      return ''
    }

    const value = Number(amount)

    if (!Number.isFinite(value)) {
      return amount
    }

    return new Intl.NumberFormat(undefined, {
      minimumFractionDigits: precision.value,
      maximumFractionDigits: precision.value,
    }).format(value)
  }

  /**
   * Whether an amount is zero, without turning it into a number to find out.
   *
   * Used to blank a zero in a debit or credit column: an accountant reads a trial balance by
   * scanning for the side that has a figure on it, and "0.00" in every other cell defeats that.
   */
  const isZero = (amount: string | null | undefined): boolean => {
    if (amount === null || amount === undefined || amount === '') {
      return true
    }

    return /^-?0(\.0*)?$/.test(amount.trim())
  }

  const isNegative = (amount: string | null | undefined): boolean =>
    typeof amount === 'string' && amount.trim().startsWith('-')

  return { currency, precision, format, formatPlain, isZero, isNegative }
}
