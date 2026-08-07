import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ProblemDocument } from '@/types/api'
// Type-only, alongside the dynamic import below. `await import()` yields runtime values, and a value
// destructured from it does not carry its class type — so `error as ApiError` would be using a value
// as a type. Importing the types statically keeps the assertions typed while the mock ordering keeps
// the values dynamic.
import type { ApiError as ApiErrorType, NetworkError as NetworkErrorType } from '@/api/client'

/**
 * The HTTP client.
 *
 * Four responsibilities live here rather than at call sites, and each is the kind of thing that
 * silently half-works: the CSRF handshake, the mapping of RFC 9457 problem documents to typed
 * errors, the automatic step-up replay, and the 419 re-handshake. A step-up replay that fires twice,
 * or a handshake that races three times during boot, produces symptoms nobody attributes to the
 * client.
 */

/** Scripted responses for the client's own axios instance. */
const requestMock = vi.fn()

/** The bare `axios.get` the CSRF handshake uses — deliberately not the instance. */
const csrfMock = vi.fn()

/** The config the client hands `axios.create()`. Asserted below; see the `instance setup` block. */
let createConfig: unknown

// The whole module is mocked because the client is a singleton built at import time: it calls
// `axios.create()` in its constructor and `axios.get()` for the handshake. A test that stubs only the
// instance leaves the handshake reaching for a network that is not there, which surfaces as a
// NetworkError on every write and looks like a bug in error mapping rather than a gap in the test.
vi.mock('axios', () => {
  const isAxiosError = (payload: unknown): boolean =>
    typeof payload === 'object' &&
    payload !== null &&
    (payload as { isAxiosError?: boolean }).isAxiosError === true

  const instance = {
    request: requestMock,
    interceptors: { request: { use: vi.fn() }, response: { use: vi.fn() } },
  }

  const axios = {
    create: (config: unknown) => {
      createConfig = config
      return instance
    },
    get: csrfMock,
    isAxiosError,
  }

  return { default: axios, isAxiosError }
})

const { ApiError, NetworkError, api } = await import('@/api/client')

/** Scripts the next responses, in order. */
function respondWith(...responses: Array<() => unknown>): void {
  let index = 0

  requestMock.mockReset()
  requestMock.mockImplementation((config: { method?: string; url?: string }) => {
    const next = responses[index++]

    if (next === undefined) {
      throw new Error(`No scripted response for call ${index}: ${config.method} ${config.url}`)
    }

    return Promise.resolve(next())
  })
}

/** Every URL the instance was asked for, in order. */
function requestedUrls(): string[] {
  return requestMock.mock.calls.map((call) => (call[0] as { url?: string }).url ?? '')
}

/** An envelope, as `ApiResponse` produces one. */
function envelope(data: unknown): { data: { data: unknown; meta: Record<string, string> } } {
  return { data: { data, meta: { request_id: 'req_01HZ', api_version: 'v1' } } }
}

/** An RFC 9457 problem document, as the server's exception renderer produces one. */
function problem(
  code: string,
  status: number,
  extra: Partial<ProblemDocument> = {},
): ProblemDocument {
  return {
    type: `https://docs.asidstech.com/errors/${code}`,
    title: 'Something went wrong',
    status,
    detail: 'A human readable explanation.',
    request_id: 'req_01HZ',
    ...extra,
  }
}

/** An axios-shaped rejection, which is what the client branches on. */
function axiosFailure(status: number, body: ProblemDocument): never {
  const error = new Error('Request failed') as Error & {
    isAxiosError: boolean
    response: { status: number; data: ProblemDocument }
  }

  error.isAxiosError = true
  error.response = { status, data: body }

  throw error
}

beforeEach(() => {
  csrfMock.mockReset()
  csrfMock.mockResolvedValue({ data: '' })

  // Hooks accumulate on the singleton, so each test starts from none — otherwise a step-up hook
  // registered in one test silently satisfies the next.
  api.configure({
    onUnauthenticated: undefined,
    onPasswordExpired: undefined,
    onTwoFactorEnrolmentRequired: undefined,
    onStepUpRequired: undefined,
  } as never)

  // The handshake promise is memoised for the life of the page, which is right in a browser and
  // wrong across tests.
  ;(api as unknown as { csrfReady: Promise<void> | null }).csrfReady = null
})

describe('successful requests', () => {
  it('returns the envelope body', async () => {
    respondWith(() => envelope([{ id: '1' }]))

    const result = await api.get<Array<{ id: string }>>('/users')

    expect(result.data).toEqual([{ id: '1' }])
    expect(result.meta.api_version).toBe('v1')
  })

  it('passes list parameters through', async () => {
    respondWith(() => envelope([]))

    await api.get('/users', { page: 2, filter: { status: 'active' } })

    expect((requestMock.mock.calls[0]?.[0] as { params?: unknown }).params).toEqual({
      page: 2,
      filter: { status: 'active' },
    })
  })

  it('supports each verb', async () => {
    respondWith(
      () => envelope(null),
      () => envelope(null),
      () => envelope(null),
      () => envelope(null),
    )

    await api.get('/a')
    await api.post('/b', { x: 1 })
    await api.put('/c', { x: 2 })
    await api.delete('/d')

    expect(requestMock.mock.calls.map((call) => (call[0] as { method: string }).method)).toEqual([
      'GET',
      'POST',
      'PUT',
      'DELETE',
    ])
  })
})

describe('the CSRF handshake', () => {
  it('is performed before a write', async () => {
    respondWith(() => envelope(null))

    await api.post('/users', {})

    expect(csrfMock).toHaveBeenCalledTimes(1)
  })

  it('is skipped for a read', async () => {
    respondWith(() => envelope(null))

    await api.get('/users')

    // A GET cannot be a CSRF target, and paying a round trip before every read would double the
    // request count of the entire application.
    expect(csrfMock).not.toHaveBeenCalled()
  })

  it('is performed once even when several writes start together', async () => {
    respondWith(
      () => envelope(null),
      () => envelope(null),
      () => envelope(null),
    )

    await Promise.all([api.post('/a', {}), api.post('/b', {}), api.post('/c', {})])

    // Memoised on the *promise*, not on a boolean. A boolean is only set once the first handshake
    // resolves, so three requests firing during app boot would race three handshakes.
    expect(csrfMock).toHaveBeenCalledTimes(1)
  })

  it('does not poison later requests when it fails', async () => {
    csrfMock.mockRejectedValueOnce(new Error('offline'))
    respondWith(() => envelope(null))

    await expect(api.post('/users', {})).rejects.toBeInstanceOf(NetworkError)

    csrfMock.mockResolvedValue({ data: '' })

    // The memo is cleared on failure, so a transient outage does not make every subsequent write
    // fail for the life of the page.
    await expect(api.post('/users', {})).resolves.toBeTruthy()
    expect(csrfMock).toHaveBeenCalledTimes(2)
  })

  it('re-handshakes once and retries after a 419', async () => {
    respondWith(
      () => axiosFailure(419, problem('csrf-token-mismatch', 419)),
      () => envelope({ ok: true }),
    )

    const result = await api.post<{ ok: boolean }>('/users', {})

    // A 419 means the token expired — a tab left open overnight. Surfacing it would be an error the
    // user can do nothing about, so the client refreshes and retries once.
    expect(result.data.ok).toBe(true)
    expect(csrfMock).toHaveBeenCalledTimes(2)
  })

  it('does not retry a 419 twice', async () => {
    respondWith(
      () => axiosFailure(419, problem('csrf-token-mismatch', 419)),
      () => axiosFailure(419, problem('csrf-token-mismatch', 419)),
    )

    const error = await api.post('/users', {}).catch((thrown: unknown) => thrown)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiErrorType).status).toBe(419)
  })
})

describe('problem documents', () => {
  it('maps a failure to a typed ApiError with a stable code', async () => {
    respondWith(() => axiosFailure(422, problem('validation-failed', 422)))

    const error = await api.post('/users', {}).catch((thrown: unknown) => thrown)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiErrorType).code).toBe('validation-failed')
    expect((error as ApiErrorType).status).toBe(422)
  })

  it('exposes the request id so support can be quoted one', async () => {
    respondWith(() => axiosFailure(500, problem('server-error', 500)))

    const error = (await api.get('/users').catch((thrown: unknown) => thrown)) as ApiErrorType

    expect(error.requestId).toBe('req_01HZ')
  })

  it('flattens field errors to one message per field for form display', async () => {
    respondWith(() =>
      axiosFailure(
        422,
        problem('validation-failed', 422, {
          errors: {
            email: ['That address is already in use.', 'Second message.'],
            first_name: ['Required.'],
          },
        }),
      ),
    )

    const error = (await api.post('/users', {}).catch((thrown: unknown) => thrown)) as ApiErrorType

    // One per field: a form shows a single message under an input, and joining them produces a
    // paragraph in a space designed for a sentence.
    expect(error.fieldErrors).toEqual({
      email: 'That address is already in use.',
      first_name: 'Required.',
    })
  })

  it('branches on the code rather than the title', async () => {
    respondWith(() =>
      axiosFailure(403, problem('forbidden', 403, { title: 'Reworded by someone' })),
    )

    const error = (await api.get('/audit').catch((thrown: unknown) => thrown)) as ApiErrorType

    // `title` is prose and may be reworded or translated. `is()` reads the stable identifier.
    expect(error.is('forbidden')).toBe(true)
    expect(error.is('validation-failed')).toBe(false)
  })

  it('uses the detail as the error message so a caught error reads correctly', async () => {
    respondWith(() =>
      axiosFailure(422, problem('business-rule-violation', 422, { detail: 'That branch is primary.' })),
    )

    const error = (await api.get('/x').catch((thrown: unknown) => thrown)) as ApiErrorType

    expect(error.message).toBe('That branch is primary.')
  })
})

describe('network failures', () => {
  it('raises NetworkError when no response arrived at all', async () => {
    respondWith(() => {
      const error = new Error('Network Error') as Error & {
        isAxiosError: boolean
        response: undefined
      }
      error.isAxiosError = true
      error.response = undefined
      throw error
    })

    const error = await api.get('/users').catch((thrown: unknown) => thrown)

    // Distinct from ApiError on purpose: "the server said no" and "the server said nothing" need
    // different messages, and only the second is worth a retry button.
    expect(error).toBeInstanceOf(NetworkError)
    expect((error as NetworkErrorType).message).toContain('Check your connection')
  })

  it('rethrows a non-axios error untouched', async () => {
    const thrown = new TypeError('a bug in our own code')

    respondWith(() => {
      throw thrown
    })

    await expect(api.get('/users')).rejects.toBe(thrown)
  })
})

describe('step-up replay', () => {
  it('prompts for a code and replays the original request', async () => {
    const onStepUpRequired = vi.fn().mockResolvedValue('123456')

    api.configure({ onStepUpRequired })

    respondWith(
      // 1. The original request is refused with a step-up demand.
      () => axiosFailure(428, problem('two-factor-confirmation-required', 428)),
      // 2. The client confirms the session with the code it was given.
      () => envelope({ confirmed: true }),
      // 3. The original request, replayed.
      () => envelope({ transferred: true }),
    )

    const result = await api.post<{ transferred: boolean }>('/users/1/transfer-ownership')

    // The point of doing this here rather than at each call site: every sensitive action would
    // otherwise reimplement it, and one of them would get it wrong.
    expect(onStepUpRequired).toHaveBeenCalledTimes(1)
    expect(result.data.transferred).toBe(true)
    expect(requestedUrls()).toEqual([
      '/users/1/transfer-ownership',
      '/auth/two-factor/confirm-session',
      '/users/1/transfer-ownership',
    ])
  })

  it('gives up when the user cancels the prompt', async () => {
    const onStepUpRequired = vi.fn().mockResolvedValue(null)

    api.configure({ onStepUpRequired })

    respondWith(() => axiosFailure(428, problem('two-factor-confirmation-required', 428)))

    const error = await api.post('/users/1/transfer-ownership').catch((thrown: unknown) => thrown)

    // Cancelling is a decision, not a failure to retry. The original error surfaces so the caller
    // can leave the screen as it was.
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiErrorType).is('two-factor-confirmation-required')).toBe(true)
  })

  it('does not replay more than once', async () => {
    const onStepUpRequired = vi.fn().mockResolvedValue('123456')

    api.configure({ onStepUpRequired })

    respondWith(
      () => axiosFailure(428, problem('two-factor-confirmation-required', 428)),
      () => envelope({ confirmed: true }),
      // The replay is refused again, which means something else is wrong.
      () => axiosFailure(428, problem('two-factor-confirmation-required', 428)),
    )

    const error = await api.post('/users/1/transfer-ownership').catch((thrown: unknown) => thrown)

    // A second 428 after a successful confirmation is not a prompt worth repeating — retrying
    // forever would trap the user in a code dialog they cannot satisfy.
    expect(onStepUpRequired).toHaveBeenCalledTimes(1)
    expect(error).toBeInstanceOf(ApiError)
  })

  it('does nothing special when no step-up hook is registered', async () => {
    respondWith(() => axiosFailure(428, problem('two-factor-confirmation-required', 428)))

    const error = await api.post('/users/1/transfer-ownership').catch((thrown: unknown) => thrown)

    expect(error).toBeInstanceOf(ApiError)
  })
})

describe('other 428 interstitials', () => {
  it('raises the password-expired hook', async () => {
    const onPasswordExpired = vi.fn()

    api.configure({ onPasswordExpired })

    respondWith(() => axiosFailure(428, problem('password-expired', 428)))

    await api.get('/users').catch(() => undefined)

    expect(onPasswordExpired).toHaveBeenCalledTimes(1)
  })

  it('raises the enrolment hook', async () => {
    const onTwoFactorEnrolmentRequired = vi.fn()

    api.configure({ onTwoFactorEnrolmentRequired })

    respondWith(() => axiosFailure(428, problem('two-factor-enrolment-required', 428)))

    await api.get('/users').catch(() => undefined)

    expect(onTwoFactorEnrolmentRequired).toHaveBeenCalledTimes(1)
  })
})

describe('unauthenticated responses', () => {
  it('raises the hook so the store clears and the router redirects', async () => {
    const onUnauthenticated = vi.fn()

    api.configure({ onUnauthenticated })

    respondWith(() => axiosFailure(401, problem('unauthenticated', 401)))

    await api.get('/users').catch(() => undefined)

    // One place decides what a 401 means. Handling it per call site is how half the screens end up
    // silently blank instead of redirecting.
    expect(onUnauthenticated).toHaveBeenCalledTimes(1)
  })
})

describe('instance setup', () => {
  it('sends cookies and the CSRF header on every request', () => {
    const config = createConfig as {
      withCredentials?: boolean
      withXSRFToken?: boolean
      baseURL?: string
      headers?: Record<string, string>
    }

    // `withCredentials` alone is not enough. Sanctum's stateful guard reads the `X-XSRF-TOKEN`
    // header, and axios only mirrors the XSRF cookie into that header when `withXSRFToken` is set —
    // it became opt-in in axios 1.7. Without it, every GET succeeds and every write fails CSRF, which
    // reads as a session problem rather than a client configuration one.
    expect(config.withCredentials).toBe(true)
    expect(config.withXSRFToken).toBe(true)

    // Declares JSON both ways, so the API's content negotiation never falls back to an HTML error
    // page that the problem-document mapping cannot parse.
    expect(config.headers?.Accept).toBe('application/json')
    expect(config.headers?.['X-Requested-With']).toBe('XMLHttpRequest')
  })

  it('has a timeout, so a hung request cannot pin the interface open forever', () => {
    const config = createConfig as { timeout?: number }

    expect(config.timeout).toBeGreaterThan(0)
  })
})
