<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Providers;

use Asids\Core\Accounting\Application\Services\ChartOfAccountsService;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Domain\Contracts\AccountUsageProbe;
use Asids\Core\Accounting\Infrastructure\Ledger\NoPostings;
use Illuminate\Support\ServiceProvider;

/**
 * The Accounting module.
 *
 * Registered after Organization — a company must exist before it can keep books — and before
 * Settings, which the accounting settings group extends.
 *
 * The module grows through the phase; this provider is deliberately the only place its internals
 * are wired, so adding the ledger in tranche 3 means adding bindings here rather than touching
 * anything in `bootstrap/providers.php`.
 */
final class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stateless, so a singleton is safe. It reads the company's fiscal configuration on every
        // call rather than caching it, because a company's fiscal start is immutable once the
        // ledger has activity — and until then it can legitimately change mid-request.
        $this->app->singleton(FiscalCalendarService::class);
        $this->app->singleton(ChartOfAccountsService::class);
        $this->app->singleton(ChartTemplateService::class);

        // Replaced by the Eloquent implementation in tranche 3, when `journal_lines` exists. Until
        // then no account can have a posting, so `NoPostings` is the accurate answer rather than a
        // convenient one — and the rules that consult it are already in force.
        $this->app->bind(AccountUsageProbe::class, NoPostings::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
