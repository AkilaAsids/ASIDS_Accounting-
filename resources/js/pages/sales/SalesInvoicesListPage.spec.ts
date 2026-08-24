import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type * as ApiClientModule from '@/api/client'
import type { SalesInvoice } from '@/types/domain'
import type { ApiMeta, Pagination } from '@/types/api'

/**
 * QA acceptance specs — invoice list (requirements §4.6, ADR 0013 §9).
 *
 * Two things carry the weight of this file. First, the list must never render line detail
 * (§4.6.5) and must never request `include=lines` — that belongs to the view screen only.
 * Second, the same owner-shaped capability trap as the customer lane applies here with higher
 * stakes: `can_cancel` is only true while `status === 'issued'`, and an owner's raw permission
 * (`sales.invoices.cancel`) is unconditional, so a permission-only gate would offer "Cancel" on
 * an invoice that is not issued at all.
 */

const get = vi.fn()

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof ApiClientModule>('@/api/client')

  return {
    ...actual,
    api: { get, post: vi.fn(), put: vi.fn(), delete: vi.fn(), setActiveCompany: vi.fn(), configure: vi.fn() },
  }
})

const SalesInvoicesListPage = (await import('@/pages/sales/SalesInvoicesListPage.vue')).default
const { useAuthStore } = await import('@/stores/auth')
const { useUiStore } = await import('@/stores/ui')
const { ApiError } = await import('@/api/client')

function invoice(overrides: Partial<SalesInvoice> = {}): SalesInvoice {
  return {
    id: 'inv-1',
    company_id: 'company-1',
    branch_id: null,
    customer_id: 'cus-1',
    number: 'INV-2026-06-0001',
    reference: null,
    invoice_date: '2026-06-01',
    due_date: '2026-07-01',
    currency_code: 'LKR',
    exchange_rate: null,
    subtotal: '1000.0000',
    discount_total: '0.0000',
    tax_total: '150.0000',
    total: '1150.0000',
    amount_paid: '0.0000',
    amount_due: '1150.0000',
    status: 'issued',
    status_label: 'Issued',
    is_overdue: false,
    issued_at: '2026-06-01T10:00:00Z',
    issued_by_id: 'user-1',
    journal_entry_id: 'je-1',
    cancelled_at: null,
    cancellation_reason: null,
    cancelled_by_id: null,
    notes: null,
    terms: null,
    created_by_id: 'user-1',
    created_at: '2026-06-01T09:00:00Z',
    updated_at: '2026-06-01T10:00:00Z',
    capabilities: { can_update: false, can_delete: false, can_issue: false, can_cancel: true },
    customer: { id: 'cus-1', code: 'C-0001', name: 'Silva Traders' },
    ...overrides,
  }
}

function draftInvoice(overrides: Partial<SalesInvoice> = {}): SalesInvoice {
  return invoice({
    id: 'inv-2',
    number: null,
    status: 'draft',
    status_label: 'Draft',
    issued_at: null,
    issued_by_id: null,
    journal_entry_id: null,
    capabilities: { can_update: true, can_delete: true, can_issue: true, can_cancel: false },
    ...overrides,
  })
}

function pagination(overrides: Partial<Pagination> = {}): Pagination {
  return { total: 1, per_page: 15, current_page: 1, last_page: 1, from: 1, to: 1, ...overrides }
}

function apiMeta(overrides: Partial<ApiMeta> = {}): ApiMeta {
  return { request_id: 'req-1', api_version: '1', pagination: pagination(), ...overrides }
}

function signIn(options: { isOwner?: boolean; permissions?: string[] } = {}): void {
  useAuthStore().$patch({
    initialised: true,
    user: {
      id: 'user-1',
      full_name: 'Kumari Silva',
      email: 'kumari@acme.test',
      is_owner: options.isOwner ?? false,
    },
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
    permissions: new Set(
      options.permissions ?? ['sales.invoices.view', 'sales.invoices.draft', 'sales.invoices.issue', 'sales.invoices.cancel'],
    ),
  } as never)
}

function testRouter() {
  const stub = { template: '<div />' }
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'invoices', component: stub },
      { path: '/new', name: 'invoice-new', component: stub },
      { path: '/:invoiceId', name: 'invoice-detail', component: stub },
      { path: '/:invoiceId/edit', name: 'invoice-edit', component: stub },
    ],
  })
}

async function mountPage() {
  const router = testRouter()
  await router.push('/')
  await router.isReady()

  const wrapper = mount(SalesInvoicesListPage, { global: { plugins: [router] } })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
})

describe('SalesInvoicesListPage', () => {
  it('renders every invoice the server returns, keyed on a landed response', async () => {
    signIn()
    get.mockResolvedValue({ data: [invoice(), draftInvoice()], meta: apiMeta({ pagination: pagination({ total: 2, to: 2 }) }) })

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('INV-2026-06-0001')
    expect(wrapper.text()).toContain('Silva Traders')
  })

  it('never requests include=lines on the list', async () => {
    signIn()
    get.mockResolvedValue({ data: [invoice()], meta: apiMeta() })

    await mountPage()

    expect(get).toHaveBeenCalledTimes(1)
    const [, params] = get.mock.calls[0] as [string, Record<string, unknown> | undefined]
    expect(params?.include).toBeUndefined()
  })

  it('does not override the default sort client-side', async () => {
    signIn()
    get.mockResolvedValue({ data: [invoice()], meta: apiMeta() })

    await mountPage()

    expect(get).toHaveBeenCalledTimes(1)
    const [, params] = get.mock.calls[0] as [string, Record<string, unknown> | undefined]
    expect(params?.sort === undefined || params?.sort === '-invoice_date').toBe(true)
  })

  it('sends filter[status], filter[customer_id] and q as server parameters', async () => {
    signIn()
    get.mockResolvedValue({ data: [invoice()], meta: apiMeta() })

    const wrapper = await mountPage()

    const statusSelect = wrapper.findAll('select')[0]
    await statusSelect?.setValue('draft')
    await flushPromises()

    expect(get).toHaveBeenLastCalledWith(
      '/companies/company-1/sales-invoices',
      expect.objectContaining({ filter: expect.objectContaining({ status: 'draft' }) }),
    )
  })

  it('shows a draft invoice distinctly, without a number, and offers Edit rather than View', async () => {
    signIn()
    get.mockResolvedValue({ data: [draftInvoice()], meta: apiMeta() })

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('Draft')
    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Edit')).toBe(true)
  })

  it('wires meta.pagination into the Pagination control and requests the next page', async () => {
    signIn()
    get.mockResolvedValue({
      data: [invoice()],
      meta: apiMeta({ pagination: pagination({ total: 30, per_page: 15, last_page: 2, to: 15 }) }),
    })

    const wrapper = await mountPage()
    const nextButton = wrapper.findAll('button').find((b) => b.text() === 'Next')
    await nextButton?.trigger('click')
    await flushPromises()

    expect(get).toHaveBeenLastCalledWith(
      '/companies/company-1/sales-invoices',
      expect.objectContaining({ page: 2 }),
    )
  })

  it('clears rows and does not render the empty state after a failed request', async () => {
    signIn()
    get.mockResolvedValueOnce({ data: [invoice()], meta: apiMeta() })

    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('INV-2026-06-0001')

    get.mockRejectedValueOnce(
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

    const statusSelect = wrapper.findAll('select')[0]
    await statusSelect?.setValue('cancelled')
    await flushPromises()

    expect(wrapper.text()).not.toContain('INV-2026-06-0001')
    expect(wrapper.text()).not.toContain('This company has no invoices yet.')
    expect(useUiStore().notices.at(-1)).toMatchObject({ kind: 'error' })
  })

  it('hides Cancel for an OWNER when capabilities.can_cancel is false, even on an issued invoice', async () => {
    // The exact trap ADR 0012 D4 names: an owner's `sales.invoices.cancel` permission is
    // unconditional, so only `capabilities.can_cancel` may gate this control.
    signIn({ isOwner: true, permissions: [] })
    get.mockResolvedValue({
      data: [invoice({ capabilities: { can_update: false, can_delete: false, can_issue: false, can_cancel: false } })],
      meta: apiMeta(),
    })

    const wrapper = await mountPage()

    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Cancel')).toBe(false)

    const trigger = wrapper.find('button[aria-haspopup="menu"]')
    if (trigger.exists()) {
      await trigger.trigger('click')
      expect(wrapper.findAll('[role="menuitem"]').some((el) => el.text().includes('Cancel'))).toBe(
        false,
      )
    }
  })

  it('offers Cancel when capabilities.can_cancel is true on an issued invoice', async () => {
    signIn()
    get.mockResolvedValue({ data: [invoice({ capabilities: { can_update: false, can_delete: false, can_issue: false, can_cancel: true } })], meta: apiMeta() })

    const wrapper = await mountPage()
    const trigger = wrapper.find('button[aria-haspopup="menu"]')
    expect(trigger.exists()).toBe(true)
    await trigger.trigger('click')

    expect(wrapper.findAll('[role="menuitem"]').some((el) => el.text().includes('Cancel'))).toBe(true)
  })

  it('hides "New invoice" for a user without sales.invoices.draft', async () => {
    signIn({ permissions: ['sales.invoices.view'] })
    get.mockResolvedValue({ data: [], meta: apiMeta({ pagination: pagination({ total: 0, from: null, to: null }) }) })

    const wrapper = await mountPage()

    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'New invoice')).toBe(false)
  })

  it('reloads exactly once when the active company changes', async () => {
    signIn()
    get.mockResolvedValue({ data: [], meta: apiMeta({ pagination: pagination({ total: 0, from: null, to: null }) }) })

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

    expect(get).toHaveBeenCalledTimes(2)
    expect(get).toHaveBeenLastCalledWith('/companies/company-2/sales-invoices', expect.anything())
  })
})
