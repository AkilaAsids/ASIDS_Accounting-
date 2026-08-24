import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import Pagination from '@/components/ui/Pagination.vue'
import type { Pagination as PaginationMeta } from '@/types/api'

/**
 * The shared pagination control.
 *
 * Consumed identically by the customer and invoice lists (ADR 0013 §5), so the properties
 * worth pinning here are the ones both screens rely on: it renders nothing for a single page
 * rather than a useless disabled pair, it never prints "null–null" for an empty page, and it
 * only ever asks its parent for a page number — it owns no fetching itself.
 */

function meta(overrides: Partial<PaginationMeta> = {}): PaginationMeta {
  return { total: 42, per_page: 15, current_page: 2, last_page: 3, from: 16, to: 30, ...overrides }
}

describe('Pagination', () => {
  it('renders nothing when there is only one page', () => {
    const wrapper = mount(Pagination, { props: { pagination: meta({ last_page: 1 }) } })

    expect(wrapper.find('nav').exists()).toBe(false)
  })

  it('shows the from–to of total count', () => {
    const wrapper = mount(Pagination, { props: { pagination: meta() } })

    expect(wrapper.text()).toContain('16–30 of 42')
  })

  it('renders 0 rather than null for an empty page', () => {
    const wrapper = mount(Pagination, {
      props: { pagination: meta({ from: null, to: null, total: 0 }) },
    })

    expect(wrapper.text()).toContain('0–0 of 0')
    expect(wrapper.text()).not.toContain('null')
  })

  it('disables Previous on the first page', () => {
    const wrapper = mount(Pagination, { props: { pagination: meta({ current_page: 1 }) } })
    const buttons = wrapper.findAll('button')

    expect(buttons[0]?.text()).toBe('Previous')
    expect(buttons[0]?.attributes('disabled')).toBeDefined()
    expect(buttons[1]?.attributes('disabled')).toBeUndefined()
  })

  it('disables Next on the last page', () => {
    const wrapper = mount(Pagination, {
      props: { pagination: meta({ current_page: 3, last_page: 3 }) },
    })
    const buttons = wrapper.findAll('button')

    expect(buttons[0]?.attributes('disabled')).toBeUndefined()
    expect(buttons[1]?.text()).toBe('Next')
    expect(buttons[1]?.attributes('disabled')).toBeDefined()
  })

  it('disables both buttons while a request is in flight', () => {
    const wrapper = mount(Pagination, { props: { pagination: meta(), disabled: true } })

    wrapper.findAll('button').forEach((button) => {
      expect(button.attributes('disabled')).toBeDefined()
    })
  })

  it('emits update:page with the previous page number', async () => {
    const wrapper = mount(Pagination, { props: { pagination: meta({ current_page: 2 }) } })

    await wrapper.findAll('button')[0]?.trigger('click')

    expect(wrapper.emitted('update:page')?.[0]).toEqual([1])
  })

  it('emits update:page with the next page number', async () => {
    const wrapper = mount(Pagination, { props: { pagination: meta({ current_page: 2 }) } })

    await wrapper.findAll('button')[1]?.trigger('click')

    expect(wrapper.emitted('update:page')?.[0]).toEqual([3])
  })
})
