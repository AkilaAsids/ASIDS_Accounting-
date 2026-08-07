<?php

declare(strict_types=1);

namespace Asids\Core\Accounting\Policies;

use Asids\Core\Accounting\Domain\Models\JournalEntry;
use Asids\Core\Identity\Domain\Models\User;

/**
 * Who may draft, post and reverse.
 *
 * This policy is where the bookkeeper/accountant split becomes real. `draft` and `post` are separate
 * capabilities and separate methods, so a bookkeeper can prepare an entry they cannot commit — and
 * `update` and `delete` are tied to the entry still being a draft, because a posted entry is
 * immutable no matter who is asking.
 */
final class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounting.journals.view');
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $user->can('accounting.journals.view')
            && $user->canAccessCompany($entry->company_id);
    }

    public function create(User $user): bool
    {
        return $user->can('accounting.journals.draft');
    }

    /**
     * Editing is drafting, and only ever applies to a draft.
     *
     * The status check belongs here as well as in the service: a client should be able to decide
     * whether to show an edit button without attempting the edit to find out.
     */
    public function update(User $user, JournalEntry $entry): bool
    {
        return $entry->isEditable()
            && $user->can('accounting.journals.draft')
            && $user->canAccessCompany($entry->company_id);
    }

    public function delete(User $user, JournalEntry $entry): bool
    {
        return $this->update($user, $entry);
    }

    public function post(User $user, JournalEntry $entry): bool
    {
        return $entry->isEditable()
            && $user->can('accounting.journals.post')
            && $user->canAccessCompany($entry->company_id);
    }

    public function reverse(User $user, JournalEntry $entry): bool
    {
        return $entry->isPosted()
            && $user->can('accounting.journals.reverse')
            && $user->canAccessCompany($entry->company_id);
    }
}
