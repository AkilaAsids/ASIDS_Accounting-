<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Presentation\Console;

use Asids\Core\Accounting\Application\Services\LedgerBalanceService;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Audit\Application\Services\ActivityLogger;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Tenancy\Application\Services\TenantContext;
use Asids\Core\Tenancy\Domain\Contracts\TenantRepositoryContract;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Discards and recomputes the period balance aggregates from the journal lines.
 *
 * The repair half of the pair. Separate from `asids:ledger-verify` on purpose: detection is read-only
 * and belongs on a schedule, repair rewrites a derived table and belongs in someone's hands. A single
 * command that silently corrected drift would also silently hide the fact that drift is occurring,
 * which is the more important signal.
 *
 * Requires `--confirm`, is audited, and is scopable to one company and one period so a repair can be
 * narrow rather than rewriting seven years of aggregates to fix one month.
 *
 * It cannot fix an unbalanced ledger, and does not pretend to: the lines are the source of truth, so
 * recomputing from them reproduces whatever they say. `asids:ledger-verify` reports that case
 * separately for exactly this reason.
 */
final class RebuildLedgerBalancesCommand extends Command
{
    protected $signature = 'asids:ledger-rebuild
                            {--tenant= : Rebuild a single workspace by id or slug}
                            {--company= : Rebuild a single company by id or code}
                            {--period= : Rebuild a single period by id}
                            {--confirm : Required. Rewrites the derived balance table.}';

    protected $description = 'Recompute account period balances from the journal lines';

    public function handle(
        LedgerBalanceService $balances,
        TenantContext $tenantContext,
        ActivityLogger $activity,
    ): int {
        if ($this->option('confirm') !== true) {
            $this->components->error('This rewrites the derived balance table. Re-run with --confirm.');
            $this->components->info('To see what would change without changing it: php artisan asids:ledger-verify');

            return self::FAILURE;
        }

        $tenantOption = $this->option('tenant');

        if (! is_string($tenantOption) || $tenantOption === '') {
            // Deliberately not sweeping every workspace. Rebuilding one company is a repair;
            // rebuilding a hundred thousand is an unbounded write to the busiest table in the
            // platform, and nobody has ever wanted that as the default.
            $this->components->error('Name a workspace with --tenant. This command does not sweep every workspace.');

            return self::FAILURE;
        }

        $tenant = app(TenantRepositoryContract::class)->findByIdOrSlug($tenantOption);

        if ($tenant === null) {
            $this->components->error(sprintf('No workspace matches “%s”.', $tenantOption));

            return self::FAILURE;
        }

        return $tenantContext->runFor($tenant, function () use ($balances, $activity, $tenant): int {
            $companyOption = $this->option('company');

            $companies = Company::query()
                ->when(is_string($companyOption) && $companyOption !== '', static fn ($query) => $query->where(
                    static function ($inner) use ($companyOption): void {
                        $inner->whereRaw('lower(code) = ?', [strtolower((string) $companyOption)]);

                        if (Str::isUuid((string) $companyOption)) {
                            $inner->orWhere('id', $companyOption);
                        }
                    },
                ))
                ->get();

            if ($companies->isEmpty()) {
                $this->components->error('No matching company in that workspace.');

                return self::FAILURE;
            }

            $periodOption = $this->option('period');
            $period = is_string($periodOption) && $periodOption !== ''
                ? FiscalPeriod::query()->find($periodOption)
                : null;

            if (is_string($periodOption) && $periodOption !== '' && $period === null) {
                $this->components->error(sprintf('No period matches “%s” in that workspace.', $periodOption));

                return self::FAILURE;
            }

            foreach ($companies as $company) {
                $written = $balances->rebuild($company, $period);

                $this->components->twoColumnDetail(
                    sprintf('%s / %s%s', $tenant->slug, $company->code, $period === null ? '' : ' / '.$period->label),
                    sprintf('<fg=green>%d account-period(s) recomputed</>', $written),
                );

                // Recorded because it is a privileged write to financial reporting data. An auditor
                // asking "why did last March's figures change on the 14th?" needs an answer.
                $activity->log(
                    event: 'ledger.balances.rebuilt',
                    subject: $company,
                    description: sprintf(
                        'Rebuilt %d account-period balance(s) for %s%s from the journal lines.',
                        $written,
                        $company->name,
                        $period === null ? '' : ' in '.$period->label,
                    ),
                    properties: [
                        'company_code' => $company->code,
                        'period' => $period?->label,
                        'account_periods_written' => $written,
                    ],
                );
            }

            $this->components->info('Rebuilt from the journal lines. Confirm with: php artisan asids:ledger-verify');

            return self::SUCCESS;
        });
    }
}
