import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type {
  SessionPayload,
  SessionResponse,
  User,
  Workspace,
  CompanySummary,
} from '@/types/domain'

/**
 * Session state: who is signed in, what they may do, and which interstitials they owe.
 *
 * The whole shell renders from one `/auth/session` call, so this store is the single source
 * of truth for the user, their permissions, the workspace and the company list. Splitting
 * them across four stores would mean four requests before the first pixel.
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const workspace = ref<Workspace | null>(null)
  const companies = ref<CompanySummary[]>([])
  const permissions = ref<Set<string>>(new Set())
  const requires = ref({
    password_change: false,
    two_factor_enrolment: false,
    company_selection: false,
  })

  /** Distinguishes "not signed in" from "we have not asked yet", which the router needs. */
  const initialised = ref(false)
  const loading = ref(false)

  /** Held between the password step and the second-factor step. Never persisted. */
  const twoFactorChallenge = ref<string | null>(null)

  const isAuthenticated = computed(() => user.value !== null)
  const isOwner = computed(() => user.value?.is_owner ?? false)

  const activeCompany = computed<CompanySummary | null>(
    () => companies.value.find((c) => c.is_default) ?? companies.value[0] ?? null,
  )

  /**
   * Permission check for *presentation only*.
   *
   * The server authorises every request independently; this exists so the interface can hide
   * a control the user cannot use, rather than offering it and producing a 403. Treating it
   * as a security boundary would be a mistake, and the whole front end is written on that
   * assumption.
   */
  function can(permission: string): boolean {
    return isOwner.value || permissions.value.has(permission)
  }

  function canAny(...candidates: string[]): boolean {
    return isOwner.value || candidates.some((p) => permissions.value.has(p))
  }

  function apply(payload: SessionPayload): void {
    user.value = payload.user
    workspace.value = payload.workspace
    companies.value = payload.companies
    permissions.value = new Set(payload.permissions)
    requires.value = payload.requires

    api.setActiveCompany(payload.user.default_company?.id ?? null)
  }

  function clear(): void {
    user.value = null
    workspace.value = null
    companies.value = []
    permissions.value = new Set()
    requires.value = {
      password_change: false,
      two_factor_enrolment: false,
      company_selection: false,
    }
    twoFactorChallenge.value = null
    api.setActiveCompany(null)
  }

  /**
   * Loads the session. Called once at boot and again after any state change that alters
   * permissions, so the interface never renders against a stale capability set.
   */
  async function fetchSession(): Promise<boolean> {
    loading.value = true

    try {
      const { data } = await api.get<SessionResponse>('/auth/session')

      if (data.authenticated) {
        apply(data)
        return true
      }

      clear()
      return false
    } catch (error) {
      // A 401 here is the expected answer for a guest, not a failure worth surfacing.
      if (error instanceof ApiError && error.status === 401) {
        clear()
        return false
      }

      throw error
    } finally {
      loading.value = false
      initialised.value = true
    }
  }

  /**
   * Step one of sign-in.
   *
   * Returns whether a second factor is needed. The challenge is a successful outcome of a
   * correct password, not an error, so it resolves rather than throwing.
   */
  async function signIn(
    email: string,
    password: string,
    remember = false,
  ): Promise<{ twoFactorRequired: boolean }> {
    const { data } = await api.post<
      { two_factor_required: true; challenge: string; expires_in: number } | SessionPayload
    >('/auth/login', { email, password, remember })

    if ('two_factor_required' in data && data.two_factor_required) {
      twoFactorChallenge.value = data.challenge
      return { twoFactorRequired: true }
    }

    apply(data as SessionPayload)
    return { twoFactorRequired: false }
  }

  /** Step two: verify the second factor and complete sign-in. */
  async function completeTwoFactor(code: string, trustDevice = false): Promise<void> {
    if (twoFactorChallenge.value === null) {
      throw new Error('There is no sign-in attempt to complete.')
    }

    const { data } = await api.post<SessionPayload>('/auth/two-factor-challenge', {
      challenge: twoFactorChallenge.value,
      code,
      trust_device: trustDevice,
    })

    twoFactorChallenge.value = null
    apply(data)
  }

  async function signOut(everywhere = false): Promise<void> {
    try {
      await api.post(everywhere ? '/auth/logout-everywhere' : '/auth/logout')
    } finally {
      // Cleared even if the request failed: the user asked to leave, and leaving them
      // apparently signed in against a dead session is worse than a lost round trip.
      clear()
    }
  }

  /** Switches the company every subsequent request is scoped to. */
  async function selectCompany(companyId: string): Promise<void> {
    await api.post(`/companies/${companyId}/select`)
    await fetchSession()
  }

  return {
    user,
    workspace,
    companies,
    permissions,
    requires,
    initialised,
    loading,
    twoFactorChallenge,
    isAuthenticated,
    isOwner,
    activeCompany,
    can,
    canAny,
    fetchSession,
    signIn,
    completeTwoFactor,
    signOut,
    selectCompany,
    clear,
  }
})
