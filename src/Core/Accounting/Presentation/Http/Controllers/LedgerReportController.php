<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Presentation\Http\Controllers;

use Asids\Core\Accounting\Application\Services\LedgerBalanceService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\ValueObjects\DateRange;
use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Http\Controllers\ApiController;
use Asids\Core\Platform\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The trial balance and the account ledger.
 *
 * Both emit amounts as decimal strings and both carry their own totals. The totals are computed
 * server-side rather than left to the client for the same reason the ledger stores `numeric(19,4)`: a
 * client that sums IEEE-754 doubles produces a trial balance that does not tie, and the customer
 * reasonably concludes the accounting is broken rather than the arithmetic.
 */
final class LedgerReportController extends ApiController
{
    public function __construct(private readonly LedgerBalanceService $balances) {}

    public function trialBalance(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);
        $this->authorizeReports();

        $range = $this->resolveRange($request, $company);
        $currency = $company->base_currency_code;

        $rows = $this->balances->trialBalance($company, $range);

        $debitTotal = array_reduce(
            $rows,
            static fn (Money $carry, array $row): Money => $carry->plus($row['debit']),
            Money::zero($currency),
        );

        $creditTotal = array_reduce(
            $rows,
            static fn (Money $carry, array $row): Money => $carry->plus($row['credit']),
            Money::zero($currency),
        );

        return ApiResponse::item(
            data: array_map(
                static fn (array $row): array => [
                    'account_id' => $row['account']->getKey(),
                    'code' => $row['account']->code,
                    'name' => $row['account']->name,
                    'type' => $row['account']->type->value,
                    'statement' => $row['account']->statement()->value,
                    'normal_balance' => $row['account']->normal_balance->value,
                    'debit' => $row['debit']->toDecimalString(),
                    'credit' => $row['credit']->toDecimalString(),
                    'balance' => $row['balance']->toDecimalString(),
                ],
                $rows,
            ),
            meta: [
                'from' => $range->start->toDateString(),
                'to' => $range->end->toDateString(),
                'currency' => $currency,
                'totals' => [
                    'debit' => $debitTotal->toDecimalString(),
                    'credit' => $creditTotal->toDecimalString(),
                ],
                // Stated explicitly rather than left for the client to work out by comparing two
                // strings. If this is ever false the ledger is broken, and the client should say so
                // loudly rather than rendering a report that looks ordinary.
                'ties' => $this->balances->trialBalanceTies($rows, $currency),
            ],
        );
    }

    public function accountLedger(Request $request, Company $company, Account $account): JsonResponse
    {
        $this->authorize('view', $account);
        $this->authorizeReports();

        $range = $this->resolveRange($request, $company);

        $ledger = $this->balances->accountLedger($company, $account, $range);

        return ApiResponse::item(
            data: array_map(
                static fn (array $line): array => [
                    'entry_id' => $line['entry']->getKey(),
                    'number' => $line['entry']->number,
                    'entry_date' => $line['entry']->entry_date->toDateString(),
                    'description' => $line['entry']->description,
                    'status' => $line['entry']->status->value,
                    'debit' => $line['debit']->toDecimalString(),
                    'credit' => $line['credit']->toDecimalString(),
                    'running_balance' => $line['running']->toDecimalString(),
                ],
                $ledger['lines'],
            ),
            meta: [
                'account' => [
                    'id' => $account->getKey(),
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type->value,
                    'normal_balance' => $account->normal_balance->value,
                ],
                'from' => $range->start->toDateString(),
                'to' => $range->end->toDateString(),
                'currency' => $company->base_currency_code,
                // Both ends, so the report stands alone: a statement whose closing figure cannot be
                // reconciled to its opening one is a movement list.
                'opening_balance' => $ledger['opening']->toDecimalString(),
                'closing_balance' => $ledger['closing']->toDecimalString(),
            ],
        );
    }

    private function authorizeReports(): void
    {
        if (! $this->currentUser()->can('accounting.reports.view')) {
            abort(403);
        }
    }

    /**
     * The reporting window.
     *
     * Defaults to the current fiscal year rather than the calendar one, because a company with an
     * April year start asking for "this year" means their year, and a January default would silently
     * report the wrong nine months.
     */
    private function resolveRange(Request $request, Company $company): DateRange
    {
        if ($request->filled('from') && $request->filled('to')) {
            return DateRange::fromStrings(
                $request->string('from')->toString(),
                $request->string('to')->toString(),
            );
        }

        $now = CarbonImmutable::now();

        return DateRange::between(
            $company->fiscalYearStartFor($now),
            $company->fiscalYearEndFor($now),
        );
    }
}
