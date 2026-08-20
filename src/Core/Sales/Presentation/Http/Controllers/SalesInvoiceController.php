<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Controllers;

use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Domain\Query\QueryCriteria;
use Asids\Core\Platform\Exceptions\BusinessRuleViolation;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Asids\Core\Sales\Application\DTOs\SalesInvoiceData;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Sales\Presentation\Http\Requests\StoreSalesInvoiceRequest;
use Asids\Core\Sales\Presentation\Http\Requests\UpdateSalesInvoiceRequest;
use Asids\Core\Sales\Presentation\Http\Resources\SalesInvoiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sales invoices, nested under their company.
 *
 * A transport layer. `SalesInvoiceService` owns every figure, the tax resolution, the gapless numbering, the
 * posting map and every transition rule; this class authorises the caller, hands over validated input, and wraps
 * what comes back. Nothing here computes a total, resolves a rate, allocates a number or assigns a status.
 *
 * ISSUING AND CANCELLING ARE THEIR OWN ENDPOINTS
 * ----------------------------------------------
 * Not a `status` field on the update, for the reason `JournalEntryController` gives about posting and reversing:
 * they are different capabilities held by different people, they are irreversible in different ways, and a PUT
 * that could set `status: issued` would make the draft/issue split a matter of what the client chose to send.
 *
 * THE COMPANY GUARD, AND WHY THIS MODULE HAS ONE
 * ---------------------------------------------
 * Nested route bindings resolve independently, so `/companies/A/sales-invoices/{invoice of company B}` loads a
 * foreign invoice whenever the caller is a member of both companies — the policy checks membership of the
 * *invoice's* company and passes. ADR 0008 D6.1 recorded that gap as an accepted platform-wide behaviour for
 * customers and accounts, on the grounds that it is a URL/entity coherence problem rather than an escalation: the
 * caller could reach the same row under its correct URL anyway.
 *
 * That reasoning covers reads. It does not cover these endpoints. `ResolveActiveCompany` publishes the **url**
 * company to `RequestContext`, and that is what stamps `company_id` onto every audit entry the request writes — so
 * issuing or cancelling company B's invoice under company A's URL posts to B's ledger while the trail says A.
 * Misattributed audit records of a ledger write are not a coherence nit, so this controller asserts the invoice
 * belongs to the url company on every route that binds one, following
 * `BranchController::assertBelongsToCompany()` rather than inventing a mechanism. Recorded as a deliberate
 * Sales-specific exception; ADR 0008 is not amended by it.
 */
final class SalesInvoiceController extends ApiController
{
    public function __construct(
        private readonly SalesInvoiceService $invoices,
    ) {}

    /**
     * The company's invoices, newest first.
     *
     * Paginated with an allow-listed sort and filter set, following `JournalEntryController::index`: `?sort=` and
     * `?filter[]=` reach an ORDER BY and a WHERE, so accepting arbitrary column names would be an injection and an
     * information-disclosure vector.
     *
     * `q` searches the number and the reference only. Searching the customer's name would mean joining
     * `customers`, and `SalesInvoice::scopeForCompany()` emits an unqualified `company_id` — the ambiguity that
     * broke Milestone 7's reporting queries once already. Filtering by `customer_id` covers the same need without
     * the join.
     */
    public function index(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('viewAny', SalesInvoice::class);

        $criteria = QueryCriteria::fromRequest(
            $request,
            sortable: ['invoice_date', 'due_date', 'number', 'total', 'created_at'],
            filterable: ['status', 'customer_id', 'branch_id'],
            includable: ['lines'],
            defaultSort: '-invoice_date',
        );

        $invoices = SalesInvoice::query()
            // Explicit, never left to row level security: two companies in one workspace share a `tenant_id`, so
            // the policies are satisfied by either one's rows.
            ->forCompany((string) $company->getKey())
            ->with($criteria->hasInclude('lines') ? ['customer', 'lines.taxCode'] : ['customer'])
            ->when(
                $criteria->hasFilter('status'),
                static fn ($query) => $query->where('status', $criteria->filters()['status']),
            )
            ->when(
                $criteria->hasFilter('customer_id'),
                fn ($query) => $query->where('customer_id', $this->uuidFilter($criteria, 'customer_id')),
            )
            ->when(
                $criteria->hasFilter('branch_id'),
                fn ($query) => $query->where('branch_id', $this->uuidFilter($criteria, 'branch_id')),
            )
            ->when(
                $criteria->search() !== null,
                static fn ($query) => $query->where(static function ($inner) use ($criteria): void {
                    $term = '%'.$criteria->search().'%';
                    $inner->where('number', 'ilike', $term)
                        ->orWhere('reference', 'ilike', $term);
                }),
            )
            ->tap(static function ($query) use ($criteria): void {
                foreach ($criteria->sorts() as $column => $direction) {
                    $query->orderBy($column, $direction);
                }
            })
            ->paginate($criteria->perPage(), page: $criteria->page())
            ->withQueryString();

        return ApiResponse::collection(SalesInvoiceResource::collection($invoices));
    }

    public function show(Company $company, SalesInvoice $invoice): JsonResponse
    {
        $this->assertBelongsToCompany($company, $invoice);
        $this->authorize('view', $invoice);

        return ApiResponse::item(new SalesInvoiceResource(
            $invoice->load(['customer', 'lines.taxCode']),
        ));
    }

    /**
     * Draft an invoice, and issue it in the same call when asked and permitted.
     *
     * The two are one endpoint for the reason `JournalEntryController::store()` gives: a salesperson invoicing a
     * walk-in customer has no use for an intermediate draft, and making them issue two requests would leave a
     * window where a half-made document is visible to everyone else.
     *
     * One transaction covers both, and that is the substance rather than the tidiness. Drafting commits an
     * invoice; issuing can still refuse it — a closed period, an archived customer, a reclassified revenue
     * account, or a caller who may draft but not issue. Left as two commits, every one of those refusals would
     * leave the draft behind, so a salesperson who picks the wrong account and is told so would find a
     * half-made invoice sitting in the books for each attempt.
     *
     * The authorisation check stays inside, after the draft exists, so the policy still sees a real invoice and
     * the caller still gets a 403 rather than a 422 — it just no longer leaves a row behind.
     */
    public function store(StoreSalesInvoiceRequest $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorize('create', SalesInvoice::class);

        $validated = $request->validated();

        $invoice = DB::transaction(function () use ($company, $validated, $request): SalesInvoice {
            $invoice = $this->invoices->createDraft(
                $company,
                SalesInvoiceData::fromArray($validated),
                $this->currentUser()->getKey(),
            );

            if (! $request->boolean('issue')) {
                return $invoice;
            }

            $this->authorize('issue', $invoice);

            return $this->invoices->issue($invoice, $this->currentUser());
        });

        return ApiResponse::created(new SalesInvoiceResource(
            $invoice->load(['customer', 'lines.taxCode']),
        ));
    }

    /**
     * Change a draft.
     *
     * `$request->validated()` goes through as an attribute array so omitted and explicitly-null fields stay
     * distinguishable — see `UpdateSalesInvoiceRequest`. Draft-ness is the service's to refuse.
     */
    public function update(UpdateSalesInvoiceRequest $request, Company $company, SalesInvoice $invoice): JsonResponse
    {
        $this->assertBelongsToCompany($company, $invoice);
        $this->authorize('update', $invoice);

        return ApiResponse::item(new SalesInvoiceResource(
            $this->invoices->updateDraft($invoice, $request->validated())
                ->load(['customer', 'lines.taxCode']),
        ));
    }

    /**
     * Delete a draft outright.
     *
     * Hard deletion, per ADR 0007 B2: a never-issued draft is not an accounting document, so a tombstone would
     * imply otherwise. There is no restore counterpart because `sales_invoices` has no soft-delete column — an
     * issued invoice is a statutory record and cannot be removed at all, which the service refuses.
     */
    public function destroy(Company $company, SalesInvoice $invoice): JsonResponse
    {
        $this->assertBelongsToCompany($company, $invoice);
        $this->authorize('delete', $invoice);

        $this->invoices->deleteDraft($invoice);

        return ApiResponse::noContent();
    }

    /**
     * Commit a draft to the ledger and to the customer.
     *
     * Everything that decides whether this may happen — the draft state, the lines, the total, every account, the
     * customer and the fiscal period — is re-checked by the service at this moment rather than trusted from when
     * the draft was written, per ADR 0009 B5.
     */
    public function issue(Company $company, SalesInvoice $invoice): JsonResponse
    {
        $this->assertBelongsToCompany($company, $invoice);
        $this->authorize('issue', $invoice);

        return ApiResponse::item(new SalesInvoiceResource(
            $this->invoices->issue($invoice, $this->currentUser())
                ->load(['customer', 'lines.taxCode']),
        ));
    }

    /**
     * Reverse an issued invoice's posting.
     *
     * The reason is required, because it is recorded against the document for ever and "why was this cancelled"
     * is the first thing anyone reading the trail asks — the same rule `JournalEntryController::reverse()`
     * applies to a reversal.
     *
     * Capped at 255 characters rather than the 500 `sales_invoices.cancellation_reason` would hold, because the
     * service writes the same string to `journal_entries.reversal_reason`, which is `varchar(255)`. A longer
     * reason would be accepted by one column and refused by the other, mid-transaction, as a database error
     * rather than a validation message.
     */
    public function cancel(Request $request, Company $company, SalesInvoice $invoice): JsonResponse
    {
        $this->assertBelongsToCompany($company, $invoice);
        $this->authorize('cancel', $invoice);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        return ApiResponse::item(new SalesInvoiceResource(
            $this->invoices->cancel($invoice, (string) $validated['reason'], $this->currentUser())
                ->load(['customer', 'lines.taxCode']),
        ));
    }

    /**
     * That the bound invoice is one of this company's.
     *
     * Checked before the policy, so a mismatch is answered as the addressing error it is rather than as a
     * permission problem — and checked explicitly rather than through scoped bindings, so the guarantee does not
     * depend on how the route was registered. See the class docblock for why this module carries the guard when
     * customers and accounts do not.
     */
    private function assertBelongsToCompany(Company $company, SalesInvoice $invoice): void
    {
        if ((string) $invoice->company_id !== (string) $company->getKey()) {
            throw BusinessRuleViolation::make(
                code: 'invoice-company-mismatch',
                message: 'That invoice does not belong to the specified company.',
            );
        }
    }

    /**
     * A uuid filter value, refused before it reaches the query.
     *
     * A non-uuid would otherwise land on a `where()` against a uuid column and surface as a Postgres 22P02
     * rendered as a generic 500. Validated here so the caller gets a 422 naming the problem — the guard
     * `CustomerController::index` already applies to its `branch_id` filter.
     */
    private function uuidFilter(QueryCriteria $criteria, string $key): string
    {
        $value = $criteria->filters()[$key] ?? null;

        if (! is_string($value) || ! Str::isUuid($value)) {
            throw BusinessRuleViolation::make(
                'invalid-'.str_replace('_', '-', $key).'-filter',
                sprintf('The %s filter must be a valid identifier.', $key),
            );
        }

        return $value;
    }
}
