import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, h } from 'vue'
import CompanySwitcher from '@/components/app/CompanySwitcher.vue'
import { useAuthStore } from '@/stores/auth'
import { useUnsavedGuard } from '@/composables/useUnsavedGuard'
import type { CompanySummary } from '@/types/domain'

/**
 * The confirm-and-discard behaviour added to `CompanySwitcher.select()` (ADR 0013 §6,
 * Gate-1 decision #6). This is the one choke point that can abort a switch before it commits
 * server-side, so what matters is: a dirty editor mounted elsewhere in the app blocks the
 * switch until confirmed, a clean one never prompts, and declining leaves the active company
 * untouched.
 */

function companies(): CompanySummary[] {
  return [
    {
      id: 'company-1',
      name: 'Demo Trading',
      code: 'DTL',
      base_currency_code: 'LKR',
      currency_precision: 2,
      timezone: 'Asia/Colombo',
      is_default: true,
    },
    {
      id: 'company-2',
      name: 'Second Books',
      code: 'SEC',
      base_currency_code: 'LKR',
      currency_precision: 2,
      timezone: 'Asia/Colombo',
      is_default: false,
    },
  ]
}

/** Mounts a stand-in editor that registers itself as dirty (or not) for the guard's lifetime. */
function mountEditor(isDirty: () => boolean) {
  const Editor = defineComponent({
    setup() {
      useUnsavedGuard(isDirty)
      return () => h('div')
    },
  })

  return mount(Editor)
}

beforeEach(() => {
  setActivePinia(createPinia())
  useAuthStore().$patch({ companies: companies() } as never)
})

describe('CompanySwitcher — confirm-and-discard', () => {
  it('switches without prompting when nothing has unsaved changes', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm')
    const auth = useAuthStore()
    vi.spyOn(auth, 'selectCompany').mockResolvedValue()

    const wrapper = mount(CompanySwitcher)
    await wrapper.find('button[aria-haspopup="listbox"]').trigger('click')
    await wrapper.findAll('[role="option"]')[1]?.trigger('click')

    expect(confirmSpy).not.toHaveBeenCalled()
    expect(auth.selectCompany).toHaveBeenCalledWith('company-2')
  })

  it('asks before discarding when an editor elsewhere has unsaved changes', async () => {
    const editor = mountEditor(() => true)
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const auth = useAuthStore()
    vi.spyOn(auth, 'selectCompany').mockResolvedValue()

    const wrapper = mount(CompanySwitcher)
    await wrapper.find('button[aria-haspopup="listbox"]').trigger('click')
    await wrapper.findAll('[role="option"]')[1]?.trigger('click')

    expect(confirmSpy).toHaveBeenCalledWith(
      'Switching company discards unsaved changes. Continue?',
    )
    expect(auth.selectCompany).toHaveBeenCalledWith('company-2')

    editor.unmount()
  })

  it('aborts the switch entirely when the prompt is declined', async () => {
    const editor = mountEditor(() => true)
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const auth = useAuthStore()
    vi.spyOn(auth, 'selectCompany').mockResolvedValue()

    const wrapper = mount(CompanySwitcher)
    await wrapper.find('button[aria-haspopup="listbox"]').trigger('click')
    await wrapper.findAll('[role="option"]')[1]?.trigger('click')

    // No call at all — declining must not switch "elsewhere" quietly. The active company is
    // whatever `auth` already holds; this only proves the switch was never attempted.
    expect(auth.selectCompany).not.toHaveBeenCalled()

    editor.unmount()
  })

  it('does not prompt when switching to the company already active', async () => {
    mountEditor(() => true)
    const confirmSpy = vi.spyOn(window, 'confirm')
    const auth = useAuthStore()
    vi.spyOn(auth, 'selectCompany').mockResolvedValue()

    const wrapper = mount(CompanySwitcher)
    await wrapper.find('button[aria-haspopup="listbox"]').trigger('click')
    await wrapper.findAll('[role="option"]')[0]?.trigger('click')

    expect(confirmSpy).not.toHaveBeenCalled()
    expect(auth.selectCompany).not.toHaveBeenCalled()
  })
})
