import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { hasUnsavedChanges, useUnsavedGuard } from '@/composables/useUnsavedGuard'

/**
 * The unsaved-edit registry `CompanySwitcher.select()` consults before committing a switch
 * (ADR 0013 §6). What matters is the registry, not any UI: an editor registers while mounted
 * and de-registers on unmount, and the switcher's question — "does *anything* mounted right
 * now have unsaved changes?" — is answered correctly whether zero, one, or several editors
 * are registered at once.
 */

function mountGuard(isDirty: () => boolean) {
  const Host = defineComponent({
    setup() {
      useUnsavedGuard(isDirty)
      return () => h('div')
    },
  })

  return mount(Host)
}

describe('useUnsavedGuard / hasUnsavedChanges', () => {
  it('reports no unsaved changes when nothing is registered', () => {
    expect(hasUnsavedChanges()).toBe(false)
  })

  it('reports unsaved changes while a dirty editor is mounted', () => {
    const wrapper = mountGuard(() => true)

    expect(hasUnsavedChanges()).toBe(true)

    wrapper.unmount()
  })

  it('reports no unsaved changes for a clean editor', () => {
    const wrapper = mountGuard(() => false)

    expect(hasUnsavedChanges()).toBe(false)

    wrapper.unmount()
  })

  it('stops being asked once the editor unmounts', () => {
    const wrapper = mountGuard(() => true)
    expect(hasUnsavedChanges()).toBe(true)

    wrapper.unmount()

    expect(hasUnsavedChanges()).toBe(false)
  })

  it('is true if any of several mounted editors is dirty', () => {
    const clean = mountGuard(() => false)
    const dirty = mountGuard(() => true)

    expect(hasUnsavedChanges()).toBe(true)

    clean.unmount()
    dirty.unmount()
  })

  it('reads the guard live rather than snapshotting it at registration', () => {
    let dirty = false
    const wrapper = mountGuard(() => dirty)

    expect(hasUnsavedChanges()).toBe(false)

    dirty = true
    expect(hasUnsavedChanges()).toBe(true)

    wrapper.unmount()
  })
})
