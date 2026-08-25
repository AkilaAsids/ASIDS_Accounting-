import { beforeEach, describe, expect, it, vi } from 'vitest'
import type * as ApiClientModule from '@/api/client'

/**
 * The typed wrapper over `companies/{company}/customers` (ADR 0013 §2).
 *
 * These specs pin the one thing that matters at this layer: every function builds the exact
 * URL and passes through the exact arguments given, with no extra shape-guessing — the pages'
 * own specs (`CustomersListPage.spec.ts`, `CustomerFormPage.spec.ts`, `CustomerDetailPage.spec.ts`)
 * already prove the request/response cycle end to end through a mounted page.
 */
const get = vi.fn()
const post = vi.fn()
const put = vi.fn()
const del = vi.fn()

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof ApiClientModule>('@/api/client')

  return {
    ...actual,
    api: { get, post, put, delete: del, setActiveCompany: vi.fn(), configure: vi.fn() },
  }
})

const {
  archiveCustomer,
  createCustomer,
  deactivateCustomer,
  deleteCustomer,
  getCustomer,
  listCustomers,
  reactivateCustomer,
  restoreCustomer,
  updateCustomer,
} = await import('@/api/customers')

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
  del.mockReset()
})

describe('api/customers', () => {
  it('listCustomers passes params through untouched', () => {
    void listCustomers('company-1', { q: 'silva', filter: { status: 'active' } })

    expect(get).toHaveBeenCalledWith('/companies/company-1/customers', {
      q: 'silva',
      filter: { status: 'active' },
    })
  })

  it('listCustomers defaults to no params', () => {
    void listCustomers('company-1')

    expect(get).toHaveBeenCalledWith('/companies/company-1/customers', {})
  })

  it('getCustomer builds the singular resource URL with no extra argument', () => {
    void getCustomer('company-1', 'cus-1')

    expect(get).toHaveBeenCalledWith('/companies/company-1/customers/cus-1')
  })

  it('createCustomer posts the body exactly as given', () => {
    void createCustomer('company-1', { name: 'Silva Traders', credit_limit: '-500.1234' })

    expect(post).toHaveBeenCalledWith('/companies/company-1/customers', {
      name: 'Silva Traders',
      credit_limit: '-500.1234',
    })
  })

  it('updateCustomer PUTs the body exactly as given, to the singular resource URL', () => {
    void updateCustomer('company-1', 'cus-1', { branch_id: null })

    expect(put).toHaveBeenCalledWith('/companies/company-1/customers/cus-1', { branch_id: null })
  })

  it.each([
    ['archiveCustomer', archiveCustomer, 'archive'],
    ['restoreCustomer', restoreCustomer, 'restore'],
    ['deactivateCustomer', deactivateCustomer, 'deactivate'],
    ['reactivateCustomer', reactivateCustomer, 'reactivate'],
  ] as const)('%s posts to the %s sub-route with no body', (_name, fn, suffix) => {
    void fn('company-1', 'cus-1')

    expect(post).toHaveBeenCalledWith(`/companies/company-1/customers/cus-1/${suffix}`)
  })

  it('deleteCustomer sends DELETE to the singular resource URL with no body', () => {
    void deleteCustomer('company-1', 'cus-1')

    expect(del).toHaveBeenCalledWith('/companies/company-1/customers/cus-1')
  })
})
