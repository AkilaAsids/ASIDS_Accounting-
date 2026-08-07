<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Providers;

use Asids\Core\Accounting\Application\Services\ChartOfAccountsService;
use Asids\Core\Accounting\Application\Services\ChartTemplateService;
use Asids\Core\Accounting\Application\Services\DocumentNumberService;
use Asids\Core\Accounting\Application\Services\FiscalCalendarService;
use Asids\Core\Accounting\Application\Services\JournalService;
use Asids\Core\Accounting\Application\Services\LedgerBalanceService;
use Asids\Core\Accounting\Application\Services\OpeningBalanceService;
use Asids\Core\Accounting\Application\Services\PeriodCloseService;
use Asids\Core\Accounting\Application\Services\PostingService;
use Asids\Core\Accounting\Domain\Contracts\AccountUsageProbe;
use Asids\Core\Accounting\Domain\Events\JournalEntryPosted;
use Asids\Core\Accounting\Domain\Events\JournalEntryReversed;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Accounting\Domain\Models\FiscalPeriod;
use Asids\Core\Accounting\Domain\Models\FiscalYear;
use Asids\Core\Accounting\Domain\Models\Journal;
use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Accounting\Domain\Models\JournalLine;
use Asids\Core\Accounting\Infrastructure\Ledger\EloquentAccountUsageProbe;
use Asids\Core\Accounting\Infrastructure\Ledger\EloquentLedgerActivityProbe;
use Asids\Core\Accounting\Listeners\MaintainAccountPeriodBalances;
use Asids\Core\Accounting\Policies\AccountPolicy;
use Asids\Core\Accounting\Policies\FiscalPeriodPolicy;
use Asids\Core\Accounting\Policies\JournalEntryPolicy;
use Asids\Core\Accounting\Presentation\Console\RebuildLedgerBalancesCommand;
use Asids\Core\Accounting\Presentation\Console\VerifyLedgerCommand;
use Asids\Core\Organization\Domain\Contracts\LedgerActivityProbe;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        $this->app->singleton(DocumentNumberService::class);
        $this->app->singleton(JournalService::class);
        $this->app->singleton(PostingService::class);
        $this->app->singleton(LedgerBalanceService::class);
        $this->app->singleton(OpeningBalanceService::class);
        $this->app->singleton(PeriodCloseService::class);

        // The real probe, now that `journal_lines` exists. `NoPostings` was the truthful answer
        // until tranche 3 created the table; the rules in ChartOfAccountsService did not change when
        // this binding did, which is the point of having had the seam.
        $this->app->bind(AccountUsageProbe::class, EloquentAccountUsageProbe::class);

        // Phase 1's seam, finally answered for real. It bound `NoLedgerActivity` because no postable
        // table existed; the binding moving here is what makes a company's base currency and fiscal
        // calendar genuinely immutable once its books have activity. Nothing in Organization changed.
        $this->app->bind(LedgerActivityProbe::class, EloquentLedgerActivityProbe::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->registerMorphAliases();

        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(JournalEntry::class, JournalEntryPolicy::class);
        Gate::policy(FiscalPeriod::class, FiscalPeriodPolicy::class);

        Event::listen(JournalEntryPosted::class, [MaintainAccountPeriodBalances::class, 'handlePosted']);
        Event::listen(JournalEntryReversed::class, [MaintainAccountPeriodBalances::class, 'handleReversed']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                VerifyLedgerCommand::class,
                RebuildLedgerBalancesCommand::class,
            ]);
        }
    }

    /**
     * The module's own morph aliases.
     *
     * Declared here rather than appended to the central list in `PlatformServiceProvider`, and
     * `morphMap()` merges rather than replaces, so each module owns the aliases for its own models.
     * A single central list would mean every new module editing a Platform file, which is exactly
     * the coupling `ModuleServiceProvider` exists to avoid.
     *
     * The map is *enforced*, so this is not optional decoration: `JournalEntry` applies the
     * `Auditable` trait, and an audit entry for an unmapped class throws rather than storing a class
     * name that a namespace refactor would orphan.
     */
    private function registerMorphAliases(): void
    {
        Relation::morphMap([
            'account' => Account::class,
            'fiscal_year' => FiscalYear::class,
            'fiscal_period' => FiscalPeriod::class,
            'journal' => Journal::class,
            'journal_entry' => JournalEntry::class,
            'journal_line' => JournalLine::class,
        ]);
    }
}
