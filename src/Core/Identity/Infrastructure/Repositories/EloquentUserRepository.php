<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Infrastructure\Repositories;

use Asids\Core\Identity\Domain\Contracts\UserRepositoryContract;
use Asids\Core\Identity\Domain\Enums\UserStatus;
use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Platform\Domain\Query\QueryCriteria;
use Asids\Core\Platform\Infrastructure\EloquentRepository;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<User>
 */
final class EloquentUserRepository extends EloquentRepository implements UserRepositoryContract
{
    public function find(string $id): ?User
    {
        return $this->query()->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        // `lower(email)` matches the expression unique index on the table, so this uses the index
        // rather than degrading to a sequential scan on the busiest query in the system.
        return $this->query()
            ->whereRaw('lower(email) = ?', [strtolower(trim($email))])
            ->first();
    }

    public function emailExists(string $email, ?string $excludingId = null): bool
    {
        return $this->query()
            ->whereRaw('lower(email) = ?', [strtolower(trim($email))])
            ->when($excludingId !== null, static fn ($query) => $query->whereKeyNot($excludingId))
            ->exists();
    }

    public function consumedSeatCount(): int
    {
        return $this->query()
            ->whereIn('status', [
                UserStatus::Active->value,
                UserStatus::PendingInvitation->value,
            ])
            ->count();
    }

    /**
     * @return Collection<int, User>
     */
    public function otherActiveOwners(string $excludingId): Collection
    {
        /** @var Collection<int, User> $owners */
        $owners = $this->query()
            ->active()
            ->whereKeyNot($excludingId)
            ->whereHas('roles', static fn ($query) => $query->where('is_owner', true))
            ->get();

        return $owners;
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(QueryCriteria $criteria): LengthAwarePaginator
    {
        $query = $this->query()
            ->with(['roles:id,name,label,level,is_owner'])
            ->withCount('memberships');

        if ($criteria->hasFilter('status')) {
            $query->where('status', $criteria->filter('status'));
        }

        $search = $criteria->search();

        if ($search !== null) {
            $query->search($search);
        }

        return $this->paginateQuery($query, $criteria);
    }

    /**
     * @return Collection<int, User>
     */
    public function idleSince(DateTimeInterface $threshold): Collection
    {
        /** @var Collection<int, User> $users */
        $users = $this->query()
            ->active()
            ->whereNotNull('last_activity_at')
            ->where('last_activity_at', '<', $threshold)
            ->get();

        return $users;
    }

    protected function modelClass(): string
    {
        return User::class;
    }
}
