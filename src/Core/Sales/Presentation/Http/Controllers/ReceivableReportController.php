<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Presentation\Http\Controllers;

use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Asids\Core\Sales\Application\Services\ReceivableReportService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The three receivables reports, over HTTP.
 *
 * A transport layer and nothing more. `ReceivableReportService` owns every figure, every status filter and
 * every bucket boundary; this class authorises the caller, resolves one optional date, and converts `Money`
 * to decimal strings at the boundary. It follows `LedgerReportController` deliberately — no FormRequest for a
 * single query parameter, no API Resource, no report DTO, and `ApiResponse::item()` rather than
 * `collection()`, because a report is one document that happens to contain rows rather than a page of a
 * collection.
 *
 * WHY THE TOTALS COME FROM THE SERVER
 * -----------------------------------
 * Every total below is computed here or by the service and emitted in `meta`, for the reason
 * `LedgerReportController` states about the trial balance: a client that sums IEEE-754 doubles produces a
 * figure that disagrees with the ledger by a few cents, and the customer then has two numbers and no way to
 * know which to believe. `meta.totals.reconciles` is emitted for the same reason `meta.ties` is — it is a
 * verdict, not something a client should re-derive by comparing the rows it was given.
 *
 * AUTHORISATION IS TWO QUESTIONS
 * ------------------------------
 * Membership of the company in the url, answered by `CompanyPolicy` via `authorize('view', $company)` and by
 * the `company` middleware before that; and the capability itself, answered by `authorizeReports()`. A
 * permission check rather than a policy because there is no model to police — the same shape
 * `LedgerReportController::authorizeReports()` uses for `accounting.reports.view`.
 *
 * NO RESOURCE ID REACHES A QUERY
 * ------------------------------
 * All three actions take `{company}` and nothing else. There is no `{customer}` or `{account}` segment, so
 * the cross-company binding guard that `BranchController::assertBelongsToCompany()` needs has nothing to
 * guard here. Company scoping is the url segment plus the middleware; the service adds its own explicit
 * `forCompany()` on every query, which is what keeps two companies sharing a `tenant_id` apart — row level
 * security alone would not.
 */
final class ReceivableReportController extends ApiController
{
    public function __construct(private readonly ReceivableReportService $receivables) {}

    /**
     * What each customer currently owes.
     *
     * Takes no date. `amount_due` is current state with no history behind it, so an as-at parameter would
     * promise something the data cannot support (ADR 0010 D5). `meta.as_of` reports the day the figures were
     * read, so a printed copy carries it.
     *
     * The grand total is summed here because this is the one report whose service method does not return
     * one — reduced over `Money` rather than over the strings this method is about to emit, following
     * `LedgerReportController::trialBalance()`.
     */
    public function outstandingReceivables(Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorizeReports();

        $currency = $company->base_currency_code;
        $rows = $this->receivables->outstandingBalance($company);

        $total = array_reduce(
            $rows,
            static fn (Money $carry, array $row): Money => $carry->plus($row['outstanding']),
            Money::zero($currency),
        );

        return ApiResponse::item(
            data: array_map(
                static fn (array $row): array => [
                    'customer_id' => $row['customer']->getKey(),
                    'code' => $row['customer']->code,
                    'name' => $row['customer']->name,
                    'invoice_count' => $row['invoice_count'],
                    'outstanding' => $row['outstanding']->toDecimalString(),
                ],
                $rows,
            ),
            meta: [
                'currency' => $currency,
                'as_of' => CarbonImmutable::now()->toDateString(),
                'totals' => ['outstanding' => $total->toDecimalString()],
            ],
        );
    }

    /**
     * The debtor book by age, as at a cutoff.
     *
     * `as_of` is optional on the wire and required by the service. Absent, it defaults to today **here** and
     * the resolved value is echoed in `meta.as_of` — the pattern `LedgerReportController::resolveRange()`
     * uses for the fiscal year. Defaulting server-side rather than in the browser is the point: a cutoff
     * taken from the client's clock could not be reproduced from the response it produced, and two users in
     * different timezones would age the same book differently.
     *
     * Buckets, edges and the due-date basis are the service's (ADR 0010 D6) and are not restated here. The
     * totals are the service's too — it sums its own rows so that "the totals equal the sum of the rows" is
     * true by construction rather than by two computations happening to agree.
     */
    public function agedReceivables(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorizeReports();

        $request->validate(['as_of' => ['sometimes', 'date']]);

        $asOf = $request->filled('as_of')
            ? CarbonImmutable::parse($request->string('as_of')->toString())->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $report = $this->receivables->agedReceivables($company, $asOf);

        return ApiResponse::item(
            data: array_map(
                static fn (array $row): array => [
                    'customer_id' => $row['customer']->getKey(),
                    'code' => $row['customer']->code,
                    'name' => $row['customer']->name,
                    'not_yet_due' => $row['not_yet_due']->toDecimalString(),
                    'days_0_30' => $row['days_0_30']->toDecimalString(),
                    'days_31_60' => $row['days_31_60']->toDecimalString(),
                    'days_61_90' => $row['days_61_90']->toDecimalString(),
                    'days_over_90' => $row['days_over_90']->toDecimalString(),
                    'total' => $row['total']->toDecimalString(),
                ],
                $report['rows'],
            ),
            meta: [
                'currency' => $company->base_currency_code,
                'as_of' => $report['as_of']->toDateString(),
                'totals' => array_map(
                    static fn (Money $amount): string => $amount->toDecimalString(),
                    $report['totals'],
                ),
            ],
        );
    }

    /**
     * Whether the invoice subledger agrees with the general ledger, per receivable account.
     *
     * Accepts **no** date, and that is a deliberate absence rather than an omission: the subledger side reads
     * current `status` and current `amount_due` with no history to reconstruct either from, so a past cutoff
     * would have the two halves answering different questions (ADR 0010 D3). `meta.as_of` is reported so a
     * printed copy carries the day it was produced, and a client must render it as text rather than as an
     * editable control.
     *
     * `reconciles` is emitted per row **and** in the totals, where the service requires every account to
     * agree before claiming it. Two opposite differences of equal size net to zero in the grand total while
     * both accounts are wrong, so a client deriving the verdict by inspecting the total would report that as
     * reconciled. The difference is `general_ledger - subledger` and negatives are emitted as negatives —
     * the sign says which side is short, and normalising it would discard that.
     */
    public function arControl(Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorizeReports();

        $report = $this->receivables->arControlReconciliation($company);

        return ApiResponse::item(
            data: array_map(
                // The account is an Accounting model the report service already returns. Three of its fields
                // are serialised inline rather than through `AccountResource`, which would mean importing an
                // Accounting presentation class into Sales — a module crossing this needs no part of.
                static fn (array $row): array => [
                    'account_id' => $row['account']->getKey(),
                    'code' => $row['account']->code,
                    'name' => $row['account']->name,
                    'subledger' => $row['subledger']->toDecimalString(),
                    'general_ledger' => $row['general_ledger']->toDecimalString(),
                    'difference' => $row['difference']->toDecimalString(),
                    'reconciles' => $row['reconciles'],
                ],
                $report['rows'],
            ),
            meta: [
                'currency' => $company->base_currency_code,
                'as_of' => $report['as_of']->toDateString(),
                'totals' => [
                    'subledger' => $report['totals']['subledger']->toDecimalString(),
                    'general_ledger' => $report['totals']['general_ledger']->toDecimalString(),
                    'difference' => $report['totals']['difference']->toDecimalString(),
                    'reconciles' => $report['totals']['reconciles'],
                ],
            ],
        );
    }

    /**
     * The capability to read receivables reporting.
     *
     * Checked directly rather than through a policy because there is no model to police — the shape
     * `LedgerReportController::authorizeReports()` established for `accounting.reports.view`.
     *
     * `AuthorizationException` rather than `abort(403)`, and the difference is not cosmetic. `abort(403)`
     * raises a bare Symfony `HttpException`, which `ApiExceptionRenderer` matches on its generic
     * `HttpExceptionInterface` arm and renders with `type: …/http-403`. Every other denial in this
     * application renders as `…/forbidden` — that is the code `ProblemCode.Forbidden` in
     * `resources/js/types/api.ts` branches on, the code this endpoint's `Forbidden` response documents, and
     * the exact discrepancy the renderer's own `AccessDeniedHttpException` arm was added to close for
     * policy-thrown denials. Throwing the authorization exception routes through that arm, so a client can
     * treat a missing report permission the same way it treats every other refusal.
     *
     * No message is passed. The renderer deliberately never echoes which capability was missing, since that
     * enumerates the catalogue to an attacker.
     */
    private function authorizeReports(): void
    {
        if (! $this->currentUser()->can('sales.reports.view')) {
            throw new AuthorizationException;
        }
    }
}
