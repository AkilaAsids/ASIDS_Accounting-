import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { defineComponent, h, type PropType } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useCompanyReload } from '@/composables/useCompanyReload'
import type { CompanySummary } from '@/types/domain'

/**
 * The company-reload composable (ADR 0013 §6).
 *
 * The one behaviour every screen in both lanes depends on, and the one ADR 0011 D3 calls out
 * as failing silently when forgotten: exactly one request on mount, and exactly one more per
 * genuine company change — never a second request for an unrelated store mutation, and never
 * zero when the company actually changes.
 */

function company(overrides: Partial<CompanySummary> = {}): CompanySummary {
  return {
    id: 'company-1',
    name: 'Demo Trading',
    code: 'DTL',
    base_currency_code: 'LKR',
    currency_precision: 2,
    timezone: 'Asia/Colombo',
    is_default: true,
    ...overrides,
  }
}

/**
 * A single host component definition, reused by every test (including the one that reads
 * `companyId` back) — `vue/one-component-per-file` flags a second inline component literal
 * in the same test file, so this is the one seam every test mounts through.
 */
const Host = defineComponent({
  props: { load: { type: Function as PropType<() => void>, required: true } },
  setup(props) {
    const { companyId } = useCompanyReload(props.load)
    return { companyId }
  },
  render() {
    return h('div')
  },
})

function mountHost(load: () => void) {
  return mount(Host, { props: { load } })
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('useCompanyReload', () => {
  it('loads once on mount', () => {
    const load = vi.fn()
    useAuthStore().$patch({ companies: [company()] } as never)

    mountHost(load)

    expect(load).toHaveBeenCalledTimes(1)
  })

  it('does not load a second time for an unrelated store change', async () => {
    const load = vi.fn()
    const auth = useAuthStore()
    auth.$patch({ companies: [company()] } as never)

    mountHost(load)
    expect(load).toHaveBeenCalledTimes(1)

    // Same company id, different object identity — must not read as a switch.
    auth.$patch({ companies: [company()] } as never)
    await Promise.resolve()

    expect(load).toHaveBeenCalledTimes(1)
  })

  it('reloads exactly once when the active company changes', async () => {
    const load = vi.fn()
    const auth = useAuthStore()
    auth.$patch({ companies: [company()] } as never)

    mountHost(load)
    expect(load).toHaveBeenCalledTimes(1)

    auth.$patch({ companies: [company({ id: 'company-2', code: 'SEC' })] } as never)
    await Promise.resolve()

    expect(load).toHaveBeenCalledTimes(2)
  })

  it('exposes the active company id', () => {
    const auth = useAuthStore()
    auth.$patch({ companies: [company({ id: 'company-9' })] } as never)

    const wrapper = mountHost(() => {})

    expect(wrapper.vm.companyId).toBe('company-9')
  })
})
