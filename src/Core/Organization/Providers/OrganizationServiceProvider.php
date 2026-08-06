<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Providers;

use Asids\Core\Organization\Domain\Contracts\LedgerActivityProbe;
use Asids\Core\Organization\Domain\Models\Branch;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Organization\Domain\Models\CompanyMembership;
use Asids\Core\Organization\Infrastructure\NoLedgerActivity;
use Asids\Core\Organization\Policies\BranchPolicy;
use Asids\Core\Organization\Policies\CompanyMembershipPolicy;
use Asids\Core\Organization\Policies\CompanyPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bound with `bind`, not `singleton`, and deliberately overridable: the Accounting
        // module will replace this with an implementation that queries the ledger, at which
        // point the immutability rules in CompanyService and BranchService begin to apply
        // without either service changing. See NoLedgerActivity for why the seam exists now
        // rather than later.
        $this->app->bind(LedgerActivityProbe::class, NoLedgerActivity::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(CompanyMembership::class, CompanyMembershipPolicy::class);
    }
}
