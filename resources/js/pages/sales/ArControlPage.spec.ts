import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type * as ApiClientModule from '@/api/client'
import type { ArControlMeta, ArControlRow } from '@/types/domain'

/**
 * The AR control reconciliation.
 *
 * Everything asserted here is about *not hiding a discrepancy*. The backend already owns the
 * reconciliation mathematics, the account mapping and the line-number-1 invariant; what this page can
 * uniquely get wrong is presenting a disagreement as though it were not one — by normalising a sign,
 * blanking a zero, inferring the verdict from a total that cancels, or reporting a failed request as
 * a clean set of books. Each of those is a test below, and each would be invisible in review.
 *
 * The fixtures deliberately put `meta.totals` out of step with the rows, so a page that ever starts
 * computing its own totals fails here rather than in front of an accountant.
 */

const get = vi.fn()

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof ApiClientModule>('@/api/client')

  return { ...actual, api: { get, post: vi.fn(), setActiveCompany: vi.fn(), configure: vi.fn() } }
})

const ArControlPage = (await import('@/pages/sales/ArControlPage.vue')).default
const { useAuthStore } = await import('@/stores/auth')
const { useUiStore } = await import('@/stores/ui')
const { ApiError } = await import('@/api/client')

function row(overrides: Partial<ArControlRow> = {}): ArControlRow {
  return {
    account_id: 'acc-1',
    code: '1130',
    name: 'Trade receivables',
    subledger: '1000.0000',
    general_ledger: '1000.0000',
    difference: '0.0000',
    reconciles: true,
    ...overrides,
  }
}

function meta(overrides: Partial<ArControlMeta> = {}): ArControlMeta {
  return {
    currency: 'LKR',
    as_of: '2026-08-18',
    totals: {
      subledger: '1000.0000',
      general_ledger: '1000.0000',
      difference: '0.0000',
      reconciles: true,
      ...overrides.totals,
    },
    ...overrides,
  }
}

function signIn(): void {
  useAuthStore().$patch({
    initialised: true,
    companies: [
      {
        id: 'company-1',
        name: 'Demo Trading',
        code: 'DTL',
        base_currency_code: 'LKR',
        currency_precision: 2,
        timezone: 'Asia/Colombo',
        is_default: true,
      },
    ],
  } as never)
}

async function mountPage() {
  const wrapper = mount(ArControlPage)
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
})

describe('ArControlPage', () => {
  it('requests the report once, with no query parameters', async () => {
    signIn()
    get.mockResolvedValue({ data: [], meta: meta() })

    await mountPage()

    // Exactly one argument. Sending an `as_of` would imply a historical capability the
    // reconciliation does not have — its subledger side reads current state only.
    expect(get).toHaveBeenCalledTimes(1)
    expect(get).toHaveBeenCalledWith('/companies/company-1/reports/ar-control')
    expect(get.mock.calls[0]).toHaveLength(1)
  })

  it('names the account by code and title', async () => {
    signIn()
    get.mockResolvedValue({ data: [row()], meta: meta() })

    const wrapper = await mountPage()

    expect(wrapper.find('tbody tr').text()).toContain('1130')
    expect(wrapper.find('tbody tr').text()).toContain('Trade receivables')
  })

  it('renders both sides through the shared money formatter', async () => {
    signIn()
    get.mockResolvedValue({
      data: [row({ subledger: '1234567.5000', general_ledger: '1234567.5000' })],
      meta: meta(),
    })

    const wrapper = await mountPage()
    const cells = wrapper.findAll('tbody td').map((td) => td.text())

    // The company's precision, not the wire's four places.
    expect(cells[1]).toBe('1,234,567.50')
    expect(cells[2]).toBe('1,234,567.50')
  })

  it('keeps a negative difference negative', async () => {
    signIn()
    get.mockResolvedValue({
      data: [
        row({
          subledger: '1000.0000',
          general_ledger: '750.0000',
          difference: '-250.0000',
          reconciles: false,
        }),
      ],
      meta: meta({ totals: { subledger: '1000.0000', general_ledger: '750.0000', difference: '-250.0000', reconciles: false } }),
    })

    const wrapper = await mountPage()
    const difference = wrapper.findAll('tbody td')[3]?.text()

    // The sign says which side is short. `Math.abs` here would discard the only clue about what went
    // wrong while leaving the row looking answered.
    expect(difference).toBe('-250.00')
    expect(difference).not.toBe('250.00')
  })

  it('does not blank a zero difference', async () => {
    signIn()
    get.mockResolvedValue({ data: [row({ difference: '0.0000' })], meta: meta() })

    const wrapper = await mountPage()

    // Blanking zeroes is right on a trial balance, where an accountant scans for the side carrying a
    // figure. Here a zero is the answer, and an empty cell reads as missing data.
    expect(wrapper.findAll('tbody td')[3]?.text()).toBe('0.00')
  })

  it('states the row verdict in words when an account agrees', async () => {
    signIn()
    get.mockResolvedValue({ data: [row({ reconciles: true })], meta: meta() })

    const wrapper = await mountPage()

    expect(wrapper.findAll('tbody td')[4]?.text()).toBe('Reconciles')
  })

  it('states the row verdict in words when an account does not agree', async () => {
    signIn()
    get.mockResolvedValue({
      data: [row({ difference: '250.0000', reconciles: false })],
      meta: meta({ totals: { subledger: '1000.0000', general_ledger: '1250.0000', difference: '250.0000', reconciles: false } }),
    })

    const wrapper = await mountPage()

    // In words, so the state survives a colour-blind reader, a greyscale print and a screenshot.
    expect(wrapper.findAll('tbody td')[4]?.text()).toBe('Does not reconcile')
  })

  it('raises an alert when the books do not reconcile', async () => {
    signIn()
    get.mockResolvedValue({
      data: [row({ difference: '250.0000', reconciles: false })],
      meta: meta({ totals: { subledger: '1000.0000', general_ledger: '1250.0000', difference: '250.0000', reconciles: false } }),
    })

    const wrapper = await mountPage()
    const alert = wrapper.find('[role="alert"]')

    expect(alert.exists()).toBe(true)
    expect(alert.text()).toContain('Receivables do not reconcile')
    expect(alert.text()).toContain(
      'The invoice subledger and the general ledger disagree for at least one receivable account.',
    )
    expect(alert.text()).toContain('Investigate before relying on any receivables figure.')
  })

  it('raises no alert when every account agrees', async () => {
    signIn()
    get.mockResolvedValue({ data: [row()], meta: meta() })

    const wrapper = await mountPage()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.find('tfoot').text()).toContain('All accounts reconcile')
  })

  it('takes the page verdict from the server rather than from the total difference', async () => {
    signIn()
    // Two opposing errors of equal size: the grand difference nets to zero while both accounts are
    // wrong. A page inferring the verdict from that figure would report a clean reconciliation.
    get.mockResolvedValue({
      data: [
        row({ account_id: 'acc-1', code: '1130', difference: '250.0000', reconciles: false }),
        row({ account_id: 'acc-2', code: '1140', name: 'Other receivables', difference: '-250.0000', reconciles: false }),
      ],
      meta: meta({ totals: { subledger: '2000.0000', general_ledger: '2000.0000', difference: '0.0000', reconciles: false } }),
    })

    const wrapper = await mountPage()

    expect(wrapper.find('tfoot').text()).toContain('0.00')
    expect(wrapper.find('tfoot').text()).toContain('Does not reconcile')
    expect(wrapper.find('tfoot').text()).not.toContain('All accounts reconcile')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })

  it('renders the server’s totals rather than summing the rows', async () => {
    signIn()
    get.mockResolvedValue({
      data: [row({ subledger: '1000.0000', general_ledger: '1000.0000' }), row({ account_id: 'acc-2', subledger: '1000.0000', general_ledger: '1000.0000' })],
      // Deliberately not 2,000: a page adding the columns up would render that and fail here.
      meta: meta({ totals: { subledger: '1750.2500', general_ledger: '1750.2500', difference: '0.0000', reconciles: true } }),
    })

    const wrapper = await mountPage()
    const footer = wrapper.find('tfoot').text()

    expect(footer).toContain('1,750.25')
    expect(footer).not.toContain('2,000.00')
  })

  it('shows the day the server produced the report', async () => {
    signIn()
    get.mockResolvedValue({ data: [row()], meta: meta({ as_of: '2026-08-18' }) })

    const wrapper = await mountPage()

    // Shown as text, never as a control: there is no as-at parameter to change.
    expect(wrapper.text()).toContain('As produced on 2026-08-18')
    expect(wrapper.find('input[type="date"]').exists()).toBe(false)
  })

  it('reports a company with no receivable activity as exactly that', async () => {
    signIn()
    get.mockResolvedValue({
      data: [],
      meta: meta({ totals: { subledger: '0.0000', general_ledger: '0.0000', difference: '0.0000', reconciles: true } }),
    })

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('This company has no receivable account activity yet.')
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('surfaces a refusal without claiming the books reconcile', async () => {
    signIn()
    get.mockRejectedValue(
      new ApiError(
        {
          type: 'https://docs.asidstech.com/errors/forbidden',
          title: 'Permission denied.',
          status: 403,
          detail: 'Your account does not have permission to perform this action.',
        },
        403,
      ),
    )

    const wrapper = await mountPage()

    expect(useUiStore().notices[0]).toMatchObject({
      kind: 'error',
      message: 'Your account does not have permission to perform this action.',
    })

    // The distinction that matters on this report above all others: a failure is not a clean set of
    // books, and must not read as one.
    expect(wrapper.text()).not.toContain('This company has no receivable account activity yet.')
    expect(wrapper.text()).not.toContain('All accounts reconcile')
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('falls back to its own wording when the failure carries no problem document', async () => {
    signIn()
    get.mockRejectedValue(new Error('socket hang up'))

    await mountPage()

    expect(useUiStore().notices[0]).toMatchObject({
      kind: 'error',
      message: 'Could not load the AR control report.',
    })
  })

  it('reloads for the new company when the active one changes', async () => {
    signIn()
    get.mockResolvedValue({ data: [row()], meta: meta() })
    await mountPage()

    expect(get).toHaveBeenCalledTimes(1)

    useAuthStore().$patch({
      companies: [
        {
          id: 'company-2',
          name: 'Second Books',
          code: 'SEC',
          base_currency_code: 'LKR',
          currency_precision: 2,
          timezone: 'Asia/Colombo',
          is_default: true,
        },
      ],
    } as never)
    await flushPromises()

    // A reconciliation attributed to the wrong set of books is worse than none.
    expect(get).toHaveBeenCalledTimes(2)
    expect(get).toHaveBeenLastCalledWith('/companies/company-2/reports/ar-control')
  })

  it('does not call the API when no company is active', async () => {
    await mountPage()

    expect(get).not.toHaveBeenCalled()
  })
})
