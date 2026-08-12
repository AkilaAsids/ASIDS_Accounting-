<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Controllers;

use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Domain\Query\QueryCriteria;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Asids\Core\Sales\Application\DTOs\CustomerData;
use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Presentation\Http\Requests\StoreCustomerRequest;
use Asids\Core\Sales\Presentation\Http\Requests\UpdateCustomerRequest;
use Asids\Core\Sales\Presentation\Http\Resources\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The parties a company invoices, nested under their company.
 *
 * A growing, searchable list rather than a bounded catalogue like the chart of accounts or the
 * tax codes, so the index follows `JournalEntryController::index` — paginated, `q` search,
 * allow-listed sort/filter — rather than the unpaginated `AccountController` one.
 *
 * State transitions (archive, restore, deactivate, reactivate) each map onto a named service
 * method rather than a settable `status` field, the same reasoning `JournalEntryController`
 * gives for keeping posting and reversing as their own endpoints.
 */
final class CustomerController extends ApiController
{
    public function __construct(
        private readonly CustomerService $customers,
    ) {}

    public function index(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('viewAny', Customer::class);

        $criteria = QueryCriteria::fromRequest(
            $request,
            sortable: ['name', 'code', 'created_at'],
            filterable: ['status', 'branch_id'],
            defaultSort: 'name',
        );

        $customers = Customer::query()
            ->forCompany((string) $company->getKey())
            ->when(
                $criteria->hasFilter('status'),
                static fn ($query) => $query->where('status', $criteria->filters()['status']),
            )
            ->when(
                $criteria->hasFilter('branch_id'),
                static fn ($query) => $query->where('branch_id', $criteria->filters()['branch_id']),
            )
            ->when(
                $criteria->search() !== null,
                static fn ($query) => $query->where(static function ($inner) use ($criteria): void {
                    $term = '%'.$criteria->search().'%';
                    $inner->where('name', 'ilike', $term)
                        ->orWhere('code', 'ilike', $term);
                }),
            )
            ->tap(static function ($query) use ($criteria): void {
                foreach ($criteria->sorts() as $column => $direction) {
                    $query->orderBy($column, $direction);
                }
            })
            ->paginate($criteria->perPage(), page: $criteria->page())
            ->withQueryString();

        return ApiResponse::collection(CustomerResource::collection($customers));
    }

    public function show(Company $company, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return ApiResponse::item(new CustomerResource($customer));
    }

    public function store(StoreCustomerRequest $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('create', Customer::class);

        $customer = $this->customers->create(
            $company,
            CustomerData::fromArray($request->validated()),
            $this->currentUser()->getKey(),
        );

        return ApiResponse::created(new CustomerResource($customer));
    }

    public function update(UpdateCustomerRequest $request, Company $company, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        return ApiResponse::item(new CustomerResource(
            $this->customers->update($customer, $request->validated()),
        ));
    }

    /**
     * Remove a customer created in error.
     *
     * Refused by the service for any customer already invoiced — archiving is the ordinary path
     * for a customer no longer sold to.
     */
    public function destroy(Company $company, Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        $this->customers->delete($customer);

        return ApiResponse::noContent();
    }

    public function archive(Company $company, Customer $customer): JsonResponse
    {
        $this->authorize('archive', $customer);

        return ApiResponse::item(new CustomerResource($this->customers->archive($customer)));
    }

    public function restore(Company $company, Customer $customer): JsonResponse
    {
        $this->authorize('restore', $customer);

        return ApiResponse::item(new CustomerResource($this->customers->restore($customer)));
    }

    /**
     * `CustomerPolicy` has no `deactivate`/`reactivate` methods of its own — the same permission
     * as an ordinary update governs both, following how `TaxCodePolicy` delegates its equivalent
     * actions (design §4.1, ADR 0008 §D3).
     */
    public function deactivate(Company $company, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        return ApiResponse::item(new CustomerResource($this->customers->deactivate($customer)));
    }

    public function reactivate(Company $company, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        return ApiResponse::item(new CustomerResource($this->customers->reactivate($customer)));
    }
}
