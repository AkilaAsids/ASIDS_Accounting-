<?php

declare(strict_types=1);

namespace Asids\Core\Platform\Infrastructure;

use Asids\Core\Platform\Domain\Query\QueryCriteria;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Shared Eloquent plumbing for module repositories.
 *
 * Repositories exist here for one concrete reason, not for architectural
 * fashion: query construction for a table like `journal_entries` will become
 * genuinely intricate (tenant, company, branch, fiscal period, posting state,
 * dimension filters), and that logic must be unit testable and reusable from a
 * controller, a report, a scheduled job and a CSV import alike. Leaving it in
 * controllers guarantees four subtly different versions of it.
 *
 * What this base class deliberately does NOT do is hide Eloquent behind a generic
 * `findBy(array $criteria)` soup. Each concrete repository exposes intention
 * revealing methods (`activeForCompany`, `pendingApproval`) and uses the helpers
 * here to build them.
 *
 * @template TModel of Model
 */
abstract class EloquentRepository
{
    /**
     * @return TModel
     */
    public function newModel(): Model
    {
        $class = $this->modelClass();

        return new $class;
    }

    /**
     * @return Builder<TModel>
     */
    public function query(): Builder
    {
        // `newQuery()` is declared as `Builder<Model>` on the base model, which loses TModel.
        // Restated here so every caller downstream keeps the concrete model type.
        /** @var Builder<TModel> $query */
        $query = $this->newModel()->newQuery();

        return $query;
    }

    /**
     * @return TModel|null
     */
    public function find(string $id): ?Model
    {
        return $this->query()->find($id);
    }

    /**
     * @return TModel
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(string $id): Model
    {
        return $this->query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function create(array $attributes): Model
    {
        $model = $this->newModel();
        $model->fill($attributes);
        $model->save();

        return $model;
    }

    /**
     * @param  TModel  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function update(Model $model, array $attributes): Model
    {
        $model->fill($attributes);
        $model->save();

        return $model;
    }

    /**
     * @param  TModel  $model
     */
    public function delete(Model $model): void
    {
        $model->delete();
    }

    /**
     * @return class-string<TModel>
     */
    abstract protected function modelClass(): string;

    /**
     * Applies sorting, filtering and pagination described by a request-derived
     * criteria object. Centralising it here is what keeps `?sort=-created_at`
     * meaning the same thing on every endpoint, and keeps an attacker from
     * sorting by a column that is not allow-listed.
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    protected function paginateQuery(Builder $query, QueryCriteria $criteria): LengthAwarePaginator
    {
        foreach ($criteria->sorts() as $column => $direction) {
            $query->orderBy($column, $direction);
        }

        // A deterministic tie-breaker: without it, two rows with equal sort keys
        // can swap between pages and an item can be seen twice or never.
        $query->orderBy($this->newModel()->getQualifiedKeyName());

        return $query->paginate(
            perPage: $criteria->perPage(),
            page: $criteria->page(),
        )->withQueryString();
    }

    /**
     * Streams every matching row in chunks. Used by exports and by data
     * migrations, where loading a tenant's whole ledger into memory is not an
     * option.
     *
     * @param  Builder<TModel>  $query
     * @param  callable(Collection<int, TModel>): void  $handler
     */
    protected function eachChunk(Builder $query, callable $handler, int $size = 500): void
    {
        $query->orderBy($this->newModel()->getQualifiedKeyName())
            ->chunkById($size, static function (Collection $chunk) use ($handler): void {
                /** @var Collection<int, TModel> $chunk */
                $handler($chunk);
            });
    }
}
