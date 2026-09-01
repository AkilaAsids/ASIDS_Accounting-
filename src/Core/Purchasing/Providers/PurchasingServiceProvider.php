<?php

declare(strict_types=1);

namespace Asids\Core\Purchasing\Providers;

use Asids\Core\Purchasing\Application\Services\SupplierService;
use Asids\Core\Purchasing\Domain\Contracts\PayableBalanceProbe;
use Asids\Core\Purchasing\Domain\Models\Bill;
use Asids\Core\Purchasing\Domain\Models\Supplier;
use Asids\Core\Purchasing\Infrastructure\NoPayables;
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

        /*
         * The payables seam, dormant.
         *
         * Purchasing is at the "Milestone 2" state: no bill table exists, so it binds `NoPayables`,
         * exactly as Sales bound `NoReceivables` before invoices existed. Wave 7 flips this one line to
         * `EloquentPayableBalanceProbe` and the archive, delete and code-lock rules in `SupplierService`
         * begin to bite without a line of that service changing.
         */
        $this->app->bind(PayableBalanceProbe::class, NoPayables::class);
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
    }
}
