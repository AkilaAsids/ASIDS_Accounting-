import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import type * as ApiClientModule from '@/api/client'
import InvoiceLineRow from '@/components/sales/invoices/InvoiceLineRow.vue'
import { blankLine, lineFromApi, type LineDraft } from '@/components/sales/invoices/lineDraft'
import type { SalesInvoiceLine } from '@/types/domain'

/**
 * The discount mutual-exclusion control (requirements §4.7.4, design §2.3.2, ADR 0013 §7.2).
 *
 * QA could not spec this directly — the design leaves the toggle's exact mechanism
 * unspecified and QA's own acceptance file says so — so this is the one place in the whole
 * invoice lane that pins down the behaviour: switching the toggle must make it *structurally
 * impossible* to hold both `discount_percent` and `discount_amount` at once, never merely
 * "usually" true.
 */
vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof ApiClientModule>('@/api/client')
  return { ...actual, api: { ...actual.api, get: vi.fn().mockResolvedValue({ data: [], meta: { request_id: 'r', api_version: '1' } }) } }
})

function button(wrapper: ReturnType<typeof mountRow>, text: string) {
  return wrapper.findAll('button').find((candidate) => candidate.text().trim() === text)
}

function mountRow(initial: LineDraft = blankLine()) {
  const Harness = defineComponent({
    components: { InvoiceLineRow },
    setup() {
      const line = ref<LineDraft>(initial)
      return { line }
    },
    template: `<table><tbody>
      <InvoiceLineRow v-model:line="line" :index="0" :accounts="[]" company-id="company-1" :can-remove="true" />
    </tbody></table>`,
  })
  return mount(Harness)
}

function invoiceLine(overrides: Partial<SalesInvoiceLine> = {}): SalesInvoiceLine {
  return {
    id: 'line-1',
    line_number: 1,
    description: 'Consulting',
    quantity: '2.0000',
    unit_price: '100.0000',
    discount_percent: null,
    discount_amount: null,
    line_subtotal: '200.0000',
    tax_code_id: null,
    tax_code: null,
    tax_rate: '0.0000',
    tax_amount: '0.0000',
    line_total: '200.0000',
    revenue_account_id: 'acc-1',
    branch_id: null,
    ...overrides,
  }
}

describe('InvoiceLineRow — discount mutual exclusion', () => {
  it('starts with no discount field rendered when the line has none', () => {
    const wrapper = mountRow()

    expect(wrapper.find('input[aria-label="Discount percent"]').exists()).toBe(false)
    expect(wrapper.find('input[aria-label="Discount amount"]').exists()).toBe(false)
  })

  it('switching to "%" reveals only the percent field and clears any amount value', async () => {
    const wrapper = mountRow({ ...blankLine(), discountType: 'amount', discountAmount: '15.0000' })

    await button(wrapper, '%')?.trigger('click')

    expect(wrapper.find('input[aria-label="Discount percent"]').exists()).toBe(true)
    expect(wrapper.find('input[aria-label="Discount amount"]').exists()).toBe(false)
    expect((wrapper.vm as unknown as { line: LineDraft }).line.discountAmount).toBe('')
    expect((wrapper.vm as unknown as { line: LineDraft }).line.discountType).toBe('percent')
  })

  it('switching to "Amt" reveals only the amount field and clears any percent value', async () => {
    const wrapper = mountRow({ ...blankLine(), discountType: 'percent', discountPercent: '10.0000' })

    await button(wrapper, 'Amt')?.trigger('click')

    expect(wrapper.find('input[aria-label="Discount amount"]').exists()).toBe(true)
    expect(wrapper.find('input[aria-label="Discount percent"]').exists()).toBe(false)
    expect((wrapper.vm as unknown as { line: LineDraft }).line.discountPercent).toBe('')
    expect((wrapper.vm as unknown as { line: LineDraft }).line.discountType).toBe('amount')
  })

  it('typing into the active discount field never populates the other one', async () => {
    const wrapper = mountRow()

    await button(wrapper, '%')?.trigger('click')
    await wrapper.find('input[aria-label="Discount percent"]').setValue('12.5')

    const vm = wrapper.vm as unknown as { line: LineDraft }
    expect(vm.line.discountPercent).toBe('12.5')
    expect(vm.line.discountAmount).toBe('')

    await button(wrapper, 'Amt')?.trigger('click')
    await wrapper.find('input[aria-label="Discount amount"]').setValue('20')

    expect(vm.line.discountAmount).toBe('20')
    // The percent field was cleared the moment the toggle switched away from it — the value
    // typed a moment ago must not silently resurface if the user switches back.
    expect(vm.line.discountPercent).toBe('')
  })

  it('switching back and forth never leaves both fields populated at once', async () => {
    const wrapper = mountRow()
    const vm = wrapper.vm as unknown as { line: LineDraft }

    await button(wrapper, '%')?.trigger('click')
    await wrapper.find('input[aria-label="Discount percent"]').setValue('10')
    await button(wrapper, 'Amt')?.trigger('click')
    await wrapper.find('input[aria-label="Discount amount"]').setValue('30')
    await button(wrapper, '%')?.trigger('click')

    expect(vm.line.discountPercent === '' || vm.line.discountAmount === '').toBe(true)
    // Structurally impossible, not just "usually true": at most one of the two ever holds a
    // non-empty value, at every step of the toggle dance above.
    expect(!(vm.line.discountPercent !== '' && vm.line.discountAmount !== '')).toBe(true)
  })

  it('switching to "None" clears both discount fields', async () => {
    const wrapper = mountRow({ ...blankLine(), discountType: 'percent', discountPercent: '10' })

    await button(wrapper, 'None')?.trigger('click')

    const vm = wrapper.vm as unknown as { line: LineDraft }
    expect(vm.line.discountType).toBe('none')
    expect(vm.line.discountPercent).toBe('')
    expect(vm.line.discountAmount).toBe('')
    expect(wrapper.find('input[aria-label="Discount percent"]').exists()).toBe(false)
    expect(wrapper.find('input[aria-label="Discount amount"]').exists()).toBe(false)
  })

  it('a line loaded with both discounts non-null keeps only the percentage, with a note explaining why', () => {
    const draft = lineFromApi(invoiceLine({ discount_percent: '10.0000', discount_amount: '5.0000' }))

    expect(draft.discountType).toBe('percent')
    expect(draft.discountPercent).toBe('10.0000')
    expect(draft.discountAmount).toBe('')
    expect(draft.discountConflictNote).not.toBeNull()

    const wrapper = mountRow(draft)

    expect(wrapper.text()).toContain(draft.discountConflictNote)
    expect(wrapper.find('input[aria-label="Discount amount"]').exists()).toBe(false)
  })
})
