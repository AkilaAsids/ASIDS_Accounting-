import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type * as ApiClientModule from '@/api/client'
import type { OutstandingReceivableRow } from '@/types/domain'

/**
 * The outstanding receivables report.
 *
 * The first page-level spec in this codebase, and deliberately narrow: the coverage thresholds put
 * `pages/**` at zero because mounting a screen to assert that a table renders a row tests Vue rather
 * than this application, and that reasoning still holds. What is worth asserting is the handful of
 * things this page could get wrong in a way no other layer would catch — and every one of them is a
 * wrong *number* in front of an accountant rather than a broken render.
 *
 *   - The footer total must come from `meta.totals.outstanding`. A client that summed the column
 *     would disagree with the ledger by a few cents and the customer would have two figures with no
 *     way to choose between them. The fixture below makes those two values deliberately different,
 *     so a page that summed locally fails here instead of in production.
 *   - A failed request must not leave the previous figures on screen. A stale table above an error
 *     notice reads as current.
 *   - One request per mount. The report is three aggregate queries server-side; a page that fired
 *     twice would double that for nothing.
 *
 * The API client is mocked at the module boundary, following `stores/auth.spec.ts`.
 */

const get = vi.fn()

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof ApiClientModule>('@/api/client')

  return { ...actual, api: { get, post: vi.fn(), setActiveCompany: vi.fn(), configure: vi.fn() } }
})

const OutstandingReceivablesPage = (await import('@/pages/sales/OutstandingReceivablesPage.vue'))
  .default
const { useAuthStore } = await import('@/stores/auth')
const { useUiStore } = await import('@/stores/ui')
const { ApiError } = await import('@/api/client')

function row(overrides: Partial<OutstandingReceivableRow> = {}): OutstandingReceivableRow {
  return {
    customer_id: 'cus-1',
    code: 'SILVA',
    name: 'Silva Traders',
    invoice_count: 2,
    outstanding: '1250.5000',
    ...overrides,
  }
}

/**
 * A company must be active for the page to call anything at all, and `useMoney` reads its currency
 * and precision — so without one the amounts would format at a default that is not this company's.
 */
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
  const wrapper = mount(OutstandingReceivablesPage)
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
})

describe('OutstandingReceivablesPage', () => {
  it('requests the report once, for the active company', async () => {
    signIn()
    get.mockResolvedValue({ data: [], meta: { currency: 'LKR', as_of: '2026-08-18', totals: { outstanding: '0.0000' } } })

    await mountPage()

    expect(get).toHaveBeenCalledTimes(1)
    expect(get).toHaveBeenCalledWith('/companies/company-1/reports/outstanding-receivables')
  })

  it('renders a row per customer with its invoice count and formatted balance', async () => {
    signIn()
    get.mockResolvedValue({
      data: [row(), row({ customer_id: 'cus-2', code: 'PERERA', name: 'Perera & Sons', invoice_count: 1, outstanding: '400.0000' })],
      meta: { currency: 'LKR', as_of: '2026-08-18', totals: { outstanding: '1650.5000' } },
    })

    const wrapper = await mountPage()
    const cells = wrapper.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))

    // Formatted at the company's precision, not the wire's four decimal places.
    expect(cells[0]).toEqual(['SILVA', 'Silva Traders', '2', '1,250.50'])
    expect(cells[1]).toEqual(['PERERA', 'Perera & Sons', '1', '400.00'])
  })

  it('keeps the server’s ordering rather than sorting the rows itself', async () => {
    signIn()
    get.mockResolvedValue({
      data: [
        row({ customer_id: 'a', code: 'ZULU', outstanding: '5000.0000' }),
        row({ customer_id: 'b', code: 'ALPHA', outstanding: '100.0000' }),
      ],
      meta: { currency: 'LKR', as_of: '2026-08-18', totals: { outstanding: '5100.0000' } },
    })

    const wrapper = await mountPage()

    // Largest debt first is the service's ordering. Sorting by code here would put ALPHA on top and
    // silently change what the report means.
    expect(wrapper.findAll('tbody tr').map((tr) => tr.find('td').text())).toEqual(['ZULU', 'ALPHA'])
  })

  it('shows the server’s total rather than a sum of the rows', async () => {
    signIn()
    get.mockResolvedValue({
      data: [row({ outstanding: '1000.0000' }), row({ customer_id: 'cus-2', code: 'PERERA', outstanding: '1000.0000' })],
      // Deliberately not 2000: a page that added the column up would render 2,000.00 and fail here.
      meta: { currency: 'LKR', as_of: '2026-08-18', totals: { outstanding: '1750.2500' } },
    })

    const wrapper = await mountPage()

    expect(wrapper.find('tfoot').text()).toContain('1,750.25')
    expect(wrapper.find('tfoot').text()).not.toContain('2,000.00')
  })

  it('states the currency and the day the figures were read', async () => {
    signIn()
    get.mockResolvedValue({
      data: [row()],
      meta: { currency: 'LKR', as_of: '2026-08-18', totals: { outstanding: '1250.5000' } },
    })

    const wrapper = await mountPage()

    // A printed copy has to carry both, since the report offers no as-at control to infer them from.
    expect(wrapper.find('header').text()).toContain('LKR')
    expect(wrapper.find('header').text()).toContain('2026-08-18')
  })

  it('reads an empty report as everyone having paid', async () => {
    signIn()
    get.mockResolvedValue({ data: [], meta: { currency: 'LKR', as_of: '2026-08-18', totals: { outstanding: '0.0000' } } })

    const wrapper = await mountPage()

    // Customers with nothing outstanding are excluded by the report, so an empty result is good news
    // rather than a missing-data message.
    expect(wrapper.text()).toContain('No customer has an outstanding balance.')
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('surfaces a refusal in the notice stack and renders no figures', async () => {
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

    // The server's own wording, not a rewritten one. `problem.detail` is what names the actual
    // refusal, and an invented message would be less use to whoever has to act on it.
    expect(useUiStore().notices).toHaveLength(1)
    expect(useUiStore().notices[0]).toMatchObject({
      kind: 'error',
      message: 'Your account does not have permission to perform this action.',
    })

    // No table, and no total — a failed report must not leave a footer implying a figure of zero.
    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.find('tfoot').exists()).toBe(false)

    // And, above all, not the success wording. Keyed on the row count alone this page said "No
    // customer has an outstanding balance." over a refusal, telling an accountant their debtors had
    // all paid. The empty state now speaks only for a request that succeeded.
    expect(wrapper.text()).not.toContain('No customer has an outstanding balance.')
  })

  it('falls back to its own wording when the failure carries no problem document', async () => {
    signIn()
    // A network failure, a proxy returning HTML, a hard timeout: `ApiError` never arrives and there
    // is no `problem.detail` to show.
    get.mockRejectedValue(new Error('socket hang up'))

    const wrapper = await mountPage()

    expect(useUiStore().notices[0]).toMatchObject({
      kind: 'error',
      message: 'Could not load outstanding receivables.',
    })

    // The same distinction as above, for the failure path that carries no problem document.
    expect(wrapper.text()).not.toContain('No customer has an outstanding balance.')
  })

  it('lets a keyboard user reach the table’s horizontal scroll', async () => {
    signIn()
    get.mockResolvedValue({
      data: [row()],
      meta: { currency: 'LKR', as_of: '2026-08-18', totals: { outstanding: '1250.5000' } },
    })

    const wrapper = await mountPage()
    const region = wrapper.find('[role="region"]')

    // A plain overflow container holds no tab stop, so columns past the right edge are rendered but
    // not operable without a mouse. The region needs a name too, or the stop is unexplained.
    expect(region.exists()).toBe(true)
    expect(region.attributes('tabindex')).toBe('0')
    expect(region.attributes('aria-label')).toBe('Outstanding receivables')
  })

  it('reloads for the new company when the active one changes', async () => {
    signIn()
    get.mockResolvedValue({ data: [], meta: { currency: 'LKR', as_of: '2026-08-18', totals: { outstanding: '0.0000' } } })

    await mountPage()
    expect(get).toHaveBeenCalledTimes(1)

    // Switching company refreshes the session in place; nothing re-mounts the page. Left unwatched,
    // the table would keep the first company's debtors under the second company's name.
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

    expect(get).toHaveBeenCalledTimes(2)
    expect(get).toHaveBeenLastCalledWith('/companies/company-2/reports/outstanding-receivables')
  })

  it('does not call the API when no company is active', async () => {
    // A user invited but not yet granted access to any company. Calling with a null id would build
    // `/companies/null/...` and produce a 404 the user can do nothing about.
    await mountPage()

    expect(get).not.toHaveBeenCalled()
  })
})
