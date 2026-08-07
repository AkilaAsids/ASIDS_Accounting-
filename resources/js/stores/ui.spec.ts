import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { useUiStore } from '@/stores/ui'

/**
 * Interface chrome: theme, sidebar and transient notices.
 *
 * Theme lives in a cookie rather than localStorage, and that is the one decision here worth pinning
 * down. The Blade shell reads the cookie server-side and stamps the `dark` class before the
 * stylesheet loads, so a dark-mode user never sees a white flash on navigation. localStorage is only
 * readable once JavaScript has run, which is far too late — and a test that only checked the reactive
 * value would pass against a store that had stopped writing the cookie at all.
 */

/** Replaces `matchMedia` so the OS preference can be driven from a test. */
function withSystemPreference(prefersDark: boolean): { fire: (matches: boolean) => void } {
  const listeners: Array<(event: { matches: boolean }) => void> = []

  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query: string) => ({
      matches: prefersDark,
      media: query,
      onchange: null,
      addEventListener: (_: string, handler: (event: { matches: boolean }) => void) => {
        listeners.push(handler)
      },
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  })

  return {
    fire: (matches: boolean) => listeners.forEach((handler) => handler({ matches })),
  }
}

function cookieValue(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))

  return match?.[1] === undefined ? null : decodeURIComponent(match[1])
}

beforeEach(() => {
  // Cookies persist across tests in happy-dom, so a theme set in one test would decide the initial
  // state of the next.
  for (const entry of document.cookie.split(';')) {
    const name = entry.split('=')[0]?.trim()

    if (name) {
      document.cookie = `${name}=; max-age=0; path=/`
    }
  }

  document.documentElement.className = ''
  delete document.documentElement.dataset.theme

  withSystemPreference(false)
  setActivePinia(createPinia())
})

describe('theme', () => {
  it('defaults to following the system', () => {
    expect(useUiStore().theme).toBe('system')
  })

  it('writes the choice to a cookie the server can read', async () => {
    const ui = useUiStore()

    ui.setTheme('dark')
    await nextTick()

    // The cookie is the whole mechanism: the Blade shell stamps `dark` on `<html>` from it before
    // any stylesheet loads. Storing this in localStorage would reintroduce the white flash.
    expect(cookieValue('asids_theme')).toBe('dark')
  })

  it('reads an existing cookie on construction', () => {
    document.cookie = 'asids_theme=dark; path=/'

    setActivePinia(createPinia())

    expect(useUiStore().theme).toBe('dark')
  })

  it('ignores a cookie value that is not a known theme', () => {
    document.cookie = 'asids_theme=neon; path=/'

    setActivePinia(createPinia())

    // A tampered or stale cookie must not put the interface into an unstyled state.
    expect(useUiStore().theme).toBe('system')
  })

  it('resolves dark directly', async () => {
    const ui = useUiStore()

    ui.setTheme('dark')
    await nextTick()

    expect(ui.isDark).toBe(true)
    expect(document.documentElement.classList.contains('dark')).toBe(true)
  })

  it('resolves light directly, even when the system prefers dark', async () => {
    withSystemPreference(true)
    setActivePinia(createPinia())

    const ui = useUiStore()

    ui.setTheme('light')
    await nextTick()

    // An explicit choice overrides the OS. A user who picks light on a dark-mode laptop means it.
    expect(ui.isDark).toBe(false)
    expect(document.documentElement.classList.contains('dark')).toBe(false)
  })

  it('follows the system when set to system', () => {
    withSystemPreference(true)
    setActivePinia(createPinia())

    expect(useUiStore().isDark).toBe(true)
  })

  it('follows a live change to the OS appearance', async () => {
    const media = withSystemPreference(false)
    setActivePinia(createPinia())

    const ui = useUiStore()

    expect(ui.isDark).toBe(false)

    media.fire(true)
    await nextTick()

    // Kept live so a user who changes their OS appearance with the tab open follows it, rather than
    // staying on whatever it was at page load.
    expect(ui.isDark).toBe(true)
  })

  it('exposes the chosen theme as a data attribute for CSS overrides', async () => {
    const ui = useUiStore()

    ui.setTheme('light')
    await nextTick()

    expect(document.documentElement.dataset.theme).toBe('light')
  })
})

describe('the sidebar', () => {
  it('starts expanded', () => {
    expect(useUiStore().sidebarCollapsed).toBe(false)
  })

  it('remembers being collapsed across page loads', async () => {
    const ui = useUiStore()

    ui.toggleSidebar()
    await nextTick()

    expect(ui.sidebarCollapsed).toBe(true)
    expect(cookieValue('asids_sidebar_collapsed')).toBe('1')

    setActivePinia(createPinia())

    expect(useUiStore().sidebarCollapsed).toBe(true)
  })

  it('toggles back', async () => {
    const ui = useUiStore()

    ui.toggleSidebar()
    await nextTick()
    ui.toggleSidebar()
    await nextTick()

    expect(ui.sidebarCollapsed).toBe(false)
    expect(cookieValue('asids_sidebar_collapsed')).toBe('0')
  })
})

describe('notices', () => {
  it('adds a notice with its kind and message', () => {
    const ui = useUiStore()

    ui.notify('success', 'Settings saved.')

    expect(ui.notices).toHaveLength(1)
    expect(ui.notices[0]?.kind).toBe('success')
    expect(ui.notices[0]?.message).toBe('Settings saved.')
  })

  it('gives each notice a distinct id so two identical messages both show', () => {
    const ui = useUiStore()

    ui.notify('error', 'Could not save.')
    ui.notify('error', 'Could not save.')

    // Keyed by id rather than message. Deduplicating would hide the second failure of a repeated
    // action, which is exactly when the user needs to know it failed again.
    expect(ui.notices).toHaveLength(2)
    expect(ui.notices[0]?.id).not.toBe(ui.notices[1]?.id)
  })

  it('dismisses one notice without disturbing the others', () => {
    const ui = useUiStore()

    ui.notify('info', 'First')
    ui.notify('info', 'Second')

    const first = ui.notices[0]?.id

    ui.dismiss(first as number)

    expect(ui.notices).toHaveLength(1)
    expect(ui.notices[0]?.message).toBe('Second')
  })

  it('ignores a dismissal for a notice that is already gone', () => {
    const ui = useUiStore()

    ui.notify('info', 'Only')
    const id = ui.notices[0]?.id as number

    ui.dismiss(id)

    // A double click on the close button, or an auto-dismiss racing a manual one.
    expect(() => ui.dismiss(id)).not.toThrow()
    expect(ui.notices).toEqual([])
  })
})
