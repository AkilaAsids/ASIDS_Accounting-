import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { defineComponent, ref, type PropType } from 'vue'
import type * as ApiClientModule from '@/api/client'
import InvoiceLineEditor from '@/components/sales/invoices/InvoiceLineEditor.vue'
import InvoiceLineRow from '@/components/sales/invoices/InvoiceLineRow.vue'
import { blankLine, type LineDraft } from '@/components/sales/invoices/lineDraft'

let attachedWrapper: VueWrapper | undefined

afterEach(() => {
  attachedWrapper?.unmount()
  attachedWrapper = undefined
})

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof ApiClientModule>('@/api/client')
  return {
    ...actual,
    api: { ...actual.api, get: vi.fn().mockResolvedValue({ data: [], meta: { request_id: 'r', api_version: '1' } }) },
  }
})

/**
 * A single host component definition, reused by every test — `vue/one-component-per-file`
 * flags a second inline component literal in the same test file, so this is the one seam
 * every test mounts through (matching the convention in `useCompanyReload.spec.ts`).
 */
const Harness = defineComponent({
  components: { InvoiceLineEditor },
  props: {
    initialLines: { type: Array as PropType<LineDraft[]>, required: true },
    lineErrors: { type: Object as PropType<Record<number, Record<string, string>>>, default: () => ({}) },
  },
  setup(props) {
    const lines = ref<LineDraft[]>(props.initialLines)
    return { lines }
  },
  template: `<InvoiceLineEditor v-model:lines="lines" :accounts="[]" company-id="company-1" :line-errors="lineErrors" />`,
})

function mountEditor(
  initialLines: LineDraft[] = [blankLine()],
  lineErrors: Record<number, Record<string, string>> = {},
  options: Record<string, unknown> = {},
) {
  return mount(Harness, { props: { initialLines, lineErrors }, ...options })
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

  it('falls back to the raw field name in the error summary when it has no friendly label', () => {
    const wrapper = mountEditor([blankLine()], { 0: { some_unrecognised_field: 'Bad value.' } })

    expect(wrapper.text()).toContain('Line 1: some_unrecognised_field — Bad value.')
  })

  it('treats a missing lineErrors prop as no errors at all', () => {
    const wrapper = mount(InvoiceLineEditor, {
      props: { lines: [blankLine()], accounts: [], companyId: null },
    })

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('ignores a remove attempt when only one line remains, even if triggered directly (the button is disabled in that state)', async () => {
    const wrapper = mountEditor([blankLine()])

    await wrapper.findComponent(InvoiceLineRow).vm.$emit('remove')

    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
  })

  it('focuses and scrolls the offending line into view when its error summary entry is clicked', async () => {
    Element.prototype.scrollIntoView = vi.fn()

    attachedWrapper = mountEditor(
      [blankLine(), blankLine()],
      { 1: { description: 'Required.' } },
      { attachTo: document.body },
    )

    await attachedWrapper.find('[role="alert"] button').trigger('click')
    await flushPromises()

    const secondRowInput = attachedWrapper.findAll('tbody tr')[1]?.find('input, select')
    expect(document.activeElement).toBe(secondRowInput?.element)
  })
})
