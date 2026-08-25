import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import CancelInvoiceDialog from '@/components/sales/invoices/CancelInvoiceDialog.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'

describe('CancelInvoiceDialog', () => {
  it('frames cancellation as not an undo, and never labels its own dismiss button "Cancel"', () => {
    const wrapper = mount(CancelInvoiceDialog, { props: { open: true } })

    expect(wrapper.text()).toContain('This does not delete or undo the original entry')
    expect(wrapper.text()).toContain('Go back')
    expect(wrapper.findAll('button').some((b) => b.text().trim() === 'Cancel')).toBe(false)
  })

  it('keeps the confirm button disabled until a 3–255 character reason is given', async () => {
    const wrapper = mount(CancelInvoiceDialog, { props: { open: true } })
    const confirmButton = () => wrapper.findAll('button').find((b) => b.text() === 'Cancel invoice')

    expect(confirmButton()?.attributes('disabled')).toBeDefined()

    await wrapper.find('textarea').setValue('no')
    expect(confirmButton()?.attributes('disabled')).toBeDefined()

    await wrapper.find('textarea').setValue('Customer requested cancellation.')
    expect(confirmButton()?.attributes('disabled')).toBeUndefined()
  })

  it('emits the trimmed reason on confirm', async () => {
    const wrapper = mount(CancelInvoiceDialog, { props: { open: true } })

    await wrapper.find('textarea').setValue('  Goods returned.  ')
    await wrapper.findAll('button').find((b) => b.text() === 'Cancel invoice')?.trigger('click')

    expect(wrapper.emitted('confirm')?.[0]).toEqual(['Goods returned.'])
  })

  it('emits cancel when "Go back" is clicked', async () => {
    const wrapper = mount(CancelInvoiceDialog, { props: { open: true } })

    await wrapper.findAll('button').find((b) => b.text() === 'Go back')?.trigger('click')

    expect(wrapper.emitted('cancel')).toBeTruthy()
  })

  it('defaults to an empty-string reason if the underlying ConfirmDialog ever confirms without one', async () => {
    // `ConfirmDialog`'s own emit type allows `reason: string | undefined` (its `simple`/`typed`
    // modes emit `undefined`) — this dialog only ever uses `mode="reason"`, so the real
    // `ConfirmDialog` never actually takes this path, but the fallback exists for exactly this
    // defensive reason and is worth pinning directly.
    const wrapper = mount(CancelInvoiceDialog, { props: { open: true } })

    await wrapper.findComponent(ConfirmDialog).vm.$emit('confirm')

    expect(wrapper.emitted('confirm')?.[0]).toEqual([''])
  })
})
