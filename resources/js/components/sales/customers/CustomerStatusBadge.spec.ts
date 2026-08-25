import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import CustomerStatusBadge from '@/components/sales/customers/CustomerStatusBadge.vue'

/**
 * Renders `status_label` as text-plus-colour, never colour alone (requirements §4.4.2).
 */
describe('CustomerStatusBadge', () => {
  it.each([
    ['active', 'Active', 'text-success'],
    ['inactive', 'Inactive', 'text-warning'],
    ['archived', 'Archived', 'text-content-subtle'],
  ] as const)('renders the %s status as its word, paired with colour', (status, label, colourClass) => {
    const wrapper = mount(CustomerStatusBadge, { props: { status, statusLabel: label } })

    expect(wrapper.text()).toBe(label)
    expect(wrapper.classes()).toContain(colourClass)
  })
})
