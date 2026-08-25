import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type * as ApiClientModule from '@/api/client'
import type { Customer } from '@/types/domain'
import type { ApiMeta } from '@/types/api'

/**
 * QA acceptance specs — customer view + lifecycle (requirements §4.4/§4.5, ADR 0013 §9).
 *
 * The gating discipline is the point of this file: every lifecycle control (archive, restore,
 * deactivate, reactivate, edit, delete) must be driven off the customer's own `capabilities`
 * object, never `status` read directly in the template and never a raw permission check alone —
 * an owner's permission is unconditional (`Gate::before`), so a permission-only check would show
 * every control regardless of state (ADR 0012 D4's named gap, carried into ADR 0013 §4).
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

const CustomerDetailPage = (await import('@/pages/sales/CustomerDetailPage.vue')).default
const { useAuthStore } = await import('@/stores/auth')
const { useUiStore } = await import('@/stores/ui')
const { ApiError } = await import('@/api/client')

function customer(overrides: Partial<Customer> = {}): Customer {
  return {
    id: 'cus-1',
    company_id: 'company-1',
    branch_id: null,
    code: 'C-0001',
    name: 'Silva Traders',
    legal_name: 'Silva Traders (Private) Limited',
    tax_identification_number: '123456789',
    vat_registration_number: 'VAT-000111',
    is_vat_registered: true,
    email: 'accounts@silva.test',
    phone: '+94112223344',
    website: null,
    address_line_1: '12 Galle Road',
    address_line_2: null,
    city: 'Colombo',
    district: 'Colombo',
    postal_code: '00300',
    country_code: 'LK',
    payment_terms_days: 30,
    credit_limit: '50000.0000',
    receivable_account_id: 'acc-ar-1',
    notes: null,
    status: 'active',
    status_label: 'Active',
    archived_at: null,
    deleted_at: null,
    capabilities: { can_update: true, can_delete: true, accepts_new_invoices: true },
    ...overrides,
  }
}

function apiMeta(): ApiMeta {
  return { request_id: 'req-1', api_version: '1' }
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
    permissions: new Set(options.permissions ?? ['sales.customers.view', 'sales.customers.manage']),
  } as never)
}

function testRouter() {
  const stub = { template: '<div />' }
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'customers', component: stub },
      { path: '/new', name: 'customer-new', component: stub },
      { path: '/:customerId', name: 'customer-detail', component: stub },
      { path: '/:customerId/edit', name: 'customer-edit', component: stub },
    ],
  })
}

async function mountPage(customerId = 'cus-1') {
  const router = testRouter()
  await router.push(`/${customerId}`)
  await router.isReady()

  const wrapper = mount(CustomerDetailPage, { global: { plugins: [router] } })
  await flushPromises()

  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
  post.mockReset()
  del.mockReset()
})

describe('CustomerDetailPage — view (§4.4)', () => {
  it('renders the fetched customer’s fields', async () => {
    signIn()
    get.mockResolvedValue({ data: customer(), meta: apiMeta() })

    const wrapper = await mountPage()

    expect(get).toHaveBeenCalledWith('/companies/company-1/customers/cus-1')
    expect(wrapper.text()).toContain('Silva Traders')
    expect(wrapper.text()).toContain('C-0001')
    expect(wrapper.text()).toContain('accounts@silva.test')
    expect(wrapper.text()).toContain('123456789')
  })

  it('states an archived customer’s status in words, not colour alone', async () => {
    signIn()
    get.mockResolvedValue({
      data: customer({ status: 'archived', status_label: 'Archived', archived_at: '2026-08-01' }),
      meta: apiMeta(),
    })

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('Archived')
  })

  it('treats a 404 as a generic not-found state, never distinguishing "not found" from "not yours"', async () => {
    signIn()
    get.mockRejectedValue(
      new ApiError(
        {
          type: 'https://docs.asidstech.com/errors/not-found',
          title: 'Not found.',
          status: 404,
          detail: 'The requested resource could not be found.',
        },
        404,
      ),
    )

    const wrapper = await mountPage()

    expect(wrapper.text()).not.toContain('Silva Traders')
    expect(wrapper.text().toLowerCase()).toMatch(/not found|could not find/)
  })

  it('reloads when the active company changes', async () => {
    signIn()
    get.mockResolvedValue({ data: customer(), meta: apiMeta() })

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

describe('CustomerDetailPage — lifecycle (§4.5)', () => {
  it('hides Edit and every lifecycle action for an OWNER when capabilities.can_update is false', async () => {
    signIn({ isOwner: true, permissions: [] })
    get.mockResolvedValue({
      data: customer({ capabilities: { can_update: false, can_delete: false, accepts_new_invoices: true } }),
      meta: apiMeta(),
    })

    const wrapper = await mountPage()

    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Edit')).toBe(false)
    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Archive')).toBe(false)
    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Deactivate')).toBe(false)

    const trigger = wrapper.find('button[aria-haspopup="menu"]')
    if (trigger.exists()) {
      await trigger.trigger('click')
      expect(wrapper.findAll('[role="menuitem"]').some((el) => el.text().includes('Delete'))).toBe(
        false,
      )
    }
  })

  it('shows Edit when capabilities.can_update is true', async () => {
    signIn()
    get.mockResolvedValue({ data: customer(), meta: apiMeta() })

    const wrapper = await mountPage()

    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Edit')).toBe(true)
  })

  it('requires window.confirm before archiving and posts the archive sub-route on confirmation', async () => {
    signIn()
    get.mockResolvedValue({ data: customer(), meta: apiMeta() })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    post.mockResolvedValue({ data: customer({ status: 'archived' }), meta: apiMeta() })

    const wrapper = await mountPage()
    const archiveButton = wrapper.findAll('a, button').find((el) => el.text().trim() === 'Archive')
    await archiveButton?.trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/companies/company-1/customers/cus-1/archive')
  })

  it('surfaces an outstanding-balance 422 on archive as a notice, without pre-computing the balance', async () => {
    signIn()
    get.mockResolvedValue({ data: customer(), meta: apiMeta() })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    post.mockRejectedValue(
      new ApiError(
        {
          type: 'https://docs.asidstech.com/errors/validation-failed',
          title: 'Validation failed.',
          status: 422,
          detail: 'This customer has an outstanding balance and cannot be archived.',
        },
        422,
      ),
    )

    const wrapper = await mountPage()
    const archiveButton = wrapper.findAll('a, button').find((el) => el.text().trim() === 'Archive')
    await archiveButton?.trigger('click')
    await flushPromises()

    expect(useUiStore().notices.at(-1)).toMatchObject({
      kind: 'error',
      message: 'This customer has an outstanding balance and cannot be archived.',
    })
  })

  it('routes hard delete through the overflow menu and the typed-confirm dialog', async () => {
    signIn()
    get.mockResolvedValue({ data: customer(), meta: apiMeta() })
    del.mockResolvedValue({ data: null, meta: apiMeta() })

    const wrapper = await mountPage()

    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Delete')).toBe(false)

    const trigger = wrapper.find('button[aria-haspopup="menu"]')
    expect(trigger.exists()).toBe(true)
    await trigger.trigger('click')

    const deleteItem = wrapper.findAll('[role="menuitem"]').find((el) => el.text().includes('Delete'))
    await deleteItem?.trigger('click')

    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    expect(wrapper.find('[role="dialog"]').findAll('button').at(-1)?.attributes('disabled')).toBeDefined()

    // Re-queried fresh after every reactive step rather than cached: the dialog renders through
    // a `<Teleport>`, stubbed globally as `true` in `tests/Support/vitest.setup.ts`, and a
    // DOMWrapper captured before a reactive update does not reliably reflect one made afterwards
    // (the same caveat `ConfirmDialog.spec.ts` states explicitly for this exact component).
    await wrapper.find('[role="dialog"]').find('input[type="text"]').setValue('C-0001')
    expect(wrapper.find('[role="dialog"]').findAll('button').at(-1)?.attributes('disabled')).toBeUndefined()

    await wrapper.find('[role="dialog"]').findAll('button').at(-1)?.trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/companies/company-1/customers/cus-1')
  })

  it('suggests archiving when delete is refused because the customer has been invoiced', async () => {
    signIn()
    get.mockResolvedValue({ data: customer(), meta: apiMeta() })
    del.mockRejectedValue(
      new ApiError(
        {
          type: 'https://docs.asidstech.com/errors/validation-failed',
          title: 'Validation failed.',
          status: 422,
          detail: 'This customer has been invoiced and cannot be deleted.',
        },
        422,
      ),
    )

    const wrapper = await mountPage()
    const trigger = wrapper.find('button[aria-haspopup="menu"]')
    await trigger.trigger('click')
    const deleteItem = wrapper.findAll('[role="menuitem"]').find((el) => el.text().includes('Delete'))
    await deleteItem?.trigger('click')

    // Re-queried fresh at each step — see the note in the previous test.
    await wrapper.find('[role="dialog"]').find('input[type="text"]').setValue('C-0001')
    await wrapper.find('[role="dialog"]').findAll('button').at(-1)?.trigger('click')
    await flushPromises()

    const notice = useUiStore().notices.at(-1)
    expect(notice?.kind).toBe('error')
    expect(notice?.message.toLowerCase()).toContain('archiv')
  })
})
