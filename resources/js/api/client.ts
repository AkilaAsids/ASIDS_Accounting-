import axios, { type AxiosInstance, type AxiosRequestConfig } from 'axios'
import type { ApiEnvelope, ListParams, ProblemDocument } from '@/types/api'

/**
 * The single HTTP client.
 *
 * Four responsibilities, all of which have to be here rather than at call sites:
 *
 *  1. **Cookie authentication.** Sanctum's stateful guard means no token is ever held in
 *     JavaScript, so XSS cannot exfiltrate a long-lived credential. The cost is a CSRF
 *     cookie handshake, which this client performs once and lazily.
 *
 *  2. **Typed errors.** Every failure arrives as an RFC 9457 problem document and leaves
 *     here as an `ApiError` with a stable `code`. Nothing downstream inspects a status
 *     number or matches an error message.
 *
 *  3. **Step-up replay.** A 428 `two-factor-confirmation-required` is not an error the
 *     user should see — it is a prompt. The client raises a hook, waits for a code, and
 *     replays the original request. Handling this per call site would mean every sensitive
 *     action reimplementing it, and one of them getting it wrong.
 *
 *  4. **Workspace and company headers**, so no call site has to remember them.
 */

export class ApiError extends Error {
  constructor(
    readonly problem: ProblemDocument,
    readonly status: number,
  ) {
    super(problem.detail || problem.title)
    this.name = 'ApiError'
  }

  /** The stable identifier, e.g. `validation-failed`. Branch on this, never on `title`. */
  get code(): string {
    return this.problem.type.split('/').pop() ?? 'unknown'
  }

  get requestId(): string | undefined {
    return this.problem.request_id
  }

  /** Field-level validation messages, flattened to one message per field for form display. */
  get fieldErrors(): Record<string, string> {
    const errors = this.problem.errors ?? {}
    return Object.fromEntries(
      Object.entries(errors).map(([field, messages]) => [field, messages[0] ?? '']),
    )
  }

  is(code: string): boolean {
    return this.code === code
  }
}

/** Raised when no network response arrived at all — offline, DNS, TLS, or a hard timeout. */
export class NetworkError extends Error {
  // `override` because `cause` is declared on Error itself since ES2022; without it the property
  // shadows the base member rather than filling it in, and anything reading `error.cause`
  // generically sees undefined.
  constructor(override readonly cause?: unknown) {
    super('Could not reach the server. Check your connection and try again.')
    this.name = 'NetworkError'
  }
}

type StepUpResolver = () => Promise<string | null>

interface Hooks {
  /** Called on 401. The auth store clears state and the router sends the user to sign-in. */
  onUnauthenticated?: () => void
  /** Called on 428 password-expired. */
  onPasswordExpired?: () => void
  /** Called on 428 two-factor-enrolment-required. */
  onTwoFactorEnrolmentRequired?: () => void
  /**
   * Called on 428 two-factor-confirmation-required. Should prompt for a TOTP code and
   * resolve with it, or resolve null if the user cancels.
   */
  onStepUpRequired?: StepUpResolver
}

class ApiClient {
  private readonly http: AxiosInstance
  private hooks: Hooks = {}
  private csrfReady: Promise<void> | null = null
  private companyId: string | null = null

  constructor() {
    this.http = axios.create({
      baseURL: import.meta.env.VITE_API_BASE_URL ?? '/api/v1',
      withCredentials: true,
      withXSRFToken: true,
      timeout: 30_000,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })

    this.http.interceptors.request.use((config) => {
      // The tenant is implicit in the hostname for the SPA, so the header is only needed
      // when running the dev server on localhost against a tenant subdomain.
      if (this.companyId) {
        config.headers.set('X-Company', this.companyId)
      }
      return config
    })
  }

  configure(hooks: Hooks): void {
    this.hooks = { ...this.hooks, ...hooks }
  }

  /** Scopes every subsequent request to a company. Set by the company switcher. */
  setActiveCompany(companyId: string | null): void {
    this.companyId = companyId
  }

  async get<T>(
    url: string,
    params?: ListParams | Record<string, unknown>,
  ): Promise<ApiEnvelope<T>> {
    return this.send<T>({ method: 'GET', url, params })
  }

  async post<T>(url: string, data?: unknown): Promise<ApiEnvelope<T>> {
    return this.send<T>({ method: 'POST', url, data })
  }

  async put<T>(url: string, data?: unknown): Promise<ApiEnvelope<T>> {
    return this.send<T>({ method: 'PUT', url, data })
  }

  async delete<T>(url: string, data?: unknown): Promise<ApiEnvelope<T>> {
    return this.send<T>({ method: 'DELETE', url, data })
  }

  /**
   * Fetches the CSRF cookie, once per page load.
   *
   * Memoised on the promise rather than a boolean so that several requests firing during
   * app boot share one handshake instead of racing three of them.
   */
  private async ensureCsrfCookie(): Promise<void> {
    this.csrfReady ??= axios
      .get('/sanctum/csrf-cookie', { withCredentials: true })
      .then(() => undefined)
      .catch((error: unknown) => {
        // Cleared so a transient failure does not poison every later request.
        this.csrfReady = null
        throw new NetworkError(error)
      })

    return this.csrfReady
  }

  private async send<T>(config: AxiosRequestConfig, isReplay = false): Promise<ApiEnvelope<T>> {
    const method = (config.method ?? 'GET').toUpperCase()

    if (method !== 'GET') {
      await this.ensureCsrfCookie()
    }

    try {
      const response = await this.http.request<ApiEnvelope<T>>(config)
      return response.data
    } catch (error) {
      return this.handleFailure<T>(error, config, isReplay)
    }
  }

  private async handleFailure<T>(
    error: unknown,
    config: AxiosRequestConfig,
    isReplay: boolean,
  ): Promise<ApiEnvelope<T>> {
    // The generic is passed to the guard rather than applied with a cast afterwards, so the
    // narrowing and the response body's type come from the same statement.
    if (!axios.isAxiosError<ProblemDocument>(error)) {
      throw error
    }

    const response = error.response

    if (!response) {
      throw new NetworkError(error)
    }

    // A 419 means the CSRF token expired — the session outlived the token, which happens
    // on a tab left open overnight. Re-handshake and retry once rather than surfacing a
    // failure the user can do nothing about.
    if (response.status === 419 && !isReplay) {
      this.csrfReady = null
      await this.ensureCsrfCookie()
      return this.send<T>(config, true)
    }

    const problem = this.toProblem(response.status, response.data)
    const apiError = new ApiError(problem, response.status)

    // Step-up: prompt, then replay the original request with the window open. Only once —
    // a second 428 after a successful confirmation means something else is wrong, and
    // retrying forever would trap the user in a code prompt.
    if (
      response.status === 428 &&
      apiError.is('two-factor-confirmation-required') &&
      !isReplay &&
      this.hooks.onStepUpRequired
    ) {
      const code = await this.hooks.onStepUpRequired()

      if (code === null) {
        throw apiError
      }

      await this.post('/auth/two-factor/confirm-session', { code })
      return this.send<T>(config, true)
    }

    if (response.status === 428 && apiError.is('password-expired')) {
      this.hooks.onPasswordExpired?.()
    }

    if (response.status === 428 && apiError.is('two-factor-enrolment-required')) {
      this.hooks.onTwoFactorEnrolmentRequired?.()
    }

    if (response.status === 401) {
      this.hooks.onUnauthenticated?.()
    }

    throw apiError
  }

  /**
   * Normalises whatever arrived into a problem document.
   *
   * A 502 from a load balancer, or an HTML error page from a misconfigured proxy, will not
   * be a problem document — and the SPA must still render something coherent rather than
   * throwing on a missing property.
   */
  private toProblem(status: number, body: unknown): ProblemDocument {
    if (body !== null && typeof body === 'object' && 'type' in body && 'title' in body) {
      return body as ProblemDocument
    }

    return {
      type: `https://docs.asidstech.com/errors/http-${status}`,
      title: 'Request failed',
      status,
      detail:
        status >= 500
          ? 'Something went wrong on our side. Please try again in a moment.'
          : 'The request could not be completed.',
    }
  }
}

export const api = new ApiClient()
