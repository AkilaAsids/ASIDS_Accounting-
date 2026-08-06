<?php

declare(strict_types=1);

namespace Asids\Core\Organization\Domain\Exceptions;

use Asids\Core\Platform\Exceptions\BusinessRuleViolation;

/**
 * Archiving would leave the workspace in a state it cannot operate in.
 *
 * Each factory names the specific invariant, so the message tells the administrator what
 * to do first rather than simply refusing.
 */
final class CannotArchive extends BusinessRuleViolation
{
    public static function defaultCompany(string $name): self
    {
        return new self(
            sprintf('“%s” is the default company for this workspace. Make another company the default before archiving it.', $name),
            'cannot-archive-default-company',
            ['company' => $name],
        );
    }

    public static function lastActiveCompany(string $name): self
    {
        return new self(
            sprintf('“%s” is the only active company in this workspace. Create another company before archiving it.', $name),
            'cannot-archive-last-company',
            ['company' => $name],
        );
    }

    public static function primaryBranch(string $name): self
    {
        return new self(
            sprintf('“%s” is the primary branch of its company, where transactions are recorded when no branch is named. Make another branch primary before archiving it.', $name),
            'cannot-archive-primary-branch',
            ['branch' => $name],
        );
    }
}
