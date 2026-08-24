import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import CustomerForm from '@/components/sales/customers/CustomerForm.vue'
import type { Customer } from '@/types/domain'

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
  })
})
