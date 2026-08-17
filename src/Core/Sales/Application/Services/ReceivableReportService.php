<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Application\Services;

use Asids\Core\Accounting\Domain\ValueObjects\Money;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Illuminate\Support\Collection;

/**
 * What customers owe, and whether the ledger agrees.
 *
 * Milestone 7's reporting side. Built to the shape `LedgerBalanceService` established rather than a new one:
 * a `Company` first, plain arrays out, `Money` all the way through, and the conversion to decimal strings
 * left to whatever eventually presents them. There are no report DTOs in this codebase and this is not the
 * place to introduce the first.
 *
 * ONE SOURCE FOR "WHAT IS STILL OWED"
 * -----------------------------------
 * Every method here filters through `SalesInvoice::scopeCollectable()` and reads `amount_due`. Neither is
 * restated. The scope already knows that a draft is not yet owed and that a cancelled or paid invoice no
 * longer is; a second copy of that list in this file would be free to drift from it, and the report that
 * drifted would be the one nobody checked.
 *
 * `amount_due` rather than `total - amount_paid` for the same reason the receivables probe reads it: they
 * agree only because a phase-scoped CHECK pins `amount_paid` at zero, and Phase 4 drops it. At that point the
 * stored column is what payment allocation maintains, and subtracting here would be a second implementation
 * of arithmetic that phase owns.
 *
 * CANCELLATION IS EXCLUDED BY STATUS, NEVER BY AMOUNT
 * --------------------------------------------------
 * Worth stating because the obvious shortcut is wrong. Cancelling does not zero `amount_due` — the CHECK
 * holds it at `total - amount_paid` — so a cancelled invoice still carries its full figure. Only the status
 * filter keeps it out. A report that summed `amount_due` without `collectable()` would count every cancelled
 * invoice ever raised.
 *
 * WHY THERE IS NO "AS AT" DATE ON THE BALANCE
 * ------------------------------------------
 * `amount_due` is current state and no payment history exists yet, so there is nothing from which to
 * reconstruct what a customer owed on some past date. The balance is a live snapshot and says so in its
 * signature. Ageing takes an explicit `$asOf` because the *buckets* are a function of a date even though the
 * amounts are current — which is a different thing, and the distinction matters when Phase 4 makes real
 * history available.
 */
final readonly class ReceivableReportService
{
    /**
     * What each customer currently owes.
     *
     * Aggregated in SQL and sorted here. The sort cannot go in the query without joining `customers` for the
     * tie-break, and the set being sorted is one company's customers with an open balance — small enough that
     * the join costs more than it saves.
     *
     * Sorted by amount descending, then customer code, so the largest debt leads and two equal balances have
     * a stable order rather than whatever the database happened to return.
     *
     * Customers with nothing outstanding do not appear. A receivables report listing everybody who owes
     * nothing is a customer list.
     *
     * @return list<array{customer: Customer, outstanding: Money, invoice_count: int}>
     */
    public function outstandingBalance(Company $company): array
    {
        $currency = $company->base_currency_code;

        /** @var Collection<int, object{customer_id: string, outstanding: string, invoice_count: int}> $aggregates */
        $aggregates = SalesInvoice::query()
            ->forCompany((string) $company->getKey())
            ->collectable()
            ->groupBy('customer_id')
            // Excluded in SQL rather than filtered afterwards, so a company with thousands of settled
            // customers does not transfer them all to be discarded.
            ->havingRaw('SUM(amount_due) > 0')
            ->selectRaw('customer_id, SUM(amount_due) AS outstanding, COUNT(*) AS invoice_count')
            // Dropped to the query builder for the aggregate. An Eloquent `get()` would hydrate
            // `SalesInvoice` models carrying two columns no invoice has, which is a lie about the type even
            // where it happens to work. `toBase()` applies the tenant global scope first, so isolation is
            // unaffected.
            ->toBase()
            ->get();

        if ($aggregates->isEmpty()) {
            return [];
        }

        /** @var array<string, Customer> $customers */
        $customers = Customer::query()
            ->forCompany((string) $company->getKey())
            ->whereIn('id', $aggregates->pluck('customer_id')->all())
            ->get()
            ->keyBy('id')
            ->all();

        $rows = [];

        foreach ($aggregates as $aggregate) {
            /** @var string $customerId */
            $customerId = $aggregate->customer_id;
            $customer = $customers[$customerId] ?? null;

            // Absent only if the customer was hard-deleted while owing, which `CustomerService` refuses and
            // the receivables probe now enforces. Skipped rather than crashing the whole report over one row.
            if ($customer === null) {
                continue;
            }

            $rows[] = [
                'customer' => $customer,
                'outstanding' => Money::of((string) $aggregate->outstanding, $currency),
                'invoice_count' => (int) $aggregate->invoice_count,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            // Compared on `minorUnits`, an integer at the ledger's scale — exact, and it sidesteps how two
            // decimal strings order. Largest first, then code ascending so equal balances have a stable
            // order rather than whatever the database happened to return.
            $byAmount = $b['outstanding']->minorUnits <=> $a['outstanding']->minorUnits;

            if ($byAmount !== 0) {
                return $byAmount;
            }

            return strcmp((string) $a['customer']->code, (string) $b['customer']->code);
        });

        return $rows;
    }
}
