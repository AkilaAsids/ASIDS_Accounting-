import { describe, expect, it } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'

/**
 * The shared confirm dialog, covering the two tiers above `window.confirm` (Gate-1 decision
 * #2): a reason-gated modal for issue/cancel, and a typed-or-checked modal for hard delete.
 * The property worth pinning per tier is exactly the one that gates the danger button —
 * everything else is markup.
 *
 * Buttons are looked up freshly by label at each assertion rather than cached once, because
 * the dialog renders through a `<Teleport>` and a `DOMWrapper` captured before a reactive
 * update does not reliably reflect one made afterwards.
 */
function button(wrapper: VueWrapper, label: string) {
  return wrapper.findAll('button').find((candidate) => candidate.text() === label)
}

describe('ConfirmDialog', () => {
  it('renders nothing while closed', () => {
    const wrapper = mount(ConfirmDialog, { props: { open: false, title: 'Archive customer?' } })

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
  })

  it('renders the title and consequence copy while open', () => {
    const wrapper = mount(ConfirmDialog, {
      props: { open: true, title: 'Issue invoice INV-DRAFT-1?', message: 'This cannot be undone.' },
    })

    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Issue invoice INV-DRAFT-1?')
    expect(wrapper.text()).toContain('This cannot be undone.')
  })

  describe('mode="simple"', () => {
    it('allows confirming immediately, with no extra input', async () => {
      const wrapper = mount(ConfirmDialog, {
        props: { open: true, title: 'Discard changes?', mode: 'simple', confirmLabel: 'Discard' },
      })

      expect(button(wrapper, 'Discard')?.attributes('disabled')).toBeUndefined()

      await button(wrapper, 'Discard')?.trigger('click')

      expect(wrapper.emitted('confirm')?.[0]).toEqual([undefined])
    })
  })

  describe('mode="reason"', () => {
    function mountReason() {
      return mount(ConfirmDialog, {
        props: {
          open: true,
          title: 'Cancel invoice INV-2026-06-0001?',
          mode: 'reason',
          reasonLabel: 'Reason for cancelling',
          confirmLabel: 'Cancel invoice',
          cancelLabel: 'Go back',
        },
      })
    }

    it('disables the confirm button until the reason is long enough', async () => {
      const wrapper = mountReason()

      expect(button(wrapper, 'Cancel invoice')?.attributes('disabled')).toBeDefined()

      await wrapper.find('textarea').setValue('no')
      expect(button(wrapper, 'Cancel invoice')?.attributes('disabled')).toBeDefined()

      await wrapper.find('textarea').setValue('Customer requested cancellation.')
      expect(button(wrapper, 'Cancel invoice')?.attributes('disabled')).toBeUndefined()
    })

    it('rejects a reason over the maximum length', async () => {
      const wrapper = mount(ConfirmDialog, {
        props: {
          open: true,
          title: 'Cancel?',
          mode: 'reason',
          confirmLabel: 'Confirm',
          reasonMaxLength: 10,
        },
      })

      // Scripted assignment bypasses the browser's own `maxlength` enforcement (that only
      // limits interactive typing), so this is the case that actually exercises the
      // component's own length check rather than the input's.
      await wrapper.find('textarea').setValue('this reason is far too long')

      expect(button(wrapper, 'Confirm')?.attributes('disabled')).toBeDefined()
    })

    it('emits the trimmed reason on confirm', async () => {
      const wrapper = mountReason()

      await wrapper.find('textarea').setValue('  Customer requested cancellation.  ')
      await button(wrapper, 'Cancel invoice')?.trigger('click')

      expect(wrapper.emitted('confirm')?.[0]).toEqual(['Customer requested cancellation.'])
    })

    it('uses the caller-supplied "Go back" label rather than a generic Cancel', () => {
      const wrapper = mountReason()

      // Design note: when the dialog is itself about cancelling an invoice, its own dismiss
      // button must not also say "Cancel" — a reader could misread which button does what.
      expect(wrapper.text()).toContain('Go back')
    })
  })

  describe('mode="typed", with a token', () => {
    function mountTyped() {
      return mount(ConfirmDialog, {
        props: {
          open: true,
          title: 'Delete customer C-0001?',
          mode: 'typed',
          danger: true,
          confirmToken: 'C-0001',
          confirmLabel: 'Delete customer',
        },
      })
    }

    it('disables delete until the exact token is typed', async () => {
      const wrapper = mountTyped()

      expect(button(wrapper, 'Delete customer')?.attributes('disabled')).toBeDefined()

      await wrapper.find('input').setValue('C-0002')
      expect(button(wrapper, 'Delete customer')?.attributes('disabled')).toBeDefined()

      await wrapper.find('input').setValue('C-0001')
      expect(button(wrapper, 'Delete customer')?.attributes('disabled')).toBeUndefined()
    })

    it('renders the danger variant, distinct from a primary confirm', () => {
      const wrapper = mountTyped()

      expect(button(wrapper, 'Delete customer')?.classes().join(' ')).toContain('danger')
    })
  })

  describe('mode="typed", with no token (e.g. a draft invoice)', () => {
    it('falls back to a required checkbox rather than a typed field', async () => {
      const wrapper = mount(ConfirmDialog, {
        props: {
          open: true,
          title: 'Delete this draft invoice?',
          mode: 'typed',
          danger: true,
          confirmToken: null,
          confirmLabel: 'Delete draft',
        },
      })

      expect(wrapper.find('input[type="text"]').exists()).toBe(false)
      expect(button(wrapper, 'Delete draft')?.attributes('disabled')).toBeDefined()

      await wrapper.find('input[type="checkbox"]').setValue(true)

      expect(button(wrapper, 'Delete draft')?.attributes('disabled')).toBeUndefined()
    })
  })

  it('emits cancel on Escape', async () => {
    const wrapper = mount(ConfirmDialog, { props: { open: true, title: 'Archive?' } })

    await wrapper.find('[role="dialog"]').trigger('keydown', { key: 'Escape' })

    expect(wrapper.emitted('cancel')).toBeTruthy()
  })

  it('emits cancel on backdrop click, not on a click inside the dialog', async () => {
    const wrapper = mount(ConfirmDialog, { props: { open: true, title: 'Archive?' } })

    await wrapper.find('[role="dialog"]').trigger('click')
    expect(wrapper.emitted('cancel')).toBeFalsy()

    await wrapper.find('.fixed').trigger('click')
    expect(wrapper.emitted('cancel')).toBeTruthy()
  })

  it('resets its fields between openings', async () => {
    const wrapper = mount(ConfirmDialog, {
      props: { open: true, title: 'Cancel?', mode: 'reason' },
    })

    await wrapper.find('textarea').setValue('a leftover reason')
    await wrapper.setProps({ open: false })
    await wrapper.setProps({ open: true })

    expect(wrapper.find('textarea').element.value).toBe('')
  })
})
