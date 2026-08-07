<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Presentation\Http\Controllers;

use Asids\Core\Accounting\Application\DTOs\CreateAccountData;
use Asids\Core\Accounting\Application\Services\ChartOfAccountsService;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Domain\Catalogue\ChartTemplate;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Presentation\Http\Requests\StoreAccountRequest;
use Asids\Core\Accounting\Presentation\Http\Requests\UpdateAccountRequest;
use Asids\Core\Accounting\Presentation\Http\Resources\AccountResource;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The chart of accounts, nested under its company.
 *
 * Nested because an account has no meaning outside one company's books, and a flat `/accounts/{id}`
 * would invite queries that forget to scope by company — which in a product where one workspace holds
 * several legal entities is how one company's figures end up in another's report.
 */
final class AccountController extends ApiController
{
    public function __construct(
        private readonly ChartOfAccountsService $chart,
        private readonly ChartTemplateService $template,
    ) {}

    public function index(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('viewAny', Account::class);

        $accounts = Account::query()
            ->forCompany((string) $company->getKey())
            ->when($request->boolean('active_only', true), static fn ($query) => $query->active())
            ->when(
                $request->filled('type'),
                static fn ($query) => $query->where('type', $request->string('type')->toString()),
            )
            ->when(
                $request->boolean('postable_only'),
                static fn ($query) => $query->postable(),
            )
            // Type then code: the order a chart is read and printed in. Alphabetical by name would
            // scatter the current assets through the fixed ones.
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        return ApiResponse::collection(
            collection: AccountResource::collection($accounts),
            meta: ['total' => $accounts->count()],
        );
    }

    public function show(Company $company, Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        return ApiResponse::item(new AccountResource($account->load('children')));
    }

    public function store(StoreAccountRequest $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('create', Account::class);

        $account = $this->chart->create($company, CreateAccountData::fromArray($request->validated()));

        return ApiResponse::created(new AccountResource($account));
    }

    public function update(UpdateAccountRequest $request, Company $company, Account $account): JsonResponse
    {
        $this->authorize('update', $account);

        return ApiResponse::item(new AccountResource(
            $this->chart->update($account, $request->validated()),
        ));
    }

    /**
     * Deletion, which the service refuses for any account with history.
     *
     * Offered anyway rather than hidden, because the alternative is a client that cannot remove an
     * account created by mistake five seconds ago. The refusal names archiving as the remedy.
     */
    public function destroy(Company $company, Account $account): JsonResponse
    {
        $this->authorize('delete', $account);

        $this->chart->delete($account);

        return ApiResponse::noContent();
    }

    public function archive(Company $company, Account $account): JsonResponse
    {
        $this->authorize('archive', $account);

        return ApiResponse::item(new AccountResource($this->chart->archive($account)));
    }

    public function restore(Company $company, Account $account): JsonResponse
    {
        $this->authorize('archive', $account);

        return ApiResponse::item(new AccountResource($this->chart->restore($account)));
    }

    /**
     * The starter template on offer, and its disclaimer.
     *
     * The disclaimer travels in the payload rather than living in documentation, because the person
     * about to click "apply" is looking at this response and not at a manual.
     */
    public function template(Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('viewAny', Account::class);

        return ApiResponse::item([
            'version' => ChartTemplate::VERSION,
            'name' => ChartTemplate::name(),
            'description' => ChartTemplate::description(),
            'disclaimer' => ChartTemplate::disclaimer(),
            'account_count' => count(ChartTemplate::accounts()),
            'can_apply' => ! Account::query()->forCompany((string) $company->getKey())->exists(),
        ]);
    }

    public function applyTemplate(Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('create', Account::class);

        $created = $this->template->apply($company);

        return ApiResponse::item(
            data: ['created' => $created, 'template_version' => ChartTemplate::VERSION],
            // Repeated on the way out as well as the way in. A customer who applied the template
            // should have the caveat in front of them at the moment it takes effect.
            meta: ['disclaimer' => ChartTemplate::disclaimer()],
        );
    }
}
