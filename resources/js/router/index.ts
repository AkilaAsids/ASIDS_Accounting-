import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/**
 * Routing and access control.
 *
 * Every page below the shell is lazily imported, so the sign-in screen — the only page an
 * unauthenticated visitor sees — does not carry the weight of the whole administration
 * area. That matters on a Sri Lankan mobile connection.
 *
 * The guard enforces three things in order, and the order is deliberate:
 *
 *   1. Authentication.
 *   2. **Interstitials.** An expired password or a mandated 2FA enrolment confines the user
 *      to the screen that resolves it. Checked before permissions, because a confined user
 *      would otherwise be bounced to a "no access" page instead of the fix.
 *   3. Permission, from the session payload — presentation only; the server authorises
 *      every request regardless.
 */

declare module 'vue-router' {
  interface RouteMeta {
    /** Reachable without a session. */
    guest?: boolean
    /** Reachable while confined by an interstitial (sign-out, change password, enrol 2FA). */
    reachableWhileConfined?: boolean
    /** Permission required to view. Owner short-circuits it, as on the server. */
    permission?: string
    title?: string
  }
}

const routes: RouteRecordRaw[] = [
  {
    path: '/sign-in',
    name: 'sign-in',
    component: () => import('@/pages/auth/SignInPage.vue'),
    meta: { guest: true, title: 'Sign in' },
  },
  {
    path: '/two-factor',
    name: 'two-factor-challenge',
    component: () => import('@/pages/auth/TwoFactorChallengePage.vue'),
    meta: { guest: true, title: 'Verification' },
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: () => import('@/pages/auth/ForgotPasswordPage.vue'),
    meta: { guest: true, title: 'Reset your password' },
  },
  {
    // Landing page for a signed invitation or reset link. The signature travels in the query
    // string, which this page forwards to the API verbatim.
    path: '/account-link/:userId',
    name: 'account-link',
    component: () => import('@/pages/auth/AccountLinkPage.vue'),
    meta: { guest: true, title: 'Set your password' },
  },

  {
    path: '/',
    component: () => import('@/layouts/AppLayout.vue'),
    children: [
      {
        path: '',
        name: 'dashboard',
        component: () => import('@/pages/DashboardPage.vue'),
        meta: { title: 'Dashboard' },
      },
      {
        path: 'users',
        name: 'users',
        component: () => import('@/pages/users/UsersPage.vue'),
        meta: { permission: 'identity.users.view', title: 'Users' },
      },
      {
        path: 'roles',
        name: 'roles',
        component: () => import('@/pages/roles/RolesPage.vue'),
        meta: { permission: 'authorization.roles.view', title: 'Roles' },
      },
      {
        path: 'settings',
        name: 'settings',
        component: () => import('@/pages/settings/SettingsPage.vue'),
        meta: { title: 'Settings' },
      },
      {
        // Self-service, so no permission: a user managing their own second factor and
        // devices is not an administrative act.
        path: 'security',
        name: 'security',
        component: () => import('@/pages/security/SecurityPage.vue'),
        meta: { reachableWhileConfined: true, title: 'Security' },
      },
      {
        path: 'change-password',
        name: 'change-password',
        component: () => import('@/pages/security/ChangePasswordPage.vue'),
        meta: { reachableWhileConfined: true, title: 'Change your password' },
      },
    ],
  },

  {
    path: '/forbidden',
    name: 'forbidden',
    component: () => import('@/pages/ForbiddenPage.vue'),
    meta: { reachableWhileConfined: true, title: 'No access' },
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/pages/NotFoundPage.vue'),
    meta: { reachableWhileConfined: true, title: 'Page not found' },
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: (to, from, savedPosition) => savedPosition ?? { top: 0 },
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  // One session fetch per page load, before the first navigation resolves. Without this a
  // hard refresh on a deep link would bounce an authenticated user to sign-in.
  if (!auth.initialised) {
    await auth.fetchSession()
  }

  if (to.meta.guest === true) {
    // An authenticated user has no business on the sign-in screen; send them home rather
    // than letting them sign in twice. The account-link page is exempt — following an
    // invitation while signed in as someone else is legitimate.
    return auth.isAuthenticated && to.name !== 'account-link' ? { name: 'dashboard' } : true
  }

  if (!auth.isAuthenticated) {
    return { name: 'sign-in', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
  }

  if (to.meta.reachableWhileConfined !== true) {
    if (auth.requires.password_change) {
      return { name: 'change-password', query: { expired: '1' } }
    }

    if (auth.requires.two_factor_enrolment) {
      return { name: 'security', query: { enrol: '1' } }
    }
  }

  if (to.meta.permission !== undefined && !auth.can(to.meta.permission)) {
    return { name: 'forbidden' }
  }

  return true
})

router.afterEach((to) => {
  const base = import.meta.env.VITE_APP_NAME ?? 'ASIDS ERP Cloud'
  document.title = to.meta.title ? `${to.meta.title} · ${base}` : base
})
