import { config } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, vi } from 'vitest'
import en from '@/locales/en'

/**
 * Test environment setup.
 *
 * A fresh Pinia per test is the important part: stores are singletons, and a session left in
 * one test would silently authenticate the next — the kind of shared state that makes a suite
 * pass in order and fail in isolation.
 */
const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })

config.global.plugins = [i18n]

// Stubbed so components using <RouterLink> render without a full router instance.
config.global.stubs = {
  RouterLink: { template: '<a><slot /></a>' },
  RouterView: { template: '<div />' },
  Teleport: true,
}

beforeEach(() => {
  setActivePinia(createPinia())
  document.cookie = ''
  document.documentElement.className = ''
})

// happy-dom does not implement matchMedia, and the UI store reads it on construction.
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
})
