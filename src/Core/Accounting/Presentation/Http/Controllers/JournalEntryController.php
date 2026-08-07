<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Presentation\Http\Controllers;

use Asids\Core\Accounting\Application\DTOs\JournalEntryData;
use Asids\Core\Accounting\Application\Services\JournalService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Presentation\Http\Requests\StoreJournalEntryRequest;
use Asids\Core\Accounting\Presentation\Http\Resources\JournalEntryResource;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Domain\Query\QueryCriteria;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journal entries.
 *
 * Posting and reversing are separate endpoints rather than a status field on an update, and that is
 * the point rather than REST pedantry: they are different capabilities held by different people, they
 * are irreversible in different ways, and a PATCH that could set `status: posted` would make the
 * bookkeeper/accountant split a matter of what the client chose to send.
 */
final class JournalEntryController extends ApiController
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly PostingService $posting,
    ) {}

    public function index(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('viewAny', JournalEntry::class);

        $criteria = QueryCriteria::fromRequest(
            $request,
            sortable: ['entry_date', 'number', 'created_at'],
            filterable: ['status', 'document_type', 'fiscal_period_id', 'journal_id'],
            includable: ['lines'],
            defaultSort: '-entry_date',
        );

        $entries = JournalEntry::query()
            ->forCompany((string) $company->getKey())
            ->with($criteria->hasInclude('lines') ? ['lines.account', 'fiscalPeriod'] : ['fiscalPeriod'])
            ->when($criteria->hasFilter('status'), static fn ($query) => $query->where('status', $criteria->filters()['status']))
            ->when($criteria->hasFilter('document_type'), static fn ($query) => $query->where('document_type', $criteria->filters()['document_type']))
            ->when($criteria->hasFilter('fiscal_period_id'), static fn ($query) => $query->where('fiscal_period_id', $criteria->filters()['fiscal_period_id']))
            ->when($criteria->hasFilter('journal_id'), static fn ($query) => $query->where('journal_id', $criteria->filters()['journal_id']))
            ->when(
                $criteria->search() !== null,
                static fn ($query) => $query->where(static function ($inner) use ($criteria): void {
                    $term = '%'.$criteria->search().'%';
                    $inner->where('description', 'ilike', $term)
                        ->orWhere('reference', 'ilike', $term)
                        ->orWhere('number', 'ilike', $term);
                }),
            )
            ->tap(static function ($query) use ($criteria): void {
                foreach ($criteria->sorts() as $column => $direction) {
                    $query->orderBy($column, $direction);
                }
            })
            ->paginate($criteria->perPage(), page: $criteria->page())
            ->withQueryString();

        return ApiResponse::collection(JournalEntryResource::collection($entries));
    }

    public function show(Company $company, JournalEntry $entry): JsonResponse
    {
        $this->authorize('view', $entry);

        return ApiResponse::item(new JournalEntryResource(
            $entry->load(['lines.account', 'fiscalPeriod']),
        ));
    }

    /**
     * Draft an entry, and post it in the same call when asked and permitted.
     *
     * The two are one endpoint because an accountant entering a correction has no use for an
     * intermediate draft, and making them issue two requests would leave a window where a half-made
     * entry is visible to everyone else. The `post` flag is checked against the policy, so a
     * bookkeeper sending it gets a 403 rather than a posted entry.
     */
    public function store(StoreJournalEntryRequest $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('create', JournalEntry::class);

        $data = JournalEntryData::fromArray($request->validated(), $company->base_currency_code);

        $entry = $this->journals->draft($company, $data, $this->currentUser()->getKey());

        if ($request->boolean('post')) {
            $this->authorize('post', $entry);

            $entry = $this->posting->post($entry, $this->currentUser());
        }

        return ApiResponse::created(new JournalEntryResource($entry->load(['lines.account', 'fiscalPeriod'])));
    }

    public function update(StoreJournalEntryRequest $request, Company $company, JournalEntry $entry): JsonResponse
    {
        $this->authorize('update', $entry);

        $updated = $this->journals->updateDraft(
            $entry,
            JournalEntryData::fromArray($request->validated(), $company->base_currency_code),
        );

        return ApiResponse::item(new JournalEntryResource($updated->load(['lines.account', 'fiscalPeriod'])));
    }

    public function destroy(Company $company, JournalEntry $entry): JsonResponse
    {
        $this->authorize('delete', $entry);

        $this->journals->deleteDraft($entry);

        return ApiResponse::noContent();
    }

    public function post(Company $company, JournalEntry $entry): JsonResponse
    {
        $this->authorize('post', $entry);

        return ApiResponse::item(new JournalEntryResource(
            $this->posting->post($entry, $this->currentUser())->load(['lines.account', 'fiscalPeriod']),
        ));
    }

    /**
     * Reverse a posted entry.
     *
     * The reason is required, because it is recorded against the original for ever and "why was this
     * reversed" is the first thing anyone reading the trail asks.
     */
    public function reverse(Request $request, Company $company, JournalEntry $entry): JsonResponse
    {
        $this->authorize('reverse', $entry);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'reversal_date' => ['nullable', 'date'],
        ]);

        $reversal = $this->posting->reverse(
            entry: $entry,
            reason: (string) $validated['reason'],
            reversalDate: isset($validated['reversal_date'])
                ? CarbonImmutable::parse((string) $validated['reversal_date'])
                : null,
            actor: $this->currentUser(),
        );

        return ApiResponse::created(new JournalEntryResource($reversal->load(['lines.account', 'fiscalPeriod'])));
    }
}
