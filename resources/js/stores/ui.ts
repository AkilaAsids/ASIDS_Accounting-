import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import type { Theme } from '@/types/domain'

const THEME_COOKIE = 'asids_theme'
const COOKIE_MAX_AGE = 60 * 60 * 24 * 365

/**
 * Interface chrome: theme, sidebar, and transient notices.
 *
 * Theme lives in a **cookie**, not localStorage, and that is the one non-obvious decision
 * here. The Blade shell reads it server-side and stamps the `dark` class before the
 * stylesheet loads, so a dark-mode user never sees a white flash. localStorage is only
 * readable after JavaScript runs, which is far too late.
 */
export const useUiStore = defineStore('ui', () => {
  const theme = ref<Theme>(readThemeCookie())
  const sidebarCollapsed = ref(readBoolean('asids_sidebar_collapsed'))
  const notices = ref<Array<{ id: number; kind: 'success' | 'error' | 'info'; message: string }>>(
    [],
  )

  let nextNoticeId = 1

  const prefersDark = ref(
    typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches,
  )

  const isDark = computed(
    () => theme.value === 'dark' || (theme.value === 'system' && prefersDark.value),
  )

  if (typeof window !== 'undefined') {
    // Kept live so a user who changes their OS appearance while the tab is open follows it,
    // rather than staying on whatever it was at page load.
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (event) => {
      prefersDark.value = event.matches
    })
  }

  watch(
    isDark,
    (dark) => {
      if (typeof document !== 'undefined') {
        document.documentElement.classList.toggle('dark', dark)
      }
    },
    { immediate: true },
  )

  watch(theme, (value) => {
    writeCookie(THEME_COOKIE, value)

    if (typeof document !== 'undefined') {
      document.documentElement.dataset.theme = value
    }
  })

  watch(sidebarCollapsed, (collapsed) => {
    writeCookie('asids_sidebar_collapsed', collapsed ? '1' : '0')
  })

  function setTheme(value: Theme): void {
    theme.value = value
  }

  function toggleSidebar(): void {
    sidebarCollapsed.value = !sidebarCollapsed.value
  }

  /**
   * A transient notice. Errors persist until dismissed; successes auto-dismiss — a failure
   * the user did not read is a failure they will hit again.
   */
  function notify(kind: 'success' | 'error' | 'info', message: string): void {
    const id = nextNoticeId++
    notices.value.push({ id, kind, message })

    if (kind !== 'error') {
      window.setTimeout(() => dismiss(id), 5000)
    }
  }

  function dismiss(id: number): void {
    notices.value = notices.value.filter((notice) => notice.id !== id)
  }

  return { theme, isDark, sidebarCollapsed, notices, setTheme, toggleSidebar, notify, dismiss }
})

function readThemeCookie(): Theme {
  const value = readCookie(THEME_COOKIE)
  return value === 'light' || value === 'dark' || value === 'system' ? value : 'system'
}

function readBoolean(name: string): boolean {
  return readCookie(name) === '1'
}

function readCookie(name: string): string | null {
  if (typeof document === 'undefined') {
    return null
  }

  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
  return match?.[1] ? decodeURIComponent(match[1]) : null
}

function writeCookie(name: string, value: string): void {
  if (typeof document === 'undefined') {
    return
  }

  const secure = window.location.protocol === 'https:' ? '; Secure' : ''
  document.cookie = `${name}=${encodeURIComponent(value)}; Path=/; Max-Age=${COOKIE_MAX_AGE}; SameSite=Lax${secure}`
}
