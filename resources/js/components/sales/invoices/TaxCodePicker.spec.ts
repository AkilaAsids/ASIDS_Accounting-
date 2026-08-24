import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type * as ApiClientModule from '@/api/client'
import type { TaxCode } from '@/types/domain'

const get = vi.fn()

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof ApiClientModule>('@/api/client')
  return { ...actual, api: { ...actual.api, get } }
})

const TaxCodePicker = (await import('@/components/sales/invoices/TaxCodePicker.vue')).default

function taxCode(overrides: Partial<TaxCode> = {}): TaxCode {
  return {
    id: 'tax-1',
    company_id: 'company-1',
    code: 'VAT',
    name: 'Standard VAT',
    tax_type: 'vat',
    tax_type_label: 'VAT',
    rate: '18.0000',
    output_account_id: 'acc-1',
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

describe('TaxCodePicker', () => {
  it('loads active tax codes for the given company on mount', async () => {
    get.mockResolvedValue({ data: [taxCode()], meta: { request_id: 'r', api_version: '1' } })

    mount(TaxCodePicker, { props: { companyId: 'company-1', modelValue: '' } })
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/companies/company-1/tax-codes', { active_only: true })
  })

  it('always offers a "No tax" option and shows the rate alongside each code', async () => {
    get.mockResolvedValue({ data: [taxCode()], meta: { request_id: 'r', api_version: '1' } })

    const wrapper = mount(TaxCodePicker, { props: { companyId: 'company-1', modelValue: '' } })
    await flushPromises()

    const options = wrapper.findAll('option')
    expect(options[0]?.text()).toBe('No tax')
    expect(wrapper.text()).toContain('VAT — Standard VAT (18%)')
  })

  it('emits the code string, never the tax-code id, when a selection is made', async () => {
    get.mockResolvedValue({ data: [taxCode()], meta: { request_id: 'r', api_version: '1' } })

    const wrapper = mount(TaxCodePicker, { props: { companyId: 'company-1', modelValue: '' } })
    await flushPromises()

    await wrapper.find('select').setValue('VAT')

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['VAT'])
  })

  it('renders a field-error under the select when given one', async () => {
    get.mockResolvedValue({ data: [], meta: { request_id: 'r', api_version: '1' } })

    const wrapper = mount(TaxCodePicker, {
      props: { companyId: 'company-1', modelValue: '', error: 'That tax code does not belong to this company.' },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('That tax code does not belong to this company.')
  })
})
