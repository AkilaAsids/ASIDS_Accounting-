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
      /*
       * Accounting. Each screen states the capability it needs, and the guard hides what a user
       * cannot reach — presentation only, as everywhere else: the server authorises every request
       * regardless of what the client chose to render.
       */
      {
        path: 'accounting/accounts',
        name: 'chart-of-accounts',
        component: () => import('@/pages/accounting/ChartOfAccountsPage.vue'),
        meta: { permission: 'accounting.accounts.view', title: 'Chart of accounts' },
      },
      {
        path: 'accounting/journal-entries',
        name: 'journal-entries',
        component: () => import('@/pages/accounting/JournalEntriesPage.vue'),
        meta: { permission: 'accounting.journals.view', title: 'Journal entries' },
      },
      {
        path: 'accounting/trial-balance',
        name: 'trial-balance',
        component: () => import('@/pages/accounting/TrialBalancePage.vue'),
        meta: { permission: 'accounting.reports.view', title: 'Trial balance' },
      },
      /*
       * Sales. Receivables reporting arrives first, because the reports were finished before
       * anything could reach them; the customer and invoice screens the roadmap also names are
       * still outstanding.
       */
      {
        path: 'sales/outstanding-receivables',
        name: 'outstanding-receivables',
        component: () => import('@/pages/sales/OutstandingReceivablesPage.vue'),
        meta: { permission: 'sales.reports.view', title: 'Outstanding receivables' },
      },
      {
        path: 'sales/aged-receivables',
        name: 'aged-receivables',
        component: () => import('@/pages/sales/AgedReceivablesPage.vue'),
        meta: { permission: 'sales.reports.view', title: 'Aged receivables' },
      },
      {
        path: 'sales/ar-control',
        name: 'ar-control',
        component: () => import('@/pages/sales/ArControlPage.vue'),
        meta: { permission: 'sales.reports.view', title: 'AR control' },
      },
      /*
       * Customer and invoice screens (ADR 0013, Phase 3 front end). Editor routes gate on
       * `.draft`/`.manage` rather than `.view`, so a viewer never reaches a form — the route
       * guard is necessary but not sufficient: every destructive or state-dependent action
       * button inside these screens additionally gates on the resource's own `capabilities`.
       */
      {
        path: 'sales/customers',
        name: 'customers',
        component: () => import('@/pages/sales/CustomersListPage.vue'),
        meta: { permission: 'sales.customers.view', title: 'Customers' },
      },
      {
        path: 'sales/customers/new',
        name: 'customer-new',
        component: () => import('@/pages/sales/CustomerFormPage.vue'),
        meta: { permission: 'sales.customers.manage', title: 'Add a customer' },
      },
      {
        path: 'sales/customers/:customerId',
        name: 'customer-detail',
        component: () => import('@/pages/sales/CustomerDetailPage.vue'),
        meta: { permission: 'sales.customers.view', title: 'Customer' },
      },
      {
        path: 'sales/customers/:customerId/edit',
        name: 'customer-edit',
        component: () => import('@/pages/sales/CustomerFormPage.vue'),
        meta: { permission: 'sales.customers.manage', title: 'Edit customer' },
      },
      {
        path: 'sales/invoices',
        name: 'invoices',
        component: () => import('@/pages/sales/SalesInvoicesListPage.vue'),
        meta: { permission: 'sales.invoices.view', title: 'Invoices' },
      },
      {
        path: 'sales/invoices/new',
        name: 'invoice-new',
        component: () => import('@/pages/sales/SalesInvoiceEditorPage.vue'),
        meta: { permission: 'sales.invoices.draft', title: 'New invoice' },
      },
      {
        path: 'sales/invoices/:invoiceId',
        name: 'invoice-detail',
        component: () => import('@/pages/sales/SalesInvoiceDetailPage.vue'),
        meta: { permission: 'sales.invoices.view', title: 'Invoice' },
      },
      {
        path: 'sales/invoices/:invoiceId/edit',
        name: 'invoice-edit',
        component: () => import('@/pages/sales/SalesInvoiceEditorPage.vue'),
        meta: { permission: 'sales.invoices.draft', title: 'Edit invoice' },
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
  scrollBehavior: (_to, _from, savedPosition) => savedPosition ?? { top: 0 },
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
