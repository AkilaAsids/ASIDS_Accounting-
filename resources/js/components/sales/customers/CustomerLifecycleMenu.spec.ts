import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import CustomerLifecycleMenu from '@/components/sales/customers/CustomerLifecycleMenu.vue'
import type { Customer } from '@/types/domain'

/**
 * The lifecycle menu component in isolation — the QA acceptance specs
 * (`CustomersListPage.spec.ts`, `CustomerDetailPage.spec.ts`) already prove the double gate and
 * the confirmation tiers end to end through a page; these specs pin the component's own
 * contract more narrowly: it never calls `api` itself, only emits once a confirmation actually
 * happens, and never emits when a confirmation is declined.
 */
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

describe('CustomerLifecycleMenu', () => {
  it('shows Archive and Deactivate for an active customer with can_update', () => {
    const wrapper = mount(CustomerLifecycleMenu, { props: { customer: customer() } })

    const labels = wrapper.findAll('button').map((button) => button.text().trim())
    expect(labels).toContain('Archive')
    expect(labels).toContain('Deactivate')
    expect(labels).not.toContain('Restore')
    expect(labels).not.toContain('Reactivate')
  })

  it('shows Restore instead of Archive for an archived customer', () => {
    const wrapper = mount(CustomerLifecycleMenu, {
      props: { customer: customer({ status: 'archived' }) },
    })

    const labels = wrapper.findAll('button').map((button) => button.text().trim())
    expect(labels).toContain('Restore')
    expect(labels).not.toContain('Archive')
    expect(labels).not.toContain('Deactivate')
  })

  it('shows Reactivate instead of Deactivate for an inactive customer', () => {
    const wrapper = mount(CustomerLifecycleMenu, {
      props: { customer: customer({ status: 'inactive' }) },
    })

    const labels = wrapper.findAll('button').map((button) => button.text().trim())
    expect(labels).toContain('Reactivate')
    expect(labels).not.toContain('Archive')
    expect(labels).not.toContain('Deactivate')
  })

  it('renders none of the reversible actions and no overflow trigger when capabilities are false', () => {
    const wrapper = mount(CustomerLifecycleMenu, {
      props: {
        customer: customer({ capabilities: { can_update: false, can_delete: false, accepts_new_invoices: true } }),
      },
    })

    const labels = wrapper.findAll('button').map((button) => button.text().trim())
    expect(labels).not.toContain('Archive')
    expect(labels).not.toContain('Deactivate')
    expect(wrapper.find('button[aria-haspopup="menu"]').exists()).toBe(false)
  })

  it('does not emit archive when the Tier-1 confirm is declined', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const wrapper = mount(CustomerLifecycleMenu, { props: { customer: customer() } })

    const archiveButton = wrapper.findAll('button').find((b) => b.text().trim() === 'Archive')
    await archiveButton?.trigger('click')

    expect(wrapper.emitted('archive')).toBeUndefined()
  })

  it('emits archive naming the customer once the Tier-1 confirm is accepted', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(CustomerLifecycleMenu, { props: { customer: customer() } })

    const archiveButton = wrapper.findAll('button').find((b) => b.text().trim() === 'Archive')
    await archiveButton?.trigger('click')

    expect(confirmSpy.mock.calls[0]?.[0]).toContain('Silva Traders')
    expect(wrapper.emitted('archive')).toHaveLength(1)
  })

  it('does not emit restore when the Tier-1 confirm is declined', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const wrapper = mount(CustomerLifecycleMenu, { props: { customer: customer({ status: 'archived' }) } })

    const restoreButton = wrapper.findAll('button').find((b) => b.text().trim() === 'Restore')
    await restoreButton?.trigger('click')

    expect(wrapper.emitted('restore')).toBeUndefined()
  })

  it('emits restore naming the customer once the Tier-1 confirm is accepted', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(CustomerLifecycleMenu, { props: { customer: customer({ status: 'archived' }) } })

    const restoreButton = wrapper.findAll('button').find((b) => b.text().trim() === 'Restore')
    await restoreButton?.trigger('click')

    expect(confirmSpy.mock.calls[0]?.[0]).toContain('Silva Traders')
    expect(wrapper.emitted('restore')).toHaveLength(1)
  })

  it('does not emit deactivate when the Tier-1 confirm is declined, and does once accepted', async () => {
    vi.spyOn(window, 'confirm').mockReturnValueOnce(false)
    const wrapper = mount(CustomerLifecycleMenu, { props: { customer: customer() } })
    const deactivateButton = wrapper.findAll('button').find((b) => b.text().trim() === 'Deactivate')

    await deactivateButton?.trigger('click')
    expect(wrapper.emitted('deactivate')).toBeUndefined()

    vi.spyOn(window, 'confirm').mockReturnValueOnce(true)
    await deactivateButton?.trigger('click')
    expect(wrapper.emitted('deactivate')).toHaveLength(1)
  })

  it('emits reactivate naming the customer once the Tier-1 confirm is accepted', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(CustomerLifecycleMenu, { props: { customer: customer({ status: 'inactive' }) } })

    const reactivateButton = wrapper.findAll('button').find((b) => b.text().trim() === 'Reactivate')
    await reactivateButton?.trigger('click')

    expect(confirmSpy.mock.calls[0]?.[0]).toContain('Silva Traders')
    expect(wrapper.emitted('reactivate')).toHaveLength(1)
  })

  it('closes the delete dialog without emitting delete when the reader cancels it', async () => {
    const wrapper = mount(CustomerLifecycleMenu, { props: { customer: customer() } })

    await wrapper.find('button[aria-haspopup="menu"]').trigger('click')
    await wrapper.find('[role="menuitem"]').trigger('click')
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)

    // The Cancel button is the first of the two — re-queried fresh, not cached, per this
    // file's own documented Teleport caveat.
    await wrapper.find('[role="dialog"]').findAll('button')[0]?.trigger('click')

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(wrapper.emitted('delete')).toBeUndefined()
  })

  it('only emits delete once the typed-confirm token matches the customer code', async () => {
    // Every lookup below is freshly re-queried from `wrapper` rather than cached, per this
    // project's own documented `ConfirmDialog.spec.ts` note: the dialog renders through a
    // `<Teleport>`, and this project's global `Teleport: true` test stub
    // (`tests/Support/vitest.setup.ts`) replaces the teleported subtree's root element on every
    // reactive update — a `DOMWrapper` captured beforehand does not reliably reflect a change
    // made afterwards.
    const wrapper = mount(CustomerLifecycleMenu, { props: { customer: customer() } })

    await wrapper.find('button[aria-haspopup="menu"]').trigger('click')
    await wrapper.find('[role="menuitem"]').trigger('click')

    expect(wrapper.find('[role="dialog"]').findAll('button').at(-1)?.attributes('disabled')).toBeDefined()

    await wrapper.find('[role="dialog"] input[type="text"]').setValue('wrong-code')
    expect(wrapper.find('[role="dialog"]').findAll('button').at(-1)?.attributes('disabled')).toBeDefined()
    expect(wrapper.emitted('delete')).toBeUndefined()

    await wrapper.find('[role="dialog"] input[type="text"]').setValue('C-0001')
    expect(wrapper.find('[role="dialog"]').findAll('button').at(-1)?.attributes('disabled')).toBeUndefined()

    await wrapper.find('[role="dialog"]').findAll('button').at(-1)?.trigger('click')
    expect(wrapper.emitted('delete')).toHaveLength(1)
  })
})
