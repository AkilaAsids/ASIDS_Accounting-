<?php

declare(strict_types=1);

namespace Asids\Core\Tenancy\Infrastructure\Repositories;

use Asids\Core\Platform\Domain\Query\QueryCriteria;
use Asids\Core\Platform\Infrastructure\EloquentRepository;
use Asids\Core\Tenancy\Domain\Contracts\TenantRepositoryContract;
use Asids\Core\Tenancy\Domain\Models\Domain;
use Asids\Core\Tenancy\Domain\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * @extends EloquentRepository<Tenant>
 */
final class EloquentTenantRepository extends EloquentRepository implements TenantRepositoryContract
{
    public function find(string $id): ?Tenant
    {
        return $this->query()->with('domains')->find($id);
    }

    public function findBySlug(string $slug): ?Tenant
    {
        return $this->query()
            ->with('domains')
            ->whereRaw('lower(slug) = ?', [strtolower($slug)])
            ->first();
    }

    public function findByIdOrSlug(string $identifier): ?Tenant
    {
        // Branches on the shape of the identifier rather than querying both columns: `id` is a uuid,
        // and comparing it against a slug is a cast error rather than a non-match.
        return Str::isUuid($identifier)
            ? $this->find($identifier)
            : $this->findBySlug($identifier);
    }

    public function findByDomain(string $domain): ?Tenant
    {
        /** @var Domain|null $record */
        $record = Domain::query()
            ->with('tenant.domains')
            ->whereRaw('lower(domain) = ?', [strtolower($domain)])
            ->first();

        // An unverified customer-owned hostname must not route traffic; anyone can
        // point a CNAME at the platform, and honouring it before DNS proof would
        // let them serve their own content from a trusted origin.
        return ($record !== null && $record->isUsable()) ? $record->tenant : null;
    }

    public function slugExists(string $slug): bool
    {
        // Deliberately includes soft-deleted tenants: reusing the slug of a
        // recently closed workspace would let its former users' bookmarks and
        // cached DNS reach a different customer.
        return $this->query()
            ->withTrashed()
            ->whereRaw('lower(slug) = ?', [strtolower($slug)])
            ->exists();
    }

    /**
     * @return LengthAwarePaginator<int, Tenant>
     */
    public function paginate(QueryCriteria $criteria): LengthAwarePaginator
    {
        $query = $this->query()->with('domains');

        $this->applyFilters($query, $criteria);

        return $this->paginateQuery($query, $criteria);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Tenant
    {
        $tenant = new Tenant;
        $tenant->fill($attributes);
        $tenant->save();

        return $tenant;
    }

    public function save(Tenant $tenant): Tenant
    {
        $tenant->save();

        return $tenant;
    }

    protected function modelClass(): string
    {
        return Tenant::class;
    }

    /**
     * @param  Builder<Tenant>  $query
     */
    private function applyFilters(Builder $query, QueryCriteria $criteria): void
    {
        if ($criteria->hasFilter('status')) {
            $query->where('status', $criteria->filter('status'));
        }

        if ($criteria->hasFilter('plan_code')) {
            $query->where('plan_code', $criteria->filter('plan_code'));
        }

        $search = $criteria->search();

        if ($search !== null) {
            $query->where(static function (Builder $inner) use ($search): void {
                $inner->whereRaw('name ILIKE ?', ["%{$search}%"])
                    ->orWhereRaw('slug ILIKE ?', ["%{$search}%"])
                    ->orWhereRaw('lower(contact_email) LIKE ?', [strtolower("%{$search}%")]);
            });
        }
    }
}
