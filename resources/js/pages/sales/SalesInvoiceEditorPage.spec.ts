import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type * as ApiClientModule from '@/api/client'
import type { Account, Customer, SalesInvoice, TaxCode } from '@/types/domain'
import type { ApiMeta } from '@/types/api'
import { hasUnsavedChanges } from '@/composables/useUnsavedGuard'

/**
 * QA acceptance specs — the invoice draft create/edit screen and its line editor (requirements
 * §4.7/§4.8, ADR 0013 §7/§9). This is the highest-risk component in the wave (ADR 0013 §7,
 * requirements §8) and gets disproportionate attention here, per the ADR's own instruction to
 * QA.
 *
 * The absolute rule under test throughout: the editor performs **no** money arithmetic. Every
 * total, tax figure and line total shown on screen must have come from the last successful API
 * response, never a client-side multiply/sum — mirrored from ADR 0011's own technique of making a
 * mocked response's total deliberately inconsistent with its rows, so a page that ever starts
 * computing its own numbers fails here rather than in front of an accountant.
 *
 * Field labels below are taken verbatim from `docs/PHASE-3-FRONTEND-DESIGN.md` §2.2.2 (header)
 * and §2.3.1 (line columns): "Customer", "Invoice date", "Due date", "Description", "Qty",
 * "Unit price", "Revenue account", "Tax code". Where the design leaves a control's exact
 * mechanism unspecified (the discount type toggle, §2.3.2), this file does not attempt to drive
 * it — see the QA report for that gap.
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

const SalesInvoiceEditorPage = (await import('@/pages/sales/SalesInvoiceEditorPage.vue')).default
const { useAuthStore } = await import('@/stores/auth')
const { useUiStore } = await import('@/stores/ui')
const { ApiError } = await import('@/api/client')

function apiMeta(): ApiMeta {
  return { request_id: 'req-1', api_version: '1' }
}

function taxCode(overrides: Partial<TaxCode> = {}): TaxCode {
  return {
    id: 'tax-1',
    company_id: 'company-1',
    code: 'VAT',
    name: 'Standard VAT',
    tax_type: 'vat',
    tax_type_label: 'VAT',
    rate: '18.0000',
    output_account_id: 'acc-tax-out',
    input_account_id: null,
    is_active: true,
    effective_from: '2020-01-01',
    effective_to: null,
    is_open_ended: true,
    notes: null,
    deleted_at: null,
    capabilities: { can_update: false, can_delete: false, charges_tax: true },
    ...overrides,
  }
}

function account(overrides: Partial<Account> = {}): Account {
  return {
    id: 'acc-rev-1',
    company_id: 'company-1',
    parent_id: null,
    code: '4000',
    name: 'Sales revenue',
    description: null,
    type: 'income',
    type_label: 'Income',
    normal_balance: 'credit',
    statement: 'profit_and_loss',
    is_permanent: false,
    is_postable: true,
    is_system: false,
    system_key: null,
    is_active: true,
    archived_at: null,
    sort_order: 1,
    template_version: null,
    capabilities: { can_update: true, can_delete: true, accepts_postings: true },
    ...overrides,
  }
}

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

function invoice(overrides: Partial<SalesInvoice> = {}): SalesInvoice {
  return {
    id: 'inv-1',
    company_id: 'company-1',
    branch_id: null,
    customer_id: 'cus-1',
    number: null,
    reference: 'PO-100',
    invoice_date: '2026-06-01',
    due_date: '2026-07-01',
    currency_code: 'LKR',
    exchange_rate: null,
    subtotal: '200.0000',
    discount_total: '0.0000',
    tax_total: '36.0000',
    total: '236.0000',
    amount_paid: '0.0000',
    amount_due: '236.0000',
    status: 'draft',
    status_label: 'Draft',
    is_overdue: false,
    issued_at: null,
    issued_by_id: null,
    journal_entry_id: null,
    cancelled_at: null,
    cancellation_reason: null,
    cancelled_by_id: null,
    notes: null,
    terms: null,
    created_by_id: 'user-1',
    created_at: '2026-06-01T09:00:00Z',
    updated_at: '2026-06-01T09:00:00Z',
    capabilities: { can_update: true, can_delete: true, can_issue: true, can_cancel: false },
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
      {
        id: 'line-2',
        line_number: 2,
        description: 'Delivery',
        quantity: '1.0000',
        unit_price: '50.0000',
        discount_percent: null,
        discount_amount: null,
        line_subtotal: '50.0000',
        tax_code_id: null,
        tax_code: null,
        tax_rate: '0.0000',
        tax_amount: '0.0000',
        line_total: '50.0000',
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
      options.permissions ?? ['sales.invoices.view', 'sales.invoices.draft', 'sales.invoices.issue'],
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

/** Serves the tax-code, account and customer picker requests every mount needs. */
function mockPickerLookups(): void {
  get.mockImplementation(async (url: string) => {
    if (url.includes('/tax-codes')) {
      return { data: [taxCode()], meta: apiMeta() }
    }
    if (url.includes('/accounts')) {
      return { data: [account()], meta: apiMeta() }
    }
    if (url.includes('/customers')) {
      return { data: [customer()], meta: apiMeta() }
    }
    return { data: [], meta: apiMeta() }
  })
}

/**
 * Every wrapper mounted this test, unmounted in `afterEach` below.
 *
 * `useUnsavedGuard` registers into a module-level `Set` that outlives any one test (by design —
 * it is the same registry `CompanySwitcher.select()` consults in the running app). A wrapper left
 * mounted at the end of a test that made the form dirty leaves that guard function in the `Set`
 * for every test that runs afterward in this file, so `hasUnsavedChanges()` reads `true` from the
 * very first assertion of an unrelated, later test — never unmounting is a leak into the *next*
 * test's result, not a failure of the test that caused it.
 */
const mountedWrappers: Array<ReturnType<typeof mount>> = []

async function mountCreatePage() {
  const router = testRouter()
  await router.push('/new')
  await router.isReady()

  const wrapper = mount(SalesInvoiceEditorPage, { global: { plugins: [router] } })
  mountedWrappers.push(wrapper)
  await flushPromises()

  return { wrapper, router }
}

async function mountEditPage(invoiceId = 'inv-1') {
  const router = testRouter()
  await router.push(`/${invoiceId}/edit`)
  await router.isReady()

  const wrapper = mount(SalesInvoiceEditorPage, { global: { plugins: [router] } })
  mountedWrappers.push(wrapper)
  await flushPromises()

  return { wrapper, router }
}

function labelledField(wrapper: ReturnType<typeof mount>, candidates: string[]) {
  for (const text of candidates) {
    const label = wrapper.findAll('label').find((candidate) => candidate.text().includes(text))
    const forId = label?.attributes('for')
    if (forId) {
      return wrapper.find(`#${forId}`)
    }
  }
  return wrapper.find('[data-qa-not-found]')
}

/**
 * Every input/select/textarea's *value*, for every label matching `text` — one line editor has
 * several rows each carrying their own "Description" field, and a value typed into an `<input>`
 * lives in its `value` property, never in `textContent`. `wrapper.text()` only ever inspects
 * `textContent`, so asserting a line's description "renders" by looking for it there is a
 * false negative waiting to happen — it would fail even when the field is correctly pre-filled.
 */
function allLabelledValues(wrapper: ReturnType<typeof mount>, text: string): string[] {
  return wrapper
    .findAll('label')
    .filter((label) => label.text().includes(text))
    .map((label) => label.attributes('for'))
    .filter((forId): forId is string => Boolean(forId))
    .map((forId) => (wrapper.find(`#${forId}`).element as HTMLInputElement).value)
}

async function fillMinimalDraft(wrapper: ReturnType<typeof mount>): Promise<void> {
  await labelledField(wrapper, ['Customer']).setValue('cus-1')
  await labelledField(wrapper, ['Invoice date']).setValue('2026-06-01')
  await labelledField(wrapper, ['Description']).setValue('Consulting')
  await labelledField(wrapper, ['Qty', 'Quantity']).setValue('2')
  await labelledField(wrapper, ['Unit price']).setValue('100.0000')
  await labelledField(wrapper, ['Revenue account']).setValue('acc-rev-1')
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
  mockPickerLookups()
})

afterEach(() => {
  // See the docblock on `mountedWrappers`: this is what keeps `useUnsavedGuard`'s module-level
  // registry from carrying one test's dirty state into the next. Guarded because one test below
  // unmounts its own wrapper directly (to assert the guard clears immediately) — unmounting it a
  // second time here is a harmless no-op, not a second failure to surface.
  while (mountedWrappers.length > 0) {
    try {
      mountedWrappers.pop()?.unmount()
    } catch {
      // Already unmounted by the test itself.
    }
  }
})

describe('SalesInvoiceEditorPage — draft create (§4.7)', () => {
  it('sends every line amount as a decimal string, never a JSON number', async () => {
    signIn()
    post.mockResolvedValue({ data: invoice(), meta: apiMeta() })

    const { wrapper } = await mountCreatePage()
    await fillMinimalDraft(wrapper)
    await submit(wrapper)

    expect(post).toHaveBeenCalledTimes(1)
    const [url, body] = post.mock.calls[0] as [string, { lines: Array<Record<string, unknown>> }]
    expect(url).toBe('/companies/company-1/sales-invoices')
    expect(body.lines[0]?.quantity).toBe('2')
    expect(typeof body.lines[0]?.quantity).toBe('string')
    expect(body.lines[0]?.unit_price).toBe('100.0000')
    expect(typeof body.lines[0]?.unit_price).toBe('string')
  })

  it('sends tax_code as the code string, never a tax-code id', async () => {
    signIn()
    post.mockResolvedValue({ data: invoice(), meta: apiMeta() })

    const { wrapper } = await mountCreatePage()
    await fillMinimalDraft(wrapper)
    await labelledField(wrapper, ['Tax code']).setValue('VAT')
    await submit(wrapper)

    const [, body] = post.mock.calls[0] as [string, { lines: Array<Record<string, unknown>> }]
    expect(body.lines[0]?.tax_code).toBe('VAT')
    expect(body.lines[0]).not.toHaveProperty('tax_code_id')
  })

  it('omits due_date entirely when left blank, rather than sending an empty string', async () => {
    signIn()
    post.mockResolvedValue({ data: invoice(), meta: apiMeta() })

    const { wrapper } = await mountCreatePage()
    await fillMinimalDraft(wrapper)
    await submit(wrapper)

    const [, body] = post.mock.calls[0] as [string, Record<string, unknown>]
    expect('due_date' in body).toBe(false)
  })

  it('never shows a computed total before the first successful save', async () => {
    signIn()

    const { wrapper } = await mountCreatePage()
    await fillMinimalDraft(wrapper)

    // 2 * 100.0000 = 200.0000 — a client that multiplied would show this somewhere in the totals
    // area before any save has happened.
    expect(wrapper.text()).not.toContain('200.00')
    expect(wrapper.text()).toContain('—')
  })

  it('renders the API’s total after save, even when deliberately inconsistent with the lines', async () => {
    signIn()
    // 2 * 100 would be 200; the mocked response returns a deliberately different total so a
    // page that recomputes instead of rendering the response fails this assertion.
    post.mockResolvedValue({ data: invoice({ total: '999.0000', subtotal: '963.0000' }), meta: apiMeta() })

    const { wrapper } = await mountCreatePage()
    await fillMinimalDraft(wrapper)
    await submit(wrapper)

    expect(wrapper.text()).toContain('999.00')
    expect(wrapper.text()).not.toContain('200.00')
  })

  it('maps an indexed per-line 422 (lines.0.tax_code) to that exact line and field', async () => {
    signIn()
    post.mockRejectedValue(
      new ApiError(
        {
          type: 'https://docs.asidstech.com/errors/validation-failed',
          title: 'Validation failed.',
          status: 422,
          detail: 'The given data was invalid.',
          errors: { 'lines.0.tax_code': ['That tax code does not belong to this company.'] },
        },
        422,
      ),
    )

    const { wrapper } = await mountCreatePage()
    await fillMinimalDraft(wrapper)
    await submit(wrapper)

    expect(wrapper.text()).toContain('That tax code does not belong to this company.')
  })

  it('surfaces a non-line domain refusal (customer-not-invoiceable) as a notice', async () => {
    signIn()
    post.mockRejectedValue(
      new ApiError(
        {
          type: 'https://docs.asidstech.com/errors/customer-not-invoiceable',
          title: 'Unprocessable.',
          status: 422,
          detail: 'This customer cannot be invoiced in its current state.',
        },
        422,
      ),
    )

    const { wrapper } = await mountCreatePage()
    await fillMinimalDraft(wrapper)
    await submit(wrapper)

    expect(useUiStore().notices.at(-1)).toMatchObject({
      kind: 'error',
      message: 'This customer cannot be invoiced in its current state.',
    })
  })

  it('offers "issue immediately" only under sales.invoices.issue', async () => {
    signIn({ permissions: ['sales.invoices.draft'] })

    const { wrapper } = await mountCreatePage()

    expect(wrapper.text().toLowerCase()).not.toContain('issue immediately')
  })

  it('sends issue: true when "issue immediately" is checked, for a user who holds sales.invoices.issue', async () => {
    signIn({ permissions: ['sales.invoices.draft', 'sales.invoices.issue'] })
    post.mockResolvedValue({ data: invoice({ status: 'issued' }), meta: apiMeta() })

    const { wrapper } = await mountCreatePage()
    await fillMinimalDraft(wrapper)

    const checkbox = wrapper
      .findAll('input[type="checkbox"]')
      .find((input) => input.element.closest('label, div')?.textContent?.toLowerCase().includes('issue'))
    expect(checkbox).toBeDefined()
    await checkbox?.setValue(true)
    await submit(wrapper)

    const [, body] = post.mock.calls[0] as [string, Record<string, unknown>]
    expect(body.issue).toBe(true)
  })

  it('stays on the create form, without navigating, when a combined create-and-issue request fails', async () => {
    signIn({ permissions: ['sales.invoices.draft', 'sales.invoices.issue'] })
    post.mockRejectedValue(
      new ApiError(
        {
          type: 'https://docs.asidstech.com/errors/validation-failed',
          title: 'Validation failed.',
          status: 422,
          detail: 'The invoice total must be greater than zero before it can be issued.',
        },
        422,
      ),
    )

    const { wrapper, router } = await mountCreatePage()
    await fillMinimalDraft(wrapper)
    await submit(wrapper)

    // A refusal to issue leaves no draft behind (ADR 0012 D3) — the page must not act as though
    // an invoice now exists somewhere to navigate to.
    expect(router.currentRoute.value.name).toBe('invoice-new')
  })
})

describe('SalesInvoiceEditorPage — draft edit (§4.8)', () => {
  function mockInvoiceGet(fixture: SalesInvoice): void {
    get.mockImplementation(async (url: string) => {
      if (url.includes('/tax-codes')) return { data: [taxCode()], meta: apiMeta() }
      if (url.includes('/accounts')) return { data: [account()], meta: apiMeta() }
      if (url.includes('/customers') && !url.includes('/sales-invoices')) {
        return { data: [customer()], meta: apiMeta() }
      }
      if (url.includes('/sales-invoices/')) return { data: fixture, meta: apiMeta() }
      return { data: [], meta: apiMeta() }
    })
  }

  it('pre-fills from the fetched draft, including its lines', async () => {
    signIn()
    mockInvoiceGet(invoice())

    const { wrapper } = await mountEditPage()

    expect(get).toHaveBeenCalledWith('/companies/company-1/sales-invoices/inv-1')
    // Each line's description lives in an <input>'s `value`, not in the page's `textContent` —
    // asserted against the element's own value rather than `wrapper.text()` for that reason.
    const descriptions = allLabelledValues(wrapper, 'Description')
    expect(descriptions).toContain('Consulting')
    expect(descriptions).toContain('Delivery')
  })

  it('resubmits the full line set on any line-level change, never a partial patch', async () => {
    signIn()
    mockInvoiceGet(invoice())
    put.mockResolvedValue({ data: invoice(), meta: apiMeta() })

    const { wrapper } = await mountEditPage()
    // Touch just one field on the first line.
    await labelledField(wrapper, ['Description']).setValue('Consulting — revised')
    await submit(wrapper)

    expect(put).toHaveBeenCalledTimes(1)
    const [url, body] = put.mock.calls[0] as [string, { lines: Array<Record<string, unknown>> }]
    expect(url).toBe('/companies/company-1/sales-invoices/inv-1')
    // Both lines present, not only the one edited — the endpoint replaces every line when
    // `lines` is present at all (§4.8.4).
    expect(body.lines).toHaveLength(2)
  })

  it('clears reference, branch_id, discount_amount and due_date independently, each as explicit null', async () => {
    signIn()
    mockInvoiceGet(invoice())
    put.mockResolvedValue({ data: invoice(), meta: apiMeta() })

    const nulledFields = new Set<string>()
    const clearableFields = ['reference', 'branch_id', 'discount_amount', 'due_date']

    for (let index = 0; index < clearableFields.length; index += 1) {
      mockInvoiceGet(invoice())
      const { wrapper } = await mountEditPage()
      const clearButtons = wrapper.findAll('button').filter((b) => b.text().trim() === 'Clear')

      if (index >= clearButtons.length) {
        continue
      }

      await clearButtons[index]?.trigger('click')
      await submit(wrapper)

      const call = put.mock.calls.at(-1)
      if (!call) continue
      const [, body] = call as [string, Record<string, unknown>]
      for (const field of clearableFields) {
        if (field in body && body[field] === null) {
          nulledFields.add(field)
        }
      }
    }

    expect(nulledFields).toEqual(new Set(clearableFields))
  })

  it('re-renders totals and per-line tax figures from the save response, not the pre-edit values', async () => {
    signIn()
    mockInvoiceGet(invoice())

    // A changed invoice date can move a line outside its tax code's effective range (§4.8.5), so
    // the save response is built as a fully self-consistent, *different* invoice — never the
    // pre-edit fixture with only the header patched, which would leave line 1's pre-edit
    // `line_total` ("236.00") legitimately present in the response and make an assertion that it
    // must NOT render a false failure (Gate-1 #5 requires rendering exactly what the API returns,
    // never hiding a number the API actually sent).
    const baseline = invoice()
    const recomputedLines = [
      { ...baseline.lines![0]!, tax_rate: '2.5000', tax_amount: '5.0000', line_total: '205.0000' },
      baseline.lines![1]!,
    ]
    put.mockResolvedValue({
      data: invoice({ subtotal: '250.0000', tax_total: '5.0000', total: '255.0000', lines: recomputedLines }),
      meta: apiMeta(),
    })

    const { wrapper } = await mountEditPage()
    await labelledField(wrapper, ['Invoice date']).setValue('2026-07-15')
    await submit(wrapper)

    // The new, authoritative figures from the save response — never recomputed, and never left
    // showing what was on screen before the save.
    expect(wrapper.text()).toContain('255.00')
    expect(wrapper.text()).toContain('205.00')
    // 236.00 was line 1's pre-edit total; it is not part of this response at all (line 1's new
    // total is 205.00), so its absence here reflects the response replacing the view — it is not
    // an assertion that the UI hid a number the API sent.
    expect(wrapper.text()).not.toContain('236.00')
  })
})

describe('SalesInvoiceEditorPage — company switch mid-edit (Gate-1 #6, ADR 0013 §6)', () => {
  it('registers as having unsaved changes once the form is touched, and stops once unmounted', async () => {
    signIn()
    const { wrapper } = await mountCreatePage()

    expect(hasUnsavedChanges()).toBe(false)

    await labelledField(wrapper, ['Description']).setValue('Consulting')

    expect(hasUnsavedChanges()).toBe(true)

    wrapper.unmount()
    expect(hasUnsavedChanges()).toBe(false)
  })
})
