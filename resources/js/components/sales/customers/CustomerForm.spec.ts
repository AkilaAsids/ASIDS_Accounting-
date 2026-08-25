import { afterEach, describe, expect, it } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import CustomerForm from '@/components/sales/customers/CustomerForm.vue'
import type { Customer } from '@/types/domain'

let wrapper: VueWrapper | undefined

afterEach(() => {
  wrapper?.unmount()
  wrapper = undefined
})

/**
 * `CustomerForm`'s own contract in isolation, one level below the QA page specs
 * (`CustomerFormPage.spec.ts`) that already prove this end to end through the routed page.
 * These specs pin the clear-vs-omit payload builder directly — the single most
 * acceptance-critical piece of logic in the customer lane (requirements §4.3.1/§4.3.2) — plus
 * the dirty-tracking contract `useUnsavedGuard` depends on, without needing a router or the
 * API mocked.
 */
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

function labelledInput(wrapper: ReturnType<typeof mount>, labelText: string) {
  const label = wrapper.findAll('label').find((candidate) => candidate.text().includes(labelText))
  const forId = label?.attributes('for')
  return forId ? wrapper.find(`#${forId}`) : wrapper.find('[data-qa-not-found]')
}

describe('CustomerForm', () => {
  describe('create mode (no customer prop)', () => {
    it('emits only the fields the reader actually filled in, plus is_vat_registered/payment_terms_days defaults', async () => {
      const wrapper = mount(CustomerForm)

      await labelledInput(wrapper, 'Name').setValue('New Customer')
      await wrapper.find('form').trigger('submit')

      const [payload] = wrapper.emitted('submit')?.[0] as [Record<string, unknown>]
      expect(payload.name).toBe('New Customer')
      expect(payload.is_vat_registered).toBe(false)
      expect(payload.payment_terms_days).toBe(30)
      expect('status' in payload).toBe(false)
      expect('credit_limit' in payload).toBe(false)
      expect('code' in payload).toBe(false)
    })

    it('falls back to the 30-day default when payment terms is set to something non-numeric', async () => {
      wrapper = mount(CustomerForm)

      await labelledInput(wrapper, 'Name').setValue('New Customer')
      await labelledInput(wrapper, 'Payment terms (days)').setValue('not-a-number')
      await wrapper.find('form').trigger('submit')

      const [payload] = wrapper.emitted('submit')?.[0] as [Record<string, unknown>]
      expect(payload.payment_terms_days).toBe(30)
    })

    it('renders no "Clear" affordance at all — those exist only in edit mode', () => {
      const wrapper = mount(CustomerForm)

      expect(wrapper.findAll('button').filter((b) => b.text().trim() === 'Clear')).toHaveLength(0)
    })

    it('reports dirty as soon as any field changes', async () => {
      const wrapper = mount(CustomerForm)

      expect(wrapper.emitted('update:dirty')).toBeUndefined()

      await labelledInput(wrapper, 'Name').setValue('New Customer')

      expect(wrapper.emitted('update:dirty')?.at(-1)).toEqual([true])
    })

    it('emits every optional field the reader filled in, trimmed, alongside the required ones', async () => {
      wrapper = mount(CustomerForm)

      await labelledInput(wrapper, 'Name').setValue('New Customer')
      await labelledInput(wrapper, 'Code').setValue(' NC-1 ')
      await labelledInput(wrapper, 'Legal name').setValue('New Customer Ltd')
      await labelledInput(wrapper, 'Tax identification number').setValue('TIN-1')
      await labelledInput(wrapper, 'VAT registration number').setValue('VAT-1')
      await labelledInput(wrapper, 'Email').setValue('new@customer.test')
      await labelledInput(wrapper, 'Phone').setValue('+94 11 000 0000')
      await labelledInput(wrapper, 'Website').setValue('https://example.test')
      await labelledInput(wrapper, 'Address line 1').setValue('1 Main St')
      await labelledInput(wrapper, 'Address line 2').setValue('Suite 2')
      await labelledInput(wrapper, 'City').setValue('Colombo')
      await labelledInput(wrapper, 'District').setValue('Colombo')
      await labelledInput(wrapper, 'Postal code').setValue('00100')
      await labelledInput(wrapper, 'Country code').setValue('LK')
      await labelledInput(wrapper, 'Credit limit').setValue('10000')
      await labelledInput(wrapper, 'Receivable account').setValue('acc-9')
      await labelledInput(wrapper, 'Branch').setValue('branch-9')
      await wrapper.find('#customer-notes').setValue('Some notes')
      await wrapper.find('form').trigger('submit')

      const [payload] = wrapper.emitted('submit')?.[0] as [Record<string, unknown>]
      expect(payload).toMatchObject({
        name: 'New Customer',
        code: 'NC-1',
        legal_name: 'New Customer Ltd',
        tax_identification_number: 'TIN-1',
        vat_registration_number: 'VAT-1',
        email: 'new@customer.test',
        phone: '+94 11 000 0000',
        website: 'https://example.test',
        address_line_1: '1 Main St',
        address_line_2: 'Suite 2',
        city: 'Colombo',
        district: 'Colombo',
        postal_code: '00100',
        country_code: 'LK',
        credit_limit: '10000',
        receivable_account_id: 'acc-9',
        branch_id: 'branch-9',
        notes: 'Some notes',
      })
    })
  })

  describe('edit mode (customer prop supplied)', () => {
    it('pre-fills every field from the customer', () => {
      const wrapper = mount(CustomerForm, { props: { customer: customer() } })

      expect((labelledInput(wrapper, 'Name').element as HTMLInputElement).value).toBe('Silva Traders')
      expect((labelledInput(wrapper, 'Credit limit').element as HTMLInputElement).value).toBe(
        '5000.0000',
      )
    })

    it('emits an empty-ish payload (no optional keys) when nothing was touched', async () => {
      const wrapper = mount(CustomerForm, { props: { customer: customer() } })

      await wrapper.find('form').trigger('submit')

      const [payload] = wrapper.emitted('submit')?.[0] as [Record<string, unknown>]
      expect('name' in payload).toBe(false)
      expect('credit_limit' in payload).toBe(false)
      expect('branch_id' in payload).toBe(false)
      expect('receivable_account_id' in payload).toBe(false)
      expect('status' in payload).toBe(false)
    })

    it('exposes exactly three Clear affordances, for branch_id/receivable_account_id/credit_limit', () => {
      const wrapper = mount(CustomerForm, { props: { customer: customer() } })

      expect(wrapper.findAll('button').filter((b) => b.text().trim() === 'Clear')).toHaveLength(3)
    })

    it('sends credit_limit as null, and nothing else, when only Clear is used', async () => {
      const wrapper = mount(CustomerForm, { props: { customer: customer() } })

      const clearButtons = wrapper.findAll('button').filter((b) => b.text().trim() === 'Clear')
      const creditLimitClear = clearButtons.find((button) =>
        button.element.previousElementSibling?.textContent?.includes('Credit limit'),
      )
      await (creditLimitClear ?? clearButtons[0])?.trigger('click')
      await wrapper.find('form').trigger('submit')

      const [payload] = wrapper.emitted('submit')?.[0] as [Record<string, unknown>]
      expect(payload.credit_limit).toBeNull()
      expect('branch_id' in payload).toBe(false)
      expect('receivable_account_id' in payload).toBe(false)
      expect('name' in payload).toBe(false)
    })

    it('reports not-dirty again is never re-emitted after a no-op re-render', () => {
      const wrapper = mount(CustomerForm, { props: { customer: customer() } })

      // Untouched from mount: nothing has changed yet, so no dirty event has fired at all.
      expect(wrapper.emitted('update:dirty')).toBeUndefined()
    })

    it('emits every changed field, correctly diffed and trimmed, and omits nothing that changed', async () => {
      wrapper = mount(CustomerForm, {
        props: {
          customer: customer({
            legal_name: 'Old Legal',
            tax_identification_number: 'TIN-OLD',
            vat_registration_number: 'VAT-OLD',
            email: 'old@customer.test',
            phone: '011-000',
            website: 'https://old.test',
            address_line_1: 'Old Addr 1',
            address_line_2: 'Old Addr 2',
            city: 'Old City',
            district: 'Old District',
            postal_code: '00000',
            country_code: 'LK',
            notes: 'Old notes',
          }),
        },
      })

      await labelledInput(wrapper, 'Name').setValue('New Name')
      await labelledInput(wrapper, 'Code').setValue('NEW-CODE')
      await labelledInput(wrapper, 'Legal name').setValue('New Legal')
      await labelledInput(wrapper, 'Tax identification number').setValue('TIN-NEW')
      await labelledInput(wrapper, 'VAT registration number').setValue('VAT-NEW')
      await labelledInput(wrapper, 'Email').setValue('new@customer.test')
      await labelledInput(wrapper, 'Phone').setValue('011-999')
      await labelledInput(wrapper, 'Website').setValue('https://new.test')
      await labelledInput(wrapper, 'Address line 1').setValue('New Addr 1')
      await labelledInput(wrapper, 'Address line 2').setValue('New Addr 2')
      await labelledInput(wrapper, 'City').setValue('New City')
      await labelledInput(wrapper, 'District').setValue('New District')
      await labelledInput(wrapper, 'Postal code').setValue('11111')
      await labelledInput(wrapper, 'Country code').setValue('US')
      await wrapper.find('input[type="checkbox"]').setValue(true)
      await labelledInput(wrapper, 'Payment terms (days)').setValue('45')
      await labelledInput(wrapper, 'Credit limit').setValue('9999')
      await labelledInput(wrapper, 'Receivable account').setValue('acc-new')
      await labelledInput(wrapper, 'Branch').setValue('branch-new')
      await wrapper.find('#customer-notes').setValue('New notes')
      await wrapper.find('form').trigger('submit')

      const [payload] = wrapper.emitted('submit')?.[0] as [Record<string, unknown>]
      expect(payload).toEqual({
        name: 'New Name',
        code: 'NEW-CODE',
        legal_name: 'New Legal',
        tax_identification_number: 'TIN-NEW',
        vat_registration_number: 'VAT-NEW',
        email: 'new@customer.test',
        phone: '011-999',
        website: 'https://new.test',
        address_line_1: 'New Addr 1',
        address_line_2: 'New Addr 2',
        city: 'New City',
        district: 'New District',
        postal_code: '11111',
        country_code: 'US',
        is_vat_registered: true,
        payment_terms_days: 45,
        credit_limit: '9999',
        receivable_account_id: 'acc-new',
        branch_id: 'branch-new',
        notes: 'New notes',
      })
    })

    it('resets original values and cleared flags when the customer prop is swapped for a fresh one', async () => {
      wrapper = mount(CustomerForm, { props: { customer: customer() } })

      const clearButtons = () => wrapper!.findAll('button').filter((b) => b.text().trim() === 'Clear')
      const creditLimitClear = clearButtons().find((button) =>
        button.element.previousElementSibling?.textContent?.includes('Credit limit'),
      )
      await (creditLimitClear ?? clearButtons()[0])?.trigger('click')

      expect(wrapper.emitted('update:dirty')?.at(-1)).toEqual([true])

      // The page mounts this component before its own `load()` for the *next* customer
      // resolves, so `customer` arrives a tick after mount — this is that re-seed.
      await wrapper.setProps({ customer: customer({ id: 'cus-2', code: 'C-0002', credit_limit: null }) })

      // The freshly loaded customer has no credit limit at all, so its Clear affordance is
      // gone entirely (branch_id/receivable_account_id are still non-blank, so their two
      // Clear buttons remain) — and the earlier clear no longer lingers as "dirty".
      expect(clearButtons()).toHaveLength(2)
      expect(wrapper.emitted('update:dirty')?.at(-1)).toEqual([false])

      await wrapper.find('form').trigger('submit')
      const [payload] = wrapper.emitted('submit')?.at(-1) as [Record<string, unknown>]
      expect('credit_limit' in payload).toBe(false)
    })
  })
})
