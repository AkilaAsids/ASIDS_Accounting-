import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import InvoiceTotals from '@/components/sales/invoices/InvoiceTotals.vue'
import { useAuthStore } from '@/stores/auth'

function signIn(): void {
  useAuthStore().$patch({
    initialised: true,
    user: { id: 'user-1', full_name: 'Kumari Silva', email: 'k@acme.test', is_owner: false },
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
  } as never)
}

beforeEach(() => {
  setActivePinia(createPinia())
  signIn()
})

describe('InvoiceTotals', () => {
  it('shows an em dash for every figure, plus the pre-save hint, before any total is known', () => {
    const wrapper = mount(InvoiceTotals, {
      props: { subtotal: null, discountTotal: null, taxTotal: null, total: null },
    })

    expect(wrapper.text()).toContain('—')
    expect(wrapper.text()).not.toContain('0.00')
    expect(wrapper.text()).toContain('Totals finalise when you save.')
  })

  it('renders the API’s figures verbatim once known, never a recomputed number', () => {
    const wrapper = mount(InvoiceTotals, {
      props: { subtotal: '963.0000', discountTotal: '36.0000', taxTotal: '72.0000', total: '999.0000' },
    })

    expect(wrapper.text()).toContain('999.00')
    expect(wrapper.text()).toContain('963.00')
    expect(wrapper.text()).toContain('Totals shown are as saved. Editing a line will need saving again to update them.')
  })

  it('shows amount paid/due only in view mode, and no hint at all there', () => {
    const wrapper = mount(InvoiceTotals, {
      props: {
        subtotal: '200.0000',
        discountTotal: '0.0000',
        taxTotal: '36.0000',
        total: '236.0000',
        amountPaid: '0.0000',
        amountDue: '236.0000',
        mode: 'view',
      },
    })

    expect(wrapper.text()).toContain('Amount due')
    expect(wrapper.text()).not.toContain('finalise')
    expect(wrapper.text()).not.toContain('as saved')
  })
})
