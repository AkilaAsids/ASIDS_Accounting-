<?php

declare(strict_types=1);

namespace Asids\Core\Identity\Domain\Contracts;

use Asids\Core\Identity\Domain\Models\User;
use Asids\Core\Platform\Domain\Query\QueryCriteria;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Persistence boundary for users.
 *
 * Exists so the authentication and lifecycle services can be unit tested without a database —
 * the lockout arithmetic, the seat counting and the last-owner rule are all worth testing in
 * isolation from PostgreSQL.
 */
interface UserRepositoryContract
{
    public function find(string $id): ?User;

    /**
     * Case-insensitive lookup, scoped to the active tenant by the global scope. That scoping is
     * the reason credentials valid in one workspace do not authenticate in another.
     */
    public function findByEmail(string $email): ?User;

    public function emailExists(string $email, ?string $excludingId = null): bool;

    /**
     * Seats consumed: active accounts plus outstanding invitations.
     */
    public function consumedSeatCount(): int;

    /**
     * Active users holding the workspace owner role, excluding one id.
     *
     * @return Collection<int, User>
     */
    public function otherActiveOwners(string $excludingId): Collection;

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(QueryCriteria $criteria): LengthAwarePaginator;

    /**
     * Users whose session has been idle longer than the configured timeout, for the sweeper.
     *
     * @return Collection<int, User>
     */
    public function idleSince(DateTimeInterface $threshold): Collection;
}
