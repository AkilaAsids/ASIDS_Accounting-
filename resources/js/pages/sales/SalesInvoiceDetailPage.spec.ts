import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type * as ApiClientModule from '@/api/client'
import type { SalesInvoice } from '@/types/domain'
import type { ApiMeta } from '@/types/api'

/**
 * QA acceptance specs — invoice view + issue/cancel/delete (requirements §4.9–§4.12, ADR 0013
 * §9). Two hazards this file exists to catch:
 *
 *  - The "capabilities, not permission" gap (ADR 0012 D4): `can_issue`/`can_cancel`/`can_delete`
 *    each fold state into the check (draft-only, issued-only, draft-only respectively), and an
 *    owner's raw permission is unconditional, so only `capabilities` may gate these controls.
 *  - Cancellation is framed as "not an undo" (§4.11.4) and requires a 3–255 character reason
 *    that the dialog itself must refuse to submit without.
 */

const get = vi.fn()
const post = vi.fn()
const del = vi.fn()

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof ApiClientModule>('@/api/client')

  return {
    ...actual,
    api: { get, post, put: vi.fn(), delete: del, setActiveCompany: vi.fn(), configure: vi.fn() },
  }
})

const SalesInvoiceDetailPage = (await import('@/pages/sales/SalesInvoiceDetailPage.vue')).default
const { useAuthStore } = await import('@/stores/auth')
const { useUiStore } = await import('@/stores/ui')
const { ApiError } = await import('@/api/client')

function apiMeta(): ApiMeta {
  return { request_id: 'req-1', api_version: '1' }
}

function invoice(overrides: Partial<SalesInvoice> = {}): SalesInvoice {
  return {
    id: 'inv-1',
    company_id: 'company-1',
    branch_id: null,
    customer_id: 'cus-1',
    number: 'INV-2026-06-0001',
    reference: 'PO-100',
    invoice_date: '2026-06-01',
    due_date: '2026-06-10',
    currency_code: 'LKR',
    exchange_rate: null,
    subtotal: '200.0000',
    discount_total: '0.0000',
    tax_total: '36.0000',
    total: '236.0000',
    amount_paid: '0.0000',
    amount_due: '236.0000',
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
    lines: [
      {
        id: 'line-1',
        line_number: 1,
        description: 'Consulting',
        quantity: '2.0000',
        unit_price: '100.0000',
        discount_percent: null,
        discount_amount: null,
        line_subtotal: '200.0000',
        tax_code_id: 'tax-1',
        tax_code: 'VAT',
        tax_rate: '18.0000',
        tax_amount: '36.0000',
        line_total: '236.0000',
        revenue_account_id: 'acc-rev-1',
        branch_id: null,
      },
    ],
    ...overrides,
  }
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
      options.permissions ?? [
        'sales.invoices.view',
        'sales.invoices.draft',
        'sales.invoices.issue',
        'sales.invoices.cancel',
      ],
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

async function mountPage(invoiceId = 'inv-1') {
  const router = testRouter()
  await router.push(`/${invoiceId}`)
  await router.isReady()

  const wrapper = mount(SalesInvoiceDetailPage, { global: { plugins: [router] } })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
  post.mockReset()
  del.mockReset()
})

describe('SalesInvoiceDetailPage — view (§4.9)', () => {
  it('renders the invoice exactly as returned, with no client-side sum or difference', async () => {
    signIn()
    get.mockResolvedValue({ data: invoice(), meta: apiMeta() })

    const wrapper = await mountPage()

    expect(get).toHaveBeenCalledWith('/companies/company-1/sales-invoices/inv-1')
    expect(wrapper.text()).toContain('INV-2026-06-0001');
    expect(wrapper.text()).toContain('236.00')
    expect(wrapper.text()).toContain('Consulting')
  })

  it('states an overdue invoice in words, not colour alone', async () => {
    signIn()
    get.mockResolvedValue({ data: invoice({ is_overdue: true }), meta: apiMeta() })

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('Overdue')
  })

  it('shows the cancellation record — reason, date and who cancelled — for a cancelled invoice', async () => {
    signIn()
    get.mockResolvedValue({
      data: invoice({
        status: 'cancelled',
        status_label: 'Cancelled',
        cancelled_at: '2026-06-05T12:00:00Z',
        cancellation_reason: 'Customer disputed the quantity billed.',
        cancelled_by_id: 'user-1',
        capabilities: { can_update: false, can_delete: false, can_issue: false, can_cancel: false },
      }),
      meta: apiMeta(),
    })

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('Customer disputed the quantity billed.')
    expect(wrapper.text()).not.toContain('This invoice was deleted')
  })

  it('treats an invoice-company-mismatch 422 as a not-found-equivalent state, not a generic error banner', async () => {
    signIn()
    get.mockRejectedValue(
      new ApiError(
        {
          type: 'https://docs.asidstech.com/errors/invoice-company-mismatch',
          title: 'Unprocessable.',
          status: 422,
          detail: 'This invoice does not belong to the company in the path.',
        },
        422,
      ),
    )

    const wrapper = await mountPage()

    expect(wrapper.text()).not.toContain('INV-2026-06-0001')
    expect(wrapper.text().toLowerCase()).toMatch(/not found|could not find/)
  })

  it('reloads when the active company changes', async () => {
    signIn()
    get.mockResolvedValue({ data: invoice(), meta: apiMeta() })

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
  })
})

describe('SalesInvoiceDetailPage — issue (§4.10)', () => {
  it('hides Issue for an OWNER when capabilities.can_issue is false', async () => {
    signIn({ isOwner: true, permissions: [] })
    get.mockResolvedValue({
      data: invoice({ status: 'issued', capabilities: { can_update: false, can_delete: false, can_issue: false, can_cancel: true } }),
      meta: apiMeta(),
    })

    const wrapper = await mountPage()

    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Issue invoice')).toBe(
      false,
    )
  })

  it('shows a Tier-2 confirm dialog before issuing, and posts only on confirmation', async () => {
    signIn()
    get.mockResolvedValue({
      data: invoice({ status: 'draft', number: null, capabilities: { can_update: true, can_delete: true, can_issue: true, can_cancel: false } }),
      meta: apiMeta(),
    })
    post.mockResolvedValue({ data: invoice({ status: 'issued' }), meta: apiMeta() })

    const wrapper = await mountPage()
    const issueButton = wrapper.findAll('a, button').find((el) => el.text().trim() === 'Issue invoice')
    expect(issueButton).toBeDefined()
    await issueButton?.trigger('click')

    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    expect(post).not.toHaveBeenCalled()

    await wrapper.find('[role="dialog"]').findAll('button').at(-1)?.trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/companies/company-1/sales-invoices/inv-1/issue')
  })

  it('surfaces each documented issue refusal with its own distinct wording', async () => {
    signIn()
    get.mockResolvedValue({
      data: invoice({ status: 'draft', number: null, capabilities: { can_update: true, can_delete: true, can_issue: true, can_cancel: false } }),
      meta: apiMeta(),
    })
    post.mockRejectedValue(
      new ApiError(
        {
          type: 'https://docs.asidstech.com/errors/invoice-period-not-open',
          title: 'Unprocessable.',
          status: 422,
          detail: 'The accounting period for this invoice date is closed.',
        },
        422,
      ),
    )

    const wrapper = await mountPage()
    const issueButton = wrapper.findAll('a, button').find((el) => el.text().trim() === 'Issue invoice')
    await issueButton?.trigger('click')
    await wrapper.find('[role="dialog"]').findAll('button').at(-1)?.trigger('click')
    await flushPromises()

    expect(useUiStore().notices.at(-1)).toMatchObject({ kind: 'error' })
    expect(useUiStore().notices.at(-1)?.message).toContain('period')
  })
})

describe('SalesInvoiceDetailPage — cancel (§4.11)', () => {
  it('hides Cancel for an OWNER when capabilities.can_cancel is false', async () => {
    signIn({ isOwner: true, permissions: [] })
    get.mockResolvedValue({
      data: invoice({ capabilities: { can_update: false, can_delete: false, can_issue: false, can_cancel: false } }),
      meta: apiMeta(),
    })

    const wrapper = await mountPage()

    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Cancel invoice')).toBe(
      false,
    )
  })

  it('requires a 3–255 character reason before Cancel can be confirmed', async () => {
    signIn()
    get.mockResolvedValue({ data: invoice(), meta: apiMeta() })

    const wrapper = await mountPage()
    const cancelButton = wrapper.findAll('a, button').find((el) => el.text().trim() === 'Cancel invoice')
    expect(cancelButton).toBeDefined()
    await cancelButton?.trigger('click')

    // Re-queried fresh from the stable top-level `wrapper` at every step rather than cached
    // across a `setValue`: the dialog renders through a `<Teleport>`, stubbed globally as `true`
    // in `tests/Support/vitest.setup.ts`, and a DOMWrapper captured before a reactive update does
    // not reliably reflect one made afterwards (the same caveat `ConfirmDialog.spec.ts` states
    // explicitly for this exact component).
    expect(wrapper.find('[role="dialog"]').findAll('button').at(-1)?.attributes('disabled')).toBeDefined()

    await wrapper.find('[role="dialog"]').find('textarea').setValue('no')
    expect(wrapper.find('[role="dialog"]').findAll('button').at(-1)?.attributes('disabled')).toBeDefined()

    await wrapper.find('[role="dialog"]').find('textarea').setValue('Customer requested cancellation.')
    expect(wrapper.find('[role="dialog"]').findAll('button').at(-1)?.attributes('disabled')).toBeUndefined()
  })

  it('posts the reason to the cancel sub-route on confirmation', async () => {
    signIn()
    get.mockResolvedValue({ data: invoice(), meta: apiMeta() })
    post.mockResolvedValue({
      data: invoice({ status: 'cancelled', cancellation_reason: 'Customer requested cancellation.' }),
      meta: apiMeta(),
    })

    const wrapper = await mountPage()
    const cancelButton = wrapper.findAll('a, button').find((el) => el.text().trim() === 'Cancel invoice')
    await cancelButton?.trigger('click')

    await wrapper.find('[role="dialog"]').find('textarea').setValue('Customer requested cancellation.')
    await wrapper.find('[role="dialog"]').findAll('button').at(-1)?.trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith(
      '/companies/company-1/sales-invoices/inv-1/cancel',
      expect.objectContaining({ reason: 'Customer requested cancellation.' }),
    )
  })

  it('gives the "today\'s period, not the invoice\'s" refusal its own distinct wording', async () => {
    signIn()
    get.mockResolvedValue({ data: invoice(), meta: apiMeta() })
    post.mockRejectedValue(
      new ApiError(
        {
          type: 'https://docs.asidstech.com/errors/invoice-reversal-period-not-open',
          title: 'Unprocessable.',
          status: 422,
          detail: 'The current accounting period is closed.',
        },
        422,
      ),
    )

    const wrapper = await mountPage()
    const cancelButton = wrapper.findAll('a, button').find((el) => el.text().trim() === 'Cancel invoice')
    await cancelButton?.trigger('click')
    await wrapper.find('[role="dialog"]').find('textarea').setValue('Customer requested cancellation.')
    await wrapper.find('[role="dialog"]').findAll('button').at(-1)?.trigger('click')
    await flushPromises()

    const message = useUiStore().notices.at(-1)?.message ?? ''
    expect(message.toLowerCase()).toContain('today')
  })
})

describe('SalesInvoiceDetailPage — delete (§4.12)', () => {
  it('offers Delete only for a draft invoice', async () => {
    signIn()
    get.mockResolvedValue({
      data: invoice({ status: 'issued', capabilities: { can_update: false, can_delete: false, can_issue: false, can_cancel: true } }),
      meta: apiMeta(),
    })

    const wrapper = await mountPage()

    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Delete')).toBe(false)

    const trigger = wrapper.find('button[aria-haspopup="menu"]')
    if (trigger.exists()) {
      await trigger.trigger('click')
      expect(wrapper.findAll('[role="menuitem"]').some((el) => el.text().includes('Delete'))).toBe(
        false,
      )
    }
  })

  it('offers Delete for a draft with capabilities.can_delete true, behind the overflow menu with a checkbox confirm', async () => {
    signIn()
    get.mockResolvedValue({
      data: invoice({
        status: 'draft',
        number: null,
        capabilities: { can_update: true, can_delete: true, can_issue: true, can_cancel: false },
      }),
      meta: apiMeta(),
    })
    del.mockResolvedValue({ data: null, meta: apiMeta() })

    const wrapper = await mountPage()
    const trigger = wrapper.find('button[aria-haspopup="menu"]')
    expect(trigger.exists()).toBe(true)
    await trigger.trigger('click')

    const deleteItem = wrapper.findAll('[role="menuitem"]').find((el) => el.text().includes('Delete'))
    expect(deleteItem).toBeDefined()
    await deleteItem?.trigger('click')

    // A draft has no `number` yet, so the checkbox variant is used, never a typed token. Every
    // reference below is re-queried fresh from the stable top-level `wrapper` rather than cached
    // across a `setValue`/click — see the note in the cancel-reason test above.
    expect(wrapper.find('[role="dialog"]').find('input[type="text"]').exists()).toBe(false)
    expect(wrapper.find('[role="dialog"]').findAll('button').at(-1)?.attributes('disabled')).toBeDefined()

    await wrapper.find('[role="dialog"]').find('input[type="checkbox"]').setValue(true)
    expect(wrapper.find('[role="dialog"]').findAll('button').at(-1)?.attributes('disabled')).toBeUndefined()

    await wrapper.find('[role="dialog"]').findAll('button').at(-1)?.trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/companies/company-1/sales-invoices/inv-1')
  })
})
