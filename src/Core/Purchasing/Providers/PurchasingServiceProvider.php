<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Providers;

use Asids\Core\Purchasing\Application\Services\BillService;
use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Presentation\Console\BackfillInputVatAccountsCommand;
use Asids\Core\Purchasing\Domain\Contracts\PayableBalanceProbe;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Purchasing\Infrastructure\EloquentPayableBalanceProbe;
use Asids\Core\Purchasing\Policies\BillPolicy;
use Asids\Core\Purchasing\Policies\SupplierPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the Purchasing module — the payable-side mirror of Sales.
 *
 * Registered after Accounting (and, for grouping, after Sales) in `ModuleServiceProvider`, and the
 * order matters for a concrete reason: Wave 7's bills will post through Accounting's `PostingService`
 * and resolve supplier accounts against `Account`. Purchasing depends on Accounting; it depends on
 * Sales in neither direction.
 */
final class PurchasingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SupplierService::class);
        $this->app->singleton(BillService::class);

        /*
         * The payables seam, live.
         *
         * Wave 7 flips this one line from the dormant `NoPayables` to `EloquentPayableBalanceProbe` — and the
         * archive, delete and code-lock rules in `SupplierService` begin to bite for every existing supplier
         * without a line of that service changing. `NoPayables` is kept in the codebase: it is the honest
         * answer for any context with no bills, and a test binds it directly.
         */
        $this->app->bind(PayableBalanceProbe::class, EloquentPayableBalanceProbe::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Not optional decoration. `Supplier` applies `Auditable`, and an audit entry for an unmapped
        // class throws rather than storing a class name a namespace refactor would orphan. `morphMap()`
        // merges rather than replaces, so each module owns the aliases for its own models.
        Relation::morphMap([
            Supplier::MORPH_ALIAS => Supplier::class,
            // `Bill` is `Auditable`, and an audit entry for an unmapped class throws. `BillLine` registers no
            // alias — it is never audited separately and can never be a source document (ADR 0019 §C2, §E).
            Bill::MORPH_ALIAS => Bill::class,
        ]);

        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(Bill::class, BillPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillInputVatAccountsCommand::class,
            ]);
        }
    }
}
