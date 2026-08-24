import { describe, expect, it, vi } from 'vitest'
import type * as ApiClientModule from '@/api/client'

const get = vi.fn()
const post = vi.fn()
const put = vi.fn()
const del = vi.fn()

vi.mock('@/api/client', async () => {
  const actual = await vi.importActual<typeof ApiClientModule>('@/api/client')
  return { ...actual, api: { ...actual.api, get, post, put, delete: del } }
})

const {
  listSalesInvoices,
  getSalesInvoice,
  createSalesInvoice,
  updateSalesInvoice,
  deleteSalesInvoice,
  issueSalesInvoice,
  cancelSalesInvoice,
} = await import('@/api/salesInvoices')

const meta = { request_id: 'r', api_version: '1' }

describe('api/salesInvoices', () => {
  it('lists with the given params and never adds include=lines', async () => {
    get.mockResolvedValue({ data: [], meta })

    await listSalesInvoices('company-1', { page: 2, filter: { status: 'draft' } })

    const [url, params] = get.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toBe('/companies/company-1/sales-invoices')
    expect(params).toEqual({ page: 2, filter: { status: 'draft' } })
    expect('include' in params).toBe(false)
  })

  it('gets a single invoice with no extra params', async () => {
    get.mockResolvedValue({ data: {}, meta })

    await getSalesInvoice('company-1', 'inv-1')

    expect(get).toHaveBeenCalledWith('/companies/company-1/sales-invoices/inv-1')
  })

  it('creates against the collection endpoint with the given body', async () => {
    post.mockResolvedValue({ data: {}, meta })
    const body = { customer_id: 'cus-1', invoice_date: '2026-06-01', lines: [] }

    await createSalesInvoice('company-1', body as never)

    expect(post).toHaveBeenCalledWith('/companies/company-1/sales-invoices', body)
  })

  it('updates against the item endpoint with the given (possibly partial) body', async () => {
    put.mockResolvedValue({ data: {}, meta })
    const body = { reference: null }

    await updateSalesInvoice('company-1', 'inv-1', body)

    expect(put).toHaveBeenCalledWith('/companies/company-1/sales-invoices/inv-1', body)
  })

  it('deletes with no body', async () => {
    del.mockResolvedValue({ data: null, meta })

    await deleteSalesInvoice('company-1', 'inv-1')

    expect(del).toHaveBeenCalledWith('/companies/company-1/sales-invoices/inv-1')
  })

  it('issues with a bare POST — no body', async () => {
    post.mockResolvedValue({ data: {}, meta })

    await issueSalesInvoice('company-1', 'inv-1')

    expect(post).toHaveBeenCalledWith('/companies/company-1/sales-invoices/inv-1/issue')
  })

  it('cancels with the reason in the body', async () => {
    post.mockResolvedValue({ data: {}, meta })

    await cancelSalesInvoice('company-1', 'inv-1', 'Customer requested it.')

    expect(post).toHaveBeenCalledWith('/companies/company-1/sales-invoices/inv-1/cancel', {
      reason: 'Customer requested it.',
    })
  })
})
