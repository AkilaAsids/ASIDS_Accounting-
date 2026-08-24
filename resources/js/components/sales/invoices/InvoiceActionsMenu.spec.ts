import { beforeEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import InvoiceActionsMenu from '@/components/sales/invoices/InvoiceActionsMenu.vue'
import { useAuthStore } from '@/stores/auth'
import type { SalesInvoice } from '@/types/domain'

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

function invoice(overrides: Partial<SalesInvoice> = {}): SalesInvoice {
  return {
    id: 'inv-1',
    company_id: 'company-1',
    branch_id: null,
    customer_id: 'cus-1',
    number: 'INV-2026-06-0001',
    reference: null,
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
    updated_at: '2026-06-01T09:00:00Z',
    capabilities: { can_update: false, can_delete: false, can_issue: false, can_cancel: false },
    customer: { id: 'cus-1', code: 'C-0001', name: 'Silva Traders' },
    ...overrides,
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  signIn()
})

describe('InvoiceActionsMenu', () => {
  it('renders nothing actionable when every capability is false', () => {
    const wrapper = mount(InvoiceActionsMenu, { props: { invoice: invoice() } })

    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Edit')).toBe(false)
    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Issue invoice')).toBe(false)
    expect(wrapper.findAll('a, button').some((el) => el.text().trim() === 'Cancel invoice')).toBe(false)
    expect(wrapper.find('button[aria-haspopup="menu"]').exists()).toBe(false)
  })

  it('shows Issue invoice behind a confirm and emits "issue" only on confirmation', async () => {
    const wrapper = mount(InvoiceActionsMenu, {
      props: { invoice: invoice({ capabilities: { can_update: false, can_delete: false, can_issue: true, can_cancel: false } }) },
    })

    const issueButton = wrapper.findAll('button').find((b) => b.text().trim() === 'Issue invoice')
    await issueButton?.trigger('click')

    const dialog = wrapper.find('[role="dialog"]')
    expect(dialog.exists()).toBe(true)
    expect(wrapper.emitted('issue')).toBeUndefined()

    await dialog.findAll('button').at(-1)?.trigger('click')

    expect(wrapper.emitted('issue')).toBeTruthy()
  })

  it('shows Cancel invoice behind the reason dialog and emits "cancel" with the reason', async () => {
    const wrapper = mount(InvoiceActionsMenu, {
      props: { invoice: invoice({ capabilities: { can_update: false, can_delete: false, can_issue: false, can_cancel: true } }) },
    })

    const cancelButton = wrapper.findAll('button').find((b) => b.text().trim() === 'Cancel invoice')
    await cancelButton?.trigger('click')

    // Re-queried fresh at each step, never cached: the dialog renders through a `<Teleport>`
    // and a `DOMWrapper` captured before a reactive update does not reliably reflect one made
    // afterwards (the same rule `ConfirmDialog.spec.ts` documents and follows).
    await wrapper.find('[role="dialog"] textarea').setValue('Customer requested it.')
    await wrapper.find('[role="dialog"]').findAll('button').at(-1)?.trigger('click')

    expect(wrapper.emitted('cancel')?.[0]).toEqual(['Customer requested it.'])
  })

  it('puts Delete behind the overflow menu with a checkbox confirm (a draft has no number), and emits "delete"', async () => {
    const wrapper = mount(InvoiceActionsMenu, {
      props: {
        invoice: invoice({
          number: null,
          status: 'draft',
          capabilities: { can_update: true, can_delete: true, can_issue: true, can_cancel: false },
        }),
      },
    })

    const trigger = wrapper.find('button[aria-haspopup="menu"]')
    expect(trigger.exists()).toBe(true)
    await trigger.trigger('click')

    const deleteItem = wrapper.findAll('[role="menuitem"]').find((el) => el.text().includes('Delete'))
    await deleteItem?.trigger('click')

    expect(wrapper.find('[role="dialog"] input[type="text"]').exists()).toBe(false)
    expect(wrapper.find('[role="dialog"]').findAll('button').at(-1)?.attributes('disabled')).toBeDefined()

    // Re-queried fresh, not cached — see the note in the Cancel test above.
    await wrapper.find('[role="dialog"] input[type="checkbox"]').setValue(true)
    expect(wrapper.find('[role="dialog"]').findAll('button').at(-1)?.attributes('disabled')).toBeUndefined()
    await wrapper.find('[role="dialog"]').findAll('button').at(-1)?.trigger('click')

    expect(wrapper.emitted('delete')).toBeTruthy()
  })

  it('shows Edit only when capabilities.can_update is true', () => {
    const withEdit = mount(InvoiceActionsMenu, {
      props: { invoice: invoice({ capabilities: { can_update: true, can_delete: false, can_issue: false, can_cancel: false } }) },
    })
    expect(withEdit.findAll('a, button').some((el) => el.text().trim() === 'Edit')).toBe(true)

    const withoutEdit = mount(InvoiceActionsMenu, { props: { invoice: invoice() } })
    expect(withoutEdit.findAll('a, button').some((el) => el.text().trim() === 'Edit')).toBe(false)
  })
})
