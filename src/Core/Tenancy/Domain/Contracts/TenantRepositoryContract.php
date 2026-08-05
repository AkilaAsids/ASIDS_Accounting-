<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Domain\Contracts;

use Asids\Core\Platform\Domain\Query\QueryCriteria;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Persistence boundary for tenants.
 *
 * The interface lives in the domain and the Eloquent implementation in
 * infrastructure so that the provisioning service — which is where the
 * interesting rules are — can be unit tested against an in-memory double without
 * a database.
 */
interface TenantRepositoryContract
{
    public function find(string $id): ?Tenant;

    public function findBySlug(string $slug): ?Tenant;

    /**
     * Resolve by hostname. Only usable hostnames match: an unverified
     * customer-owned hostname must not route traffic.
     */
    public function findByDomain(string $domain): ?Tenant;

    public function slugExists(string $slug): bool;

    /**
     * @return LengthAwarePaginator<int, Tenant>
     */
    public function paginate(QueryCriteria $criteria): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Tenant;

    public function save(Tenant $tenant): Tenant;
}
