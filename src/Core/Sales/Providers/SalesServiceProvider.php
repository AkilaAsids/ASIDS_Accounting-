<?php

declare(strict_types=1);

namespace Asids\Core\Sales\Providers;

use Asids\Core\Sales\Application\Services\CustomerService;
use Asids\Core\Sales\Application\Services\InvoicePostingMap;
use Asids\Core\Sales\Application\Services\InvoiceTotalsCalculator;
use Asids\Core\Sales\Application\Services\SalesInvoiceService;
use Asids\Core\Sales\Application\Services\TaxCodeService;
use Asids\Core\Sales\Application\Services\TaxRateResolver;
use Asids\Core\Sales\Domain\Contracts\ReceivableBalanceProbe;
use Asids\Core\Sales\Domain\Contracts\TaxRateUsageProbe;
use Asids\Core\Sales\Domain\Models\Customer;
use Asids\Core\Sales\Domain\Models\SalesInvoice;
use Asids\Core\Sales\Domain\Models\TaxCode;
use Asids\Core\Sales\Infrastructure\EloquentReceivableBalanceProbe;
use Asids\Core\Sales\Infrastructure\EloquentTaxRateUsageProbe;
use Asids\Core\Sales\Infrastructure\NoReceivables;
use Asids\Core\Sales\Infrastructure\NoTaxRateUsage;
use Asids\Core\Sales\Policies\CustomerPolicy;
use Asids\Core\Sales\Policies\SalesInvoicePolicy;
use Asids\Core\Sales\Policies\TaxCodePolicy;
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
         * The receivables seam, now answered for real.
         *
         * `NoReceivables` was an accurate statement of a schema with customers and no invoices, not a
         * placeholder. Invoices exist, so the binding moves — and the archive, delete and code-lock
         * rules in `CustomerService` start biting without a line of that service changing. That is
         * the whole point of the seam, and the same shape Phase 1 used for `LedgerActivityProbe`.
         *
         * `NoReceivables` is kept rather than deleted: it is the honest implementation for any context
         * with no invoice table, and a test wanting "this customer owes nothing" binds it directly
         * rather than constructing invoices to prove a negative.
         */
        $this->app->bind(ReceivableBalanceProbe::class, EloquentReceivableBalanceProbe::class);

        $this->app->singleton(TaxCodeService::class);
        $this->app->singleton(TaxRateResolver::class);
        $this->app->singleton(InvoicePostingMap::class);
        $this->app->singleton(InvoiceTotalsCalculator::class);
        $this->app->singleton(SalesInvoiceService::class);

        /*
         * The rate-usage seam, now answered for real — same shape and same reasoning as the receivables
         * probe above.
         *
         * `NoTaxRateUsage` stated the truth for a schema with tax codes and no documents carrying tax.
         * Invoices exist, so the binding moves, and the rate-immutability and delete rules in
         * `TaxCodeService` start biting without that service changing.
         *
         * Kept rather than deleted, for the same reason as `NoReceivables`: it is the honest answer where
         * nothing has been invoiced, and a test wanting an unused rate binds it directly.
         */
        $this->app->bind(TaxRateUsageProbe::class, EloquentTaxRateUsageProbe::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $this->registerMorphAliases();

        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(TaxCode::class, TaxCodePolicy::class);
        Gate::policy(SalesInvoice::class, SalesInvoicePolicy::class);
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
            TaxCode::MORPH_ALIAS => TaxCode::class,
            SalesInvoice::MORPH_ALIAS => SalesInvoice::class,
        ]);
    }
}
