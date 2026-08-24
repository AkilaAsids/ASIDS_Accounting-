import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type * as ApiClientModule from '@/api/client'
import type { Customer } from '@/types/domain'
import type { ApiMeta } from '@/types/api'

/**
 * Company-switch-mid-edit for the customer form (security review follow-up, ADR 0011 D3).
 *
 * Every list/detail page in this lane reloads via `useCompanyReload` when the active company
 * changes. `CustomerFormPage.vue` is the one screen that instead has something to *lose* — its
 * `useUnsavedGuard` registration lets `CompanySwitcher.select()` ask "discard unsaved changes?"
 * before a switch commits. But once a switch HAS committed, an open create/edit form for the
 * *old* company is not just stale, it is actively dangerous to leave on screen: a create
 * submission would silently post a new customer into the new company, and an edit form keeps
 * rendering the old company's customer — TIN, VAT registration, address, credit limit, notes —
 * under the new company's banner. This mirrors `SalesInvoiceEditorPage.vue`'s own
 * `watch(companyId, …)`, which leaves for its list on a switch; this page must do the same for
 * both create and edit, since neither has anything sensible left to show.
 *
 * QA's own `CustomerFormPage.spec.ts` is the acceptance layer for §4.2/§4.3 and is not touched
 * here — this file is this lane's own, narrowly scoped to the one behaviour flagged.
 */
const get = vi.fn()
const post = vi.fn()
const put = vi.fn()

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof ApiClientModule>('@/api/client')

  return {
    ...actual,
    api: { get, post, put, delete: vi.fn(), setActiveCompany: vi.fn(), configure: vi.fn() },
  }
})

const CustomerFormPage = (await import('@/pages/sales/CustomerFormPage.vue')).default
const { useAuthStore } = await import('@/stores/auth')

function customer(overrides: Partial<Customer> = {}): Customer {
  return {
    id: 'cus-1',
    company_id: 'company-1',
    branch_id: null,
    code: 'C-0001',
    name: 'Silva Traders',
    legal_name: null,
    tax_identification_number: '123456789',
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

function apiMeta(): ApiMeta {
  return { request_id: 'req-1', api_version: '1' }
}

function companySummary(overrides: Partial<{ id: string; name: string; code: string }> = {}) {
  return {
    id: 'company-1',
    name: 'Demo Trading',
    code: 'DTL',
    base_currency_code: 'LKR',
    currency_precision: 2,
    timezone: 'Asia/Colombo',
    is_default: true,
    ...overrides,
  }
}

function signIn(): void {
  useAuthStore().$patch({
    initialised: true,
    user: { id: 'user-1', full_name: 'Kumari Silva', email: 'kumari@acme.test', is_owner: false },
    companies: [companySummary()],
    permissions: new Set(['sales.customers.view', 'sales.customers.manage']),
  } as never)
}

function switchCompany(): void {
  useAuthStore().$patch({ companies: [companySummary({ id: 'company-2', name: 'Second Books', code: 'SEC' })] } as never)
}

function labelledInput(wrapper: ReturnType<typeof mount>, labelText: string) {
  const label = wrapper.findAll('label').find((candidate) => candidate.text().includes(labelText))
  const forId = label?.attributes('for')
  return forId ? wrapper.find(`#${forId}`) : wrapper.find('[data-qa-not-found]')
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

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
  post.mockReset()
  put.mockReset()
})

describe('CustomerFormPage — company switch mid-edit (ADR 0011 D3)', () => {
  it('leaves the create form for the customer list when the active company changes', async () => {
    signIn()
    const router = testRouter()
    await router.push('/new')
    await router.isReady()

    const wrapper = mount(CustomerFormPage, { global: { plugins: [router] } })
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('customer-new')

    switchCompany()
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('customers')
    wrapper.unmount()
  })

  it('leaves the edit form — no longer showing the old company’s customer PII — when the active company changes', async () => {
    signIn()
    get.mockResolvedValue({
      data: customer({ tax_identification_number: '999888777' }),
      meta: apiMeta(),
    })

    const router = testRouter()
    await router.push('/cus-1/edit')
    await router.isReady()

    const wrapper = mount(CustomerFormPage, { global: { plugins: [router] } })
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('customer-edit')
    expect((labelledInput(wrapper, 'Name').element as HTMLInputElement).value).toBe('Silva Traders')

    switchCompany()
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('customers')
    wrapper.unmount()
  })

  it('does not navigate away on mount, only on an actual change', async () => {
    signIn()
    const router = testRouter()
    await router.push('/new')
    await router.isReady()

    const wrapper = mount(CustomerFormPage, { global: { plugins: [router] } })
    await flushPromises()

    // Patching with the *same* company must not read as a switch.
    useAuthStore().$patch({ companies: [companySummary()] } as never)
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('customer-new')
    wrapper.unmount()
  })
})
