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
     * Resolve a workspace the way an operator refers to it on a command line — by id or by slug.
     *
     * A single method rather than "try one, then the other" at each call site, because the naive
     * form is `where('id', $x)->orWhere('slug', $x)` and that is a latent crash: `id` is a uuid
     * column, so a slug does not simply fail to match — PostgreSQL refuses the cast and raises
     * 22P02. Two console commands had exactly that, which meant `--tenant=acme`, the usage their
     * own help text documents, produced a raw SQL error.
     */
    public function findByIdOrSlug(string $identifier): ?Tenant;

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
