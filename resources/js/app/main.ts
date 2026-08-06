import { createPinia } from 'pinia'
import { createApp } from 'vue'
import { createI18n } from 'vue-i18n'
import App from '@/app/App.vue'
import { api } from '@/api/client'
import en from '@/locales/en'
import { router } from '@/router'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import '@/styles/app.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)

/**
 * Sinhala and Tamil are lazily loaded, not bundled: the overwhelming majority of sessions
 * are English, and shipping three message catalogues to every visitor to serve a minority
 * is the wrong trade on a mobile connection.
 */
const i18n = createI18n({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  messages: { en },
  missingWarn: import.meta.env.DEV,
  fallbackWarn: false,
})

app.use(i18n)
app.use(router)

/**
 * Wire the client's hooks to the stores.
 *
 * Done here rather than inside the client so the client stays free of Pinia and Vue Router
 * and remains unit-testable in isolation.
 */
const auth = useAuthStore()
const ui = useUiStore()

api.configure({
  onUnauthenticated: () => {
    // Only redirect if the user thought they were signed in. A 401 on the guest sign-in
    // request is the expected answer, not a session expiry.
    if (auth.isAuthenticated) {
      auth.clear()
      ui.notify('info', 'Your session has ended. Please sign in again.')
      void router.push({ name: 'sign-in' })
    }
  },

  onPasswordExpired: () => {
    auth.requires.password_change = true
    void router.push({ name: 'change-password', query: { expired: '1' } })
  },

  onTwoFactorEnrolmentRequired: () => {
    auth.requires.two_factor_enrolment = true
    void router.push({ name: 'security', query: { enrol: '1' } })
  },

  // Resolved by the step-up dialog rendered in App.vue. The client awaits the code and then
  // replays the original request, so no call site handles this.
  onStepUpRequired: () => window.asidsRequestStepUp(),
})

/**
 * Unhandled rejections and render errors are surfaced rather than swallowed. A silent
 * failure in an accounting interface means a user believes something saved when it did not.
 */
app.config.errorHandler = (error) => {
  console.error('[asids] Unhandled error', error)
  ui.notify('error', 'Something went wrong. If it keeps happening, please contact support.')
}

window.addEventListener('unhandledrejection', (event) => {
  console.error('[asids] Unhandled rejection', event.reason)
})

// Mounted only after the first navigation resolves, so the guard's session fetch completes
// before anything renders and the shell never flashes an unauthenticated state.
void router.isReady().then(() => app.mount('#app'))
