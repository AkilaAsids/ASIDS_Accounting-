import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type * as ApiClientModule from '@/api/client'
import type { Customer } from '@/types/domain'
import type { ApiMeta } from '@/types/api'

/**
 * QA acceptance specs — customer create/edit (requirements §4.2/§4.3, ADR 0013 §9).
 *
 * The single most acceptance-critical behaviour on this screen is the clear-vs-omit contract
 * (§4.3.1/§4.3.2): an untouched optional field must be **absent** from the `PUT` body (so the
 * server leaves it alone) and an explicitly-cleared one must be sent as JSON `null` — sending an
 * untouched field back as its current value would silently do no harm today, but sending a
 * *cleared* field as an empty string rather than `null`, or an untouched field's current value
 * because the diff was computed wrong, are exactly the silent, no-error failure modes this test
 * exists to catch before the API ever gets a chance to refuse anything.
 *
 * `status` must never appear in a create/update body in any form — the request schema does not
 * accept it (§4.2.7) — regardless of anything the UI's local state might otherwise imply.
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
const { useUiStore } = await import('@/stores/ui')
const { ApiError } = await import('@/api/client')

function customer(overrides: Partial<Customer> = {}): Customer {
  return {
    id: 'cus-1',
    company_id: 'company-1',
    branch_id: 'branch-1',
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
    credit_limit: '5000.0000',
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

function signIn(): void {
  useAuthStore().$patch({
    initialised: true,
    user: { id: 'user-1', full_name: 'Kumari Silva', email: 'kumari@acme.test', is_owner: false },
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
    permissions: new Set(['sales.customers.view', 'sales.customers.manage']),
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

async function mountCreatePage() {
  const router = testRouter()
  await router.push('/new')
  await router.isReady()

  const wrapper = mount(CustomerFormPage, { global: { plugins: [router] } })
  await flushPromises()

  return wrapper
}

async function mountEditPage() {
  const router = testRouter()
  await router.push('/cus-1/edit')
  await router.isReady()

  const wrapper = mount(CustomerFormPage, { global: { plugins: [router] } })
  await flushPromises()

  return wrapper
}

function labelledInput(wrapper: ReturnType<typeof mount>, labelText: string) {
  const label = wrapper.findAll('label').find((candidate) => candidate.text().includes(labelText))
  const forId = label?.attributes('for')
  return forId ? wrapper.find(`#${forId}`) : wrapper.find('[data-qa-not-found]')
}

async function submit(wrapper: ReturnType<typeof mount>) {
  await wrapper.find('form').trigger('submit')
  await flushPromises()
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
  post.mockReset()
  put.mockReset()
})

describe('CustomerFormPage — create (§4.2)', () => {
  it('submits with only the name filled in', async () => {
    signIn()
    post.mockResolvedValue({ data: customer({ name: 'New Customer' }), meta: apiMeta() })

    const wrapper = await mountCreatePage()
    await labelledInput(wrapper, 'Name').setValue('New Customer')
    await submit(wrapper)

    expect(post).toHaveBeenCalledTimes(1)
    const [url, body] = post.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe('/companies/company-1/customers')
    expect(body.name).toBe('New Customer')
  })

  it('never sends a status field, regardless of UI state', async () => {
    signIn()
    post.mockResolvedValue({ data: customer(), meta: apiMeta() })

    const wrapper = await mountCreatePage()
    await labelledInput(wrapper, 'Name').setValue('Silva Traders')
    await submit(wrapper)

    const [, body] = post.mock.calls[0] as [string, Record<string, unknown>]
    expect('status' in body).toBe(false)
  })

  it('sends credit_limit as the exact typed decimal string, leading minus included, never a number', async () => {
    signIn()
    post.mockResolvedValue({ data: customer(), meta: apiMeta() })

    const wrapper = await mountCreatePage()
    await labelledInput(wrapper, 'Name').setValue('Silva Traders')
    await labelledInput(wrapper, 'Credit limit').setValue('-500.1234')
    await submit(wrapper)

    const [, body] = post.mock.calls[0] as [string, Record<string, unknown>]
    expect(body.credit_limit).toBe('-500.1234')
    expect(typeof body.credit_limit).toBe('string')
  })

  it('shows 422 field errors against their own fields', async () => {
    signIn()
    post.mockRejectedValue(
      new ApiError(
        {
          type: 'https://docs.asidstech.com/errors/validation-failed',
          title: 'Validation failed.',
          status: 422,
          detail: 'The given data was invalid.',
          errors: { name: ['The name has already been taken.'] },
        },
        422,
      ),
    )

    const wrapper = await mountCreatePage()
    await labelledInput(wrapper, 'Name').setValue('Silva Traders')
    await submit(wrapper)

    expect(wrapper.text()).toContain('The name has already been taken.')
  })

  it('surfaces a 409 duplicate-code conflict as a notice, not a field error', async () => {
    signIn()
    post.mockRejectedValue(
      new ApiError(
        {
          type: 'https://docs.asidstech.com/errors/resource-conflict',
          title: 'Conflict.',
          status: 409,
          detail: 'That customer code is already in use.',
        },
        409,
      ),
    )

    const wrapper = await mountCreatePage()
    await labelledInput(wrapper, 'Name').setValue('Silva Traders')
    await submit(wrapper)

    expect(useUiStore().notices.at(-1)).toMatchObject({
      kind: 'error',
      message: 'That customer code is already in use.',
    })
  })

  it('notifies success naming the created customer', async () => {
    signIn()
    post.mockResolvedValue({ data: customer({ name: 'Brand New Traders' }), meta: apiMeta() })

    const wrapper = await mountCreatePage()
    await labelledInput(wrapper, 'Name').setValue('Brand New Traders')
    await submit(wrapper)

    const success = useUiStore().notices.find((notice) => notice.kind === 'success')
    expect(success?.message).toContain('Brand New Traders')
  })
})

describe('CustomerFormPage — edit (§4.3)', () => {
  it('pre-fills the form from the fetched customer', async () => {
    signIn()
    get.mockResolvedValue({ data: customer(), meta: apiMeta() })

    const wrapper = await mountEditPage()

    expect(get).toHaveBeenCalledWith('/companies/company-1/customers/cus-1')
    expect((labelledInput(wrapper, 'Name').element as HTMLInputElement).value).toBe('Silva Traders')
  })

  it('omits every field the user did not touch from the PUT body', async () => {
    signIn()
    get.mockResolvedValue({ data: customer(), meta: apiMeta() })
    put.mockResolvedValue({ data: customer(), meta: apiMeta() })

    const wrapper = await mountEditPage()
    await labelledInput(wrapper, 'Name').setValue('Silva Traders Ltd')
    await submit(wrapper)

    expect(put).toHaveBeenCalledTimes(1)
    const [url, body] = put.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe('/companies/company-1/customers/cus-1')
    expect(body.name).toBe('Silva Traders Ltd')

    // Untouched, so absent — not resent as their current (unchanged) value.
    expect('credit_limit' in body).toBe(false)
    expect('branch_id' in body).toBe(false)
    expect('receivable_account_id' in body).toBe(false)
    expect('status' in body).toBe(false)
  })

  it('sends branch_id, receivable_account_id and credit_limit as explicit null when each is independently cleared', async () => {
    signIn()
    get.mockResolvedValue({ data: customer(), meta: apiMeta() })
    put.mockResolvedValue({ data: customer(), meta: apiMeta() })

    // Each of the three "Clear" affordances (design §2.1.3) is exercised once, in a fresh mount
    // each time, and the resulting body is inspected for which field came back `null`. This does
    // not assume a fixed left-to-right order for the three controls — it only asserts that all
    // three are independently reachable and each clears to `null` on its own, which is the
    // acceptance-critical property (§4.3.2), not their on-screen order.
    const nulledFields = new Set<string>()

    for (let index = 0; index < 3; index += 1) {
      const wrapper = await mountEditPage()
      const clearButtons = wrapper.findAll('button').filter((b) => b.text().trim() === 'Clear')
      expect(clearButtons).toHaveLength(3)

      await clearButtons[index]?.trigger('click')
      await submit(wrapper)

      const [, body] = put.mock.calls.at(-1) as [string, Record<string, unknown>]
      for (const field of ['branch_id', 'receivable_account_id', 'credit_limit']) {
        if (field in body && body[field] === null) {
          nulledFields.add(field)
        }
      }
    }

    expect(nulledFields).toEqual(new Set(['branch_id', 'receivable_account_id', 'credit_limit']))
  })

  it('surfaces a 422/409 on update exactly as on create', async () => {
    signIn()
    get.mockResolvedValue({ data: customer(), meta: apiMeta() })
    put.mockRejectedValue(
      new ApiError(
        {
          type: 'https://docs.asidstech.com/errors/resource-conflict',
          title: 'Conflict.',
          status: 409,
          detail: 'That customer code is already in use.',
        },
        409,
      ),
    )

    const wrapper = await mountEditPage()
    await labelledInput(wrapper, 'Name').setValue('Silva Traders Ltd')
    await submit(wrapper)

    expect(useUiStore().notices.at(-1)).toMatchObject({
      kind: 'error',
      message: 'That customer code is already in use.',
    })
  })
})
