import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type * as ApiClientModule from '@/api/client'
import type { Customer } from '@/types/domain'
import type { ApiMeta, Pagination } from '@/types/api'

/**
 * QA acceptance specs — customer list (requirements §4.1, ADR 0013 §9).
 *
 * Written red, against the pre-step "Coming soon" stub, per the Phase 3 frontend two-lane build
 * (ADR 0013 §8). These specs are the independent acceptance layer the customer lane must turn
 * green — they are not the lane's own component unit specs, and the lane must not overwrite them.
 *
 * What is asserted here is deliberately narrow to the behaviours that fail *silently* if gotten
 * wrong — a request missing its filter parameter, a stale table left under an error notice, and
 * above all the "capabilities-AND-permission" double gate (ADR 0013 §4, ADR 0012 D4's owner-gap):
 * an owner always passes a raw permission check via `Gate::before`, so the one place an action's
 * visibility can still be got right is the resource's own `capabilities` object. A spec below
 * proves an owner-shaped user with `capabilities.can_update === false` still does not see Edit —
 * the exact gap a permission-only `PermissionGate` check would silently reintroduce.
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

const CustomersListPage = (await import('@/pages/sales/CustomersListPage.vue')).default
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
    legal_name: null,
    tax_identification_number: null,
    vat_registration_number: null,
    is_vat_registered: false,
    email: null,
    phone: null,
    website: null,
    address_line_1: null,
    address_line_2: null,
    city: null,
    district: null,
    postal_code: null,
    country_code: null,
    payment_terms_days: 30,
    credit_limit: null,
    receivable_account_id: null,
    notes: null,
    status: 'active',
    status_label: 'Active',
    archived_at: null,
    deleted_at: null,
    capabilities: { can_update: true, can_delete: true, accepts_new_invoices: true },
    ...overrides,
  }
}

function pagination(overrides: Partial<Pagination> = {}): Pagination {
  return { total: 1, per_page: 15, current_page: 1, last_page: 1, from: 1, to: 1, ...overrides }
}

function apiMeta(overrides: Partial<ApiMeta> = {}): ApiMeta {
  return {
    request_id: 'req-1',
    api_version: '1',
    pagination: pagination(),
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

async function mountPage() {
  const router = testRouter()
  await router.push('/')
  await router.isReady()

  const wrapper = mount(CustomersListPage, { global: { plugins: [router] } })
  await flushPromises()

  return wrapper
}

function wait(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

function labelledInput(wrapper: ReturnType<typeof mount>, labelText: string) {
  const label = wrapper.findAll('label').find((candidate) => candidate.text().includes(labelText))
  const forId = label?.attributes('for')
  return forId ? wrapper.find(`#${forId}`) : wrapper.find('[data-qa-not-found]')
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
  post.mockReset()
  del.mockReset()
})

describe('CustomersListPage', () => {
  it('renders every customer the server returns', async () => {
    signIn()
    get.mockResolvedValue({
      data: [customer(), customer({ id: 'cus-2', code: 'C-0002', name: 'Perera & Sons' })],
      meta: apiMeta({ pagination: pagination({ total: 2, to: 2 }) }),
    })

    const wrapper = await mountPage()

    expect(wrapper.text()).toContain('Silva Traders')
    expect(wrapper.text()).toContain('Perera & Sons')
  })

  it('sends q and filter[status] as server parameters, never filtering the array itself', async () => {
    signIn()
    get.mockResolvedValue({ data: [customer()], meta: apiMeta() })

    const wrapper = await mountPage()
    expect(get).toHaveBeenCalledTimes(1)

    const search = labelledInput(wrapper, 'Search')
    await search.setValue('silva')

    // Debounced: no second request until the 300ms window has elapsed (matches UsersPage.vue).
    expect(get).toHaveBeenCalledTimes(1)
    await wait(350)
    await flushPromises()

    expect(get).toHaveBeenCalledTimes(2)
    expect(get).toHaveBeenLastCalledWith(
      '/companies/company-1/customers',
      expect.objectContaining({ q: 'silva' }),
    )

    const statusSelect = wrapper.find('select')
    await statusSelect.setValue('archived')
    await flushPromises()

    // A filter change re-queries immediately, with no debounce.
    expect(get).toHaveBeenCalledTimes(3)
    expect(get).toHaveBeenLastCalledWith(
      '/companies/company-1/customers',
      expect.objectContaining({ filter: expect.objectContaining({ status: 'archived' }) }),
    )
  })

  it('wires meta.pagination into the Pagination control and requests the next page on click', async () => {
    signIn()
    get.mockResolvedValue({
      data: [customer()],
      meta: apiMeta({ pagination: pagination({ total: 30, per_page: 15, last_page: 2, to: 15 }) }),
    })

    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('1–15 of 30')

    const nextButton = wrapper.findAll('button').find((b) => b.text() === 'Next')
    expect(nextButton).toBeDefined()
    await nextButton?.trigger('click')
    await flushPromises()

    expect(get).toHaveBeenLastCalledWith(
      '/companies/company-1/customers',
      expect.objectContaining({ page: 2 }),
    )
  })

  it('clears rows and does not render the empty state after a failed request', async () => {
    signIn()
    get.mockResolvedValueOnce({ data: [customer()], meta: apiMeta() })

    const wrapper = await mountPage()
    expect(wrapper.text()).toContain('Silva Traders')

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

    const statusSelect = wrapper.find('select')
    await statusSelect.setValue('inactive')
    await flushPromises()

    expect(wrapper.text()).not.toContain('Silva Traders')
    // ADR 0011 D4's corrected mistake: a failed request must not fall through to the empty copy.
    expect(wrapper.text()).not.toContain('This company has no customers yet.')
    expect(wrapper.text()).not.toContain('No customers match that search.')
    expect(useUiStore().notices.at(-1)).toMatchObject({
      kind: 'error',
      message: 'Your account does not have permission to perform this action.',
    })
  })

  it('surfaces a 422 unsupported-sort refusal via a notice using the problem detail', async () => {
    signIn()
    get.mockRejectedValueOnce(
      new ApiError(
        {
          type: 'https://docs.asidstech.com/errors/validation-failed',
          title: 'Validation failed.',
          status: 422,
          detail: 'The selected sort is invalid.',
        },
        422,
      ),
    )

    await mountPage()

    expect(useUiStore().notices.at(-1)).toMatchObject({
      kind: 'error',
      message: 'The selected sort is invalid.',
    })
  })

  it('de-emphasises an archived customer row', async () => {
    signIn()
    get.mockResolvedValue({
      data: [customer({ status: 'archived', status_label: 'Archived', archived_at: '2026-08-01' })],
      meta: apiMeta(),
    })

    const wrapper = await mountPage()

    // Colour-plus-opacity, never colour alone: the label itself must also be legible in words.
    expect(wrapper.text()).toContain('Archived')
    expect(wrapper.html()).toContain('opacity-60')
  })

  it('hides "Add a customer" for a user without sales.customers.manage', async () => {
    signIn({ permissions: ['sales.customers.view'] })
    get.mockResolvedValue({ data: [], meta: apiMeta({ pagination: pagination({ total: 0, from: null, to: null }) }) })

    const wrapper = await mountPage()

    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Add a customer')).toBe(
      false,
    )
  })

  it('hides Edit and every lifecycle action for an OWNER when capabilities.can_update is false', async () => {
    // The exact gap ADR 0012 D4 / ADR 0013 §4 name: `Gate::before` grants an owner every
    // permission unconditionally, so a permission-only check would show Edit here. Only the
    // resource's own `capabilities.can_update` may gate this control.
    signIn({ isOwner: true, permissions: [] })
    get.mockResolvedValue({
      data: [customer({ capabilities: { can_update: false, can_delete: false, accepts_new_invoices: true } })],
      meta: apiMeta(),
    })

    const wrapper = await mountPage()

    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Edit')).toBe(false)
    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Archive')).toBe(false)
    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Deactivate')).toBe(false)

    // If an overflow menu is even offered with nothing enabled in it, opening it must not show
    // "Delete" either — an owner's raw permission must never be the deciding factor.
    const trigger = wrapper.find('button[aria-haspopup="menu"]')
    if (trigger.exists()) {
      await trigger.trigger('click')
      expect(wrapper.findAll('[role="menuitem"]').some((el) => el.text().includes('Delete'))).toBe(
        false,
      )
    }
  })

  it('shows Edit and the lifecycle actions when capabilities.can_update is true', async () => {
    signIn()
    get.mockResolvedValue({ data: [customer()], meta: apiMeta() })

    const wrapper = await mountPage()

    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Edit')).toBe(true)
  })

  it('requires a Tier-1 window.confirm before archiving, and does nothing on cancel', async () => {
    signIn()
    get.mockResolvedValue({ data: [customer()], meta: apiMeta() })
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)

    const wrapper = await mountPage()
    const archiveButton = wrapper
      .findAll('a, button')
      .find((el) => el.text().trim() === 'Archive')

    expect(archiveButton).toBeDefined()
    await archiveButton?.trigger('click')

    expect(confirmSpy).toHaveBeenCalled()
    expect(post).not.toHaveBeenCalled()
  })

  it('archives after confirmation, posting to the archive sub-route', async () => {
    signIn()
    get.mockResolvedValue({ data: [customer()], meta: apiMeta() })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    post.mockResolvedValue({ data: customer({ status: 'archived' }), meta: apiMeta() })

    const wrapper = await mountPage()
    const archiveButton = wrapper
      .findAll('a, button')
      .find((el) => el.text().trim() === 'Archive')

    await archiveButton?.trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/companies/company-1/customers/cus-1/archive')
  })

  it('routes hard delete through the overflow menu and the typed-confirm dialog, never a primary button', async () => {
    signIn()
    get.mockResolvedValue({ data: [customer()], meta: apiMeta() })
    del.mockResolvedValue({ data: null, meta: apiMeta() })

    const wrapper = await mountPage()

    // Gate-1 decision #2: "Delete" is never a first-class button beside Edit/Archive.
    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Delete')).toBe(false)

    const trigger = wrapper.find('button[aria-haspopup="menu"]')
    expect(trigger.exists()).toBe(true)
    await trigger.trigger('click')

    const deleteItem = wrapper.findAll('[role="menuitem"]').find((el) => el.text().includes('Delete'))
    expect(deleteItem).toBeDefined()
    await deleteItem?.trigger('click')

    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)

    // Disabled until the customer's own code is typed verbatim (the typed-token variant, since a
    // customer always has a stable `code`). Every reference below is re-queried fresh from the
    // stable top-level `wrapper` rather than cached across a `setValue`/click: the dialog renders
    // through a `<Teleport>`, stubbed globally as `true` in `tests/Support/vitest.setup.ts`, and a
    // DOMWrapper captured before a reactive update does not reliably reflect one made afterwards
    // (the same caveat `ConfirmDialog.spec.ts` states explicitly for this exact component).
    expect(wrapper.find('[role="dialog"]').findAll('button').at(-1)?.attributes('disabled')).toBeDefined()

    await wrapper.find('[role="dialog"]').find('input[type="text"]').setValue('C-0001')
    expect(wrapper.find('[role="dialog"]').findAll('button').at(-1)?.attributes('disabled')).toBeUndefined()

    await wrapper.find('[role="dialog"]').findAll('button').at(-1)?.trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/companies/company-1/customers/cus-1')
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
    expect(get).toHaveBeenLastCalledWith(
      '/companies/company-2/customers',
      expect.anything(),
    )
  })
})
