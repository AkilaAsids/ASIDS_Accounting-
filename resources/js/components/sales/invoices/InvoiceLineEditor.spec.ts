import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import type * as ApiClientModule from '@/api/client'
import InvoiceLineEditor from '@/components/sales/invoices/InvoiceLineEditor.vue'
import { blankLine, type LineDraft } from '@/components/sales/invoices/lineDraft'

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof ApiClientModule>('@/api/client')
  return {
    ...actual,
    api: { ...actual.api, get: vi.fn().mockResolvedValue({ data: [], meta: { request_id: 'r', api_version: '1' } }) },
  }
})

function mountEditor(initialLines: LineDraft[] = [blankLine()], lineErrors: Record<number, Record<string, string>> = {}) {
  const Harness = defineComponent({
    components: { InvoiceLineEditor },
    setup() {
      const lines = ref<LineDraft[]>(initialLines)
      return { lines, lineErrors }
    },
    template: `<InvoiceLineEditor v-model:lines="lines" :accounts="[]" company-id="company-1" :line-errors="lineErrors" />`,
  })
  return mount(Harness)
}

describe('InvoiceLineEditor', () => {
  it('renders one row per line and keeps the wide table reachable by keyboard', () => {
    const wrapper = mountEditor([blankLine(), blankLine()])

    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    expect(wrapper.find('[role="region"][aria-label="Invoice lines"]').attributes('tabindex')).toBe('0')
  })

  it('"Add a line" appends a blank line', async () => {
    const wrapper = mountEditor([blankLine()])

    await wrapper.find('button.inline-flex, button').exists()
    const addButton = wrapper.findAll('button').find((b) => b.text() === 'Add a line')
    await addButton?.trigger('click')

    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
  })

  it('disables Remove on the only remaining line, and removing drops it back to none left disabled once two exist', async () => {
    const wrapper = mountEditor([blankLine()])

    const soleRemove = wrapper.find('button[aria-label="Remove line 1"]')
    expect(soleRemove.attributes('disabled')).toBeDefined()

    const addButton = wrapper.findAll('button').find((b) => b.text() === 'Add a line')
    await addButton?.trigger('click')

    const removeButtons = wrapper.findAll('button[aria-label^="Remove line"]')
    expect(removeButtons).toHaveLength(2)
    expect(removeButtons[0]?.attributes('disabled')).toBeUndefined()

    await removeButtons[0]?.trigger('click')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.find('button[aria-label="Remove line 1"]').attributes('disabled')).toBeDefined()
  })

  it('shows a focusable error summary above the lines, one entry per offending line/field', () => {
    const wrapper = mountEditor([blankLine(), blankLine()], {
      0: { tax_code: 'That tax code does not belong to this company.' },
    })

    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Line 1: tax code — That tax code does not belong to this company.')
  })
})
