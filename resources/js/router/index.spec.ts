import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

/**
 * The navigation guard.
 *
 * It enforces three things and the *order* is the whole point: authentication, then interstitials,
 * then permission. Reversing the last two is the interesting failure — a user whose password has
 * expired, navigating to a page they lack permission for, would be sent to "no access" instead of to
 * the change-password screen, and would have no way to work out what was actually wrong.
 *
 * Tested through the real router rather than by calling the guard directly, so the route table's own
 * `meta` — which is what decides confinement and permission — is part of what is under test.
 */

const fetchSession = vi.fn()

// Pages are lazily imported by the route table; stubbing the loader keeps this about the guard and
// not about mounting thirteen screens.
vi.mock('@/pages/auth/SignInPage.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/pages/auth/TwoFactorChallengePage.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/pages/auth/ForgotPasswordPage.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/pages/auth/AccountLinkPage.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/pages/DashboardPage.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/pages/users/UsersPage.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/pages/roles/RolesPage.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/pages/settings/SettingsPage.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/pages/security/SecurityPage.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/pages/security/ChangePasswordPage.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/pages/ForbiddenPage.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/pages/NotFoundPage.vue', () => ({ default: { template: '<div />' } }))

const { router } = await import('@/router/index')
const { useAuthStore } = await import('@/stores/auth')

/**
 * Puts the store into a given state without touching the network.
 */
function session(state: {
  authenticated: boolean
  permissions?: string[]
  isOwner?: boolean
  requires?: { password_change?: boolean; two_factor_enrolment?: boolean }
}): void {
  const auth = useAuthStore()

  auth.$patch({
    initialised: true,
    user: state.authenticated
      ? { id: 'u', full_name: 'A', email: 'a@b.test', is_owner: state.isOwner ?? false }
      : null,
    permissions: new Set(state.permissions ?? []),
    requires: {
      password_change: state.requires?.password_change ?? false,
      two_factor_enrolment: state.requires?.two_factor_enrolment ?? false,
      company_selection: false,
    },
  } as never)

  // Overridden so the guard's "fetch once per page load" branch does not reach the API.
  auth.fetchSession = fetchSession
}

/** Navigates and reports where the guard actually landed. */
async function navigateTo(path: string): Promise<string | undefined> {
  await router.push(path).catch(() => undefined)
  await router.isReady()

  return router.currentRoute.value.name?.toString()
}

beforeEach(async () => {
  setActivePinia(createPinia())
  fetchSession.mockReset()
  fetchSession.mockResolvedValue(false)

  // The router is a module singleton, so `currentRoute` survives between tests — and vue-router
  // treats a push to the route you are already on as a duplicate and aborts it. A test that then
  // reads `currentRoute` sees the previous test's destination and passes or fails for that reason
  // rather than for the guard's decision. Every test therefore starts from the dashboard, reached
  // with a session permissive enough to get there.
  session({ authenticated: true, isOwner: true })
  await router.replace('/').catch(() => undefined)

  fetchSession.mockClear()
})

describe('the first navigation', () => {
  it('loads the session once before resolving', async () => {
    const auth = useAuthStore()
    auth.fetchSession = fetchSession
    auth.$patch({ initialised: false } as never)

    // Somewhere other than the dashboard, which `beforeEach` already navigated to.
    await navigateTo('/users')

    // Without this, a hard refresh on a deep link bounces an authenticated user to sign-in because
    // the guard runs before the session is known.
    expect(fetchSession).toHaveBeenCalledTimes(1)
  })

  it('does not re-fetch on subsequent navigations', async () => {
    session({ authenticated: true, permissions: ['identity.users.view'] })

    await navigateTo('/')
    await navigateTo('/users')

    expect(fetchSession).not.toHaveBeenCalled()
  })
})

describe('authentication', () => {
  it('sends a guest to sign-in', async () => {
    session({ authenticated: false })

    expect(await navigateTo('/users')).toBe('sign-in')
  })

  it('remembers where the guest was going', async () => {
    session({ authenticated: false })

    await navigateTo('/users')

    // So sign-in returns them to the page they asked for, rather than dropping them on the dashboard
    // and making them navigate again.
    expect(router.currentRoute.value.query.redirect).toBe('/users')
  })

  it('does not add a redirect for the root path', async () => {
    session({ authenticated: false })

    await navigateTo('/')

    // `?redirect=/` is noise: the dashboard is where sign-in lands anyway.
    expect(router.currentRoute.value.query.redirect).toBeUndefined()
  })

  it('lets a guest reach the sign-in screen', async () => {
    session({ authenticated: false })

    expect(await navigateTo('/sign-in')).toBe('sign-in')
  })

  it('lets a guest reach the forgotten-password screen', async () => {
    session({ authenticated: false })

    expect(await navigateTo('/forgot-password')).toBe('forgot-password')
  })

  it('sends an authenticated user away from sign-in', async () => {
    session({ authenticated: true })

    // Signing in twice is not a thing. Leaving them on the form invites them to try.
    expect(await navigateTo('/sign-in')).toBe('dashboard')
  })

  it('lets an authenticated user follow an invitation link', async () => {
    session({ authenticated: true })

    // The one guest page exempt from that redirect: following an invitation while signed in as
    // somebody else is legitimate, and bouncing to the dashboard would make the link look broken.
    expect(await navigateTo('/account-link/user-1')).toBe('account-link')
  })
})

describe('interstitials', () => {
  it('confines a user with an expired password to the change-password screen', async () => {
    session({
      authenticated: true,
      permissions: ['identity.users.view'],
      requires: { password_change: true },
    })

    expect(await navigateTo('/users')).toBe('change-password')
    expect(router.currentRoute.value.query.expired).toBe('1')
  })

  it('confines a user who must enrol in 2FA to the security screen', async () => {
    session({
      authenticated: true,
      permissions: ['identity.users.view'],
      requires: { two_factor_enrolment: true },
    })

    expect(await navigateTo('/users')).toBe('security')
    expect(router.currentRoute.value.query.enrol).toBe('1')
  })

  it('prefers the password interstitial when both apply', async () => {
    session({
      authenticated: true,
      requires: { password_change: true, two_factor_enrolment: true },
    })

    // One at a time, in a fixed order. Sending the user to enrol and then immediately to change
    // their password reads as the application not knowing what it wants.
    expect(await navigateTo('/users')).toBe('change-password')
  })

  it('lets a confined user reach the screen that resolves it', async () => {
    session({ authenticated: true, requires: { password_change: true } })

    // The exemption that keeps confinement from being a trap.
    expect(await navigateTo('/change-password')).toBe('change-password')
  })

  it('checks confinement before permission', async () => {
    session({
      authenticated: true,
      permissions: [],
      requires: { password_change: true },
    })

    // The ordering that matters. Checking permission first would send this user to "no access" —
    // true but useless, and it hides the fact that their password has expired.
    expect(await navigateTo('/users')).toBe('change-password')
  })
})

describe('permission', () => {
  it('allows a page the user holds the permission for', async () => {
    session({ authenticated: true, permissions: ['identity.users.view'] })

    expect(await navigateTo('/users')).toBe('users')
  })

  it('sends a user without the permission to the forbidden page', async () => {
    session({ authenticated: true, permissions: [] })

    expect(await navigateTo('/users')).toBe('forbidden')
  })

  it('allows an owner everything', async () => {
    session({ authenticated: true, permissions: [], isOwner: true })

    // Mirrors the server's owner short circuit, so the interface does not refuse a page the owner
    // can in fact open.
    expect(await navigateTo('/users')).toBe('users')
  })

  it('allows a page with no permission requirement', async () => {
    session({ authenticated: true, permissions: [] })

    expect(await navigateTo('/')).toBe('dashboard')
  })
})

describe('unknown paths', () => {
  it('renders the not-found page rather than a blank screen', async () => {
    session({ authenticated: true })

    expect(await navigateTo('/no-such-page')).toBe('not-found')
  })

  it('sends a guest on an unknown path to sign-in', async () => {
    session({ authenticated: false })

    // The catch-all is marked `reachableWhileConfined` but not `guest`, so an unauthenticated visitor
    // is treated like any other unauthenticated visitor. Stated here rather than assumed, because it
    // is a real trade-off either way: this reveals nothing about which paths exist, at the cost of
    // sending someone who mistyped a URL through sign-in only to land on a 404 afterwards. Adding
    // `guest: true` to that route is all it would take to change — worth deciding deliberately rather
    // than discovering from a support ticket.
    expect(await navigateTo('/no-such-page')).toBe('sign-in')
  })
})

describe('the document title', () => {
  it('is set from the route', async () => {
    session({ authenticated: true, permissions: ['identity.users.view'] })

    await navigateTo('/users')

    expect(document.title).toContain('Users')
  })
})
