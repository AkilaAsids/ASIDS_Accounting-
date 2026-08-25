import { afterEach, describe, expect, it } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import OverflowMenu from '@/components/ui/OverflowMenu.vue'

let attachedWrapper: VueWrapper | undefined

afterEach(() => {
  attachedWrapper?.unmount()
  attachedWrapper = undefined
})

/**
 * The shared overflow ("⋯") menu (Gate-2 decision B), reused by the customer lifecycle menu
 * and the invoice actions menu for every row/detail action — "Delete" always lives inside one
 * of these rather than as a peer of "Edit"/"Archive".
 */

function mountMenu(label = 'More actions for Silva Traders') {
  return mount(OverflowMenu, {
    props: { label },
    slots: {
      default: `<template #default="{ close }">
        <button role="menuitem" @click="close">Archive</button>
        <button role="menuitem" class="text-danger" @click="close">Delete</button>
      </template>`,
    },
  })
}

describe('OverflowMenu', () => {
  it('labels its trigger for the specific row it belongs to', () => {
    const wrapper = mountMenu()

    expect(wrapper.find('button[aria-label="More actions for Silva Traders"]').exists()).toBe(
      true,
    )
  })

  it('starts closed', () => {
    const wrapper = mountMenu()

    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
    expect(wrapper.find('button[aria-haspopup="menu"]').attributes('aria-expanded')).toBe('false')
  })

  it('opens the menu on trigger click and reflects it in aria-expanded', async () => {
    const wrapper = mountMenu()

    await wrapper.find('button[aria-haspopup="menu"]').trigger('click')

    expect(wrapper.find('[role="menu"]').exists()).toBe(true)
    expect(wrapper.find('button[aria-haspopup="menu"]').attributes('aria-expanded')).toBe('true')
  })

  it('toggles closed on a second trigger click', async () => {
    const wrapper = mountMenu()
    const trigger = wrapper.find('button[aria-haspopup="menu"]')

    await trigger.trigger('click')
    await trigger.trigger('click')

    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
  })

  it('renders every supplied item as a menuitem', async () => {
    const wrapper = mountMenu()
    await wrapper.find('button[aria-haspopup="menu"]').trigger('click')

    const items = wrapper.findAll('[role="menuitem"]')
    expect(items.map((item) => item.text())).toEqual(['Archive', 'Delete'])
  })

  it('does nothing on Escape while already closed', async () => {
    const wrapper = mountMenu()

    await wrapper.find('.relative').trigger('keydown', { key: 'Escape' })

    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
  })

  it('does nothing on Tab while closed — there is no panel to trap focus within', async () => {
    const wrapper = mountMenu()

    await wrapper.find('.relative').trigger('keydown', { key: 'Tab' })

    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
  })

  it('ignores keys other than Escape and Tab', async () => {
    const wrapper = mountMenu()
    await wrapper.find('button[aria-haspopup="menu"]').trigger('click')

    await wrapper.find('.relative').trigger('keydown', { key: 'a' })

    expect(wrapper.find('[role="menu"]').exists()).toBe(true)
  })

  it('traps Tab at the last menu item, wrapping focus back to the first', async () => {
    attachedWrapper = mount(OverflowMenu, {
      attachTo: document.body,
      props: { label: 'More actions for Silva Traders' },
      slots: {
        default: `<template #default="{ close }">
          <button role="menuitem" @click="close">Archive</button>
          <button role="menuitem" @click="close">Delete</button>
        </template>`,
      },
    })

    await attachedWrapper.find('button[aria-haspopup="menu"]').trigger('click')
    const items = attachedWrapper.findAll('[role="menuitem"]')
    ;(items[1]!.element as HTMLElement).focus()
    expect(document.activeElement).toBe(items[1]!.element)

    await attachedWrapper.find('.relative').trigger('keydown', { key: 'Tab' })

    const refreshedItems = attachedWrapper.findAll('[role="menuitem"]')
    expect(document.activeElement).toBe(refreshedItems[0]!.element)
  })

  it('traps Shift+Tab at the first menu item, wrapping focus to the last', async () => {
    attachedWrapper = mount(OverflowMenu, {
      attachTo: document.body,
      props: { label: 'More actions for Silva Traders' },
      slots: {
        default: `<template #default="{ close }">
          <button role="menuitem" @click="close">Archive</button>
          <button role="menuitem" @click="close">Delete</button>
        </template>`,
      },
    })

    await attachedWrapper.find('button[aria-haspopup="menu"]').trigger('click')
    const items = attachedWrapper.findAll('[role="menuitem"]')
    ;(items[0]!.element as HTMLElement).focus()
    expect(document.activeElement).toBe(items[0]!.element)

    await attachedWrapper.find('.relative').trigger('keydown', { key: 'Tab', shiftKey: true })

    const refreshedItems = attachedWrapper.findAll('[role="menuitem"]')
    expect(document.activeElement).toBe(refreshedItems[1]!.element)
  })

  it('closes on Escape', async () => {
    const wrapper = mountMenu()
    await wrapper.find('button[aria-haspopup="menu"]').trigger('click')

    await wrapper.find('.relative').trigger('keydown', { key: 'Escape' })

    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
  })

  it('closes when an item calls the scoped close function', async () => {
    const wrapper = mountMenu()
    await wrapper.find('button[aria-haspopup="menu"]').trigger('click')

    await wrapper.find('[role="menuitem"]').trigger('click')

    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
  })

  it('closes on a click outside the menu', async () => {
    const wrapper = mount(
      { components: { OverflowMenu }, template: '<div><OverflowMenu label="More actions" /><button id="elsewhere">Elsewhere</button></div>' },
      { attachTo: document.body },
    )

    await wrapper.find('button[aria-haspopup="menu"]').trigger('click')
    expect(wrapper.find('[role="menu"]').exists()).toBe(true)

    await wrapper.find('#elsewhere').trigger('click')

    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
