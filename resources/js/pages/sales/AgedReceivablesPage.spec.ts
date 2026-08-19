import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import type * as ApiClientModule from '@/api/client'
import type { AgedReceivableMeta, AgedReceivableRow } from '@/types/domain'

/**
 * The aged receivables report.
 *
 * Narrow on purpose, like the outstanding balance spec: `pages/**` sits at a zero coverage floor
 * because mounting a screen to watch a table render tests Vue rather than this application. What is
 * asserted here is the set of things that would put a *wrong number* or a *wrong date* in front of an
 * accountant, none of which any other layer would catch.
 *
 * The cutoff carries most of the weight. It must never be the browser's idea of today — a blank
 * control means "server, you choose", and the date that comes back is what the figures were actually
 * aged against, so the control has to end up showing that rather than whatever was typed. The
 * fixtures below therefore give `meta.as_of` a value the test never typed, so a page that echoed its
 * own input instead of the server's answer fails here.
 *
 * The totals carry the rest: `meta.totals` is deliberately inconsistent with the rows, so a page that
 * ever starts summing eight columns of money in JavaScript fails here rather than in production.
 */

const get = vi.fn()

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof ApiClientModule>('@/api/client')

  return { ...actual, api: { get, post: vi.fn(), setActiveCompany: vi.fn(), configure: vi.fn() } }
})

const AgedReceivablesPage = (await import('@/pages/sales/AgedReceivablesPage.vue')).default
const { useAuthStore } = await import('@/stores/auth')
const { useUiStore } = await import('@/stores/ui')
const { ApiError } = await import('@/api/client')

function row(overrides: Partial<AgedReceivableRow> = {}): AgedReceivableRow {
  return {
    customer_id: 'cus-1',
    code: 'SILVA',
    name: 'Silva Traders',
    not_yet_due: '0.0000',
    days_0_30: '1000.0000',
    days_31_60: '0.0000',
    days_61_90: '0.0000',
    days_over_90: '250.5000',
    total: '1250.5000',
    ...overrides,
  }
}

function meta(overrides: Partial<AgedReceivableMeta> = {}): AgedReceivableMeta {
  return {
    currency: 'LKR',
    as_of: '2026-08-18',
    totals: {
      not_yet_due: '0.0000',
      days_0_30: '1000.0000',
      days_31_60: '0.0000',
      days_61_90: '0.0000',
      days_over_90: '250.5000',
      total: '1250.5000',
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
  const wrapper = mount(AgedReceivablesPage)
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
})

describe('AgedReceivablesPage', () => {
  it('omits as_of on first load so the server chooses the cutoff', async () => {
    signIn()
    get.mockResolvedValue({ data: [], meta: meta() })

    await mountPage()

    // `undefined`, not an empty string: an empty string reaches the API as `as_of=` and is refused as
    // not-a-date, and the browser's clock must never decide the cutoff.
    expect(get).toHaveBeenCalledTimes(1)
    expect(get).toHaveBeenCalledWith('/companies/company-1/reports/aged-receivables', {
      as_of: undefined,
    })
  })

  it('repopulates the date control from the cutoff the server actually used', async () => {
    signIn()
    get.mockResolvedValue({ data: [row()], meta: meta({ as_of: '2026-08-18' }) })

    const wrapper = await mountPage()

    // Nothing typed that date. It came back from the server, and the control must show it — otherwise
    // the figures are aged against one date and the screen claims another.
    expect((wrapper.find('#aged-as-of').element as HTMLInputElement).value).toBe('2026-08-18')
  })

  it('sends a chosen cutoff and shows the one the server confirms', async () => {
    signIn()
    get.mockResolvedValue({ data: [row()], meta: meta() })
    const wrapper = await mountPage()

    get.mockResolvedValue({ data: [row()], meta: meta({ as_of: '2026-09-30' }) })
    await wrapper.find('#aged-as-of').setValue('2026-09-30')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(get).toHaveBeenLastCalledWith('/companies/company-1/reports/aged-receivables', {
      as_of: '2026-09-30',
    })
    expect(wrapper.text()).toContain('Aged as at 2026-09-30')
  })

  it('displays the resolved cutoff', async () => {
    signIn()
    get.mockResolvedValue({ data: [row()], meta: meta({ as_of: '2026-08-18' }) })

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('Aged as at 2026-08-18')
  })

  it('states that ageing runs from the due date', async () => {
    signIn()
    get.mockResolvedValue({ data: [row()], meta: meta() })

    const wrapper = await mountPage()

    // A reader who assumes invoice-date ageing misreads every column — for thirty-day terms, by a
    // month. The basis has to be on the page, not only in the API docs.
    expect(wrapper.find('header').text()).toContain('due date')
  })

  it('renders a row per customer with all five buckets and a total', async () => {
    signIn()
    get.mockResolvedValue({ data: [row()], meta: meta() })

    const wrapper = await mountPage()
    const cells = wrapper.findAll('tbody tr td').map((td) => td.text())

    // Formatted at the company's precision, not the wire's four decimal places, and in bucket order.
    expect(cells).toEqual([
      'SILVA',
      'Silva Traders',
      '0.00',
      '1,000.00',
      '0.00',
      '0.00',
      '250.50',
      '1,250.50',
    ])
  })

  it('labels the bucket columns in ageing order', async () => {
    signIn()
    get.mockResolvedValue({ data: [row()], meta: meta() })

    const wrapper = await mountPage()
    const headers = wrapper.findAll('thead th').map((th) => th.text())

    expect(headers).toEqual([
      'Code',
      'Customer',
      'Not yet due',
      '0–30',
      '31–60',
      '61–90',
      '90+',
      'Total',
    ])
  })

  it('renders the server’s column totals rather than summing the rows', async () => {
    signIn()
    get.mockResolvedValue({
      data: [row({ days_0_30: '1000.0000', total: '1000.0000' }), row({ customer_id: 'cus-2', days_0_30: '1000.0000', total: '1000.0000' })],
      // Deliberately not 2,000: a page that added the column up would render that and fail here.
      meta: meta({
        totals: {
          not_yet_due: '0.0000',
          days_0_30: '1750.2500',
          days_31_60: '0.0000',
          days_61_90: '0.0000',
          days_over_90: '0.0000',
          total: '1750.2500',
        },
      }),
    })

    const wrapper = await mountPage()
    const footer = wrapper.find('tfoot').text()

    expect(footer).toContain('1,750.25')
    expect(footer).not.toContain('2,000.00')
  })

  it('reports an empty book against the date it was aged at', async () => {
    signIn()
    get.mockResolvedValue({
      data: [],
      meta: meta({
        as_of: '2026-08-18',
        totals: {
          not_yet_due: '0.0000',
          days_0_30: '0.0000',
          days_31_60: '0.0000',
          days_61_90: '0.0000',
          days_over_90: '0.0000',
          total: '0.0000',
        },
      }),
    })

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('Nothing is outstanding as at 2026-08-18.')
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('surfaces a refusal without claiming that nothing is outstanding', async () => {
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

    // The distinction that matters: a failure is not an empty book. Reporting "nothing is
    // outstanding" over a refusal would tell an accountant their debtors had all paid.
    expect(wrapper.text()).not.toContain('Nothing is outstanding')
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('falls back to its own wording when the failure carries no problem document', async () => {
    signIn()
    get.mockRejectedValue(new Error('socket hang up'))

    await mountPage()

    expect(useUiStore().notices[0]).toMatchObject({
      kind: 'error',
      message: 'Could not load aged receivables.',
    })
  })

  it('reloads for the new company when the active one changes, keeping the cutoff', async () => {
    signIn()
    get.mockResolvedValue({ data: [row()], meta: meta({ as_of: '2026-08-18' }) })
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

    // Same date, the other company — which is what someone comparing two sets of books is asking for.
    expect(get).toHaveBeenCalledTimes(2)
    expect(get).toHaveBeenLastCalledWith('/companies/company-2/reports/aged-receivables', {
      as_of: '2026-08-18',
    })
  })

  it('does not call the API when no company is active', async () => {
    await mountPage()

    expect(get).not.toHaveBeenCalled()
  })
})
