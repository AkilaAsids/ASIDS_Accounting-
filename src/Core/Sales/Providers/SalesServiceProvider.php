<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Providers;

use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Domain\Contracts\ReceivableBalanceProbe;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Infrastructure\NoReceivables;
use Asids\Core\Sales\Policies\CustomerPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the Sales module.
 *
 * Registered after Accounting in `ModuleServiceProvider`, and the order matters for a concrete reason:
 * a customer's receivable account is an `Account`, and the invoices this module will post go through
 * Accounting's `PostingService`. Sales depends on Accounting; the reverse must never become true.
 */
final class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CustomerService::class);

        /*
         * The receivables seam.
         *
         * `NoReceivables` is not a placeholder — it is an accurate statement of the current schema,
         * which has customers and no invoices. Milestone 5 binds an implementation that queries
         * `sales_invoices` over this line, and the archive and delete rules in `CustomerService`
         * start biting without those methods changing.
         *
         * The same shape Phase 1 used for `LedgerActivityProbe`, which let Organization enforce
         * "a company's base currency freezes once its books have activity" a whole phase before any
         * postable table existed.
         */
        $this->app->bind(ReceivableBalanceProbe::class, NoReceivables::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->registerMorphAliases();

        Gate::policy(Customer::class, CustomerPolicy::class);
    }

    /**
     * The module's own morph aliases.
     *
     * Declared here rather than appended to a central list, and `morphMap()` merges rather than
     * replaces, so each module owns the aliases for its own models.
     *
     * Not optional decoration. `Customer` applies `Auditable`, and an audit entry for an unmapped class
     * throws rather than storing a class name a namespace refactor would orphan. Milestone 1's
     * `SourceDocument` depends on the same registration for the invoice that arrives in Milestone 4 —
     * it feeds the alias back through `getMorphedModel()` and refuses an unmapped model, because
     * `getMorphAlias()` hands back the class name rather than throwing.
     */
    private function registerMorphAliases(): void
    {
        Relation::morphMap([
            Customer::MORPH_ALIAS => Customer::class,
        ]);
    }
}
