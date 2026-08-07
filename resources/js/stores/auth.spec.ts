import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import type { SessionPayload } from '@/types/domain'
// Imported as a type so `importActual` can be given the module's shape without an inline
// `import()` annotation, which the lint config forbids for the same reason it forbids inline
// requires: the dependency becomes invisible to anything reading the import block.
import type * as ApiClientModule from '@/api/client'

/**
 * The session store.
 *
 * The whole shell renders from one `/auth/session` call, so this store is the single source of truth
 * for the user, their permissions, the workspace and the company list. Two properties matter most:
 * `can()` is presentation-only and must never be mistaken for a security boundary, and a cleared
 * store must leave nothing behind — a stale permission set after sign-out is a menu the next user of
 * a shared browser can see.
 */

const get = vi.fn()
const post = vi.fn()
const setActiveCompany = vi.fn()

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof ApiClientModule>('@/api/client')

  return {
    ...actual,
    api: { get, post, setActiveCompany, configure: vi.fn() },
  }
})

const { useAuthStore } = await import('@/stores/auth')
const { ApiError } = await import('@/api/client')

function sessionPayload(overrides: Partial<SessionPayload> = {}): SessionPayload {
  return {
    authenticated: true,
    user: {
      id: 'user-1',
      first_name: 'Kumari',
      last_name: 'Silva',
      full_name: 'Kumari Silva',
      email: 'kumari@acme.test',
      status: 'active',
      status_label: 'Active',
      is_owner: false,
      default_company: { id: 'company-1', name: 'Demo Trading', code: 'DTL' },
    },
    permissions: ['identity.users.view', 'organization.companies.view'],
    workspace: { id: 'tenant-1', name: 'Acme', slug: 'acme' },
    companies: [
      { id: 'company-1', name: 'Demo Trading', code: 'DTL', is_default: true },
      { id: 'company-2', name: 'Second Books', code: 'SEC', is_default: false, base_currency_code: 'LKR', currency_precision: 2, timezone: 'Asia/Colombo' },
    ],
    requires: { password_change: false, two_factor_enrolment: false, company_selection: false },
    ...overrides,
  } as SessionPayload
}

beforeEach(() => {
  setActivePinia(createPinia())
  get.mockReset()
  post.mockReset()
  setActiveCompany.mockReset()
})

describe('loading the session', () => {
  it('applies the payload and reports authenticated', async () => {
    get.mockResolvedValue({ data: sessionPayload(), meta: {} })

    const auth = useAuthStore()

    await expect(auth.fetchSession()).resolves.toBe(true)

    expect(auth.isAuthenticated).toBe(true)
    expect(auth.user?.email).toBe('kumari@acme.test')
    expect(auth.workspace?.slug).toBe('acme')
    expect(auth.companies).toHaveLength(2)
  })

  it('scopes subsequent requests to the user’s default company', async () => {
    get.mockResolvedValue({ data: sessionPayload(), meta: {} })

    await useAuthStore().fetchSession()

    // The client sends `X-Company` from here on, so a deep link opens in the company the user last
    // chose rather than whichever one the server picks.
    expect(setActiveCompany).toHaveBeenCalledWith('company-1')
  })

  it('treats an unauthenticated payload as a clean guest state', async () => {
    get.mockResolvedValue({ data: { authenticated: false }, meta: {} })

    const auth = useAuthStore()

    await expect(auth.fetchSession()).resolves.toBe(false)

    expect(auth.isAuthenticated).toBe(false)
    expect(auth.initialised).toBe(true)
  })

  it('treats a 401 as a guest rather than an error worth surfacing', async () => {
    get.mockRejectedValue(
      new ApiError(
        { type: 'x/unauthenticated', title: 'Unauthenticated', status: 401, detail: 'No session.' },
        401,
      ),
    )

    const auth = useAuthStore()

    // The expected answer for a first-time visitor. Surfacing it would put an error banner on the
    // sign-in screen of every new user.
    await expect(auth.fetchSession()).resolves.toBe(false)
    expect(auth.initialised).toBe(true)
  })

  it('rethrows anything that is not a 401', async () => {
    get.mockRejectedValue(
      new ApiError({ type: 'x/server-error', title: 'Error', status: 500, detail: 'Boom.' }, 500),
    )

    await expect(useAuthStore().fetchSession()).rejects.toBeInstanceOf(ApiError)
  })

  it('marks itself initialised even when the call fails', async () => {
    get.mockRejectedValue(
      new ApiError({ type: 'x/server-error', title: 'Error', status: 500, detail: 'Boom.' }, 500),
    )

    const auth = useAuthStore()

    await auth.fetchSession().catch(() => undefined)

    // The router distinguishes "not signed in" from "we have not asked yet". Without this, a failed
    // boot leaves every navigation waiting on a session fetch that will never be retried.
    expect(auth.initialised).toBe(true)
  })
})

describe('permission checks', () => {
  it('reports a held permission', async () => {
    get.mockResolvedValue({ data: sessionPayload(), meta: {} })

    const auth = useAuthStore()
    await auth.fetchSession()

    expect(auth.can('identity.users.view')).toBe(true)
    expect(auth.can('identity.users.invite')).toBe(false)
  })

  it('grants an owner everything without enumerating it', async () => {
    get.mockResolvedValue({
      data: sessionPayload({
        user: { ...sessionPayload().user, is_owner: true },
        permissions: [],
      }),
      meta: {},
    })

    const auth = useAuthStore()
    await auth.fetchSession()

    // Mirrors the server's `Gate::before` short circuit. An exhaustive list would mean a capability
    // added in a later phase is silently missing from the paying customer's own role.
    expect(auth.can('anything.at.all')).toBe(true)
  })

  it('answers canAny for the first match', async () => {
    get.mockResolvedValue({ data: sessionPayload(), meta: {} })

    const auth = useAuthStore()
    await auth.fetchSession()

    expect(auth.canAny('nothing.here', 'identity.users.view')).toBe(true)
    expect(auth.canAny('nothing.here', 'nothing.there')).toBe(false)
  })

  it('reports nothing before a session is loaded', () => {
    // Fail closed. A menu that renders before the session arrives must show nothing rather than
    // everything.
    expect(useAuthStore().can('identity.users.view')).toBe(false)
  })
})

describe('the active company', () => {
  it('prefers the company marked default', async () => {
    get.mockResolvedValue({ data: sessionPayload(), meta: {} })

    const auth = useAuthStore()
    await auth.fetchSession()

    expect(auth.activeCompany?.id).toBe('company-1')
  })

  it('falls back to the first company when none is marked default', async () => {
    get.mockResolvedValue({
      data: sessionPayload({
        companies: [
          { id: 'company-2', name: 'Second Books', code: 'SEC', is_default: false, base_currency_code: 'LKR', currency_precision: 2, timezone: 'Asia/Colombo' },
          { id: 'company-3', name: 'Third', code: 'THD', is_default: false, base_currency_code: 'LKR', currency_precision: 2, timezone: 'Asia/Colombo' },
        ],
      }),
      meta: {},
    })

    const auth = useAuthStore()
    await auth.fetchSession()

    // A user whose default company was archived still has to land somewhere.
    expect(auth.activeCompany?.id).toBe('company-2')
  })

  it('is null when the user has no company at all', async () => {
    get.mockResolvedValue({ data: sessionPayload({ companies: [] }), meta: {} })

    const auth = useAuthStore()
    await auth.fetchSession()

    expect(auth.activeCompany).toBeNull()
  })
})

describe('two-step sign-in', () => {
  it('applies the session when no second factor is needed', async () => {
    post.mockResolvedValue({ data: sessionPayload(), meta: {} })

    const auth = useAuthStore()

    await expect(auth.signIn('kumari@acme.test', 'secret')).resolves.toEqual({
      twoFactorRequired: false,
    })

    expect(auth.isAuthenticated).toBe(true)
  })

  it('holds the challenge without authenticating when a second factor is needed', async () => {
    post.mockResolvedValue({
      data: { two_factor_required: true, challenge: 'challenge-token', expires_in: 300 },
      meta: {},
    })

    const auth = useAuthStore()

    await expect(auth.signIn('kumari@acme.test', 'secret')).resolves.toEqual({
      twoFactorRequired: true,
    })

    // A challenge is the successful outcome of a correct password, not an error — so it resolves
    // rather than throwing, and the user is not yet authenticated.
    expect(auth.twoFactorChallenge).toBe('challenge-token')
    expect(auth.isAuthenticated).toBe(false)
  })

  it('completes the second step and clears the challenge', async () => {
    post.mockResolvedValueOnce({
      data: { two_factor_required: true, challenge: 'challenge-token', expires_in: 300 },
      meta: {},
    })
    post.mockResolvedValueOnce({ data: sessionPayload(), meta: {} })

    const auth = useAuthStore()

    await auth.signIn('kumari@acme.test', 'secret')
    await auth.completeTwoFactor('123456', true)

    expect(auth.isAuthenticated).toBe(true)
    // Never persisted, and cleared the moment it is spent.
    expect(auth.twoFactorChallenge).toBeNull()
  })

  it('refuses to complete a second step that was never started', async () => {
    await expect(useAuthStore().completeTwoFactor('123456')).rejects.toThrow(
      'no sign-in attempt to complete',
    )
  })
})

describe('signing out', () => {
  it('clears every trace of the session', async () => {
    get.mockResolvedValue({ data: sessionPayload(), meta: {} })
    post.mockResolvedValue({ data: null, meta: {} })

    const auth = useAuthStore()
    await auth.fetchSession()
    await auth.signOut()

    expect(auth.user).toBeNull()
    expect(auth.workspace).toBeNull()
    expect(auth.companies).toEqual([])
    expect(auth.can('identity.users.view')).toBe(false)
    expect(setActiveCompany).toHaveBeenLastCalledWith(null)
  })

  it('clears the session even when the request fails', async () => {
    get.mockResolvedValue({ data: sessionPayload(), meta: {} })
    post.mockRejectedValue(new Error('offline'))

    const auth = useAuthStore()
    await auth.fetchSession()

    await auth.signOut().catch(() => undefined)

    // The user asked to leave. Leaving them apparently signed in against a dead session is worse
    // than a lost round trip — particularly on a shared machine.
    expect(auth.isAuthenticated).toBe(false)
  })

  it('signs out everywhere when asked', async () => {
    post.mockResolvedValue({ data: null, meta: {} })

    await useAuthStore().signOut(true)

    expect(post).toHaveBeenCalledWith('/auth/logout-everywhere')
  })
})

describe('switching company', () => {
  it('selects the company and reloads the session', async () => {
    post.mockResolvedValue({ data: null, meta: {} })
    get.mockResolvedValue({ data: sessionPayload(), meta: {} })

    await useAuthStore().selectCompany('company-2')

    // Reloaded rather than patched locally: the permission set and the company list are both
    // company-dependent, and guessing at the new state is how a switcher shows the wrong books.
    expect(post).toHaveBeenCalledWith('/companies/company-2/select')
    expect(get).toHaveBeenCalledWith('/auth/session')
  })
})
