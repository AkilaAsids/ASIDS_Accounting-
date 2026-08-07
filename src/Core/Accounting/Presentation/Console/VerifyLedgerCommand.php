<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Presentation\Console;

use Asids\Core\Accounting\Application\Services\LedgerBalanceService;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Domain\Contracts\TenantRepositoryContract;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Proves the period balance aggregates still agree with the ledger.
 *
 * Read-only, and safe to run anywhere. The same treatment `asids:audit-verify` gets, for the same
 * reason: a derived table nobody checks is a derived table nobody can trust, and the moment it
 * silently disagrees with the lines every report drawn from it is wrong with no indication.
 *
 * Two checks, and the second is the one that matters more:
 *
 *   1. **Aggregate drift** — a stored total that does not match a recomputation from the lines.
 *      Repairable with `asids:ledger-rebuild`.
 *   2. **An unbalanced ledger** — debits not equalling credits for a whole company. This is not
 *      drift and rebuilding will not fix it: it means something bypassed both the deferred constraint
 *      trigger and the posting service, and the aggregates are the least of the problem.
 *
 * A non-zero exit is the signal. Wire it to an alert.
 */
final class VerifyLedgerCommand extends Command
{
    protected $signature = 'asids:ledger-verify
                            {--tenant= : Verify a single workspace by id or slug}
                            {--company= : Verify a single company by id or code}';

    protected $description = 'Verify that account period balances agree with the journal lines';

    public function handle(LedgerBalanceService $balances, TenantContext $tenantContext): int
    {
        $single = $this->option('tenant');

        if (is_string($single) && $single !== '') {
            $tenant = app(TenantRepositoryContract::class)->findByIdOrSlug($single);

            if ($tenant === null) {
                $this->components->error(sprintf('No workspace matches “%s”.', $single));

                return self::FAILURE;
            }

            return $tenantContext->runFor($tenant, fn (): int => $this->verifyTenant($balances, $tenant));
        }

        $failed = 0;

        $tenantContext->eachActiveTenant(
            function (Tenant $tenant) use ($balances, &$failed): void {
                if ($this->verifyTenant($balances, $tenant) !== self::SUCCESS) {
                    $failed++;
                }
            },
            // One workspace's bad data must not stop the sweep for the other ninety-nine thousand.
            onFailure: function (Tenant $tenant, Throwable $e) use (&$failed): void {
                $this->components->error(sprintf('%s: %s', $tenant->slug, $e->getMessage()));
                $failed++;
            },
        );

        if ($failed > 0) {
            $this->components->error(sprintf('%d workspace(s) reported a problem.', $failed));

            return self::FAILURE;
        }

        $this->components->info('Every ledger agrees with its balances.');

        return self::SUCCESS;
    }

    private function verifyTenant(LedgerBalanceService $balances, Tenant $tenant): int
    {
        $companyFilter = $this->option('company');

        $companies = Company::query()
            ->when(is_string($companyFilter) && $companyFilter !== '', static function ($query) use ($companyFilter) {
                // Matched on code as well as id, and case-insensitively, because an operator running
                // this at two in the morning has the code in front of them, not a uuid.
                $filter = (string) $companyFilter;

                return $query->where(static function ($inner) use ($filter): void {
                    $inner->whereRaw('lower(code) = ?', [strtolower($filter)]);

                    if (Str::isUuid($filter)) {
                        $inner->orWhere('id', $filter);
                    }
                });
            })
            ->get();

        $problems = 0;

        foreach ($companies as $company) {
            $problems += $this->verifyCompany($balances, $tenant, $company);
        }

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function verifyCompany(LedgerBalanceService $balances, Tenant $tenant, Company $company): int
    {
        $problems = 0;

        $drift = $balances->drift($company);

        if ($drift !== []) {
            $problems += count($drift);

            $this->components->error(sprintf(
                '%s / %s: %d account-period balance(s) disagree with the journal lines.',
                $tenant->slug,
                $company->code,
                count($drift),
            ));

            // Named rather than counted. "Fourteen rows drifted" is not actionable; the account and
            // the month are what an operator needs to decide whether to rebuild or investigate.
            $this->table(
                ['Account', 'Period', 'Stored Dr', 'Actual Dr', 'Stored Cr', 'Actual Cr'],
                array_map(
                    static fn (array $row): array => [
                        Account::query()->withoutGlobalScopes()->find($row['account_id'])->code ?? $row['account_id'],
                        FiscalPeriod::query()->withoutGlobalScopes()->find($row['fiscal_period_id'])->label ?? $row['fiscal_period_id'],
                        $row['stored_debit'],
                        $row['actual_debit'],
                        $row['stored_credit'],
                        $row['actual_credit'],
                    ],
                    array_slice($drift, 0, 20),
                ),
            );

            if (count($drift) > 20) {
                // Never silently truncated: a report that shows twenty of fourteen hundred rows and
                // says nothing reads as "twenty problems".
                $this->components->warn(sprintf('%d further rows not shown.', count($drift) - 20));
            }

            $this->components->info('Repair with: php artisan asids:ledger-rebuild --company='.$company->code.' --confirm');
        }

        // The ledger itself, independent of the aggregates. If this fails, rebuilding changes nothing
        // — the lines are wrong, and that should be impossible.
        $unbalanced = DB::selectOne(<<<'SQL'
            SELECT COALESCE(SUM(l.debit), 0) AS debits, COALESCE(SUM(l.credit), 0) AS credits
            FROM journal_lines l
            JOIN journal_entries e ON e.id = l.journal_entry_id
            WHERE l.company_id = ?
              AND e.status IN ('posted', 'reversed')
        SQL, [$company->getKey()]);

        if ($unbalanced !== null && (string) $unbalanced->debits !== (string) $unbalanced->credits) {
            $problems++;

            $this->components->error(sprintf(
                '%s / %s: THE LEDGER DOES NOT BALANCE. Debits %s, credits %s. This is not aggregate drift — rebuilding will not fix it.',
                $tenant->slug,
                $company->code,
                $unbalanced->debits,
                $unbalanced->credits,
            ));
        }

        if ($problems === 0) {
            $this->components->twoColumnDetail(
                sprintf('%s / %s', $tenant->slug, $company->code),
                '<fg=green>balanced and in agreement</>',
            );
        }

        return $problems;
    }
}
