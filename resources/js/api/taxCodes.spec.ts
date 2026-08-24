import { describe, expect, it, vi } from 'vitest'
import type * as ApiClientModule from '@/api/client'

const get = vi.fn()

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof ApiClientModule>('@/api/client')
  return { ...actual, api: { ...actual.api, get } }
})

const { listTaxCodes } = await import('@/api/taxCodes')

describe('listTaxCodes', () => {
  it('requests active_only=true by default', async () => {
    get.mockResolvedValue({ data: [], meta: { request_id: 'r', api_version: '1' } })

    await listTaxCodes('company-1')

    expect(get).toHaveBeenCalledWith('/companies/company-1/tax-codes', { active_only: true })
  })

  it('passes activeOnly through explicitly', async () => {
    get.mockResolvedValue({ data: [], meta: { request_id: 'r', api_version: '1' } })

    await listTaxCodes('company-1', false)

    expect(get).toHaveBeenCalledWith('/companies/company-1/tax-codes', { active_only: false })
  })
})
