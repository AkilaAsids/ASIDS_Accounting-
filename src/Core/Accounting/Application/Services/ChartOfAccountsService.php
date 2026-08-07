<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Application\Services;

use Asids\Core\Accounting\Application\DTOs\CreateAccountData;
use Asids\Core\Accounting\Domain\Contracts\AccountUsageProbe;
use Asids\Core\Accounting\Domain\Enums\AccountType;
use Asids\Core\Accounting\Domain\Exceptions\AccountInUse;
use Asids\Core\Accounting\Domain\Exceptions\InvalidAccountHierarchy;
use Asids\Core\Accounting\Domain\Exceptions\SystemAccountIsProtected;
use Asids\Core\Accounting\Domain\Models\Account;
use Asids\Core\Organization\Domain\Models\Company;
use Asids\Core\Platform\Exceptions\ResourceConflict;
use Illuminate\Support\Facades\DB;

/**
 * Creating and maintaining a company's chart of accounts.
 *
 * Most of this class is refusals, and that is the right proportion. Creating an account is
 * straightforward; the value is in the things it will not let you do afterwards, because each of
 * them produces a chart that still saves, still balances, and misreports.
 *
 * The one to understand if you read nothing else: **an account with postings cannot change its
 * type.** Moving an account from expense to asset does not error, does not unbalance anything, and
 * silently changes every profit figure the company has ever filed. There is no way to detect it
 * afterwards from the data alone.
 */
final readonly class ChartOfAccountsService
{
    public function __construct(private AccountUsageProbe $usage) {}

    public function create(Company $company, CreateAccountData $data): Account
    {
        $this->assertCodeAvailable($company, $data->code);

        $parent = $this->resolveParent($company, $data->parentId);

        if ($parent !== null) {
            $this->assertParentIsSuitable($parent, $data->type);
        }

        return DB::transaction(function () use ($company, $data, $parent): Account {
            $account = new Account;

            $account->company_id = $company->getKey();
            $account->parent_id = $parent?->getKey();
            $account->code = $data->code;
            $account->name = $data->name;
            $account->description = $data->description;
            $account->type = $data->type;
            $account->is_postable = $data->isPostable;
            $account->is_system = $data->systemKey !== null;
            $account->system_key = $data->systemKey;
            $account->is_active = true;
            $account->sort_order = $data->sortOrder;
            $account->template_version = $data->templateVersion;
            $account->save();

            return $account;
        });
    }

    /**
     * Update the mutable parts of an account.
     *
     * Name, code, description, sort order and parent may move freely. Type may move only while the
     * account is unused. This asymmetry is the whole design: a customer renumbering their chart to
     * match a group standard is routine and must stay easy, and it is safe precisely because
     * everything the platform depends on resolves by system key rather than by code.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Account $account, array $attributes): Account
    {
        if (array_key_exists('code', $attributes) && trim((string) $attributes['code']) !== $account->code) {
            $this->assertCodeAvailable($account->company, (string) $attributes['code'], excluding: $account->getKey());
        }

        if (array_key_exists('type', $attributes)) {
            $this->assertTypeMayChange($account, $attributes['type']);
        }

        if (array_key_exists('parent_id', $attributes)) {
            $this->assertParentMayChange($account, $attributes['parent_id'] === null ? null : (string) $attributes['parent_id']);
        }

        if (array_key_exists('is_postable', $attributes)) {
            $this->assertPostabilityMayChange($account, (bool) $attributes['is_postable']);
        }

        return DB::transaction(function () use ($account, $attributes): Account {
            $account->fill(array_intersect_key($attributes, array_flip(['code', 'name', 'description', 'sort_order'])));

            if (array_key_exists('type', $attributes)) {
                $account->type = $attributes['type'] instanceof AccountType
                    ? $attributes['type']
                    : AccountType::from((string) $attributes['type']);
            }

            if (array_key_exists('parent_id', $attributes)) {
                $account->parent_id = $attributes['parent_id'] === null ? null : (string) $attributes['parent_id'];
            }

            if (array_key_exists('is_postable', $attributes)) {
                $account->is_postable = (bool) $attributes['is_postable'];
            }

            $account->save();

            return $account;
        });
    }

    /**
     * Close an account to new postings while keeping its history readable.
     *
     * The counterpart to refusing deletion. A customer who has stopped using an account needs it out
     * of the picker, not out of the ledger.
     */
    public function archive(Account $account): Account
    {
        if ($account->is_system) {
            // The year-end close posts to retained earnings. An archived one fails the close months
            // later, at the worst possible moment.
            throw SystemAccountIsProtected::cannotArchive($account->code);
        }

        if (! $account->is_active) {
            return $account;
        }

        $account->is_active = false;
        $account->archived_at = now();
        $account->save();

        return $account;
    }

    public function restore(Account $account): Account
    {
        if ($account->is_active) {
            return $account;
        }

        $account->is_active = true;
        $account->archived_at = null;
        $account->save();

        return $account;
    }

    /**
     * Remove an account that was never used.
     *
     * Deletion exists only for the case it is safe for: an account created by mistake, before
     * anything referenced it. Everything else archives.
     */
    public function delete(Account $account): void
    {
        if ($account->is_system) {
            throw SystemAccountIsProtected::cannotDelete($account->code);
        }

        if ($this->usage->subtreeHasPostings($account)) {
            throw AccountInUse::cannotDelete($account->code);
        }

        if (Account::query()->where('parent_id', $account->getKey())->exists()) {
            // Refused rather than cascaded. Deleting a heading silently takes its children with it,
            // and the customer discovers which accounts vanished by their absence from a report.
            throw AccountInUse::cannotDeleteWithChildren($account->code);
        }

        $account->delete();
    }

    /**
     * The account a platform routine resolves by name — retained earnings, opening balance equity.
     *
     * By key rather than by code, so a customer renumbering their chart cannot break the year-end
     * close.
     */
    public function systemAccount(Company $company, string $key): ?Account
    {
        return Account::query()
            ->forCompany($company->getKey())
            ->withSystemKey($key)
            ->first();
    }

    private function assertCodeAvailable(Company $company, string $code, ?string $excluding = null): void
    {
        $exists = Account::query()
            ->forCompany($company->getKey())
            ->whereRaw('lower(code) = ?', [strtolower(trim($code))])
            ->when($excluding !== null, static fn ($query) => $query->whereKeyNot($excluding))
            ->exists();

        if ($exists) {
            throw ResourceConflict::duplicate('account', 'code', $code);
        }
    }

    private function resolveParent(Company $company, ?string $parentId): ?Account
    {
        if ($parentId === null) {
            return null;
        }

        $parent = Account::query()->forCompany($company->getKey())->find($parentId);

        if ($parent === null) {
            // Reported as a hierarchy error rather than "not found", because from the caller's point
            // of view the parent may well exist — in another company. Saying so would confirm the
            // existence of a record in a workspace they cannot see.
            throw InvalidAccountHierarchy::foreignCompany();
        }

        return $parent;
    }

    /**
     * A parent must be in the same company, of the same type, and not itself carry postings.
     *
     * The type rule is what keeps a statement's subtotals meaningful: an expense rolling up into an
     * asset heading puts its balance into the wrong section of the balance sheet, and the total
     * still ties because the amount is real — it is simply in the wrong place.
     */
    private function assertParentIsSuitable(Account $parent, AccountType $childType): void
    {
        if ($parent->type !== $childType) {
            throw InvalidAccountHierarchy::typeMismatch($childType->value, $parent->type->value);
        }

        if ($this->usage->hasPostings($parent)) {
            // A heading with its own postings produces a subtotal that double-counts: the parent's
            // own balance plus its children's.
            throw InvalidAccountHierarchy::parentIsPostable($parent->code);
        }
    }

    private function assertTypeMayChange(Account $account, mixed $requested): void
    {
        $type = $requested instanceof AccountType ? $requested : AccountType::from((string) $requested);

        if ($type === $account->type) {
            return;
        }

        if ($account->is_system) {
            throw SystemAccountIsProtected::cannotChangeType($account->code);
        }

        if ($this->usage->hasPostings($account)) {
            throw AccountInUse::cannotChangeType($account->code, $account->type->value, $type->value);
        }

        // Children inherit the constraint: a chart where a parent and child disagree on type is the
        // same misreport one level down.
        $mismatched = Account::query()
            ->where('parent_id', $account->getKey())
            ->where('type', '<>', $type->value)
            ->exists();

        if ($mismatched) {
            throw InvalidAccountHierarchy::typeMismatch($account->type->value, $type->value);
        }
    }

    private function assertParentMayChange(Account $account, ?string $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $account->getKey()) {
            throw InvalidAccountHierarchy::cycle($account->code);
        }

        $parent = $this->resolveParent($account->company, $parentId);

        if ($parent === null) {
            return;
        }

        $this->assertParentIsSuitable($parent, $account->type);

        // Walk the proposed parent's ancestry looking for this account. A cycle cannot be expressed
        // as a check constraint beyond the self-reference case, so it is caught here — and a cycle
        // that reached the database would make the roll-up on every statement non-terminating.
        $accounts = Account::query()->forCompany($account->company_id)->get();

        foreach ($parent->ancestorsWithin($accounts) as $ancestor) {
            if ($ancestor->getKey() === $account->getKey()) {
                throw InvalidAccountHierarchy::cycle($account->code);
            }
        }
    }

    private function assertPostabilityMayChange(Account $account, bool $isPostable): void
    {
        if ($isPostable || ! $account->is_postable) {
            return;
        }

        if ($this->usage->hasPostings($account)) {
            throw AccountInUse::cannotStopBeingPostable($account->code);
        }
    }
}
